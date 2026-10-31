<?php

namespace App\Services;

use App\Models\DeferredLink;
use Illuminate\Http\Request;

/**
 * Deferred deep links: someone taps "Get the app" on a product page, installs the app, and the app
 * opens that same product on its first start. The website remembers the page for a short time by the
 * visitor's network address; the app asks for it once. The Play Store link also carries the page as
 * the install referrer.
 */
class DeferredLinkService
{
    private const MINUTES = 120;

    public function remember(Request $request, string $path): void
    {
        DeferredLink::create([
            'fingerprint' => $this->fingerprint($request),
            'path' => $path,
            'expires_at' => now()->addMinutes(self::MINUTES),
        ]);
    }

    /** The page this visitor wanted before installing, once. */
    public function claim(Request $request): ?string
    {
        $link = DeferredLink::where('fingerprint', $this->fingerprint($request))->whereNull('claimed_at')
            ->where('expires_at', '>', now())->latest('id')->first();

        $link?->forceFill(['claimed_at' => now()])->save();

        return $link?->path;
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', 'wibuy-deferred|'.$request->ip());
    }
}
