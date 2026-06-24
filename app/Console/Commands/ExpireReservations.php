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
 *   - ACTIVE stays the customer already paid for, but the owner forgot to
 *     exit, are auto-completed shortly after settlement: the car is exited
 *     (slot refunded) and the reservation COMPLETED.
 *
 * The work is bounded by the number of reservations that just lapsed.
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire stale holds, close unpaid 24h stays, and auto-exit paid stays the owner forgot to close.';

    public function handle(ReservationService $reservations): int
    {
        $expired    = $reservations->expireStale();
        $closed     = $reservations->expireStaleActive();
        $autoExited = $reservations->closePaidStaleActive();

        if ($expired > 0) {
            $this->info("Expired {$expired} stale hold(s).");
        }

        if ($closed > 0) {
            $this->info("Closed {$closed} unpaid stay(s) past the 24h limit.");
        }

        if ($autoExited > 0) {
            $this->info("Auto-exited {$autoExited} paid stay(s) the owner left open.");
        }

        return self::SUCCESS;
    }
}
