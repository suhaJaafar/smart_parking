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
     * The garage is created *pending*: it is invisible to drivers and its
     * creator gains nothing until an admin clears it. The SPACE_OWNER role is
     * deliberately NOT granted here — see {@see self::approve()}. Granting it
     * on submission would hand out owner powers to anyone who filled a form.
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

            return Park::create([
                ...$park->toArray(),
                'user_id'         => $owner->id,
                'location_id'     => $locationRow->id,
                'approval_status' => Park::APPROVAL_PENDING,
            ])->refresh();
        });
    }

    /**
     * Clear a garage for business.
     *
     * This is the moment the owner role is earned, which is why it lives with
     * the approval rather than the creation. Idempotent: approving an already
     * approved garage is a no-op rather than an error, so a double-click in
     * the dashboard cannot produce a second notification.
     */
    public function approve(Park $park, User $admin): Park
    {
        if ($park->isApproved()) {
            return $park;
        }

        return DB::transaction(function () use ($park, $admin) {
            $park->forceFill([
                'approval_status'  => Park::APPROVAL_APPROVED,
                'approved_by'      => $admin->id,
                'approved_at'      => now(),
                'rejection_reason' => null,
            ])->save();

            // syncWithoutDetaching, not sync: an approved owner who is also a
            // driver keeps both hats. Elsewhere roles are exclusive, but a
            // garage owner losing their customer role here would strand any
            // reservation they hold as a driver.
            $role = Role::firstOrCreate(['role' => RoleTypes::SPACE_OWNER->value]);
            $park->owner?->roles()->syncWithoutDetaching([$role->id]);

            return $park->refresh();
        });
    }

    /**
     * Refuse a garage, optionally saying why.
     *
     * No role is revoked: the owner may have other approved garages, and
     * stripping the role here would lock them out of those.
     */
    public function reject(Park $park, User $admin, ?string $reason = null): Park
    {
        $park->forceFill([
            'approval_status'  => Park::APPROVAL_REJECTED,
            'approved_by'      => $admin->id,
            'approved_at'      => now(),
            'rejection_reason' => $reason,
        ])->save();

        return $park->refresh();
    }
}
