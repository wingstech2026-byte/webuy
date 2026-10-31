<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Services\BusinessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Register a business buyer account with the registration papers. The app opens this page. */
class BusinessAccountController extends Controller
{
    public function create(Request $request): View
    {
        return view('business.create', ['profile' => $request->user()->businessProfile, 'districts' => config('wibuy.districts')]);
    }

    public function store(Request $request, BusinessService $business): RedirectResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'registration_no' => ['required', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:200'],
            'documents' => ['required', 'array', 'min:1', 'max:4'],
            'documents.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('wibuy.images.max_upload_kb')],
        ], ['documents.required' => 'Add a photo of your business registration certificate.']);

        try {
            $business->register($request->user(), collect($data)->except('documents')->all(), $request->file('documents', []));
        } catch (MarketplaceException $e) {
            return back()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('business.create')->with('status', 'Thank you. WiBuy checks your papers within 2 working days.');
    }
}
