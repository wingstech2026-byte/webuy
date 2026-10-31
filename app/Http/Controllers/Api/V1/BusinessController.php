<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\BusinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Business buyer account status (registration with papers is on the website, or here as multipart). */
class BusinessController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->businessProfile;

        return response()->json([
            'status' => $profile?->status, // null | pending | verified | rejected
            'business_name' => $profile?->business_name,
            'registration_no' => $profile?->registration_no,
            'rejection_reason' => $profile?->rejection_reason,
            'register_url' => route('business.create'),
        ]);
    }

    public function store(Request $request, BusinessService $business): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:120'],
            'registration_no' => ['required', 'string', 'max:60'],
            'district' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:200'],
            'documents' => ['required', 'array', 'min:1', 'max:4'],
            'documents.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('wibuy.images.max_upload_kb')],
        ]);

        $profile = $business->register($request->user(), collect($data)->except('documents')->all(), $request->file('documents', []));

        return response()->json(['status' => $profile->status, 'message' => 'Thank you. WiBuy checks your papers within 2 working days.'], 201);
    }
}
