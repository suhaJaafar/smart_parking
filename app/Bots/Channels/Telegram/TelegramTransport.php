<?php

namespace App\Bots\Channels\Telegram;

use App\Bots\Contracts\BotSession;
use App\Bots\Contracts\BotTransport;
use App\Bots\Dto\OutboundReply;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram implementation of {@see BotTransport}.
 *
 * Talks to the Telegram Bot API "sendMessage" endpoint. Translates a
 * generic {@see OutboundReply}:
 *   - text     → plain sendMessage (Markdown parse_mode)
 *   - cta_url  → sendMessage with an `inline_keyboard` URL button
 *
 * Telegram has no signature header for outbound — auth is the bot token
 * embedded in the URL.
 */
class TelegramTransport implements BotTransport
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

    private function sendText(string $chatId, string $body): void
    {
        $this->dispatch('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $body,
            'parse_mode' => 'Markdown',
        ]);
    }

    private function sendCtaUrl(string $chatId, string $body, string $ctaText, string $url): void
    {
        $ok = $this->dispatch('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $body,
            'parse_mode' => 'Markdown',
            'reply_markup' => [
                'inline_keyboard' => [[
                    ['text' => mb_substr($ctaText, 0, 64), 'url' => $url],
                ]],
            ],
        ]);

        if (!$ok) {
            // Fallback to plain text so the user still sees something useful.
            $this->sendText($chatId, $body . "\n\n" . $url);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $method, array $payload): bool
    {
        $token = config('services.telegram.bot_token');
        $base  = config('services.telegram.api_base_url', 'https://api.telegram.org');

        if (!$token) {
            Log::warning('TelegramTransport: missing bot token, skipping send.', [
                'chat_id' => $payload['chat_id'] ?? null,
            ]);
            return false;
        }

        try {
            $response = Http::asJson()->post("{$base}/bot{$token}/{$method}", $payload);

            if ($response->failed() || $response->json('ok') !== true) {
                Log::error('TelegramTransport send failed', [
                    'method' => $method,
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('TelegramTransport exception', [
                'method' => $method,
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }
}
