<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** "Rice + oil + onions together for Le 250": the bundle price applies when all its items are in the cart. */
class Bundle extends Model
{
    protected $fillable = ['seller_id', 'title', 'price', 'is_active'];

    protected function casts(): array
    {
        return ['price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'bundle_items')->withPivot('quantity');
    }

    /** What the items cost one by one (today's prices). */
    public function normalPrice(): string
    {
        return Money::sum($this->products->map(fn (Product $p) => Money::mul($p->price, (int) $p->pivot->quantity)));
    }
}
