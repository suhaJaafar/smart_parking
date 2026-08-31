<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Switch the signed-in account between driver and garage owner.
 *
 * The phone number is only consulted when becoming an owner; drivers keep
 * whatever is already on file.
 */
class SwitchRoleRequest extends FormRequest
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
            'role'         => ['required', 'string', 'in:owner,customer'],
            'phone_number' => ['nullable', 'string', 'max:32'],
        ];
    }

    /**
     * Digits-only form of the submitted phone, or null when none was sent.
     * Arabic-Indic digits are folded to ASCII first so a number typed on an
     * Arabic keyboard is accepted.
     */
    public function normalizedPhone(): ?string
    {
        $raw = $this->input('phone_number');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $ascii = strtr($raw, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);

        $digits = preg_replace('/\D/', '', $ascii) ?? '';

        return $digits === '' ? null : $digits;
    }
}
