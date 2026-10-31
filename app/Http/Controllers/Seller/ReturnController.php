<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The seller answers a return: accept (with instructions), refuse (WiBuy decides), or confirm it came back. */
class ReturnController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    public function accept(Request $request, ReturnRequest $returnRequest): RedirectResponse
    {
        $data = $request->validate(['instructions' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->run($returnRequest, fn () => $this->returns->accept($returnRequest, $request->user(), $data['instructions']), 'Return accepted. We sent the buyer your instructions.');
    }

    public function refuse(Request $request, ReturnRequest $returnRequest): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']]);

        return $this->run($returnRequest, fn () => $this->returns->refuse($returnRequest, $request->user(), $data['note']), 'Return refused. The WiBuy team will look at it and decide.');
    }

    public function received(Request $request, ReturnRequest $returnRequest): RedirectResponse
    {
        return $this->run($returnRequest, fn () => $this->returns->received($returnRequest, $request->user()), 'Thanks. The stock is back and the buyer is being refunded.');
    }

    private function run(ReturnRequest $returnRequest, callable $action, string $done): RedirectResponse
    {
        abort_unless($returnRequest->seller_id === request()->user()->seller?->id, 404);

        try {
            $action();
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('seller.orders.show', $returnRequest->order_id)->with('status', $done);
    }
}
