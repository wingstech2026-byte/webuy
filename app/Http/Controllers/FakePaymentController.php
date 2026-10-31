<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Simulate approve" / "Simulate decline" buttons for the fake gateway.
 * They only record what the phone would have answered, then run the same
 * sync code the webhook uses. Disabled unless PAYMENT_GATEWAY=fake outside production.
 */
class FakePaymentController extends Controller
{
    public function approve(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        return $this->simulate($request, $payment, $payments, 'COMPLETED');
    }

    public function decline(Request $request, Payment $payment, PaymentService $payments): RedirectResponse
    {
        return $this->simulate($request, $payment, $payments, 'FAILED');
    }

    private function simulate(Request $request, Payment $payment, PaymentService $payments, string $outcome): RedirectResponse
    {
        abort_unless(config('payments.gateway') === 'fake' && ! app()->isProduction(), 404);
        abort_unless($payment->isDeposit() && $payment->checkout, 404);
        abort_unless($payment->checkout->buyer_id === $request->user()->id || $request->user()->is_admin, 403);
        abort_unless($payment->status === PaymentStatus::Pending, 422, 'This payment is already finished.');

        $payment->forceFill([
            'raw_response' => array_merge($payment->raw_response ?? [], ['fake_status' => $outcome]),
        ])->save();

        $payments->sync($payment);

        return redirect()->route('checkout.status', $payment->checkout_id);
    }
}
