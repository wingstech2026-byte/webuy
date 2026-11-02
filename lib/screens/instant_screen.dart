import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/map_widgets.dart';
import 'checkout_screen.dart';

/// ⚡ Instant: hot food from shops near you in about 30-60 minutes, or at a time you choose. Office team orders too.
class InstantScreen extends StatefulWidget {
  const InstantScreen({super.key});

  /// Where the buyer is (replaced in tests).
  static Future<LatLng?> Function(BuildContext) locate = currentLocation;

  @override
  State<InstantScreen> createState() => _InstantScreenState();
}

class _InstantScreenState extends State<InstantScreen> {
  LatLng? _here;
  int _version = 0;

  Future<void> _locate() async {
    final here = await InstantScreen.locate(context);
    if (here != null && mounted) {
      setState(() {
        _here = here;
        _version++;
      });
    }
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _locate());
  }

  Future<void> _startTeam(Seller seller, List<int> closeOptions) async {
    final session = SessionScope.read(context);
    final here = _here;
    if (!session.isLoggedIn || here == null) {
      showMessage(context, here == null ? 'Share your location first, so the rider finds the office.' : 'Log in to open an office order.');
      return;
    }
    final team = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      builder: (_) => _StartTeamSheet(seller: seller, here: here, closeOptions: closeOptions),
    );
    if (team != null && mounted) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => TeamOrderScreen(token: team['token'] as String)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final here = _here;

    return Scaffold(
      appBar: AppBar(title: const Text('⚡ Instant food')),
      body: Loader<Map<String, dynamic>>(
        key: ValueKey(_version),
        load: () async => await SessionScope.read(context).api.get(here == null ? '/instant' : '/instant?lat=${here.latitude}&lng=${here.longitude}') as Map<String, dynamic>,
        builder: (context, json, reload) {
          final shops = (json['data'] as List).whereType<Map<String, dynamic>>().toList();
          final closeOptions = ((json['close_options'] as List?) ?? const [30]).map((e) => (e as num).toInt()).toList();

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(12), children: [
              const Text('Hot food brought by a WiBuy rider in about 30–60 minutes, or at a time you choose. Pay by mobile money: the shop cooks once you have paid.',
                  style: TextStyle(color: WiBuyColors.muted)),
              if (here == null)
                Card(
                  color: Colors.amber.shade50,
                  child: ListTile(
                    leading: const Icon(Icons.my_location),
                    title: const Text('Share your location to see who delivers to you'),
                    trailing: FilledButton(onPressed: _locate, child: const Text('Use my location')),
                  ),
                ),
              if (shops.isEmpty)
                Padding(padding: const EdgeInsets.all(20), child: Text(here == null ? 'No food shops yet.' : 'No food shop delivers to this spot yet.')),
              for (final row in shops) ...[
                const SizedBox(height: 12),
                _ShopHeader(row: row, onTeam: () => _startTeam(Seller.fromJson(row['seller'] as Map<String, dynamic>), closeOptions)),
                ProductGrid((row['products'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList()),
              ],
            ]),
          );
        },
      ),
    );
  }
}

class _ShopHeader extends StatelessWidget {
  const _ShopHeader({required this.row, required this.onTeam});

  final Map<String, dynamic> row;
  final VoidCallback onTeam;

  @override
  Widget build(BuildContext context) {
    final seller = row['seller'] as Map<String, dynamic>;
    final open = row['is_open'] == true;
    final eta = row['eta_minutes'];

    return Row(children: [
      Expanded(
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('${seller['shop_name']}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
          Text([
            open ? 'Open' : 'Closed now: order for later',
            if (seller['hours'] != null) '${seller['hours']}',
            if (eta != null) '⏱ about $eta min · ${row['distance_km']} km',
          ].join(' · '), style: TextStyle(color: open ? WiBuyColors.green : WiBuyColors.muted)),
        ]),
      ),
      TextButton.icon(onPressed: onTeam, icon: const Icon(Icons.groups_outlined), label: const Text('Office order')),
    ]);
  }
}

/// Open an office team order: what it is called, where the office is, until when colleagues can join.
class _StartTeamSheet extends StatefulWidget {
  const _StartTeamSheet({required this.seller, required this.here, required this.closeOptions});

  final Seller seller;
  final LatLng here;
  final List<int> closeOptions;

  @override
  State<_StartTeamSheet> createState() => _StartTeamSheetState();
}

class _StartTeamSheetState extends State<_StartTeamSheet> {
  final _title = TextEditingController(text: 'Lunch at the office');
  final _building = TextEditingController();
  final _floor = TextEditingController();
  final _address = TextEditingController();
  late final _name = TextEditingController(text: SessionScope.read(context).user?.name ?? '');
  late final _phone = TextEditingController(text: displayPhone(SessionScope.read(context).user?.phone));
  late int _closesIn = widget.closeOptions.contains(30) ? 30 : widget.closeOptions.first;
  TimeOfDay? _time;
  bool _busy = false;

  @override
  void dispose() {
    for (final c in [_title, _building, _floor, _address, _name, _phone]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _open() async {
    final session = SessionScope.read(context);
    setState(() => _busy = true);
    try {
      final json = await session.api.post('/team', {
        'shop': widget.seller.slug,
        'title': _title.text.trim(),
        'closes_in': _closesIn,
        if (_time != null) 'deliver_at': deliveryTime(_time!).toUtc().toIso8601String(),
        'name': _name.text.trim(),
        'phone': _phone.text.trim(),
        'district': session.user?.district ?? 'Western Area Urban',
        'address': _address.text.trim(),
        'building': _building.text.trim(),
        'floor': _floor.text.trim(),
        'latitude': widget.here.latitude,
        'longitude': widget.here.longitude,
      }) as Map<String, dynamic>;
      if (mounted) Navigator.of(context).pop(json['data'] as Map<String, dynamic>);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(16, 16, 16, 16 + MediaQuery.of(context).viewInsets.bottom),
        child: SingleChildScrollView(
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('Office order from ${widget.seller.shopName}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
            const Text('Colleagues add their own meals and pay for them. You pay the delivery once, and it all comes together.', style: TextStyle(color: WiBuyColors.muted)),
            TextField(controller: _title, decoration: const InputDecoration(labelText: 'Name')),
            Row(children: [
              Expanded(child: TextField(controller: _building, decoration: const InputDecoration(labelText: 'Building'))),
              const SizedBox(width: 8),
              SizedBox(width: 90, child: TextField(controller: _floor, decoration: const InputDecoration(labelText: 'Floor'))),
            ]),
            TextField(controller: _address, decoration: const InputDecoration(labelText: 'Street and area')),
            Row(children: [
              Expanded(child: TextField(controller: _name, decoration: const InputDecoration(labelText: 'Who receives it'))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone for the rider'))),
            ]),
            DropdownButtonFormField<int>(
              initialValue: _closesIn,
              decoration: const InputDecoration(labelText: 'Colleagues can join for'),
              items: [for (final m in widget.closeOptions) DropdownMenuItem(value: m, child: Text('$m minutes'))],
              onChanged: (v) => setState(() => _closesIn = v ?? _closesIn),
            ),
            TextButton.icon(
              onPressed: () async {
                final picked = await showTimePicker(context: context, initialTime: const TimeOfDay(hour: 13, minute: 0));
                if (picked != null) setState(() => _time = picked);
              },
              icon: const Icon(Icons.schedule),
              label: Text(_time == null ? 'Deliver as soon as it is cooked (tap to choose a time)' : 'Deliver at ${_time!.format(context)}'),
            ),
            const SizedBox(height: 8),
            FilledButton(onPressed: _busy ? null : _open, child: const Text('Open the office order')),
          ]),
        ),
      );
}

/// The next time it is [time]: today, or tomorrow when that is already past.
DateTime deliveryTime(TimeOfDay time) {
  final now = DateTime.now();
  final today = DateTime(now.year, now.month, now.day, time.hour, time.minute);
  return today.isAfter(now) ? today : today.add(const Duration(days: 1));
}

/// An office team order: who is in, until when, the shop's dishes, and "check out for this office order".
class TeamOrderScreen extends StatelessWidget {
  const TeamOrderScreen({super.key, required this.token});

  final String token;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Office order')),
        body: Loader<Map<String, dynamic>>(
          load: () async => await SessionScope.read(context).api.get('/team/$token') as Map<String, dynamic>,
          builder: (context, json, reload) {
            final team = json['data'] as Map<String, dynamic>;
            final products = (json['products'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();
            final members = (team['members'] as List).whereType<Map<String, dynamic>>().toList();
            final open = team['is_open'] == true;
            final deliverAt = team['deliver_at'] == null ? null : DateTime.tryParse(team['deliver_at'] as String)?.toLocal();
            final closesAt = DateTime.tryParse(team['closes_at'] as String)?.toLocal();
            String hm(DateTime d) => '${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(12), children: [
                Text('${team['title']}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
                Text('From ${(team['shop'] as Map)['name']} · opened by ${team['organiser']}'),
                Text('To ${team['place']}'),
                Text([
                  deliverAt == null ? '⚡ As soon as it is cooked' : '🕐 Delivery at ${hm(deliverAt)}',
                  if (open && closesAt != null) 'open until ${hm(closesAt)}' else if (!open) 'closed',
                ].join(' · '), style: const TextStyle(fontWeight: FontWeight.w700)),
                const Text('Each person pays for their own food. The organiser pays the delivery once.', style: TextStyle(color: WiBuyColors.muted)),
                if (members.isNotEmpty) Text('Already in: ${members.map((m) => '${m['name']} (${m['items']})').join(', ')}'),
                if (team['is_organiser'] == true && team['organiser_has_ordered'] != true && open)
                  const Text('Add your own meal too: your order carries the delivery fee for everyone.', style: TextStyle(color: WiBuyColors.orange)),
                const SizedBox(height: 8),
                if (open)
                  Wrap(spacing: 8, children: [
                    FilledButton.icon(
                      onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => CheckoutScreen(teamToken: token))),
                      icon: const Icon(Icons.shopping_bag_outlined),
                      label: const Text('Check out my cart for this order'),
                    ),
                    OutlinedButton.icon(
                      onPressed: () => openLink(context, 'https://wa.me/?text=${Uri.encodeComponent('Lunch order from ${(team['shop'] as Map)['name']}: add your meal here ${team['share_url']}')}'),
                      icon: const Icon(Icons.share),
                      label: const Text('Share on WhatsApp'),
                    ),
                    if (team['is_organiser'] == true)
                      OutlinedButton(
                        onPressed: () async {
                          try {
                            await SessionScope.read(context).api.post('/team/$token/close');
                            await reload();
                          } on Object catch (e) {
                            if (context.mounted) showMessage(context, e);
                          }
                        },
                        child: const Text('Close it now'),
                      ),
                  ]),
                if (open) ...[
                  const SizedBox(height: 12),
                  const Text('Add your meal to the cart', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                  ProductGrid(products),
                ],
              ]),
            );
          },
        ),
      );
}
