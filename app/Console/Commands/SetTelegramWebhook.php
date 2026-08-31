<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Register (or inspect) the bot's webhook.
 *
 * Outbound messages work without this — the app calls Telegram directly. The
 * webhook is the *inbound* half: without it Telegram has nowhere to deliver
 * what people type, so the bot silently stops replying while notifications
 * keep arriving. That asymmetry makes a missing webhook look like a broken
 * bot, which is why this is a command rather than a remembered curl.
 *
 *     php artisan telegram:set-webhook            # register, using APP_URL
 *     php artisan telegram:set-webhook --show     # just report current state
 *     php artisan telegram:set-webhook --url=https://abc.ngrok-free.dev
 *     php artisan telegram:set-webhook --delete
 *
 * Re-run it whenever the public URL changes.
 */
class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:set-webhook
                            {--url= : Override the public base URL (defaults to APP_URL)}
                            {--show : Report the current webhook and exit}
                            {--delete : Remove the webhook instead of setting it}';

    protected $description = "Register the Telegram bot's webhook so inbound messages reach the app.";

    /** Must match the route in routes/api.php. */
    private const WEBHOOK_PATH = '/api/telegram/webhook';

    public function handle(): int
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            return $this->reportCurrent($token);
        }

        if ($this->option('delete')) {
            return $this->call_api($token, 'deleteWebhook', [], 'Webhook removed.');
        }

        $secret = (string) config('services.telegram.webhook_secret');

        // VerifyTelegramSecret fails closed, so registering without a secret
        // would produce a webhook that Telegram calls and the app always
        // rejects — the same silence, but harder to diagnose.
        if ($secret === '') {
            $this->error('TELEGRAM_WEBHOOK_SECRET is not set — the app would reject every webhook.');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');

        if (!str_starts_with($base, 'https://')) {
            $this->error("Webhook URL must be HTTPS. Got: {$base}");

            return self::FAILURE;
        }

        $url = $base . self::WEBHOOK_PATH;

        return $this->call_api(
            $token,
            'setWebhook',
            [
                'url'          => $url,
                'secret_token' => $secret,
                // Anything queued while the webhook was down is almost always
                // stale by the time it is registered again.
                'drop_pending_updates' => true,
            ],
            "Webhook set to {$url}",
        );
    }

    private function reportCurrent(string $token): int
    {
        $info = $this->request($token, 'getWebhookInfo');

        if ($info === null) {
            return self::FAILURE;
        }

        $result = (array) ($info['result'] ?? []);
        $url    = (string) ($result['url'] ?? '');

        $this->line('URL             : ' . ($url !== '' ? $url : '(none — the bot cannot receive messages)'));
        $this->line('Pending updates : ' . ($result['pending_update_count'] ?? 0));

        if (!empty($result['last_error_message'])) {
            $this->warn('Last error      : ' . $result['last_error_message']);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function call_api(string $token, string $method, array $payload, string $success): int
    {
        if ($this->request($token, $method, $payload) === null) {
            return self::FAILURE;
        }

        $this->info($success);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function request(string $token, string $method, array $payload = []): ?array
    {
        $base = rtrim((string) config('services.telegram.api_base_url'), '/');

        // The bot token sits in the URL, so an uncaught client exception would
        // print it in the stack trace. Catch and report without it.
        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post("{$base}/bot{$token}/{$method}", $payload);
        } catch (ConnectionException $e) {
            $this->error('Could not reach the Telegram API: ' . $this->redact($e->getMessage(), $token));

            return null;
        }

        if (!$response->successful() || $response->json('ok') !== true) {
            $this->error('Telegram rejected the request: ' . $this->redact($response->body(), $token));

            return null;
        }

        return $response->json();
    }

    private function redact(string $message, string $token): string
    {
        return $token === '' ? $message : str_replace($token, '<bot-token>', $message);
    }
}
