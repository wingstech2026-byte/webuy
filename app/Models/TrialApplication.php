<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrialApplication extends Model
{
    protected $fillable = ['trial_campaign_id', 'user_id', 'reason', 'status', 'coupon_id', 'review_id', 'selected_at', 'review_due_at'];

    protected function casts(): array
    {
        return [
            'selected_at' => 'datetime',
            'review_due_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(TrialCampaign::class, 'trial_campaign_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
