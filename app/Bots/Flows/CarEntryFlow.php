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
use Illuminate\Support\Str;
use Throwable;

/**
 * Flow for a SPACE_OWNER to bring a car INTO their park.
 *
 * Steps: select_reservation → (select_car | plate) → done.
 *   • select_reservation -> the customers who currently hold a pending
 *                           reservation at this park are listed as tappable
 *                           choices. The owner just taps the arriving
 *                           customer — no booking code to type. Typing a
 *                           booking code still works as a fallback.
 *   • select_car         -> the chosen customer's known cars are shown as
 *                           tappable choices so a RETURNING car never has to
 *                           be retyped. Tapping "➕ سيارة جديدة" (or typing a
 *                           plate) falls through to the `plate` step.
 *   • plate              -> "BG-12345" (prefix-number) for a first-time car.
 *
 * The car is found-or-created by plate when typed, linked to the
 * SPACE_OWNER's park, and the matching pending reservation is marked
 * active.
 */
class CarEntryFlow
{
    public const FLOW = 'car_enter';
    private const TTL_MINUTES = 10;

    /** Payload prefix for a "select this pending reservation" choice. */
    private const RESERVATION_OPTION_PREFIX = 'rsv:';

    /** Payload prefix for a "select this known car" choice. */
    private const CAR_OPTION_PREFIX = 'car:';

    /** Payload / words that mean "this car isn't in the list, let me type it". */
    private const NEW_CAR_OPTION = 'car:new';
    private const NEW_CAR_WORDS  = ['جديدة', 'سيارة جديدة', 'new', 'new car'];

    /** Cars shown per customer — WhatsApp lists allow at most 10 rows. */
    private const MAX_CAR_CHOICES = 9;

    /** Pending reservations shown — WhatsApp lists allow at most 10 rows. */
    private const MAX_RESERVATION_CHOICES = 10;

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
            'select_reservation' => $this->afterReservationChoice($session, $message),
            'select_car'         => $this->afterCarChoice($session, $message),
            'plate'              => $this->afterPlate($session, $message),
            default              => OutboundReply::empty(),
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
            'step'       => 'select_reservation',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $this->presentReservations($session, $park);
    }

    /**
     * List the customers who currently hold a pending reservation at this
     * park as tappable choices. The owner picks the arriving customer
     * instead of typing a booking code.
     */
    private function presentReservations(BotSession $session, Park $park): OutboundReply
    {
        $pending = $this->reservations->pendingForPark($park, self::MAX_RESERVATION_CHOICES);

        if ($pending->isEmpty()) {
            $session->reset();
            return OutboundReply::text(
                "ℹ️ لا توجد حجوزات معلّقة حالياً في *{$park->name}*.\n\n"
                . "تظهر هنا الحجوزات التي لم تدخل سياراتها بعد. اطلب من الزبون إنشاء حجز أولاً."
            );
        }

        $options = $pending->map(
            fn (Reserve $reserve): array => $this->reservationOption($reserve)
        )->all();

        return OutboundReply::buttons(
            body: "🅿️ *{$park->name}*\n\nاختر الزبون الواصل لإدخال سيارته:",
            options: $options,
            listButton: 'اختر الحجز',
        );
    }

    /**
     * Build one tappable picker row for a pending reservation. Surfaces the
     * customer's most recent car plate (read from the eager-loaded `cars`
     * relation, newest first) so the owner can match the physically-
     * arriving vehicle at a glance.
     *
     * @return array{id: string, title: string, description: string}
     */
    private function reservationOption(Reserve $reserve): array
    {
        $customer = $reserve->user;
        $name     = $customer?->name ?: 'زبون';
        $car      = $customer?->cars->first();

        $title = $car
            ? "🚗 {$car->plate_prefix}-{$car->car_number} — {$name}"
            : "👤 {$name}";

        $description = ($reserve->is_pre_booking ? '💳 حجز مسبق • ' : '')
            . "رمز: {$reserve->booking_code}";

        return [
            'id'          => self::RESERVATION_OPTION_PREFIX . $reserve->id,
            'title'       => $title,
            'description' => $description,
        ];
    }

    /**
     * Owner tapped a pending reservation (or typed a booking code as a
     * fallback). Resolve the customer and move on to picking the car.
     */
    private function afterReservationChoice(BotSession $session, string $message): OutboundReply
    {
        $park = Park::find($session->getData()['park_id'] ?? null);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الموقف.");
        }

        $raw   = trim($message);
        $lower = mb_strtolower($raw);

        $reserve = null;

        if (str_starts_with($lower, self::RESERVATION_OPTION_PREFIX)) {
            // A tapped list choice.
            $reserveId = Str::after($raw, self::RESERVATION_OPTION_PREFIX);
            $reserve   = Reserve::where('park_id', $park->id)
                ->whereKey($reserveId)
                ->where('status', Reserve::STATUS_START)
                ->with('user')
                ->first();
        } else {
            // Fallback: owner typed the booking code by hand.
            $code = DigitNormalizer::toAscii($raw);
            if (preg_match('/^\d{3,4}$/', $code)) {
                $reserve = $this->reservations->findPendingByBookingCode($park, $code);
            }
        }

        if (!$reserve) {
            return OutboundReply::text(
                Prompt::ask("⚠️ اختر حجزاً من القائمة، أو أرسل *booking code* الصحيح (3 أو 4 أرقام).")
            );
        }

        $carOwner = $reserve->user;
        if (!$carOwner) {
            $session->reset();
            return OutboundReply::text("❌ لم يتم العثور على الزبون المرتبط بهذا الحجز.");
        }

        // Remember the validated booking code so later steps re-resolve the
        // very same pending reservation (single source of truth).
        $this->merge($session, ['booking_code' => $reserve->booking_code], 'select_reservation');

        return $this->presentCarChoices($session, $park, $carOwner);
    }

    /**
     * Show the customer's previously-seen cars as tappable choices. A
     * returning car is selected, never retyped. Falls back to the plate
     * prompt when the customer has no cars on file yet.
     */
    private function presentCarChoices(BotSession $session, Park $park, User $carOwner): OutboundReply
    {
        $cars = $carOwner->cars()
            ->latest()
            ->take(self::MAX_CAR_CHOICES)
            ->get();

        if ($cars->isEmpty()) {
            $this->merge($session, [], 'plate');
            return OutboundReply::text(
                Prompt::ask("🚗 أرسل لوحة السيارة بالشكل: PREFIX-NUMBER\nمثال: BG-12345")
            );
        }

        $options = [];
        foreach ($cars as $car) {
            $option = [
                'id'    => self::CAR_OPTION_PREFIX . $car->id,
                'title' => "🚗 {$car->plate_prefix}-{$car->car_number}",
            ];
            if (!empty($car->model)) {
                $option['description'] = (string) $car->model;
            }
            $options[] = $option;
        }
        $options[] = ['id' => self::NEW_CAR_OPTION, 'title' => '➕ سيارة جديدة'];

        $this->merge($session, [], 'select_car');

        $customerName = $carOwner->name ?: 'الزبون';

        return OutboundReply::buttons(
            body: "👤 الزبون: *{$customerName}*\n🅿️ الموقف: *{$park->name}*\n\n"
                . "اختر سيارة الزبون من القائمة، أو *سيارة جديدة* لإدخال لوحة جديدة:",
            options: $options,
            listButton: 'اختر السيارة',
        );
    }

    /**
     * Owner tapped a car choice (or typed a fallback). Resolve it to a
     * concrete car and complete the entry.
     */
    private function afterCarChoice(BotSession $session, string $message): OutboundReply
    {
        $context = $this->context($session);
        if ($context === null) {
            $session->reset();
            return OutboundReply::text("❌ انتهت صلاحية الحجز. ابدأ العملية من جديد.");
        }
        [$park, $carOwner] = $context;

        $raw   = trim($message);
        $lower = mb_strtolower($raw);

        // "New car" — drop to the plate prompt.
        if ($lower === self::NEW_CAR_OPTION || in_array($lower, self::NEW_CAR_WORDS, true)) {
            $this->merge($session, [], 'plate');
            return OutboundReply::text(
                Prompt::ask("🚗 أرسل لوحة السيارة بالشكل: PREFIX-NUMBER\nمثال: BG-12345")
            );
        }

        // A known-car selection.
        if (str_starts_with($lower, self::CAR_OPTION_PREFIX)) {
            $carId = Str::after($raw, self::CAR_OPTION_PREFIX);
            $car   = $carOwner->cars()->whereKey($carId)->first();

            if (!$car) {
                return OutboundReply::text(
                    Prompt::ask("⚠️ لم أتعرف على هذه السيارة. اختر من القائمة أو أرسل *سيارة جديدة*.")
                );
            }

            return $this->completeEntry($session, $park, $carOwner, $car);
        }

        // Fallback: owner typed a plate directly instead of tapping.
        $plate = CarPlate::fromString($raw);
        if ($plate !== null) {
            return $this->enterWithPlate($session, $park, $carOwner, $plate);
        }

        return OutboundReply::text(
            Prompt::ask("⚠️ اختر سيارة من القائمة، أو أرسل *سيارة جديدة* لإدخال لوحة جديدة.")
        );
    }

    /**
     * First-time car: parse the typed plate and complete the entry.
     */
    private function afterPlate(BotSession $session, string $message): OutboundReply
    {
        $plate = CarPlate::fromString($message);
        if ($plate === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. أرسل: PREFIX-NUMBER (مثال: BG-12345)")
            );
        }

        $context = $this->context($session);
        if ($context === null) {
            $session->reset();
            return OutboundReply::text("❌ انتهت صلاحية الحجز. ابدأ العملية من جديد.");
        }
        [$park, $carOwner] = $context;

        return $this->enterWithPlate($session, $park, $carOwner, $plate);
    }

    private function enterWithPlate(BotSession $session, Park $park, User $carOwner, CarPlate $plate): OutboundReply
    {
        $car = $this->carService->findOrCreateByPlate(plate: $plate, owner: $carOwner);

        return $this->completeEntry($session, $park, $carOwner, $car);
    }

    /**
     * Park the resolved car, activate the reservation, notify the customer
     * and confirm to the owner. Shared by both the known-car and new-plate
     * paths so they behave identically.
     */
    private function completeEntry(BotSession $session, Park $park, User $carOwner, Car $car): OutboundReply
    {
        try {
            // The reservation already debited the space at reservation time,
            // so we must not decrement free_spaces again.
            $car = $this->carService->enterPark(
                $car,
                $park->fresh(),
                alreadyFull: true,
            );

            // Mark the reservation as ACTIVE.
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
     * Re-resolve the (park, customer) pair from the session's stored
     * booking code. Returns null when the park is gone or the pending
     * reservation no longer exists (e.g. it expired between steps).
     *
     * @return array{0: Park, 1: User}|null
     */
    private function context(BotSession $session): ?array
    {
        $data = $session->getData();

        $park = Park::find($data['park_id'] ?? null);
        if (!$park) {
            return null;
        }

        $bookingCode = $data['booking_code'] ?? null;
        if (!$bookingCode) {
            return null;
        }

        $reserve = $this->reservations->findPendingByBookingCode($park, $bookingCode);
        if (!$reserve || !$reserve->user) {
            return null;
        }

        return [$park, $reserve->user];
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
