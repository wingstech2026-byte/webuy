<?php

namespace Tests\Feature;

use Tests\Concerns\Marketplace;
use Tests\TestCase;

class CompareAndRecentTest extends TestCase
{
    use Marketplace;

    public function test_visitors_see_what_they_looked_at_most_recent_first_without_hidden_products(): void
    {
        $rice = $this->makeProduct(null, ['title' => 'Local rice']);
        $oil = $this->makeProduct(null, ['title' => 'Palm oil']);

        $this->get(route('products.show', $rice))->assertOk();
        $this->get(route('products.show', $oil))->assertOk()->assertSee('You looked at')->assertSee('Local rice');
        $this->get(route('home'))->assertOk()->assertSeeInOrder(['Recently viewed', 'Palm oil', 'Local rice']);

        $rice->update(['status' => 'draft']);
        $this->get(route('compare.index'))->assertOk()->assertDontSee('Local rice');
    }

    public function test_up_to_three_products_are_compared_side_by_side(): void
    {
        [$a, $b, $c, $d] = [
            $this->makeProduct(null, ['title' => 'Cement A', 'price' => '100.00']),
            $this->makeProduct(null, ['title' => 'Cement B', 'price' => '95.00']),
            $this->makeProduct(null, ['title' => 'Cement C', 'price' => '110.00']),
            $this->makeProduct(null, ['title' => 'Cement D']),
        ];

        foreach ([$a, $b, $c] as $product) {
            $this->from(route('products.show', $product))->post(route('compare.toggle', $product))->assertRedirect();
        }
        $this->from(route('products.show', $d))->post(route('compare.toggle', $d))->assertSessionHasErrors('general');

        $this->get(route('compare.index'))->assertOk()
            ->assertSeeInOrder(['Cement A', 'Cement B', 'Cement C'])
            ->assertSee('Le 95.00')
            ->assertDontSee('Cement D');

        $this->post(route('compare.toggle', $b));
        $this->get(route('compare.index'))->assertDontSee('Cement B');

        $this->post(route('compare.clear'));
        $this->get(route('compare.index'))->assertSee('Nothing to compare yet');
    }
}
