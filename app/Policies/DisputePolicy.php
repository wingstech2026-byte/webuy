<?php

namespace App\Policies;

use App\Models\Dispute;
use App\Models\User;

class DisputePolicy
{
    /** The buyer, the seller and admins. */
    public function view(User $user, Dispute $dispute): bool
    {
        return $user->is_admin
            || $dispute->buyer_id === $user->id
            || ($user->isSeller() && $user->seller->id === $dispute->seller_id);
    }

    public function respond(User $user, Dispute $dispute): bool
    {
        return $user->isSeller() && $user->seller->id === $dispute->seller_id;
    }
}
