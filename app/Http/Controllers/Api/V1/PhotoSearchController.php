<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Services\Assistant\PhotoSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Search by photo in the app (PHOTO_SEARCH_ENABLED). Multipart: photo. The Claude call happens on the server. */
class PhotoSearchController extends Controller
{
    public function __invoke(Request $request, PhotoSearch $photos): JsonResponse
    {
        abort_unless(PhotoSearch::enabled(), 404);
        $data = $request->validate(['photo' => ['required', 'image', 'max:8192']], [
            'photo.required' => 'Take or choose a photo of the item.',
            'photo.image' => 'That file is not a photo.',
            'photo.max' => 'That photo is too big (8 MB at most).',
        ]);

        $result = $photos->search($request->user(), $data['photo']);

        return response()->json([
            'understood' => $result['understood'],
            'products' => ProductResource::collection($result['products'])->resolve($request),
            'left_today' => $result['left_today'],
        ]);
    }
}
