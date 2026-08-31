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
            'price'       => $this->price,

            // Review state. `is_approved` is derived here so no client has to
            // re-encode which string counts as live.
            'approval_status'   => $this->approval_status,
            'is_approved'       => $this->resource->isApproved(),
            'approved_at'       => $this->approved_at?->toIso8601String(),
            'rejection_reason'  => $this->rejection_reason,

            'location'    => new LocationResource($this->whenLoaded('location')),
            'owner'       => $this->whenLoaded('owner', fn () => [
                'id'    => $this->owner?->id,
                'name'  => $this->owner?->name,
                'email' => $this->owner?->email,
            ]),
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
