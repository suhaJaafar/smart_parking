<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for a SPACE_OWNER manually recording a car that entered one of
 * their garages. Park ownership is enforced in the controller (data-level
 * guard) on top of the `role:SPACE_OWNER,SUPER_ADMIN` route middleware.
 */
class StoreOwnerCarRequest extends FormRequest
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
        return [
            'park_id'      => ['required', 'uuid', 'exists:parks,id'],
            'plate_prefix' => ['required', 'string', 'max:8'],
            'car_number'   => ['required', 'string', 'max:20'],
            'model'        => ['nullable', 'string', 'max:50'],
        ];
    }
}
