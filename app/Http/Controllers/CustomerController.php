<?php

namespace App\Http\Controllers;

use App\Http\Requests\NearbyParksRequest;
use App\Http\Resources\NearbyParkResource;
use App\Repositories\Contracts\ParkRepositoryInterface;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Customer-facing endpoints. Customers don't manage parks — they discover and
 * (later) reserve them.
 */
class CustomerController extends Controller
{
    public function __construct(
        private readonly ParkRepositoryInterface $parks,
    ) {}

    /**
     * GET /api/customer/parks/nearby?latitude=..&longitude=..&radius=..&limit=..
     *
     * Returns parks within `radius` meters (default 5 km) of the given point,
     * ordered from closest to farthest. Each result includes `distance_meters`.
     */
    public function nearbyParks(NearbyParksRequest $request): AnonymousResourceCollection
    {
        $data = $request->validated();

        $parks = $this->parks->nearby(
            latitude:     (float) $data['latitude'],
            longitude:    (float) $data['longitude'],
            radiusMeters: (int) ($data['radius'] ?? 5000),
            limit:        (int) ($data['limit'] ?? 20),
        );

        return NearbyParkResource::collection($parks);
    }
}
