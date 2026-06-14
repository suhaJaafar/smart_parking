<?php

namespace App\Data;

use App\Enums\CountryTypes;
use App\Enums\StateTypes;

/**
 * Typed payload for creating a {@see \App\Models\Location} row.
 *
 * Lives at the service boundary so callers (HTTP controllers, bot flows,
 * console commands, tests, …) all speak the same shape instead of passing
 * loose arrays around. The repository layer keeps accepting arrays — this
 * DTO converts on the way down via {@see self::toArray()}.
 */
final class LocationData
{
    public function __construct(
        public readonly CountryTypes $country,
        public readonly StateTypes $state,
        public readonly ?string $city,
        public readonly ?string $postalCode,
        public readonly float $latitude,
        public readonly float $longitude,
        public readonly ?string $extraDetails = null,
    ) {}

    /**
     * Build from validated HTTP input (FormRequest::safe()->only(...)).
     *
     * Accepts both the enum's *value* (when it came over the wire) and an
     * already-cast enum instance, so it's safe to call from anywhere.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            country:      self::toCountry($data['country']),
            state:        self::toState($data['state']),
            city:         $data['city']          ?? null,
            postalCode:   $data['postal_code']   ?? null,
            latitude:     (float) $data['latitude'],
            longitude:    (float) $data['longitude'],
            extraDetails: $data['extra_details'] ?? null,
        );
    }

    /**
     * Snake-cased array suitable for `LocationRepository::create()`.
     */
    public function toArray(): array
    {
        return [
            'country'       => $this->country->value,
            'state'         => $this->state->value,
            'city'          => $this->city,
            'postal_code'   => $this->postalCode,
            'latitude'      => $this->latitude,
            'longitude'     => $this->longitude,
            'extra_details' => $this->extraDetails,
        ];
    }

    private static function toCountry(CountryTypes|string|int $value): CountryTypes
    {
        return $value instanceof CountryTypes ? $value : CountryTypes::from($value);
    }

    private static function toState(StateTypes|string|int $value): StateTypes
    {
        return $value instanceof StateTypes ? $value : StateTypes::from($value);
    }
}
