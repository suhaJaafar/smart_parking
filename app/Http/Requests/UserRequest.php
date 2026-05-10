<?php

namespace App\Http\Requests;

use App\Enums\RoleTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('post');
        $userId   = $this->route('id'); // null on POST

        $required = $isCreate ? 'required' : 'sometimes';

        return [
            'name'         => [$required, 'string', 'max:255'],
            'email'        => [$required, 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password'     => [$required, 'string', 'min:8'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'location_id'  => ['sometimes', 'nullable', 'uuid', 'exists:locations,id'],
            'roles'        => ['sometimes', 'array'],
            'roles.*'      => ['integer', Rule::enum(RoleTypes::class)],
        ];
    }
}
