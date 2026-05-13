<?php

namespace App\Repositories\Contracts;

use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Contract for everything that touches the `locations` table or
 * performs spatial queries against it (PostGIS).
 *
 * Returning Eloquent models here is intentional: we are not building a
 * framework-agnostic domain layer, so abstracting Eloquent away would
 * only add noise without buying us anything.
 */
interface LocationRepositoryInterface
{
    /**
     * Fetch a single location by its UUID.
     */
    public function findById(string $id): ?Location;

    /**
     * Persist a brand-new location.
     *
     * Expected $data keys:
     *   - country        (int, CountryTypes value)
     *   - state          (int, StateTypes value)
     *   - latitude       (float, -90..90)
     *   - longitude      (float, -180..180)
     *   - city           (?string)
     *   - postal_code    (?string)
     *   - extra_details  (?string)
     */
    public function create(array $data): Location;

    /**
     * Update an existing location. Same $data shape as create(), all keys optional.
     */
    public function update(Location $location, array $data): Location;

    /**
     * Delete a location.
     */
    public function delete(Location $location): bool;
    /**
     * Find the nearest parks to a given point, within $radiusMeters.
     *
     * Uses PostGIS ST_DWithin (index-accelerated) for the radius filter and
     * ST_Distance for ordering / exposing the distance.
     *
     * Each returned row is a Park model with two extra attributes loaded:
     *   - distance_meters (float)
     *   - location        (eager-loaded Location model)
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Park>
     */
    public function nearestParks(float $latitude, float $longitude, int $radiusMeters = 500): Collection;
}
