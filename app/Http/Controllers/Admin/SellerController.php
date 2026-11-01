<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SellerStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReasonRequest;
use App\Models\Seller;
use App\Services\LedgerService;
use App\Services\OwnerIdentityService;
use App\Services\SellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Seller applications and shops: approve, reject, verify, suspend. */
class SellerController extends Controller
{
    public function __construct(private SellerService $sellers) {}

    public function index(Request $request)
    {
        $status = SellerStatus::tryFrom((string) $request->query('status', 'pending'));

        $items = Seller::with('user', 'logo')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(20)->withQueryString();

        return view('admin.sellers.index', [
            'items' => $items,
            'status' => $status,
            'counts' => Seller::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function show(Seller $seller, LedgerService $ledger, OwnerIdentityService $identity)
    {
        return view('admin.sellers.show', [
            'seller' => $seller->load('user', 'logo', 'deliveryAreas'),
            'sameIdElsewhere' => $identity->sameIdElsewhere($seller),
            'money' => $ledger->summary($seller),
            'productCount' => $seller->products()->count(),
            'orderCount' => $seller->orders()->count(),
        ]);
    }

    public function approve(Request $request, Seller $seller): RedirectResponse
    {
        try {
            $this->sellers->approve($seller, $request->boolean('verified'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', "{$seller->shop_name} is approved.");
    }

    /** A photo of the owner's ID card, streamed from the private disk; never cached by the browser. */
    public function identityPhoto(Seller $seller, string $side): StreamedResponse
    {
        $path = $side === 'back' ? $seller->owner_id_back_path : $seller->owner_id_front_path;
        abort_unless($path && Storage::disk()->exists($path), 404);

        return Storage::disk()->response($path, "id-{$side}.jpg", [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function reject(ReasonRequest $request, Seller $seller): RedirectResponse
    {
        $this->sellers->reject($seller, $request->validated('reason'));

        return back()->with('status', "{$seller->shop_name} was rejected.");
    }

    public function suspend(Seller $seller): RedirectResponse
    {
        $this->sellers->suspend($seller);

        return back()->with('status', "{$seller->shop_name} is suspended. Its products are hidden.");
    }

    public function verify(Seller $seller): RedirectResponse
    {
        $this->sellers->toggleVerified($seller);

        return back()->with('status', $seller->is_verified ? 'Verified badge added.' : 'Verified badge removed.');
    }

    /** A seller-specific commission rate (blank = use the site-wide rate). */
    public function commission(Request $request, Seller $seller): RedirectResponse
    {
        $data = $request->validate(['commission_percent' => ['nullable', 'numeric', 'min:0', 'max:50', 'decimal:0,2']]);

        $seller->forceFill(['commission_percent' => $data['commission_percent'] ?? null])->save();

        return back()->with('status', 'Commission saved. It applies to new orders only.');
    }
}
