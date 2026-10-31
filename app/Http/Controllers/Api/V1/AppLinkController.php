<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeferredLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Asked once by the app on its first start: which page did this person want before installing? */
class AppLinkController extends Controller
{
    public function deferred(Request $request, DeferredLinkService $links): JsonResponse
    {
        $path = $links->claim($request);

        parse_str((string) parse_url((string) $path, PHP_URL_QUERY), $query);

        return response()->json([
            'path' => $path ? parse_url($path, PHP_URL_PATH) : null, // e.g. /products/rice-50kg
            'referral_code' => $query['ref'] ?? null,
        ]);
    }
}
