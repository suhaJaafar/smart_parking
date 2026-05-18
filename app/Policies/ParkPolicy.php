<?php

namespace App\Policies;

use App\Enums\RoleTypes;
use App\Models\Park;
use App\Models\User;

class ParkPolicy
{
    /**
     * SUPER_ADMIN bypasses every other check.
     *
     * Returning `true` short-circuits the gate to allowed; returning `null`
     * lets the specific ability method decide.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $this->isSuperAdmin($user) ? true : null;
    }

    /** Any authenticated user can list parks (filtering happens server-side). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /** Anyone authenticated can view a single park's details. */
    public function view(User $user, Park $park): bool
    {
        return true;
    }

    /** Park managers (owner) can update; SUPER_ADMIN is allowed via `before`. */
    public function update(User $user, Park $park): bool
    {
        return $park->user_id === $user->id;
    }

    /** Same rule as update: owner-only, with SUPER_ADMIN bypass. */
    public function delete(User $user, Park $park): bool
    {
        return $park->user_id === $user->id;
    }

    private function isSuperAdmin(User $user): bool
    {
        return $user->roles()
            ->where('role', RoleTypes::SUPER_ADMIN->value)
            ->exists();
    }
}
