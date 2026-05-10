<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outbound WhatsApp text-message sender.
 *
 * The webhook controller used to be the only place that talked to the Meta
 * Graph API. Background notifications (e.g. notifying a customer that the
 * space owner just registered their car) need the same capability without
 * dragging the controller into the dependency graph.
 *
 * Keep this class deliberately narrow: just plain text messages. Richer
 * payloads (interactive, cta_url) live in the controller until a flow
 * actually needs them.
 */
class WhatsAppNotifier
{
    /**
     * Send a plain-text WhatsApp message. Failures are logged, never thrown,
     * because notifications must not break the primary flow that triggered them.
     */
    public function send(string $to, string $message): void
    {
        if ($to === '' || trim($message) === '') {
            return;
        }

        $phoneNumberId = config('services.whatsapp.phone_number_id');
        $accessToken   = config('services.whatsapp.access_token');
        $apiVersion    = config('services.whatsapp.api_version', 'v18.0');

        if (!$phoneNumberId || !$accessToken) {
            Log::warning('WhatsAppNotifier: missing credentials, skipping send.');
            return;
        }

        try {
            $response = Http::withToken($accessToken)
                ->post("https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'                => $to,
                    'type'              => 'text',
                    'text'              => ['body' => $message],
                ]);

            if ($response->failed()) {
                Log::error('WhatsAppNotifier send failed', [
                    'to'   => $to,
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('WhatsAppNotifier exception', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
