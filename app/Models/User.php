<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    // Source code for some of this class (without AI) : "https://rifatcse09.medium.com/understanding-the-differences-and-uses-of-sanctum-passport-and-jwt-4186af0949aa"

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, HasApiTokens;

    /**
     * Get the Attributes that should be mass assignable.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone_number',
        'location_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * Get the identifier that will be stored in the subject claim of the JWT.
     *
     * @return mixed
     */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /**
     * Return a key value array, containing any custom claims to be added to the JWT.
     *
     * @return array
     */
    public function getJWTCustomClaims()
    {
        return [];
    }

    /**
     * Get the location associated with the user.
     * users.location_id is the FK, so this is BelongsTo (not HasOne).
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }


    /**
     * All cars owned by the user (one user → many cars).
     */
    public function cars(): HasMany
    {
        return $this->hasMany(Car::class);
    }

    /**
     * The roles that belong to the user.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    /**
     * Parks owned by this user (only relevant for SPACE_OWNER role).
     */
    public function ownedParks(): HasMany
    {
        return $this->hasMany(Park::class);
    }

    /**
    * Reserves made by this user (only relevant for CUSTOMER role).
    */
    public function reserves(): HasMany
    {
        return $this->hasMany(Reserve::class);
    }
}
