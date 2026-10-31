<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\OtpThrottledException;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Models\User;
use App\Services\DeviceService;
use App\Services\OtpService;
use App\Services\RewardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function show()
    {
        return view('auth.login');
    }

    public function sendCode(SendOtpRequest $request, OtpService $otp): RedirectResponse
    {
        $phone = $request->validated('phone');

        try {
            $code = $otp->send($phone, $request->ip());
        } catch (OtpThrottledException $e) {
            return back()->withInput()->withErrors(['phone' => $e->getMessage()]);
        }

        $request->session()->put('otp_phone', $phone);

        $redirect = redirect()->route('login.verify');

        // Local convenience: with the log SMS driver nothing is really sent, so show the code.
        if (app()->environment('local') && config('sms.driver') === 'log') {
            $redirect->with('dev_otp', $code);
        }

        return $redirect;
    }

    public function showVerify(Request $request)
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('login');
        }

        return view('auth.verify', [
            'phone' => $phone,
            'needsName' => ! User::where('phone', $phone)->whereNotNull('name')->exists(),
        ]);
    }

    public function verify(VerifyOtpRequest $request, OtpService $otp, RewardService $rewards, DeviceService $devices): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return redirect()->route('login');
        }

        if (! $otp->verify($phone, $request->validated('code'))) {
            return back()->withErrors(['code' => 'That code is wrong or has expired. Check the SMS and try again, or ask for a new code.']);
        }

        $user = User::firstOrNew(['phone' => $phone]);

        if (! $user->name && $request->filled('name')) {
            $user->name = trim($request->validated('name'));
        }

        $isNew = ! $user->exists;
        $user->last_login_at = now();
        $user->save();

        // Welcome pack for new people, and credit for the friend whose link brought them.
        $rewards->afterLogin($user, $isNew, 'web', $request->session()->pull('referral_code'), $devices->hashFromRequest($request));

        Auth::login($user, remember: true);
        $request->session()->regenerate();
        $request->session()->forget('otp_phone');

        return redirect()->intended($user->is_admin ? route('admin.dashboard') : route('home'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
