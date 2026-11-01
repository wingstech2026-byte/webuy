<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Image;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use Marketplace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function form(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Local rice, 50kg bag',
            'category_id' => Category::factory()->create()->id,
            'description' => 'Clean parboiled rice',
            'price' => '650.00',
            'compare_at_price' => '720.00',
            'stock' => 100,
            'unit' => '50kg bag',
            'min_order_quantity' => 1,
            'condition' => 'new',
            'status' => 'active',
            'tiers' => [
                ['min_quantity' => '10', 'unit_price' => '620'],
                ['min_quantity' => '50', 'unit_price' => '590.50'],
                ['min_quantity' => '', 'unit_price' => ''], // blank rows are ignored
            ],
        ], $overrides);
    }

    public function test_a_seller_creates_a_product_with_images_and_price_tiers(): void
    {
        $seller = $this->makeSeller();

        $response = $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form([
            'images' => [
                UploadedFile::fake()->image('front.jpg', 3000, 2000),
                UploadedFile::fake()->image('side.png', 600, 900),
            ],
        ]));

        $product = Product::firstOrFail();
        $response->assertRedirect(route('seller.products.edit', $product));

        $this->assertSame($seller->id, $product->seller_id);
        $this->assertSame('local-rice-50kg-bag', $product->slug);
        $this->assertSame('650.00', $product->price);
        $this->assertSame(ProductStatus::Active, $product->status);

        // Tiers
        $this->assertSame([10 => '620.00', 50 => '590.50'], $product->tiers->pluck('unit_price', 'min_quantity')->all());
        $this->assertSame('650.00', $product->unitPriceFor(9));
        $this->assertSame('620.00', $product->unitPriceFor(10));
        $this->assertSame('620.00', $product->unitPriceFor(49));
        $this->assertSame('590.50', $product->unitPriceFor(50));
        $this->assertSame('590.50', $product->unitPriceFor(5000));

        // Images: processed on the queue (sync in tests), resized to max 1080px with a thumbnail, original deleted.
        $images = $product->images;
        $this->assertCount(2, $images);
        foreach ($images as $image) {
            $this->assertSame('ready', $image->status);
            $this->assertNull($image->original_path);
            Storage::disk('public')->assertExists([$image->path, $image->thumb_path]);
        }

        [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($images[0]->path));
        $this->assertSame([1080, 720, IMAGETYPE_JPEG], [$w, $h, $type]);
        [$tw] = getimagesizefromstring(Storage::disk('public')->get($images[0]->thumb_path));
        $this->assertSame(360, $tw);
        [, $h2] = getimagesizefromstring(Storage::disk('public')->get($images[1]->path));
        $this->assertSame(900, $h2, 'small pictures are never scaled up');
        $this->assertSame([], Storage::disk('local')->allFiles('uploads'), 'originals are deleted');

        // Buyers see it, with tiers and lazy-loaded pictures.
        $this->get(route('products.show', $product))->assertOk()
            ->assertSee('Local rice, 50kg bag')->assertSee('Wholesale prices')->assertSee('Le 590.50')
            ->assertSee('loading="lazy"', false)
            ->assertSee('property="og:image"', false);
    }

    public function test_price_tiers_must_go_down_and_be_unique(): void
    {
        $seller = $this->makeSeller();

        foreach ([
            [['min_quantity' => 10, 'unit_price' => '700']],                                               // higher than retail
            [['min_quantity' => 10, 'unit_price' => '600'], ['min_quantity' => 50, 'unit_price' => '610']], // goes up
            [['min_quantity' => 10, 'unit_price' => '600'], ['min_quantity' => 10, 'unit_price' => '590']], // duplicate
        ] as $tiers) {
            $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form(['tiers' => $tiers]))
                ->assertSessionHasErrors('tiers');
        }

        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form(['tiers' => [['min_quantity' => 1, 'unit_price' => '600']]]))
            ->assertSessionHasErrors('tiers.0.min_quantity');

        $this->assertSame(0, Product::count());
    }

    public function test_product_fields_are_validated(): void
    {
        $seller = $this->makeSeller();

        $this->actingAs($seller->user)->post(route('seller.products.store'), [])
            ->assertSessionHasErrors(['title', 'price', 'stock', 'unit', 'min_order_quantity', 'condition', 'status']);
        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form(['compare_at_price' => '500']))->assertSessionHasErrors('compare_at_price');
        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form(['price' => '1.234']))->assertSessionHasErrors('price');
        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form(['status' => 'suspended']))->assertSessionHasErrors('status');
        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form([
            'images' => [UploadedFile::fake()->create('evil.svg', 5, 'image/svg+xml')],
        ]))->assertSessionHasErrors('images.0');
        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form([
            'images' => array_fill(0, 7, UploadedFile::fake()->image('a.jpg')),
        ]))->assertSessionHasErrors('images');

        $this->assertSame(0, Product::count());
    }

    public function test_at_most_six_pictures_in_total(): void
    {
        $seller = $this->makeSeller();
        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form([
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 5)),
        ]));
        $product = Product::firstOrFail();

        $this->actingAs($seller->user)->put(route('seller.products.update', $product), $this->form([
            'images' => [UploadedFile::fake()->image('6.jpg'), UploadedFile::fake()->image('7.jpg')],
        ]))->assertSessionHasErrors('general');
        $this->assertCount(5, $product->fresh()->images);

        // Remove one and add two: 6.
        $this->actingAs($seller->user)->put(route('seller.products.update', $product), $this->form([
            'remove_images' => [$product->images->first()->id],
            'images' => [UploadedFile::fake()->image('6.jpg'), UploadedFile::fake()->image('7.jpg')],
        ]))->assertRedirect();
        $this->assertCount(6, $product->fresh()->images);
    }

    public function test_a_bad_picture_is_marked_failed_and_never_breaks_saving(): void
    {
        $seller = $this->makeSeller();

        // Looks like a JPEG to the browser, but is not a real picture.
        $fake = UploadedFile::fake()->createWithContent('broken.jpg', 'this is not really an image');

        $this->actingAs($seller->user)->post(route('seller.products.store'), $this->form(['images' => [$fake]]))->assertRedirect();

        // The product is saved; the picture could not be decoded, so it is marked failed and never shown.
        $this->assertSame(1, Product::count());
        $image = Image::sole();
        $this->assertSame('failed', $image->status);
        $this->assertNull($image->path);
        $this->assertSame([], Storage::disk('local')->allFiles('uploads'));
        $this->get(route('products.show', Product::first()))->assertOk();
    }

    public function test_sellers_can_only_edit_their_own_products(): void
    {
        $mine = $this->makeSeller();
        $theirs = $this->makeProduct($this->makeSeller(), ['title' => 'Not yours']);

        $this->actingAs($mine->user)->get(route('seller.products.edit', $theirs))->assertForbidden();
        $this->actingAs($mine->user)->put(route('seller.products.update', $theirs), $this->form(['title' => 'Hacked']))->assertForbidden();
        $this->actingAs($mine->user)->delete(route('seller.products.destroy', $theirs))->assertForbidden();

        $this->assertSame('Not yours', $theirs->fresh()->title);
    }

    public function test_seller_product_list_only_shows_their_own(): void
    {
        $mine = $this->makeSeller();
        $this->makeProduct($mine, ['title' => 'My product']);
        $this->makeProduct($this->makeSeller(), ['title' => 'Their product']);

        $this->actingAs($mine->user)->get(route('seller.products.index'))
            ->assertOk()->assertSee('My product')->assertDontSee('Their product');
    }

    public function test_drafts_are_hidden_from_buyers_but_previewable_by_the_owner(): void
    {
        $seller = $this->makeSeller();
        $draft = $this->makeProduct($seller, ['status' => ProductStatus::Draft, 'title' => 'Secret draft']);

        $this->get(route('products.show', $draft))->assertNotFound();
        $this->get(route('search', ['q' => 'Secret']))->assertDontSee('Secret draft');
        $this->actingAs($seller->user)->get(route('products.show', $draft))->assertOk()->assertSee('Preview');
    }

    public function test_products_with_orders_cannot_be_deleted(): void
    {
        $product = $this->makeProduct();
        $this->paidOrder($product);

        $this->actingAs($product->seller->user)->delete(route('seller.products.destroy', $product))->assertSessionHasErrors('general');
        $this->assertModelExists($product);
    }

    public function test_a_suspended_product_stays_suspended_when_the_seller_edits_it(): void
    {
        $seller = $this->makeSeller();
        $product = $this->makeProduct($seller, ['status' => ProductStatus::Suspended]);

        $this->actingAs($seller->user)->put(route('seller.products.update', $product), $this->form(['status' => 'active']))->assertRedirect();

        $this->assertSame(ProductStatus::Suspended, $product->fresh()->status);
    }

    public function test_admin_can_suspend_and_feature_products(): void
    {
        $product = $this->makeProduct(null, ['title' => 'Featured rice']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('admin.products.feature', $product), ['slot' => 'deal']);
        $this->actingAs($admin)->post(route('admin.products.feature', $product), ['slot' => 'home']);
        $product->refresh();
        $this->assertTrue($product->is_deal);
        $this->assertTrue($product->is_featured);
        $this->get(route('home'))->assertSee('Deals of the day')->assertSee('Featured rice');

        $this->actingAs($admin)->post(route('admin.products.suspend', $product));
        $this->assertSame(ProductStatus::Suspended, $product->fresh()->status);
        $this->assertFalse($product->fresh()->is_deal);
        $this->actingAs($this->buyer())->get(route('products.show', $product))->assertNotFound();

        $this->actingAs($this->buyer())->post(route('admin.products.suspend', $product))->assertForbidden();
    }

    public function test_the_edit_page_does_not_nest_the_delete_form_inside_the_save_form(): void
    {
        $product = $this->makeProduct();

        $html = $this->actingAs($product->seller->user)->get(route('seller.products.edit', $product))->assertOk()->getContent();

        // Browsers ignore a <form> inside a <form>: its hidden "DELETE" would join the save form and
        // "Save product" would delete the product. The save form must close before another form opens.
        $start = strpos($html, '<form method="POST" enctype="multipart/form-data"');
        $end = strpos($html, '</form>', $start);
        $this->assertSame(0, substr_count(substr($html, $start + 5, $end - $start - 5), '<form'));
        $this->assertStringNotContainsString('value="DELETE"', substr($html, $start, $end - $start));
        $this->assertStringContainsString('form="delete-product"', $html);
    }
}
