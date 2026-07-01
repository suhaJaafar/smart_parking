<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Contracts\PlateRecognizer;
use App\Bots\Dto\OutboundReply;
use App\Bots\Flows\Concerns\AcceptsPlateImage;
use App\Bots\Support\Prompt;
use App\Data\CarPlate;
use App\Enums\RoleTypes;
use App\Models\Car;
use App\Models\Park;
use App\Services\CarService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One-step flow for a SPACE_OWNER to take a car OUT of their park.
 *
 * Steps: (pick) → plate → done.
 *   • pick  -> only when the owner has more than one park: a tappable list
 *              of their parks (annotated with how many cars are inside) so
 *              they choose which park the car is leaving. Skipped for
 *              single-park owners.
 *   • plate -> the departing car's plate (prefix-number).
 */
class CarExitFlow
{
    use AcceptsPlateImage;

    public const FLOW = 'car_exit';
    private const TTL_MINUTES = 10;

    /**
     * Payload prefix for a "choose this park" row, shown only when the owner
     * has more than one park. Full payload is "park:<parkId>".
     */
    private const PARK_OPTION_PREFIX = 'park:';

    /** Callback payload for confirming an OCR-detected plate. */
    private const PLATE_CONFIRM = 'plate_confirm';

    public function __construct(
        private readonly CarService $carService,
        private readonly ReservationService $reservations,
        private readonly PlateRecognizer $plates,
    ) {}

    public function handle(BotSession $session, string $message): OutboundReply
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'pick'          => $this->onPick($session, $message),
            'plate'         => $this->finish($session, $message),
            'confirm_plate' => $this->onPlateConfirm($session, $message),
            default         => OutboundReply::empty(),
        };
    }

    private function start(BotSession $session): OutboundReply
    {
        $owner = $session->getUser();

        if (!$owner) {
            return OutboundReply::text("📱 حسابك غير مسجل في النظام.");
        }

        if (!$owner->roles()->where('role', RoleTypes::SPACE_OWNER->value)->exists()) {
            return OutboundReply::text("🚫 هذه العملية متاحة لمالكي المواقف فقط.");
        }

        /** @var \Illuminate\Support\Collection<int, Park> $parks */
        $parks = $owner->ownedParks()->orderBy('created_at')->get();

        if ($parks->isEmpty()) {
            return OutboundReply::text("🚫 لا يوجد موقف مسجل باسمك.");
        }

        // A single park needs no disambiguation — ask for the plate directly.
        if ($parks->count() === 1) {
            return $this->promptPlateForPark($session, $parks->first());
        }

        // Multiple parks: let the owner tap which one the car is leaving
        // from, each row annotated with how many cars are currently inside.
        $options = [];
        foreach ($parks as $park) {
            $inside    = max(0, (int) $park->capacity - (int) $park->free_spaces);
            $options[] = [
                'id'          => self::PARK_OPTION_PREFIX . $park->id,
                'title'       => "📍 {$park->name}",
                'description' => "داخل الموقف: {$inside}",
            ];
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'pick',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::buttons(
            body:       "🚙 *إخراج سيارة*\n\nاختر الموقف:",
            options:    $options,
            listButton: 'اختر الموقف',
        );
    }

    /**
     * Owner tapped a park row — resolve it (scoped to the owner) and ask for
     * the departing car's plate.
     */
    private function onPick(BotSession $session, string $message): OutboundReply
    {
        $raw = trim($message);

        if (!str_starts_with(mb_strtolower($raw), self::PARK_OPTION_PREFIX)) {
            return OutboundReply::text(Prompt::ask("⚠️ اختر الموقف من القائمة أعلاه."));
        }

        $parkId = Str::after($raw, self::PARK_OPTION_PREFIX);
        $owner  = $session->getUser();

        $park = ($owner && Str::isUuid($parkId))
            ? $owner->ownedParks()->whereKey($parkId)->first()
            : null;

        if (!$park) {
            return OutboundReply::text(Prompt::ask("⚠️ اختر الموقف من القائمة أعلاه."));
        }

        return $this->promptPlateForPark($session, $park);
    }

    /**
     * Lock the flow onto a concrete park and ask for the departing plate.
     * Shared by the single-park and multi-park (post-pick) paths.
     */
    private function promptPlateForPark(BotSession $session, Park $park): OutboundReply
    {
        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'plate',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            Prompt::ask(
                "🚙 أرسل لوحة السيارة الخارجة من *{$park->name}*، أو أرسل *صورة* للوحة.\n"
                . "مثال: 11G-12345"
            )
        );
    }

    private function finish(BotSession $session, string $message): OutboundReply
    {
        // Owner re-tapped a park button (Telegram keeps old inline buttons
        // tappable) — switch to that park instead of treating it as a plate.
        $raw = trim($message);
        if (str_starts_with(mb_strtolower($raw), self::PARK_OPTION_PREFIX)) {
            $owner  = $session->getUser();
            $parkId = Str::after($raw, self::PARK_OPTION_PREFIX);
            $picked = ($owner && Str::isUuid($parkId))
                ? $owner->ownedParks()->whereKey($parkId)->first()
                : null;

            if ($picked) {
                return $this->promptPlateForPark($session, $picked);
            }
        }

        // Plate photo → OCR → ask the owner to confirm/correct the read.
        if ($this->isImagePayload($raw)) {
            $plate = $this->plates->recognize($this->imageUrl($raw));
            if ($plate === null) {
                return OutboundReply::text(
                    Prompt::ask(
                        "⚠️ تعذّر قراءة اللوحة من الصورة.\n"
                        . "أرسل صورة أوضح، أو اكتب اللوحة يدوياً. مثال: 11G-12345"
                    )
                );
            }

            return $this->askPlateConfirmation($session, $plate);
        }

        // Delegate plate parsing (incl. Arabic-digit normalization and
        // optional separator) to the shared value object.
        $plate = CarPlate::fromString($message);
        if ($plate === null) {
            return OutboundReply::text(
                Prompt::ask(
                    "⚠️ صيغة اللوحة غير صحيحة. مثال: 11G-12345\n"
                    . "أو أرسل صورة واضحة للوحة."
                )
            );
        }

        return $this->processExit($session, $plate);
    }

    /**
     * Show the OCR-detected plate and wait for the owner to confirm it, type
     * a correction, or send a clearer photo.
     */
    private function askPlateConfirmation(BotSession $session, CarPlate $plate): OutboundReply
    {
        $session->update([
            'data'       => array_merge($session->getData(), ['ocr_plate' => (string) $plate]),
            'step'       => 'confirm_plate',
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::buttons(
            body: "🔍 قرأنا اللوحة: *{$plate}*\n\n"
                . "إن كانت صحيحة اضغط للتأكيد، أو اكتب اللوحة الصحيحة، أو أرسل صورة أوضح.",
            options: [
                ['id' => self::PLATE_CONFIRM, 'title' => "✅ تأكيد {$plate}"],
            ],
            listButton: 'تأكيد',
        );
    }

    /**
     * Resolve the confirm step: a tapped confirmation uses the stored plate;
     * a typed plate or a new photo overrides it.
     */
    private function onPlateConfirm(BotSession $session, string $message): OutboundReply
    {
        $raw = trim($message);

        if ($raw === self::PLATE_CONFIRM) {
            $stored = $session->getData()['ocr_plate'] ?? null;
            $plate  = is_string($stored) ? CarPlate::fromString($stored) : null;

            if ($plate === null) {
                $session->update(['step' => 'plate']);
                return OutboundReply::text(
                    Prompt::ask("🚙 أرسل لوحة السيارة الخارجة. مثال: 11G-12345")
                );
            }

            return $this->processExit($session, $plate);
        }

        if ($this->isImagePayload($raw)) {
            $plate = $this->plates->recognize($this->imageUrl($raw));
            if ($plate === null) {
                return OutboundReply::text(
                    Prompt::ask("⚠️ تعذّر قراءة اللوحة. أرسل صورة أوضح أو اكتب اللوحة يدوياً.")
                );
            }

            return $this->askPlateConfirmation($session, $plate);
        }

        $plate = CarPlate::fromString($raw);
        if ($plate === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. اكتب اللوحة الصحيحة أو اضغط تأكيد.")
            );
        }

        return $this->processExit($session, $plate);
    }

    /**
     * Look the plate up inside the locked park and check the car out,
     * closing the customer's ACTIVE reservation. Shared by the typed-plate,
     * photo-confirm and correction paths.
     */
    private function processExit(BotSession $session, CarPlate $plate): OutboundReply
    {
        $data    = $session->getData();
        $parkId  = $data['park_id'] ?? null;

        $car = Car::where('plate_prefix', $plate->prefix)
            ->where('car_number', $plate->number)
            ->first();

        if (!$car) {
            $session->reset();
            return OutboundReply::text("❌ لا توجد سيارة بهذه اللوحة في النظام.");
        }

        if ($car->park_id !== $parkId) {
            $session->reset();
            return OutboundReply::text("❌ هذه السيارة ليست داخل موقفك حالياً.");
        }

        try {
            // Capture the car's owner BEFORE detaching it from the park so
            // we can close out their ACTIVE reservation → COMPLETED.
            $carOwner = $car->user;

            $car = $this->carService->exitPark($car);

            if ($carOwner) {
                $park = Park::find($parkId);
                if ($park) {
                    $this->reservations->markCompleted($carOwner, $park);
                }
            }
        } catch (Throwable $e) {
            Log::error('Bot car exit failed', ['error' => $e->getMessage()]);
            $session->reset();
            return OutboundReply::text("❌ تعذّر إخراج السيارة: {$e->getMessage()}");
        }

        $session->reset();
        $park = Park::find($parkId);

        return OutboundReply::text(
            "✅ تم إخراج السيارة!\n"
            . "اللوحة: {$plate}\n"
            . "الأماكن الفارغة: " . ($park?->free_spaces ?? '?')
        );
    }
}
