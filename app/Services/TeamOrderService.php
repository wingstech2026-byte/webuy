<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\MarketplaceException;
use App\Models\Seller;
use App\Models\TeamOrder;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * Office team orders: someone opens one for a food shop and the office, shares the link, and colleagues add
 * their meals until it closes. Each pays their own food; the organiser pays the delivery once.
 */
class TeamOrderService
{
    public const CLOSE_OPTIONS = [15, 30, 45, 60, 90, 120];

    public function __construct(private InstantService $instant, private GeoService $geo) {}

    /**
     * @param  array{name: string, phone: string, district: string, address: string, landmark?: ?string, building?: ?string, floor?: ?string, latitude: float, longitude: float}  $place
     *
     * @throws MarketplaceException
     */
    public function start(User $leader, Seller $seller, array $place, string $title, int $closesInMinutes, ?CarbonInterface $deliverAt): TeamOrder
    {
        if (! $seller->is_instant || ! $seller->isApproved()) {
            throw new MarketplaceException('Office orders are for food shops with instant delivery.');
        }

        if ($seller->user_id === $leader->id) {
            throw new MarketplaceException('This is your own shop.');
        }

        if (! in_array($closesInMinutes, self::CLOSE_OPTIONS, true)) {
            throw new MarketplaceException('Choose how long colleagues have to add their meals.');
        }

        $point = ['lat' => $place['latitude'], 'lng' => $place['longitude']];
        $this->instant->assertCanOrder($seller, $point, $deliverAt);

        if (! $this->geo->deliveryQuote($seller, $place['district'], (float) $place['latitude'], (float) $place['longitude'])['can_deliver']) {
            throw new MarketplaceException("{$seller->shop_name} does not deliver to this office.");
        }

        $closesAt = now()->addMinutes($closesInMinutes);

        // Scheduled: colleagues must be in before the shop starts cooking.
        if ($deliverAt !== null) {
            $closesAt = $closesAt->min($deliverAt->copy()->subMinutes($seller->prep_minutes + 10));
        }

        return TeamOrder::create([
            'token' => Str::lower(Str::random(10)),
            'leader_id' => $leader->id,
            'seller_id' => $seller->id,
            'title' => $title,
            'deliver_at' => $deliverAt,
            'closes_at' => $closesAt,
            'recipient_name' => $place['name'],
            'recipient_phone' => $place['phone'],
            'district' => $place['district'],
            'address' => $place['address'],
            'landmark' => $place['landmark'] ?? null,
            'building' => $place['building'] ?? null,
            'floor' => $place['floor'] ?? null,
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'status' => TeamOrder::OPEN,
        ]);
    }

    /** @throws MarketplaceException */
    public function close(TeamOrder $team, User $by): void
    {
        if ($team->leader_id !== $by->id) {
            throw new MarketplaceException('Only the person who opened the office order can close it.');
        }

        $team->forceFill(['status' => TeamOrder::CLOSED])->save();
    }

    /** What the team page and the app show. Colleagues are shown by first name only. */
    public function present(TeamOrder $team, ?User $viewer): array
    {
        $team->loadMissing('seller', 'leader');
        $orders = $team->orders()->whereNotIn('status', [OrderStatus::PendingPayment, OrderStatus::Cancelled, OrderStatus::Rejected, OrderStatus::Refunded])
            ->with('buyer:id,name', 'items')->get();

        return [
            'token' => $team->token,
            'title' => $team->title,
            'shop' => ['name' => $team->seller->shop_name, 'slug' => $team->seller->slug, 'hours' => $team->seller->hoursLabel()],
            'organiser' => Str::before((string) $team->leader->name, ' '),
            'place' => $team->place,
            'deliver_at' => $team->deliver_at?->toIso8601String(),
            'closes_at' => $team->closes_at->toIso8601String(),
            'is_open' => $team->isOpen(),
            'is_organiser' => $viewer?->id === $team->leader_id,
            'organiser_has_ordered' => $orders->contains('buyer_id', $team->leader_id),
            'members' => $orders->map(fn ($o) => ['name' => Str::before((string) $o->buyer->name, ' '), 'items' => $o->items->sum('quantity')])->values(),
            'share_url' => route('team.show', $team),
        ];
    }
}
