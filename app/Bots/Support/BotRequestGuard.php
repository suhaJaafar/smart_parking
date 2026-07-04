<?php

namespace App\Bots\Support;

use App\Bots\Contracts\BotSession;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Serialises concurrent inbound messages from the SAME conversation.
 *
 * Messaging clients — and impatient users double-tapping buttons — can fire
 * several webhook deliveries within milliseconds of each other. Handling them
 * concurrently races on the shared session row (flow / step / data) and
 * surfaces spurious "invalid input" style errors. This guard grants at most
 * ONE in-flight request per (channel, recipient); any overlapping request is
 * refused so the caller can politely ask the user to wait for the current one
 * to finish.
 *
 * Backed by the cache's atomic lock (the `database` store uses the
 * `cache_locks` table), so the guarantee holds across separate webhook PHP
 * processes. A short TTL auto-releases the lock if a request dies mid-flight,
 * preventing a permanent deadlock.
 */
class BotRequestGuard
{
    /** Cache-key namespace for the per-conversation lock. */
    private const PREFIX = 'bot:inflight:';

    /** Safety ceiling — the lock self-releases after this many seconds. */
    private const TTL_SECONDS = 20;

    /**
     * Try to claim the conversation for the current request.
     *
     * @return Lock|null The held lock (call {@see Lock::release()} when done),
     *                   or null when another request is already in flight.
     */
    public function acquire(BotSession $session): ?Lock
    {
        $lock = Cache::lock($this->keyFor($session), self::TTL_SECONDS);

        return $lock->get() ? $lock : null;
    }

    private function keyFor(BotSession $session): string
    {
        return self::PREFIX . $session->getChannel() . ':' . $session->getRecipient();
    }
}
