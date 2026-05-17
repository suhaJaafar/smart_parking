<?php

namespace App\Models;

use App\Enums\CountryTypes;
use App\Enums\StateTypes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Location extends Model
{
    use HasUuids;

    // NOTE: `latitude` and `longitude` are read-only computed accessors backed by
    // the PostGIS `coordinates` column — they cannot be mass-assigned. Writes
    // must go through the `coordinates` mutator (see LocationRepository).
    protected $fillable = [
        'country',
        'city',
        'postal_code',
        'state',
        'extra_details',
    ];

    protected function casts(): array
    {
        return [
            'country'   => CountryTypes::class,
            'state'     => StateTypes::class,
            'latitude'  => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Each location has one user.
     */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /**
     * Each location has one park.
     */
    public function park(): HasOne
    {
        return $this->hasOne(Park::class);
    }

    public function coordinates(): Attribute
    {
        return Attribute::set(function (array $value) {
            // Build a geography(POINT, 4326) value. PostGIS expects (longitude, latitude).
            return DB::raw(sprintf(
                "ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography",
                (float) $value['long'],
                (float) $value['lat'],
            ));
        });
    }

    public function longitude(): Attribute
    {
        return Attribute::get(function () {
            return (float) static::query()
                ->whereKey($this->getKey())
                ->selectRaw('ST_X(coordinates::geometry) as longitude')
                ->toBase()
                ->soleValue('longitude');
        })->shouldCache();
    }

    public function latitude(): Attribute
    {
        return Attribute::get(function () {
            return (float) static::query()
                ->whereKey($this->getKey())
                ->selectRaw('ST_Y(coordinates::geometry) as latitude')
                ->toBase()
                ->soleValue('latitude');
        })->shouldCache();
    }
}
