import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../api/geo_models.dart';
import '../api/models.dart';
import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'addresses_screen.dart';
import 'details_screen.dart';
import 'instant_screen.dart';
import 'payment_screen.dart';
import 'rewards_screen.dart';

/// Checkout for the whole cart, or "Buy now" for one product. One order is made per seller;
/// the backend computes every fee and total (shown from /checkout/quote).
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen(
      {super.key,
      this.productSlug,
      this.quantity,
      this.couponId,
      this.sample = false,
      this.variantId,
      this.teamToken,
      this.addonIds = const []});

  /// Extras chosen on the product page ("Buy now").
  final List<int> addonIds;

  /// Checking out for an office team order (delivered to the office with colleagues' meals).
  final String? teamToken;

  /// The option chosen on the product page (size, colour...).
  final int? variantId;

  /// One unit at the sample price.
  final bool sample;

  final String? productSlug;
  final int? quantity;

  /// A coupon to start with (e.g. from a finished price cut).
  final int? couponId;

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  final _name = TextEditingController();
  final _phone = TextEditingController();
  final _addressText = TextEditingController();
  final _note = TextEditingController();
  final _payerPhone = TextEditingController();

  String _district = 'Western Area Urban';

  /// Saved addresses; when one is chosen its details (and map point) are used on the server.
  List<SavedAddress> _addresses = const [];
  int? _addressId;

  SavedAddress? get _address =>
      _addresses.where((a) => a.id == _addressId).firstOrNull;
  String _operator = 'ORANGE';

  /// seller id => 'delivery' | 'pickup'
  final Map<int, String> _methods = {};

  /// The WiBuy station where the buyer collects ('station' method).
  int? _stationId;

  Quote? _quote;
  Object? _error;

  /// Instant food: where the buyer is (when the address has no map pin) and an optional delivery time.
  LatLng? _here;
  DateTime? _deliverAt;

  /// A shop's offer code and tips for the rider (seller id => Le).
  final _promo = TextEditingController();
  final Map<int, int> _tips = {};
  late int? _couponId = widget.couponId;
  bool _couponTouched = false;
  List<Coupon> _coupons = [];
  bool _inParts = false;
  bool _quoting = false;
  bool _paying = false;

  bool get _isBuyNow => widget.productSlug != null;

  @override
  void initState() {
    super.initState();
    final user = SessionScope.read(context).user;
    _name.text = user?.name ?? '';
    _phone.text = displayPhone(user?.phone);
    _payerPhone.text = displayPhone(user?.phone);
    _loadAddresses();
  }

  Future<void> _loadAddresses() async {
    try {
      final list = await loadAddresses(context);
      if (!mounted) return;
      setState(() {
        _addresses = list;
        if (list.isNotEmpty) {
          _addressId = list.first.id;
          _district = list.first.district;
        }
      });
    } on Object {
      // No saved addresses (or offline): type one below as before.
    }
    if (mounted) _requote();
  }

  @override
  void dispose() {
    for (final c in [_name, _phone, _addressText, _note, _payerPhone, _promo]) {
      c.dispose();
    }
    super.dispose();
  }

  Map<String, dynamic> get _lineParams => {
        if (_isBuyNow) 'product': widget.productSlug,
        if (_isBuyNow) 'qty': widget.quantity,
        if (_isBuyNow && widget.variantId != null) 'variant': widget.variantId,
        if (widget.sample) 'sample': true,
        if (_isBuyNow && widget.addonIds.isNotEmpty) 'addons': widget.addonIds,
        if (_promo.text.trim().isNotEmpty) 'promo_code': _promo.text.trim(),
        if (_tips.values.any((t) => t > 0)) 'tips': _tips.map((k, v) => MapEntry('$k', v)),
      };

  Future<void> _requote() async {
    setState(() {
      _quoting = true;
      _error = null;
    });
    try {
      final json =
          await SessionScope.read(context).api.post('/checkout/quote', {
        ..._lineParams,
        if (_addressId != null)
          'address_id': _addressId
        else
          'district': _district,
        'methods': _methods.map((k, v) => MapEntry('$k', v)),
        if (_couponId != null) 'coupon_id': _couponId,
        if (_stationId != null) 'station_id': _stationId,
        if (widget.teamToken != null) 'team': widget.teamToken,
        if (_addressId == null && _here != null) ...{'lat': _here!.latitude, 'lng': _here!.longitude},
      }) as Map<String, dynamic>;
      final quote = Quote.fromJson(json);
      setState(() {
        _quote = quote;
        _coupons = quote.couponJson.map(Coupon.fromJson).toList();
        // Start with the best usable coupon, until the person chooses themselves.
        if (!_couponTouched && _couponId == null && _coupons.isNotEmpty) {
          _couponId = _coupons.first.id;
          _couponTouched = true;
          _requote();
        }
        for (final group in quote.groups) {
          if (group.method != null) _methods[group.sellerId] = group.method!;
        }
      });
    } on Object catch (e) {
      setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _quoting = false);
    }
  }

  bool get _needsAddress =>
      _methods.values.contains('delivery') ||
      _methods.values.contains('station_door');

  Future<void> _pay() async {
    final quote = _quote;
    if (quote == null) return;
    // Every buyer gives their name and where they live before buying (older accounts do it once).
    if (SessionScope.read(context).user?.hasDetails == false) {
      showMessage(
          context, 'Please add your full name and where you live first.');
      await Navigator.of(context)
          .push(MaterialPageRoute(builder: (_) => const MyDetailsScreen()));
      return;
    }
    if (quote.groups.any((g) => !_methods.containsKey(g.sellerId))) {
      showMessage(context, 'Choose delivery or pickup for every shop.');
      return;
    }
    if (_methods.values.contains('station') && _stationId == null) {
      showMessage(context, 'Choose the WiBuy station where you will collect.');
      return;
    }

    setState(() => _paying = true);
    try {
      final json = await SessionScope.read(context).api.post('/checkout', {
        'source': _isBuyNow ? 'buy' : 'cart',
        ..._lineParams,
        if (_addressId != null) 'address_id': _addressId,
        if (_addressId == null) ...{
          'recipient_name': _name.text.trim(),
          'recipient_phone': _phone.text.trim(),
          'district': _district,
          'address': _addressText.text.trim(),
        },
        'note': _note.text.trim(),
        'methods': _methods.map((k, v) => MapEntry('$k', v)),
        if (_stationId != null) 'station_id': _stationId,
        if (widget.teamToken != null) 'team': widget.teamToken,
        if (_addressId == null && _here != null) ...{'latitude': _here!.latitude, 'longitude': _here!.longitude},
        if (_deliverAt != null) 'deliver_at': _deliverAt!.toUtc().toIso8601String(),
        'operator': _operator,
        'payer_phone': _payerPhone.text.trim(),
        if (_couponId != null && quote.couponError == null)
          'coupon_id': _couponId,
        'payment_plan': _inParts && quote.canPayInParts ? 'milestones' : 'full',
      }) as Map<String, dynamic>;
      final checkout =
          CheckoutInfo.fromJson(json['data'] as Map<String, dynamic>);
      if (!mounted) return;
      SessionScope.read(context).refreshCartCount();
      Navigator.of(context).pushReplacement(
          MaterialPageRoute(builder: (_) => PaymentScreen(initial: checkout)));
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _paying = false);
    }
  }

  /// Office order banner, or for instant food: "about N min", the exact spot and an optional time.
  List<Widget> _instantPanel(Quote quote) {
    final team = quote.team;
    if (team != null) {
      return [
        Card(
          color: WiBuyColors.green.withValues(alpha: 0.08),
          child: ListTile(
            leading: const Icon(Icons.groups),
            title: Text('Office order: ${team['title']}'),
            subtitle: Text('To ${team['place']}. ${team['organiser_pays_delivery'] == true ? 'The organiser pays the delivery: you pay only for your food.' : 'You pay the delivery once for everyone.'}'),
          ),
        ),
      ];
    }
    final instant = quote.groups.where((g) => g.isInstant && _methods[g.sellerId] == 'delivery').toList();
    if (instant.isEmpty) return const [];
    final hasPin = _address?.hasPin == true || _here != null;
    String hm(DateTime d) => '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

    return [
      Card(
        color: Colors.amber.shade50,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('⚡ Instant food delivery', style: TextStyle(fontWeight: FontWeight.w800)),
            for (final g in instant)
              if (g.etaMinutes != null) Text('${g.seller.shopName}: about ${g.etaMinutes} min'),
            if (!hasPin)
              TextButton.icon(
                onPressed: () async {
                  final here = await InstantScreen.locate(context);
                  if (here != null && mounted) {
                    setState(() => _here = here);
                    _requote();
                  }
                },
                icon: const Icon(Icons.my_location),
                label: const Text('The rider needs your exact spot: use my location'),
              ),
            TextButton.icon(
              onPressed: () async {
                final picked = await showTimePicker(context: context, initialTime: const TimeOfDay(hour: 13, minute: 0));
                setState(() => _deliverAt = picked == null ? null : deliveryTime(picked));
              },
              icon: const Icon(Icons.schedule),
              label: Text(_deliverAt == null ? 'As soon as it is cooked (tap to choose a time)' : 'Deliver at ${hm(_deliverAt!)} (tap to change)'),
            ),
            const Text('The shop starts cooking only after you have paid.', style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
          ]),
        ),
      ),
    ];
  }

  @override
  Widget build(BuildContext context) {
    final meta = SessionScope.of(context).meta;
    final districts = meta?.districts ?? [_district];
    final operators = meta?.operators ??
        const [
          MapEntry('ORANGE', 'Orange Money'),
          MapEntry('AFRIMONEY', 'Afrimoney')
        ];
    final quote = _quote;

    return Scaffold(
      appBar: AppBar(title: const Text('Checkout')),
      body: quote == null
          ? (_error != null
              ? ErrorRetry(message: _error.toString(), onRetry: _requote)
              : const Center(child: CircularProgressIndicator()))
          : ListView(padding: const EdgeInsets.all(14), children: [
              ..._instantPanel(quote),
              _section('1. Where are you?'),
              if (_addresses.isNotEmpty)
                Wrap(spacing: 8, runSpacing: 4, children: [
                  for (final a in _addresses)
                    ChoiceChip(
                      avatar: Icon(
                          a.hasPin
                              ? Icons.location_on
                              : Icons.location_on_outlined,
                          size: 18),
                      label: Text(a.label),
                      selected: _addressId == a.id,
                      onSelected: (_) {
                        setState(() {
                          _addressId = a.id;
                          _district = a.district;
                        });
                        _requote();
                      },
                    ),
                  ChoiceChip(
                    label: const Text('Somewhere else'),
                    selected: _addressId == null,
                    onSelected: (_) {
                      setState(() => _addressId = null);
                      _requote();
                    },
                  ),
                ]),
              if (_address != null)
                Padding(
                  padding: const EdgeInsets.only(top: 6),
                  child: Text(
                      '${_address!.recipientName} · ${_address!.oneLine}',
                      style: const TextStyle(color: WiBuyColors.muted)),
                )
              else ...[
                DropdownButtonFormField<String>(
                  initialValue:
                      districts.contains(_district) ? _district : null,
                  decoration: const InputDecoration(labelText: 'District'),
                  items: [
                    for (final d in districts)
                      DropdownMenuItem(value: d, child: Text(d))
                  ],
                  onChanged: (value) {
                    if (value == null) return;
                    setState(() => _district = value);
                    _requote();
                  },
                ),
                TextButton.icon(
                  icon: const Icon(Icons.add_location_alt_outlined),
                  label: const Text('Save an address for next time'),
                  onPressed: () async {
                    final added = await Navigator.of(context).push<bool>(
                        MaterialPageRoute(
                            builder: (_) => const AddressFormScreen()));
                    if (added == true) _loadAddresses();
                  },
                ),
              ],
              _section('2. Delivery or pickup'),
              for (final group in quote.groups) _groupCard(group),
              if (_addressId == null) ...[
                _section('3. Who receives it?'),
                TextField(
                    controller: _name,
                    decoration: const InputDecoration(labelText: 'Name')),
                const SizedBox(height: 10),
                TextField(
                    controller: _phone,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Phone')),
              ],
              if (_needsAddress && _addressId == null) ...[
                const SizedBox(height: 10),
                TextField(
                  controller: _addressText,
                  decoration: const InputDecoration(
                      labelText: 'Address or landmark',
                      hintText: 'Near the mosque, Wilkinson Road'),
                ),
              ],
              const SizedBox(height: 10),
              TextField(
                  controller: _note,
                  decoration: const InputDecoration(
                      labelText: 'Note for the seller (optional)')),
              _section('4. Pay with mobile money'),
              SegmentedButton<String>(
                segments: [
                  for (final o in operators)
                    ButtonSegment(value: o.key, label: Text(o.value))
                ],
                selected: {_operator},
                onSelectionChanged: (s) => setState(() => _operator = s.first),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _payerPhone,
                keyboardType: TextInputType.phone,
                decoration:
                    const InputDecoration(labelText: 'Mobile money number'),
              ),
              if (_coupons.isNotEmpty) ...[
                _section('5. Coupon'),
                for (final coupon in _coupons)
                  CouponTile(
                    coupon,
                    selected: _couponId == coupon.id,
                    onTap: () {
                      setState(() {
                        _couponTouched = true;
                        _couponId = _couponId == coupon.id ? null : coupon.id;
                      });
                      _requote();
                    },
                  ),
                if (quote.couponError != null)
                  Text(quote.couponError!,
                      style: const TextStyle(color: Colors.red)),
              ],
              _section('Shop offer code (optional)'),
              Row(children: [
                Expanded(child: TextField(controller: _promo, textCapitalization: TextCapitalization.characters, decoration: const InputDecoration(hintText: 'FRIDAY10'))),
                const SizedBox(width: 8),
                OutlinedButton(onPressed: _quoting ? null : _requote, child: const Text('Apply')),
              ]),
              if (quote.promoError != null) Text(quote.promoError!, style: const TextStyle(color: Colors.red)),
              for (final g in quote.groups.where((g) => _methods[g.sellerId] == 'delivery')) ...[
                _section('Tip for the rider · ${g.seller.shopName} (all of it goes to them)'),
                Wrap(spacing: 8, children: [
                  for (final amount in const [0, 5, 10, 20])
                    ChoiceChip(
                      label: Text(amount == 0 ? 'No tip' : formatLe('$amount.00')),
                      selected: (_tips[g.sellerId] ?? 0) == amount,
                      onSelected: (_) {
                        setState(() => _tips[g.sellerId] = amount);
                        _requote();
                      },
                    ),
                ]),
              ],
              if (quote.canPayInParts)
                SwitchListTile(
                  contentPadding: EdgeInsets.zero,
                  value: _inParts,
                  onChanged: (v) => setState(() => _inParts = v),
                  title: Text(
                      'Pay in parts: ${quote.depositPercent}% now (${formatLe(quote.depositAmount)})'),
                  subtitle: const Text(
                      'The balance before delivery. You can book a WiBuy inspection first.'),
                ),
              const SizedBox(height: 18),
              _row('Items', quote.itemsTotal),
              if (quote.shopDiscountTotal != '0.00') _row('Shop offers', '-${quote.shopDiscountTotal}'),
              _row('Delivery', quote.deliveryTotal),
              if (quote.tipsTotal != '0.00') _row('Tip for the rider', quote.tipsTotal),
              if (quote.passSavings != '0.00')
                _row('WiBuy Pass free delivery', '-${quote.passSavings}'),
              if (quote.couponDiscount != '0.00')
                _row('Coupon', '-${quote.couponDiscount}'),
              const Divider(),
              _row('Total to pay', quote.amountDue, bold: true),
              const SizedBox(height: 14),
              FilledButton(
                onPressed: _paying || _quoting ? null : _pay,
                child: Text(_paying
                    ? 'Sending payment request…'
                    : (quote.amountDue == '0.00'
                        ? 'Place free order'
                        : 'Pay ${formatLe(_inParts && quote.canPayInParts ? quote.depositAmount : quote.amountDue)}')),
              ),
              const SizedBox(height: 8),
              const Text(
                'Your phone will ask you to approve the payment with your PIN. WiBuy keeps the money safe until you get your order.',
                textAlign: TextAlign.center,
                style: TextStyle(color: WiBuyColors.muted),
              ),
              const SizedBox(height: 24),
            ]),
    );
  }

  Widget _groupCard(QuoteGroup group) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(group.seller.shopName,
                  style: const TextStyle(
                      fontSize: 16, fontWeight: FontWeight.w800)),
              for (final item in group.items)
                Text('${item.quantity} × ${item.title}',
                    maxLines: 1, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 8),
              Wrap(spacing: 8, children: [
                if (group.canDeliver)
                  ChoiceChip(
                    label: Text(
                        'Delivery${group.districtFee != null ? ' (${formatLe(group.districtFee)})' : ''}'),
                    selected: _methods[group.sellerId] == 'delivery',
                    onSelected: (_) => _choose(group, 'delivery'),
                  ),
                if (group.canPickup)
                  ChoiceChip(
                    label: const Text('Pickup (free)'),
                    selected: _methods[group.sellerId] == 'pickup',
                    onSelected: (_) => _choose(group, 'pickup'),
                  ),
                if (group.canStation) ...[
                  ChoiceChip(
                    label: Text(
                        '📦 WiBuy station (${formatLe(group.stationFee)})'),
                    selected: _methods[group.sellerId] == 'station',
                    onSelected: (_) => _choose(group, 'station'),
                  ),
                  ChoiceChip(
                    label: Text(
                        '🛵 WiBuy to my door (${formatLe(group.stationDoorFee)})'),
                    selected: _methods[group.sellerId] == 'station_door',
                    onSelected: (_) => _choose(group, 'station_door'),
                  ),
                ],
              ]),
              if (_methods[group.sellerId] == 'station' &&
                  (_quote?.stations.isNotEmpty ?? false))
                DropdownButtonFormField<int>(
                  isExpanded: true,
                  initialValue: _stationId,
                  decoration: const InputDecoration(
                      labelText: 'Collect at which WiBuy station?'),
                  items: [
                    for (final s in _quote!.stations)
                      DropdownMenuItem(
                          value: (s['id'] as num).toInt(),
                          child: Text('${s['name']} · ${s['district']}',
                              overflow: TextOverflow.ellipsis)),
                  ],
                  onChanged: (id) {
                    setState(() => _stationId = id);
                    _requote();
                  },
                ),
              if (_methods[group.sellerId] == 'station')
                const Text(
                    'We text you a code when it arrives. Show it at the station.',
                    style: TextStyle(color: WiBuyColors.muted)),
              if (_methods[group.sellerId] == 'station_door')
                const Text(
                    'A WiBuy rider brings it from the station. Give the rider the code from our SMS.',
                    style: TextStyle(color: WiBuyColors.muted)),
              if (!group.canDeliver && group.deliveryReason != null)
                Text(group.deliveryReason!,
                    style: const TextStyle(color: Colors.red))
              else if (!group.canDeliver && !group.canPickup)
                const Text('This shop cannot deliver to your district.',
                    style: TextStyle(color: Colors.red)),
              if (_methods[group.sellerId] == 'delivery' &&
                  group.distanceKm != null)
                Text(
                    'About ${group.distanceKm} km from the shop${group.deliveryWindow != null ? ' · usually ${group.deliveryWindow}' : ''} (estimate)',
                    style: const TextStyle(color: WiBuyColors.muted)),
              if (_methods[group.sellerId] == 'pickup' &&
                  group.seller.pickupAddress != null)
                Text('Pick up at: ${group.seller.pickupAddress}',
                    style: const TextStyle(color: WiBuyColors.muted)),
              if (group.freeDelivery)
                const Text('Free delivery with WiBuy Pass ⭐',
                    style: TextStyle(
                        color: WiBuyColors.green, fontWeight: FontWeight.w700)),
              const SizedBox(height: 4),
              Text('Shop total: ${formatLe(group.total)}',
                  style: const TextStyle(fontWeight: FontWeight.w700)),
            ]),
          ),
        ),
      );

  void _choose(QuoteGroup group, String method) {
    setState(() => _methods[group.sellerId] = method);
    _requote();
  }

  Widget _section(String text) => Padding(
        padding: const EdgeInsets.fromLTRB(2, 18, 2, 8),
        child: Text(text,
            style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
      );

  Widget _row(String label, String amount, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(children: [
          Text(label,
              style: TextStyle(
                  fontSize: bold ? 18 : 15,
                  fontWeight: bold ? FontWeight.w800 : FontWeight.w400)),
          const Spacer(),
          Text(formatLe(amount),
              style: TextStyle(
                  fontSize: bold ? 20 : 15,
                  fontWeight: bold ? FontWeight.w900 : FontWeight.w600)),
        ]),
      );
}
