<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellerDeliveryArea extends Model
{
    protected $fillable = ['seller_id', 'district', 'fee', 'min_order_value', 'delivery_window'];

    protected function casts(): array
    {
        return ['fee' => 'decimal:2'];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
