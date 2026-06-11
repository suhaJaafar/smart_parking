<?php

namespace App\Bots\Contracts;

use App\Bots\Dto\OutboundReply;
use App\Models\User;

/**
 * User-level outbound notifier — channel-agnostic.
 *
 * Where {@see BotTransport} sends to one specific channel address, a
 * {@see BotNotifier} takes a domain {@see User} and fans the reply out
 * across every channel that user is enrolled in (phone_number for
 * WhatsApp, telegram_chat_id for Telegram, …).
 *
 * Application code (flows, services like PaymentService) should depend on
 * this interface so it never has to care which messaging app the user
 * happens to be on. The default implementation lives in
 * {@see \App\Bots\Support\UserNotifier} and is bound in
 * {@see \App\Providers\AppServiceProvider}.
 */
interface BotNotifier
{
    /**
     * Deliver a reply to the given user on every channel they are
     * reachable on. Implementations should swallow per-channel failures
     * so a transient outage on one transport does not break the others.
     */
    public function notify(User $user, OutboundReply $reply): void;
}
