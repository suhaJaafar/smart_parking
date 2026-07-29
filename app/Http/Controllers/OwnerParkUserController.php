<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesDateRange;
use App\Http\Requests\ParkHistoryRequest;
use App\Http\Resources\ParkUserResource;
use App\Models\Reserve;
use App\Models\User;
use App\Services\OwnerStatsService;
use App\Support\CsvExporter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Space-owner facing "customers" surface: every user who has ever made a
 * reservation at one of the owner's garages — regardless of how it ended
 * (completed, cancelled, expired, still waiting, or currently active).
 *
 * This is the person-centric counterpart to the reservation list: one row
 * per customer with their lifetime activity at the owner's parks, so the
 * operator can see who their repeat visitors are and reach them by phone.
 *
 * Scope is enforced two ways, like the other owner endpoints: the
 * `role:SPACE_OWNER,SUPER_ADMIN` route middleware, plus a
 * `whereIn(park_id, ownedParkIds)` data-level guard here.
 */
class OwnerParkUserController extends Controller
{
    use ResolvesDateRange;

    /**
     * Paginated list of distinct customers with their per-owner activity
     * aggregates, newest activity first.
     */
    public function index(ParkHistoryRequest $request): AnonymousResourceCollection
    {
        return ParkUserResource::collection(
            $this->customersQuery($request)->paginate(20)
        );
    }

    /**
     * Stream every customer (no pagination) as a CSV (Excel) download.
     */
    public function export(ParkHistoryRequest $request): StreamedResponse
    {
        $query = $this->customersQuery($request);

        [$from, $to] = $this->dateBounds(
            $request->validated('from'),
            $request->validated('to'),
        );

        $headers = [
            'Customer', 'Phone', 'Plate', 'Model', 'Total reservations',
            'Completed', 'Active', 'Waiting', 'Cancelled', 'Expired',
            'First reservation', 'Last reservation',
        ];

        $rows = function () use ($query) {
            foreach ($query->lazy() as $user) {
                $car = $user->cars->first();

                yield [
                    $user->name,
                    $user->phone_number,
                    $car ? trim("{$car->plate_prefix}-{$car->car_number}", '-') : null,
                    $car?->model,
                    (int) $user->reservations_total,
                    (int) $user->reservations_completed,
                    (int) $user->reservations_active,
                    (int) $user->reservations_waiting,
                    (int) $user->reservations_cancelled,
                    (int) $user->reservations_expired,
                    $this->toDateTime($user->first_reservation_at),
                    $this->toDateTime($user->last_reservation_at),
                ];
            }
        };

        return CsvExporter::stream(
            $this->exportFilename('park-customers', $from, $to),
            $headers,
            $rows(),
        );
    }

    /**
     * Customers with at least one reservation at the owner's garages, each
     * carrying lifetime status counts and first/last activity, newest first.
     *
     * A single `$scope` closure is reused for the `whereHas` existence check,
     * every `withCount` bucket, and the first/last sub-selects so they all
     * cover exactly the same reservations (owner's parks + optional single
     * garage + optional date window). Mirrors the `withCount` approach in
     * {@see OwnerStatsService}.
     *
     * @return Builder<User>
     */
    private function customersQuery(ParkHistoryRequest $request): Builder
    {
        $parkIds = $this->ownedParkIds($request->user());

        $parkId = $request->validated('park_id');
        $onlyParkId = (is_string($parkId) && $parkIds->contains($parkId)) ? $parkId : null;

        [$from, $to] = $this->dateBounds(
            $request->validated('from'),
            $request->validated('to'),
        );

        // Applied identically to the existence check, each count bucket and
        // the first/last sub-selects so every aggregate covers the same rows.
        $scope = function ($q) use ($parkIds, $onlyParkId, $from, $to) {
            $q->whereIn('park_id', $parkIds);
            if ($onlyParkId !== null) {
                $q->where('park_id', $onlyParkId);
            }
            if ($from !== null) {
                $q->where('created_at', '>=', $from);
            }
            if ($to !== null) {
                $q->where('created_at', '<=', $to);
            }
        };

        $countFor = fn (int $status) => function ($q) use ($scope, $status) {
            $scope($q);
            $q->where('status', $status);
        };

        $firstSub = Reserve::query()
            ->select('created_at')
            ->whereColumn('user_id', 'users.id')
            ->oldest('created_at')
            ->limit(1);
        $scope($firstSub);

        $lastSub = Reserve::query()
            ->select('created_at')
            ->whereColumn('user_id', 'users.id')
            ->latest('created_at')
            ->limit(1);
        $scope($lastSub);

        return User::query()
            ->select('users.*')
            ->selectSub($firstSub, 'first_reservation_at')
            ->selectSub($lastSub, 'last_reservation_at')
            ->whereHas('reserves', $scope)
            ->withCount([
                'reserves as reservations_total' => $scope,
                'reserves as reservations_completed' => $countFor(Reserve::STATUS_COMPLETED),
                'reserves as reservations_active' => $countFor(Reserve::STATUS_ACTIVE),
                'reserves as reservations_waiting' => $countFor(Reserve::STATUS_START),
                'reserves as reservations_cancelled' => $countFor(Reserve::STATUS_CANCELLED),
                'reserves as reservations_expired' => $countFor(Reserve::STATUS_EXPIRED),
            ])
            ->with(['cars' => fn ($q) => $q->latest()])
            ->orderByDesc('last_reservation_at');
    }

    private function toDateTime(?string $value): ?string
    {
        return $value !== null && $value !== ''
            ? CarbonImmutable::parse($value)->toDateTimeString()
            : null;
    }

    /**
     * @return Collection<int, string>
     */
    private function ownedParkIds(User $owner): Collection
    {
        return $owner->ownedParks()->pluck('id');
    }
}
