<?php

namespace App\Bots\Channels\Telegram;

use App\Bots\Dto\OutboundReply;

/**
 * Symmetric counterpart to {@see \App\Bots\Channels\WhatsApp\WhatsAppNotifier}.
 *
 * New code should prefer {@see \App\Bots\Contracts\BotNotifier} which
 * fans out across every channel the user is enrolled in. This thin shim
 * exists for places that explicitly need to reach a known Telegram chat.
 */
class TelegramNotifier
{
    public function __construct(
        private readonly TelegramTransport $transport,
    ) {}

    public function send(string $chatId, string $message): void
    {
        $this->transport->sendTo($chatId, OutboundReply::text($message));
    }
}
