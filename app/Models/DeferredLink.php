<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeferredLink extends Model
{
    protected $fillable = ['fingerprint', 'path', 'expires_at', 'claimed_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }
}
