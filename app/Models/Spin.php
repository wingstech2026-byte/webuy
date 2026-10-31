<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Spin extends Model
{
    protected $fillable = ['user_id', 'spin_prize_id', 'day', 'source', 'prize_label'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(SpinPrize::class, 'spin_prize_id');
    }
}
