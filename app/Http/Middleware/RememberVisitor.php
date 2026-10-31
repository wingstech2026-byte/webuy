<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website visitors get a random device id cookie (used against fake accounts in price cuts and
 * referrals), and a ?ref=CODE from a shared link is kept until they sign up.
 */
class RememberVisitor
{
    public const DEVICE_COOKIE = 'wibuy_device';

    public function handle(Request $request, Closure $next): Response
    {
        if ($ref = $request->query('ref')) {
            $request->session()->put('referral_code', Str::upper(Str::limit((string) $ref, 12, '')));
        }

        if (! $request->cookie(self::DEVICE_COOKIE)) {
            $id = (string) Str::uuid();
            $request->cookies->set(self::DEVICE_COOKIE, $id); // usable during this request too
            Cookie::queue(self::DEVICE_COOKIE, $id, 60 * 24 * 730);
        }

        return $next($request);
    }
}
