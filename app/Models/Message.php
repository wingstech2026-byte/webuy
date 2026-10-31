<?php

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'user_id', 'type', 'body', 'image_path', 'thumb_path', 'audio_path', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'deleted_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(MessageReport::class);
    }

    public function isSystem(): bool
    {
        return $this->type === MessageType::System;
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path ? Storage::disk(config('chat.images.disk'))->url($this->image_path) : null;
    }

    public function getThumbUrlAttribute(): ?string
    {
        return $this->thumb_path ? Storage::disk(config('chat.images.disk'))->url($this->thumb_path) : null;
    }

    public function getAudioUrlAttribute(): ?string
    {
        return $this->audio_path ? Storage::disk(config('chat.images.disk'))->url($this->audio_path) : null;
    }

    /**
     * Who the viewer sees as the author. Customers see first names only; the WiBuy team is
     * always "WiBuy team"; admins see full names so they can moderate.
     */
    public function authorLabel(?User $viewer): string
    {
        if ($this->isSystem() || ! $this->user) {
            return config('wibuy.name');
        }

        if ($this->user->is_admin) {
            return 'WiBuy team';
        }

        return $viewer?->is_admin ? ($this->user->name ?: $this->user->display_phone) : $this->user->first_name;
    }
}
