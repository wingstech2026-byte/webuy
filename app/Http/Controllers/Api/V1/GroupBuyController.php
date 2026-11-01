<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CheckoutResource;
use App\Http\Resources\Api\V1\ProductResource;
use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Models\SavedAddress;
use App\Services\DeviceService;
use App\Services\GroupBuyService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Group buying in the app: offers, open groups, start or join (each member pays their own order). */
class GroupBuyController extends Controller
{
    public function __construct(private GroupBuyService $groups) {}

    private function campaignJson(Request $request, GroupBuyCampaign $c): array
    {
        return [
            'id' => $c->id,
            'group_price' => (string) $c->group_price,
            'normal_price' => (string) $c->product->price,
            'saving_per_unit' => $c->savingPerUnit(),
            'min_group_size' => $c->min_group_size,
            'max_group_size' => $c->max_group_size,
            'max_quantity_each' => $c->max_quantity_each,
            'hours' => $c->hours,
            'eligible_districts' => $c->eligible_districts,
            'collection_point' => $c->collectionPoint ? GeoController::publicLocation($c->collectionPoint) : null,
            'product' => (new ProductResource($c->product))->resolve($request),
        ];
    }

    /** Open offers; ?district= shows only offers that deliver there. */
    public function index(Request $request): JsonResponse
    {
        $district = $request->query('district');

        $campaigns = GroupBuyCampaign::open()->whereHas('product', fn ($q) => $q->listed())
            ->with('product.seller', 'product.images', 'product.tiers', 'collectionPoint')->latest('id')->limit(50)->get()
            ->filter(fn ($c) => ! $district || $c->servesDistrict($district) || $c->collectionPoint);

        return response()->json(['data' => $campaigns->map(fn ($c) => $this->campaignJson($request, $c))->values()]);
    }

    public function show(Request $request, GroupBuyCampaign $campaign): JsonResponse
    {
        $open = $campaign->groups()->where('status', GroupBuy::OPEN)->where('expires_at', '>', now())->orderBy('expires_at')->limit(10)->get();

        return response()->json([
            'data' => $this->campaignJson($request, $campaign->load('product.seller', 'product.images', 'product.tiers', 'product.variants', 'collectionPoint')),
            // Join a group that is close to complete instead of starting a new one.
            'open_groups' => $open->map(fn ($g) => $this->groups->present($g, $request->user('sanctum')))->values(),
        ]);
    }

    public function group(Request $request, GroupBuy $group): JsonResponse
    {
        return response()->json([
            'data' => $this->groups->present($group, $request->user('sanctum')),
            'campaign' => $this->campaignJson($request, $group->campaign->load('product.seller', 'product.images', 'product.variants', 'collectionPoint')),
        ]);
    }

    public function start(Request $request, GroupBuyCampaign $campaign, DeviceService $devices): JsonResponse
    {
        [$quantity, $details, $method, $operator, $phone, $variantId] = $this->order($request);
        $participant = $this->groups->start($request->user(), $campaign, $quantity, $details, $method, $operator, $phone, $devices->hashFromRequest($request), $variantId);

        return $this->placed($request, $participant);
    }

    public function join(Request $request, GroupBuy $group, DeviceService $devices): JsonResponse
    {
        [$quantity, $details, $method, $operator, $phone, $variantId] = $this->order($request);
        $participant = $this->groups->join($request->user(), $group, $quantity, $details, $method, $operator, $phone, $devices->hashFromRequest($request), $variantId);

        return $this->placed($request, $participant);
    }

    public function mine(Request $request): JsonResponse
    {
        $rows = GroupBuyParticipant::where('user_id', $request->user()->id)->with('group.campaign.product')->latest('id')->limit(30)->get();

        return response()->json(['data' => $rows->map(fn ($p) => $this->groups->present($p->group, $request->user()) + [
            'product' => $p->group->campaign->product->title,
            'quantity' => $p->quantity,
            'order_id' => $p->order_id,
        ])]);
    }

    private function placed(Request $request, GroupBuyParticipant $participant): JsonResponse
    {
        $checkout = $participant->order->checkout()->with('orders.items', 'orders.seller', 'latestDeposit')->first();

        return response()->json([
            'group' => $this->groups->present($participant->group->refresh(), $request->user()),
            'checkout' => (new CheckoutResource($checkout))->resolve($request),
        ], 201);
    }

    /** The member's own order: quantity, own address (or the collection point) and mobile money. */
    private function order(Request $request): array
    {
        $request->merge(array_map(fn ($v) => PhoneNumber::normalize($v), array_filter($request->only('payer_phone', 'recipient_phone'))));
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'variant_id' => ['nullable', 'integer'],
            'method' => ['required', Rule::enum(DeliveryMethod::class)],
            'address_id' => ['nullable', 'integer'],
            'recipient_name' => ['nullable', 'string', 'max:80'],
            'recipient_phone' => ['nullable', 'regex:/^232[1-9]\d{7}$/'],
            'district' => ['nullable', Rule::in(config('wibuy.districts'))],
            'address' => ['nullable', 'string', 'max:200'],
            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
        ]);

        $user = $request->user();
        $details = ['name' => $data['recipient_name'] ?? $user->name, 'phone' => $data['recipient_phone'] ?? $user->phone, 'district' => $data['district'] ?? null, 'address' => $data['address'] ?? null];

        if (isset($data['address_id'])) {
            $details = self::fromSavedAddress(SavedAddress::where('user_id', $user->id)->findOrFail($data['address_id']));
        }

        return [(int) $data['quantity'], $details, $data['method'], Operator::from($data['operator']), $data['payer_phone'], isset($data['variant_id']) ? (int) $data['variant_id'] : null];
    }

    /** Checkout details from a saved address (the coordinates go with it, server side). */
    public static function fromSavedAddress(SavedAddress $a): array
    {
        return [
            'name' => $a->recipient_name,
            'phone' => $a->recipient_phone,
            'district' => $a->district,
            'address' => $a->one_line ?: $a->district,
            'landmark' => $a->landmark,
            'note' => $a->directions,
            'saved_address_id' => $a->id,
            'latitude' => $a->latitude !== null ? (float) $a->latitude : null,
            'longitude' => $a->longitude !== null ? (float) $a->longitude : null,
        ];
    }
}
