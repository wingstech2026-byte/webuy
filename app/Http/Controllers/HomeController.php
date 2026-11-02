<?php

namespace App\Http\Controllers;

use App\Services\RecommendationService;
use App\Services\SearchService;
use App\Services\SponsoredProductService;
use App\Support\BrowsingHistory;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(SearchService $search, Request $request, RecommendationService $recommendations)
    {
        $recent = (new BrowsingHistory($request->session()))->recent();

        return view('home', [
            'recent' => $recent,
            // Empty until there is something to go on (a product looked at or bought).
            'forYou' => $recommendations->forYou($request->user(), $recent->pluck('id')->all(), 8),
            'categories' => $search->categoryTree(),
            'deals' => $search->deals(),
            'sponsored' => app(SponsoredProductService::class)->forHome(),
            'featured' => $search->featured(),
            'newArrivals' => $search->newArrivals(),
            'topSellers' => $search->topSellers(),
        ]);
    }
}
