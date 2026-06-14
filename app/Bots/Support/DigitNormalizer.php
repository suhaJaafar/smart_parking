<?php

namespace App\Bots\Support;

/**
 * Translate Eastern-Arabic and Persian digits to ASCII 0–9.
 *
 * Bot users routinely type numbers using their keyboard's native digit
 * set (٠١٢٣٤٥٦٧٨٩ on Arabic keyboards, ۰۱۲۳۴۵۶۷۸۹ on Persian). PHP's
 * `ctype_digit`, `(int)` casts, and `\d` regex all treat these as
 * non-digits, so a raw input like "٥" silently parses as 0 or fails
 * validation. Calling {@see self::toAscii()} at the very edge of each
 * input parser (plate, capacity, coords, menu choice, phone) lets every
 * downstream check stay simple and ASCII-only.
 *
 * Use at the boundary, once — never in the middle of business logic.
 */
final class DigitNormalizer
{
    /** @var array<string, string> */
    private const MAP = [
        // Arabic-Indic (U+0660..U+0669)
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        // Extended Arabic-Indic / Persian (U+06F0..U+06F9)
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /**
     * Return $input with any Arabic-Indic or Persian digit replaced by
     * its ASCII counterpart. All other characters are preserved as-is,
     * so this is safe to call on free-form text (plates, addresses).
     */
    public static function toAscii(string $input): string
    {
        return strtr($input, self::MAP);
    }
}
