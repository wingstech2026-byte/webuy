<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** English, or Krio when the visitor chose it in the footer (a cookie). Krio words are in lang/kri.json. */
class SetLanguage
{
    public const LANGUAGES = ['en' => 'English', 'kri' => 'Krio'];

    public function handle(Request $request, Closure $next): Response
    {
        $language = $request->cookie('language');

        // Always set it, so a language never carries over from an earlier request on a long-running server.
        // (Not config('app.locale'): setLocale() overwrites that value.)
        app()->setLocale(is_string($language) && array_key_exists($language, self::LANGUAGES) ? $language : 'en');

        return $next($request);
    }
}
