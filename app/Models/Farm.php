<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A person's WiBuy Farm plant. Water comes from missions; at the target it is harvested for a prize. */
class Farm extends Model
{
    public const PLANTS = ['rice' => 'Rice plant', 'mango' => 'Mango tree'];

    public const STAGES = ['Seed', 'Sprout', 'Young plant', 'Flowering', 'Ready to harvest'];

    protected $fillable = ['user_id', 'code', 'plant', 'water', 'target', 'harvests'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function waterings(): HasMany
    {
        return $this->hasMany(FarmWatering::class);
    }

    public function getProgressPercentAttribute(): int
    {
        return $this->target > 0 ? (int) min(100, floor($this->water * 100 / $this->target)) : 0;
    }

    public function getStageAttribute(): string
    {
        $percent = $this->progress_percent;

        return self::STAGES[$percent >= 100 ? 4 : min(3, intdiv($percent, 25))];
    }

    public function isGrown(): bool
    {
        return $this->water >= $this->target;
    }
}
