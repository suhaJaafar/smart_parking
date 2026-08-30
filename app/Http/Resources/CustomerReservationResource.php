<?php

namespace App\Http\Resources;

use App\Enums\PaymentStatusTypes;
use App\Models\Reserve;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing view of their own reservation.
 *
 * Deliberately narrower than {@see OwnerReservationResource}: a customer sees
 * their booking code, where they are parked, how long they have to arrive and
 * whether payment is due — never other customers, and never owner-side data.
 *
 * @mixin Reserve
 */
class CustomerReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = (int) $this->status;
        $isLive = $status === Reserve::STATUS_START
            && ($this->expires_at === null || $this->expires_at->isFuture());

        $payment = $this->whenLoaded(
            'payments',
            fn () => $this->payments
                ->sortByDesc('created_at')
                ->first(),
        );

        return [
            'id'             => $this->id,
            'status'         => $status,
            'status_label'   => $this->statusLabel($status, $isLive),
            'is_pre_booking' => (bool) $this->is_pre_booking,
            'booking_code'   => $this->booking_code,
            'park'           => $this->whenLoaded('park', fn () => [
                'id'          => $this->park?->id,
                'name'        => $this->park?->name,
                'price'       => $this->park?->price,
                'free_spaces' => $this->park?->free_spaces,
                'latitude'    => $this->park?->location?->latitude,
                'longitude'   => $this->park?->location?->longitude,
            ]),
            'scheduled_at'   => $this->scheduled_at?->toIso8601String(),
            'expires_at'     => $this->expires_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),

            // Payment only exists once the owner has entered the car.
            'payment'        => $payment ? [
                'status'   => $payment->status instanceof PaymentStatusTypes
                    ? $payment->status->value
                    : (string) $payment->status,
                'method'   => $payment->method,
                'is_cash'  => $payment->isCash(),
                'amount'   => $payment->amount,
                'currency' => $payment->currency,
                'is_paid'  => $payment->isPaid(),
                // Short, unguessable pay link the Mini App opens externally.
                'pay_url'  => route('payments.redirect', $payment->token),
            ] : null,

            // Derived here so the UI never re-encodes domain rules.
            'can_cancel'     => $status === Reserve::STATUS_START,
        ];
    }

    private function statusLabel(int $status, bool $isLive): string
    {
        return match ($status) {
            Reserve::STATUS_START     => $isLive ? 'waiting' : 'lapsed',
            Reserve::STATUS_ACTIVE    => 'active',
            Reserve::STATUS_COMPLETED => 'completed',
            Reserve::STATUS_EXPIRED   => 'expired',
            Reserve::STATUS_CANCELLED => 'cancelled',
            default                   => 'unknown',
        };
    }
}
