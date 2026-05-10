<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CarRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'car_number'   => ['required', 'string', 'max:20'],
            'plate_prefix' => ['required', 'string', 'max:8'],
            'model'        => ['sometimes', 'string', 'max:50'],
            'park_id'      => ['sometimes', 'uuid', 'exists:parks,id'],
        ];
    }
}
