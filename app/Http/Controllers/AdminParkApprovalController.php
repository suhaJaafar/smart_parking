<?php

namespace App\Http\Controllers;

use App\Bots\Support\ParkApprovalNotifier;
use App\Http\Resources\ParkResource;
use App\Models\Park;
use App\Services\ParkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin review queue for newly registered garages.
 *
 * Mirrors {@see CoOwnerRequestController}: a list, an approve and a reject,
 * each returning the updated resource so the dashboard can patch its row in
 * place rather than refetching the whole page.
 *
 * The decision itself lives in {@see ParkService} because approving is not a
 * status write — it also grants the owner role — and the bot could one day
 * need the same action.
 */
class AdminParkApprovalController extends Controller
{
    private const PARK_WITH = ['location', 'owner:id,name,email,phone_number'];

    public function __construct(
        private readonly ParkService $parks,
        private readonly ParkApprovalNotifier $notifier,
    ) {}

    /**
     * Garages awaiting a decision, oldest first.
     *
     * Oldest-first is deliberate: this is a queue with a 24-hour promise
     * attached, so the one closest to breaking that promise sorts to the top.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $status = (string) $request->input('status', Park::APPROVAL_PENDING);

        $query = Park::with(self::PARK_WITH);

        if (in_array($status, [
            Park::APPROVAL_PENDING,
            Park::APPROVAL_APPROVED,
            Park::APPROVAL_REJECTED,
        ], true)) {
            $query->where('approval_status', $status);
        }

        return ParkResource::collection(
            $query->orderBy('created_at')->paginate(20)
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $park = Park::with(self::PARK_WITH)->findOrFail($id);

        $wasPending = ! $park->isApproved();
        $park = $this->parks->approve($park, $request->user());

        // Only announce a real transition, so re-clicking approve cannot spam
        // the owner with duplicate "your garage is live" messages.
        if ($wasPending) {
            $this->notifier->notifyOwnerOfApproval($park);
        }

        return response()->json([
            'message' => 'Garage approved.',
            'data'    => new ParkResource($park->load(self::PARK_WITH)),
        ]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $park = Park::with(self::PARK_WITH)->findOrFail($id);
        $park = $this->parks->reject($park, $request->user(), $validated['reason'] ?? null);

        $this->notifier->notifyOwnerOfRejection($park, $validated['reason'] ?? null);

        return response()->json([
            'message' => 'Garage rejected.',
            'data'    => new ParkResource($park->load(self::PARK_WITH)),
        ]);
    }
}
