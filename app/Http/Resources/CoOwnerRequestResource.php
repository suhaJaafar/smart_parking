<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\CoOwnerRequest
 */
class CoOwnerRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'requester_name'  => $this->requester_name,
            'requester_phone' => $this->requester_phone,
            'channel'         => $this->channel,
            'status'          => $this->status,
            'park'            => $this->whenLoaded('park', fn () => [
                'id'   => $this->park?->id,
                'name' => $this->park?->name,
            ]),
            'created_at'      => $this->created_at?->toIso8601String(),
            'decided_at'      => $this->decided_at?->toIso8601String(),
        ];
    }
}
