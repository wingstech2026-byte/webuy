<?php

namespace App\Services;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Seller;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Many products at once from a spreadsheet (CSV). Every row is checked first; if any row is wrong
 * nothing is imported and the seller sees each problem with its row number. Products arrive as
 * drafts so the seller can add photos before putting them live.
 */
class ProductImportService
{
    public const COLUMNS = ['title', 'price', 'stock', 'unit', 'min_order_quantity', 'category', 'condition', 'description', 'compare_at_price', 'sku'];

    public const MAX_ROWS = 500;

    public function __construct(private ProductService $products) {}

    /** An example file sellers can download and fill in. */
    public function template(): string
    {
        $rows = [
            self::COLUMNS,
            ['Local rice 50kg bag', '650', '40', 'bag', '1', 'Food', 'new', 'Clean parboiled rice', '700', 'RICE-50'],
            ['Plastic chairs (white)', '120', '200', 'piece', '10', '', 'new', '', '', ''],
        ];

        return collect($rows)->map(fn ($row) => implode(',', array_map(fn ($cell) => str_contains($cell, ',') ? '"'.$cell.'"' : $cell, $row)))->implode("\r\n")."\r\n";
    }

    /**
     * @return array{imported: int, errors: list<string>}
     */
    public function import(Seller $seller, string $path): array
    {
        [$rows, $errors] = $this->read($path);

        if ($errors !== []) {
            return ['imported' => 0, 'errors' => $errors];
        }

        $categories = Category::all(['id', 'name', 'slug']);
        $clean = [];

        foreach ($rows as $line => $row) {
            $validator = Validator::make($row, [
                'title' => ['required', 'string', 'min:3', 'max:120'],
                'price' => ['required', 'decimal:0,2', 'min:0.01', 'max:99999999'],
                'stock' => ['required', 'integer', 'min:0', 'max:10000000'],
                'unit' => ['nullable', 'string', 'max:40'],
                'min_order_quantity' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'condition' => ['nullable', Rule::enum(ProductCondition::class)],
                'description' => ['nullable', 'string', 'max:4000'],
                'compare_at_price' => ['nullable', 'decimal:0,2', 'gt:price', 'max:99999999'],
                'category' => ['nullable', 'string', 'max:80'],
            ]);

            foreach ($validator->errors()->all() as $message) {
                $errors[] = "Row {$line}: {$message}";
            }

            $category = filled($row['category'] ?? null)
                ? $categories->first(fn ($c) => mb_strtolower($c->name) === mb_strtolower(trim($row['category'])) || $c->slug === trim($row['category']))
                : null;

            if (filled($row['category'] ?? null) && ! $category) {
                $errors[] = "Row {$line}: there is no category called \"{$row['category']}\". Leave it empty or use a category name from the website.";
            }

            $clean[$line] = [
                'title' => trim((string) ($row['title'] ?? '')),
                'price' => filled($row['price'] ?? null) ? Money::from($row['price']) : null,
                'stock' => (int) ($row['stock'] ?? 0),
                'unit' => filled($row['unit'] ?? null) ? trim($row['unit']) : 'piece',
                'min_order_quantity' => filled($row['min_order_quantity'] ?? null) ? (int) $row['min_order_quantity'] : 1,
                'category_id' => $category?->id,
                'condition' => filled($row['condition'] ?? null) ? strtolower(trim($row['condition'])) : ProductCondition::New->value,
                'description' => filled($row['description'] ?? null) ? trim($row['description']) : null,
                'compare_at_price' => filled($row['compare_at_price'] ?? null) ? Money::from($row['compare_at_price']) : null,
                'status' => ProductStatus::Draft->value,
            ];
        }

        if ($errors !== []) {
            return ['imported' => 0, 'errors' => array_slice($errors, 0, 50)];
        }

        DB::transaction(function () use ($seller, $clean) {
            foreach ($clean as $data) {
                $this->products->save($seller, $data);
            }
        });

        return ['imported' => count($clean), 'errors' => []];
    }

    /**
     * Rows keyed by spreadsheet row number (the header is row 1).
     *
     * @return array{0: array<int, array<string, string>>, 1: list<string>}
     */
    private function read(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [[], ['The file could not be read.']];
        }

        $first = fgets($handle);
        rewind($handle);
        // Spreadsheets saved in some countries use ";" between columns.
        $delimiter = $first !== false && substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        $header = fgetcsv($handle, 0, $delimiter, '"', '');
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header ?: []);

        if (! in_array('title', $header, true) || ! in_array('price', $header, true) || ! in_array('stock', $header, true)) {
            fclose($handle);

            return [[], ['The first row must be the column names. Download the example file and keep its first row.']];
        }

        $rows = [];
        $line = 1;

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;

            if ($cells === [null] || implode('', $cells) === '') {
                continue; // empty line
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                return [[], ['A file can have up to '.self::MAX_ROWS.' products. Split it into smaller files.']];
            }

            $rows[$line] = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
        }

        fclose($handle);

        return $rows === [] ? [[], ['The file has no products in it.']] : [$rows, []];
    }
}
