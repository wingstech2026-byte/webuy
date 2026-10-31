<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\SearchService;
use Illuminate\Http\Request;

/** Search results and category pages, with filters and sorting. */
class BrowseController extends Controller
{
    public function __invoke(Request $request, SearchService $search, ?Category $category = null)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:80'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'district' => ['nullable', 'string', 'max:40'],
            'verified' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:'.implode(',', array_keys(SearchService::SORTS))],
        ]);

        if ($category) {
            $filters['category'] = $category->slug;
        } elseif (! empty($filters['category'])) {
            $category = Category::where('slug', $filters['category'])->first();
        }

        return view('browse', [
            'products' => $search->search($filters),
            'filters' => $filters,
            'category' => $category,
            'categories' => $search->categoryTree(),
            'districts' => config('wibuy.districts'),
            'sorts' => SearchService::SORTS,
        ]);
    }
}
