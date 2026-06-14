<?php

namespace App\Data;

/**
 * Typed payload for creating a {@see \App\Models\Park} row.
 *
 * Mirrors {@see LocationData} in style. `freeSpaces` defaults to `capacity`
 * when omitted, so callers can pass just the name + capacity for the
 * common "park opens with all spaces free" case.
 */
final class ParkData
{
    public function __construct(
        public readonly string $name,
        public readonly int $capacity,
        public readonly ?int $freeSpaces = null,
    ) {}

    /**
     * Build from validated HTTP input (FormRequest::safe()->only(...)).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name:       $data['name'],
            capacity:   (int) $data['capacity'],
            freeSpaces: isset($data['free_spaces']) ? (int) $data['free_spaces'] : null,
        );
    }

    /**
     * Snake-cased array suitable for `ParkRepository::create()`.
     * `freeSpaces` is materialised to `capacity` when null so the parks
     * table always gets a concrete value.
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'capacity'    => $this->capacity,
            'free_spaces' => $this->freeSpaces ?? $this->capacity,
        ];
    }
}
