<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\OtpThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VerifyOtpRequest;
use App\Http\Requests\SendOtpRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\OtpService;
use App\Services\RewardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Phone + SMS code login for the mobile app. A successful login returns a Sanctum token. */
class AuthController extends Controller
{
    public function sendCode(SendOtpRequest $request, OtpService $otp): JsonResponse
    {
        $phone = $request->validated('phone');

        try {
            $code = $otp->send($phone, $request->ip());
        } catch (OtpThrottledException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        return response()->json([
            'message' => 'We sent a code by SMS.',
            'phone' => $phone,
            'needs_name' => blank(($user = User::where('phone', $phone)->first())?->name),
            // Where they live (district, town or area): asked at sign-up and from accounts that miss it.
            'needs_location' => blank($user?->district) || blank($user?->area),
            'expires_in_minutes' => config('wibuy.otp.ttl_minutes'),
            // Local development with the log SMS driver: nothing is really sent, so return the code.
            'dev_code' => app()->environment('local') && config('sms.driver') === 'log' ? $code : null,
        ]);
    }

    public function verify(VerifyOtpRequest $request, OtpService $otp, RewardService $rewards, DeviceService $devices): JsonResponse
    {
        $phone = $request->validated('phone');

        if (! $otp->verify($phone, $request->validated('code'))) {
            return response()->json([
                'message' => 'That code is wrong or has expired.',
                'errors' => ['code' => ['That code is wrong or has expired. Check the SMS and try again, or ask for a new code.']],
            ], 422);
        }

        $user = User::firstOrNew(['phone' => $phone]);

        if (! $user->name && $request->filled('name')) {
            $user->name = trim($request->validated('name'));
        }

        // Where they live, asked at sign-up (and once from accounts that miss it).
        if ($request->filled('district') && $request->filled('area')) {
            $user->forceFill(['district' => $request->validated('district'), 'area' => trim($request->validated('area'))]);
        }

        $isNew = ! $user->exists;
        $user->last_login_at = now();
        $user->save();

        $rewards->afterLogin($user, $isNew, 'app', $request->validated('referral_code'), $devices->hashFromRequest($request));

        $token = $user->createToken($request->validated('device_name') ?: 'WiBuy mobile app')->plainTextToken;

        return response()->json(['token' => $token, 'user' => new UserResource($user)]);
    }

    /** "My details" in the app: name and where they live. */
    public function updateMe(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'area' => ['required', 'string', 'min:2', 'max:80'],
        ], [
            'name.*' => 'Please type your full name.',
            'district.*' => 'Please choose the district where you live.',
            'area.*' => 'Please type your town or area, e.g. Lumley or Bo town.',
        ]);

        $request->user()->forceFill(['name' => trim($data['name']), 'district' => $data['district'], 'area' => trim($data['area'])])->save();

        return new UserResource($request->user());
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /** Log out this phone only: its token stops working. */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
