<?php

namespace App\Payments;

use App\Models\Payment;

interface PaymentGateway
{
    /** Ask the buyer's mobile money account to approve a deposit. */
    public function initiateDeposit(Payment $deposit): GatewayResult;

    /** Send money back to the buyer for (part of) a completed deposit. */
    public function initiateRefund(Payment $refund, Payment $deposit): GatewayResult;

    /** Send money to a seller's mobile money account. */
    public function initiatePayout(Payment $payout): GatewayResult;

    /** Ask the gateway for the real, current status of a deposit, refund or payout. */
    public function fetchStatus(Payment $payment): GatewayResult;
}
