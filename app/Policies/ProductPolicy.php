<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/** A seller can only change their own products. */
class ProductPolicy
{
    public function manage(User $user, Product $product): bool
    {
        return $user->is_admin || ($user->isSeller() && $user->seller->id === $product->seller_id);
    }

    /** Draft, suspended and hidden-shop products are only visible to their owner and admins. */
    public function preview(User $user, Product $product): bool
    {
        return $this->manage($user, $product);
    }
}
