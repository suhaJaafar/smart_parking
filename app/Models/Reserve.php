<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reserve extends Model
{
    use HasUuids;

    public const STATUS_START     = 1;
    public const STATUS_ACTIVE    = 2;
    public const STATUS_COMPLETED = 4;
    public const STATUS_EXPIRED   = 5;
    public const STATUS_CANCELLED = 7;

    protected $fillable = [
        'user_id',
        'park_id',
        'status',
        'expires_at',
        'booking_code',
        'is_pre_booking',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'     => 'datetime',
            'is_pre_booking' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /**
     * Payments associated with this reservation (only relevant for
     * ACTIVE / COMPLETED rows).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'reserve_id');
    }

    /**
     * Generate a short numeric booking code for a specific park.
     *
     * Uniqueness scope is ACTIVE reservation lifecycle (START/ACTIVE)
     * within the same park, which keeps the code customer-friendly.
     */
    public static function generateBookingCodeForPark(string $parkId): string
    {
        do {
            $code = (string) random_int(1000, 9999);
        } while (self::where('park_id', $parkId)
            ->where('booking_code', $code)
            ->whereIn('status', [self::STATUS_START, self::STATUS_ACTIVE])
            ->exists());

        return $code;
    }
}
