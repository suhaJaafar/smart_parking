<?php

namespace App\Http\Controllers;

use App\Http\Resources\OwnerReservationResource;
use App\Models\Car;
use App\Models\Reserve;
use App\Models\User;
use App\Services\CarService;
use App\Services\ReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Space-owner facing surface for reservations in the owner's garages.
 *
 * Every method is scoped to parks the authenticated user owns — the
 * `role:SPACE_OWNER,SUPER_ADMIN` route middleware gates access, and the
 * `whereIn(park_id, ownedParkIds)` guard here is a second, data-level check
 * so one owner can never see or touch another owner's reservations.
 *
 * Domain writes reuse the exact same services the bot flows use
 * ({@see ReservationService}, {@see CarService}), so cancelling a hold or
 * exiting a car from the dashboard runs through the same procedure — free
 * space accounting, reservation lifecycle transitions, notifications — as
 * doing it in Telegram/WhatsApp. No shortcuts, no duplication.
 */
class OwnerReservationController extends Controller
{
    /**
     * Relations every response payload needs — eager-loaded up front so
     * {@see OwnerReservationResource} never triggers an N+1 query.
     */
    private const RESPONSE_RELATIONS = [
        'park:id,name',
        'user:id,name,phone_number',
    ];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly CarService $cars,
    ) {}

    /**
     * Merge {@see RESPONSE_RELATIONS} with the `user.cars` constraint.
     * Split out so `->with()` and `->fresh()` share the exact same spec.
     *
     * @return array<int|string, string|\Closure>
     */
    private function responseRelations(): array
    {
        return array_merge(self::RESPONSE_RELATIONS, [
            'user.cars' => fn ($q) => $q->latest(),
        ]);
    }

    /**
     * List reservations in the owner's garages, filterable by park and
     * lifecycle bucket. Default surfaces the "live" set — pending live
     * holds (STATUS_START within TTL) plus every ACTIVE stay — because
     * that is what an owner acts on.
     *
     * Supported filters:
     *   ?park_id=<uuid>         narrow to a single owned park
     *   ?filter=live|waiting|active|history|all
     *     live      (default) — waiting + active
     *     waiting   — live pending holds only (see Reserve::livePending)
     *     active    — cars physically inside
     *     history   — completed + expired + cancelled
     *     all       — everything (no status filter)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $owner   = $request->user();
        $parkIds = $this->ownedParkIds($owner);

        $query = Reserve::query()
            ->whereIn('park_id', $parkIds)
            ->with($this->responseRelations())
            ->latest('created_at');

        $parkId = $request->query('park_id');
        if (is_string($parkId) && $parkIds->contains($parkId)) {
            $query->where('park_id', $parkId);
        }

        $filter = (string) $request->query('filter', 'live');
        $this->applyFilter($query, $filter);

        return OwnerReservationResource::collection($query->paginate(20));
    }

    /**
     * Show a single reservation. Kept minimal — the dashboard row already
     * carries every field the detail view needs.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $reserve = $this->reserveForOwner($request->user(), $id);

        return response()->json([
            'data' => new OwnerReservationResource(
                $reserve->load($this->responseRelations())
            ),
        ]);
    }

    /**
     * Cancel a pending hold on behalf of the customer. Only meaningful
     * while the reservation is still STATUS_START — an already-active,
     * completed, expired, or cancelled row is left as-is and reported
     * back as a validation error so the UI can render a clear reason.
     *
     * The lifecycle transition is delegated to
     * {@see ReservationService::cancel()} so this endpoint behaves exactly
     * like the customer's own cancel path in the bot.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $reserve = $this->reserveForOwner($request->user(), $id);

        if ($reserve->status !== Reserve::STATUS_START) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending hold can be cancelled.',
            ]);
        }

        $reserve = $this->reservations->cancel($reserve);

        return response()->json([
            'message' => 'Reservation cancelled.',
            'data'    => new OwnerReservationResource(
                $reserve->load($this->responseRelations())
            ),
        ]);
    }

    /**
     * Exit the customer's car and close their reservation — the dashboard
     * counterpart to the owner's Telegram CarExitFlow.
     *
     * Only valid while the reservation is STATUS_ACTIVE (car is inside).
     * Mirrors the bot exactly:
     *   1) find the customer's car currently parked in this park
     *   2) run it through CarService::exitPark (frees the slot atomically)
     *   3) transition the reservation → COMPLETED via ReservationService
     *
     * The car may be absent in edge cases (already exited via another
     * path, data drift). In that case we still complete the reservation
     * and log a warning so the operator has a breadcrumb — never crash on
     * a missing car, exactly like the sweep at expireStaleActive().
     */
    public function exitCar(Request $request, string $id): JsonResponse
    {
        $reserve = $this->reserveForOwner($request->user(), $id);

        if ($reserve->status !== Reserve::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'status' => 'Only an active reservation can be exited.',
            ]);
        }

        $park = $reserve->park;
        if (!$park) {
            throw ValidationException::withMessages([
                'park_id' => 'This reservation is no longer attached to a park.',
            ]);
        }

        try {
            $car = Car::where('user_id', $reserve->user_id)
                ->where('park_id', $park->id)
                ->first();

            if ($car) {
                $this->cars->exitPark($car);
            } else {
                Log::warning('Owner dashboard exit: no car found for ACTIVE reservation', [
                    'reserve_id' => $reserve->id,
                    'user_id'    => $reserve->user_id,
                    'park_id'    => $park->id,
                ]);
            }

            $customer = $reserve->user;
            if ($customer) {
                $this->reservations->markCompleted($customer, $park);
            }
        } catch (Throwable $e) {
            Log::error('Owner dashboard exit failed', [
                'reserve_id' => $reserve->id,
                'error'      => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'exit' => 'Failed to exit the car. Please try again.',
            ]);
        }

        $reserve = $reserve->fresh($this->responseRelations());

        return response()->json([
            'message' => 'Car exited and reservation completed.',
            'data'    => new OwnerReservationResource($reserve),
        ]);
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
     * Resolve a reservation that belongs to one of the owner's garages, or 404.
     */
    private function reserveForOwner(User $owner, string $id): Reserve
    {
        return Reserve::query()
            ->whereKey($id)
            ->whereIn('park_id', $this->ownedParkIds($owner))
            ->firstOrFail();
    }

    /**
     * Bucketed status filter. Kept in one place so the controller stays lean
     * and the list stays in sync with the dashboard filter tabs.
     *
     * @param  Builder<Reserve>  $query
     */
    private function applyFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'waiting' => $query->livePending(),
            'active'  => $query->where('status', Reserve::STATUS_ACTIVE),
            'history' => $query->whereIn('status', [
                Reserve::STATUS_COMPLETED,
                Reserve::STATUS_EXPIRED,
                Reserve::STATUS_CANCELLED,
            ]),
            'all'     => null,
            default   => $query->where(function ($q) {
                // 'live' = waiting (live START holds) + active
                $q->livePending()
                    ->orWhere('status', Reserve::STATUS_ACTIVE);
            }),
        };
    }
}
