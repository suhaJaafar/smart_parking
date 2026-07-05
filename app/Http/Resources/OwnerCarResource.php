<?php

namespace App\Http\Resources;

use App\Models\Car;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Space-owner facing projection of a {@see Car}.
 *
 * Richer than the minimal {@see CarResource} used by the legacy generic
 * car endpoints: it exposes the full plate, the park the car currently sits
 * in, and the customer contact — everything the dashboard needs to list and
 * manage the cars physically inside an owner's garages.
 *
 * @mixin Car
 */
class OwnerCarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'plate_prefix' => $this->plate_prefix,
            'car_number'   => $this->car_number,
            // Human-readable plate, e.g. "بغداد-12345" / "IA-12345".
            'plate'        => trim(($this->plate_prefix ?? '') . '-' . ($this->car_number ?? ''), '-'),
            'model'        => $this->model,
            'park_id'      => $this->park_id,
            'park'         => $this->whenLoaded('park', fn () => [
                'id'   => $this->park->id,
                'name' => $this->park->name,
            ]),
            'customer'     => $this->whenLoaded('user', fn () => [
                'id'           => $this->user->id,
                'name'         => $this->user->name,
                'phone_number' => $this->user->phone_number,
            ]),
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
