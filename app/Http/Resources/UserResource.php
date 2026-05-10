<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\RoleResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            // 'location' => new LocationResource($this->whenLoaded('location')),
            // 'car' => new CarResource($this->whenLoaded('car')),
            // 'parks' => ParkResource::collection($this->whenLoaded('parks')),
        ];
    }
}
