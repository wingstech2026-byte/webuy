<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Seller\OrderController as WebOrderController;
use App\Http\Requests\Seller\LocationRequest;
use App\Http\Requests\Seller\PayoutRequest;
use App\Http\Requests\Seller\ProductRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Http\Resources\Api\V1\RfqResource;
use App\Models\GroupBuyCampaign;
use App\Models\Order;
use App\Models\Product;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Models\SellerLocation;
use App\Services\BuyerProtectionService;
use App\Services\GroupBuyService;
use App\Services\LedgerService;
use App\Services\OrderService;
use App\Services\PayoutService;
use App\Services\ProductImportService;
use App\Services\ProductService;
use App\Services\ProductVideoService;
use App\Services\RfqService;
use App\Services\SellerReportService;
use App\Services\SettingsService;
use App\Services\VariantService;
use App\Support\GroupBuyOfferRules;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Seller tools in the app: overview, orders (accept, reject, ship, deliver), products with photos,
 * earnings and payouts, and answering requests for quotes. Same services and rules as the website.
 */
class SellerController extends Controller
{
    private function seller(Request $request)
    {
        abort_unless($request->user()->isSeller(), 403, 'Only approved sellers can do this. Apply to sell on the website.');

        return $request->user()->seller;
    }

    public function dashboard(Request $request, LedgerService $ledger): JsonResponse
    {
        $seller = $this->seller($request);
        $counts = Order::where('seller_id', $seller->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return response()->json([
            'shop_name' => $seller->shop_name,
            'reliability_score' => $seller->reliability_score,
            'counts' => collect(WebOrderController::TABS)->map(fn ($statuses) => collect($statuses)->sum(fn ($s) => (int) ($counts[$s->value] ?? 0))),
            'money' => $ledger->summary($seller),
            'min_payout' => SettingsService::minPayout(),
            'product_count' => $seller->products()->count(),
            'low_stock' => $seller->products()->where('stock', '<=', 5)->where('status', '!=', 'suspended')->orderBy('stock')->limit(5)->get(['slug', 'title', 'stock']),
            'open_rfqs' => Rfq::open()->where('buyer_id', '!=', $seller->user_id)->whereDoesntHave('quotes', fn ($q) => $q->where('seller_id', $seller->id))->count(),
        ]);
    }

    public function orders(Request $request): AnonymousResourceCollection
    {
        $seller = $this->seller($request);
        $tab = array_key_exists($request->query('tab'), WebOrderController::TABS) ? $request->query('tab') : 'new';

        return OrderResource::collection(Order::where('seller_id', $seller->id)->whereIn('status', WebOrderController::TABS[$tab])
            ->with('items', 'seller', 'milestones', 'inspection')->orderByDesc('id')->paginate(20));
    }

    public function order(Request $request, Order $order): JsonResponse
    {
        $this->seller($request);
        abort_if(Gate::denies('manage', $order), 404);
        $order->load('items', 'seller', 'buyer', 'milestones', 'inspection');

        return response()->json([
            'data' => (new OrderResource($order))->resolve($request),
            'buyer' => ['name' => $order->recipient_name, 'phone' => $order->recipient_phone],
            'earnings' => $order->seller_earnings,
            'accept_by' => $order->accept_by?->toIso8601String(),
            'can_accept' => $order->status === OrderStatus::Paid,
            'can_ship' => $order->status === OrderStatus::Accepted && ! $order->hasUnpaidMilestones() && ! $order->viaStation(),
            'can_deliver' => $order->status === OrderStatus::Shipped && ! $order->isPickup() && ! $order->viaStation(),
            // Station orders: the seller takes the parcel to this station (the station marks it received).
            'drop_station' => $order->viaStation() && $order->dropStation ? [
                'name' => $order->dropStation->name, 'address' => $order->dropStation->address, 'district' => $order->dropStation->district,
                'opening_hours' => $order->dropStation->opening_hours, 'latitude' => (float) $order->dropStation->latitude, 'longitude' => (float) $order->dropStation->longitude,
            ] : null,
        ]);
    }

    public function act(Request $request, Order $order, string $action, OrderService $orders): JsonResponse
    {
        $this->seller($request);
        abort_if(Gate::denies('manage', $order), 404);
        $data = $request->validate(['reason' => [$action === 'reject' ? 'required' : 'nullable', 'string', 'max:200']]);

        if ($order->viaStation() && in_array($action, ['ship', 'deliver'], true)) {
            throw new MarketplaceException('Take this order to the WiBuy station. The station marks it when they receive it.');
        }

        match ($action) {
            'accept' => $orders->accept($order),
            'reject' => $orders->reject($order, $data['reason']),
            'ship' => $orders->ship($order, $data['reason'] ?? null),
            'deliver' => $orders->markDelivered($order),
            default => abort(404),
        };

        return $this->order($request, $order->refresh());
    }

    public function products(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $products = $seller->products()->with(['images', 'tiers', 'seller', 'variants'])->latest('id')->get();

        return response()->json(['data' => $products->map(fn (Product $p) => $this->productJson($request, $p))]);
    }

    /**
     * Create (no product) or update a product. Multipart: images[] adds photos, remove_images[] deletes them.
     * Options (size, colour) are saved only when the app sends variants_sent=1, so an older app that
     * does not know about options leaves them as they are.
     */
    public function saveProduct(ProductRequest $request, ProductService $products, VariantService $variants, ?Product $product = null): JsonResponse
    {
        $seller = $this->seller($request);

        if ($product) {
            abort_if(Gate::denies('manage', $product), 404);
        }

        $saved = $products->save($seller, $request->productData(), $request->tiers(), $request->file('images', []), $request->input('remove_images', []), $product);

        if ($request->boolean('variants_sent')) {
            $variants->sync($saved, $request->variants());
        }

        if ($request->hasFile('video')) {
            app(ProductVideoService::class)->attach($saved, $request->file('video'));
        } elseif ($request->boolean('remove_video')) {
            app(ProductVideoService::class)->remove($saved);
        }

        if ($product) {
            // A lower price: recent buyers get the difference as a coupon (price protection).
            app(BuyerProtectionService::class)->onPriceChange($saved->refresh());
        }

        return response()->json(['data' => $this->productJson($request, $saved->refresh()->load('images', 'tiers', 'seller', 'variants'))], $product ? 200 : 201);
    }

    /** The bulk upload columns and an example, so the app can show what to paste. */
    public function importTemplate(ProductImportService $imports): JsonResponse
    {
        return response()->json([
            'columns' => ProductImportService::COLUMNS,
            'max_rows' => ProductImportService::MAX_ROWS,
            'example' => $imports->template(),
        ]);
    }

    /**
     * Bulk upload: a CSV file, or the same rows pasted as text. Every row is checked first; with any
     * problem nothing is added. Products are added as drafts.
     */
    public function import(Request $request, ProductImportService $imports): JsonResponse
    {
        $seller = $this->seller($request);
        $request->validate([
            'file' => ['required_without:csv', 'file', 'mimes:csv,txt', 'max:2048'],
            'csv' => ['required_without:file', 'string', 'max:500000'],
        ], [
            'file.mimes' => 'Save your spreadsheet as CSV and upload that file.',
            'csv.required_without' => 'Paste your product rows, or choose a CSV file.',
        ]);

        if ($request->hasFile('file')) {
            $result = $imports->import($seller, $request->file('file')->getRealPath());
        } else {
            $path = tempnam(sys_get_temp_dir(), 'wibuy-import');
            file_put_contents($path, $request->string('csv')->toString());

            try {
                $result = $imports->import($seller, $path);
            } finally {
                @unlink($path);
            }
        }

        if ($result['errors'] !== []) {
            return response()->json(['message' => 'Nothing was added. Fix these rows and try again.', 'errors' => ['rows' => $result['errors']]], 422);
        }

        return response()->json(['imported' => $result['imported'], 'message' => "{$result['imported']} products added as drafts. Add photos, then set each one to live."], 201);
    }

    private function productJson(Request $request, Product $product): array
    {
        return (new ProductResource($product))->resolve($request) + [
            'status' => $product->status->value,
            'description' => $product->description,
            'category_id' => $product->category_id,
            'condition' => $product->condition->value,
            'all_tiers' => $product->tiers->map(fn ($t) => ['min_quantity' => $t->min_quantity, 'unit_price' => (string) $t->unit_price, 'business_only' => $t->business_only])->values(),
            'photos' => $product->images->sortBy('position')->map(fn ($i) => ['id' => $i->id, 'status' => $i->status, 'thumb_url' => $i->thumb_url])->values(),
        ];
    }

    /** Sales report: ?days=7|30|90. Same numbers as the website. */
    public function reports(Request $request, SellerReportService $reports): JsonResponse
    {
        return response()->json($reports->report($this->seller($request), $request->integer('days', 30)));
    }

    public function earnings(Request $request, LedgerService $ledger): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json([
            'money' => $ledger->summary($seller),
            'min_payout' => SettingsService::minPayout(),
            'payout_to' => ['operator' => $seller->payout_operator, 'phone' => $seller->payout_phone],
            'entries' => $seller->ledgerEntries()->latest('id')->limit(30)->get(['amount', 'description', 'created_at']),
            'payouts' => $seller->payouts()->latest('id')->limit(20)->get(['id', 'amount', 'status', 'created_at', 'rejection_reason']),
        ]);
    }

    public function payout(PayoutRequest $request, PayoutService $payouts): JsonResponse
    {
        $payout = $payouts->request($this->seller($request), $request->validated('amount'));

        return response()->json(['message' => 'Payout requested: '.Money::format($payout->amount).'. WiBuy checks it and sends it to your mobile money.'], 201);
    }

    public function rfqs(Request $request): AnonymousResourceCollection
    {
        $seller = $this->seller($request);

        return RfqResource::collection(Rfq::open()->where('buyer_id', '!=', $seller->user_id)
            ->whereDoesntHave('quotes', fn ($q) => $q->where('seller_id', $seller->id))
            ->withCount('quotes')->latest('id')->paginate(20));
    }

    public function quote(Request $request, Rfq $rfq, RfqService $rfqs): JsonResponse
    {
        $seller = $this->seller($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'unit_price' => ['required', 'numeric', 'min:0.01'],
            'quantity' => ['required', 'integer', 'min:1'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:365'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $product = $seller->products()->find($data['product_id']) ?? throw new MarketplaceException('Choose one of your products.');

        $quote = $rfqs->quote($seller, $rfq, $product, (string) $data['unit_price'], (int) $data['quantity'], (int) $data['lead_time_days'], $data['note'] ?? null);

        return response()->json(['id' => $quote->id, 'valid_until' => $quote->valid_until->toIso8601String()], 201);
    }

    /** My quotes and whether they were accepted. */
    public function quotes(Request $request): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json(['data' => RfqQuote::where('seller_id', $seller->id)->with('rfq', 'product')->latest('id')->limit(30)->get()->map(fn ($q) => [
            'id' => $q->id, 'rfq' => $q->rfq->title, 'product' => $q->product->title, 'unit_price' => (string) $q->unit_price,
            'quantity' => $q->quantity, 'status' => $q->status, 'valid_until' => $q->valid_until->toIso8601String(),
        ])]);
    }

    // ------------------------------------------------------------ locations (map) and delivery radius

    public function locations(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->seller($request)->locations()->latest('id')->get()]);
    }

    public function saveLocation(LocationRequest $request, ?SellerLocation $location = null): JsonResponse
    {
        $seller = $this->seller($request);
        abort_if($location && $location->seller_id !== $seller->id, 404);

        $location ??= new SellerLocation(['seller_id' => $seller->id]);
        $location->fill($request->locationData())->save();

        return response()->json(['data' => $location->refresh()], $location->wasRecentlyCreated ? 201 : 200);
    }

    // ------------------------------------------------------------ group-buy offers

    public function groupOffers(Request $request, GroupBuyService $groups): JsonResponse
    {
        $seller = $this->seller($request);

        return response()->json(['data' => GroupBuyCampaign::where('seller_id', $seller->id)->with('product')->latest('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'product' => $c->product->title, 'group_price' => (string) $c->group_price, 'min_group_size' => $c->min_group_size,
                'hours' => $c->hours, 'is_active' => $c->is_active, 'stats' => $groups->stats($c)])]);
    }

    public function saveGroupOffer(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $data = GroupBuyOfferRules::validate($request, $seller);

        return response()->json(['id' => GroupBuyCampaign::create($data)->id], 201);
    }
}
