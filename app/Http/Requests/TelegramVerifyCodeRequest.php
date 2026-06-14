<?php

namespace App\Http\Requests;

use App\Bots\Channels\Telegram\TelegramLoginService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Telegram dashboard login: submit the one-time code the bot displayed.
 *
 * Only the code is needed — the backend maps it to the issuing chat_id
 * server-side (see {@see TelegramLoginService}).
 */
class TelegramVerifyCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $len = TelegramLoginService::CODE_LENGTH;

        return [
            'code' => ['required', 'string', "size:{$len}", 'regex:/^[0-9]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'The verification code must contain digits only.',
            'code.size'  => 'The verification code must be exactly :size digits.',
        ];
    }
}
