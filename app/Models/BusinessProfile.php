<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A buyer's registered business. Once WiBuy verifies it, they see business-only wholesale prices. */
class BusinessProfile extends Model
{
    protected $fillable = ['user_id', 'business_name', 'registration_no', 'district', 'address', 'status', 'rejection_reason', 'verified_at'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Registration certificate and other papers (private files). */
    public function documents(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('collection', 'business_doc');
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
