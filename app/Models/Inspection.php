<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** A WiBuy agent checks the goods at the seller before the buyer pays the last milestone. */
class Inspection extends Model
{
    protected $fillable = ['order_id', 'agent_id', 'fee', 'status', 'report', 'paid_at', 'reported_at'];

    protected function casts(): array
    {
        return [
            'fee' => 'decimal:2',
            'paid_at' => 'datetime',
            'reported_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function photos(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')->where('collection', 'inspection')->orderBy('position');
    }

    /** The buyer may pay the last milestone: no inspection asked, or it passed. */
    public function allowsFinalPayment(): bool
    {
        return $this->status === 'passed';
    }
}
