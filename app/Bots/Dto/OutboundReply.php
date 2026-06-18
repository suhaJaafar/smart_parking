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
 *   - WhatsApp buttons       → interactive reply buttons (≤3) or list (>3)
 *   - Telegram text          → sendMessage
 *   - Telegram cta_url       → sendMessage + inline_keyboard with a URL button
 *   - Telegram buttons       → sendMessage + inline_keyboard with callback buttons
 *
 * EMPTY replies are a no-op — used when a handler wants to consume input
 * without sending anything back (e.g. silently ignore an unknown event).
 */
final class OutboundReply
{
    public const TYPE_TEXT    = 'text';
    public const TYPE_CTA_URL = 'cta_url';
    public const TYPE_BUTTONS = 'buttons';
    public const TYPE_EMPTY   = 'empty';

    /**
     * @param array<int, array{id: string, title: string, description?: string}> $options
     */
    private function __construct(
        public readonly string  $type,
        public readonly string  $body       = '',
        public readonly ?string $ctaText    = null,
        public readonly ?string $url        = null,
        public readonly array   $options    = [],
        public readonly ?string $listButton = null,
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

    /**
     * A prompt the user answers by tapping a choice rather than typing.
     *
     * Each option carries an `id` (the payload echoed back as inbound text
     * when tapped) and a human-readable `title`; an optional `description`
     * is shown only where the channel supports it (WhatsApp list rows).
     *
     * Transports decide the concrete widget: WhatsApp uses reply buttons
     * for ≤3 options and an interactive list beyond that; Telegram always
     * uses an inline keyboard.
     *
     * @param array<int, array{id: string, title: string, description?: string}> $options
     * @param ?string $listButton Label for the WhatsApp list "open" button.
     */
    public static function buttons(string $body, array $options, ?string $listButton = null): self
    {
        if ($body === '') {
            throw new InvalidArgumentException('buttons reply requires a body.');
        }

        $clean = [];
        foreach ($options as $option) {
            $id    = (string) ($option['id'] ?? '');
            $title = (string) ($option['title'] ?? '');
            if ($id === '' || $title === '') {
                continue;
            }
            $entry = ['id' => $id, 'title' => $title];
            if (!empty($option['description'])) {
                $entry['description'] = (string) $option['description'];
            }
            $clean[] = $entry;
        }

        if ($clean === []) {
            throw new InvalidArgumentException('buttons reply requires at least one option.');
        }

        return new self(
            type: self::TYPE_BUTTONS,
            body: $body,
            options: $clean,
            listButton: $listButton,
        );
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
