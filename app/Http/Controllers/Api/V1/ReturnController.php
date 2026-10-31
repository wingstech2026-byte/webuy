<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReturnRequestForm;
use App\Models\Order;
use App\Models\ReturnRequest;
use App\Services\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Returns in the app: the buyer asks; the seller accepts, refuses or confirms the items came back. */
class ReturnController extends Controller
{
    public function __construct(private ReturnService $returns) {}

    public function reasons(): JsonResponse
    {
        return response()->json(['data' => collect(ReturnRequest::REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()]);
    }

    public function store(ReturnRequestForm $request, Order $order): JsonResponse
    {
        $return = $this->returns->request($order, $request->user(), $request->quantities(), $request->validated('reason'), $request->validated('description'), $request->file('photos', []));

        return response()->json([
            'id' => $return->id,
            'refund_amount' => (string) $return->refund_amount,
            'message' => 'Return requested. We told the seller.',
        ], 201);
    }

    public function act(Request $request, ReturnRequest $returnRequest, string $action): JsonResponse
    {
        abort_unless($returnRequest->seller_id === $request->user()->seller?->id, 404);

        match ($action) {
            'accept' => $this->returns->accept($returnRequest, $request->user(), $request->validate(['instructions' => ['required', 'string', 'min:5', 'max:500']])['instructions']),
            'refuse' => $this->returns->refuse($returnRequest, $request->user(), $request->validate(['note' => ['required', 'string', 'min:5', 'max:500']])['note']),
            'received' => $this->returns->received($returnRequest, $request->user()),
        };

        return response()->json(['status' => $returnRequest->fresh()->status, 'status_label' => $returnRequest->fresh()->statusLabel()]);
    }
}
