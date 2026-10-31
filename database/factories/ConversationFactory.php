<?php

namespace Database\Factories;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => ConversationType::Support,
            'owner_id' => User::factory(),
        ];
    }

    public function forOwner(User $owner): static
    {
        return $this->state(fn () => ['owner_id' => $owner->id]);
    }
}
