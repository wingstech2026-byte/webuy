<?php

namespace App\Models;

use App\Enums\DisputeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Dispute extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => DisputeStatus::class,
            'seller_responded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('collection', 'dispute')->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === DisputeStatus::Open;
    }
}
