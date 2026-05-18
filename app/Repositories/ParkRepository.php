<?php

namespace App\Repositories;

use App\Models\Park;
use App\Models\User;
use App\Repositories\Contracts\ParkRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ParkRepository implements ParkRepositoryInterface
{
    public function findById(string $id): ?Park
    {
        return Park::with(['location', 'owner:id,name,email'])->find($id);
    }

    public function create(array $data): Park
    {
        return Park::create($data)->refresh();
    }

    public function update(Park $park, array $data): Park
    {
        $park->fill($data)->save();

        return $park->refresh();
    }

    public function delete(Park $park): bool
    {
        return (bool) $park->delete();
    }

    public function paginate(int $perPage = 10): LengthAwarePaginator
    {
        return Park::with(['location', 'owner:id,name,email'])->latest()->paginate($perPage);
    }

    public function paginateByOwner(User $owner, int $perPage = 10): LengthAwarePaginator
    {
        return Park::with(['location', 'owner:id,name,email'])
            ->where('user_id', $owner->id)
            ->latest()
            ->paginate($perPage);
    }

    public function nearby(
        float $latitude,
        float $longitude,
        int $radiusMeters = 5000,
        int $limit = 20,
    ): \Illuminate\Support\Collection {
        // PostGIS expects (longitude, latitude) for ST_MakePoint.
        // We compute distance against the location's `coordinates` column,
        // filter by radius using ST_DWithin (which uses the spatial index),
        // and order ascending by distance so the closest park is first.
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
