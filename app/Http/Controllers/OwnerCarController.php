<?php

namespace App\Http\Controllers;

use App\Data\CarPlate;
use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Requests\ParkHistoryRequest;
use App\Http\Requests\StoreOwnerCarRequest;
use App\Http\Requests\UpdateOwnerCarRequest;
use App\Http\Resources\OwnerCarResource;
use App\Http\Resources\OwnerHoldResource;
use App\Http\Resources\ParkCarHistoryResource;
use App\Models\Car;
use App\Models\Park;
use App\Models\Reserve;
use App\Models\User;
use App\Services\CarService;
use App\Services\ReservationService;
use App\Support\CsvExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    use ResolvesDateRange;

    public function __construct(
        private readonly CarService $cars,
        private readonly ReservationService $reservations,
    ) {}

    /**
     * List the cars currently inside the signed-in owner's garages, together
     * with the cars still *waiting to enter* — reservations placed from the
     * bot that haven't physically driven in yet.
     *
     * The parked cars are the paginated `data`; the waiting holds ride along
     * under `waiting`. Holds reserve a customer's intent but do NOT occupy a
     * physical slot (free_spaces only drops on real entry), so one request
     * gives the owner the full picture without a second round-trip.
     *
     * Optional `?park_id=` narrows both lists to a single garage (must belong
     * to the owner, otherwise it is ignored).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $owner = $request->user();
        $parkIds = $this->ownedParkIds($owner);

        $parkId = $request->query('park_id');
        $onlyParkId = (is_string($parkId) && $parkIds->contains($parkId)) ? $parkId : null;

        $query = Car::query()
            ->whereIn('park_id', $parkIds)
            ->with(['park:id,name', 'user:id,name,phone_number'])
            ->latest();

        if ($onlyParkId !== null) {
            $query->where('park_id', $onlyParkId);
        }

        $waiting = $this->reservations->pendingForParkIds($parkIds, $onlyParkId);

        return OwnerCarResource::collection($query->paginate(20))
            ->additional([
                'waiting' => OwnerHoldResource::collection($waiting),
            ]);
    }

    /**
     * Historical parking sessions — cars that entered one of the owner's
     * garages in the past and have since left (COMPLETED reservations).
     *
     * This is intentionally NOT the currently-parked list: it is the audit
     * trail of who has used the garage over time, filterable by garage and
     * date window, paginated for the dashboard.
     */
    public function history(ParkHistoryRequest $request): AnonymousResourceCollection
    {
        return ParkCarHistoryResource::collection(
            $this->historyQuery($request)->paginate(20)
        );
    }

    /**
     * Stream the full historical parking sessions (COMPLETED reservations)
     * matching the garage + date window as a CSV (Excel) download.
     */
    public function exportHistory(ParkHistoryRequest $request): StreamedResponse
    {
        $query = $this->historyQuery($request);

        [$from, $to] = $this->dateBounds(
            $request->validated('from'),
            $request->validated('to'),
        );

        $headers = [
            'Booking code', 'Plate', 'Model', 'Owner', 'Phone', 'Garage',
            'Entered at', 'Exited at', 'Duration (min)',
        ];

        $rows = function () use ($query) {
            foreach ($query->lazy() as $reserve) {
                $car = $reserve->user?->cars?->first();

                $durationMinutes = $reserve->created_at !== null && $reserve->updated_at !== null
                    ? max(0, (int) round($reserve->created_at->diffInSeconds($reserve->updated_at, false) / 60))
                    : null;

                yield [
                    $reserve->booking_code,
                    $car ? trim("{$car->plate_prefix}-{$car->car_number}", '-') : null,
                    $car?->model,
                    $reserve->user?->name,
                    $reserve->user?->phone_number,
                    $reserve->park?->name,
                    $reserve->created_at?->toDateTimeString(),
                    $reserve->updated_at?->toDateTimeString(),
                    $durationMinutes,
                ];
            }
        };

        return CsvExporter::stream(
            $this->exportFilename('park-cars', $from, $to),
            $headers,
            $rows(),
        );
    }

    /**
     * Shared query for the historical parking sessions: COMPLETED
     * reservations across the owner's garages, newest first, scoped by an
     * optional single garage and an optional date window.
     *
     * @return Builder<Reserve>
     */
    private function historyQuery(ParkHistoryRequest $request): Builder
    {
        $owner = $request->user();
        $parkIds = $this->ownedParkIds($owner);

        $query = Reserve::query()
            ->where('status', Reserve::STATUS_COMPLETED)
            ->whereIn('park_id', $parkIds)
            ->with([
                'park:id,name',
                'user:id,name,phone_number',
                'user.cars' => fn ($q) => $q->latest(),
            ])
            ->latest('created_at');

        $parkId = $request->validated('park_id');
        if (is_string($parkId) && $parkIds->contains($parkId)) {
            $query->where('park_id', $parkId);
        }

        [$from, $to] = $this->dateBounds(
            $request->validated('from'),
            $request->validated('to'),
        );
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        return $query;
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
        $data = $request->validated();

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
            'data' => new OwnerCarResource($car->load(['park:id,name', 'user:id,name,phone_number'])),
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
            'data' => new OwnerCarResource($car->load(['park:id,name', 'user:id,name,phone_number'])),
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
     * @return Collection<int, string>
     */
    private function ownedParkIds(User $owner): Collection
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
