<?php

namespace App\Bots\Channels\Telegram;

use App\Bots\Engine\ConversationEngine;
use App\Enums\RoleTypes;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the Telegram-native webhook payload apart and normalises it into
 * the shape the channel-agnostic {@see ConversationEngine} understands.
 *
 * Telegram delivers one "Update" object per webhook hit. We handle:
 *   • message.text        → plain text
 *   • message.location    → "lat,lng"
 *   • message.contact     → digits-only phone number (used as text)
 *   • callback_query      → button data (used as text, with auto-ack)
 *
 * Anything else (photo, voice, sticker, …) yields an empty text so the
 * caller silently drops it.
 */
class TelegramInboundParser
{
    /**
     * Extract a single normalised inbound message from a webhook update.
     *
     * @return array{chat_id: string, type: string, text: string, name: ?string}|null
     *         Null when the update has no actionable content.
     */
    public function fromUpdate(array $update): ?array
    {
        // Inline button presses arrive as callback_query, not message.
        if (isset($update['callback_query'])) {
            $cb = $update['callback_query'];
            $chatId = $cb['message']['chat']['id'] ?? null;
            $data   = (string) ($cb['data'] ?? '');

            if ($chatId === null || $data === '') {
                return null;
            }

            return [
                'chat_id' => (string) $chatId,
                'type'    => ConversationEngine::TYPE_TEXT,
                'text'    => $data,
                'name'    => $this->extractName($cb['from'] ?? null),
            ];
        }

        $message = $update['message'] ?? $update['edited_message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $chatId = $message['chat']['id'] ?? null;
        if ($chatId === null) {
            return null;
        }

        $name = $this->extractName($message['from'] ?? null);

        // Location share — both flows and the location shortcut want
        // "lat,lng" so we serialise it eagerly here.
        if (isset($message['location'])) {
            $lat = $message['location']['latitude']  ?? null;
            $lng = $message['location']['longitude'] ?? null;
            if ($lat !== null && $lng !== null) {
                return [
                    'chat_id' => (string) $chatId,
                    'type'    => ConversationEngine::TYPE_LOCATION,
                    'text'    => sprintf('%s,%s', $lat, $lng),
                    'name'    => $name,
                ];
            }
        }

        // Contact share — treated as raw text (engine handles phone-number
        // extraction inside the relevant flows).
        if (isset($message['contact']['phone_number'])) {
            return [
                'chat_id' => (string) $chatId,
                'type'    => ConversationEngine::TYPE_TEXT,
                'text'    => preg_replace('/\D/', '', (string) $message['contact']['phone_number']),
                'name'    => $name,
            ];
        }

        // Photo (or an image sent as a file) — used by owner flows to OCR a
        // car plate. Resolve the largest size to a directly downloadable URL
        // so the flow can hand it to the plate recogniser. The marker prefix
        // lets the flow tell an image apart from typed text.
        $fileId = $this->imageFileId($message);
        if ($fileId !== null) {
            return [
                'chat_id' => (string) $chatId,
                'type'    => ConversationEngine::TYPE_IMAGE,
                'text'    => ConversationEngine::IMAGE_PAYLOAD_PREFIX . ($this->resolveFileUrl($fileId) ?? ''),
                'name'    => $name,
            ];
        }

        $text = (string) ($message['text'] ?? '');
        if ($text === '') {
            return null;
        }

        return [
            'chat_id' => (string) $chatId,
            'type'    => ConversationEngine::TYPE_TEXT,
            'text'    => $text,
            'name'    => $name,
        ];
    }

    /**
     * Build a display name from a Telegram `from` object. Prefers the
     * real first/last name, falls back to the @username, null when the
     * object carries neither.
     *
     * @param array<string, mixed>|null $from
     */
    private function extractName(?array $from): ?string
    {
        if (!is_array($from)) {
            return null;
        }

        $full = trim(sprintf(
            '%s %s',
            (string) ($from['first_name'] ?? ''),
            (string) ($from['last_name'] ?? ''),
        ));

        if ($full !== '') {
            return $full;
        }

        $username = trim((string) ($from['username'] ?? ''));

        return $username !== '' ? $username : null;
    }

    /**
     * Pull the best image `file_id` from a message: the largest size of a
     * `photo`, or an image-typed `document` (when the user sends the plate
     * picture "as a file"). Null when the message carries no image.
     *
     * @param array<string, mixed> $message
     */
    private function imageFileId(array $message): ?string
    {
        // `photo` is an array of progressively larger sizes — last is biggest.
        if (isset($message['photo']) && is_array($message['photo']) && $message['photo'] !== []) {
            $largest = end($message['photo']);
            $id      = $largest['file_id'] ?? null;

            return is_string($id) ? $id : null;
        }

        // An image sent as a document/file attachment.
        $document = $message['document'] ?? null;
        if (is_array($document)
            && str_starts_with((string) ($document['mime_type'] ?? ''), 'image/')
        ) {
            $id = $document['file_id'] ?? null;

            return is_string($id) ? $id : null;
        }

        return null;
    }

    /**
     * Exchange a Telegram `file_id` for a directly downloadable URL via the
     * Bot API `getFile` method. Returns null on any failure so the caller
     * degrades to manual plate entry.
     */
    private function resolveFileUrl(string $fileId): ?string
    {
        $token = config('services.telegram.bot_token');
        $base  = config('services.telegram.api_base_url', 'https://api.telegram.org');

        if (!is_string($token) || $token === '') {
            return null;
        }

        try {
            $response = Http::timeout(10)->get("{$base}/bot{$token}/getFile", [
                'file_id' => $fileId,
            ]);

            $path = $response->json('result.file_path');
            if (!is_string($path) || $path === '') {
                return null;
            }

            return "{$base}/file/bot{$token}/{$path}";
        } catch (\Throwable $e) {
            Log::warning('Telegram getFile failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get-or-create a session row for this chat, linking it to a User if
     * the chat_id is already known.
     */
    public function resolveSession(string $chatId): TelegramSession
    {
        $session = TelegramSession::firstOrNew(['chat_id' => $chatId]);

        if (!$session->exists) {
            $session->step = 'idle';
            $session->data = [];
        }

        if ($session->user_id === null) {
            $user = User::where('telegram_chat_id', $chatId)->first();

            if ($user) {
                $hasBotRole = $user->roles->contains(
                    fn ($r) => in_array($r->role, [
                        RoleTypes::SPACE_OWNER,
                        RoleTypes::CUSTOMER,
                        RoleTypes::USER,
                    ], true)
                );
                if ($hasBotRole) {
                    $session->user_id = $user->id;
                }
            }
        }

        $session->save();
        $session->load(['user', 'user.roles']);

        // Defensive: if the linked user no longer exists, clear the link.
        if ($session->user_id !== null && $session->user === null) {
            $session->update(['user_id' => null]);
            $session->load(['user', 'user.roles']);
        }

        return $session;
    }
}
