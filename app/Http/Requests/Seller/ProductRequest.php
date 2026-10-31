<?php

namespace App\Http\Requests\Seller;

use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Http\Requests\Concerns\ValidatesImages;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    use ValidatesImages;

    public function authorize(): bool
    {
        return $this->user()?->isSeller() ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Ignore blank tier rows (the form always shows a few empty ones).
        $tiers = collect($this->input('tiers', []))
            ->filter(fn ($t) => is_array($t) && (filled($t['min_quantity'] ?? null) || filled($t['unit_price'] ?? null)))
            ->values()->all();

        // Blank option rows too; a row with a name is kept.
        $variants = collect($this->input('variants', []))
            ->filter(fn ($v) => is_array($v) && filled($v['name'] ?? null))
            ->values()->all();

        $this->merge(['tiers' => $tiers, 'variants' => $variants]);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:120'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'description' => ['nullable', 'string', 'max:4000'],
            'price' => ['required', 'decimal:0,2', 'min:0.01', 'max:99999999'],
            'compare_at_price' => ['nullable', 'decimal:0,2', 'gt:price', 'max:99999999'],
            'member_price' => ['nullable', 'decimal:0,2', 'min:0.01', 'lt:price'],
            'stock' => ['required', 'integer', 'min:0', 'max:10000000'],
            'unit' => ['required', 'string', 'max:40'],
            'min_order_quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            // Sellers choose draft or live. Suspending is an admin decision.
            'status' => ['required', Rule::in([ProductStatus::Draft->value, ProductStatus::Active->value])],

            'tiers' => ['array', 'max:6'],
            'tiers.*.min_quantity' => ['required', 'integer', 'min:2', 'max:1000000'],
            'tiers.*.unit_price' => ['required', 'decimal:0,2', 'min:0.01'],
            'tiers.*.business_only' => ['nullable', 'boolean'],
            'lead_time_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'sample_price' => ['nullable', 'decimal:0,2', 'min:0.01'],

            // Options such as size or colour, each with its own stock (and price, if different).
            'variants' => ['array', 'max:30'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:80', 'distinct:ignore_case'],
            'variants.*.price' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:99999999'],
            'variants.*.stock' => ['required', 'integer', 'min:0', 'max:10000000'],

            'images' => ['nullable', 'array', 'max:'.config('wibuy.max_product_images')],
            'images.*' => $this->imageRules(),
            'remove_images' => ['nullable', 'array'],
            'remove_images.*' => ['integer'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $price = (string) $this->input('price');
            $tiers = collect($this->input('tiers', []))->sortBy(fn ($t) => (int) $t['min_quantity'])->values();

            if ($tiers->pluck('min_quantity')->map(fn ($q) => (int) $q)->duplicates()->isNotEmpty()) {
                $validator->errors()->add('tiers', 'Each wholesale tier needs a different quantity.');

                return;
            }

            // Buying more must never cost more per unit.
            $previous = $price;

            foreach ($tiers as $tier) {
                if (Money::cmp($tier['unit_price'], $previous) >= 0) {
                    $validator->errors()->add('tiers', 'Wholesale prices must go down as the quantity goes up, and be lower than the retail price.');

                    return;
                }

                $previous = $tier['unit_price'];
            }
        }];
    }

    /** Tiers as a clean list sorted by quantity. */
    public function tiers(): array
    {
        return collect($this->validated('tiers', []))
            ->map(fn ($t) => ['min_quantity' => (int) $t['min_quantity'], 'unit_price' => Money::from($t['unit_price']), 'business_only' => (bool) ($t['business_only'] ?? false)])
            ->sortBy('min_quantity')->values()->all();
    }

    /** @return list<array{id: ?int, name: string, price: ?string, stock: int}> option rows, in form order */
    public function variants(): array
    {
        return collect($this->validated('variants', []))->map(fn ($v) => [
            'id' => isset($v['id']) ? (int) $v['id'] : null,
            'name' => trim($v['name']),
            'price' => filled($v['price'] ?? null) ? Money::from($v['price']) : null,
            'stock' => (int) $v['stock'],
        ])->values()->all();
    }

    public function productData(): array
    {
        return collect($this->validated())->only([
            'title', 'category_id', 'description', 'price', 'compare_at_price', 'member_price', 'lead_time_days', 'sample_price', 'stock', 'unit',
            'min_order_quantity', 'condition', 'status',
        ])->all();
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please enter a product title.',
            'price.required' => 'Please enter the price.',
            'price.decimal' => 'Enter the price as an amount like 150 or 150.50.',
            'compare_at_price.gt' => 'The old price must be higher than the price.',
            'member_price.lt' => 'The WiBuy Pass price must be lower than the normal price.',
            'images.max' => 'A product can have up to '.config('wibuy.max_product_images').' pictures.',
            'tiers.*.min_quantity.min' => 'A wholesale tier starts at 2 units or more.',
            'variants.*.name.distinct' => 'Each option needs a different name.',
            'variants.*.stock.required' => 'Enter how many you have of each option.',
        ] + $this->imageMessages('images.*', 'picture');
    }
}
