<?php

namespace App\Console\Commands;

use App\Models\Car;
use App\Models\Park;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recompute every park's `free_spaces` from the ground truth: the number of
 * cars physically inside it (cars whose `park_id` points at the park).
 *
 *     free_spaces = capacity - (cars currently inside)
 *
 * Under the current model a reservation hold no longer debits `free_spaces`;
 * the counter only moves when a car enters (CarService::enterPark) or exits
 * (CarService::exitPark). If that pairing ever drifts — e.g. an old hold
 * decremented the counter and the crashing sweep never refunded it — the
 * displayed FREE value drops permanently below the true value. This command
 * heals that drift and is safe to run anytime; it only writes when a park is
 * actually out of sync.
 *
 * Use --dry-run to preview the corrections without touching the database.
 */
class ReconcileFreeSpaces extends Command
{
    protected $signature = 'parks:reconcile-free-spaces {--dry-run : Show what would change without writing}';

    protected $description = 'Recompute each park free_spaces from the cars physically inside it, healing any counter drift.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $fixed  = 0;

        Park::query()
            ->orderBy('name')
            ->chunkById(100, function ($parks) use ($dryRun, &$fixed) {
                foreach ($parks as $park) {
                    $inside  = Car::where('park_id', $park->id)->count();
                    $correct = max(0, $park->capacity - $inside);

                    if ($correct === $park->free_spaces) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s: free_spaces %d -> %d (capacity %d, inside %d)',
                        $park->name,
                        $park->free_spaces,
                        $correct,
                        $park->capacity,
                        $inside,
                    ));

                    if (! $dryRun) {
                        // Lock the row so a concurrent entry/exit can't race
                        // us between the read above and this write.
                        DB::transaction(function () use ($park, $correct) {
                            $locked = Park::whereKey($park->id)->lockForUpdate()->first();

                            if ($locked === null) {
                                return;
                            }

                            $inside  = Car::where('park_id', $locked->id)->count();
                            $correct = max(0, $locked->capacity - $inside);

                            if ($locked->free_spaces !== $correct) {
                                $locked->update(['free_spaces' => $correct]);
                            }
                        });
                    }

                    $fixed++;
                }
            });

        if ($fixed === 0) {
            $this->info('All parks are already in sync. Nothing to fix.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "{$fixed} park(s) would be corrected. Re-run without --dry-run to apply."
            : "Reconciled {$fixed} park(s).");

        return self::SUCCESS;
    }
}
