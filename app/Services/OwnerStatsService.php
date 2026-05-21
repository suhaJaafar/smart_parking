<?php

namespace App\Services;

use App\Enums\StateTypes;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-owner statistics for the SPACE_OWNER dashboard.
 *
 * Mirrors the contract of {@see AdminStatsService}: each public method
 * returns a plain array or Collection shaped exactly the way the frontend
 * consumes it. The controller is a thin presenter — all SQL lives here.
 *
 * Every query is scoped to the parks owned by the supplied user, so the
 * service is safe to call for any authenticated space owner.
 */
final class OwnerStatsService
{
    private const PARKS_LIMIT = 50;
    private const RESERVE_STATUS_ACTIVE = [
        Reserve::STATUS_START,
        Reserve::STATUS_ACTIVE,
    ];

    /**
     * Build the full owner dashboard payload.
     *
     * @return array{
     *   totals: array<string, int|float>,
     *   parks: Collection,
     *   parks_by_state: Collection,
     * }
     */
    public function dashboard(User $owner): array
    {
        $parkIds = $this->ownedParkIds($owner);

        return [
            'totals'         => $this->totals($owner, $parkIds),
            'parks'          => $this->parksBreakdown($parkIds),
            'parks_by_state' => $this->parksByState($parkIds),
        ];
    }

    /** Headline KPIs for the owner's portfolio. */
    private function totals(User $owner, array $parkIds): array
    {
        // Aggregate park totals in a single round trip.
        $agg = DB::table('parks')
            ->whereIn('id', $parkIds)
            ->selectRaw('coalesce(sum(capacity),0) as capacity, coalesce(sum(free_spaces),0) as free_spaces')
            ->first();

        $capacity   = (int) ($agg->capacity ?? 0);
        $freeSpaces = (int) ($agg->free_spaces ?? 0);
        $occupied   = max(0, $capacity - $freeSpaces);

        // "Active customers" = distinct car owners currently parked in any
        // of the owner's parks. Cars with a non-null `park_id` are inside.
        $activeCustomers = empty($parkIds)
            ? 0
            : (int) DB::table('cars')
                ->whereIn('park_id', $parkIds)
                ->whereNotNull('user_id')
                ->distinct()
                ->count('user_id');

        // "All-time customers" = distinct users who ever reserved one of
        // this owner's parks. A coarse but useful loyalty signal.
        $totalCustomers = empty($parkIds)
            ? 0
            : (int) DB::table('reserves')
                ->whereIn('park_id', $parkIds)
                ->distinct()
                ->count('user_id');

        $activeReserves = empty($parkIds)
            ? 0
            : (int) DB::table('reserves')
                ->whereIn('park_id', $parkIds)
                ->whereIn('status', self::RESERVE_STATUS_ACTIVE)
                ->count();

        return [
            'parks'             => count($parkIds),
            'capacity'          => $capacity,
            'free_spaces'       => $freeSpaces,
            'occupied'          => $occupied,
            'occupancy_pct'     => $capacity > 0 ? round(($occupied / $capacity) * 100, 1) : 0.0,
            'active_customers'  => $activeCustomers,
            'total_customers'   => $totalCustomers,
            'active_reserves'   => $activeReserves,
        ];
    }

    /** Per-park snapshot, ready to render in a list/table. */
    private function parksBreakdown(array $parkIds): Collection
    {
        if (empty($parkIds)) {
            return collect();
        }

        return Park::query()
            ->with('location')
            ->whereIn('id', $parkIds)
            ->withCount(['cars as cars_count' => function ($q) {
                $q->whereNotNull('park_id');
            }])
            ->latest()
            ->limit(self::PARKS_LIMIT)
            ->get()
            ->map(fn (Park $p) => [
                'id'           => $p->id,
                'name'         => $p->name,
                'capacity'     => $p->capacity,
                'free_spaces'  => $p->free_spaces,
                'occupied'     => max(0, $p->capacity - $p->free_spaces),
                'cars_count'   => (int) ($p->cars_count ?? 0),
                'city'         => $p->location?->city,
                'state'        => $p->location?->state?->name,
                'created_at'   => $p->created_at?->toIso8601String(),
            ]);
    }

    /** Distribution of the owner's parks across states. */
    private function parksByState(array $parkIds): Collection
    {
        if (empty($parkIds)) {
            return collect();
        }

        return DB::table('parks')
            ->join('locations', 'parks.location_id', '=', 'locations.id')
            ->whereIn('parks.id', $parkIds)
            ->select('locations.state as state')
            ->selectRaw('count(parks.id) as count')
            ->whereNotNull('locations.state')
            ->groupBy('locations.state')
            ->orderByDesc('count')
            ->get()
            ->map(function ($row) {
                $enum = StateTypes::tryFrom((int) $row->state);

                return [
                    'state' => (int) $row->state,
                    'label' => $enum?->name ?? 'Unknown',
                    'count' => (int) $row->count,
                ];
            });
    }

    /** All park ids owned by the user. Returns `[]` if they own none. */
    private function ownedParkIds(User $owner): array
    {
        return Park::query()
            ->where('user_id', $owner->id)
            ->pluck('id')
            ->all();
    }
}
