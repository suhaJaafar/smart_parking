<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class NearbyParksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'latitude'  => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            // Radius in meters — defaults applied in the controller.
            'radius'    => ['nullable', 'integer', 'min:50', 'max:50000'],
            'limit'     => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
