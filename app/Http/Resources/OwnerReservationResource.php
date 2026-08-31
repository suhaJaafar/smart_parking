<?php

namespace App\Http\Resources;

use App\Models\Car;
use App\Models\Reserve;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Owner-facing view of a single reservation, whatever its lifecycle stage.
 *
 * Where {@see OwnerHoldResource} is scoped to pending holds only (the
 * "waiting to enter" list), this one exposes every stage — START, ACTIVE,
 * COMPLETED, EXPIRED, CANCELLED — so the dashboard reservations page can
 * present the full picture and drive per-row actions (cancel a hold, exit
 * a car) that mirror the bot procedures.
 *
 * @mixin Reserve
 */
class OwnerReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status  = (int) $this->status;
        $isLive  = $status === Reserve::STATUS_START
            && $this->expires_at !== null
            && $this->expires_at->isFuture();

        /** @var Car|null $car */
        $car = $this->whenLoaded('user', fn () => $this->user?->cars?->first());

        return [
            'id'             => $this->id,
            'status'         => $status,
            'status_label'   => $this->statusLabel($status, $isLive),
            'is_pre_booking' => (bool) $this->is_pre_booking,
            'booking_code'   => $this->booking_code,
            'park_id'        => $this->park_id,
            'park'           => $this->whenLoaded('park', fn () => [
                'id'   => $this->park?->id,
                'name' => $this->park?->name,
            ]),
            'customer'       => $this->whenLoaded('user', fn () => [
                'id'           => $this->user?->id,
                'name'         => $this->user?->name,
                'phone_number' => $this->user?->phone_number,
            ]),
            'car'            => $car instanceof Car ? [
                'id'           => $car->id,
                'plate_prefix' => $car->plate_prefix,
                'car_number'   => $car->car_number,
                'plate'        => trim("{$car->plate_prefix}-{$car->car_number}", '-'),
                'model'        => $car->model,
            ] : null,
            'scheduled_at'   => $this->scheduled_at?->toIso8601String(),
            'expires_at'     => $this->expires_at?->toIso8601String(),
            'created_at'     => $this->created_at?->toIso8601String(),
            'updated_at'     => $this->updated_at?->toIso8601String(),
            // Which actions the dashboard row should offer. Derived here so
            // the UI never has to re-encode the domain rules.
            'can_admit'      => $status === Reserve::STATUS_START,
            'can_cancel'     => $status === Reserve::STATUS_START,
            'can_exit_car'   => $status === Reserve::STATUS_ACTIVE,
        ];
    }

    /**
     * Machine-readable slug — kept ASCII so the dashboard can key styles
     * off it without worrying about the numeric codes. The `waiting` value
     * further distinguishes a still-live hold from a stale START row
     * awaiting the sweep.
     */
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
