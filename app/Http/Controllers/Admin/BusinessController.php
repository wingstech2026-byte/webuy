<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use App\Services\BusinessService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Verify business buyers from their registration papers. */
class BusinessController extends Controller
{
    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['pending', 'verified', 'rejected'], true) ? $request->query('status') : 'pending';

        return view('admin.business.index', [
            'items' => BusinessProfile::where('status', $status)->with('user', 'documents')->latest('id')->paginate(20)->withQueryString(),
            'status' => $status,
        ]);
    }

    public function verify(BusinessProfile $businessProfile, BusinessService $business): RedirectResponse
    {
        $business->verify($businessProfile);

        return back()->with('status', "{$businessProfile->business_name} is verified. They now see business prices.");
    }

    public function reject(Request $request, BusinessProfile $businessProfile, BusinessService $business): RedirectResponse
    {
        $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $business->reject($businessProfile, $request->input('reason'));

        return back()->with('status', 'Business application rejected.');
    }
}
