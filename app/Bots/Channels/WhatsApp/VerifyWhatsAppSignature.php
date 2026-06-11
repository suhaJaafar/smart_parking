<?php

namespace App\Bots\Channels\WhatsApp;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies inbound WhatsApp webhook requests using the
 * `X-Hub-Signature-256` header.
 *
 * Meta signs the RAW request body with HMAC-SHA256 using your App Secret
 * and sends the result as `X-Hub-Signature-256: sha256=<hex>`. Missing,
 * malformed, or mismatched signatures are rejected so attackers can't
 * POST fake messages to your webhook URL.
 */
class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        // GET /webhook is the verification handshake — no signature sent.
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        $secret = config('services.whatsapp.app_secret');

        // Fail closed: refuse all webhooks if no secret is configured
        // rather than silently accept unverified traffic.
        if (empty($secret)) {
            Log::warning('WhatsApp webhook rejected: app_secret is not configured.');
            return response('Server misconfigured', 500);
        }

        $header = $request->header('X-Hub-Signature-256', '');

        if (!is_string($header) || !str_starts_with($header, 'sha256=')) {
            Log::warning('WhatsApp webhook rejected: missing or malformed signature header.', [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
                'header'     => $header,
            ]);
            return response('Invalid signature', 403);
        }

        $providedHex = substr($header, 7); // strip "sha256="
        $expectedHex = hash_hmac('sha256', $request->getContent(), $secret);

        // Constant-time comparison — prevents timing attacks.
        if (!hash_equals($expectedHex, $providedHex)) {
            Log::warning('WhatsApp webhook rejected: signature mismatch.', [
                'expected'  => $expectedHex,
                'provided'  => $providedHex,
                'body_sha1' => sha1($request->getContent()),
            ]);
            return response('Invalid signature', 403);
        }

        return $next($request);
    }
}
