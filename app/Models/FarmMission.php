<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FarmMission extends Model
{
    public const KEYS = [
        'check_in' => 'Daily check-in',
        'browse_deals' => "Browse today's lightning deals",
        'share' => 'Share WiBuy with a friend',
        'order' => 'Make an order',
        'review' => 'Write a review',
        'water_friend' => "Water a friend's plant",
    ];

    protected $fillable = ['key', 'label', 'water', 'daily_limit', 'is_active', 'sort_order'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
