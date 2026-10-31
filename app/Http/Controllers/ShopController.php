<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Services\SearchService;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    public function show(Request $request, Seller $seller, SearchService $search)
    {
        $user = $request->user();

        // Shops waiting for approval, rejected or suspended are not public.
        if (! $seller->isApproved() && ! ($user && ($user->is_admin || $user->id === $seller->user_id))) {
            abort(404);
        }

        $sort = in_array($request->query('sort'), array_keys(SearchService::SORTS), true) ? $request->query('sort') : 'newest';

        return view('shops.show', [
            'seller' => $seller->load('logo'),
            'products' => $search->search(['seller' => $seller->id, 'sort' => $sort, 'q' => $request->query('q')], 12),
            'reviews' => $seller->reviews()->with('buyer')->limit(10)->get(),
            'sorts' => SearchService::SORTS,
            'sort' => $sort,
        ]);
    }
}
