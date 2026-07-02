<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Telegram device (chat) linked to a ParkIQ {@see User}.
 *
 * One user may have several of these, which is what lets two people operate
 * the same park-owner account from two different phones. The chat_id is
 * unique across the table, so a given chat always resolves to exactly one
 * account.
 */
class TelegramAccount extends Model
{
    use HasUuids;

    protected $table = 'telegram_accounts';

    protected $fillable = [
        'user_id',
        'chat_id',
        'is_primary',
        'label',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
