<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pending/decided request from a person who wants to co-manage a garage.
 *
 * Created by the bot when someone chooses "تسجيل دخول لكراج آخر" and picks a
 * target garage. Resolved by that garage's owner from the dashboard: approval
 * links the requester's Telegram chat to the owner's account.
 */
class CoOwnerRequest extends Model
{
    use HasUuids;

    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'park_id',
        'owner_id',
        'requester_name',
        'requester_phone',
        'telegram_chat_id',
        'channel',
        'status',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /** The garage the requester wants to help manage. */
    public function park(): BelongsTo
    {
        return $this->belongsTo(Park::class);
    }

    /** The garage's owner (recipient of the request). */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The user who approved/rejected the request. */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
