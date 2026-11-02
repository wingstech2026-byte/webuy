import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../api/geo_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/map_widgets.dart';

/// Loads the buyer's saved addresses (default first).
Future<List<SavedAddress>> loadAddresses(BuildContext context) async {
  final json = await SessionScope.read(context).api.get('/addresses') as Map<String, dynamic>;
  return (json['data'] as List).whereType<Map<String, dynamic>>().map(SavedAddress.fromJson).toList();
}

/// "8.4844, -13.2299" (as copied from Google Maps) => (lat, lng); null when empty or unreadable.
/// The server still checks the point is inside Sierra Leone.
(double, double)? parseMapPoint(String text) {
  final parts = text.split(RegExp(r'[,\s]+')).where((p) => p.isNotEmpty).toList();
  if (parts.length != 2) return null;
  final lat = double.tryParse(parts[0]);
  final lng = double.tryParse(parts[1]);
  return lat == null || lng == null ? null : (lat, lng);
}

/// "My addresses": home, shop, office... so checkout is one tap.
class AddressesScreen extends StatefulWidget {
  const AddressesScreen({super.key});

  @override
  State<AddressesScreen> createState() => _AddressesScreenState();
}

class _AddressesScreenState extends State<AddressesScreen> {
  /// Bumped after a change so the list loads again.
  int _version = 0;

  Future<void> _edit([SavedAddress? address]) async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => AddressFormScreen(address: address)));
    if (saved == true) setState(() => _version++);
  }

  Future<void> _delete(SavedAddress address) async {
    try {
      await SessionScope.read(context).api.delete('/addresses/${address.id}');
      setState(() => _version++);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('My addresses')),
        floatingActionButton: FloatingActionButton.extended(onPressed: _edit, icon: const Icon(Icons.add_location_alt), label: const Text('Add address')),
        body: Loader<List<SavedAddress>>(
          key: ValueKey(_version),
          load: () => loadAddresses(context),
          builder: (context, addresses, reload) => addresses.isEmpty
              ? const EmptyState(icon: Icons.location_on_outlined, title: 'No saved addresses yet. Add your home or shop so checkout is quicker.')
              : ListView(padding: const EdgeInsets.fromLTRB(12, 12, 12, 90), children: [
                  for (final a in addresses)
                    Card(
                      child: ListTile(
                        leading: Icon(a.hasPin ? Icons.location_on : Icons.location_on_outlined, color: WiBuyColors.green),
                        title: Text('${a.label}${a.isDefault ? ' (default)' : ''}', style: const TextStyle(fontWeight: FontWeight.w800)),
                        subtitle: Text('${a.recipientName} · ${displayPhone(a.recipientPhone)}\n${a.oneLine}${a.hasPin ? '' : '\nNo map point (landmark only)'}'),
                        isThreeLine: true,
                        onTap: () => _edit(a),
                        trailing: IconButton(icon: const Icon(Icons.delete_outline), tooltip: 'Delete', onPressed: () => _delete(a)),
                      ),
                    ),
                ]),
        ),
      );
}

/// Add or change an address. Typing a landmark always works; a map point is optional and is only
/// sent when the buyer types or pastes it (no location is read in the background).
class AddressFormScreen extends StatefulWidget {
  const AddressFormScreen({super.key, this.address});

  final SavedAddress? address;

  @override
  State<AddressFormScreen> createState() => _AddressFormScreenState();
}

class _AddressFormScreenState extends State<AddressFormScreen> {
  late final _label = TextEditingController(text: widget.address?.label ?? 'Home');
  late final _name = TextEditingController(text: widget.address?.recipientName);
  late final _phone = TextEditingController(text: displayPhone(widget.address?.recipientPhone));
  late final _town = TextEditingController(text: widget.address?.town);
  late final _neighbourhood = TextEditingController(text: widget.address?.neighbourhood);
  late final _street = TextEditingController(text: widget.address?.street);
  late final _landmark = TextEditingController(text: widget.address?.landmark);
  late final _directions = TextEditingController(text: widget.address?.directions);
  late final _building = TextEditingController(text: widget.address?.building);
  late final _floor = TextEditingController(text: widget.address?.floor);
  late final _point = TextEditingController(
      text: widget.address?.hasPin == true ? '${widget.address!.latitude}, ${widget.address!.longitude}' : '');
  late String _district = widget.address?.district ?? 'Western Area Urban';
  late bool _isDefault = widget.address?.isDefault ?? false;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    if (widget.address == null) {
      final user = SessionScope.read(context).user;
      _name.text = user?.name ?? '';
      _phone.text = displayPhone(user?.phone);
    }
  }

  @override
  void dispose() {
    for (final c in [_label, _name, _phone, _town, _neighbourhood, _street, _landmark, _directions, _point, _building, _floor]) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    final point = parseMapPoint(_point.text);
    if (_point.text.trim().isNotEmpty && point == null) {
      showMessage(context, 'Type the map point as two numbers, like 8.4844, -13.2299, or leave it empty.');
      return;
    }
    setState(() => _saving = true);
    final body = {
      'label': _label.text.trim(),
      'recipient_name': _name.text.trim(),
      'recipient_phone': _phone.text.trim(),
      'district': _district,
      'town': _town.text.trim(),
      'neighbourhood': _neighbourhood.text.trim(),
      'street': _street.text.trim(),
      'landmark': _landmark.text.trim(),
      'directions': _directions.text.trim(),
      'building': _building.text.trim(),
      'floor': _floor.text.trim(),
      'latitude': point?.$1,
      'longitude': point?.$2,
      'is_default': _isDefault,
    };
    try {
      final api = SessionScope.read(context).api;
      if (widget.address == null) {
        await api.post('/addresses', body);
      } else {
        await api.put('/addresses/${widget.address!.id}', body);
      }
      if (mounted) Navigator.of(context).pop(true);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final districts = SessionScope.of(context).meta?.districts ?? [_district];

    return Scaffold(
      appBar: AppBar(title: Text(widget.address == null ? 'Add address' : 'Change address')),
      body: ListView(padding: const EdgeInsets.all(14), children: [
        TextField(controller: _label, decoration: const InputDecoration(labelText: 'Name of this place', hintText: 'Home, Shop, Office')),
        const SizedBox(height: 10),
        TextField(controller: _name, decoration: const InputDecoration(labelText: 'Who receives it')),
        const SizedBox(height: 10),
        TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Their phone')),
        const SizedBox(height: 10),
        DropdownButtonFormField<String>(
          initialValue: districts.contains(_district) ? _district : null,
          decoration: const InputDecoration(labelText: 'District'),
          items: [for (final d in districts) DropdownMenuItem(value: d, child: Text(d))],
          onChanged: (v) => setState(() => _district = v ?? _district),
        ),
        const SizedBox(height: 10),
        TextField(controller: _town, decoration: const InputDecoration(labelText: 'Town or city')),
        const SizedBox(height: 10),
        TextField(controller: _neighbourhood, decoration: const InputDecoration(labelText: 'Area / neighbourhood')),
        const SizedBox(height: 10),
        TextField(controller: _street, decoration: const InputDecoration(labelText: 'Street (if it has a name)')),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: TextField(controller: _building, decoration: const InputDecoration(labelText: 'Building (offices)', hintText: 'Africell House'))),
          const SizedBox(width: 8),
          SizedBox(width: 100, child: TextField(controller: _floor, decoration: const InputDecoration(labelText: 'Floor'))),
        ]),
        const SizedBox(height: 10),
        TextField(controller: _landmark, decoration: const InputDecoration(labelText: 'Landmark', hintText: 'Opposite the Total filling station')),
        const SizedBox(height: 10),
        TextField(controller: _directions, maxLines: 2, decoration: const InputDecoration(labelText: 'Directions for the rider (optional)')),
        const SizedBox(height: 14),
        const Text('Map point (optional)', style: TextStyle(fontWeight: FontWeight.w700)),
        const Text('Helps the rider find you and prices delivery by distance.', style: TextStyle(color: WiBuyColors.muted)),
        const SizedBox(height: 6),
        ValueListenableBuilder(
          valueListenable: _point,
          builder: (context, value, _) {
            final point = parseMapPoint(value.text);
            return Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              if (point != null) MapPreview(LatLng(point.$1, point.$2)),
              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.map_outlined),
                    label: Text(point == null ? 'Set on the map' : 'Move the pin'),
                    onPressed: () async {
                      final chosen = await Navigator.of(context).push<LatLng>(MaterialPageRoute(
                        builder: (_) => MapPickerScreen(initial: point == null ? null : LatLng(point.$1, point.$2)),
                      ));
                      if (chosen != null) _point.text = '${chosen.latitude.toStringAsFixed(6)}, ${chosen.longitude.toStringAsFixed(6)}';
                    },
                  ),
                ),
                if (point != null) TextButton(onPressed: _point.clear, child: const Text('Remove')),
              ]),
            ]);
          },
        ),
        // No map (offline or blocked)? The point can still be typed or pasted from Google Maps.
        TextField(
          controller: _point,
          keyboardType: const TextInputType.numberWithOptions(decimal: true, signed: true),
          decoration: const InputDecoration(labelText: 'Or type the point', hintText: '8.4844, -13.2299'),
        ),
        SwitchListTile(
          contentPadding: EdgeInsets.zero,
          value: _isDefault,
          onChanged: (v) => setState(() => _isDefault = v),
          title: const Text('Use this address first'),
        ),
        const SizedBox(height: 10),
        FilledButton(onPressed: _saving ? null : _save, child: Text(_saving ? 'Saving…' : 'Save address')),
      ]),
    );
  }
}
