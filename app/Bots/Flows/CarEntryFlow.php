<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\DigitNormalizer;
use App\Bots\Support\Prompt;
use App\Data\CarPlate;
use App\Enums\PaymentStatusTypes;
use App\Enums\RoleTypes;
use App\Models\Car;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use App\Services\CarService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-step flow for a SPACE_OWNER to bring a car INTO their park.
 *
 * Steps: plate → identifier → done.
 *   • plate       -> "BG-12345" (prefix-number)
 *   • identifier  -> phone number (WhatsApp drivers) OR Telegram chat_id
 *                    (Telegram drivers). The flow auto-detects which
 *                    column to query — drivers never have both.
 *
 * If the car doesn't exist yet, it's created (find-or-create by plate).
 * The car is then linked to the SPACE_OWNER's park and free_spaces is
 * decremented (unless the customer pre-reserved, in which case the slot
 * was already debited at reservation time).
 */
class CarEntryFlow
{
    public const FLOW = 'car_enter';
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly CarService $carService,
        private readonly ReservationService $reservations,
        private readonly BotNotifier $notifier,
    ) {}

    public function handle(BotSession $session, string $message): OutboundReply
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'plate'      => $this->askIdentifier($session, $message),
            'identifier' => $this->finish($session, $message),
            default      => OutboundReply::empty(),
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

        $park = $owner->ownedParks()->first();
        if (!$park) {
            return OutboundReply::text("🚫 لا يوجد موقف مسجل باسمك. أنشئ موقفاً أولاً.");
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'plate',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            Prompt::ask("🚗 أرسل لوحة السيارة بالشكل: PREFIX-NUMBER\nمثال: BG-12345")
        );
    }

    private function askIdentifier(BotSession $session, string $message): OutboundReply
    {
        $plate = CarPlate::fromString($message);
        if ($plate === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. أرسل: PREFIX-NUMBER (مثال: BG-12345)")
            );
        }

        $this->merge($session, [
            'plate_prefix' => $plate->prefix,
            'car_number'   => $plate->number,
        ], 'identifier');

        return OutboundReply::text(
            Prompt::ask(
                "📱 أرسل *معرّف* صاحب السيارة:\n"
                . "• رقم الهاتف (واتساب) — مثال: `9647701234567`\n"
                . "• أو رقم تيليجرام (Telegram ID) — مثال: `7804684895`\n\n"
                . "_يحصل الزبون على معرّفه من بوت تيليجرام بإرسال *الحالة*._"
            )
        );
    }

    private function finish(BotSession $session, string $message): OutboundReply
    {
        [$raw, $normalized] = $this->parseIdentifier($message);

        if ($raw === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ معرّف غير صحيح. أرسل رقم هاتف أو Telegram ID صالحاً.")
            );
        }

        $carOwner = $this->resolveDriver($raw, $normalized);

        if (!$carOwner) {
            $session->reset();
            return OutboundReply::text(
                "❌ لا يوجد مستخدم بهذا المعرّف. اطلب من صاحب السيارة التسجيل في البوت أولاً."
            );
        }

        $data = $session->getData();
        $park = Park::find($data['park_id'] ?? null);

        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الموقف.");
        }

        try {
            $car = $this->carService->findOrCreateByPlate(
                plate: new CarPlate(
                    prefix: $data['plate_prefix'],
                    number: $data['car_number'],
                ),
                owner: $carOwner,
            );

            // If the customer pre-reserved this slot via the bot, the slot
            // was already debited from free_spaces at reservation time.
            // Accepting the hold (START → ACTIVE) means we must NOT
            // decrement free_spaces a second time.
            $heldReservation = $this->reservations->findPendingHold($carOwner, $park);

            $car = $this->carService->enterPark(
                $car,
                $park->fresh(),
                alreadyFull: $heldReservation !== null,
            );

            $activeReservation = null;
            if ($heldReservation !== null) {
                $activeReservation = $this->reservations->markActive($carOwner, $park);
            }
        } catch (Throwable $e) {
            Log::error('Bot car enter failed', ['error' => $e->getMessage()]);
            $session->reset();
            return OutboundReply::text("❌ تعذّر إدخال السيارة: {$e->getMessage()}");
        }

        $session->reset();

        // Notify the customer their car has been registered as parked.
        // Best-effort — BotNotifier swallows its own errors.
        $this->notifyCustomer(
            $carOwner,
            $park,
            $car,
            $heldReservation !== null,
            $activeReservation,
        );

        $arrivalNote = $heldReservation !== null
            ? "✅ تم تأكيد وصول العميل! (الحجز مكتمل)\n"
            : "✅ تم إدخال السيارة!\n";

        return OutboundReply::text(
            $arrivalNote
            . "اللوحة: {$car->plate_prefix}-{$car->car_number}\n"
            . "الموقف: {$park->name}\n"
            . "الأماكن الفارغة: {$park->fresh()->free_spaces}"
        );
    }

    /**
     * Tell the car's owner that their vehicle was just checked into the
     * park. Routed via {@see BotNotifier} so they get the message on
     * whichever channel(s) they're enrolled in.
     *
     * If a reservation was just activated, appends a pay link so the
     * customer can settle electronically; falls back silently to cash
     * when no payment row exists.
     */
    private function notifyCustomer(
        User $carOwner,
        Park $park,
        Car $car,
        bool $fulfilledReservation,
        ?Reserve $reserve = null,
    ): void {
        $headline = $fulfilledReservation
            ? "✅ تم تأكيد حجزك! دخلت سيارتك إلى الموقف."
            : "✅ تم تسجيل دخول سيارتك إلى الموقف.";

        $body = $headline . "\n\n"
              . "🅿️ الموقف: {$park->name}\n"
              . "🚗 اللوحة: {$car->plate_prefix}-{$car->car_number}\n"
              . "🕒 وقت الدخول: " . now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i');

        if ($reserve) {
            $payment = $reserve->payments()
                ->whereIn('status', [
                    PaymentStatusTypes::CREATED->value,
                    PaymentStatusTypes::SUCCESS->value,
                ])
                ->latest()
                ->first();

            if ($payment && !$payment->isPaid()) {
                $url = route('payments.redirect', $payment->token);
                $body .= "\n\n💳 لإتمام الدفع إلكترونياً:\n{$url}\n\nأو يمكنك الدفع نقداً عند الخروج.";
            }
        }

        $this->notifier->notify($carOwner, OutboundReply::text($body));
    }

    private function merge(BotSession $session, array $patch, string $nextStep): void
    {
        $session->update([
            'data'       => array_merge($session->getData(), $patch),
            'step'       => $nextStep,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * Normalize a free-form identifier into two digit strings:
     *  - $raw:        digits as the user typed them (just Arabic/Persian
     *                 → ASCII, then non-digits stripped). Preserved so a
     *                 Telegram chat_id like "7804684895" still matches
     *                 the `telegram_chat_id` column even though it also
     *                 happens to look like an Iraqi mobile shape.
     *  - $normalized: Iraqi phone numbers reshaped to E.164-without-'+'
     *                 ("9647XXXXXXXXX"). Everything else is returned
     *                 unchanged.
     *
     * Accepted phone shapes (all map to "9647775270135"):
     *   "+964 777 527 0135", "00964 777 527 0135", "964-777-527-0135",
     *   "07775270135", "0777 527 0135", "7775270135",
     *   "٠٧٧٧٥٢٧٠١٣٥" (Arabic-Indic), "۰۷۷۷۵۲۷۰۱۳۵" (Persian).
     *
     * Returns [null, null] when input is too short to be usable.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parseIdentifier(string $input): array
    {
        // 1. Translate Arabic-Indic (٠-٩) and Persian (۰-۹) digits → ASCII.
        //    MUST happen before \D stripping or the Arabic digits get
        //    treated as non-digits and dropped.
        $ascii = DigitNormalizer::toAscii($input);

        // 2. Strip everything but digits (also drops '+', spaces, dashes).
        $raw = preg_replace('/\D+/', '', $ascii) ?? '';

        if (strlen($raw) < 6) {
            return [null, null];
        }

        // 3. Reshape known Iraqi mobile prefixes → "964XXXXXXXXXX".
        $normalized = match (true) {
            str_starts_with($raw, '00964')                          => substr($raw, 2),
            str_starts_with($raw, '964')                            => $raw,
            str_starts_with($raw, '0') && strlen($raw) === 11       => '964' . substr($raw, 1),
            strlen($raw) === 10 && str_starts_with($raw, '7')       => '964' . $raw,
            default                                                  => $raw,
        };

        return [$raw, $normalized];
    }

    /**
     * Find the driver by either a phone number (in any common shape) or
     * a Telegram chat_id.
     *
     * Tries the normalized phone form first (international without '+',
     * matching how WhatsApp stores `wa_id`), then the raw input as-is,
     * then a leading-zero-stripped variant, and finally the Telegram
     * chat_id column. Both columns are UNIQUE so we get at most one row.
     */
    private function resolveDriver(string $raw, string $normalized): ?User
    {
        return User::query()
            ->where(function ($q) use ($raw, $normalized) {
                $q->where('phone_number', $normalized)
                  ->orWhere('phone_number', $raw)
                  ->orWhere('phone_number', ltrim($raw, '0'))
                  ->orWhere('telegram_chat_id', $raw)
                  ->orWhere('telegram_chat_id', $normalized);
            })
            ->first();
    }
}
