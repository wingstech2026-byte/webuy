<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Services\Assistant\PhotoSearch;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Search by photo on the website: take or choose a photo, see what it is and the matching products. */
class PhotoSearchController extends Controller
{
    public function create(): View
    {
        abort_unless(PhotoSearch::enabled(), 404);

        return view('search-photo', ['result' => null]);
    }

    public function store(Request $request, PhotoSearch $photos): View|RedirectResponse
    {
        abort_unless(PhotoSearch::enabled(), 404);
        $data = $request->validate(['photo' => ['required', 'image', 'max:8192']], [
            'photo.required' => 'Take or choose a photo of the item.',
            'photo.image' => 'That file is not a photo.',
            'photo.max' => 'That photo is too big (8 MB at most).',
        ]);

        try {
            $result = $photos->search($request->user(), $data['photo']);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['photo' => $e->getMessage()]);
        }

        return view('search-photo', ['result' => $result]);
    }
}
