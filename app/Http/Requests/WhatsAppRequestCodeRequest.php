<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 1 of WhatsApp OTP login: ask the backend to send a code to the phone.
 */
class WhatsAppRequestCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accepts +9647775270135 or 9647775270135 — server normalizes to digits.
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Enter a valid phone number with country code (8–15 digits).',
        ];
    }

    /**
     * Normalize the submitted phone to the digits-only form used in storage.
     */
    public function normalizedPhone(): string
    {
        return preg_replace('/\D/', '', (string) $this->input('phone_number'));
    }
}
