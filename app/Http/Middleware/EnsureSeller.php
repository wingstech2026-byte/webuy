<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only people with an approved shop get into /seller. Everyone else is sent to apply (or wait). */
class EnsureSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isSeller()) {
            return redirect()->route('sell.apply')->with('status', $user?->seller
                ? 'Your shop is "'.$user->seller->status->label().'". You can use the seller dashboard once it is approved.'
                : 'Apply to open a shop first.');
        }

        return $next($request);
    }
}
