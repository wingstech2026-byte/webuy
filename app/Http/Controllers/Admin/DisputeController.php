<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DisputeStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ResolveDisputeRequest;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DisputeController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status', 'open');

        $items = Dispute::with('order', 'buyer', 'seller')
            ->when($status !== 'all', fn ($q) => $q->where('status', DisputeStatus::tryFrom((string) $status) ?? DisputeStatus::Open))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->latest('id')
            ->paginate(20)->withQueryString();

        return view('admin.disputes.index', ['items' => $items, 'status' => $status]);
    }

    public function resolve(ResolveDisputeRequest $request, Dispute $dispute, DisputeService $disputes): RedirectResponse
    {
        try {
            $disputes->resolve($dispute, $request->user(), $request->validated('decision'), $request->validated('note'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('admin.disputes.index')->with('status', 'Decision saved. Everyone was told.');
    }
}
