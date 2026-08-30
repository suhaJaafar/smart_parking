<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Point the bot's persistent menu button at the Telegram Mini App.
 *
 * This is the least invasive way to publish the Mini App: the button lives
 * next to the message input in every chat with the bot, so no conversation
 * flow has to change and nothing about the existing chat UX moves.
 *
 * Run once per environment (and again whenever the public URL changes):
 *
 *     php artisan telegram:set-menu-button
 *     php artisan telegram:set-menu-button --label="افتح التطبيق"
 *     php artisan telegram:set-menu-button --reset
 *
 * Telegram requires the Mini App to be served over HTTPS, so a local run
 * needs a tunnel (ngrok, Cloudflare Tunnel) in TELEGRAM_MINI_APP_URL.
 */
class SetTelegramMenuButton extends Command
{
    protected $signature = 'telegram:set-menu-button
                            {--label= : Text shown on the menu button}
                            {--url= : Override the configured Mini App URL}
                            {--reset : Restore the default "commands" menu button}';

    protected $description = "Set the Telegram bot's menu button to open the Smart Parking Mini App.";

    public function handle(): int
    {
        $token = (string) config('services.telegram.bot_token');

        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not set.');

            return self::FAILURE;
        }

        $payload = $this->option('reset')
            ? ['menu_button' => ['type' => 'commands']]
            : $this->miniAppPayload();

        if ($payload === null) {
            return self::FAILURE;
        }

        $base = rtrim((string) config('services.telegram.api_base_url'), '/');

        // The bot token sits in the URL, so an uncaught client exception would
        // print it in the stack trace. Catch and report without the URL.
        try {
            $response = Http::asJson()
                ->timeout(15)
                ->post("{$base}/bot{$token}/setChatMenuButton", $payload);
        } catch (ConnectionException $e) {
            $this->error('Could not reach the Telegram API: ' . $this->redact($e->getMessage(), $token));

            return self::FAILURE;
        }

        if (!$response->successful() || $response->json('ok') !== true) {
            $this->error('Telegram rejected the request: ' . $this->redact($response->body(), $token));

            return self::FAILURE;
        }

        $this->info(
            $this->option('reset')
                ? 'Menu button reset to the default commands menu.'
                : 'Menu button now opens the Mini App.',
        );

        return self::SUCCESS;
    }

    /**
     * Build the `web_app` menu-button payload, or null when the URL is
     * missing or not HTTPS (Telegram silently refuses to open those).
     *
     * @return array<string, mixed>|null
     */
    private function miniAppPayload(): ?array
    {
        $url = (string) ($this->option('url') ?: config('services.telegram.mini_app_url'));

        if ($url === '') {
            $this->error('TELEGRAM_MINI_APP_URL is not set (or pass --url=).');

            return null;
        }

        if (!str_starts_with($url, 'https://')) {
            $this->error("Mini App URL must be HTTPS. Got: {$url}");

            return null;
        }

        $label = (string) ($this->option('label') ?: 'Smart Parking');

        return [
            'menu_button' => [
                'type'    => 'web_app',
                'text'    => mb_substr($label, 0, 64),
                'web_app' => ['url' => $url],
            ],
        ];
    }

    /** Strip the bot token out of anything destined for the console. */
    private function redact(string $message, string $token): string
    {
        return $token === '' ? $message : str_replace($token, '<bot-token>', $message);
    }
}
