<?php

namespace App\Policies;

use App\Models\Checkout;
use App\Models\User;

class CheckoutPolicy
{
    public function view(User $user, Checkout $checkout): bool
    {
        return $user->is_admin || $checkout->buyer_id === $user->id;
    }
}
