<?php

namespace App\Bots\Dto;

use InvalidArgumentException;

/**
 * Channel-agnostic outbound message envelope.
 *
 * Flows return one of these instead of channel-specific payloads. Each
 * channel's transport translates it into the right wire format:
 *   - WhatsApp text          → POST text message
 *   - WhatsApp cta_url       → POST interactive cta_url
 *   - Telegram text          → sendMessage
 *   - Telegram cta_url       → sendMessage + inline_keyboard with a URL button
 *
 * EMPTY replies are a no-op — used when a handler wants to consume input
 * without sending anything back (e.g. silently ignore an unknown event).
 */
final class OutboundReply
{
    public const TYPE_TEXT    = 'text';
    public const TYPE_CTA_URL = 'cta_url';
    public const TYPE_EMPTY   = 'empty';

    private function __construct(
        public readonly string  $type,
        public readonly string  $body    = '',
        public readonly ?string $ctaText = null,
        public readonly ?string $url     = null,
    ) {}

    public static function text(string $body): self
    {
        return new self(self::TYPE_TEXT, $body);
    }

    public static function ctaUrl(string $body, string $ctaText, string $url): self
    {
        if ($body === '' || $ctaText === '' || $url === '') {
            throw new InvalidArgumentException('cta_url reply requires body, ctaText and url.');
        }
        return new self(self::TYPE_CTA_URL, $body, $ctaText, $url);
    }

    public static function empty(): self
    {
        return new self(self::TYPE_EMPTY);
    }

    public function isEmpty(): bool
    {
        return $this->type === self::TYPE_EMPTY || trim($this->body) === '' && $this->type === self::TYPE_TEXT;
    }
}
