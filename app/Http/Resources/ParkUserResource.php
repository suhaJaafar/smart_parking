<?php

namespace App\Http\Resources;

use App\Http\Controllers\OwnerParkUserController;
use App\Models\Car;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer who has reserved at one of the owner's garages, with their
 * lifetime activity aggregates.
 *
 * Backs `GET /api/owner/park-users`. The status counts arrive as
 * `withCount` aliases and the first/last activity timestamps as correlated
 * sub-select columns on the {@see User} model — see
 * {@see OwnerParkUserController::customersQuery()}.
 *
 * @mixin User
 */
class ParkUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car|null $car */
        $car = $this->whenLoaded('cars', fn () => $this->cars->first());

        return [
            'user_id' => $this->id,
            'name' => $this->name,
            'phone_number' => $this->phone_number,
            'car' => $car instanceof Car ? [
                'id' => $car->id,
                'plate' => trim("{$car->plate_prefix}-{$car->car_number}", '-'),
                'model' => $car->model,
            ] : null,
            'total' => (int) ($this->reservations_total ?? 0),
            'completed' => (int) ($this->reservations_completed ?? 0),
            'active' => (int) ($this->reservations_active ?? 0),
            'waiting' => (int) ($this->reservations_waiting ?? 0),
            'cancelled' => (int) ($this->reservations_cancelled ?? 0),
            'expired' => (int) ($this->reservations_expired ?? 0),
            'first_at' => $this->toIso($this->first_reservation_at),
            'last_at' => $this->toIso($this->last_reservation_at),
        ];
    }

    private function toIso(?string $value): ?string
    {
        return $value !== null && $value !== ''
            ? CarbonImmutable::parse($value)->toIso8601String()
            : null;
    }
}
