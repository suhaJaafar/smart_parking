<?php

namespace App\Http\Controllers\Concerns;

use Carbon\CarbonImmutable;

/**
 * Shared helpers for the owner "history / export" endpoints that accept an
 * optional `from` / `to` date window.
 *
 * Bounds are inclusive whole days: `from` snaps to the start of its day and
 * `to` to the end of its day, so a caller passing the same date for both
 * gets that entire day. Either bound may be null (open-ended).
 */
trait ResolvesDateRange
{
    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    protected function dateBounds(?string $from, ?string $to): array
    {
        $start = ($from !== null && $from !== '')
            ? CarbonImmutable::parse($from)->startOfDay()
            : null;

        $end = ($to !== null && $to !== '')
            ? CarbonImmutable::parse($to)->endOfDay()
            : null;

        return [$start, $end];
    }

    /**
     * Build a descriptive, filesystem-safe export filename, e.g.
     * `reservations_20260601-20260629.csv` or `reservations_20260729.csv`.
     */
    protected function exportFilename(
        string $prefix,
        ?CarbonImmutable $from,
        ?CarbonImmutable $to,
    ): string {
        if ($from !== null || $to !== null) {
            $range = ($from?->format('Ymd') ?? 'start')
                .'-'
                .($to?->format('Ymd') ?? 'now');
        } else {
            $range = CarbonImmutable::now()->format('Ymd');
        }

        return "{$prefix}_{$range}.csv";
    }
}
