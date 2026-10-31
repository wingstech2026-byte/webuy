<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Seller\DisputeResponseRequest;
use App\Models\Dispute;
use App\Services\DisputeService;
use Illuminate\Http\RedirectResponse;

class DisputeController extends Controller
{
    public function respond(DisputeResponseRequest $request, Dispute $dispute, DisputeService $disputes): RedirectResponse
    {
        $this->authorize('respond', $dispute);

        try {
            $disputes->respond($dispute, $request->user(), $request->validated('response'), $request->file('photos', []));
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('disputes.show', $dispute)->with('status', 'Your reply was sent. An admin will decide.');
    }
}
