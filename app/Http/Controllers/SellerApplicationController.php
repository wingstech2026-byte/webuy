<?php

namespace App\Http\Controllers;

use App\Enums\IdentityDocument;
use App\Enums\Operator;
use App\Enums\SellerStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\OwnerIdentityRequest;
use App\Http\Requests\SellerApplicationRequest;
use App\Services\OwnerIdentityService;
use App\Services\SellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Any buyer can apply to open a shop, with the owner's identity document (ID card or passport). An admin checks it and approves the shop. */
class SellerApplicationController extends Controller
{
    public function create(Request $request)
    {
        $seller = $request->user()->seller;

        return view('sell.apply', [
            'seller' => $seller?->load('logo'),
            'districts' => config('wibuy.districts'),
            'operators' => Operator::cases(),
        ]);
    }

    public function store(SellerApplicationRequest $request, SellerService $sellers, OwnerIdentityService $identity): RedirectResponse
    {
        $user = $request->user();
        $data = collect($request->validated())->except(['logo', 'owner_id_number', 'owner_id_type', 'owner_nationality', 'owner_id_front', 'owner_id_back'])->all();

        try {
            DB::transaction(function () use ($user, $data, $request, $sellers, $identity) {
                $existing = $user->seller()->first();

                $seller = $existing && $existing->status === SellerStatus::Rejected
                    ? $sellers->reapply($existing, $data, $request->file('logo'))
                    : $sellers->apply($user, $data, $request->file('logo'));

                // The owner's identity document (optional on a new application only when one is already on file).
                if ($request->filled('owner_id_number')) {
                    $identity->store($seller, $request->validated('owner_id_number'), IdentityDocument::from($request->validated('owner_id_type')),
                        $request->validated('owner_nationality'), $request->file('owner_id_front'), $request->file('owner_id_back'));
                }
            });
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('sell.apply')
            ->with('status', 'Thank you! We will check your application and text you when it is approved.');
    }

    /** Send (or replace) the identity document after applying: shops that applied before it was needed, or a blurry photo. */
    public function identity(OwnerIdentityRequest $request, OwnerIdentityService $identity): RedirectResponse
    {
        try {
            $identity->store($request->user()->seller, $request->validated('owner_id_number'), IdentityDocument::from($request->validated('owner_id_type')),
                $request->validated('owner_nationality'), $request->file('owner_id_front'), $request->file('owner_id_back'));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('sell.apply')->with('status', 'Thank you! We received your ID card and will check it.');
    }
}
