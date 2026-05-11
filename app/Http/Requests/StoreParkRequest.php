<?php

namespace App\Http\Requests;

use App\Enums\CountryTypes;
use App\Enums\StateTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the payload for creating a park together with its location.
 *
 * The client sends lat/lng + park fields in a single flat JSON body. The
 * `user_id` (park owner) is intentionally NOT accepted here — it is taken
 * from the authenticated user inside the controller to prevent forgery.
 */
class StoreParkRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Role-level authorization (SPACE_OWNER) is enforced by the route
        // middleware. We only need to ensure the user is authenticated here.
        return $this->user() !== null;
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
}
