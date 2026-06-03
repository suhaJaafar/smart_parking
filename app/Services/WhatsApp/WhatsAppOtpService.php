<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * Issue and verify one-time codes delivered over WhatsApp.
 *
 * The bot already authenticates users by `phone_number`, so the web/dashboard
 * needs no password — just the same channel. This service is the single
 * source of truth for code lifecycle:
 *
 *   issue($phone)  → stores hash($code) in cache with TTL, returns plain code
 *   verify($phone, $code) → constant-time check, then returns the User
 *
 * Hashing avoids leaking valid codes if the cache store is later moved to a
 * shared driver (Redis, etc). Attempt counters short-circuit brute force.
 */
class WhatsAppOtpService
{
    /** How long an issued code stays valid. */
    public const TTL_SECONDS = 300; // 5 minutes

    /** Cooldown between two requestCode calls for the same phone. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    /** Max wrong verification tries before the code is invalidated. */
    public const MAX_ATTEMPTS = 5;

    /** Length of the numeric code. */
    public const CODE_LENGTH = 6;

    /**
     * Generate a fresh code for a phone and store its hash. Returns the
     * plaintext code so the caller can dispatch it via WhatsApp.
     *
     * Replaces any previously-issued code for the same phone (the user
     * just requested a new one — invalidate the old one).
     */
    public function issue(string $phone): string
    {
        $code = $this->generateCode();

        Cache::put($this->codeKey($phone), [
            'hash'     => Hash::make($code),
            'attempts' => 0,
        ], self::TTL_SECONDS);

        Cache::put($this->cooldownKey($phone), true, self::RESEND_COOLDOWN_SECONDS);

        return $code;
    }

    /**
     * Whether the caller must wait before requesting another code.
     */
    public function isOnCooldown(string $phone): bool
    {
        return Cache::has($this->cooldownKey($phone));
    }

    /**
     * Verify a submitted code. Returns the matching User on success,
     * or null on any failure (wrong code, expired, too many attempts,
     * no user with that phone). The cache entry is consumed on success
     * and on terminal failure so the same code can't be reused.
     */
    public function verify(string $phone, string $code): ?User
    {
        $key   = $this->codeKey($phone);
        $entry = Cache::get($key);

        if (!is_array($entry) || !isset($entry['hash'])) {
            return null;
        }

        $attempts = (int) ($entry['attempts'] ?? 0);
        if ($attempts >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return null;
        }

        if (!Hash::check($code, $entry['hash'])) {
            Cache::put($key, [
                'hash'     => $entry['hash'],
                'attempts' => $attempts + 1,
            ], self::TTL_SECONDS);
            return null;
        }

        Cache::forget($key);

        return $this->resolveUser($phone);
    }

    /**
     * Look up the User this phone belongs to. Mirrors the lookup logic
     * already used in WhatsAppController so a number stored without a
     * leading 0 still matches when the dashboard sends a local format.
     */
    public function resolveUser(string $phone): ?User
    {
        return User::where('phone_number', $phone)
            ->orWhere('phone_number', ltrim($phone, '0'))
            ->first();
    }

    private function generateCode(): string
    {
        // random_int is CSPRNG; pad-left so leading zeros are preserved.
        $max = (10 ** self::CODE_LENGTH) - 1;
        return str_pad((string) random_int(0, $max), self::CODE_LENGTH, '0', STR_PAD_LEFT);
    }

    private function codeKey(string $phone): string
    {
        return "whatsapp_otp:code:{$phone}";
    }

    private function cooldownKey(string $phone): string
    {
        return "whatsapp_otp:cooldown:{$phone}";
    }
}
