<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $deleted = $this->isDeleted();

        return [
            'id' => $this->id,
            'type' => $this->type->value, // text | image | voice | system
            'mine' => $this->user_id !== null && $this->user_id === $request->user()?->id,
            'author' => $this->authorLabel($request->user()),
            'body' => $deleted ? 'This message was removed.' : $this->body,
            'image_url' => $deleted ? null : $this->image_url,
            'thumb_url' => $deleted ? null : $this->thumb_url,
            'audio_url' => $deleted ? null : $this->audio_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
