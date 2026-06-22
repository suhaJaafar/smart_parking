<?php

namespace App\Services;

use App\Enums\PaymentStatusTypes;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    /**
     * Atomically place a hold (status = START) on one space at $park for $user.
     *
     * Decrements parks.free_spaces inside a row-locked transaction so two
     * customers can't grab the last spot. The slot stays debited until the
     * owner enters the car (→ ACTIVE → COMPLETED, slot returns on exit),
     * the TTL elapses (→ EXPIRED, slot refunded), or the customer cancels
     * (→ CANCELLED, slot refunded).
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
            // Lock the park row to serialize free_spaces decrement.
            $locked = Park::whereKey($park->id)->lockForUpdate()->firstOrFail();

            if ($locked->free_spaces < 1) {
                throw new RuntimeException('PARK_FULL');
            }

            // Prevent stacking holds on the same park. An ACTIVE row means
            // the customer's car is already inside — also a duplicate.
            $existing = Reserve::where('user_id', $user->id)
                ->where('park_id', $locked->id)
                ->whereIn('status', [Reserve::STATUS_START, Reserve::STATUS_ACTIVE])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $locked->decrement('free_spaces');

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

            $park = Park::whereKey($reserve->park_id)->lockForUpdate()->firstOrFail();

            if ($park->free_spaces < $park->capacity) {
                $park->increment('free_spaces');
            }

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
            DB::transaction(function () use ($id, &$count) {
                $reserve = Reserve::whereKey($id)->lockForUpdate()->first();

                if (!$reserve || $reserve->status !== Reserve::STATUS_START) {
                    return;
                }

                if ($reserve->expires_at === null || $reserve->expires_at->isFuture()) {
                    return;
                }

                // A paid pre-booking must never be auto-released: the
                // customer already settled the bill for this slot.
                $paid = $reserve->payments()
                    ->where('status', PaymentStatusTypes::SUCCESS->value)
                    ->exists();

                if ($paid) {
                    return;
                }

                $park = Park::whereKey($reserve->park_id)->lockForUpdate()->first();

                if ($park && $park->free_spaces < $park->capacity) {
                    $park->increment('free_spaces');
                }

                $reserve->update(['status' => Reserve::STATUS_EXPIRED]);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Find the pending reservation by booking code within a specific park.
     *
     * Scope to START only so the same code cannot be reused to re-enter
     * an already-activated reservation.
     */
    public function findPendingByBookingCode(Park $park, string $bookingCode): ?Reserve
    {
        return Reserve::where('park_id', $park->id)
            ->where('booking_code', $bookingCode)
            ->where('status', Reserve::STATUS_START)
            ->with('user')
            ->latest('created_at')
            ->first();
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
            ->where('status', Reserve::STATUS_START)
            ->with(['user.cars' => fn ($query) => $query->latest()])
            ->latest('created_at')
            ->take($limit)
            ->get();
    }
}
