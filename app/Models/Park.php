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
        'user_id',
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

    /**
     * Reserves made for this park.
     */
    public function reserves(): HasMany
    {
        return $this->hasMany(Reserve::class);
    }
}

