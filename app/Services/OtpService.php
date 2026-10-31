<?php

namespace App\Services;

use App\Exceptions\OtpThrottledException;
use App\Models\OtpCode;
use App\Sms\SmsMessages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class OtpService
{
    public function __construct(private SmsService $sms) {}

    /**
     * Create a code for the phone and SMS it. Codes are stored hashed.
     *
     * @throws OtpThrottledException when the phone asked for too many codes this hour
     */
    public function send(string $phone, ?string $ip = null): string
    {
        $sentLastHour = OtpCode::where('phone', $phone)->where('created_at', '>=', now()->subHour())->count();

        if ($sentLastHour >= config('wibuy.otp.max_sends_per_hour')) {
            throw new OtpThrottledException('Too many codes were sent to this number. Please try again in an hour.');
        }

        // Only the newest code is valid.
        OtpCode::where('phone', $phone)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        $length = config('wibuy.otp.length');
        $minutes = config('wibuy.otp.ttl_minutes');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        OtpCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($minutes),
            'ip' => $ip,
        ]);

        $this->sms->send($phone, SmsMessages::otp($code, $minutes), inApp: false); // never show login codes in the app

        return $code;
    }

    /** True when the code is right, unexpired and within the attempt limit. A good code works once. */
    public function verify(string $phone, string $code): bool
    {
        return DB::transaction(function () use ($phone, $code) {
            $otp = OtpCode::where('phone', $phone)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $otp || $otp->attempts >= config('wibuy.otp.max_attempts')) {
                return false;
            }

            $otp->increment('attempts');

            if (! Hash::check($code, $otp->code_hash)) {
                return false;
            }

            $otp->update(['consumed_at' => now()]);

            return true;
        });
    }
}
