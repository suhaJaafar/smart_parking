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

    /** ASCII 0–9 → Arabic-Indic ٠–٩, for rendering numbers back to the user. */
    private const ASCII_TO_ARABIC = [
        '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
        '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
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

    /**
     * Return $input with any ASCII digit replaced by its Arabic-Indic
     * counterpart. Use when rendering numbers inside Arabic UI text so the
     * digits match the surrounding script. All other characters are kept.
     */
    public static function toArabic(string $input): string
    {
        return strtr($input, self::ASCII_TO_ARABIC);
    }
}
