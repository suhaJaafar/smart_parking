<?php

namespace App\Bots\Channels\WhatsApp;

use App\Bots\Contracts\BotSession;
use App\Bots\Contracts\BotTransport;
use App\Bots\Dto\OutboundReply;
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
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $to, array $payload): bool
    {
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
