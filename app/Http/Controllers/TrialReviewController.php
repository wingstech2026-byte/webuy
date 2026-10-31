<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Requests\TrialReviewRequest;
use App\Models\OrderItem;
use App\Models\Review;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Services\ImageService;
use App\Services\ReviewService;
use App\Services\TrialService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The honest review (with photos) that free trial testers write. It is marked "Free trial". */
class TrialReviewController extends Controller
{
    public function create(Request $request, TrialCampaign $trialCampaign): View
    {
        [$application, $item] = $this->find($request, $trialCampaign);

        return view('trials.review', ['campaign' => $trialCampaign->load('product'), 'application' => $application, 'item' => $item]);
    }

    public function store(TrialReviewRequest $request, TrialCampaign $trialCampaign, ReviewService $reviews, ImageService $images, TrialService $trials): RedirectResponse
    {
        try {
            $trials->writeReview($request->user(), $trialCampaign, (int) $request->validated('rating'), $request->validated('comment'), $request->file('photos', []));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('products.show', $trialCampaign->product)->with('status', 'Thank you for your honest review!');
    }

    /** @return array{0: TrialApplication, 1: ?OrderItem} */
    private function find(Request $request, TrialCampaign $campaign): array
    {
        $application = TrialApplication::where('trial_campaign_id', $campaign->id)->where('user_id', $request->user()->id)
            ->whereIn('status', ['selected', 'overdue', 'reviewed'])->firstOrFail();

        $item = OrderItem::where('product_id', $campaign->product_id)
            ->whereHas('order', fn ($q) => $q->where('buyer_id', $request->user()->id)->where('status', OrderStatus::Completed))
            ->whereDoesntHave('review')->with('order')->latest('id')->first();

        return [$application, $item];
    }
}
