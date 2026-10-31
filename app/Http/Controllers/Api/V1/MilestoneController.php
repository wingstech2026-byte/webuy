<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Operator;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderMilestone;
use App\Models\Payment;
use App\Services\MilestoneService;
use App\Services\PaymentService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Paying the next part of a big order, booking an inspection, and polling those payments. */
class MilestoneController extends Controller
{
    private function payer(Request $request): array
    {
        $request->merge(['payer_phone' => PhoneNumber::normalize($request->input('payer_phone'))]);

        return $request->validate([
            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
        ]);
    }

    public function pay(Request $request, Order $order, OrderMilestone $milestone, MilestoneService $milestones): JsonResponse
    {
        abort_unless($milestone->order_id === $order->id && $order->buyer_id === $request->user()->id, 404);
        $data = $this->payer($request);

        $payment = $milestones->pay($request->user(), $milestone, Operator::from($data['operator']), $data['payer_phone']);

        return response()->json($this->paymentJson($payment), 201);
    }

    public function inspection(Request $request, Order $order, MilestoneService $milestones): JsonResponse
    {
        abort_unless($order->buyer_id === $request->user()->id, 404);
        $data = $this->payer($request);

        $inspection = $milestones->requestInspection($request->user(), $order, Operator::from($data['operator']), $data['payer_phone']);
        $payment = $inspection->payments()->latest('id')->first();

        return response()->json(['status' => $inspection->status, 'fee' => (string) $inspection->fee, 'payment' => $payment ? $this->paymentJson($payment) : null], 201);
    }

    /** Poll a balance, inspection or import payment. */
    public function payment(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        $owner = $payment->payable?->order?->buyer_id ?? $payment->payable?->user_id;
        abort_unless($owner === $request->user()->id, 404);

        if ($payment->status === PaymentStatus::Pending && $payment->created_at->lt(now()->subSeconds(30))) {
            $payments->syncQuietly($payment);
        }

        return response()->json($this->paymentJson($payment->refresh()));
    }

    /** Development only (fake gateway): pretend the phone approved or declined. */
    public function simulate(Request $request, Payment $payment, PaymentService $payments): JsonResponse
    {
        abort_unless(config('payments.gateway') === 'fake' && ! app()->isProduction(), 404);
        $owner = $payment->payable?->order?->buyer_id ?? $payment->payable?->user_id;
        abort_unless($owner === $request->user()->id && $payment->status === PaymentStatus::Pending, 404);
        $request->validate(['outcome' => ['required', 'in:approve,decline']]);

        $payment->forceFill(['raw_response' => array_merge($payment->raw_response ?? [], [
            'fake_status' => $request->input('outcome') === 'approve' ? 'COMPLETED' : 'FAILED',
        ])])->save();
        $payments->sync($payment);

        return response()->json($this->paymentJson($payment->refresh()));
    }

    private function paymentJson(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'status' => $payment->status->value, // pending | completed | failed
            'amount' => (string) $payment->amount,
            'can_simulate' => config('payments.gateway') === 'fake' && ! app()->isProduction(),
        ];
    }
}
