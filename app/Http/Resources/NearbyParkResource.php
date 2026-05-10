<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Park resource enriched with the distance from the customer's coordinates.
 * Expects `distance_meters` to be present on the underlying model
 * (set via a `selectRaw` in ParkRepository::nearby()).
 */
class NearbyParkResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'capacity'        => $this->capacity,
            'free_spaces'     => $this->free_spaces,
            'distance_meters' => isset($this->distance_meters)
                ? (int) round($this->distance_meters)
                : null,
            'location'        => new LocationResource($this->whenLoaded('location')),
        ];
    }
}
