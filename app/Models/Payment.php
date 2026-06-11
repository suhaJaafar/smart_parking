<?php

namespace App\Models;

use App\Enums\PaymentStatusTypes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single QiCard payment attempt against a {@see Reserve}.
 *
 * Lifecycle:
 *   CREATED  → a row is provisioned the moment a reservation goes ACTIVE
 *              (the customer's car was just entered into the park). The
 *              row carries a stable `token` we hand to the user as a
 *              short URL — visiting it lazily fetches a Qi `formUrl` on
 *              first hit and redirects there.
 *   SUCCESS  → set by the QiCard webhook (or by /payments/return) once
 *              `getPaymentStatus` returns SUCCESS. Triggers customer +
 *              owner confirmation notifications exactly once.
 *   FAILED   → terminal but retriable from the customer's side — they
 *              can revisit the same pay link to start a new Qi session.
 *   CANCELED → customer aborted on Qi's hosted form.
 */
class Payment extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'qi_status',
        'reserve_id',
        'user_id',
        'amount',
        'currency',
        'request_id',
        'payment_id',
        'form_url',
        'token',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'status'  => PaymentStatusTypes::class,
            'amount'  => 'decimal:3',
        ];
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatusTypes::SUCCESS;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatusTypes::CREATED;
    }

    public function reserve(): BelongsTo
    {
        return $this->belongsTo(Reserve::class, 'reserve_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
