<?php

namespace App\Services;

use App\Enums\RoleTypes;
use App\Models\Park;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\LocationRepositoryInterface;
use App\Repositories\Contracts\ParkRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the multi-step business workflow of creating a park together
 * with its location, atomically.
 *
 * Why a service? Because this use case touches MORE THAN ONE repository and
 * needs transactional guarantees. A repository owns a single table; a service
 * owns a workflow.
 */
class ParkService
{
    public function __construct(
        private readonly ParkRepositoryInterface $parks,
        private readonly LocationRepositoryInterface $locations,
    ) {}

    /**
     * Create a park and its location in one transaction.
     *
     * @param  array  $locationData  Validated payload for Location (lat/lng/country/state/...)
     * @param  array  $parkData      Validated payload for Park (name/capacity/free_spaces/...)
     * @param  User   $owner         The authenticated user that will own this park.
     */
    public function createWithLocation(array $locationData, array $parkData, User $owner): Park
    {
        return DB::transaction(function () use ($locationData, $parkData, $owner) {
            $location = $this->locations->create($locationData);

            $park = $this->parks->create([
                ...$parkData,
                'owner_id'    => $owner->id,
                'location_id' => $location->id,
            ]);

            // Auto-grant SPACE_OWNER role on first park, idempotent on subsequent ones.
            $role = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
            $owner->roles()->syncWithoutDetaching([$role->id]);

            return $park;
        });
    }
}
