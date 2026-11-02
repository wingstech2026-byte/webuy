<?php

namespace App\Http\Controllers;

use App\Services\Sms\SmsOrdering;
use App\Services\Sms\UssdMenu;
use App\Services\SmsService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Text messages and USSD sessions from the SMS gateway (off until SMS_ORDERING_ENABLED and a token are set).
 * The gateway calls https://<site>/webhooks/sms?token=... and /webhooks/ussd?token=... with the sender's phone.
 */
class SmsWebhookController extends Controller
{
    public function sms(Request $request, SmsOrdering $ordering, SmsService $sms): JsonResponse
    {
        $phone = $this->authorizedPhone($request, $request->input('from'));
        $reply = $ordering->reply($phone, mb_substr((string) $request->input('text', ''), 0, 300));
        $sms->send($phone, $reply, false);

        return response()->json(['received' => true]);
    }

    public function ussd(Request $request, UssdMenu $menu): Response
    {
        $phone = $this->authorizedPhone($request, $request->input('phoneNumber'));

        return response($menu->reply($phone, mb_substr((string) $request->input('text', ''), 0, 100)), 200, ['Content-Type' => 'text/plain']);
    }

    /** Only the gateway (it knows the token) may speak for a phone number. */
    private function authorizedPhone(Request $request, mixed $from): string
    {
        $token = (string) config('services.sms_ordering.token');
        abort_unless(SmsOrdering::enabled() && $token !== '' && hash_equals($token, (string) $request->query('token')), 404);

        $phone = PhoneNumber::normalize((string) $from);
        abort_if($phone === null, 422, 'Unknown phone number.');

        return $phone;
    }
}
