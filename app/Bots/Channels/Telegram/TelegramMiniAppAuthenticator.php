<?php

namespace App\Bots\Channels\Telegram;

use App\Enums\RoleTypes;
use App\Models\Role;
use App\Models\TelegramAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Authenticates a Telegram Mini App session from its signed `initData`.
 *
 * Unlike {@see TelegramLoginService} — where the bot issues a 6-digit code the
 * user retypes in the dashboard — a Mini App receives a payload that Telegram
 * itself signed with our bot token. Verifying that signature *is* the login:
 * no code, no password, no round trip through the chat.
 *
 * Verification follows Telegram's published algorithm:
 *
 *   1. Drop `hash` — and ONLY `hash` — from the payload; sort the remaining
 *      `key=value` pairs by key and join them with "\n". Every other field
 *      counts, including `signature` on newer clients.
 *   2. secret = HMAC-SHA256(bot_token, key: "WebAppData")
 *   3. expected = HMAC-SHA256(data-check-string, key: secret)
 *   4. Compare `expected` with the supplied `hash` in constant time.
 *
 * A valid signature proves the payload came from Telegram and was not
 * tampered with. `auth_date` is additionally checked so a leaked payload
 * cannot be replayed indefinitely.
 */
class TelegramMiniAppAuthenticator
{
    /**
     * How old an `initData` payload may be and still be accepted.
     *
     * Telegram does not expire `initData` itself, so this is our own replay
     * window. It is deliberately generous — the payload is captured once when
     * the Mini App opens and a user may sit on the launch screen for a while —
     * but short enough that a payload lifted from a log is quickly useless.
     */
    public const MAX_AUTH_AGE_SECONDS = 86400; // 24 hours

    /** Telegram's fixed HMAC key derivation salt for Mini Apps. */
    private const SECRET_SALT = 'WebAppData';

    /**
     * Verify a raw `initData` query string and return its decoded fields.
     *
     * Returns null when the signature is absent, malformed, forged, or stale —
     * the caller must treat every null as an authentication failure and must
     * not distinguish between the reasons in its response.
     *
     * @return array<string, mixed>|null
     */
    public function verify(string $initData): ?array
    {
        $botToken = (string) config('services.telegram.bot_token');
        if ($botToken === '' || $initData === '') {
            return $this->reject('missing bot token or init_data');
        }

        parse_str($initData, $fields);

        $hash = $fields['hash'] ?? null;
        if (!is_string($hash) || $hash === '') {
            return $this->reject('payload carries no hash');
        }

        // ONLY `hash` is removed. Newer clients also send `signature` (for
        // Telegram's separate Ed25519 third-party check) and it *is* part of
        // the data-check-string — excluding it makes every payload from a
        // modern client fail verification.
        unset($fields['hash']);

        if (!hash_equals($this->expectedHash($fields, $botToken), $hash)) {
            // Almost always means the payload was signed by a *different* bot
            // than the one in TELEGRAM_BOT_TOKEN.
            return $this->reject('hash mismatch', [
                'fields'        => array_keys($fields),
                'token_hint'    => $this->tokenHint($botToken),
                'auth_date_age' => is_numeric($fields['auth_date'] ?? null)
                    ? time() - (int) $fields['auth_date']
                    : null,
            ]);
        }

        if (!$this->isFresh($fields['auth_date'] ?? null)) {
            return $this->reject('auth_date outside the accepted window', [
                'auth_date' => $fields['auth_date'] ?? null,
            ]);
        }

        return $this->decode($fields);
    }

    /**
     * Log why a payload was refused, then return null.
     *
     * Debug-level and reason-only: the caller still returns one generic error,
     * so this aids operators without giving a prober anything.
     *
     * @param  array<string, mixed>  $context
     */
    private function reject(string $reason, array $context = []): null
    {
        Log::debug("Mini App initData rejected: {$reason}", $context);

        return null;
    }

    /**
     * Non-reversible fingerprint of the configured token — enough to tell two
     * bots apart in a log without ever writing the secret itself.
     */
    private function tokenHint(string $token): string
    {
        // The numeric bot id before the colon is public (it appears in every
        // Bot API URL), so it is safe to surface; the secret half never is.
        $botId = strtok($token, ':') ?: '?';

        return $botId . ':' . substr(hash('sha256', $token), 0, 8);
    }

    /**
     * Resolve the Smart Parking account behind a verified Mini App payload,
     * provisioning one on first contact.
     *
     * Resolution order mirrors the rest of the Telegram surface: the shared
     * `telegram_accounts` table first (so a linked co-owner device lands on the
     * account it was attached to), then the legacy `users.telegram_chat_id`
     * column. Only when neither matches do we create a CUSTOMER account, using
     * the exact same shape the bot's onboarding produces so an account created
     * here is indistinguishable from one created in chat.
     *
     * @param  array<string, mixed>  $payload  Output of {@see verify()}.
     */
    public function resolveOrCreateUser(array $payload): ?User
    {
        $telegramUser = $payload['user'] ?? null;
        if (!is_array($telegramUser)) {
            return null;
        }

        $chatId = isset($telegramUser['id']) ? (string) $telegramUser['id'] : '';
        if ($chatId === '') {
            return null;
        }

        $existing = $this->findByChatId($chatId);
        if ($existing) {
            return $existing->load('roles');
        }

        return $this->provisionCustomer($chatId, $telegramUser);
    }

    /**
     * The account already linked to a Telegram chat, if any.
     */
    private function findByChatId(string $chatId): ?User
    {
        return TelegramAccount::with('user.roles')
            ->where('chat_id', $chatId)
            ->first()?->user
            ?? User::with('roles')
                ->where('telegram_chat_id', $chatId)
                ->first();
    }

    /**
     * Create a CUSTOMER account for a Telegram user who has never touched the
     * bot. Mirrors {@see \App\Bots\Flows\OnboardingFlow} field-for-field:
     * synthetic e-mail (the column is NOT NULL), an unusable password because
     * the channel *is* the credential, and a primary `telegram_accounts` row so
     * a second device can be linked later.
     *
     * @param  array<string, mixed>  $telegramUser
     */
    private function provisionCustomer(string $chatId, array $telegramUser): User
    {
        return DB::transaction(function () use ($chatId, $telegramUser) {
            $user = User::create([
                'name'             => $this->resolveDisplayName($telegramUser, $chatId),
                'email'            => "{$chatId}@telegram.parkiq.local",
                'password'         => Hash::make(Str::password(40)),
                'telegram_chat_id' => $chatId,
            ]);

            $user->telegramAccounts()->create([
                'chat_id'    => $chatId,
                'is_primary' => true,
            ]);

            $role = Role::firstOrCreate(['role' => RoleTypes::CUSTOMER->value]);
            $user->roles()->sync([$role->id]);

            return $user->load('roles');
        });
    }

    /**
     * Best-effort display name from the Telegram profile, falling back to a
     * readable placeholder built from the chat id tail.
     *
     * @param  array<string, mixed>  $telegramUser
     */
    private function resolveDisplayName(array $telegramUser, string $chatId): string
    {
        $name = trim(sprintf(
            '%s %s',
            is_string($telegramUser['first_name'] ?? null) ? $telegramUser['first_name'] : '',
            is_string($telegramUser['last_name'] ?? null) ? $telegramUser['last_name'] : '',
        ));

        if ($name !== '') {
            return Str::limit($name, 100, '');
        }

        $username = $telegramUser['username'] ?? null;
        if (is_string($username) && $username !== '') {
            return Str::limit($username, 100, '');
        }

        return 'مستخدم ' . Str::substr($chatId, -4);
    }

    /**
     * HMAC the sorted data-check-string with the bot-token-derived secret.
     *
     * @param  array<string, mixed>  $fields
     */
    private function expectedHash(array $fields, string $botToken): string
    {
        ksort($fields);

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . (is_string($value) ? $value : json_encode($value));
        }

        $secret = hash_hmac('sha256', $botToken, self::SECRET_SALT, true);

        return hash_hmac('sha256', implode("\n", $pairs), $secret);
    }

    /**
     * Reject payloads older than {@see MAX_AUTH_AGE_SECONDS}, and any payload
     * whose `auth_date` is missing or non-numeric.
     */
    private function isFresh(mixed $authDate): bool
    {
        if (!is_numeric($authDate)) {
            return false;
        }

        $age = time() - (int) $authDate;

        // A small negative age is tolerated for clock skew between our host
        // and Telegram; a far-future timestamp is treated as forged.
        return $age <= self::MAX_AUTH_AGE_SECONDS && $age >= -300;
    }

    /**
     * Expand the JSON-encoded members (`user`, `receiver`, `chat`) that
     * Telegram embeds as strings inside the query payload.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function decode(array $fields): array
    {
        foreach (['user', 'receiver', 'chat'] as $key) {
            if (isset($fields[$key]) && is_string($fields[$key])) {
                $decoded = json_decode($fields[$key], true);
                if (is_array($decoded)) {
                    $fields[$key] = $decoded;
                }
            }
        }

        return $fields;
    }
}
