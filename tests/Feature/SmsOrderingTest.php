<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Ordering by SMS and USSD for people without data, through the gateway's webhooks. */
class SmsOrderingTest extends TestCase
{
    use Marketplace;

    private const PHONE = '23276555000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.sms_ordering' => ['enabled' => true, 'token' => 'gateway-secret', 'number' => '2000', 'ussd_code' => '*123#']]);
    }

    private function text(string $message, string $from = '076 555 000'): ?string
    {
        $this->post('/webhooks/sms?token=gateway-secret', ['from' => $from, 'text' => $message])->assertOk();

        return $this->sms->lastTo(self::PHONE);
    }

    private function dial(string $text): string
    {
        return $this->post('/webhooks/ussd?token=gateway-secret', ['sessionId' => 's1', 'phoneNumber' => '+23276555000', 'text' => $text])->assertOk()->getContent();
    }

    public function test_someone_without_data_joins_finds_and_orders_by_sms(): void
    {
        $rice = $this->makeProduct(null, ['title' => 'Rice 50kg', 'price' => '400.00']);

        $this->assertStringContainsString('JOIN your full name', $this->text("BUY {$rice->id} 1 ORANGE"));
        $this->assertStringContainsString('Welcome Aminata Kamara', $this->text('JOIN Aminata Kamara, Lumley, Freetown'));
        $this->assertSame(['Western Area Urban', 'Lumley', 'sms'], [User::where('phone', self::PHONE)->value('district'), User::where('phone', self::PHONE)->value('area'), User::where('phone', self::PHONE)->value('signup_channel')]);

        $this->assertStringContainsString("#{$rice->id} Rice 50kg Le 400.00", $this->text('find rice'));
        $this->assertStringContainsString('Add how you pay', $this->text("BUY {$rice->id} 2"));

        $reply = $this->text("buy #{$rice->id} 2 orange");
        $order = Order::sole();
        $this->assertStringContainsString("Order {$order->number}: 2 x Rice 50kg = Le 820.00 with delivery to Lumley", $reply);
        $this->assertSame('sms', $order->checkout->channel);

        $this->pay($order->checkout);
        $this->assertStringContainsString("Order {$order->number}", $this->text('status'));
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_the_ussd_menu_places_an_order_step_by_step(): void
    {
        $rice = $this->makeProduct(null, ['title' => 'Rice 50kg', 'price' => '400.00']);
        User::factory()->create(['phone' => self::PHONE, 'name' => 'Musa Conteh']);

        $this->assertStringStartsWith("CON Welcome to WiBuy\n1. Buy with a product code", $this->dial(''));
        $this->assertStringStartsWith('CON Enter the product code', $this->dial('1'));
        $this->assertStringStartsWith("CON Rice 50kg\nLe 400.00", $this->dial("1*{$rice->id}"));
        $this->assertStringStartsWith('CON Pay with', $this->dial("1*{$rice->id}*1"));
        $this->assertStringContainsString('Approve the Afrimoney prompt', $this->dial("1*{$rice->id}*1*2"));
        $this->assertSame(1, Order::count());
        $this->assertStringStartsWith('END There is no product with code 999999', $this->dial('1*999999'));
    }

    public function test_only_the_gateway_with_the_token_can_order_for_a_phone(): void
    {
        $this->post('/webhooks/sms?token=wrong', ['from' => '076555000', 'text' => 'STATUS'])->assertNotFound();
        $this->post('/webhooks/ussd', ['phoneNumber' => '076555000', 'text' => ''])->assertNotFound();

        config(['services.sms_ordering.enabled' => false]);
        $this->post('/webhooks/sms?token=gateway-secret', ['from' => '076555000', 'text' => 'STATUS'])->assertNotFound();
    }
}
