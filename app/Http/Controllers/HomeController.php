<?php

namespace App\Http\Controllers;

use App\Services\SearchService;
use App\Support\BrowsingHistory;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(SearchService $search, Request $request)
    {
        return view('home', [
            'recent' => (new BrowsingHistory($request->session()))->recent(),
            'categories' => $search->categoryTree(),
            'deals' => $search->deals(),
            'featured' => $search->featured(),
            'newArrivals' => $search->newArrivals(),
            'topSellers' => $search->topSellers(),
        ]);
    }
}
