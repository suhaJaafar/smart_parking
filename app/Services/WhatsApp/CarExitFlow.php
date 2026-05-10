<?php

namespace App\Services\WhatsApp;

use App\Enums\RoleTypes;
use App\Models\Car;
use App\Models\WhatsAppSession;
use App\Services\CarService;
use App\Services\WhatsApp\Prompt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One-step flow for a SPACE_OWNER to take a car OUT of their park via WhatsApp.
 *
 * Steps: plate → done.
 */
class CarExitFlow
{
    public const FLOW = 'car_exit';
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly CarService $carService,
    ) {}

    public function handle(WhatsAppSession $session, string $message): ?string
    {
        if ($session->isExpired()) {
            $session->reset();
        }

        if (in_array(mb_strtolower(trim($message)), ['cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return "تم إلغاء العملية.";
        }

        if ($session->step === 'idle') {
            return $this->start($session);
        }

        return match ($session->step) {
            'plate' => $this->finish($session, $message),
            default => null,
        };
    }

    private function start(WhatsAppSession $session): string
    {
        $owner = $session->user;

        if (!$owner) {
            return "📱 رقمك غير مسجل في النظام.";
        }

        if (!$owner->roles()->where('role', RoleTypes::SPACE_OWNER->value)->exists()) {
            return "🚫 هذه العملية متاحة لمالكي المواقف فقط.";
        }

        $park = $owner->ownedParks()->first();
        if (!$park) {
            return "🚫 لا يوجد موقف مسجل باسمك.";
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'plate',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return Prompt::ask("🚙 أرسل لوحة السيارة الخارجة (مثال: BG-12345)");
    }

    private function finish(WhatsAppSession $session, string $message): string
    {
        $message = mb_strtoupper(trim($message));
        if (!preg_match('/^([A-Z]{1,8})[\s\-]+([0-9]{1,20})$/u', $message, $m)) {
            return Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. مثال: BG-12345");
        }

        $data    = $session->data ?? [];
        $parkId  = $data['park_id'] ?? null;

        $car = Car::where('plate_prefix', $m[1])
            ->where('car_number', $m[2])
            ->first();

        if (!$car) {
            $session->reset();
            return "❌ لا توجد سيارة بهذه اللوحة في النظام.";
        }

        if ($car->park_id !== $parkId) {
            $session->reset();
            return "❌ هذه السيارة ليست داخل موقفك حالياً.";
        }

        try {
            $car = $this->carService->exitPark($car);
        } catch (Throwable $e) {
            Log::error('WA car exit failed', ['error' => $e->getMessage()]);
            $session->reset();
            return "❌ تعذّر إخراج السيارة: {$e->getMessage()}";
        }

        $session->reset();
        $park = \App\Models\Park::find($parkId);

        return "✅ تم إخراج السيارة!\n"
             . "اللوحة: {$m[1]}-{$m[2]}\n"
             . "الأماكن الفارغة: " . ($park?->free_spaces ?? '?');
    }
}
