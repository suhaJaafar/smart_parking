<?php

namespace App\Http\Resources;

use App\Models\Car;
use App\Models\Reserve;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A completed parking session — a car that entered one of the owner's
 * garages and has since left. Sourced from COMPLETED reservations, which are
 * the reliable record of a car having physically entered and exited.
 *
 * `entered_at` uses the reservation's creation time (an on-site hold enters
 * within minutes of creation) and `exited_at` its last update (when it was
 * marked COMPLETED), matching the duration model used across the analytics.
 *
 * @mixin Reserve
 */
class ParkCarHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car|null $car */
        $car = $this->whenLoaded('user', fn () => $this->user?->cars?->first());

        $durationMinutes = $this->created_at !== null && $this->updated_at !== null
            ? max(0, (int) round($this->created_at->diffInSeconds($this->updated_at, false) / 60))
            : null;

        return [
            'id' => $this->id,
            'booking_code' => $this->booking_code,
            'is_pre_booking' => (bool) $this->is_pre_booking,
            'park' => $this->whenLoaded('park', fn () => [
                'id' => $this->park?->id,
                'name' => $this->park?->name,
            ]),
            'customer' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'phone_number' => $this->user?->phone_number,
            ]),
            'car' => $car instanceof Car ? [
                'id' => $car->id,
                'plate_prefix' => $car->plate_prefix,
                'car_number' => $car->car_number,
                'plate' => trim("{$car->plate_prefix}-{$car->car_number}", '-'),
                'model' => $car->model,
            ] : null,
            'entered_at' => $this->created_at?->toIso8601String(),
            'exited_at' => $this->updated_at?->toIso8601String(),
            'duration_minutes' => $durationMinutes,
        ];
    }
}
