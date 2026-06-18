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
            OutboundReply::TYPE_BUTTONS => $this->sendButtons($recipient, $reply->body, $reply->options),
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
     * A tap-to-choose prompt rendered as a vertical inline keyboard. Each
     * option's `id` is sent back verbatim as `callback_data` when tapped,
     * which {@see \App\Bots\Channels\Telegram\TelegramInboundParser} turns
     * into ordinary inbound text for the flow to interpret.
     *
     * Telegram caps `callback_data` at 64 bytes.
     *
     * @param array<int, array{id: string, title: string, description?: string}> $options
     */
    private function sendButtons(string $chatId, string $body, array $options): void
    {
        $keyboard = array_map(static fn (array $o): array => [[
            'text'          => mb_substr($o['title'], 0, 64),
            'callback_data' => mb_substr($o['id'], 0, 64),
        ]], array_values($options));

        $ok = $this->dispatch('sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $body,
            'parse_mode'   => 'Markdown',
            'reply_markup' => ['inline_keyboard' => $keyboard],
        ]);

        if (!$ok) {
            $this->sendText($chatId, $this->optionsAsText($body, $options));
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
