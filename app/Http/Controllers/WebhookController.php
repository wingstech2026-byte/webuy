<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPaymentJob;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    /**
     * pawaPay callback. The body is NOT trusted: we only read the deposit/refund
     * id from it, then re-fetch the real status from pawaPay (SyncPaymentJob).
     * Replaying the same callback is harmless.
     */
    public function pawapay(Request $request): JsonResponse
    {
        $id = $request->input('depositId') ?? $request->input('refundId') ?? $request->input('payoutId');

        if (is_string($id) && Str::isUuid($id)) {
            $paymentId = Payment::where('provider_reference', $id)->value('id');

            if ($paymentId) {
                SyncPaymentJob::dispatch($paymentId);
            }
        }

        // Always 200 so the sender does not keep retrying callbacks for unknown ids.
        return response()->json(['received' => true]);
    }
}
