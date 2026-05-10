<?php

namespace App\Http\Requests;

use App\Enums\CountryTypes;
use App\Enums\StateTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
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
     * @return array<string, array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'country'       => ['required', Rule::enum(CountryTypes::class)],
            'city'          => ['nullable', 'string', 'max:255'],
            'postal_code'   => ['nullable', 'string', 'max:20'],
            'state'         => ['required', Rule::enum(StateTypes::class)],
            'latitude'      => ['required', 'numeric', 'between:-90,90'],
            'longitude'     => ['required', 'numeric', 'between:-180,180'],
            'extra_details' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
