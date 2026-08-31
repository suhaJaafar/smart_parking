<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Customer places a hold on a park from the Mini App.
 *
 * The customer is always the authenticated user — never a field in the body —
 * so one account can't reserve on behalf of another.
 */
class StoreCustomerReservationRequest extends FormRequest
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
            'park_id' => ['required', 'uuid', 'exists:parks,id'],

            // Present only for a pre-booking. Must be in the future; the
            // service anchors the hold window to it.
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function messages(): array
    {
        return [
            'park_id.exists'      => 'That parking garage no longer exists.',
            'scheduled_at.after'  => 'Choose an arrival time in the future.',
        ];
    }
}
