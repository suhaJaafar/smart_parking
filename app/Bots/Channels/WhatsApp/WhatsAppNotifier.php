<?php

namespace App\Bots\Channels\WhatsApp;

use App\Bots\Dto\OutboundReply;

/**
 * Thin alias around {@see WhatsAppTransport} kept for callers that
 * historically depended on a "notifier" concept (sending plain text to
 * a known WhatsApp phone, e.g. from {@see \App\Http\Controllers\AuthController}
 * for OTP delivery).
 *
 * New code should depend on {@see \App\Bots\Contracts\BotTransport} or
 * {@see \App\Bots\Contracts\BotNotifier} instead.
 */
class WhatsAppNotifier
{
    public function __construct(
        private readonly WhatsAppTransport $transport,
    ) {}

    /**
     * Send a plain-text WhatsApp message. Best-effort: failures are
     * logged inside the transport and never thrown.
     */
    public function send(string $to, string $message): void
    {
        $this->transport->sendTo($to, OutboundReply::text($message));
    }
}
