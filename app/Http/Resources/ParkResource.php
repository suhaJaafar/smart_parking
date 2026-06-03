<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'user_id'     => $this->user_id,
            'capacity'    => $this->capacity,
            'free_spaces' => $this->free_spaces,
            'location'    => new LocationResource($this->whenLoaded('location')),
            'owner'       => $this->whenLoaded('owner', fn () => [
                'id'    => $this->owner?->id,
                'name'  => $this->owner?->name,
                'email' => $this->owner?->email,
            ]),
            'cars' => CarResource::collection($this->whenLoaded('cars')),
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
