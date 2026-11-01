import 'package:flutter_test/flutter_test.dart';
import 'package:latlong2/latlong.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/screens/nearby_screen.dart';

void main() {
  test('a nearby home business shows its area point but no address', () {
    final shop = NearbyShop.fromJson({
      'seller': {'slug': 'aminas-kitchen', 'shop_name': "Amina's Kitchen", 'is_verified': false},
      'distance_km': 1.4,
      'location': {
        'name': "Amina's Kitchen",
        'address': null,
        'district': 'Western Area Urban',
        'pickup': true,
        'verified': false,
        'latitude': 8.49,
        'longitude': -13.23,
      },
    });

    expect(shop.point, const LatLng(8.49, -13.23));
    expect(shop.place.address, isNull);
    expect(shop.place.pickup, isTrue);
    expect(shop.distanceKm, 1.4);
  });

  test('the map image address comes from the server, with OpenStreetMap as the fallback', () {
    final meta = AppMeta.fromJson({'districts': ['Bo'], 'operators': [], 'web_url': 'http://x', 'map_tiles': 'https://tiles.example/{z}/{x}/{y}.png'});
    expect(meta.mapTiles, 'https://tiles.example/{z}/{x}/{y}.png');
    expect(AppMeta.fromJson({'districts': ['Bo'], 'operators': [], 'web_url': 'http://x'}).mapTiles, AppMeta.defaultMapTiles);
  });
}
