<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\LiveSession;
use App\Models\Product;
use App\Services\LiveSellingService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The shop's live shows: schedule, go live, pin a product, end. */
class LiveController extends Controller
{
    public function __construct(private LiveSellingService $live) {}

    public function index(Request $request): View
    {
        $seller = $request->user()->seller;

        return view('seller.live', [
            'shows' => LiveSession::where('seller_id', $seller->id)->with('products', 'pinnedProduct')->withCount('comments')->latest('id')->get(),
            'products' => $seller->products()->listed()->orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'stream_url' => ['required', 'url', 'max:300'],
            'starts_at' => ['nullable', 'date'],
            'products' => ['required', 'array', 'max:30'],
            'products.*' => ['integer'],
        ]);

        try {
            $this->live->schedule($request->user()->seller, $data['title'], $data['stream_url'], filled($data['starts_at'] ?? null) ? Carbon::parse($data['starts_at']) : null, $data['products']);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Show saved. Start your live video on YouTube or Facebook, then tap "Go live".');
    }

    public function act(Request $request, LiveSession $liveSession, string $action): RedirectResponse
    {
        abort_unless($liveSession->seller_id === $request->user()->seller->id, 404);

        try {
            match ($action) {
                'start' => $this->live->start($liveSession),
                'pin' => $this->live->pin($liveSession, Product::findOrFail($request->integer('product'))),
                'end' => $this->live->end($liveSession),
            };
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', match ($action) {
            'start' => 'You are live! People who bought from you were told.',
            'pin' => 'Pinned: viewers see it with a Buy button.',
            default => 'Show ended. It stays as a replay.',
        });
    }
}
