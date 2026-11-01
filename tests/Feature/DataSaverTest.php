<?php

namespace Tests\Feature;

use App\Models\Image;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** "Save data" on the website: small pictures on the product page, remembered in a cookie. */
class DataSaverTest extends TestCase
{
    use Marketplace;

    public function test_save_data_shows_the_small_picture_and_can_be_turned_off(): void
    {
        $product = $this->makeProduct();
        Image::create([
            'imageable_type' => $product->getMorphClass(), 'imageable_id' => $product->id, 'collection' => 'gallery',
            'original_path' => 'uploads/x.upload', 'path' => 'products/big-picture.jpg', 'thumb_path' => 'products/small-picture.jpg', 'status' => 'ready',
        ]);

        $this->get(route('products.show', $product))->assertSee('big-picture.jpg')->assertSee('Save data: use smaller pictures');

        $on = $this->from(route('products.show', $product))->post(route('data-saver'));
        $on->assertRedirect(route('products.show', $product))->assertCookie('data_saver', '1');

        $page = $this->withCookie('data_saver', '1')->get(route('products.show', $product));
        $page->assertSee('Save data is on');
        $this->assertMatchesRegularExpression('/<img src="[^"]*small-picture\.jpg"[^>]*data-gallery-main/', $page->getContent());

        $this->withCookie('data_saver', '1')->post(route('data-saver'))->assertCookie('data_saver', '0');
    }
}
