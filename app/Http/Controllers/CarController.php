<?php

namespace App\Http\Controllers;

use App\Http\Requests\CarRequest;
use App\Http\Resources\CarResource;
use App\Models\Car;
use App\Services\CarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class CarController extends Controller
{
    public function __construct(
        private readonly CarService $carService,
    ) {}

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
        $car = $this->carService->store($request->validated(), $request->user());

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
        abort_if($car->owner_id !== $request->user()->id, HttpResponse::HTTP_FORBIDDEN);

        $car = $this->carService->patch($car, $request->validated());

        return new CarResource($car);
    }

    public function destroy(Request $request, string $id): Response
    {
        $car = Car::findOrFail($id);
        abort_if($car->owner_id !== $request->user()->id, HttpResponse::HTTP_FORBIDDEN);

        $this->carService->delete($car);

        return response()->noContent();
    }
}
