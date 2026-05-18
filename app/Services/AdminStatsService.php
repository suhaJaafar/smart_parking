<?php

namespace App\Services;

use App\Enums\RoleTypes;
use App\Enums\StateTypes;
use App\Models\Park;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates platform-wide metrics for the admin dashboard.
 *
 * Each public method returns a plain array (or Collection) shaped exactly the
 * way the frontend consumes it; the controller is just a thin presenter that
 * stitches the pieces together.
 */
final class AdminStatsService
{
    private const RECENT_PARKS_LIMIT = 5;
    private const PARKS_BY_STATE_LIMIT = 15;

    /**
     * Build the full dashboard payload.
     *
     * @return array{
     *   totals: array<string, int|float>,
     *   users_by_role: Collection,
     *   parks_by_state: Collection,
     *   recent_parks: Collection,
     * }
     */
    public function dashboard(): array
    {
        return [
            'totals'         => $this->totals(),
            'users_by_role'  => $this->usersByRole(),
            'parks_by_state' => $this->parksByState(),
            'recent_parks'   => $this->recentParks(),
        ];
    }

    /** Headline KPIs: park count, capacity and occupancy. */
    private function totals(): array
    {
        $agg = DB::table('parks')
            ->selectRaw('count(*) as total, coalesce(sum(capacity),0) as capacity, coalesce(sum(free_spaces),0) as free_spaces')
            ->first();

        $capacity   = (int) ($agg->capacity ?? 0);
        $freeSpaces = (int) ($agg->free_spaces ?? 0);
        $occupied   = max(0, $capacity - $freeSpaces);

        return [
            'parks'         => (int) ($agg->total ?? 0),
            'capacity'      => $capacity,
            'free_spaces'   => $freeSpaces,
            'occupied'      => $occupied,
            'occupancy_pct' => $capacity > 0 ? round(($occupied / $capacity) * 100, 1) : 0.0,
            'users'         => User::query()->count(),
        ];
    }

    /** Distinct user counts per role, with enum-aware labels. */
    private function usersByRole(): Collection
    {
        $labels = collect(RoleTypes::cases())
            ->mapWithKeys(fn (RoleTypes $r) => [$r->value => $r->name]);

        return Role::query()
            ->leftJoin('role_user', 'role_user.role_id', '=', 'roles.id')
            ->select('roles.role as role')
            ->selectRaw('count(distinct role_user.user_id) as count')
            ->groupBy('roles.role')
            ->get()
            ->map(function ($row) use ($labels) {
                $value = $row->role instanceof RoleTypes
                    ? $row->role->value
                    : (int) $row->role;

                return [
                    'role'  => $value,
                    'label' => $labels[$value] ?? (string) $value,
                    'count' => (int) $row->count,
                ];
            })
            ->sortBy('role')
            ->values();
    }

    /** Top N states by park count, joined through locations. */
    private function parksByState(): Collection
    {
        return DB::table('parks')
            ->join('locations', 'parks.location_id', '=', 'locations.id')
            ->select('locations.state as state')
            ->selectRaw('count(parks.id) as count')
            ->whereNotNull('locations.state')
            ->groupBy('locations.state')
            ->orderByDesc('count')
            ->limit(self::PARKS_BY_STATE_LIMIT)
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

    /** Most recently created parks, ready for the dashboard list. */
    private function recentParks(): Collection
    {
        return Park::query()
            ->with(['location', 'owner:id,name,email'])
            ->latest()
            ->limit(self::RECENT_PARKS_LIMIT)
            ->get()
            ->map(fn (Park $p) => [
                'id'          => $p->id,
                'name'        => $p->name,
                'capacity'    => $p->capacity,
                'free_spaces' => $p->free_spaces,
                'city'        => $p->location?->city,
                'state'       => $p->location?->state?->name,
                'owner'       => $p->owner ? [
                    'id'    => $p->owner->id,
                    'name'  => $p->owner->name,
                    'email' => $p->owner->email,
                ] : null,
                'created_at'  => $p->created_at?->toIso8601String(),
            ]);
    }
}
