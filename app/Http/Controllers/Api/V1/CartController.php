<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddToCartRequest;
use App\Http\Resources\Api\V1\CartLineResource;
use App\Http\Resources\Api\V1\SellerResource;
use App\Models\CartItem;
use App\Models\Product;
use App\Services\CartService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The cart, grouped by seller like the website. Errors (stock, minimum order) come back as 422 messages. */
class CartController extends Controller
{
    public function __construct(private CartService $cart) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function store(AddToCartRequest $request, Product $product): JsonResponse
    {
        $this->cart->add($request->user(), $product, (int) $request->validated('quantity'), $request->validated('variant') !== null ? (int) $request->validated('variant') : null);

        return response()->json($this->payload($request), 201);
    }

    public function update(Request $request, CartItem $item): JsonResponse
    {
        $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.config('wibuy.max_cart_quantity')]]);

        $this->cart->setQuantity($request->user(), $item, (int) $request->input('quantity'));

        return response()->json($this->payload($request));
    }

    public function destroy(Request $request, CartItem $item): JsonResponse
    {
        $this->cart->remove($request->user(), $item);

        return response()->json($this->payload($request));
    }

    /**
     * @return array{groups: array<int, array<string, mixed>>, total: string, count: int, has_problems: bool}
     */
    private function payload(Request $request): array
    {
        $lines = $this->cart->lines($request->user());

        $groups = $lines->groupBy(fn (array $line): int => $line['product']->seller_id)->map(function ($sellerLines) use ($request): array {
            $items = CartLineResource::collection($sellerLines->values())->resolve($request);

            return [
                'seller' => (new SellerResource($sellerLines->first()['product']->seller))->resolve($request),
                'items' => $items,
                'subtotal' => Money::sum(array_column($items, 'line_total')),
            ];
        })->values();

        return [
            'groups' => $groups->all(),
            'total' => Money::sum($groups->pluck('subtotal')),
            'count' => (int) $lines->sum('quantity'),
            'has_problems' => $lines->contains(fn (array $line): bool => $line['problem'] !== null),
        ];
    }
}
