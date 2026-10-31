<?php

namespace Database\Factories;

use App\Enums\MessageType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'type' => MessageType::Text,
            'body' => fake()->sentence(),
        ];
    }

    public function system(string $body = 'Something happened'): static
    {
        return $this->state(fn () => ['type' => MessageType::System, 'user_id' => null, 'body' => $body]);
    }
}
