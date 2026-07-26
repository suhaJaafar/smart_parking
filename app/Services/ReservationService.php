<?php

namespace App\Services;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Dto\OutboundReply;
use App\Enums\PaymentStatusTypes;
use App\Models\Car;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReservationService
{
    /**
     * How long an unclaimed on-site reservation holds a space before it
     * auto-expires. The customer must reach the park and have the owner let
     * their car in within this window, otherwise the every-minute sweep
     * ({@see self::expireStale()}) releases the slot.
     */
    public const HOLD_MINUTES = 10;

    /**
     * Pre-bookings are made remotely (e.g. from home for a later arrival),
     * so they get a much longer hold than an on-site reservation. Once the
     * customer pays, {@see self::expireStale()} stops touching the row at
     * all — a paid slot is never auto-released.
     */
    public const PRE_BOOKING_HOLD_MINUTES = 240;

    /**
     * When a pre-booking carries an explicit arrival time, the hold stays
     * valid until that time plus this grace window. After it lapses without
     * the owner entering the car, the every-minute sweep
     * ({@see self::expireStale()}) releases the slot — unless the customer
     * has already paid, in which case the slot is theirs and never expires.
     */
    public const PRE_BOOKING_GRACE_MINUTES = 10;

    /**
     * How long an *entered* (ACTIVE) reservation may sit unpaid before the
     * sweep ({@see self::expireStaleActive()}) force-closes it: the car is
     * auto-exited (releasing the slot) and the reservation is cancelled.
     *
     * Measured from the reservation's `created_at`. On-site holds are entered
     * within {@see self::HOLD_MINUTES} of creation, so created_at is a close
     * proxy for the entry time. This only ever touches unpaid stays — once the
     * customer pays, the slot is theirs and the reservation is never
     * auto-closed.
     */
    public const ACTIVE_UNPAID_TIMEOUT_HOURS = 24;

    /**
     * Grace window after a stay's payment settles before the sweep
     * ({@see self::closePaidStaleActive()}) auto-completes it: the car is
     * exited (releasing the slot) and the reservation → COMPLETED.
     *
     * An ACTIVE stay is only reached once the car has physically entered, so
     * a settled payment means the customer paid while parked — they are on
     * their way out. This short grace lets the owner tap "exit" themselves;
     * if they forget, the slot is released shortly after so it isn't held
     * open indefinitely.
     */
    public const PAID_STAY_EXIT_GRACE_MINUTES = 15;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly CarService $cars,
        private readonly BotNotifier $notifier,
    ) {}

    /**
     * Atomically place a hold (status = START) on one space at $park for $user.
     *
     * A hold does NOT debit parks.free_spaces — the physical slot is claimed
     * only when the owner actually enters the car (CarService::enterPark). To
     * avoid over-promising, the hold is still rejected inside a row-locked
     * transaction once (cars inside + outstanding holds) fills the park, so
     * two customers can't be promised the same last spot. The hold is later
     * released — with no slot to refund — when the owner enters the car
     * (→ ACTIVE), the TTL elapses (→ EXPIRED), or the customer cancels
     * (→ CANCELLED).
     *
     * When $scheduledAt is supplied (pre-booking for a future arrival) it is
     * recorded on the reservation and the hold window is anchored to that
     * time rather than a fixed offset from now.
     *
     * @throws RuntimeException if the park is full or user already holds a
     *                          pending reservation at this park.
     */
    public function reserve(
        User $user,
        Park $park,
        bool $preBooking = false,
        ?\DateTimeInterface $scheduledAt = null,
    ): Reserve {
        return DB::transaction(function () use ($user, $park, $preBooking, $scheduledAt) {
            // Lock the park row to serialize the availability check below.
            $locked = Park::whereKey($park->id)->lockForUpdate()->firstOrFail();

            // Prevent stacking holds on the same park. An ACTIVE row means
            // the customer's car is already inside — also a duplicate. Checked
            // first so a repeat tap idempotently returns the same hold instead
            // of tripping the capacity gate below.
            //
            // Crucially, only *live* pending holds count — a STATUS_START whose
            // TTL has already lapsed (but hasn't been flipped to EXPIRED yet by
            // the sweep) must NOT be reused: doing so re-uses its stale
            // expires_at and booking_code in the owner notification and locks
            // the customer out of ever re-reserving because the entry picker
            // (which uses the same livePending filter) will never surface it.
            $existing = Reserve::where('user_id', $user->id)
                ->where('park_id', $locked->id)
                ->where(function ($q) {
                    $q->livePending()
                        ->orWhere('status', Reserve::STATUS_ACTIVE);
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            // Any leftover stale START rows for this (user, park) pair are
            // effectively dead — flip them to EXPIRED now so the DB history
            // stays clean and the sweep has less to do. This is a no-op when
            // there is none, and is safe here because we already established
            // above that no *live* duplicate exists.
            Reserve::where('user_id', $user->id)
                ->where('park_id', $locked->id)
                ->where('status', Reserve::STATUS_START)
                ->update(['status' => Reserve::STATUS_EXPIRED]);

            // A hold no longer debits free_spaces — the slot is claimed only
            // when the car physically enters the park. We still guard against
            // over-promising: free_spaces already reflects the cars currently
            // inside, so the room left to promise is that minus the holds still
            // outstanding. Once (inside + holds) reaches capacity, we're full.
            $outstandingHolds = Reserve::where('park_id', $locked->id)
                ->livePending()
                ->count();

            if ($locked->free_spaces - $outstandingHolds < 1) {
                throw new RuntimeException('PARK_FULL');
            }

            // NOTE: free_spaces is intentionally NOT decremented here. It is
            // debited only on physical entry (CarService::enterPark).

            // A scheduled pre-booking is only meaningful when pre-booking.
            $scheduled = $preBooking ? $scheduledAt : null;

            if ($scheduled !== null) {
                $expiresAt = Carbon::instance($scheduled)
                    ->addMinutes(self::PRE_BOOKING_GRACE_MINUTES);
            } else {
                $expiresAt = now()->addMinutes(
                    $preBooking ? self::PRE_BOOKING_HOLD_MINUTES : self::HOLD_MINUTES
                );
            }

            return Reserve::create([
                'user_id'        => $user->id,
                'park_id'        => $locked->id,
                'status'         => Reserve::STATUS_START,
                'booking_code'   => Reserve::generateBookingCodeForPark($locked->id),
                'is_pre_booking' => $preBooking,
                'scheduled_at'   => $scheduled,
                'expires_at'     => $expiresAt,
            ]);
        });
    }

    /**
     * Find the customer's pending hold (status = START) at $park, if any.
     *
     * Used by the owner's car-entry flow to detect that the slot was already
     * debited at reservation time and must not be debited a second time.
     */
    public function findPendingHold(User $user, Park $park): ?Reserve
    {
        return Reserve::where('user_id', $user->id)
            ->where('park_id', $park->id)
            ->where('status', Reserve::STATUS_START)
            ->latest('created_at')
            ->first();
    }

    /**
     * Owner accepted the customer (entered their car).
     * Transitions the most recent START hold → ACTIVE.
     *
     * Does NOT touch park.free_spaces — the slot was already debited at
     * reservation time and the car is now physically occupying it.
     *
     * Side effect: provisions a {@see \App\Models\Payment} row for the
     * just-activated reservation so the customer can settle the bill
     * electronically via the pay link in their bot notification. This
     * happens AFTER the activation transaction commits — if Qi/DB is
     * flaky we still keep the car-entry: the customer's vehicle is
     * already physically in the spot, rolling that back would be wrong.
     *
     * Idempotent: returns null if there is no pending hold to activate.
     */
    public function markActive(User $user, Park $park): ?Reserve
    {
        $reserve = DB::transaction(function () use ($user, $park) {
            $reserve = Reserve::where('user_id', $user->id)
                ->where('park_id', $park->id)
                ->where('status', Reserve::STATUS_START)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if (!$reserve) {
                return null;
            }

            $reserve->update(['status' => Reserve::STATUS_ACTIVE]);
            return $reserve->fresh();
        });

        if ($reserve) {
            try {
                $this->payments->ensureForReserve($reserve);
            } catch (\Throwable $e) {
                Log::error('ensureForReserve failed after markActive', [
                    'reserve_id' => $reserve->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        return $reserve;
    }

    /**
     * Owner exited the customer's car.
     * Transitions the most recent ACTIVE reservation → COMPLETED.
     *
     * Does NOT touch park.free_spaces — CarService::exitPark already
     * increments it when the car physically leaves.
     *
     * Idempotent: returns null if there is no active reservation.
     */
    public function markCompleted(User $user, Park $park): ?Reserve
    {
        return DB::transaction(function () use ($user, $park) {
            $reserve = Reserve::where('user_id', $user->id)
                ->where('park_id', $park->id)
                ->where('status', Reserve::STATUS_ACTIVE)
                ->lockForUpdate()
                ->latest('created_at')
                ->first();

            if (!$reserve) {
                return null;
            }

            $reserve->update(['status' => Reserve::STATUS_COMPLETED]);
            return $reserve->fresh();
        });
    }

    /**
     * Customer-initiated cancellation. Refunds the slot.
     *
     * Only allowed while the reservation is still a pending hold (START).
     * Once the owner has entered the car (ACTIVE), the customer can no
     * longer cancel — they must wait for the car to exit.
     *
     * Idempotent: a reservation already past START is returned as-is.
     */
    public function cancel(Reserve $reserve): Reserve
    {
        return DB::transaction(function () use ($reserve) {
            $reserve = Reserve::whereKey($reserve->id)->lockForUpdate()->firstOrFail();

            if ($reserve->status !== Reserve::STATUS_START) {
                return $reserve;
            }

            // A START hold never debited free_spaces (the slot is only taken
            // when the car physically enters), so there is nothing to refund —
            // just release the hold.
            $reserve->update(['status' => Reserve::STATUS_CANCELLED]);
            return $reserve->fresh();
        });
    }

    /**
     * Sweep stale holds: any START reservation past its expires_at is
     * flipped to EXPIRED and its slot is refunded to the park.
     *
     * ACTIVE reservations are NEVER expired here — once the owner has
     * entered the car the slot is physically occupied; only car exit can
     * close it out.
     *
     * Designed to be called from a frequent (every-minute) scheduled task.
     * Returns the number of reservations expired this run.
     */
    public function expireStale(): int
    {
        $count = 0;

        // Pull a snapshot of stale ids, then process one-by-one inside its own
        // transaction so a single bad row doesn't poison the whole sweep.
        $staleIds = Reserve::where('status', Reserve::STATUS_START)
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($staleIds as $id) {
            // The transaction returns the row that was just expired so we can
            // notify customer + owner *outside* the DB critical section —
            // messaging is best-effort I/O and must never hold a row lock or
            // fail the sweep. Null means the row was already handled (paid,
            // re-activated, or expired by a concurrent worker).
            $expired = DB::transaction(function () use ($id, &$count): ?Reserve {
                $reserve = Reserve::whereKey($id)->lockForUpdate()->first();

                if (!$reserve || $reserve->status !== Reserve::STATUS_START) {
                    return null;
                }

                if ($reserve->expires_at === null || $reserve->expires_at->isFuture()) {
                    return null;
                }

                // A paid pre-booking must never be auto-released: the
                // customer already settled the bill for this slot.
                $paid = $reserve->payments()
                    ->where('status', PaymentStatusTypes::SUCCESS->value)
                    ->exists();

                if ($paid) {
                    return null;
                }

                // A START hold never debited free_spaces, so expiry only
                // releases the hold — there is no slot to refund.
                $reserve->update(['status' => Reserve::STATUS_EXPIRED]);
                $count++;

                return $reserve->fresh(['user', 'park.owner']);
            });

            if ($expired !== null) {
                $this->notifyHoldExpired($expired);
            }
        }

        return $count;
    }

    /**
     * Best-effort "your hold expired" notification, sent after the row has
     * been flipped to EXPIRED. Delivers a short Arabic message to BOTH the
     * customer ("reserve again to enter") and the park owner ("this hold is
     * no longer valid; ask the customer to reserve again"), through
     * {@see BotNotifier} so it reaches every channel each user is enrolled
     * on. Failures are logged and never propagate — messaging must never
     * break the sweep or roll back the state change.
     */
    private function notifyHoldExpired(Reserve $reserve): void
    {
        $customer = $reserve->user;
        $park     = $reserve->park;
        $owner    = $park?->owner;
        $parkName = $park?->name ?? '—';
        $code     = $reserve->booking_code;

        // Customer: they need to know their hold lapsed and the fix is to
        // reserve again — no penalty, no slot debited.
        if ($customer !== null) {
            try {
                $codeLine = $code ? "🔢 رمز الحجز: *{$code}*\n" : '';

                $this->notifier->notify(
                    $customer,
                    OutboundReply::text(
                        "⏰ *انتهت مدة حجزك*\n\n"
                        . "الموقف: *{$parkName}*\n"
                        . $codeLine
                        . "\nلم يتم إدخال سيارتك خلال الوقت المحدد، فتم إلغاء الحجز تلقائياً.\n"
                        . "يمكنك حجز مكان مرة أخرى بإرسال موقعك أو رقم *4⃣*."
                    ),
                );
            } catch (Throwable $e) {
                Log::warning('Customer hold-expired notification failed', [
                    'reserve_id' => $reserve->id,
                    'user_id'    => $customer->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        // Owner: mirrors the "new reservation" ping in NearbyParksFlow so
        // they can drop the stale row from mental state and know why the
        // waiting list changed. Kept short — no action required from them.
        if ($owner !== null) {
            try {
                $customerName = $customer?->name ?: 'زبون';
                $codeLine     = $code ? "🔢 رمز الحجز: *{$code}*\n" : '';

                $this->notifier->notify(
                    $owner,
                    OutboundReply::text(
                        "⏰ *انتهت مدة حجز في موقفك*\n\n"
                        . "الموقف: *{$parkName}*\n"
                        . "الزبون: *{$customerName}*\n"
                        . $codeLine
                        . "\nلم يصل الزبون في الوقت المحدد، فتم إلغاء الحجز تلقائياً.\n"
                        . "_إذا وصل، اطلب منه إعادة الحجز ليظهر في قائمة الواصلين._"
                    ),
                );
            } catch (Throwable $e) {
                Log::warning('Owner hold-expired notification failed', [
                    'reserve_id' => $reserve->id,
                    'owner_id'   => $owner->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sweep entered-but-unpaid stays: any ACTIVE reservation created more
     * than {@see self::ACTIVE_UNPAID_TIMEOUT_HOURS} hours ago which the
     * customer never paid for is force-closed — the car is auto-exited
     * (releasing the slot) and the reservation is CANCELLED.
     *
     * A paid stay is always honoured and never touched here. Designed to run
     * on the same frequent schedule as {@see self::expireStale()}.
     *
     * Returns the number of reservations closed this run.
     */
    public function expireStaleActive(): int
    {
        $count  = 0;
        $cutoff = now()->subHours(self::ACTIVE_UNPAID_TIMEOUT_HOURS);

        // Snapshot the candidate ids, then process each in its own
        // transaction so one bad row can't poison the whole sweep.
        $staleIds = Reserve::where('status', Reserve::STATUS_ACTIVE)
            ->where('created_at', '<', $cutoff)
            ->pluck('id');

        foreach ($staleIds as $id) {
            DB::transaction(function () use ($id, $cutoff, &$count) {
                $reserve = Reserve::whereKey($id)->lockForUpdate()->first();

                if (!$reserve || $reserve->status !== Reserve::STATUS_ACTIVE) {
                    return;
                }

                if ($reserve->created_at === null || $reserve->created_at->gt($cutoff)) {
                    return;
                }

                // A paid stay is the customer's — never auto-close it.
                $paid = $reserve->payments()
                    ->where('status', PaymentStatusTypes::SUCCESS->value)
                    ->exists();

                if ($paid) {
                    return;
                }

                // Physically release the car if it's still parked here;
                // CarService::exitPark also refunds the slot. If the car is
                // already gone, refund the slot defensively so it isn't lost.
                $car = Car::where('user_id', $reserve->user_id)
                    ->where('park_id', $reserve->park_id)
                    ->first();

                if ($car) {
                    $this->cars->exitPark($car);
                } else {
                    $park = Park::whereKey($reserve->park_id)->lockForUpdate()->first();
                    if ($park && $park->free_spaces < $park->capacity) {
                        $park->increment('free_spaces');
                    }
                }

                $reserve->update(['status' => Reserve::STATUS_CANCELLED]);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Sweep paid stays the owner forgot to close: any ACTIVE reservation the
     * customer already paid for, whose payment settled more than
     * {@see self::PAID_STAY_EXIT_GRACE_MINUTES} ago, is auto-completed — the
     * car is exited (releasing the slot) and the reservation → COMPLETED.
     *
     * This is the counterpart to {@see self::expireStaleActive()} (which only
     * touches *unpaid* stays). Together they guarantee an ACTIVE row can never
     * hold a slot indefinitely: unpaid stays are cancelled after the long
     * timeout, paid stays are completed shortly after settlement. Designed to
     * run on the same every-minute schedule.
     *
     * Returns the number of stays closed this run.
     */
    public function closePaidStaleActive(): int
    {
        $count  = 0;
        $cutoff = now()->subMinutes(self::PAID_STAY_EXIT_GRACE_MINUTES);

        // Snapshot the candidate ids, then process each in its own
        // transaction so one bad row can't poison the whole sweep.
        $staleIds = Reserve::where('status', Reserve::STATUS_ACTIVE)
            ->whereHas('payments', function ($query) use ($cutoff) {
                $query->where('status', PaymentStatusTypes::SUCCESS->value)
                    ->whereNotNull('paid_at')
                    ->where('paid_at', '<', $cutoff);
            })
            ->pluck('id');

        foreach ($staleIds as $id) {
            DB::transaction(function () use ($id, $cutoff, &$count) {
                $reserve = Reserve::whereKey($id)->lockForUpdate()->first();

                if (!$reserve || $reserve->status !== Reserve::STATUS_ACTIVE) {
                    return;
                }

                // Re-confirm a settled payment older than the grace window
                // (use the earliest success so an early payer isn't penalised).
                $paidAt = $reserve->payments()
                    ->where('status', PaymentStatusTypes::SUCCESS->value)
                    ->whereNotNull('paid_at')
                    ->min('paid_at');

                if ($paidAt === null || Carbon::parse($paidAt)->gt($cutoff)) {
                    return;
                }

                // Release the car if it's still parked here; exitPark also
                // refunds the slot. If the car already left, refund defensively
                // so the slot isn't lost.
                $car = Car::where('user_id', $reserve->user_id)
                    ->where('park_id', $reserve->park_id)
                    ->first();

                if ($car) {
                    $this->cars->exitPark($car);
                } else {
                    $park = Park::whereKey($reserve->park_id)->lockForUpdate()->first();
                    if ($park && $park->free_spaces < $park->capacity) {
                        $park->increment('free_spaces');
                    }
                }

                // Paid + fulfilled stay → COMPLETED (not CANCELLED).
                $reserve->update(['status' => Reserve::STATUS_COMPLETED]);
                $count++;
            });
        }

        return $count;
    }

    /**
     * All still-pending holds (START) for a park, newest first, with each
     * reservation's customer and that customer's cars (newest first)
     * eager-loaded — a single query, no per-row lookups.
     *
     * Drives the owner's car-entry picker: instead of typing a booking
     * code, the owner taps the arriving customer straight from this list.
     *
     * @return Collection<int, Reserve>
     */
    public function pendingForPark(Park $park, int $limit = 10): Collection
    {
        return Reserve::where('park_id', $park->id)
            ->livePending()
            ->with(['user.cars' => fn ($query) => $query->latest()])
            ->latest('created_at')
            ->take($limit)
            ->get();
    }

    /**
     * Number of cars still waiting to be entered (pending holds) at a park.
     * Lightweight COUNT used to annotate the park picker when an owner has
     * more than one park.
     */
    public function pendingCountForPark(Park $park): int
    {
        return Reserve::where('park_id', $park->id)
            ->livePending()
            ->count();
    }

    /**
     * Pending holds (START) across a set of parks — the cars that have
     * reserved a slot but haven't physically entered yet, newest first.
     *
     * Embedded in the owner dashboard's cars response as the "waiting to
     * enter" list. Each row carries its customer (with phone) and that
     * customer's cars (newest first) so the plate can be shown, plus the park,
     * all eager-loaded. Capped at $limit — an owner's outstanding holds are
     * naturally bounded by their parks' capacity, so no pagination is needed.
     *
     * @param  \Illuminate\Support\Collection<int, string>|array<int, string>  $parkIds
     * @return Collection<int, Reserve>
     */
    public function pendingForParkIds(
        \Illuminate\Support\Collection|array $parkIds,
        ?string $onlyParkId = null,
        int $limit = 100,
    ): Collection {
        $query = Reserve::query()
            ->whereIn('park_id', $parkIds)
            ->livePending()
            ->with([
                'park:id,name',
                'user:id,name,phone_number',
                'user.cars' => fn ($q) => $q->latest(),
            ])
            ->latest('created_at');

        if ($onlyParkId !== null) {
            $query->where('park_id', $onlyParkId);
        }

        return $query->take($limit)->get();
    }
}
