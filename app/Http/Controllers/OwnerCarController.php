<?php

namespace App\Http\Controllers;

use App\Data\CarPlate;
use App\Http\Requests\StoreOwnerCarRequest;
use App\Http\Requests\UpdateOwnerCarRequest;
use App\Http\Resources\OwnerCarResource;
use App\Models\Car;
use App\Models\Park;
use App\Models\User;
use App\Services\CarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Space-owner facing CRUD for the cars physically parked inside the owner's
 * garages.
 *
 * Every action is scoped to parks the authenticated user owns — the
 * `role:SPACE_OWNER,SUPER_ADMIN` route middleware gates access, and the
 * `whereIn(park_id, ownedParkIds)` filter here is a second, data-level guard
 * so one owner can never see or touch cars in another owner's garage.
 *
 * Slot accounting (`free_spaces`) always flows through {@see CarService}'s
 * atomic `enterPark`/`exitPark`, keeping this surface consistent with the bot
 * and reservation flows.
 */
class OwnerCarController extends Controller
{
    public function __construct(
        private readonly CarService $cars,
    ) {}

    /**
     * List the cars currently inside the signed-in owner's garages.
     *
     * Optional `?park_id=` narrows to a single garage (must belong to the
     * owner, otherwise it is ignored).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $owner    = $request->user();
        $parkIds  = $this->ownedParkIds($owner);

        $query = Car::query()
            ->whereIn('park_id', $parkIds)
            ->with(['park:id,name', 'user:id,name,phone_number'])
            ->latest();

        $parkId = $request->query('park_id');
        if (is_string($parkId) && $parkIds->contains($parkId)) {
            $query->where('park_id', $parkId);
        }

        return OwnerCarResource::collection($query->paginate(20));
    }

    /**
     * Show a single car inside one of the owner's garages.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $car = $this->carForOwner($request->user(), $id);

        return response()->json([
            'data' => new OwnerCarResource($car->load(['park:id,name', 'user:id,name,phone_number'])),
        ]);
    }

    /**
     * Record a car that entered one of the owner's garages.
     *
     * The car is resolved (or created) by its unique plate, then parked via
     * {@see CarService::enterPark} which decrements `free_spaces` atomically
     * and rejects a full park or a car already inside another garage.
     */
    public function store(StoreOwnerCarRequest $request): JsonResponse
    {
        $owner = $request->user();
        $data  = $request->validated();

        $park = $this->ownedParkOrFail($owner, $data['park_id']);

        $plate = new CarPlate(
            prefix: $data['plate_prefix'],
            number: $data['car_number'],
        );

        $car = $this->cars->findOrCreateByPlate($plate, $owner, $data['model'] ?? null);

        try {
            $car = $this->cars->enterPark($car, $park);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'park_id' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => 'Car added to the park.',
            'data'    => new OwnerCarResource($car->load(['park:id,name', 'user:id,name,phone_number'])),
        ], HttpResponse::HTTP_CREATED);
    }

    /**
     * Update a car's plate or model. Moving a car between parks is not done
     * here — use the enter/exit flows so slot accounting stays correct.
     */
    public function update(UpdateOwnerCarRequest $request, string $id): JsonResponse
    {
        $car = $this->carForOwner($request->user(), $id);

        $car = $this->cars->patch($car, $request->validated());

        return response()->json([
            'message' => 'Car updated.',
            'data'    => new OwnerCarResource($car->load(['park:id,name', 'user:id,name,phone_number'])),
        ]);
    }

    /**
     * Remove a car from the owner's garage. Frees its slot before deleting.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $car = $this->carForOwner($request->user(), $id);

        if ($car->park_id !== null) {
            $this->cars->exitPark($car);
        }

        $this->cars->delete($car);

        return response()->json(null, HttpResponse::HTTP_NO_CONTENT);
    }

    /**
     * IDs of every park owned by the given user.
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function ownedParkIds(User $owner): \Illuminate\Support\Collection
    {
        return $owner->ownedParks()->pluck('id');
    }

    /**
     * Resolve a car that sits inside one of the owner's garages, or 404.
     */
    private function carForOwner(User $owner, string $id): Car
    {
        return Car::query()
            ->whereKey($id)
            ->whereIn('park_id', $this->ownedParkIds($owner))
            ->firstOrFail();
    }

    /**
     * Resolve a park the owner actually owns, or fail validation.
     */
    private function ownedParkOrFail(User $owner, string $parkId): Park
    {
        $park = Park::query()
            ->whereKey($parkId)
            ->where('user_id', $owner->id)
            ->first();

        if ($park === null) {
            throw ValidationException::withMessages([
                'park_id' => 'Selected park is not one of your garages.',
            ]);
        }

        return $park;
    }
}
