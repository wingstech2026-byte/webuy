<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Money, shop settings and helpers: the shop owner only (helpers manage orders and products). */
class EnsureShopOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isShopOwner(), 403, 'Only the shop owner can do this. Helpers can manage orders and products.');

        return $next($request);
    }
}
