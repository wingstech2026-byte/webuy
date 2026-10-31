<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A buyer's named delivery address. Coordinates are optional: GPS and maps are never required. */
class SavedAddress extends Model
{
    protected $fillable = [
        'user_id', 'label', 'recipient_name', 'recipient_phone', 'district', 'town', 'neighbourhood',
        'street', 'landmark', 'directions', 'latitude', 'longitude', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasPoint(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** One line people can read, for orders and sellers. */
    public function getOneLineAttribute(): string
    {
        return collect([$this->street, $this->neighbourhood, $this->town, $this->landmark ? "({$this->landmark})" : null])->filter()->implode(', ');
    }
}
