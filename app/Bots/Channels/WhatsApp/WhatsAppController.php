<?php

namespace App\Bots\Channels\WhatsApp;

use App\Bots\Channels\WhatsApp\WhatsAppInboundParser;
use App\Bots\Channels\WhatsApp\WhatsAppTransport;
use App\Bots\Engine\ConversationEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Thin WhatsApp webhook adapter.
 *
 * Responsibilities are minimal — all conversation logic lives in the
 * channel-agnostic {@see ConversationEngine}:
 *
 *   1. Verify the GET handshake from Meta.
 *   2. Parse inbound POSTs through {@see WhatsAppInboundParser}.
 *   3. Hand each normalised message to the engine.
 *   4. Push the resulting {@see \App\Bots\Dto\OutboundReply} out via
 *      {@see WhatsAppTransport}.
 *
 * Webhook payload signature is checked by the `whatsapp.signed` middleware.
 */
class WhatsAppController
{
    public function __construct(
        private readonly WhatsAppInboundParser $parser,
        private readonly WhatsAppTransport $transport,
        private readonly ConversationEngine $engine,
    ) {}

    /**
     * GET /webhook — Meta verification handshake.
     */
    public function verify(Request $request): Response
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token')) {
            Log::info('WhatsApp webhook verified.');
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * POST /webhook — inbound messages.
     */
    public function receive(Request $request): Response
    {
        Log::info('WhatsApp webhook received', ['raw' => $request->getContent()]);

        $body = json_decode($request->getContent(), true) ?? [];

        foreach ($this->parser->messages($body) as $msg) {
            $session = $this->parser->resolveSession($msg['from']);

            $reply = $this->engine->handle(
                $session,
                $msg['text'],
                $msg['type'],
            );

            $this->transport->send($session, $reply);
        }

        // Meta requires a 200 within ~5s regardless of processing result.
        return response('OK', 200);
    }
}
