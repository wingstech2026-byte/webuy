<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceException;
use App\Models\Product;
use App\Services\ProductQuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A buyer asks a question on a product page. */
class ProductQuestionController extends Controller
{
    public function store(Request $request, Product $product, ProductQuestionService $questions): RedirectResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:5', 'max:500']], ['question.*' => 'Type your question (5 to 500 characters).']);

        try {
            $questions->ask($product, $request->user(), $data['question']);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['question' => $e->getMessage()]);
        }

        return back()->with('status', 'Question sent. We will tell you when the shop answers.');
    }
}
