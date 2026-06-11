<?php

namespace App\Bots\Contracts;

use App\Models\User;

/**
 * Channel-agnostic view of a user's bot conversation.
 *
 * Both the WhatsApp session (keyed by phone) and the Telegram session
 * (keyed by chat_id) implement this so the same set of flows can drive
 * either channel without knowing which one it's running on.
 *
 * Implementations are expected to be Eloquent models — `update()` and
 * `fresh()` mirror the Eloquent API so flows can keep using them.
 */
interface BotSession
{
    /** Short identifier for the channel, e.g. "whatsapp" or "telegram". */
    public function getChannel(): string;

    /**
     * Channel-native recipient address used to send a reply back to this
     * user — phone number for WhatsApp, chat_id for Telegram.
     */
    public function getRecipient(): string;

    public function getFlow(): ?string;
    public function getStep(): string;
    /** @return array<string, mixed> */
    public function getData(): array;
    public function getUser(): ?User;

    public function isExpired(): bool;
    public function reset(): void;

    /**
     * Eloquent passthrough — updates session columns and persists.
     *
     * Signature mirrors {@see \Illuminate\Database\Eloquent\Model::update}
     * exactly so concrete implementations can extend the model directly.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function update(array $attributes = [], array $options = []);

    /**
     * Eloquent passthrough — reload from DB with the given relations.
     *
     * @param array<int, string>|string $with
     */
    public function fresh($with = []);

    /**
     * Eloquent passthrough — set an eager-loaded relation manually
     * (used by flows that just created a User and want to attach it).
     */
    public function setRelation($relation, $value);
}
