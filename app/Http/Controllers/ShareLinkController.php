<?php

namespace App\Http\Controllers;

use App\Models\Share;
use App\Services\DeferredLinkService;
use App\Services\DeviceService;
use App\Services\ShareService;
use App\Support\RewardSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Shared WhatsApp links, and the "Get the app" button that keeps the page for after installing. */
class ShareLinkController extends Controller
{
    public function open(Request $request, Share $share, ShareService $shares, DeviceService $devices): RedirectResponse
    {
        $shares->open($share, $request->user(), $devices->hashFromRequest($request));

        if ($share->user->referral_code) {
            $request->session()->put('referral_code', $share->user->referral_code);
        }

        return redirect()->to($shares->destination($share));
    }

    /**
     * "Get Le X off your first order in the WiBuy app": remember the page (deferred deep link)
     * and send the person to the Play Store with the page as the install referrer.
     */
    public function getApp(Request $request, DeferredLinkService $links): RedirectResponse
    {
        $path = (string) $request->query('path', '/');

        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }

        if ($ref = $request->session()->get('referral_code')) {
            $path .= (str_contains($path, '?') ? '&' : '?').'ref='.urlencode($ref);
        }

        $links->remember($request, $path);

        $store = RewardSettings::text('app_store_url');

        return redirect()->away($store.(str_contains($store, '?') ? '&' : '?').'referrer='.rawurlencode('path='.$path));
    }
}
