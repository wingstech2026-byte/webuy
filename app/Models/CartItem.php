<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['cart_id', 'product_id', 'variant_id', 'quantity', 'addon_key'];

    /** @return list<int> the chosen add-on ids */
    public function addonIds(): array
    {
        return $this->addon_key === '' || $this->addon_key === null ? [] : array_map('intval', explode(',', $this->addon_key));
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** The chosen option (size, colour...) when the product has variants. */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
