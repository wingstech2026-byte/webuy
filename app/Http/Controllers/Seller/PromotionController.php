<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Admin\PriceCutCampaignController;
use App\Http\Controllers\Controller;
use App\Models\PriceCutCampaign;
use App\Models\Product;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Services\TrialService;
use App\Support\RewardSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A seller's own promotions: "cut the price" products and free trials (and picking testers). */
class PromotionController extends Controller
{
    public function index(Request $request)
    {
        $seller = $request->user()->seller;

        return view('seller.promotions', [
            'products' => $seller->products()->listed()->orderBy('title')->get(['id', 'title', 'slug', 'price']),
            'cuts' => PriceCutCampaign::whereIn('product_id', $seller->products()->select('id'))->with('product')->withCount('cuts')->latest('id')->get(),
            'trials' => TrialCampaign::where('seller_id', $seller->id)->with(['product', 'applications.user'])->latest('id')->get(),
            'offers' => $seller->offers()->with('product')->latest('id')->get(),
            'bundles' => $seller->bundles()->with('products')->latest('id')->get(),
        ]);
    }

    public function storeCut(Request $request): RedirectResponse
    {
        $data = PriceCutCampaignController::validateCampaign($request);
        $product = $this->ownProduct($request, $data['product']);

        if ($error = PriceCutCampaignController::checkPrices($data, $product)) {
            return back()->withInput()->withErrors($error);
        }

        PriceCutCampaign::create(PriceCutCampaignController::attributes($data, $product, $request->user()->id));

        return back()->with('status', 'Price cut added. Buyers can now ask friends to cut the price. You get your normal price; WiBuy pays the difference.');
    }

    public function toggleCut(Request $request, PriceCutCampaign $priceCutCampaign): RedirectResponse
    {
        abort_unless($priceCutCampaign->product->seller_id === $request->user()->seller->id, 404);

        $priceCutCampaign->update(['is_active' => ! $priceCutCampaign->is_active]);

        return back()->with('status', $priceCutCampaign->is_active ? 'Price cut switched on.' : 'Price cut switched off.');
    }

    public function storeTrial(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'product' => ['required', 'string', 'max:120'],
            'trial_price' => ['required', 'numeric', 'min:0'],
            'slots' => ['required', 'integer', 'min:1', 'max:100'],
            'days_open' => ['required', 'integer', 'min:1', 'max:60'],
        ]);
        $product = $this->ownProduct($request, $data['product']);

        if ((float) $data['trial_price'] >= (float) $product->price) {
            return back()->withInput()->withErrors(['trial_price' => 'The trial price must be below the normal price.']);
        }

        TrialCampaign::create([
            'product_id' => $product->id,
            'seller_id' => $product->seller_id,
            'trial_price' => $data['trial_price'],
            'slots' => $data['slots'],
            'apply_until' => now()->addDays((int) $data['days_open']),
            'review_days' => RewardSettings::int('trial_review_days'),
        ]);

        return back()->with('status', 'Free trial created. Pick testers here when people apply.');
    }

    public function select(Request $request, TrialApplication $trialApplication, TrialService $trials): RedirectResponse
    {
        $this->ownApplication($request, $trialApplication);

        try {
            $trials->select($trialApplication);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Tester selected. They got a coupon so the product costs the trial price.');
    }

    public function reject(Request $request, TrialApplication $trialApplication, TrialService $trials): RedirectResponse
    {
        $this->ownApplication($request, $trialApplication);
        $trials->reject($trialApplication);

        return back()->with('status', 'Application declined.');
    }

    private function ownProduct(Request $request, string $slug): Product
    {
        return $request->user()->seller->products()->where('slug', $slug)->firstOr(fn () => abort(back()->withInput()->withErrors(['product' => 'Choose one of your products.'])));
    }

    private function ownApplication(Request $request, TrialApplication $application): void
    {
        abort_unless($application->campaign->seller_id === $request->user()->seller->id, 404);
    }
}
