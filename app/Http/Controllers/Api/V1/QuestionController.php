<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Services\ProductQuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Product questions in the app: buyers ask; shops see and answer theirs. */
class QuestionController extends Controller
{
    public function store(Request $request, Product $product, ProductQuestionService $questions): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:5', 'max:500']], ['question.*' => 'Type your question (5 to 500 characters).']);
        $question = $questions->ask($product, $request->user(), $data['question']);

        return response()->json(['data' => ['id' => $question->id, 'question' => $question->question]], 201);
    }

    public function forShop(Request $request): JsonResponse
    {
        $seller = $request->user()->seller;
        abort_unless($seller, 403, 'Only shops can do this.');

        $rows = ProductQuestion::whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))->where('is_hidden', false)
            ->with('product')->orderByRaw('case when answered_at is null then 0 else 1 end')->latest('id')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn (ProductQuestion $q) => [
            'id' => $q->id, 'product' => $q->product->title, 'question' => $q->question, 'answer' => $q->answer, 'asked_at' => $q->created_at?->toIso8601String(),
        ])->values()]);
    }

    public function answer(Request $request, ProductQuestion $question, ProductQuestionService $questions): JsonResponse
    {
        abort_unless($question->product->seller_id === $request->user()->seller?->id, 404);
        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:1000']], ['answer.*' => 'Type your answer.']);
        $questions->answer($question, $request->user(), $data['answer']);

        return response()->json(['data' => ['id' => $question->id, 'answer' => $question->answer]]);
    }
}
