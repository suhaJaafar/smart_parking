<?php

namespace App\Bots\Channels\Telegram;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies inbound Telegram webhook requests using the
 * `X-Telegram-Bot-Api-Secret-Token` header.
 *
 * When you set the webhook via `setWebhook` with a `secret_token`,
 * Telegram echoes that token back in every webhook call. Anyone POSTing
 * to your webhook URL without it is rejected.
 *
 * This is the only practical way to authenticate Telegram webhooks —
 * there is no payload-level signature analogous to Meta's
 * `X-Hub-Signature-256`.
 */
class VerifyTelegramSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('services.telegram.webhook_secret');

        // Fail closed: refuse all webhooks if no secret is configured
        // rather than silently accept unverified traffic.
        if (empty($expected)) {
            Log::warning('Telegram webhook rejected: webhook_secret is not configured.');
            return response('Server misconfigured', 500);
        }

        $provided = $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (!is_string($provided) || !hash_equals((string) $expected, $provided)) {
            Log::warning('Telegram webhook rejected: secret token mismatch.', [
                'ip'         => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return response('Invalid signature', 403);
        }

        return $next($request);
    }
}
