<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * Sweep stale reservations on a tight (every-minute) schedule:
 *   - START holds past their `expires_at` are flipped to EXPIRED and their
 *     slot refunded (customer never arrived / owner never entered the car).
 *   - ACTIVE stays that are still unpaid 24h after they were created are
 *     force-closed: the car is auto-exited (slot refunded) and the
 *     reservation CANCELLED.
 *
 * The work is bounded by the number of reservations that just lapsed.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire stale holds and close unpaid 24h stays, refunding their parking slots.';

    public function handle(ReservationService $reservations): int
    {
        $expired = $reservations->expireStale();
        $closed  = $reservations->expireStaleActive();

        if ($expired > 0) {
            $this->info("Expired {$expired} stale hold(s).");
        }

        if ($closed > 0) {
            $this->info("Closed {$closed} unpaid stay(s) past the 24h limit.");
        }

        return self::SUCCESS;
    }
}
