<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query-string validation for the reservations-analytics endpoints
 * (`GET /api/owner/reservation-stats`, `GET /api/admin/reservation-stats`).
 *
 * The `park_id` scope is only *shape-validated* here — the controller is
 * responsible for enforcing that the caller is actually allowed to see
 * that park's reservations (owner: it belongs to them; admin: it exists).
 */
class ReservationStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware already gates access (role:SPACE_OWNER,SUPER_ADMIN
        // or role:ADMIN,SUPER_ADMIN). Presence of an authenticated user is
        // therefore sufficient at this layer.
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'from'    => ['nullable', 'date'],
            'to'      => ['nullable', 'date', 'after_or_equal:from'],
            'park_id' => ['nullable', 'string', 'uuid'],
        ];
    }
}
