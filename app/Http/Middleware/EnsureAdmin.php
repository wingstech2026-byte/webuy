<?php

namespace App\Http\Middleware;

use App\Support\AdminAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Admins only, and only the pages their role allows (AdminAccess). */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_admin, 403, 'Admins only.');
        abort_unless(AdminAccess::allows($request->user(), (string) $request->route()?->getName()), 403, 'Your admin role does not include this page.');

        return $next($request);
    }
}
