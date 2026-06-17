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
 * Steps: plate → booking_code → done.
 *   • plate         -> "BG-12345" (prefix-number)
 *   • booking_code  -> short code given by customer
 *
 * If the car doesn't exist yet, it's created (find-or-create by plate).
 * The car is then linked to the SPACE_OWNER's park and the matching
 * pending reservation is marked active.
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

        if (in_array(mb_strtolower(trim($message)), ['0', 'cancel', 'الغاء', 'إلغاء'], true)) {
            $session->reset();
            return OutboundReply::text("تم إلغاء العملية.");
        }

        if ($session->getStep() === 'idle') {
            return $this->start($session);
        }

        return match ($session->getStep()) {
            'plate'        => $this->askBookingCode($session, $message),
            'booking_code' => $this->finish($session, $message),
            default        => OutboundReply::empty(),
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

    private function askBookingCode(BotSession $session, string $message): OutboundReply
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
        ], 'booking_code');

        return OutboundReply::text(
            Prompt::ask(
                "🔑 أرسل *booking code* الذي أعطاه لك الزبون:\n"
                . "_مثال: 1234_"
            )
        );
    }

    private function finish(BotSession $session, string $message): OutboundReply
    {
        $bookingCode = trim(DigitNormalizer::toAscii($message));
        if (!preg_match('/^\d{3,4}$/', $bookingCode)) {
            return OutboundReply::text(
                Prompt::ask("⚠️ booking code غير صالح. أرسل 3 أو 4 أرقام فقط.")
            );
        }

        $data = $session->getData();
        $park = Park::find($data['park_id'] ?? null);

        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الموقف.");
        }

        $reserve = $this->reservations->findPendingByBookingCode($park, $bookingCode);
        if (!$reserve) {
            return OutboundReply::text(
                Prompt::ask("⚠️ booking code غير صالح لهذا الموقف أو منتهي الصلاحية.")
            );
        }

        $carOwner = $reserve->user;
        if (!$carOwner) {
            $session->reset();
            return OutboundReply::text("❌ لم يتم العثور على الزبون المرتبط بهذا الحجز.");
        }

        try {
            $car = $this->carService->findOrCreateByPlate(
                plate: new CarPlate(
                    prefix: $data['plate_prefix'],
                    number: $data['car_number'],
                ),
                owner: $carOwner,
            );

            // The reservation already debited the space at reservation time,
            // so we must not decrement free_spaces again.
            $car = $this->carService->enterPark(
                $car,
                $park->fresh(),
                alreadyFull: true,
            );

            // Mark the reservation as ACTIVE
            $activeReservation = $this->reservations->markActive($carOwner, $park);
        } catch (Throwable $e) {
            Log::error('Bot car enter failed', ['error' => $e->getMessage()]);
            $session->reset();
            return OutboundReply::text("❌ تعذّر إدخال السيارة: {$e->getMessage()}");
        }

        $session->reset();

        // Notify the customer their car has been registered as parked.
        $this->notifyCustomer(
            $carOwner,
            $park,
            $car,
            true,
            $activeReservation,
        );

        return OutboundReply::text(
            "✅ تم تأكيد وصول العميل! (الحجز مكتمل)\n"
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
}
