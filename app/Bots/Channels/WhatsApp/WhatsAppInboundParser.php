<?php

namespace App\Bots\Channels\WhatsApp;

use App\Enums\RoleTypes;
use App\Models\User;

/**
 * Pulls the WhatsApp-native webhook payload apart and resolves it into a
 * channel-agnostic shape the {@see \App\Bots\Engine\ConversationEngine}
 * can consume.
 *
 * The Meta webhook envelope contains a list of entries, each with a list
 * of changes, each with a list of messages. We iterate them all and yield
 * normalised tuples to the caller.
 */
class WhatsAppInboundParser
{
    /**
     * Iterate every inbound message from the webhook body.
     *
     * @return \Generator<int, array{from: string, type: string, text: string, name: ?string}>
     */
    public function messages(array $body): \Generator
    {
        if (($body['object'] ?? '') !== 'whatsapp_business_account') {
            return;
        }

        foreach ($body['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                // "statuses" updates are delivery receipts — not user input.
                if (isset($value['statuses'])) {
                    continue;
                }

                // Map wa_id → profile name so each message can adopt the
                // sender's WhatsApp display name.
                $names = $this->extractNames($value['contacts'] ?? []);

                foreach ($value['messages'] ?? [] as $message) {
                    $from = $message['from'] ?? null;
                    $type = $message['type'] ?? 'text';
                    $text = $this->extractContent($message, $type);

                    if ($from && $text !== '') {
                        yield [
                            'from' => (string) $from,
                            'type' => $this->normaliseType($type),
                            'text' => $text,
                            'name' => $names[(string) $from] ?? null,
                        ];
                    }
                }
            }
        }
    }

    /**
     * Build a wa_id → display-name lookup from the webhook's `contacts`
     * array. Empty names are dropped so callers fall back cleanly.
     *
     * @param array<int, array<string, mixed>> $contacts
     * @return array<string, string>
     */
    private function extractNames(array $contacts): array
    {
        $names = [];

        foreach ($contacts as $contact) {
            $waId = (string) ($contact['wa_id'] ?? '');
            $name = trim((string) ($contact['profile']['name'] ?? ''));

            if ($waId !== '' && $name !== '') {
                $names[$waId] = $name;
            }
        }

        return $names;
    }

    /**
     * Convert any inbound message type into a plain text payload the
     * flows can parse.
     */
    private function extractContent(array $message, string $type): string
    {
        return match ($type) {
            'text' => $message['text']['body'] ?? '',

            'location' => isset($message['location']['latitude'], $message['location']['longitude'])
                ? sprintf('%s,%s', $message['location']['latitude'], $message['location']['longitude'])
                : '',

            'interactive' => $message['interactive']['button_reply']['id']
                          ?? $message['interactive']['list_reply']['id']
                          ?? '',

            default => '',
        };
    }

    /**
     * Map WhatsApp-native types onto the engine's vocabulary.
     */
    private function normaliseType(string $type): string
    {
        return $type === 'location'
            ? \App\Bots\Engine\ConversationEngine::TYPE_LOCATION
            : \App\Bots\Engine\ConversationEngine::TYPE_TEXT;
    }

    /**
     * Get-or-create a session row for this phone, linking it to a User if
     * registered.
     */
    public function resolveSession(string $from): WhatsAppSession
    {
        $session = WhatsAppSession::firstOrNew(['phone' => $from]);

        if (!$session->exists) {
            $session->step = 'idle';
            $session->data = [];
        }

        if ($session->user_id === null) {
            $user = User::where('phone_number', $from)
                ->orWhere('phone_number', ltrim($from, '0'))
                ->first();

            // Only auto-link if the user has a bot-relevant role.
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
