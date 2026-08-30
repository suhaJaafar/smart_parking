<?php

namespace App\Http\Controllers;

use App\Bots\Support\ReservationNotifier;
use App\Enums\PaymentStatusTypes;
use App\Exceptions\ActiveReservationElsewhere;
use App\Http\Requests\StoreCustomerReservationRequest;
use App\Http\Resources\CustomerReservationResource;
use App\Models\Park;
use App\Models\Payment;
use App\Models\Reserve;
use App\Services\ReservationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Customer-facing reservation surface, driven by the Telegram Mini App.
 *
 * Every query is scoped to the authenticated user, so a customer can only ever
 * see and act on their own bookings. Writes delegate to
 * {@see ReservationService} — the same service the bot flows use — so placing
 * a hold here runs the identical capacity gate, TTL and duplicate-hold rules
 * as reserving in chat. No behaviour is duplicated.
 */
class CustomerReservationController extends Controller
{
    /** Eager-loads every response needs, so the resource never N+1s. */
    private const RESPONSE_RELATIONS = ['park.location', 'payments'];

    public function __construct(
        private readonly ReservationService $reservations,
        private readonly ReservationNotifier $notifier,
    ) {}

    /**
     * The customer's current booking, if they have one.
     *
     * "Current" means a hold that is still live (see
     * {@see Reserve::scopeLivePending()}) or a stay whose car is inside the
     * park. Anything else is history and is not returned here.
     */
    public function active(Request $request): JsonResponse
    {
        $reserve = $this->currentQuery($request)->first();

        return response()->json([
            'data' => $reserve
                ? new CustomerReservationResource($reserve)
                : null,
        ]);
    }

    /**
     * Place a hold on a park.
     *
     * Idempotent by virtue of the service: tapping reserve twice returns the
     * existing hold rather than stacking a second one.
     */
    public function store(StoreCustomerReservationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $park = Park::whereKey($data['park_id'])->firstOrFail();

        $scheduledAt = isset($data['scheduled_at'])
            ? new \DateTimeImmutable((string) $data['scheduled_at'])
            : null;

        try {
            $reserve = $this->reservations->reserve(
                user:        $request->user(),
                park:        $park,
                preBooking:  $scheduledAt !== null,
                scheduledAt: $scheduledAt,
            );
        } catch (ActiveReservationElsewhere $e) {
            // One driver, one space. Refuse up front and name the garage they
            // are still tied to so the message is actionable.
            throw ValidationException::withMessages([
                $e->isCarInside() ? 'car_inside_elsewhere' : 'hold_elsewhere' => $e->parkName(),
            ]);
        } catch (RuntimeException $e) {
            // The service signals a full park with a bare `PARK_FULL` marker.
            // Translate it into a normal validation failure so the client gets
            // a 422 with a readable message instead of a 500.
            if ($e->getMessage() === 'PARK_FULL') {
                throw ValidationException::withMessages([
                    'park_id' => 'This garage is fully booked right now.',
                ]);
            }

            if ($e->getMessage() === 'PARK_NOT_APPROVED') {
                throw ValidationException::withMessages([
                    'park_unavailable' => 'This garage is not open for bookings yet.',
                ]);
            }

            Log::error('Mini App reservation failed', [
                'user_id' => $request->user()?->id,
                'park_id' => $park->id,
                'error'   => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'park_id' => 'Could not reserve this garage. Please try again.',
            ]);
        }

        $reserve->load(self::RESPONSE_RELATIONS);

        // The bot pings the owner on every new reservation; the Mini App must
        // do the same or an owner would silently miss app-made bookings.
        $this->notifier->notifyOwnerOfNewReservation($reserve, $park);

        return response()->json([
            'message' => 'Reservation confirmed.',
            'data'    => new CustomerReservationResource($reserve),
        ], HttpResponse::HTTP_CREATED);
    }

    /**
     * Everything the customer is finished with, newest first.
     *
     * Deliberately the exact complement of {@see self::active()}: a booking
     * leaves this list only while it is a live hold or a stay in progress, so
     * it moves from the booking screen into the log without ever appearing on
     * both — or on neither.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $this->historyQuery($request)
            ->with(self::RESPONSE_RELATIONS)
            ->latest('created_at');

        $this->applyHistoryFilter($query, (string) $request->input('filter', 'all'));

        $perPage = max(1, min(50, (int) $request->input('per_page', 15)));
        $page    = $query->paginate($perPage);

        return response()->json([
            'data' => CustomerReservationResource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page'    => $page->lastPage(),
                'total'        => $page->total(),
            ],
            // Totals span the whole history rather than the current page, so
            // the header does not shift as the customer pages through.
            'summary' => $this->summary($request),
        ]);
    }

    /**
     * Cancel the customer's own pending hold.
     *
     * Only a START row can be cancelled — once the owner has entered the car
     * the stay must be closed by the owner, not abandoned by the customer.
     */
    public function cancel(Request $request, string $id): JsonResponse
    {
        $reserve = Reserve::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ((int) $reserve->status !== Reserve::STATUS_START) {
            throw ValidationException::withMessages([
                'status' => 'Only a pending reservation can be cancelled.',
            ]);
        }

        $reserve = $this->reservations->cancel($reserve);

        return response()->json([
            'message' => 'Reservation cancelled.',
            'data'    => new CustomerReservationResource(
                $reserve->load(self::RESPONSE_RELATIONS),
            ),
        ]);
    }

    /**
     * Choose to settle this stay in cash rather than online.
     *
     * Nothing is marked paid here — the money changes hands physically. This
     * only records the intent and tells the owner to collect before releasing
     * the car, which is the piece they cannot otherwise know.
     *
     * Reversible: the customer can still open the pay link and pay by card,
     * which the QiCard webhook will settle as normal.
     */
    public function payCash(Request $request, string $id): JsonResponse
    {
        $reserve = Reserve::query()
            ->whereKey($id)
            ->where('user_id', $request->user()->id)
            ->with(self::RESPONSE_RELATIONS)
            ->firstOrFail();

        $payment = $reserve->payments()
            ->where('status', PaymentStatusTypes::CREATED->value)
            ->latest('created_at')
            ->first();

        if (! $payment) {
            throw ValidationException::withMessages([
                'payment' => 'There is nothing outstanding to pay for this reservation.',
            ]);
        }

        $payment->update(['method' => 'cash']);

        if ($reserve->park) {
            $this->notifier->notifyOwnerOfCashChoice(
                $reserve,
                $reserve->park,
                number_format((float) $payment->amount, 0) . ' ' . $payment->currency,
            );
        }

        return response()->json([
            'message' => 'Cash payment selected.',
            'data'    => new CustomerReservationResource($reserve->fresh(self::RESPONSE_RELATIONS)),
        ]);
    }

    /**
     * Live hold or in-progress stay belonging to the caller, newest first.
     *
     * @return Builder<Reserve>
     */
    private function currentQuery(Request $request): Builder
    {
        return Reserve::query()
            ->where('user_id', $request->user()->id)
            ->where(function (Builder $q) {
                $q->livePending()
                    ->orWhere('status', Reserve::STATUS_ACTIVE);
            })
            ->with(self::RESPONSE_RELATIONS)
            ->latest('created_at');
    }

    /**
     * Settled rows belonging to the caller.
     *
     * Spelled out rather than negating {@see Reserve::scopeLivePending()} so
     * the lapsed-but-not-yet-swept case stays visible: a hold whose TTL passed
     * is history the moment it lapses, even though the every-minute sweep has
     * not flipped it to EXPIRED yet. That mirrors `livePending()` exactly, and
     * without it a just-expired hold would belong to no list at all.
     *
     * @return Builder<Reserve>
     */
    private function historyQuery(Request $request): Builder
    {
        return Reserve::query()
            ->where('user_id', $request->user()->id)
            ->where(function (Builder $q) {
                $q->whereIn('status', [
                    Reserve::STATUS_COMPLETED,
                    Reserve::STATUS_EXPIRED,
                    Reserve::STATUS_CANCELLED,
                ])->orWhere(function (Builder $lapsed) {
                    $lapsed->where('status', Reserve::STATUS_START)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now())
                        ->whereDoesntHave('payments', function (Builder $p) {
                            $p->where('status', PaymentStatusTypes::SUCCESS->value);
                        });
                });
            });
    }

    /**
     * @param  Builder<Reserve>  $query
     */
    private function applyHistoryFilter(Builder $query, string $filter): void
    {
        match ($filter) {
            'completed' => $query->where('status', Reserve::STATUS_COMPLETED),
            'cancelled' => $query->whereIn('status', [
                Reserve::STATUS_CANCELLED,
                Reserve::STATUS_EXPIRED,
            ]),
            // Money still owed — the only filter that is actionable.
            'unpaid'    => $query->whereHas('payments', function (Builder $p) {
                $p->where('status', PaymentStatusTypes::CREATED->value);
            }),
            default     => null,
        };
    }

    /**
     * Lifetime totals across the caller's whole history.
     *
     * @return array<string, mixed>
     */
    private function summary(Request $request): array
    {
        $ids = $this->historyQuery($request)->select('id');

        $sumWhere = fn (PaymentStatusTypes $status): string => (string) Payment::query()
            ->whereIn('reserve_id', $ids)
            ->where('status', $status->value)
            ->sum('amount');

        return [
            'stays'      => $this->historyQuery($request)
                ->where('status', Reserve::STATUS_COMPLETED)
                ->count(),
            'paid_total' => $sumWhere(PaymentStatusTypes::SUCCESS),
            'due_total'  => $sumWhere(PaymentStatusTypes::CREATED),
            'currency'   => Payment::query()->whereIn('reserve_id', $ids)->value('currency') ?? 'IQD',
        ];
    }
}
