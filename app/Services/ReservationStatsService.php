<?php

namespace App\Services;

use App\Models\Reserve;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregated reservations analytics.
 *
 * Mirrors the contract of {@see OwnerStatsService} and
 * {@see AdminStatsService}: a single public `report()` method returns a
 * plain array shaped exactly the way the frontend consumes it. All heavy
 * lifting stays in SQL — the controllers are thin presenters that just
 * hand in a scope (owner vs. admin) and a date range.
 *
 * Duration semantics: for a `COMPLETED` reservation, we approximate the
 * end-to-end time in the system as `updated_at - created_at`. The Reserve
 * table does not carry a dedicated `entered_at` / `exited_at` (see the
 * memory notes for why that column was rolled back), so this is the best
 * available signal. In practice `created_at` is within `HOLD_MINUTES` of
 * the physical entry, so it's a close-enough proxy for how long the car
 * stayed in the garage.
 */
final class ReservationStatsService
{
    private const DEFAULT_RANGE_DAYS = 30;
    private const RECENT_LIMIT       = 20;
    private const TOP_PARKS_LIMIT    = 10;

    /**
     * Duration histogram buckets, in minutes. `to = null` means "and above".
     *
     * @var array<int, array{label: string, from: int, to: int|null}>
     */
    private const DURATION_BUCKETS = [
        ['label' => '< 15 min',  'from' => 0,   'to' => 15],
        ['label' => '15–30 min', 'from' => 15,  'to' => 30],
        ['label' => '30–60 min', 'from' => 30,  'to' => 60],
        ['label' => '1–2 hrs',   'from' => 60,  'to' => 120],
        ['label' => '2–4 hrs',   'from' => 120, 'to' => 240],
        ['label' => '4–8 hrs',   'from' => 240, 'to' => 480],
        ['label' => '8+ hrs',    'from' => 480, 'to' => null],
    ];

    /**
     * Build the full reservations-analytics payload.
     *
     * @param  Collection<int, string>|null  $parkScope
     *         The park IDs to restrict the report to. `null` = every park
     *         on the platform (admin view). An empty collection means the
     *         caller owns no parks and everything comes back as zero — the
     *         frontend can render an empty state without a special case.
     * @param  string|null  $parkId
     *         Optional single-park narrowing. When set, the caller must
     *         already have validated it belongs to `$parkScope`.
     */
    public function report(
        ?Collection $parkScope,
        ?string $parkId,
        ?CarbonInterface $from,
        ?CarbonInterface $to,
    ): array {
        [$from, $to] = $this->normalizeRange($from, $to);
        $parkIds     = $this->resolveScope($parkScope, $parkId);

        // The caller is scoped to zero parks (owner with no garages yet, or
        // an empty-list filter) — return the shell so the UI still renders.
        if ($parkIds !== null && $parkIds->isEmpty()) {
            return $this->emptyPayload($from, $to);
        }

        return [
            'range'            => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'totals'           => $this->totals($parkIds, $from, $to),
            'by_status'        => $this->byStatus($parkIds, $from, $to),
            'by_day'           => $this->byDay($parkIds, $from, $to),
            'by_hour'          => $this->byHour($parkIds, $from, $to),
            'by_park'          => $this->byPark($parkIds, $from, $to),
            'duration_buckets' => $this->durationBuckets($parkIds, $from, $to),
            'recent'           => $this->recent($parkIds, $from, $to),
        ];
    }

    /**
     * Headline KPIs. One round trip; every count comes from a filtered
     * aggregate expression so we can grab the whole line in a single query.
     */
    private function totals(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): array {
        /** @var object{
         *   total:int, completed:int, cancelled:int, expired:int,
         *   active:int, waiting:int, pre_booking:int, on_site:int,
         *   unique_customers:int, avg_duration:float|null,
         *   total_duration:float|null,
         * }|null $row
         */
        $row = $this->baseQuery($parkIds, $from, $to)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where status = ?) as completed', [Reserve::STATUS_COMPLETED])
            ->selectRaw('count(*) filter (where status = ?) as cancelled', [Reserve::STATUS_CANCELLED])
            ->selectRaw('count(*) filter (where status = ?) as expired',   [Reserve::STATUS_EXPIRED])
            ->selectRaw('count(*) filter (where status = ?) as active',    [Reserve::STATUS_ACTIVE])
            ->selectRaw('count(*) filter (where status = ?) as waiting',   [Reserve::STATUS_START])
            ->selectRaw('count(*) filter (where is_pre_booking = true)  as pre_booking')
            ->selectRaw('count(*) filter (where is_pre_booking = false) as on_site')
            ->selectRaw('count(distinct user_id) as unique_customers')
            ->selectRaw(
                'avg(extract(epoch from (updated_at - created_at))) filter (where status = ?) as avg_duration',
                [Reserve::STATUS_COMPLETED]
            )
            ->selectRaw(
                'sum(extract(epoch from (updated_at - created_at))) filter (where status = ?) as total_duration',
                [Reserve::STATUS_COMPLETED]
            )
            ->first();

        $total     = (int) ($row->total ?? 0);
        $completed = (int) ($row->completed ?? 0);
        $cancelled = (int) ($row->cancelled ?? 0);

        return [
            'total_reservations'     => $total,
            'completed'              => $completed,
            'cancelled'              => $cancelled,
            'expired'                => (int) ($row->expired ?? 0),
            'active'                 => (int) ($row->active ?? 0),
            'waiting'                => (int) ($row->waiting ?? 0),
            'pre_booking'            => (int) ($row->pre_booking ?? 0),
            'on_site'                => (int) ($row->on_site ?? 0),
            'unique_customers'       => (int) ($row->unique_customers ?? 0),
            'avg_duration_minutes'   => $this->secondsToMinutes($row->avg_duration ?? null),
            'total_duration_minutes' => (int) round((float) ($row->total_duration ?? 0) / 60),
            'completion_rate'        => $this->rate($completed, $total),
            'cancellation_rate'      => $this->rate($cancelled, $total),
        ];
    }

    /**
     * Reservations grouped by lifecycle status. Ordered the way the badges
     * appear in the UI so the donut segments always render in a stable
     * sequence.
     */
    private function byStatus(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $rows = $this->baseQuery($parkIds, $from, $to)
            ->select('status')
            ->selectRaw('count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        $order = [
            Reserve::STATUS_START     => 'Waiting',
            Reserve::STATUS_ACTIVE    => 'Active',
            Reserve::STATUS_COMPLETED => 'Completed',
            Reserve::STATUS_EXPIRED   => 'Expired',
            Reserve::STATUS_CANCELLED => 'Cancelled',
        ];

        return collect($order)->map(fn (string $label, int $status) => [
            'status' => $status,
            'label'  => $label,
            'count'  => (int) ($rows[$status] ?? 0),
        ])->values();
    }

    /**
     * Reservations per day. Densified: every day in the range is present so
     * the time-series chart doesn't collapse across empty spans.
     */
    private function byDay(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $rows = $this->baseQuery($parkIds, $from, $to)
            ->selectRaw("to_char(date_trunc('day', created_at), 'YYYY-MM-DD') as date")
            ->selectRaw('count(*) as count')
            ->selectRaw('count(*) filter (where status = ?) as completed', [Reserve::STATUS_COMPLETED])
            ->groupBy(DB::raw("date_trunc('day', created_at)"))
            ->orderBy(DB::raw("date_trunc('day', created_at)"))
            ->get()
            ->keyBy('date');

        // Densify with zeros so the line chart has one point per day.
        $period = CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay());

        return collect($period)->map(function (CarbonInterface $day) use ($rows) {
            $key = $day->format('Y-m-d');
            $row = $rows->get($key);

            return [
                'date'      => $key,
                'count'     => (int) ($row->count ?? 0),
                'completed' => (int) ($row->completed ?? 0),
            ];
        })->values();
    }

    /**
     * Peak-hour histogram (0..23) using the reservation's `created_at`.
     */
    private function byHour(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $rows = $this->baseQuery($parkIds, $from, $to)
            ->selectRaw('extract(hour from created_at)::int as hour')
            ->selectRaw('count(*) as count')
            ->groupBy(DB::raw('extract(hour from created_at)'))
            ->pluck('count', 'hour');

        return collect(range(0, 23))->map(fn (int $hour) => [
            'hour'  => $hour,
            'count' => (int) ($rows[$hour] ?? 0),
        ]);
    }

    /**
     * Top parks by reservation volume, together with their average
     * completed-stay duration.
     */
    private function byPark(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        return $this->baseQuery($parkIds, $from, $to)
            ->join('parks', 'parks.id', '=', 'reserves.park_id')
            ->select('parks.id as park_id', 'parks.name as name')
            ->selectRaw('count(*) as count')
            ->selectRaw(
                'avg(extract(epoch from (reserves.updated_at - reserves.created_at))) filter (where reserves.status = ?) as avg_duration',
                [Reserve::STATUS_COMPLETED]
            )
            ->groupBy('parks.id', 'parks.name')
            ->orderByDesc('count')
            ->limit(self::TOP_PARKS_LIMIT)
            ->get()
            ->map(fn ($row) => [
                'park_id'              => (string) $row->park_id,
                'name'                 => (string) $row->name,
                'count'                => (int) $row->count,
                'avg_duration_minutes' => $this->secondsToMinutes($row->avg_duration ?? null),
            ]);
    }

    /**
     * Stay-duration histogram, restricted to COMPLETED reservations (the
     * only status where "duration in park" is a meaningful figure).
     */
    private function durationBuckets(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $base = $this->baseQuery($parkIds, $from, $to)
            ->where('status', Reserve::STATUS_COMPLETED);

        $selects = [];
        foreach (self::DURATION_BUCKETS as $i => $bucket) {
            $fromMin = (int) $bucket['from'];
            $alias   = "b_{$i}";

            if ($bucket['to'] === null) {
                $selects[] = "count(*) filter (where extract(epoch from (updated_at - created_at)) / 60 >= {$fromMin}) as {$alias}";
            } else {
                $toMin     = (int) $bucket['to'];
                $selects[] = "count(*) filter (where extract(epoch from (updated_at - created_at)) / 60 >= {$fromMin} and extract(epoch from (updated_at - created_at)) / 60 < {$toMin}) as {$alias}";
            }
        }

        $row = $base->selectRaw(implode(', ', $selects))->first();

        return collect(self::DURATION_BUCKETS)->map(fn (array $bucket, int $i) => [
            'label'   => $bucket['label'],
            'from_min' => (int) $bucket['from'],
            'to_min'   => $bucket['to'] !== null ? (int) $bucket['to'] : null,
            'count'    => (int) ($row?->{"b_{$i}"} ?? 0),
        ])->values();
    }

    /**
     * The most recent reservations in the window, in the shape the
     * dashboard detail table expects. Kept small (20 rows) so the payload
     * stays lean — the dedicated reservations list is one click away for
     * full paging.
     */
    private function recent(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): Collection {
        $query = Reserve::query()
            ->whereBetween('created_at', [$from, $to])
            ->with([
                'park:id,name',
                'user:id,name,phone_number',
                'user.cars' => fn ($q) => $q->latest(),
            ])
            ->latest('created_at')
            ->limit(self::RECENT_LIMIT);

        if ($parkIds !== null) {
            $query->whereIn('park_id', $parkIds);
        }

        return $query->get()->map(function (Reserve $r) {
            $car = $r->user?->cars?->first();
            $isCompleted = (int) $r->status === Reserve::STATUS_COMPLETED;
            $durationMin = $isCompleted && $r->created_at !== null && $r->updated_at !== null
                ? max(0, (int) round($r->created_at->diffInSeconds($r->updated_at, false) / 60))
                : null;

            return [
                'id'               => $r->id,
                'booking_code'     => $r->booking_code,
                'status'           => (int) $r->status,
                'status_label'     => $this->statusLabel((int) $r->status),
                'is_pre_booking'   => (bool) $r->is_pre_booking,
                'park'             => $r->park ? [
                    'id'   => $r->park->id,
                    'name' => $r->park->name,
                ] : null,
                'customer'         => $r->user ? [
                    'id'           => $r->user->id,
                    'name'         => $r->user->name,
                    'phone_number' => $r->user->phone_number,
                ] : null,
                'car'              => $car ? [
                    'id'    => $car->id,
                    'plate' => trim("{$car->plate_prefix}-{$car->car_number}", '-'),
                ] : null,
                'from_iso'         => $r->created_at?->toIso8601String(),
                'to_iso'           => $isCompleted ? $r->updated_at?->toIso8601String() : null,
                'duration_minutes' => $durationMin,
            ];
        });
    }

    /**
     * Base filtered query used by every aggregate. Kept as a DB query
     * builder (not Eloquent) so aggregate SQL is cheap. Column names are
     * fully qualified because `byPark()` joins the `parks` table (which
     * also has `created_at` / `park_id`).
     */
    private function baseQuery(
        ?Collection $parkIds,
        CarbonInterface $from,
        CarbonInterface $to,
    ): QueryBuilder {
        $query = DB::table('reserves')
            ->whereBetween('reserves.created_at', [$from, $to]);

        if ($parkIds !== null) {
            $query->whereIn('reserves.park_id', $parkIds);
        }

        return $query;
    }

    /**
     * @param  Collection<int, string>|null  $parkScope
     * @return Collection<int, string>|null
     */
    private function resolveScope(?Collection $parkScope, ?string $parkId): ?Collection
    {
        if ($parkId === null) {
            return $parkScope;
        }

        if ($parkScope === null) {
            // Admin narrowing to a single park — trust it (the controller
            // already validated the id exists / is well-formed).
            return collect([$parkId]);
        }

        // Owner narrowing to a park we know they own.
        return $parkScope->contains($parkId) ? collect([$parkId]) : collect();
    }

    /**
     * Normalize the requested range. Defaults to the trailing 30 days when
     * the caller didn't provide bounds, and always widens `to` to the end
     * of the day so today's reservations aren't excluded.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function normalizeRange(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $to   = ($to ? CarbonImmutable::instance($to) : CarbonImmutable::now())->endOfDay();
        $from = ($from ? CarbonImmutable::instance($from) : $to->subDays(self::DEFAULT_RANGE_DAYS))->startOfDay();

        if ($from->greaterThan($to)) {
            // Defensive: bad ranges (from > to) collapse to a single-day window.
            $from = $to->startOfDay();
        }

        return [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(CarbonInterface $from, CarbonInterface $to): array
    {
        return [
            'range'            => [
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'totals'           => [
                'total_reservations'     => 0,
                'completed'              => 0,
                'cancelled'              => 0,
                'expired'                => 0,
                'active'                 => 0,
                'waiting'                => 0,
                'pre_booking'            => 0,
                'on_site'                => 0,
                'unique_customers'       => 0,
                'avg_duration_minutes'   => null,
                'total_duration_minutes' => 0,
                'completion_rate'        => 0.0,
                'cancellation_rate'      => 0.0,
            ],
            'by_status'        => collect(),
            'by_day'           => collect(),
            'by_hour'          => collect(),
            'by_park'          => collect(),
            'duration_buckets' => collect(),
            'recent'           => collect(),
        ];
    }

    private function statusLabel(int $status): string
    {
        return match ($status) {
            Reserve::STATUS_START     => 'waiting',
            Reserve::STATUS_ACTIVE    => 'active',
            Reserve::STATUS_COMPLETED => 'completed',
            Reserve::STATUS_EXPIRED   => 'expired',
            Reserve::STATUS_CANCELLED => 'cancelled',
            default                   => 'unknown',
        };
    }

    private function rate(int $numerator, int $denominator): float
    {
        return $denominator > 0
            ? round(($numerator / $denominator) * 100, 1)
            : 0.0;
    }

    private function secondsToMinutes(float|int|string|null $seconds): ?float
    {
        if ($seconds === null) return null;
        return round(((float) $seconds) / 60, 1);
    }
}
