<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use App\Enums\PaymentStatusTypes;

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
            'status' => PaymentStatusTypes::class,
            'amount' => 'decimal:3',
        ];
    }

    // helper method isPaid ?
    public function isPaid(): bool
    {
        return $this->status === PaymentStatusTypes::SUCCESS;
    }

    // helper method isPending?
    public function isPending(): bool
    {
        return $this->status === PaymentStatusTypes::CREATED;
    }

    /* ---------------------------------------------------------------------
     | Relations
     * --------------------------------------------------------------------- */

     /**
      * Each payment belongs to a reservation.
      */
    public function reserve()
    {
        return $this->belongsTo(Reserve::class, 'reserve_id');
    }

    /**
     * Each payment belongs to a user.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

}
