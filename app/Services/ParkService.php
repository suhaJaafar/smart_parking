<?php

namespace App\Services;

use App\Data\LocationData;
use App\Data\ParkData;
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
        private readonly ParkRepositoryInterface $parksInterface,
        private readonly LocationRepositoryInterface $locationsInterface,
    ) {}

    /**
     * Create a park and its location in one transaction.
     *
     * @param  LocationData  $location  Validated location payload (lat/lng/country/state/...).
     * @param  ParkData      $park      Validated park payload (name/capacity/free_spaces).
     * @param  User          $owner     The user that will own this park.
     */
    public function createWithLocation(LocationData $location, ParkData $park, User $owner): Park
    {
        return DB::transaction(function () use ($location, $park, $owner) {
            $locationRow = $this->locationsInterface->create($location->toArray());

            $parkRow = $this->parksInterface->create([
                ...$park->toArray(),
                'user_id'     => $owner->id,
                'location_id' => $locationRow->id,
            ]);

            // Promote the creator to SPACE_OWNER (idempotent).
            $role = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
            $owner->roles()->syncWithoutDetaching([$role->id]);

            return $parkRow;
        });
    }
}
