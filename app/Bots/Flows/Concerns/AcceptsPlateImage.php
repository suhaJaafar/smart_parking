<?php

namespace App\Bots\Flows\Concerns;

use App\Bots\Engine\ConversationEngine;
use Illuminate\Support\Str;

/**
 * Helpers for flow steps that accept a plate picture as an alternative to
 * typed text. The channel parser tags an inbound image by prefixing its
 * download URL with {@see ConversationEngine::IMAGE_PAYLOAD_PREFIX}; these
 * helpers detect and unwrap that marker.
 */
trait AcceptsPlateImage
{
    /** True when the message carries an inbound image rather than text. */
    private function isImagePayload(string $message): bool
    {
        return str_starts_with(trim($message), ConversationEngine::IMAGE_PAYLOAD_PREFIX);
    }

    /** The downloadable image URL carried by an image payload (may be empty). */
    private function imageUrl(string $message): string
    {
        return Str::after(trim($message), ConversationEngine::IMAGE_PAYLOAD_PREFIX);
    }
}
