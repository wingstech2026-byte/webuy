<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

/** Seller tools in the app: product options (size, colour) and bulk upload. */
class SellerProductToolsApiTest extends TestCase
{
    use Marketplace;

    private function product(array $fields): array
    {
        return $fields + ['title' => 'Ankara dress', 'price' => '150', 'stock' => '1', 'unit' => 'piece', 'min_order_quantity' => '1', 'condition' => 'new', 'status' => 'active'];
    }

    public function test_a_seller_saves_options_from_the_app_and_older_apps_leave_them_alone(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $id = $this->postJson(route('api.v1.seller.products.store'), $this->product([
            'variants_sent' => '1',
            'variants' => [['name' => 'Small', 'price' => '', 'stock' => '3'], ['name' => 'Large', 'price' => '180', 'stock' => '2'], ['name' => '', 'stock' => '']],
        ]))->assertCreated()
            ->assertJsonPath('data.variants.1.name', 'Large')
            ->assertJsonPath('data.variants.1.price', '180.00')
            ->assertJsonPath('data.stock', 5)
            ->json('data.id');
        $product = Product::findOrFail($id);
        $small = ProductVariant::where('name', 'Small')->sole();

        // An app without options support sends no variants_sent: the options stay.
        $this->postJson(route('api.v1.seller.products.update', $product), $this->product(['title' => 'Ankara dress (new)']))->assertOk();
        $this->assertSame(2, $product->variants()->count());

        // Rename keeps the same option by id; leaving one out removes it.
        $this->postJson(route('api.v1.seller.products.update', $product), $this->product([
            'variants_sent' => '1', 'variants' => [['id' => $small->id, 'name' => 'S', 'stock' => '4']],
        ]))->assertOk()->assertJsonPath('data.variants.0.id', $small->id)->assertJsonPath('data.stock', 4);
        $this->assertSame(['S'], $product->variants()->pluck('name')->all());

        // Sending an empty list switches options off.
        $this->postJson(route('api.v1.seller.products.update', $product), $this->product(['variants_sent' => '1', 'stock' => '7']))
            ->assertOk()->assertJsonCount(0, 'data.variants')->assertJsonPath('data.stock', 7);
    }

    public function test_a_seller_bulk_uploads_pasted_rows_or_a_file_from_the_app(): void
    {
        $seller = $this->makeSeller();
        Sanctum::actingAs($seller->user);

        $this->getJson(route('api.v1.seller.products.import-template'))->assertOk()->assertJsonPath('columns.0', 'title')->assertJsonPath('max_rows', 500);

        // Cells copied from a spreadsheet arrive separated by tabs.
        $this->postJson(route('api.v1.seller.products.import'), ['csv' => "title\tprice\tstock\nOil 5L\t95\t10\nRice 25kg\t320\t4\n"])
            ->assertCreated()->assertJsonPath('imported', 2);
        $this->assertTrue(Product::all()->every(fn ($p) => $p->status === ProductStatus::Draft && $p->seller_id === $seller->id));

        $this->post(route('api.v1.seller.products.import'), ['file' => UploadedFile::fake()->createWithContent('p.csv', "title,price,stock\nSoap,5,100\n")], ['Accept' => 'application/json'])
            ->assertCreated();

        $this->postJson(route('api.v1.seller.products.import'), ['csv' => "title,price,stock\nGood,10,1\nBad,-5,1\n"])
            ->assertStatus(422)->assertJsonPath('errors.rows.0', fn ($e) => str_starts_with($e, 'Row 3:'));
        $this->assertSame(3, Product::count());

        $this->postJson(route('api.v1.seller.products.import'), [])->assertJsonValidationErrors('csv');

        Sanctum::actingAs($this->buyer());
        $this->postJson(route('api.v1.seller.products.import'), ['csv' => "title,price,stock\nX,1,1\n"])->assertForbidden();
    }
}
