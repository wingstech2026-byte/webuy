import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:geolocator/geolocator.dart';
import 'package:latlong2/latlong.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import 'common.dart';

/// Central Freetown, where the map starts when there is no point yet.
const freetown = LatLng(8.4844, -13.2299);

/// The map images, from the server's /meta (OpenStreetMap by default).
TileLayer wibuyTiles(BuildContext context) => TileLayer(
      urlTemplate: SessionScope.of(context).meta?.mapTiles ?? AppMeta.defaultMapTiles,
      userAgentPackageName: 'sl.wibuy.wibuy_mobile',
      maxZoom: 19,
    );

const mapAttribution = SimpleAttributionWidget(source: Text('OpenStreetMap contributors'));

/// Asks for the phone's position once, only after the person tapped "Use my location".
/// Returns null (with a message shown) when location is off, refused or unavailable.
/// The app never follows the person in the background.
Future<LatLng?> currentLocation(BuildContext context) async {
  void say(String text) {
    if (context.mounted) showMessage(context, text);
  }

  try {
    if (!await Geolocator.isLocationServiceEnabled()) {
      say('Location is switched off on this phone. Turn it on, or move the map yourself.');
      return null;
    }
    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
      say('WiBuy may not use your location. You can move the map yourself instead.');
      return null;
    }
    final position = await Geolocator.getCurrentPosition(
      locationSettings: const LocationSettings(accuracy: LocationAccuracy.high, timeLimit: Duration(seconds: 20)),
    );
    return LatLng(position.latitude, position.longitude);
  } on Object {
    say('Could not find your location. Move the map yourself instead.');
    return null;
  }
}

/// Full-screen map with a pin in the middle: move the map until the pin is on the place, then confirm.
/// Pops with the chosen [LatLng], or null when cancelled.
class MapPickerScreen extends StatefulWidget {
  const MapPickerScreen({super.key, this.initial, this.title = 'Set the map point'});

  final LatLng? initial;
  final String title;

  @override
  State<MapPickerScreen> createState() => _MapPickerScreenState();
}

class _MapPickerScreenState extends State<MapPickerScreen> {
  final _map = MapController();
  late LatLng _point = widget.initial ?? freetown;
  bool _locating = false;

  Future<void> _useMyLocation() async {
    setState(() => _locating = true);
    final here = await currentLocation(context);
    if (!mounted) return;
    setState(() => _locating = false);
    if (here != null) {
      _map.move(here, 17);
      setState(() => _point = here);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: Stack(children: [
          FlutterMap(
            mapController: _map,
            options: MapOptions(
              initialCenter: _point,
              initialZoom: widget.initial == null ? 13 : 17,
              onPositionChanged: (camera, _) => _point = camera.center,
              onTap: (_, point) {
                _map.move(point, _map.camera.zoom);
                setState(() => _point = point);
              },
            ),
            children: [wibuyTiles(context), mapAttribution],
          ),
          // The pin stays in the middle; its tip marks the point.
          const IgnorePointer(
            child: Center(
              child: Padding(
                padding: EdgeInsets.only(bottom: 44),
                child: Icon(Icons.location_on, size: 48, color: WiBuyColors.orange),
              ),
            ),
          ),
          Positioned(
            left: 12,
            right: 12,
            top: 12,
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Text('Move the map until the pin is on your door, gate or shop.', style: TextStyle(color: Colors.grey.shade800)),
              ),
            ),
          ),
        ]),
        bottomNavigationBar: SafeArea(
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Row(children: [
              Expanded(
                child: OutlinedButton.icon(
                  icon: const Icon(Icons.my_location),
                  label: Text(_locating ? 'Finding you…' : 'Use my location'),
                  onPressed: _locating ? null : _useMyLocation,
                ),
              ),
              const SizedBox(width: 10),
              Expanded(
                child: FilledButton(
                  onPressed: () => Navigator.of(context).pop(_point),
                  child: const Text('Use this point'),
                ),
              ),
            ]),
          ),
        ),
      );
}

/// A small map that shows one point (not movable). Tapping it is up to the parent.
class MapPreview extends StatelessWidget {
  const MapPreview(this.point, {super.key, this.height = 150});

  final LatLng point;
  final double height;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(12),
        child: SizedBox(
          height: height,
          child: IgnorePointer(
            child: FlutterMap(
              options: MapOptions(initialCenter: point, initialZoom: 16, interactionOptions: const InteractionOptions(flags: InteractiveFlag.none)),
              children: [
                wibuyTiles(context),
                MarkerLayer(markers: [Marker(point: point, width: 40, height: 40, alignment: Alignment.topCenter, child: const Icon(Icons.location_on, size: 40, color: WiBuyColors.orange))]),
              ],
            ),
          ),
        ),
      );
}
