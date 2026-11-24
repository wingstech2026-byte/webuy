<?php

namespace Tests\Feature;

use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class MobileShopToolsTest extends TestCase
{
    use Marketplace;

    public function test_the_owner_can_manage_holiday_mode_and_helpers(): void
    {
        $shop = $this->makeSeller();
        $helper = $this->buyer();
        $settings = '/api/v1/seller/settings';

        Sanctum::actingAs($shop->user);
        $this->putJson("$settings/holiday", ['holiday_until' => now()->subDay()->toDateString()])->assertUnprocessable();
        $date = now()->addDays(5)->toDateString();
        $this->putJson("$settings/holiday", ['holiday_until' => $date, 'holiday_message' => 'Back next week'])->assertNoContent();
        $this->postJson("$settings/staff", ['phone' => $helper->phone])->assertNoContent();
        $this->getJson($settings)->assertOk()->assertJsonPath('holiday_until', $date)
            ->assertJsonPath('holiday_message', 'Back next week')->assertJsonPath('staff.0.id', $helper->id);

        Sanctum::actingAs($helper);
        $this->getJson($settings)->assertForbidden();
        $this->getJson('/api/v1/seller/reports')->assertForbidden();
        $this->putJson("$settings/holiday", ['holiday_until' => null])->assertForbidden();

        Sanctum::actingAs($shop->user);
        $this->deleteJson("$settings/staff/{$helper->id}")->assertNoContent();
        $this->putJson("$settings/holiday", ['holiday_until' => null])->assertNoContent();
        $this->getJson($settings)->assertOk()->assertJsonPath('holiday_until', null)->assertJsonCount(0, 'staff');
    }

    public function test_a_shop_can_answer_and_hide_only_its_own_product_questions(): void
    {
        $shop = $this->makeSeller();
        $product = $this->makeProduct($shop);
        Sanctum::actingAs($this->buyer());
        $question = $this->postJson("/api/v1/products/{$product->slug}/questions", ['question' => 'Is this available in blue?'])
            ->assertCreated()->json('data.id');

        $questions = '/api/v1/seller/questions';
        Sanctum::actingAs($this->makeSeller()->user);
        $this->deleteJson("$questions/$question")->assertNotFound();

        Sanctum::actingAs($shop->user);
        $this->getJson($questions)->assertJsonPath('data.0.id', $question);
        $this->postJson("$questions/$question/answer", ['answer' => 'Yes, it comes in blue.'])->assertOk();
        $this->deleteJson("$questions/$question")->assertNoContent();
        $this->getJson($questions)->assertJsonCount(0, 'data');
    }
}
