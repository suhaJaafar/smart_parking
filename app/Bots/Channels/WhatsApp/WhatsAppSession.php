<?php

namespace App\Bots\Channels\WhatsApp;

use App\Bots\Contracts\BotSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-phone WhatsApp conversation state.
 *
 * Implements {@see BotSession} so the channel-agnostic flows can drive a
 * conversation without knowing it came in over WhatsApp.
 */
class WhatsAppSession extends Model implements BotSession
{
    use HasUuids;

    public const CHANNEL = 'whatsapp';

    protected $table = 'whatsapp_sessions';

    protected $fillable = [
        'phone',
        'user_id',
        'flow',
        'step',
        'data',
        'expires_at',
    ];

    protected $casts = [
        'data'       => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function reset(): void
    {
        $this->update([
            'flow'       => null,
            'step'       => 'idle',
            'data'       => [],
            'expires_at' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // BotSession contract
    // -----------------------------------------------------------------

    public function getChannel(): string
    {
        return self::CHANNEL;
    }

    public function getRecipient(): string
    {
        return (string) $this->phone;
    }

    public function getFlow(): ?string
    {
        return $this->flow;
    }

    public function getStep(): string
    {
        return (string) ($this->step ?? 'idle');
    }

    public function getData(): array
    {
        return is_array($this->data) ? $this->data : [];
    }

    public function getUser(): ?User
    {
        return $this->user;
    }
}
