<?php

namespace App\Http\Controllers;

use App\Http\Requests\CarRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CarController extends Controller
{
    /**
     * List cars belonging to a given park.
     */
    public function index(string $parkId): AnonymousResourceCollection
    {
        return CarResource::collection(
            Car::where('park_id', $parkId)->paginate(10)
        );
    }

    /**
     * Create a car owned by the authenticated user.
     */
    public function store(CarRequest $request): JsonResponse
    {
        $car = $request->user()->cars()->create($request->validated());

        return (new CarResource($car))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function show(string $id): JsonResource
    {
        $car = Car::findOrFail($id);
        return new CarResource($car);
    }

    /**
     * Update a car the authenticated user owns.
     */
    public function update(CarRequest $request, string $id): JsonResource
    {
        $car = Car::findOrFail($id);
        $this->authorizeOwnership($request, $car);
        $car->update($request->validated());

        return new CarResource($car);
    }

    /**
     * Delete a car the authenticated user owns.
     */

    public function destroy(Request $request, string $id): Response
    {
        $car = Car::findOrFail($id);
        $this->authorizeOwnership($request, $car);
        $car->delete();

        return response()->noContent();
    }

    /**
     * Ensure the authenticated user is the owner of the car.
     */

    private function authorizeOwnership(Request $request, Car $car): void
    {
        abort_unless($car->user_id === $request->user()->id, 403);
    }
}
