<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Models\SellerReview;
use App\Services\ReviewService;
use Illuminate\Http\RedirectResponse;
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

    /** Answer a review in public (shop review or product review). */
    public function reply(Request $request, string $kind, int $id, ReviewService $reviews): RedirectResponse
    {
        $data = $request->validate(['reply' => ['required', 'string', 'max:1000']]);
        $review = $kind === 'shop' ? SellerReview::findOrFail($id) : Review::findOrFail($id);

        try {
            $reviews->reply($review, $request->user(), $data['reply']);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['reply' => $e->getMessage()]);
        }

        return back()->with('status', 'Reply posted. The buyer was told.');
    }
}
