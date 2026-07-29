<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query validation shared by the owner park-history + export endpoints
 * (reservations export, park-cars history, park-users). All parameters are
 * optional — an absent range means "all time", an absent park means "every
 * garage the owner holds".
 *
 * `filter` is only meaningful for the reservations endpoints; it is ignored
 * elsewhere but validated here so one request class covers every caller.
 */
class ParkHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware (role:SPACE_OWNER,SUPER_ADMIN) already gates access;
        // an authenticated user is sufficient at this layer.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'park_id' => ['nullable', 'string', 'uuid'],
            'filter' => ['nullable', 'string', 'in:live,waiting,active,history,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
