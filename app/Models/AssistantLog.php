<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'message', 'understood', 'input_tokens', 'output_tokens', 'status'];

    protected function casts(): array
    {
        return ['understood' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
