<?php

namespace App\Http\Controllers;

use App\Bots\Support\ParkApprovalNotifier;
use App\Http\Requests\ParkRequest;
use App\Http\Requests\StoreParkRequest;
use App\Http\Resources\ParkResource;
use App\Models\Park;
use App\Models\User;
use App\Services\ParkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ParkController extends Controller
{
    /**
     * Eager-loads applied to every single-park lookup, so the API always
     * returns the same shape (park + location + slim owner).
     */
    private const PARK_WITH = ['location', 'owner:id,name,email'];

    public function __construct(
        private readonly ParkService $parkService,
        private readonly ParkApprovalNotifier $approvalNotifier,
    ) {}

    /**
     * List all parks (paginated).
     */
    public function index(): AnonymousResourceCollection
    {
        return ParkResource::collection(
            Park::with(self::PARK_WITH)->latest()->paginate(10)
        );
    }

    /**
     * Parks owned by the authenticated user (SPACE_OWNER).
     */
    public function userParks(Request $request): AnonymousResourceCollection
    {
        return ParkResource::collection(
            Park::with(self::PARK_WITH)
                ->where('user_id', $request->user()->id)
                ->latest()
                ->paginate(10)
        );
    }

    /**
     * Create a park together with its location, automatically.
     *
     * The garage is created pending review — see {@see ParkService::approve()}
     * for where it goes live and where the SPACE_OWNER role is granted.
     *
     * Owner resolution:
     *  - If the request carries a validated `user_id` (only possible when the
     *    actor is SUPER_ADMIN — see StoreParkRequest), that user becomes the
     *    owner.
     *  - Otherwise the park is owned by the authenticated user.
     */
    public function store(StoreParkRequest $request): JsonResponse
    {
        $ownerId = $request->ownerOverrideId();
        $owner   = $ownerId !== null
            ? User::findOrFail($ownerId)
            : $request->user();

        $park = $this->parkService->createWithLocation(
            location: $request->locationData(),
            park:     $request->parkData(),
            owner:    $owner,
        );

        $this->approvalNotifier->notifyOwnerOfSubmission($park);

        return (new ParkResource($park->load(self::PARK_WITH)))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function show(string $id): ParkResource
    {
        $park = Park::with(self::PARK_WITH)->find($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);

        return new ParkResource($park);
    }

    public function update(ParkRequest $request, string $id): ParkResource
    {
        $park = Park::with(self::PARK_WITH)->find($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);
        $this->authorize('update', $park);

        $park->fill($request->validated())->save();

        return new ParkResource($park->load(self::PARK_WITH));
    }

    public function destroy(Request $request, string $id): Response
    {
        $park = Park::find($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);
        $this->authorize('delete', $park);

        $park->delete();

        return response()->noContent();
    }


    // ===============================
    // Enter new car into park
    // ===============================
    public function enterCar(string $id): JsonResponse
    {
        $park = Park::find($id);
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
        $park = Park::find($id);
        abort_if($park === null, HttpResponse::HTTP_NOT_FOUND);
        if ($park->free_spaces >= $park->capacity) {
            return response()->json(['message' => 'الموقف فارغ. لا توجد سيارات داخله.'], HttpResponse::HTTP_BAD_REQUEST);
        }
        $park->free_spaces += 1;
        $park->save();
        return response()->json(['message' => 'تم خروج السيارة من الموقف بنجاح.']);
    }
}
