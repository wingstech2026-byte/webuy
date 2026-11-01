import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../api/geo_models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/map_widgets.dart';
import 'shop_screen.dart';

/// One shop near the buyer, with the place buyers may see.
class NearbyShop {
  NearbyShop({required this.slug, required this.name, required this.verified, required this.distanceKm, required this.place, required this.point});

  factory NearbyShop.fromJson(Map<String, dynamic> j) {
    final seller = j['seller'] as Map<String, dynamic>;
    final location = j['location'] as Map<String, dynamic>;
    return NearbyShop(
      slug: seller['slug'].toString(),
      name: seller['shop_name'].toString(),
      verified: seller['is_verified'] == true,
      distanceKm: (j['distance_km'] as num).toDouble(),
      place: ShopPlace.fromJson(location),
      point: LatLng((location['latitude'] as num).toDouble(), (location['longitude'] as num).toDouble()),
    );
  }

  final String slug;
  final String name;
  final bool verified;
  final double distanceKm;
  final ShopPlace place;
  final LatLng point;
}

/// "Shops near me": the buyer chooses where they are (their location, only when tapped, or the map),
/// then sees the closest shops on a map and in a list.
class NearbyShopsScreen extends StatefulWidget {
  const NearbyShopsScreen({super.key});

  @override
  State<NearbyShopsScreen> createState() => _NearbyShopsScreenState();
}

class _NearbyShopsScreenState extends State<NearbyShopsScreen> {
  LatLng? _from;
  List<NearbyShop>? _shops;
  Object? _error;
  bool _busy = false;

  Future<void> _search(LatLng from) async {
    setState(() {
      _from = from;
      _busy = true;
      _error = null;
    });
    try {
      final json = await SessionScope.read(context).api.get('/geo/nearby', query: {
        'lat': from.latitude.toString(),
        'lng': from.longitude.toString(),
        'radius_km': '25',
      }) as Map<String, dynamic>;
      setState(() => _shops = (json['data'] as List).whereType<Map<String, dynamic>>().map(NearbyShop.fromJson).toList());
    } on Object catch (e) {
      setState(() => _error = e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _useMyLocation() async {
    setState(() => _busy = true);
    final here = await currentLocation(context);
    if (!mounted) return;
    setState(() => _busy = false);
    if (here != null) await _search(here);
  }

  Future<void> _pickOnMap() async {
    final chosen = await Navigator.of(context).push<LatLng>(MaterialPageRoute(builder: (_) => MapPickerScreen(initial: _from, title: 'Where are you?')));
    if (chosen != null) await _search(chosen);
  }

  @override
  Widget build(BuildContext context) {
    final from = _from;
    final shops = _shops;

    return Scaffold(
      appBar: AppBar(title: const Text('Shops near me')),
      body: ListView(padding: const EdgeInsets.all(12), children: [
        Row(children: [
          Expanded(
            child: FilledButton.icon(icon: const Icon(Icons.my_location), label: const Text('Use my location'), onPressed: _busy ? null : _useMyLocation),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: OutlinedButton.icon(icon: const Icon(Icons.map_outlined), label: const Text('Choose on map'), onPressed: _busy ? null : _pickOnMap),
          ),
        ]),
        const Padding(
          padding: EdgeInsets.symmetric(vertical: 6),
          child: Text('Your location is used once for this search and is not saved.', style: TextStyle(fontSize: 12, color: WiBuyColors.muted)),
        ),
        if (_busy) const Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
        if (_error != null) ErrorRetry(message: _error.toString(), onRetry: () => from == null ? _useMyLocation() : _search(from)),
        if (from != null && shops != null && !_busy) ...[
          ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: SizedBox(
              height: 260,
              child: FlutterMap(
                options: MapOptions(initialCenter: from, initialZoom: 13),
                children: [
                  wibuyTiles(context),
                  MarkerLayer(markers: [
                    Marker(point: from, width: 22, height: 22, child: const Icon(Icons.radio_button_checked, color: Colors.blue)),
                    for (final s in shops)
                      Marker(
                        point: s.point,
                        width: 40,
                        height: 40,
                        alignment: Alignment.topCenter,
                        child: GestureDetector(
                          onTap: () => _openShop(s),
                          child: Tooltip(message: s.name, child: const Icon(Icons.storefront, size: 32, color: WiBuyColors.green)),
                        ),
                      ),
                  ]),
                  mapAttribution,
                ],
              ),
            ),
          ),
          const SizedBox(height: 10),
          if (shops.isEmpty) const EmptyState(icon: Icons.store_mall_directory_outlined, title: 'No shops on the map within 25 km yet.'),
          for (final s in shops)
            Card(
              child: ListTile(
                leading: const Icon(Icons.storefront, color: WiBuyColors.green),
                title: Text('${s.name}${s.verified ? ' ✓' : ''}', style: const TextStyle(fontWeight: FontWeight.w800)),
                subtitle: Text([
                  '${s.distanceKm} km away (straight line)',
                  if (s.place.address != null) s.place.address!,
                  if (s.place.pickup) 'Pickup available',
                ].join(' · ')),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => _openShop(s),
              ),
            ),
        ],
        if (from == null && !_busy)
          const Padding(
            padding: EdgeInsets.only(top: 40),
            child: EmptyState(icon: Icons.near_me_outlined, title: 'Find shops close to you: use your location or choose a place on the map.'),
          ),
      ]),
    );
  }

  void _openShop(NearbyShop s) => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ShopScreen(slug: s.slug)));
}
