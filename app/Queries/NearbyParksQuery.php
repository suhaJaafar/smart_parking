<?php

namespace App\Queries;

use App\Models\Park;
use Illuminate\Support\Collection;

/**
 * Finds parks with free spaces near a geographic point, ordered from closest
 * to farthest.
 *
 * This is a dedicated Query Object: it isolates the one genuinely complex,
 * database-specific query in the app (a PostGIS spatial lookup) away from the
 * plain Eloquent CRUD. Callers — the customer API and the bot reservation
 * flows — depend on this single-purpose class rather than a broad repository.
 *
 * Each returned park carries three extra selected columns:
 *   - `distance_meters` — distance from the point, in metres
 *   - `lat` / `lng`     — the park location's decoded coordinates
 */
class NearbyParksQuery
{
    /**
     * @return Collection<int, Park>
     */
    public function get(
        float $latitude,
        float $longitude,
        int $radiusMeters = 5000,
        int $limit = 20,
    ): Collection {
        // PostGIS expects (longitude, latitude) for ST_MakePoint. We compute
        // distance against the location's `coordinates` column, filter by
        // radius using ST_DWithin (which uses the spatial index), and order
        // ascending by distance so the closest park is first.
        $point = sprintf(
            "ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography",
            $longitude,
            $latitude,
        );

        return Park::query()
            ->select('parks.*')
            ->selectRaw("ST_Distance(locations.coordinates, {$point}) AS distance_meters")
            ->selectRaw('ST_Y(locations.coordinates::geometry) AS lat')
            ->selectRaw('ST_X(locations.coordinates::geometry) AS lng')
            ->join('locations', 'locations.id', '=', 'parks.location_id')
            ->whereRaw("ST_DWithin(locations.coordinates, {$point}, ?)", [$radiusMeters])
            ->where('parks.free_spaces', '>', 0)
            ->orderBy('distance_meters')
            ->limit($limit)
            ->with('location')
            ->get();
    }
}
