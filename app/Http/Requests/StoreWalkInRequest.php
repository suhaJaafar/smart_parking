<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner admits a car that arrived without any reservation.
 *
 * Plate is mandatory here — unlike the admit flow there is no reservation to
 * infer the vehicle from.
 */
class StoreWalkInRequest extends FormRequest
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
            'park_id'      => ['required', 'uuid', 'exists:parks,id'],
            'plate_prefix' => ['required', 'string', 'max:8'],
            'car_number'   => ['required', 'string', 'max:20'],
            'model'        => ['nullable', 'string', 'max:50'],
        ];
    }
}
