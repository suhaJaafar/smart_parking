<?php

namespace App\Bots\Support;

use App\Bots\Channels\Telegram\TelegramTransport;
use App\Bots\Channels\WhatsApp\WhatsAppTransport;
use App\Bots\Contracts\BotNotifier;
use App\Bots\Dto\OutboundReply;
use App\Models\User;

/**
 * Default {@see BotNotifier} implementation.
 *
 * Looks at which channels the user has enrolled in (a non-null
 * `phone_number` means WhatsApp, a non-null `telegram_chat_id` means
 * Telegram) and forwards the reply to every reachable channel.
 *
 * If a user is on both channels, both get the same notification — by
 * design, since we don't know which one they're currently looking at.
 *
 * Both transports already swallow their own per-call failures so a
 * Telegram outage cannot prevent a WhatsApp delivery and vice-versa.
 */
class UserNotifier implements BotNotifier
{
    public function __construct(
        private readonly WhatsAppTransport $whatsapp,
        private readonly TelegramTransport $telegram,
    ) {}

    public function notify(User $user, OutboundReply $reply): void
    {
        if ($reply->isEmpty()) {
            return;
        }

        if (!empty($user->phone_number)) {
            $this->whatsapp->sendTo((string) $user->phone_number, $reply);
        }

        if (!empty($user->telegram_chat_id)) {
            $this->telegram->sendTo((string) $user->telegram_chat_id, $reply);
        }
    }
}
