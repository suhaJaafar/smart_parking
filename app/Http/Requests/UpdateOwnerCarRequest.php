<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a SPACE_OWNER editing a car inside one of their garages.
 *
 * Only the plate and model are editable — moving a car between parks is done
 * through the dedicated enter/exit flows so `free_spaces` accounting stays
 * correct. The plate must stay unique across the `(plate_prefix, car_number)`
 * pair, ignoring the car being edited.
 */
class UpdateOwnerCarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $carId = $this->route('id');

        return [
            'plate_prefix' => ['sometimes', 'required', 'string', 'max:8'],
            'car_number'   => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('cars', 'car_number')
                    ->where(fn ($query) => $query->where(
                        'plate_prefix',
                        $this->input('plate_prefix', $this->currentPlatePrefix()),
                    ))
                    ->ignore($carId),
            ],
            'model'        => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /**
     * Resolve the car's existing plate prefix so the uniqueness rule works
     * even when the client only updates the car number.
     */
    private function currentPlatePrefix(): ?string
    {
        $car = \App\Models\Car::find($this->route('id'));

        return $car?->plate_prefix;
    }
}
