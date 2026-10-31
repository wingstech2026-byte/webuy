<?php

namespace Tests\Feature;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Broadcasting\BroadcastManager;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Support chat with the WiBuy team, and buyer <-> seller chat behind CHAT_DIRECT_ENABLED. */
class ChatTest extends TestCase
{
    use Marketplace;

    private function chat(): ChatService
    {
        return app(ChatService::class);
    }

    public function test_a_customer_writes_to_the_team_and_an_admin_replies(): void
    {
        $customer = $this->buyer(['name' => 'Aminata Kamara']);
        $admin = User::factory()->admin()->create(['name' => 'Secret Admin']);

        $this->actingAs($customer)->get(route('chat.index'))->assertOk()->assertSee('WiBuy team');
        $support = Conversation::where('type', ConversationType::Support)->where('owner_id', $customer->id)->sole();

        $this->actingAs($customer)->postJson(route('chat.store', $support), ['body' => 'Where is my order?'])->assertCreated();
        $this->assertSame(1, $this->chat()->supportUnreadTotal());

        $this->actingAs($admin)->get(route('admin.support.index'))->assertOk()->assertSee('Aminata Kamara')->assertSee('1 new');
        $this->actingAs($admin)->get(route('admin.support.show', $support))->assertOk()->assertSee('Where is my order?');
        $this->assertSame(0, $this->chat()->supportUnreadTotal());

        $this->travel(2)->minutes(); // the customer left the chat
        $this->actingAs($admin)->postJson(route('chat.store', $support), ['body' => 'It ships today'])->assertCreated();

        $html = $this->actingAs($customer)->getJson(route('chat.messages', $support))->json('html');
        $this->assertStringContainsString('It ships today', $html);
        $this->assertStringContainsString('WiBuy team', $html);
        $this->assertStringNotContainsString('Secret Admin', $html);
        $this->assertStringContainsString('You have new messages on WiBuy', $this->sms->lastTo($customer->phone));
    }

    public function test_support_chats_are_private(): void
    {
        $support = $this->chat()->supportFor($this->buyer());
        $stranger = $this->buyer();

        $this->actingAs($stranger)->getJson(route('chat.messages', $support))->assertForbidden();
        $this->actingAs($stranger)->postJson(route('chat.store', $support), ['body' => 'hi'])->assertForbidden();
    }

    public function test_messaging_is_rate_limited_to_20_a_minute(): void
    {
        $customer = $this->buyer();
        $support = $this->chat()->supportFor($customer);

        for ($i = 1; $i <= 20; $i++) {
            $this->actingAs($customer)->postJson(route('chat.store', $support), ['body' => "m{$i}"])->assertCreated();
        }

        $this->actingAs($customer)->postJson(route('chat.store', $support), ['body' => 'too many'])->assertStatus(429);
        $this->assertSame(20, Message::count());
    }

    public function test_direct_buyer_seller_chat_is_off_by_default_and_works_when_turned_on(): void
    {
        $buyer = $this->buyer();
        $seller = $this->makeSeller();

        $this->actingAs($buyer)->get(route('chat.direct', $seller->user))->assertNotFound();

        config(['chat.direct_enabled' => true]);
        $this->actingAs($buyer)->get(route('chat.direct', $seller->user))->assertRedirect();
        $direct = Conversation::where('type', ConversationType::Direct)->sole();

        $this->actingAs($buyer)->postJson(route('chat.store', $direct), ['body' => 'Do you have 20 bags?'])->assertCreated();
        $this->assertSame(1, $this->chat()->unreadFor($seller->user));
        $this->actingAs($seller->user)->get(route('chat.index'))->assertSee('Do you have 20 bags?');
        $this->actingAs($this->buyer())->getJson(route('chat.messages', $direct))->assertForbidden();
    }

    public function test_private_channels_only_admit_members(): void
    {
        config(['broadcasting.default' => 'reverb']);
        app(BroadcastManager::class)->purge('reverb');
        require base_path('routes/channels.php');

        $owner = $this->buyer();
        $support = $this->chat()->supportFor($owner);
        $auth = fn ($user) => $this->actingAs($user)->postJson('/broadcasting/auth', [
            'socket_id' => '1234.5678', 'channel_name' => 'private-conversation.'.$support->id,
        ]);

        $auth($owner)->assertOk()->assertJsonStructure(['auth']);
        $auth(User::factory()->admin()->create())->assertOk();
        $auth($this->buyer())->assertForbidden();
    }
}
