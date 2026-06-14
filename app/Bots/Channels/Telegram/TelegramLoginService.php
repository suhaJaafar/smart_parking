<?php

namespace App\Bots\Channels\Telegram;

use App\Enums\RoleTypes;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * NOTE: This code file written by AI
 * Issue and verify one-time codes that let a Telegram user sign in to the
 * web dashboard.
 *
 * Symmetric counterpart to {@see \App\Bots\Channels\WhatsApp\WhatsAppOtpService}
 * with one structural difference: a Telegram account has no phone number for
 * the dashboard to address, so the code is *issued inside the bot* (the owner
 * taps "login to dashboard") and the dashboard only ever **verifies** it.
 *
 * Because the dashboard submits the code alone — it never knows the user's
 * chat_id — the cache is keyed by the code itself (HMAC'd so the plaintext
 * never lands in a shared cache key) and maps to the issuing chat_id:
 *
 *   issue($chatId)   → store code → chat_id with TTL, return plaintext code
 *   consume($code)   → single-use lookup, returns the chat_id (or null)
 *   resolveOwner($chatId) → the dashboard-eligible User behind that chat_id
 *
 * Brute force is bounded by a tiny 6-digit window only in combination with:
 * the short TTL, single-use invalidation, per-IP route throttling, and the
 * per-chat resend cooldown enforced here.
 */
class TelegramLoginService
{
    /** How long an issued code stays valid. */
    public const TTL_SECONDS = 300; // 5 minutes

    /** Cooldown between two issue() calls for the same chat. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Length of the numeric code. */
    public const CODE_LENGTH = 6;

    /** How many times to retry on the (astronomically rare) code collision. */
    private const MAX_ISSUE_ATTEMPTS = 5;

    /**
     * Generate a fresh code for a chat and store the code → chat_id mapping.
     * Returns the plaintext code so the caller can deliver it in the chat.
     */
    public function issue(string $chatId): string
    {
        $code = $this->generateUniqueCode();

        Cache::put($this->codeKey($code), $chatId, self::TTL_SECONDS);
        Cache::put($this->cooldownKey($chatId), true, self::RESEND_COOLDOWN_SECONDS);

        return $code;
    }

    public function isOnCooldown(string $chatId): bool
    {
        return Cache::has($this->cooldownKey($chatId));
    }

    /**
     * Single-use verification: return the chat_id behind a code and
     * immediately invalidate it so a code can never be replayed.
     */
    public function consume(string $code): ?string
    {
        $key    = $this->codeKey($code);
        $chatId = Cache::get($key);

        if (!is_string($chatId) || $chatId === '') {
            return null;
        }

        Cache::forget($key);

        return $chatId;
    }

    /**
     * Resolve the dashboard-eligible user behind a chat_id. Only
     * SPACE_OWNER (or SUPER_ADMIN) accounts may sign in to the dashboard;
     * everyone else resolves to null so the caller returns a generic error.
     */
    public function resolveOwner(string $chatId): ?User
    {
        $user = User::with('roles')
            ->where('telegram_chat_id', $chatId)
            ->first();

        if (!$user) {
            return null;
        }

        $eligible = $user->roles->contains(
            fn ($role) => in_array(
                $role->role,
                [RoleTypes::SPACE_OWNER, RoleTypes::SUPER_ADMIN],
                true,
            ),
        );

        return $eligible ? $user : null;
    }

    private function generateUniqueCode(): string
    {
        for ($i = 0; $i < self::MAX_ISSUE_ATTEMPTS; $i++) {
            $code = $this->generateCode();
            if (!Cache::has($this->codeKey($code))) {
                return $code;
            }
        }

        // Collisions in a 1M space with short-lived codes are vanishingly
        // unlikely; fall back to the last generated code rather than loop.
        return $this->generateCode();
    }

    private function generateCode(): string
    {
        // random_int is CSPRNG; pad-left so leading zeros are preserved.
        $max = (10 ** self::CODE_LENGTH) - 1;
        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function codeKey(string $code): string
    {
        // HMAC keeps the plaintext code out of the cache key while staying
        // deterministic, so verification can recompute the lookup key.
        $digest = hash_hmac('sha256', $code, (string) config('app.key'));

        return "telegram_login:code:{$digest}";
    }

    private function cooldownKey(string $chatId): string
    {
        return "telegram_login:cooldown:{$chatId}";
    }
}
