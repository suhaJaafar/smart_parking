<?php

namespace App\Repositories\Contracts;

use App\Models\Park;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ParkRepositoryInterface
{
    public function findById(string $id): ?Park;

    /**
     * Persist a park. Caller must supply at minimum:
     *   - user_id     (uuid, users.id) — the SPACE_OWNER user that owns the park
     *   - location_id (uuid, locations.id)
     *   - name        (string)
     *   - capacity    (int)
     *   - free_spaces (int)
     */
    public function create(array $data): Park;

    public function update(Park $park, array $data): Park;

    public function delete(Park $park): bool;

    public function paginate(int $perPage = 10): LengthAwarePaginator;

    /**
     * Parks owned by a given user (paginated).
     */
    public function paginateByOwner(User $owner, int $perPage = 10): LengthAwarePaginator;

    /**
     * Parks within `radiusMeters` of the given coordinates, ordered by distance.
     * Each park has a `distance_meters` attribute attached.
     */
    public function nearby(
        float $latitude,
        float $longitude,
        int $radiusMeters = 5000,
        int $limit = 20,
    ): \Illuminate\Support\Collection;
}
