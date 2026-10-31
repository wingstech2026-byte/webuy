<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TrialReviewRequest;
use App\Http\Resources\Api\V1\TrialCampaignResource;
use App\Models\TrialApplication;
use App\Models\TrialCampaign;
use App\Services\TrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Free trials: apply, and see the trials you were picked for. */
class TrialController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $mine = TrialApplication::where('user_id', $user->id)->pluck('status', 'trial_campaign_id');
        TrialCampaignResource::$mine = $mine->all();

        $campaigns = TrialCampaign::where(fn ($q) => $q->open()->orWhereIn('id', $mine->keys()))
            ->whereHas('product', fn ($q) => $q->listed())
            ->withCount('applications')->with('product.seller', 'product.images', 'product.tiers')
            ->latest('id')->limit(40)->get();

        return TrialCampaignResource::collection($campaigns);
    }

    /** The tester's review with photos (multipart: rating, comment, photos[]). */
    public function review(TrialReviewRequest $request, TrialCampaign $trialCampaign, TrialService $trials): JsonResponse
    {
        $trials->writeReview($request->user(), $trialCampaign, (int) $request->validated('rating'), $request->validated('comment'), $request->file('photos', []));

        return response()->json(['message' => 'Thank you for your honest review! It shows a "Free trial" label.'], 201);
    }

    public function apply(Request $request, TrialCampaign $trialCampaign, TrialService $trials): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $trials->apply($request->user(), $trialCampaign, $data['reason'] ?? null);

        return response()->json(['message' => 'You applied. The seller picks people soon; we will tell you here.'], 201);
    }
}
