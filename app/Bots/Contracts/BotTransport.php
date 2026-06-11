<?php

namespace App\Bots\Contracts;

use App\Bots\Contracts\BotSession;
use App\Bots\Dto\OutboundReply;

/**
 * Channel-level outbound transport — knows how to put a reply on the wire
 * for one specific channel (WhatsApp Graph API, Telegram Bot API, …).
 *
 * Controllers depend on this interface so they don't have to know whether
 * a reply will end up as an HTTP POST to Meta or to Telegram. The concrete
 * implementations live under each channel's namespace.
 */
interface BotTransport
{
    /**
     * Send a reply back to the same conversation the inbound message came
     * from. Recipient is derived from the session.
     */
    public function send(BotSession $session, OutboundReply $reply): void;

    /**
     * Send a reply to an explicit channel-native recipient address
     * (phone number for WhatsApp, chat_id for Telegram). Used for
     * cross-user notifications (owner gets DM when a customer reserves).
     */
    public function sendTo(string $recipient, OutboundReply $reply): void;
}
