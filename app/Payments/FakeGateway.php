<?php

namespace App\Payments;

use App\Models\Payment;

/**
 * Development gateway. Deposits stay pending until someone presses
 * "Simulate approve" / "Simulate decline" on the payment page, which stores
 * raw_response.fake_status. Refunds and payouts complete immediately.
 */
class FakeGateway implements PaymentGateway
{
    public function initiateDeposit(Payment $deposit): GatewayResult
    {
        return GatewayResult::pending(['gateway' => 'fake', 'status' => 'ACCEPTED']);
    }

    public function initiateRefund(Payment $refund, Payment $deposit): GatewayResult
    {
        return GatewayResult::completed(['gateway' => 'fake', 'status' => 'COMPLETED']);
    }

    public function initiatePayout(Payment $payout): GatewayResult
    {
        return GatewayResult::completed(['gateway' => 'fake', 'status' => 'COMPLETED']);
    }

    public function fetchStatus(Payment $payment): GatewayResult
    {
        if (! $payment->isDeposit()) {
            return GatewayResult::completed(['gateway' => 'fake', 'status' => 'COMPLETED']);
        }

        $fake = $payment->raw_response['fake_status'] ?? 'PENDING';

        return match ($fake) {
            'COMPLETED' => GatewayResult::completed(['gateway' => 'fake', 'status' => $fake]),
            'FAILED' => GatewayResult::failed('Declined on the phone', ['gateway' => 'fake', 'status' => $fake]),
            default => GatewayResult::pending(['gateway' => 'fake', 'status' => $fake]),
        };
    }
}
