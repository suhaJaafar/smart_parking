<?php

namespace App\Exceptions;

use App\Models\Park;
use App\Models\Reserve;
use RuntimeException;

/**
 * The customer already holds a live reservation somewhere else.
 *
 * A driver can only occupy one space at a time, so a second reservation is
 * refused up front — at the moment they tap "احجز" — rather than letting them
 * discover the clash on arrival.
 *
 * Extends {@see RuntimeException} deliberately: every existing caller of
 * {@see \App\Services\ReservationService::reserve()} already catches that
 * type, so adding this guard cannot turn into an unhandled 500 anywhere.
 */
class ActiveReservationElsewhere extends RuntimeException
{
    public function __construct(
        public readonly Reserve $existing,
        public readonly ?Park $park,
    ) {
        parent::__construct('ACTIVE_RESERVATION_ELSEWHERE');
    }

    /** Name of the garage the customer is still tied to. */
    public function parkName(): string
    {
        return $this->park?->name ?? 'موقف آخر';
    }

    /** True once the car is physically inside — they must exit, not cancel. */
    public function isCarInside(): bool
    {
        return (int) $this->existing->status === Reserve::STATUS_ACTIVE;
    }
}
