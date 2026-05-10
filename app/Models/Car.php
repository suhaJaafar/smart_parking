<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Car extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'model',
        'car_number',
        'plate_prefix',
        'park_id',
    ];

    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
