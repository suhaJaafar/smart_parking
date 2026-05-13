<?php

namespace App\Repositories;

use App\Models\Location;
use App\Models\Park;
use App\Repositories\Contracts\LocationRepositoryInterface;
use Illuminate\Support\Collection;

class LocationRepository implements LocationRepositoryInterface
{
    public function findById(string $id): ?Location
    {
        return Location::find($id);
    }

        public function create(array $data): Location
    {
        $location = new Location();
        $this->convertCoordinatesData($location, $data);
        $location->save();
        return $location;
    }

    public function update(Location $location, array $data): Location
    {
        $this->convertCoordinatesData($location, $data);
        $location->save();
        return $location;
    }

    public function delete(Location $location): bool
    {
        return (bool) $location->delete();
    }

    public function nearestParks(float $latitude, float $longitude, int $radiusMeters = 500): Collection
    {
        // Build the reference point once. Note: PostGIS expects (longitude, latitude).
        $point = sprintf(
            "ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography",
            $longitude,
            $latitude
        );

        return Park::query()
            ->join('locations', 'locations.id', '=', 'parks.location_id')
            ->whereRaw("ST_DWithin(locations.coordinates, {$point}, ?)", [$radiusMeters])
            ->select('parks.*')
            ->selectRaw("ST_Distance(locations.coordinates, {$point}) AS distance_meters")
            ->orderBy('distance_meters')
            ->with('location')
            ->get();
    }

    /* ---------------------------------------------------------------------
     | Internals
     * --------------------------------------------------------------------- */

    /**
     * Map the public array shape (with latitude/longitude) onto the model,
     * translating lat/lng into the PostGIS `coordinates` geography value.
     */
    private function convertCoordinatesData(Location $location, array $data): void
    {
        foreach (['country', 'city', 'postal_code', 'state', 'extra_details'] as $key) {
            if (array_key_exists($key, $data)) {
                $location->{$key} = $data[$key];
            }
        }

        if (array_key_exists('latitude', $data) && array_key_exists('longitude', $data)) {
            // Uses the `coordinates` mutator defined on the Location model.
            $location->coordinates = [
                'lat'  => (float) $data['latitude'],
                'long' => (float) $data['longitude'],
            ];
        }
    }
}
