<?php

namespace App\Http\Controllers;

use App\Services\SearchService;

class HomeController extends Controller
{
    public function __invoke(SearchService $search)
    {
        return view('home', [
            'categories' => $search->categoryTree(),
            'deals' => $search->deals(),
            'featured' => $search->featured(),
            'newArrivals' => $search->newArrivals(),
            'topSellers' => $search->topSellers(),
        ]);
    }
}
