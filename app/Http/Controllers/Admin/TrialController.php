<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Services\TrialService;
use Illuminate\Http\RedirectResponse;

/** Free trials across all sellers. Admins can pick testers too (e.g. when a seller is slow). */
class TrialController extends Controller
{
    public function index()
    {
        return view('admin.rewards.trials', [
            'campaigns' => TrialCampaign::with(['product', 'seller', 'applications.user'])->latest('id')->paginate(20),
        ]);
    }

    public function select(TrialApplication $trialApplication, TrialService $trials): RedirectResponse
    {
        try {
            $trials->select($trialApplication);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return back()->with('status', 'Tester selected. They got a coupon for the trial price.');
    }

    public function reject(TrialApplication $trialApplication, TrialService $trials): RedirectResponse
    {
        $trials->reject($trialApplication);

        return back()->with('status', 'Application declined.');
    }

    public function close(TrialCampaign $trialCampaign): RedirectResponse
    {
        $trialCampaign->update(['status' => 'closed']);

        return back()->with('status', 'Free trial closed.');
    }
}
