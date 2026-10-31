<?php

namespace Tests\Feature;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Seller;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\Marketplace;
use Tests\TestCase;

class ProductImportTest extends TestCase
{
    use Marketplace;

    private ?Seller $seller = null;

    private function upload(string $csv): TestResponse
    {
        $seller = $this->seller ??= $this->makeSeller();

        return $this->actingAs($seller->user)->post(route('seller.products.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('products.csv', $csv),
        ]);
    }

    public function test_a_seller_adds_many_products_as_drafts_from_a_csv(): void
    {
        Category::factory()->create(['name' => 'Food', 'slug' => 'food']);

        $this->upload("\xEF\xBB\xBFtitle,price,stock,unit,min_order_quantity,category,condition,description\n"
            ."Local rice 50kg bag,650,40,bag,1,food,new,\"Clean, parboiled\"\n"
            ."\n"
            ."Plastic chairs,120.50,200,,10,,,\n")
            ->assertRedirect(route('seller.products.index'));

        $products = Product::orderBy('id')->get();
        $this->assertSame(['Local rice 50kg bag', 'Plastic chairs'], $products->pluck('title')->all());
        $this->assertSame('120.50', $products[1]->price);
        $this->assertSame('piece', $products[1]->unit);
        $this->assertSame('Clean, parboiled', $products[0]->description);
        $this->assertNotNull($products[0]->category_id);
        $this->assertTrue($products->every(fn ($p) => $p->status === ProductStatus::Draft && $p->seller_id === $this->seller->id));
    }

    public function test_semicolon_files_from_some_spreadsheets_work(): void
    {
        $this->upload("title;price;stock\nOil 5L;95;10\n")->assertRedirect(route('seller.products.index'));

        $this->assertSame('95.00', Product::sole()->price);
    }

    public function test_one_bad_row_imports_nothing_and_names_the_row(): void
    {
        $this->upload("title,price,stock,category\nGood item,10,1,\nBad item,-5,1,\nOther,10,abc,Nonsense\n")
            ->assertSessionHas('import_errors', fn ($errors) => collect($errors)->contains(fn ($e) => str_starts_with($e, 'Row 3:'))
                && collect($errors)->contains(fn ($e) => str_contains($e, 'Row 4: there is no category called "Nonsense"')));

        $this->assertSame(0, Product::count());
    }

    public function test_a_file_without_the_column_names_is_refused(): void
    {
        $this->upload("Rice,650,40\n")->assertSessionHas('import_errors', ['The first row must be the column names. Download the example file and keep its first row.']);
        $this->assertSame(0, Product::count());
    }

    public function test_the_example_file_can_be_downloaded_and_imported_back(): void
    {
        $seller = $this->seller = $this->makeSeller();
        $csv = $this->actingAs($seller->user)->get(route('seller.products.import.template'))
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        Category::factory()->create(['name' => 'Food', 'slug' => 'food']);

        $this->upload($csv)->assertRedirect(route('seller.products.index'));
        $this->assertSame(2, Product::count());
    }
}
