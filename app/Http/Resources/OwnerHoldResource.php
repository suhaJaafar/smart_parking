<?php

namespace App\Http\Resources;

use App\Models\Car;
use App\Models\Reserve;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pending reservation (hold) shown on the owner's "waiting to enter" list:
 * a customer who reserved a slot but whose car hasn't physically entered yet.
 *
 * @mixin Reserve
 */
class OwnerHoldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Car|null $car */
        $car = $this->whenLoaded('user', fn () => $this->user?->cars?->first());

        return [
            'id'             => $this->id,
            'booking_code'   => $this->booking_code,
            'status'         => 'waiting',
            'is_pre_booking' => (bool) $this->is_pre_booking,
            'park_id'        => $this->park_id,
            'park'           => $this->whenLoaded('park', fn () => [
                'id'   => $this->park?->id,
                'name' => $this->park?->name,
            ]),
            'customer'       => $this->whenLoaded('user', fn () => [
                'id'           => $this->user?->id,
                'name'         => $this->user?->name,
                'phone_number' => $this->user?->phone_number,
            ]),
            'car'            => $car instanceof Car ? [
                'id'           => $car->id,
                'plate_prefix' => $car->plate_prefix,
                'car_number'   => $car->car_number,
                'plate'        => trim("{$car->plate_prefix}-{$car->car_number}", '-'),
                'model'        => $car->model,
            ] : null,
            'scheduled_at'   => $this->scheduled_at?->toIso8601String(),
            'expires_at'     => $this->expires_at?->toIso8601String(),
            'reserved_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
