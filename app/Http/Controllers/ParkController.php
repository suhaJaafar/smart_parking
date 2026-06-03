<?php

namespace App\Http\Controllers;

use App\Http\Requests\ParkRequest;
use App\Http\Requests\StoreParkRequest;
use App\Http\Resources\ParkResource;
use App\Models\User;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Services\ParkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ParkController extends Controller
{
    public function __construct(
        private readonly ParkRepositoryInterface $parks,
        private readonly ParkService $parkService,
    ) {}

    /**
     * List all parks (paginated).
     */
    public function index(): AnonymousResourceCollection
    {
        return ParkResource::collection($this->parks->paginate(10));
    }

    /**
     * Parks owned by the authenticated user (SPACE_OWNER).
     */
    public function userParks(Request $request): AnonymousResourceCollection
    {
        return ParkResource::collection(
            $this->parks->paginateByOwner($request->user(), 10)
        );
    }

    /**
     * Create a park together with its location, automatically.
     *
     * Owner resolution:
     *  - If the request carries a validated `user_id` (only possible when the
     *    actor is SUPER_ADMIN — see StoreParkRequest), that user becomes the
     *    owner. The ParkService will also promote them to SPACE_OWNER.
     *  - Otherwise the park is owned by the authenticated user.
     */
    public function store(StoreParkRequest $request): JsonResponse
    {
        $ownerId = $request->ownerOverrideId();
        $owner   = $ownerId !== null
            ? User::findOrFail($ownerId)
            : $request->user();

        $park = $this->parkService->createWithLocation(
            locationData: $request->locationData(),
            parkData:     $request->parkData(),
            owner:        $owner,
        );

        return (new ParkResource($park->load(['location', 'owner:id,name,email'])))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function show(string $id): JsonResource
    {
        $park = $this->parks->findById($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);

        $park->load([
            'location',
            'owner:id,name,email',
            'cars' => fn ($q) => $q->whereNotNull('park_id')
                ->with('user:id,phone_number'),
        ]);

        return new ParkResource($park);
    }

    public function update(ParkRequest $request, string $id): JsonResource
    {
        $park = $this->parks->findById($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);
        $this->authorize('update', $park);

        $park = $this->parks->update($park, $request->validated());

        return new ParkResource($park->load(['location', 'owner:id,name,email']));
    }

    public function destroy(Request $request, string $id): Response
    {
        $park = $this->parks->findById($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);
        $this->authorize('delete', $park);

        $this->parks->delete($park);

        return response()->noContent();
    }


    // ===============================
    // Enter new car into park
    // ===============================
    public function enterCar(string $id): JsonResponse
    {
        $park = $this->parks->findById($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);

        if ($park->free_spaces <= 0) {
            return response()->json(['message' => 'الموقف ممتلئ. لا توجد أماكن فارغة.'], HttpResponse::HTTP_BAD_REQUEST);
        }
        $park->free_spaces -= 1;
        $park->save();
        return response()->json(['message' => 'تم دخول السيارة إلى الموقف بنجاح.']);
    }

    // ===============================
    // Exit car from park
    // ===============================
    public function exitCar(string $id): JsonResponse
    {
        $park = $this->parks->findById($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);
        if ($park->free_spaces >= $park->capacity) {
            return response()->json(['message' => 'الموقف فارغ. لا توجد سيارات داخله.'], HttpResponse::HTTP_BAD_REQUEST);
        }
        $park->free_spaces += 1;
        $park->save();
        return response()->json(['message' => 'تم خروج السيارة من الموقف بنجاح.']);
    }
}
