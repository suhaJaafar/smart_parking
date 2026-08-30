<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Telegram Mini App sign-in: submit the raw `initData` query string that
 * Telegram injects into the WebView.
 *
 * Only shape is validated here — authenticity is proved by the HMAC check in
 * {@see \App\Bots\Channels\Telegram\TelegramMiniAppAuthenticator}, never by
 * anything the client asserts about itself.
 */
class TelegramMiniAppAuthRequest extends FormRequest
{
    /** Guests only — this endpoint mints the very first token for a session. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Telegram payloads are compact; the ceiling only guards against a
            // client posting megabytes into the HMAC routine.
            'init_data' => ['required', 'string', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'init_data.required' => 'Telegram launch data is missing.',
        ];
    }
}
