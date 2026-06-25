<?php

namespace App\Bots\Flows;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;
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
 * Steps: (pick) → select_car → (plate) → done.
 *   • pick       -> only when the owner has more than one park: a tappable
 *                   list of their parks (annotated with the number of cars
 *                   waiting and free spaces) so they choose which park to
 *                   enter the car at. Skipped for single-park owners.
 *   • select_car -> every car with a pending reservation at this park is
 *                   listed as a tappable choice, labelled by plate (and the
 *                   customer's name). The owner taps the arriving vehicle
 *                   directly — there is no customer to pick and no booking
 *                   code to type. The matching reservation (and therefore the
 *                   customer) is resolved in the backend from the tapped
 *                   choice. Typing a booking code still works as a fallback.
 *   • plate      -> "BG-12345" (prefix-number), used only when the arriving
 *                   customer has no car on file yet.
 *
 * On selection the car is parked, the matching pending reservation is marked
 * ACTIVE, and the customer is notified.
 */
class CarEntryFlow
{
    public const FLOW = 'car_enter';
    private const TTL_MINUTES = 10;

    /**
     * Payload prefix for an "enter this arriving car" choice. The full
     * payload is "enter:<reserveId>" — kept short so it stays within
     * Telegram's 64-byte callback_data limit. The exact car is resolved in
     * the backend from the reservation's customer.
     */
    private const ENTRY_OPTION_PREFIX = 'enter:';

    /**
     * Payload prefix for a "choose this park" row, shown only when the owner
     * has more than one park. Full payload is "park:<parkId>".
     */
    private const PARK_OPTION_PREFIX = 'park:';

    /** Arriving cars shown — WhatsApp lists allow at most 10 rows. */
    private const MAX_ARRIVING_CARS = 10;

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
            'pick'       => $this->onPick($session, $message),
            'select_car' => $this->afterCarChoice($session, $message),
            'plate'      => $this->afterPlate($session, $message),
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

        /** @var \Illuminate\Support\Collection<int, Park> $parks */
        $parks = $owner->ownedParks()->orderBy('created_at')->get();

        if ($parks->isEmpty()) {
            return OutboundReply::text("🚫 لا يوجد موقف مسجل باسمك. أنشئ موقفاً أولاً.");
        }

        // A single park needs no disambiguation — go straight to its cars.
        if ($parks->count() === 1) {
            return $this->beginForPark($session, $parks->first());
        }

        // Multiple parks: let the owner tap which one to enter a car at,
        // each row annotated with how many cars are waiting and free spaces.
        $options = [];
        foreach ($parks as $park) {
            $waiting   = $this->reservations->pendingCountForPark($park);
            $options[] = [
                'id'          => self::PARK_OPTION_PREFIX . $park->id,
                'title'       => "📍 {$park->name}",
                'description' => "بانتظار الدخول: {$waiting} • متاح: {$park->free_spaces}",
            ];
        }

        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'pick',
            'data'       => [],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OutboundReply::buttons(
            body:       "🚗 *إدخال سيارة*\n\nاختر الموقف:",
            options:    $options,
            listButton: 'اختر الموقف',
        );
    }

    /**
     * Owner tapped a park row — resolve it (scoped to the owner) and present
     * that park's arriving cars.
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

        return $this->beginForPark($session, $park);
    }

    /**
     * Lock the flow onto a concrete park and present its arriving cars.
     * Shared by the single-park and multi-park (post-pick) paths so they
     * behave identically from here on.
     */
    private function beginForPark(BotSession $session, Park $park): OutboundReply
    {
        $session->update([
            'flow'       => self::FLOW,
            'step'       => 'select_car',
            'data'       => ['park_id' => $park->id],
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return $this->presentArrivingCars($session, $park);
    }

    /**
     * List every car with a pending reservation at this park as tappable
     * choices, each labelled by plate (and the customer's name). The owner
     * taps the arriving vehicle directly — no customer step. The reservation
     * (and its customer) is resolved in the backend from the tapped choice.
     */
    private function presentArrivingCars(BotSession $session, Park $park): OutboundReply
    {
        $pending = $this->reservations->pendingForPark($park, self::MAX_ARRIVING_CARS);

        if ($pending->isEmpty()) {
            $session->reset();
            return OutboundReply::text(
                "ℹ️ لا توجد سيارات بانتظار الدخول حالياً في *{$park->name}*.\n\n"
                . "تظهر هنا سيارات الحجوزات التي لم تدخل بعد. اطلب من الزبون إنشاء حجز أولاً."
            );
        }

        $options = $pending->map(
            fn (Reserve $reserve): array => $this->arrivingCarOption($reserve)
        )->all();

        return OutboundReply::buttons(
            body: "📍 *{$park->name}*\n\nاختر السيارة الواصلة لإدخالها:",
            options: $options,
            listButton: 'اختر السيارة',
        );
    }

    /**
     * Build one tappable row for a pending reservation, labelled by the
     * customer's most recent car (read from the eager-loaded `cars` relation,
     * newest first). The payload carries only the reservation id (to stay
     * within Telegram's 64-byte callback limit); the car is re-resolved in
     * the backend when tapped.
     *
     * @return array{id: string, title: string, description: string}
     */
    private function arrivingCarOption(Reserve $reserve): array
    {
        $customer = $reserve->user;
        $name     = $customer?->name ?: 'زبون';
        $car      = $customer?->cars->first();

        $title = $car
            ? "🚗 {$car->plate_prefix}-{$car->car_number} — {$name}"
            : "👤 {$name} — بدون سيارة مسجّلة";

        if ($car) {
            $description = $reserve->is_pre_booking ? '💳 حجز مسبق' : 'جاهزة للإدخال';
        } else {
            $badge       = $reserve->is_pre_booking ? '💳 حجز مسبق • ' : '';
            $description = $badge . 'أدخل اللوحة بعد الاختيار';
        }

        return [
            'id'          => self::ENTRY_OPTION_PREFIX . $reserve->id,
            'title'       => $title,
            'description' => $description,
        ];
    }

    /**
     * Owner tapped an arriving car. Resolve the reservation/customer in the
     * backend and enter the car.
     */
    private function afterCarChoice(BotSession $session, string $message): OutboundReply
    {
        $park = Park::find($session->getData()['park_id'] ?? null);
        if (!$park) {
            $session->reset();
            return OutboundReply::text("❌ تعذّر العثور على الموقف.");
        }

        $raw   = trim($message);
        $lower = mb_strtolower($raw);

        if (str_starts_with($lower, self::ENTRY_OPTION_PREFIX)) {
            return $this->enterFromChoice(
                $session,
                $park,
                Str::after($raw, self::ENTRY_OPTION_PREFIX),
            );
        }

        // Owner re-tapped a park button (Telegram keeps old inline buttons
        // tappable) — switch to that park's arriving cars instead of erroring.
        if (str_starts_with($lower, self::PARK_OPTION_PREFIX)) {
            $owner  = $session->getUser();
            $parkId = Str::after($raw, self::PARK_OPTION_PREFIX);
            $picked = ($owner && Str::isUuid($parkId))
                ? $owner->ownedParks()->whereKey($parkId)->first()
                : null;

            if ($picked) {
                return $this->beginForPark($session, $picked);
            }
        }

        return OutboundReply::text(
            Prompt::ask("⚠️ اختر السيارة الواصلة من القائمة أعلاه.")
        );
    }

    /**
     * Resolve a tapped "enter:<reserveId>" payload to a concrete pending
     * reservation and the customer's most recent car, then complete the
     * entry. Falls through to the plate prompt when the customer has no car
     * on file yet.
     */
    private function enterFromChoice(BotSession $session, Park $park, string $reserveId): OutboundReply
    {
        if (!Str::isUuid($reserveId)) {
            return OutboundReply::text(
                Prompt::ask("⚠️ اختر سيارة من القائمة.")
            );
        }

        $reserve = Reserve::where('park_id', $park->id)
            ->whereKey($reserveId)
            ->where('status', Reserve::STATUS_START)
            ->with(['user.cars' => fn ($query) => $query->latest()])
            ->first();

        if (!$reserve || !$reserve->user) {
            return OutboundReply::text(
                Prompt::ask("⚠️ لم يعد هذا الحجز متاحاً. اختر سيارة أخرى من القائمة.")
            );
        }

        $carOwner = $reserve->user;
        $car      = $carOwner->cars->first();

        // No car on file → ask for the plate, remembering which reservation
        // we're fulfilling so the follow-up plate step re-resolves it.
        if (!$car) {
            $this->merge($session, ['reserve_id' => $reserve->id], 'plate');
            return OutboundReply::text(
                Prompt::ask("🚗 أرسل لوحة السيارة \nمثال: 11G-12345")
            );
        }

        return $this->completeEntry($session, $park, $carOwner, $car);
    }

    /**
     * First-time car: parse the typed plate and complete the entry.
     */
    private function afterPlate(BotSession $session, string $message): OutboundReply
    {
        $plate = CarPlate::fromString($message);
        if ($plate === null) {
            return OutboundReply::text(
                Prompt::ask("⚠️ صيغة اللوحة غير صحيحة. (مثال: 11G-12345)")
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
     * reservation id. Returns null when the park is gone or the pending
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

        $reserveId = $data['reserve_id'] ?? null;
        if (!$reserveId) {
            return null;
        }

        $reserve = Reserve::where('park_id', $park->id)
            ->whereKey($reserveId)
            ->where('status', Reserve::STATUS_START)
            ->with('user')
            ->first();

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
              . "📍 الموقف: {$park->name}\n"
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
                $body .= "\n\n💳 لإتمام عملية الدفع إلكترونياً: [اضغط هنا]({$url})\n\nأو يمكنك الدفع نقداً عند الخروج.";
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
