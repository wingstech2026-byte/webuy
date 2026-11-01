<?php

namespace App\Models;

use Database\Factories\StationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A WiBuy pickup station run by a partner shop (see StationService for the parcel steps). */
class Station extends Model
{
    /** @use HasFactory<StationFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'address', 'district', 'latitude', 'longitude', 'opening_hours', 'is_active'];

    protected function casts(): array
    {
        return ['latitude' => 'decimal:6', 'longitude' => 'decimal:6', 'is_active' => 'boolean'];
    }

    /** The partner who runs the station. */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** What buyers see: name, address, area and hours. */
    public function label(): string
    {
        return "{$this->name}, {$this->address} ({$this->district})".($this->opening_hours ? " · {$this->opening_hours}" : '');
    }
}
