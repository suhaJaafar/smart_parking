<?php

namespace App\Data;

use App\Bots\Support\DigitNormalizer;

/**
 * Iraqi vehicle plate as a typed value object.
 *
 * A plate has two components that always travel together:
 *   - $prefix: 1–8 letters identifying the issuing province / category
 *              (e.g. "BG" = Baghdad). Stored UPPERCASE.
 *   - $number: 1–20 digits of the plate body.
 *
 * Passing them around as a single value (instead of two loose strings)
 * eliminates the whole "did I pass them in the right order?" class of
 * bug and gives the IDE something to autocomplete.
 *
 * Factories:
 *   - {@see self::fromString()} parses free-form user input like
 *     "BG-12345", "BG 12345", "bg12345" → a CarPlate, or null when
 *     the input doesn't look like a plate at all.
 */
final class CarPlate
{
    public function __construct(
        public readonly string $prefix,
        public readonly string $number,
    ) {}

    /**
     * Parse a user-typed plate. Accepts a separator of dash, space, or
     * nothing. Returns null when the input is not a valid plate shape
     * so callers can render a friendly retry prompt.
     */
    public static function fromString(string $input): ?self
    {
        $normalized = mb_strtoupper(trim(DigitNormalizer::toAscii($input)));

        // Prefix: 1–8 English/Arabic letters or digits (lazy, so it never
        //         swallows the separator or the trailing number).
        // Separator: an optional single dash or space.
        // Number: the trailing run of 1–20 ASCII digits.
        if (!preg_match('/^([A-Z0-9\x{0621}-\x{064A}]{1,8}?)[\s\-]?([0-9]{1,20})$/u', $normalized, $m)) {
            return null;
        }

        return new self(prefix: $m[1], number: $m[2]);
    }

    /**
     * Canonical "PREFIX-NUMBER" form for display to humans.
     */
    public function __toString(): string
    {
        return "{$this->prefix}-{$this->number}";
    }
}
