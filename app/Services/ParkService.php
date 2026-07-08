<?php

namespace App\Services;

use App\Data\LocationData;
use App\Data\ParkData;
use App\Enums\RoleTypes;
use App\Models\Location;
use App\Models\Park;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the multi-step business workflow of creating a park together
 * with its location, atomically.
 *
 * This is a *service / action*: it owns a business use-case that spans more
 * than one table (locations + parks + roles) and needs transactional
 * guarantees. Trivial single-table reads/writes stay on Eloquent directly.
 */
class ParkService
{
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
            $locationRow                = new Location();
            $locationRow->country       = $location->country;
            $locationRow->state         = $location->state;
            $locationRow->city          = $location->city;
            $locationRow->postal_code   = $location->postalCode;
            $locationRow->extra_details = $location->extraDetails;
            // Uses the `coordinates` mutator defined on the Location model,
            // which translates lat/long into a PostGIS geography(POINT, 4326).
            $locationRow->coordinates = [
                'lat'  => $location->latitude,
                'long' => $location->longitude,
            ];
            $locationRow->save();

            $parkRow = Park::create([
                ...$park->toArray(),
                'user_id'     => $owner->id,
                'location_id' => $locationRow->id,
            ])->refresh();

            // Promote the creator to SPACE_OWNER (idempotent).
            $role = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
            $owner->roles()->syncWithoutDetaching([$role->id]);

            return $parkRow;
        });
    }
}
