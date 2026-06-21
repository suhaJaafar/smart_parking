<?php

namespace App\Bots\Support;

/**
 * Pins outbound bot messages to a consistent right-to-left layout.
 *
 * Telegram and WhatsApp infer each line's text direction from its first
 * strong directional character. A line that begins with an emoji, a digit,
 * a Latin word or a URL is therefore laid out left-to-right, which looks
 * broken sitting next to Arabic copy. Prefixing every non-empty line with a
 * Right-to-Left Mark (U+200F) forces the whole message to read RTL while
 * staying invisible to the reader and harmless to Markdown parsing.
 */
final class BidiText
{
    /** Right-to-Left Mark (U+200F). */
    private const RLM = "\u{200F}";

    /**
     * Prefix each non-empty line with a Right-to-Left Mark so the message
     * renders right-to-left on every client. Idempotent: lines already
     * marked are left untouched.
     */
    public static function rtl(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        $lines = explode("\n", $text);
        foreach ($lines as $i => $line) {
            if ($line !== '' && !str_starts_with($line, self::RLM)) {
                $lines[$i] = self::RLM . $line;
            }
        }

        return implode("\n", $lines);
    }
}
