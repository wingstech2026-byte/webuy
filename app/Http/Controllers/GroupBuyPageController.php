<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryMethod;
use App\Enums\Operator;
use App\Exceptions\MarketplaceException;
use App\Models\GroupBuy;
use App\Models\GroupBuyCampaign;
use App\Models\GroupBuyParticipant;
use App\Services\DeviceService;
use App\Services\GroupBuyService;
use App\Support\PhoneNumber;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Group buying on the website: see offers, start a group, or join one, then pay like any checkout. */
class GroupBuyPageController extends Controller
{
    public function __construct(private GroupBuyService $groups, private DeviceService $devices) {}

    public function index(): View
    {
        $campaigns = GroupBuyCampaign::open()->whereHas('product', fn ($q) => $q->listed())
            ->with('product.images', 'product.seller', 'collectionPoint')->latest('id')->limit(60)->get();

        return view('groups.index', ['campaigns' => $campaigns]);
    }

    public function start(Request $request, GroupBuyCampaign $campaign): RedirectResponse
    {
        return $this->place($request, fn (array $o) => $this->groups->start($request->user(), $campaign, ...$o));
    }

    public function join(Request $request, GroupBuy $group): RedirectResponse
    {
        return $this->place($request, fn (array $o) => $this->groups->join($request->user(), $group, ...$o));
    }

    /** @param callable(array): GroupBuyParticipant $action */
    private function place(Request $request, callable $action): RedirectResponse
    {
        $request->merge(array_map(fn ($v) => PhoneNumber::normalize($v) ?? $v, array_filter($request->only('payer_phone', 'recipient_phone'))));
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'variant_id' => ['nullable', 'integer'],
            'method' => ['required', Rule::enum(DeliveryMethod::class)],
            'recipient_name' => ['required', 'string', 'max:80'],
            'recipient_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
            'district' => ['required_if:method,delivery', 'nullable', Rule::in(config('wibuy.districts'))],
            'address' => ['required_if:method,delivery', 'nullable', 'string', 'max:200'],
            'operator' => ['required', Rule::enum(Operator::class)],
            'payer_phone' => ['required', 'regex:/^232[1-9]\d{7}$/'],
        ], [
            'recipient_phone.regex' => 'Type a Sierra Leone phone number, like 076 123 456.',
            'payer_phone.regex' => 'Type the mobile money number, like 076 123 456.',
        ]);

        $details = ['name' => $data['recipient_name'], 'phone' => $data['recipient_phone'], 'district' => $data['district'] ?? null, 'address' => $data['address'] ?? null];

        try {
            $participant = $action([
                (int) $data['quantity'], $details, $data['method'], Operator::from($data['operator']), $data['payer_phone'], $this->devices->hashFromRequest($request),
                isset($data['variant_id']) ? (int) $data['variant_id'] : null,
            ]);
        } catch (MarketplaceException $e) {
            return back()->withInput()->withErrors(['general' => $e->getMessage()]);
        }

        return redirect()->route('checkout.status', $participant->checkout_id)
            ->with('status', 'Approve the payment on your phone. Your place in the group counts once it is paid.');
    }
}
