<?php

namespace App\Http\Requests;

use App\Enums\CountryTypes;
use App\Enums\RoleTypes;
use App\Enums\StateTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for creating a park together with its location.
 *
 * The client sends lat/lng + park fields in a single flat JSON body.
 *
 * Ownership rules:
 *  - By default the park is owned by the authenticated user.
 *  - SUPER_ADMIN may pass an explicit `user_id` to assign ownership to
 *    another user. For all other roles, any `user_id` value in the payload
 *    is stripped before validation so it cannot be forged.
 */
class StoreParkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces role-level access; here we just need to
        // ensure the request is authenticated.
        return $this->user() !== null;
    }

    /**
     * Strip `user_id` from the input unless the actor is allowed to assign
     * ownership. Done in `prepareForValidation` (before rules run) so that
     * forged fields never reach the validated set.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->canAssignOwner()) {
            $this->request->remove('user_id');
        }
    }

    /**
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Park fields
            'name'          => ['required', 'string', 'max:255'],
            'capacity'      => ['required', 'integer', 'min:1'],
            'free_spaces'   => ['nullable', 'integer', 'min:0', 'lte:capacity'],

            // Optional owner override (SUPER_ADMIN only).
            'user_id'       => ['sometimes', 'uuid', Rule::exists('users', 'id')],

            // Location fields (mirrors LocationRequest)
            'country'       => ['required', Rule::enum(CountryTypes::class)],
            'state'         => ['required', Rule::enum(StateTypes::class)],
            'city'          => ['nullable', 'string', 'max:255'],
            'postal_code'   => ['nullable', 'string', 'max:20'],
            'latitude'      => ['required', 'numeric', 'between:-90,90'],
            'longitude'     => ['required', 'numeric', 'between:-180,180'],
            'extra_details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * The validated owner UUID if the actor is allowed to assign ownership
     * AND supplied one. Otherwise `null` (controller will fall back to the
     * authenticated user).
     */
    public function ownerOverrideId(): ?string
    {
        return $this->safe()->only(['user_id'])['user_id'] ?? null;
    }

    /**
     * Subset of validated input destined for the locations table.
     */
    public function locationData(): array
    {
        return $this->safe()->only([
            'country', 'state', 'city', 'postal_code',
            'latitude', 'longitude', 'extra_details',
        ]);
    }

    /**
     * Subset of validated input destined for the parks table.
     * `free_spaces` defaults to `capacity` if the client didn't supply it.
     */
    public function parkData(): array
    {
        $data = $this->safe()->only(['name', 'capacity', 'free_spaces']);
        $data['free_spaces'] ??= $data['capacity'];

        return $data;
    }

    /**
     * Whether the authenticated user is allowed to assign a different owner.
     * Mirrors the SUPER_ADMIN check used elsewhere (e.g. ParkPolicy::before).
     */
    private function canAssignOwner(): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        return $user->roles()
            ->where('role', RoleTypes::SUPER_ADMIN->value)
            ->exists();
    }
}
