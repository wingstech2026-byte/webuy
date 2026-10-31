<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\RfqResource;
use App\Services\Assistant\ShoppingAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The AI shopping assistant (ASSISTANT_ENABLED). The Claude call happens on the server. */
class AssistantController extends Controller
{
    public function ask(Request $request, ShoppingAssistant $assistant): JsonResponse
    {
        abort_unless(ShoppingAssistant::enabled(), 404);
        $data = $request->validate(['message' => ['required', 'string', 'min:3', 'max:500']]);

        $answer = $assistant->ask($request->user(), $data['message']);

        return response()->json([
            'reply' => $answer['reply'],
            'understood' => $answer['understood'],
            'shortlist' => ProductResource::collection($answer['shortlist'])->resolve($request),
            'rfq_draft' => $answer['rfq_draft'],
            'left_today' => $answer['left_today'],
        ]);
    }

    public function createRfq(Request $request, ShoppingAssistant $assistant): RfqResource
    {
        abort_unless(ShoppingAssistant::enabled(), 404);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1'],
            'unit' => ['required', 'string', 'max:40'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'needed_by' => ['nullable', 'date'],
        ]);

        return new RfqResource($assistant->createRfq($request->user(), $data));
    }
}
