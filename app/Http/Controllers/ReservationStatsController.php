<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReservationStatsRequest;
use App\Models\Park;
use App\Models\User;
use App\Services\ReservationStatsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Reservations analytics — thin presenter over
 * {@see ReservationStatsService}. Two entry points, one for each audience:
 *
 * - `owner()`  → scoped to the signed-in space-owner's `ownedParks()`.
 * - `admin()`  → platform-wide, restricted at the route level to
 *                 SUPER_ADMIN / ADMIN.
 *
 * Every heavy computation happens in the service. This class just resolves
 * the scope + validates that a caller-supplied `?park_id=` is one the
 * caller is actually allowed to inspect, then delegates.
 */
class ReservationStatsController extends Controller
{
    public function __construct(
        private readonly ReservationStatsService $stats,
    ) {}

    /**
     * Owner-facing report: metrics limited to parks the caller owns.
     *
     * A `?park_id=` narrows the report to one of those parks; anything
     * outside the owner's portfolio fails validation with a clear message
     * so the dashboard can render the error inline.
     */
    public function owner(ReservationStatsRequest $request): JsonResponse
    {
        $owner   = $request->user();
        $parkIds = $this->ownedParkIds($owner);

        $parkId = $this->validatedParkIdForOwner($request, $parkIds);

        [$from, $to] = $this->range($request);

        return response()->json([
            'data' => $this->stats->report($parkIds, $parkId, $from, $to),
        ]);
    }

    /**
     * Admin-facing report: metrics across every park on the platform.
     *
     * A `?park_id=` narrows to one park (any park, since admins can inspect
     * the whole system). We do a light existence check so a caller doesn't
     * silently see zeros because they mistyped a UUID.
     */
    public function admin(ReservationStatsRequest $request): JsonResponse
    {
        $parkId = $this->validatedParkIdForAdmin($request);

        [$from, $to] = $this->range($request);

        return response()->json([
            'data' => $this->stats->report(null, $parkId, $from, $to),
        ]);
    }

    /**
     * @return Collection<int, string>
     */
    private function ownedParkIds(User $owner): Collection
    {
        return $owner->ownedParks()->pluck('id');
    }

    /**
     * @param  Collection<int, string>  $ownedParkIds
     */
    private function validatedParkIdForOwner(
        ReservationStatsRequest $request,
        Collection $ownedParkIds,
    ): ?string {
        $parkId = $request->validated('park_id');
        if ($parkId === null || $parkId === '') {
            return null;
        }

        if (! $ownedParkIds->contains($parkId)) {
            throw ValidationException::withMessages([
                'park_id' => 'Selected park is not one of your garages.',
            ]);
        }

        return (string) $parkId;
    }

    private function validatedParkIdForAdmin(ReservationStatsRequest $request): ?string
    {
        $parkId = $request->validated('park_id');
        if ($parkId === null || $parkId === '') {
            return null;
        }

        $exists = Park::query()->whereKey($parkId)->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                'park_id' => 'No park with that id exists.',
            ]);
        }

        return (string) $parkId;
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function range(ReservationStatsRequest $request): array
    {
        $from = $request->validated('from');
        $to   = $request->validated('to');

        return [
            $from !== null ? CarbonImmutable::parse($from) : null,
            $to   !== null ? CarbonImmutable::parse($to)   : null,
        ];
    }
}
