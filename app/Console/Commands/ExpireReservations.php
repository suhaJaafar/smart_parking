<?php

namespace App\Console\Commands;

use App\Services\ReservationService;
use Illuminate\Console\Command;

/**
 * Sweep stale reservations: any ACTIVE Reserve whose `expires_at` is in the
 * past is flipped to EXPIRED and its slot is refunded to the parent park.
 *
 * Run on a tight schedule (every minute is fine — the work is bounded by the
 * number of reservations whose hold just lapsed).
 */
class ExpireReservations extends Command
{
    protected $signature = 'reservations:expire';

    protected $description = 'Expire stale active reservations and refund their parking slots.';

    public function handle(ReservationService $reservations): int
    {
        $count = $reservations->expireStale();

        if ($count > 0) {
            $this->info("Expired {$count} stale reservation(s).");
        }

        return self::SUCCESS;
    }
}
