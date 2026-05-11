<?php

namespace App\Services\WhatsApp;

use App\Enums\RoleTypes;
use App\Models\Car;
use App\Models\Park;
use App\Models\User;
use App\Models\WhatsAppSession;
use App\Services\CarService;
use App\Services\ReservationService;
use App\Services\WhatsApp\Prompt;
use App\Services\WhatsApp\WhatsAppNotifier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Two-step flow for a SPACE_OWNER to bring a car INTO their park via WhatsApp.
 *
 * Steps: plate → phone → done.
 *   • plate  -> "BG-12345" (prefix-number)
 *   • phone  -> phone number of the car owner (registered user)
 *
 * If the car doesn't exist yet, it's created (find-or-create by plate).
 * The car is then linked to the SPACE_OWNER's park and free_spaces is decremented.
 */
class CarEntryFlow
{
    public const FLOW = 'car_enter';
    private const TTL_MINUTES = 10;

    public function __construct(
        private readonly CarService $carService,
        private readonly ReservationService $reservations,
        private readonly WhatsAppNotifier $notifier,
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
            'plate' => $this->askPhone($session, $message),
            'phone' => $this->finish($session, $message),
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
            return "🚫 لا يوجد موقف مسجل باسمك. أنشئ موقفاً أولاً.";
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'plate',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return Prompt::ask("🚗 أرسل لوحة السيارة بالشكل: PREFIX-NUMBER\nمثال: BG-12345");
    }

    private function askPhone(WhatsAppSession $session, string $message): string
    {
        [$prefix, $number] = $this->parsePlate($message);
        if ($prefix === null) {
            return Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. أرسل: PREFIX-NUMBER (مثال: BG-12345)");
        }

        $this->merge($session, [
            'plate_prefix' => $prefix,
            'car_number'   => $number,
        ], 'phone');

        return Prompt::ask("📱 أرسل رقم هاتف صاحب السيارة (مثال: 9647701234567)");
    }

    private function finish(WhatsAppSession $session, string $message): string
    {
        $phone = preg_replace('/\D/', '', trim($message));
        if ($phone === '' || strlen($phone) < 8) {
            return Prompt::ask("⚠️ رقم هاتف غير صحيح. أرسل رقماً صالحاً.");
        }

        $carOwner = User::where('phone_number', $phone)
            ->orWhere('phone_number', ltrim($phone, '0'))
            ->first();

        if (!$carOwner) {
            $session->reset();
            return "❌ لا يوجد مستخدم بهذا الرقم. اطلب من المالك التسجيل أولاً.";
        }

        $data = $session->data ?? [];
        $park = Park::find($data['park_id'] ?? null);

        if (!$park) {
            $session->reset();
            return "❌ تعذّر العثور على الموقف.";
        }

        try {
            $car = $this->carService->findOrCreateByPlate(
                platePrefix: $data['plate_prefix'],
                carNumber:   $data['car_number'],
                owner:       $carOwner,
            );

            // If the customer pre-reserved this slot via the bot, the space
            // was already debited from free_spaces at reservation time.
            // Accepting the hold (START → ACTIVE) means we must NOT decrement
            // free_spaces a second time.
            $heldReservation = $this->reservations->findPendingHold($carOwner, $park);

            $car = $this->carService->enterPark(
                $car,
                $park->fresh(),
                alreadyHeld: $heldReservation !== null,
            );

            if ($heldReservation !== null) {
                $this->reservations->markActive($carOwner, $park);
            }
        } catch (Throwable $e) {
            Log::error('WA car enter failed', ['error' => $e->getMessage()]);
            $session->reset();
            return "❌ تعذّر إدخال السيارة: {$e->getMessage()}";
        }

        $session->reset();

        // Notify the customer their car has been registered as parked.
        // Best-effort — the WhatsAppNotifier swallows its own errors so a
        // failed notification cannot break the owner's flow.
        $this->notifyCustomer($carOwner, $park, $car, $heldReservation !== null);

        $arrivalNote = $heldReservation !== null
            ? "✅ تم تأكيد وصول العميل! (الحجز مكتمل)\n"
            : "✅ تم إدخال السيارة!\n";

        return $arrivalNote
             . "اللوحة: {$car->plate_prefix}-{$car->car_number}\n"
             . "الموقف: {$park->name}\n"
             . "الأماكن الفارغة: {$park->fresh()->free_spaces}";
    }

    /**
     * Tell the car's owner (the customer) that their vehicle was just
     * checked into the park by the SPACE_OWNER.
     */
    private function notifyCustomer(User $carOwner, Park $park, Car $car, bool $fulfilledReservation): void
    {
        $phone = $carOwner->phone_number;
        if (!$phone) {
            return;
        }

        $headline = $fulfilledReservation
            ? "✅ تم تأكيد حجزك! دخلت سيارتك إلى الموقف."
            : "✅ تم تسجيل دخول سيارتك إلى الموقف.";

        $body = $headline . "\n\n"
              . "🅿️ الموقف: {$park->name}\n"
              . "🚗 اللوحة: {$car->plate_prefix}-{$car->car_number}\n"
              . "🕒 وقت الدخول: " . now()->setTimezone(config('app.timezone'))->format('Y-m-d H:i');

        $this->notifier->send($phone, $body);
    }

    private function merge(WhatsAppSession $session, array $patch, string $nextStep): void
    {
        $session->update([
            'data'       => array_merge($session->data ?? [], $patch),
            'step'       => $nextStep,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);
    }

    /**
     * Parse "BG-12345" or "BG 12345" into ['BG', '12345'].
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parsePlate(string $message): array
    {
        $message = mb_strtoupper(trim($message));
        if (!preg_match('/^([A-Z]{1,8})[\s\-]+([0-9]{1,20})$/u', $message, $m)) {
            return [null, null];
        }
        return [$m[1], $m[2]];
    }
}
