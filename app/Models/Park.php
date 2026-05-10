<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Park extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_id',
        'location_id',
        'name',
        'capacity',
        'free_spaces',
    ];

    protected function casts(): array
    {
        return [
            'capacity'    => 'integer',
            'free_spaces' => 'integer',
        ];
    }

    /* ---------------------------------------------------------------------
     | Relations
     * --------------------------------------------------------------------- */

    /**
     * Each park stores its location_id locally — that's a BelongsTo, not HasOne.
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
     * The space owner (a user with the SPACE_OWNER role) who owns this park.
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}

