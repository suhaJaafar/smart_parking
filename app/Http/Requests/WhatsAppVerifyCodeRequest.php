<?php

namespace App\Http\Requests;

use App\Services\WhatsApp\WhatsAppOtpService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Step 2 of WhatsApp OTP login: submit the code the user received.
 */
class WhatsAppVerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $len = WhatsAppOtpService::CODE_LENGTH;

        return [
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9]{8,15}$/'],
            'code'         => ['required', 'string', "size:{$len}", 'regex:/^[0-9]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone_number.regex' => 'Enter a valid phone number with country code (8–15 digits).',
            'code.regex'         => 'The verification code must contain digits only.',
            'code.size'          => 'The verification code must be exactly :size digits.',
        ];
    }

    public function normalizedPhone(): string
    {
        return preg_replace('/\D/', '', (string) $this->input('phone_number'));
    }
}
