<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One group: a leader invites people with a link; it succeeds when enough members have paid in time. */
class GroupBuy extends Model
{
    public const OPEN = 'open';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    protected $fillable = ['group_buy_campaign_id', 'leader_id', 'token', 'status', 'paid_count', 'expires_at', 'completed_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'token';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(GroupBuyCampaign::class, 'group_buy_campaign_id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(GroupBuyParticipant::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN && $this->expires_at->isFuture();
    }
}
