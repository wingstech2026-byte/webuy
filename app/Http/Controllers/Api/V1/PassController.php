<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Operator;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MembershipResource;
use App\Models\Membership;
use App\Services\MembershipService;
use App\Services\PaymentService;
use App\Support\Money;
use App\Support\PhoneNumber;
use App\Support\RewardSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** WiBuy Pass: buy a month with mobile money and poll until it is paid. Never charged automatically. */
class PassController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $active = $request->user()->activeMembership();

        return response()->json([
            'price' => RewardSettings::money('pass_price'),
            'days' => RewardSettings::int('pass_days'),
            'benefits' => [
                'Free delivery on shop orders from '.Money::format(RewardSettings::money('pass_free_delivery_min')),
                'Member prices on many products',
                RewardSettings::int('spin_pass_extra').' extra spin(s) every day',
                'An SMS reminder before it ends. We never charge you automatically.',
            ],
            'active' => $active ? (new MembershipResource($active))->resolve($request) : null,
        ]);
    }

    public function store(Request $request, MembershipService $memberships): JsonResponse
    {
        $request->merge(['payer_phone' => PhoneNumber::normalize($request->input('payer_phone'))]);

        $data = $request->validate([
            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
        ], ['payer_phone.regex' => 'Enter a Sierra Leone mobile number, like 076 123 456.']);

        $membership = $memberships->buy($request->user(), Operator::from($data['operator']), $data['payer_phone']);

        return (new MembershipResource($membership))->response()->setStatusCode(201);
    }

    public function status(Request $request, Membership $membership, PaymentService $payments): MembershipResource
    {
        abort_unless($membership->user_id === $request->user()->id, 404);

        if ($membership->status === 'pending' && ($payment = $membership->payment) && $payment->created_at->lt(now()->subSeconds(30))) {
            $payments->syncQuietly($payment);
            $membership->refresh();
        }

        return new MembershipResource($membership);
    }

    /** Development only (fake gateway): pretend the phone approved or declined. */
    public function simulate(Request $request, Membership $membership, PaymentService $payments): MembershipResource
    {
        abort_unless(config('payments.gateway') === 'fake' && ! app()->isProduction(), 404);
        abort_unless($membership->user_id === $request->user()->id, 404);
        $request->validate(['outcome' => ['required', 'in:approve,decline']]);

        $payment = $membership->payment;
        abort_unless($payment && $payment->status === PaymentStatus::Pending, 422, 'This payment is already finished.');

        $payment->forceFill(['raw_response' => array_merge($payment->raw_response ?? [], [
            'fake_status' => $request->input('outcome') === 'approve' ? 'COMPLETED' : 'FAILED',
        ])])->save();
        $payments->sync($payment);

        return new MembershipResource($membership->refresh());
    }
}
