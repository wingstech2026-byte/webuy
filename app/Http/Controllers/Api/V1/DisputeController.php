<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisputeRequest;
use App\Models\Order;
use App\Services\DisputeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Report a problem with an order from the app, with photos (multipart photos[]). */
class DisputeController extends Controller
{
    public function reasons(): JsonResponse
    {
        return response()->json(['data' => collect(DisputeRequest::REASONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()]);
    }

    public function store(DisputeRequest $request, Order $order, DisputeService $disputes): JsonResponse
    {
        abort_if(Gate::denies('buy', $order), 404);

        $dispute = $disputes->open(
            $order,
            $request->user(),
            DisputeRequest::REASONS[$request->validated('reason')],
            $request->validated('description'),
            $request->file('photos', []),
        );

        return response()->json([
            'id' => $dispute->id,
            'message' => 'Problem reported. Your money stays on hold until the WiBuy team decides.',
        ], 201);
    }
}
