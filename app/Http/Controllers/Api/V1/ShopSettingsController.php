<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShopHolidayRequest;
use App\Models\User;
use App\Services\ShopStaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $shop = $request->user()->ownShop();

        return response()->json([
            'holiday_until' => $shop->isOnHoliday() ? $shop->holiday_until->toDateString() : null,
            'holiday_message' => $shop->isOnHoliday() ? $shop->holiday_message : null,
            'staff' => $shop->staff()->orderBy('name')->get(['users.id', 'users.name', 'users.phone'])
                ->map(fn (User $helper): array => ['id' => $helper->id, 'name' => $helper->name, 'phone' => $helper->display_phone]),
        ]);
    }

    public function holiday(ShopHolidayRequest $request): Response
    {
        $data = $request->validated();
        $request->user()->ownShop()->forceFill([
            'holiday_until' => $data['holiday_until'] ?? null,
            'holiday_message' => isset($data['holiday_until']) ? ($data['holiday_message'] ?? null) : null,
        ])->save();

        return response()->noContent();
    }

    public function addStaff(Request $request, ShopStaffService $staff): Response
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']]);
        $staff->add($request->user()->ownShop(), $data['phone']);

        return response()->noContent();
    }

    public function removeStaff(Request $request, User $user, ShopStaffService $staff): Response
    {
        $staff->remove($request->user()->ownShop(), $user);

        return response()->noContent();
    }
}
