<?php

namespace App\Bots\Channels\Telegram;

use App\Bots\Channels\Telegram\TelegramInboundParser;
use App\Bots\Channels\Telegram\TelegramTransport;
use App\Bots\Engine\ConversationEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Thin Telegram webhook adapter.
 *
 * Mirror of {@see \App\Bots\Channels\WhatsApp\WhatsAppController}:
 *
 *   1. Parse the inbound Update through {@see TelegramInboundParser}.
 *   2. Hand it to the channel-agnostic {@see ConversationEngine}.
 *   3. Push the resulting {@see \App\Bots\Dto\OutboundReply} out via
 *      {@see TelegramTransport}.
 *
 * Telegram has no GET handshake; webhook authenticity is enforced by
 * the `telegram.signed` middleware
 * ({@see VerifyTelegramSecret}).
 */
class TelegramController
{
    public function __construct(
        private readonly TelegramInboundParser $parser,
        private readonly TelegramTransport $transport,
        private readonly ConversationEngine $engine,
    ) {}

    /**
     * POST /telegram/webhook — inbound update.
     */
    public function receive(Request $request): Response
    {
        Log::info('Telegram webhook received', ['raw' => $request->getContent()]);

        $update = $request->json()->all();
        $msg    = $this->parser->fromUpdate($update);

        if ($msg === null) {
            // Unhandled update type — ACK so Telegram doesn't retry.
            return response('OK', 200);
        }

        $session = $this->parser->resolveSession($msg['chat_id']);
        $session->setProfileName($msg['name'] ?? null);

        $reply = $this->engine->handle(
            $session,
            $msg['text'],
            $msg['type'],
        );

        $this->transport->send($session, $reply);

        return response('OK', 200);
    }
}
