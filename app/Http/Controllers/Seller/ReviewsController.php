<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\Request;

class ReviewsController extends Controller
{
    public function __invoke(Request $request)
    {
        $seller = $request->user()->seller;

        return view('seller.reviews', [
            'seller' => $seller,
            'shopReviews' => $seller->reviews()->with('buyer')->paginate(10, ['*'], 'shop')->withQueryString(),
            'productReviews' => Review::where('seller_id', $seller->id)->with('buyer', 'product')->latest('id')->paginate(10, ['*'], 'products')->withQueryString(),
        ]);
    }
}
