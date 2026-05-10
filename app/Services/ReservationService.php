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
     * Atomically reserve one space at $park for $user.
     *
     * Decrements parks.free_spaces inside a row-locked transaction so two
     * customers can't grab the last spot. Returns the active Reserve.
     *
     * @throws RuntimeException if the park is full or user already has an
     *                          active reservation at this park.
     */
    public function reserve(User $user, Park $park): Reserve
    {
        return DB::transaction(function () use ($user, $park) {
            // Lock the park row to serialize free_spaces decrement.
            $locked = Park::whereKey($park->id)->lockForUpdate()->firstOrFail();

            if ($locked->free_spaces < 1) {
                throw new RuntimeException('PARK_FULL');
            }

            // Prevent stacking active reservations on the same park.
            $existing = Reserve::where('user_id', $user->id)
                ->where('park_id', $locked->id)
                ->where('status', Reserve::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $locked->decrement('free_spaces');

            return Reserve::create([
                'user_id'    => $user->id,
                'park_id'    => $locked->id,
                'status'     => Reserve::STATUS_ACTIVE,
                'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
            ]);
        });
    }

    /**
     * Find the active reservation for $user at $park, if any.
     *
     * Returned within the caller's lock scope is the responsibility of the
     * caller (we don't lock here so this is safe to call from read paths).
     */
    public function findActive(User $user, Park $park): ?Reserve
    {
        return Reserve::where('user_id', $user->id)
            ->where('park_id', $park->id)
            ->where('status', Reserve::STATUS_ACTIVE)
            ->latest('created_at')
            ->first();
    }

    /**
     * Mark the customer's active hold as fulfilled (they arrived).
     *
     * Does NOT touch park.free_spaces — the slot was already debited at
     * reservation time and the car is now physically occupying it.
     *
     * Idempotent: returns null if no active reservation exists.
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
     * Idempotent: a non-active reservation is returned as-is.
     */
    public function cancel(Reserve $reserve): Reserve
    {
        return DB::transaction(function () use ($reserve) {
            $reserve = Reserve::whereKey($reserve->id)->lockForUpdate()->firstOrFail();

            if ($reserve->status !== Reserve::STATUS_ACTIVE) {
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
     * Sweep stale holds: any ACTIVE reservation past its expires_at is
     * flipped to EXPIRED and its slot is refunded to the park.
     *
     * Designed to be called from a frequent (every-minute) scheduled task.
     * Returns the number of reservations expired this run.
     */
    public function expireStale(): int
    {
        $count = 0;

        // Pull a snapshot of stale ids, then process one-by-one inside its own
        // transaction so a single bad row doesn't poison the whole sweep.
        $staleIds = Reserve::where('status', Reserve::STATUS_ACTIVE)
            ->where('expires_at', '<', now())
            ->pluck('id');

        foreach ($staleIds as $id) {
            DB::transaction(function () use ($id, &$count) {
                $reserve = Reserve::whereKey($id)->lockForUpdate()->first();

                if (!$reserve || $reserve->status !== Reserve::STATUS_ACTIVE) {
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
