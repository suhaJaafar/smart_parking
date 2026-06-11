<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\Prompt;
use App\Enums\RoleTypes;
use App\Models\Car;
use App\Models\Park;
use App\Services\CarService;
use App\Services\ReservationService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-step flow for a SPACE_OWNER to take a car OUT of their park.
 *
 * Steps: plate → done.
 */
class CarExitFlow
{
    public const FLOW = 'car_exit';
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly CarService $carService,
        private readonly ReservationService $reservations,
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
            'plate' => $this->finish($session, $message),
            default => OutboundReply::empty(),
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
            return OutboundReply::text("🚫 لا يوجد موقف مسجل باسمك.");
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'plate',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::text(
            Prompt::ask("🚙 أرسل لوحة السيارة الخارجة (مثال: BG-12345)")
        );
    }

    private function finish(BotSession $session, string $message): OutboundReply
    {
        $message = mb_strtoupper(trim($message));
        if (!preg_match('/^([A-Z]{1,8})[\s\-]+([0-9]{1,20})$/u', $message, $m)) {
            return OutboundReply::text(
                Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. مثال: BG-12345")
            );
        }

        $data    = $session->getData();
        $parkId  = $data['park_id'] ?? null;

        $car = Car::where('plate_prefix', $m[1])
            ->where('car_number', $m[2])
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
            . "اللوحة: {$m[1]}-{$m[2]}\n"
            . "الأماكن الفارغة: " . ($park?->free_spaces ?? '?')
        );
    }
}
