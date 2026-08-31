<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Owner admits an arriving customer's car.
 *
 * The plate is optional: it is only needed when the customer has no vehicle on
 * file, mirroring the bot's `plate` step. Both halves must be supplied together
 * — half a plate identifies nothing.
 */
class AdmitReservationRequest extends FormRequest
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
            'plate_prefix' => ['nullable', 'required_with:car_number', 'string', 'max:8'],
            'car_number'   => ['nullable', 'required_with:plate_prefix', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'plate_prefix.required_with' => 'Enter the plate prefix as well as the number.',
            'car_number.required_with'   => 'Enter the plate number as well as the prefix.',
        ];
    }
}
