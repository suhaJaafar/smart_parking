<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Park extends Model
{
    use HasUuids;

    /** A garage is only reservable once an admin has cleared it. */
    public const APPROVAL_PENDING  = 'pending';
    public const APPROVAL_APPROVED = 'approved';
    public const APPROVAL_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'location_id',
        'name',
        'capacity',
        'free_spaces',
        'price',
        'approval_status',
        'approved_by',
        'approved_at',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'capacity'    => 'integer',
            'free_spaces' => 'integer',
            'price'       => 'decimal:3',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * Garages the public may see and book.
     *
     * @param  Builder<Park>  $query
     * @return Builder<Park>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('approval_status', self::APPROVAL_APPROVED);
    }

    public function isApproved(): bool
    {
        return $this->approval_status === self::APPROVAL_APPROVED;
    }

    public function isPendingApproval(): bool
    {
        return $this->approval_status === self::APPROVAL_PENDING;
    }

    /* ---------------------------------------------------------------------
     | Relations
     * --------------------------------------------------------------------- */

    /**
     * Each park has one location.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    /**
     * Each park has a user space owner
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** The admin who cleared (or refused) this garage. */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Reserves made for this park.
     */
    public function reserves(): HasMany
    {
        return $this->hasMany(Reserve::class);
    }
}

