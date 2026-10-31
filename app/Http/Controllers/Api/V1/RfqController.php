<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CheckoutResource;
use App\Http\Resources\Api\V1\RfqResource;
use App\Models\Rfq;
use App\Models\RfqQuote;
use App\Services\RfqService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/** Requests for quotation: post, compare quotes side by side, accept one (an order at the quoted price). */
class RfqController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return RfqResource::collection(Rfq::where('buyer_id', $request->user()->id)->withCount('quotes')->latest('id')->paginate(20));
    }

    public function store(Request $request, RfqService $rfqs): RfqResource
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'unit' => ['nullable', 'string', 'max:40'],
            'district' => ['required', Rule::in(config('wibuy.districts'))],
            'target_price' => ['nullable', 'numeric', 'min:0'],
            'needed_by' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        return new RfqResource($rfqs->create($request->user(), $data + ['unit' => $data['unit'] ?? 'piece']));
    }

    public function show(Request $request, Rfq $rfq, RfqService $rfqs): RfqResource
    {
        abort_unless($rfq->buyer_id === $request->user()->id, 404);

        $resource = new RfqResource($rfq->loadCount('quotes'));
        $resource->comparison = $rfqs->compare($rfq);

        return $resource;
    }

    public function accept(Request $request, RfqQuote $quote, RfqService $rfqs): JsonResponse
    {
        abort_unless($quote->rfq->buyer_id === $request->user()->id, 404);

        $request->merge(array_map(fn ($v) => PhoneNumber::normalize($v), $request->only('recipient_phone', 'payer_phone')));
        $data = $request->validate([
            'recipient_name' => ['required', 'string', 'max:80'],
            'recipient_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
            'address' => ['nullable', 'string', 'max:200'],
            'method' => ['required', Rule::enum(DeliveryMethod::class)],
            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
            'payment_plan' => ['nullable', 'in:full,milestones'],
        ]);

        $checkout = $rfqs->accept(
            $request->user(),
            $quote,
            ['name' => $data['recipient_name'], 'phone' => $data['recipient_phone'], 'address' => $data['address'] ?? null],
            $data['method'],
            Operator::from($data['operator']),
            $data['payer_phone'],
            $data['payment_plan'] ?? 'full',
        );

        return (new CheckoutResource($checkout->load('orders.items', 'orders.seller', 'latestDeposit')))->response()->setStatusCode(201);
    }
}
