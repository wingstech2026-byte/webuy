<?php

namespace App\Http\Controllers\Seller;

use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Models\ProductQuestion;
use App\Services\ProductQuestionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Buyers' questions about the shop's products: answer them (the answer shows on the product page) or hide them. */
class QuestionController extends Controller
{
    public function index(Request $request): View
    {
        $seller = $request->user()->seller;

        return view('seller.questions', [
            'open' => ProductQuestion::whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))->whereNull('answered_at')->where('is_hidden', false)->with('product', 'asker')->oldest()->get(),
            'answered' => ProductQuestion::whereHas('product', fn ($q) => $q->where('seller_id', $seller->id))->whereNotNull('answered_at')->with('product')->latest('answered_at')->limit(30)->get(),
        ]);
    }

    public function answer(Request $request, ProductQuestion $question, ProductQuestionService $questions): RedirectResponse
    {
        abort_unless($question->product->seller_id === $request->user()->seller?->id, 404);
        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:1000']], ['answer.*' => 'Type your answer.']);

        try {
            $questions->answer($question, $request->user(), $data['answer']);
        } catch (MarketplaceException $e) {
            return back()->withErrors(['answer' => $e->getMessage()]);
        }

        return back()->with('status', 'Answer saved. It shows on the product page, and the buyer was told.');
    }

    public function hide(Request $request, ProductQuestion $question): RedirectResponse
    {
        abort_unless($question->product->seller_id === $request->user()->seller?->id, 404);
        $question->forceFill(['is_hidden' => true])->save();

        return back()->with('status', 'Question hidden.');
    }
}
