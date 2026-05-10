<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the payload for UPDATING an existing park.
 *
 * Location and ownership are NOT mutable through this endpoint. To move a
 * park to a new location, delete it and create it again, or expose a dedicated
 * endpoint with proper authorization.
 */
class ParkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'required', 'string', 'max:255'],
            'capacity'    => ['sometimes', 'required', 'integer', 'min:1'],
            'free_spaces' => ['sometimes', 'required', 'integer', 'min:0', 'lte:capacity'],
        ];
    }
}
