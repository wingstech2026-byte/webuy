<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Operator;
use App\Http\Controllers\Controller;
use App\Models\ImportOrder;
use App\Models\ImportProduct;
use App\Services\ImportService;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** WiBuy Global import orders (WIBUY_GLOBAL_ENABLED). */
class ImportController extends Controller
{
    public function products(): JsonResponse
    {
        abort_unless(ImportService::enabled(), 404);

        return response()->json(['data' => ImportProduct::where('is_active', true)->with('images')->latest('id')->get()->map(fn (ImportProduct $p) => [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'origin' => $p->origin,
            'unit' => $p->unit,
            'min_quantity' => $p->min_quantity,
            'deposit_percent' => $p->deposit_percent,
            'options' => $p->shippingOptions(), // sea|air => landed price per unit (goods, shipping, duties) and days
            'image_url' => $p->images->firstWhere('status', 'ready')?->thumb_url,
        ])]);
    }

    public function orders(Request $request): JsonResponse
    {
        abort_unless(ImportService::enabled(), 404);

        return response()->json(['data' => ImportOrder::where('user_id', $request->user()->id)->with('product')->latest('id')->get()->map(fn ($o) => $this->orderJson($o))]);
    }

    public function store(Request $request, ImportService $imports): JsonResponse
    {
        abort_unless(ImportService::enabled(), 404);
        $data = $this->validated($request, [
            'import_product_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
            'shipping' => ['required', 'in:sea,air'],
        ]);

        $order = $imports->order($request->user(), ImportProduct::findOrFail($data['import_product_id']), (int) $data['quantity'], $data['shipping'],
            Operator::from($data['operator']), $data['payer_phone']);

        return response()->json(['data' => $this->orderJson($order->load('product'))], 201);
    }

    public function payBalance(Request $request, ImportOrder $importOrder, ImportService $imports): JsonResponse
    {
        abort_unless(ImportService::enabled(), 404);
        $data = $this->validated($request);

        $payment = $imports->payBalance($request->user(), $importOrder, Operator::from($data['operator']), $data['payer_phone']);

        return response()->json(['payment_id' => $payment->id, 'amount' => (string) $payment->amount], 201);
    }

    private function validated(Request $request, array $extra = []): array
    {
        $request->merge(['payer_phone' => PhoneNumber::normalize($request->input('payer_phone'))]);

        return $request->validate($extra + [
            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
        ]);
    }

    private function orderJson(ImportOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'product' => $order->product->title,
            'quantity' => $order->quantity,
            'shipping' => $order->shipping,
            'total' => (string) $order->total,
            'deposit_amount' => (string) $order->deposit_amount,
            'balance_amount' => (string) $order->balance_amount,
            'status' => $order->status,
            'status_label' => $order->status_label,
            'estimated_arrival' => $order->estimated_arrival?->toDateString(),
            'deposit_paid' => $order->deposit_paid_at !== null,
            'balance_paid' => $order->balance_paid_at !== null,
            'can_pay_balance' => ! $order->balance_paid_at && in_array($order->status, ['at_port', 'customs_cleared', 'ready_for_pickup'], true),
            'steps' => collect(ImportService::TRACKING)->map(fn ($step) => [
                'key' => $step, 'label' => ImportOrder::STEPS[$step], 'at' => $order->timeline[$step] ?? null,
            ])->values(),
            'payment_id' => $order->payments()->latest('id')->value('id'),
        ];
    }
}
