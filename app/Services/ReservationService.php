<?php

namespace App\Services;

use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReservationService
{
    /**
     * How long an unclaimed reservation holds a space before it auto-expires.
     */
    public const HOLD_MINUTES = 30;

    /**
     * Atomically place a hold (status = START) on one space at $park for $user.
     *
     * Decrements parks.free_spaces inside a row-locked transaction so two
     * customers can't grab the last spot. The slot stays debited until the
     * owner enters the car (→ ACTIVE → COMPLETED, slot returns on exit),
     * the TTL elapses (→ EXPIRED, slot refunded), or the customer cancels
     * (→ CANCELLED, slot refunded).
     *
     * @throws RuntimeException if the park is full or user already holds a
     *                          pending reservation at this park.
     */
    public function reserve(User $user, Park $park): Reserve
    {
        return DB::transaction(function () use ($user, $park) {
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

            return Reserve::create([
                'user_id'    => $user->id,
                'park_id'    => $locked->id,
                'status'     => Reserve::STATUS_START,
                'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
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
     * Idempotent: returns null if there is no pending hold to activate.
     */
    public function markActive(User $user, Park $park): ?Reserve
    {
        return DB::transaction(function () use ($user, $park) {
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
}
