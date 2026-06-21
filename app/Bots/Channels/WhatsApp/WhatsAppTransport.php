<?php

namespace App\Bots\Channels\WhatsApp;

use App\Bots\Contracts\BotSession;
use App\Bots\Contracts\BotTransport;
use App\Bots\Dto\OutboundReply;
use App\Bots\Support\BidiText;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp implementation of {@see BotTransport}.
 *
 * Wraps the Meta Graph API "messages" endpoint. Knows how to translate
 * a generic {@see OutboundReply} into either a text payload or an
 * interactive `cta_url` button.
 *
 * Failures are logged, never thrown — outbound delivery must not break
 * the inbound webhook response or its caller's primary flow.
 */
class WhatsAppTransport implements BotTransport
{
    public function send(BotSession $session, OutboundReply $reply): void
    {
        $this->sendTo($session->getRecipient(), $reply);
    }

    public function sendTo(string $recipient, OutboundReply $reply): void
    {
        if ($recipient === '' || $reply->isEmpty()) {
            return;
        }

        match ($reply->type) {
            OutboundReply::TYPE_TEXT    => $this->sendText($recipient, $reply->body),
            OutboundReply::TYPE_CTA_URL => $this->sendCtaUrl($recipient, $reply->body, $reply->ctaText ?? '', $reply->url ?? ''),
            OutboundReply::TYPE_BUTTONS => $this->sendButtons($recipient, $reply->body, $reply->options, $reply->listButton, $reply->linkButton),
            default                     => null,
        };
    }

    private function sendText(string $to, string $body): void
    {
        $this->dispatch($to, [
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    /**
     * Interactive "Call to Action" message — one tappable button that
     * opens an external URL. Body text appears above the button.
     *
     * Meta limits: display_text ≤ 20 chars, body text ≤ 1024 chars.
     */
    private function sendCtaUrl(string $to, string $body, string $ctaText, string $url): void
    {
        $ok = $this->dispatch($to, [
            'type'        => 'interactive',
            'interactive' => [
                'type'   => 'cta_url',
                'body'   => ['text' => mb_substr($body, 0, 1024)],
                'action' => [
                    'name'       => 'cta_url',
                    'parameters' => [
                        'display_text' => mb_substr($ctaText, 0, 20),
                        'url'          => $url,
                    ],
                ],
            ],
        ]);

        // Fallback to plain text so the user still sees something useful.
        if (!$ok) {
            $this->sendText($to, $body . "\n\n" . $url);
        }
    }

    /**
     * A tap-to-choose prompt. WhatsApp offers two native widgets and we
     * pick based on option count:
     *   - ≤ 3 options → interactive *reply buttons*
     *   - > 3 options → interactive *list* (up to 10 rows)
     *
     * The chosen option's `id` is echoed back by WhatsApp as
     * `button_reply.id` / `list_reply.id`, which the inbound parser maps
     * to ordinary text for the flow to interpret.
     *
     * Meta limits: reply-button title ≤ 20 chars, list-row title ≤ 24,
     * list-row description ≤ 72, list/section labels ≤ 24, body ≤ 1024.
     *
     * @param array<int, array{id: string, title: string, description?: string}> $options
     * @param array{title: string, url: string}|null $linkButton A URL link.
     *        WhatsApp lists/buttons can't host a URL button alongside the
     *        choices, so it's appended to the body text instead.
     */
    private function sendButtons(string $to, string $body, array $options, ?string $listButton, ?array $linkButton = null): void
    {
        $options = array_values($options);

        if ($linkButton !== null) {
            $body = $body . "\n\n" . $linkButton['title'] . ":\n" . $linkButton['url'];
        }

        if ($options === []) {
            $this->sendText($to, $body);
            return;
        }

        if (count($options) <= 3) {
            $buttons = array_map(static fn (array $o): array => [
                'type'  => 'reply',
                'reply' => [
                    'id'    => mb_substr($o['id'], 0, 256),
                    'title' => mb_substr($o['title'], 0, 20),
                ],
            ], $options);

            $ok = $this->dispatch($to, [
                'type'        => 'interactive',
                'interactive' => [
                    'type'   => 'button',
                    'body'   => ['text' => mb_substr($body, 0, 1024)],
                    'action' => ['buttons' => $buttons],
                ],
            ]);
        } else {
            $rows = array_map(static function (array $o): array {
                $row = [
                    'id'    => mb_substr($o['id'], 0, 200),
                    'title' => mb_substr($o['title'], 0, 24),
                ];
                if (!empty($o['description'])) {
                    $row['description'] = mb_substr($o['description'], 0, 72);
                }
                return $row;
            }, array_slice($options, 0, 10));

            $ok = $this->dispatch($to, [
                'type'        => 'interactive',
                'interactive' => [
                    'type'   => 'list',
                    'body'   => ['text' => mb_substr($body, 0, 1024)],
                    'action' => [
                        'button'   => mb_substr($listButton ?: 'اختر', 0, 20),
                        'sections' => [[
                            'title' => 'الخيارات',
                            'rows'  => $rows,
                        ]],
                    ],
                ],
            ]);
        }

        if (!$ok) {
            $this->sendText($to, $this->optionsAsText($body, $options));
        }
    }

    /**
     * Plain-text fallback when an interactive send fails: the prompt body
     * followed by each option's title on its own line.
     *
     * @param array<int, array{id: string, title: string, description?: string}> $options
     */
    private function optionsAsText(string $body, array $options): string
    {
        $lines = [$body, ''];
        foreach (array_values($options) as $o) {
            $lines[] = '• ' . $o['title'];
        }

        return implode("\n", $lines);
    }

    /**
     * Pin every outbound message body to a right-to-left layout, regardless
     * of the interactive widget it travels in.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function forceRtl(array $payload): array
    {
        if (isset($payload['text']['body']) && is_string($payload['text']['body'])) {
            $payload['text']['body'] = BidiText::rtl($this->flattenLinks($payload['text']['body']));
        }
        if (isset($payload['interactive']['body']['text']) && is_string($payload['interactive']['body']['text'])) {
            $payload['interactive']['body']['text'] = BidiText::rtl($this->flattenLinks($payload['interactive']['body']['text']));
        }

        return $payload;
    }

    /**
     * WhatsApp text cannot mask a URL behind a label the way Telegram's
     * Markdown can, so a `[label](url)` link would render literally. Flatten
     * any such links to "label: url" — the bare URL stays auto-clickable.
     */
    private function flattenLinks(string $text): string
    {
        return preg_replace(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/u',
            '$1: $2',
            $text,
        ) ?? $text;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $to, array $payload): bool
    {
        $payload = $this->forceRtl($payload);

        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken   = config('services.whatsapp.access_token');
        $apiVersion    = config('services.whatsapp.api_version', 'v18.0');

        if (!$phoneNumberId || !$accessToken) {
            Log::warning('WhatsAppTransport: missing credentials, skipping send.', ['to' => $to]);
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->post(
                    "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages",
                    array_merge(
                        ['messaging_product' => 'whatsapp', 'to' => $to],
                        $payload,
                    ),
                );

            if ($response->failed()) {
                Log::error('WhatsAppTransport send failed', [
                    'to'   => $to,
                    'body' => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('WhatsAppTransport exception', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
