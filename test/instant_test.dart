import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/instant_screen.dart';
import 'package:wibuy_mobile/screens/rider_screen.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(Future<http.Response> Function(http.Request) handler) async {
  SharedPreferences.setMockInitialValues({});
  return Session(ApiClient(httpClient: MockClient(handler), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

Map<String, dynamic> _product(String title) =>
    {'id': 1, 'slug': 'jollof', 'title': title, 'price': '50.00', 'unit': 'plate', 'stock': 9, 'min_order_quantity': 1, 'tiers': []};

void main() {
  setUp(() => InstantScreen.locate = (_) async => const LatLng(8.484, -13.2299));

  testWidgets('food shops near the buyer show with their delivery time', (tester) async {
    tester.view.physicalSize = const Size(1000, 2400);
    addTearDown(tester.view.reset);
    final asked = <String>[];
    final session = await _session((request) async {
      asked.add(request.url.toString());
      return _json({
        'data': [
          {
            'seller': {'id': 3, 'slug': 'mama-fatu', 'shop_name': 'Mama Fatu Kitchen', 'location': 'Freetown', 'is_verified': false, 'hours': '08:00–20:00'},
            'distance_km': 1.0, 'eta_minutes': 29, 'is_open': true, 'products': [_product('Jollof rice')],
          },
        ],
        'close_options': [30, 60],
      });
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: InstantScreen())));
    await tester.pumpAndSettle();

    expect(asked.last, contains('/instant?lat=8.484&lng=-13.2299'));
    expect(find.text('Mama Fatu Kitchen'), findsOneWidget);
    expect(find.textContaining('about 29 min'), findsOneWidget);
    expect(find.text('Jollof rice'), findsOneWidget);
  });

  testWidgets('an office order shows who is in and lets colleagues check out for it', (tester) async {
    tester.view.physicalSize = const Size(1000, 2400);
    addTearDown(tester.view.reset);
    final session = await _session((_) async => _json({
          'data': {
            'token': 'abc', 'title': 'Friday lunch', 'shop': {'name': 'Mama Fatu Kitchen'}, 'organiser': 'Aminata', 'place': 'Africell House, floor 3',
            'deliver_at': null, 'closes_at': DateTime.now().add(const Duration(minutes: 30)).toIso8601String(), 'is_open': true,
            'is_organiser': false, 'organiser_has_ordered': true, 'members': [{'name': 'Aminata', 'items': 2}], 'share_url': 'http://test/team/abc',
          },
          'products': [_product('Jollof rice')],
        }));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: TeamOrderScreen(token: 'abc'))));
    await tester.pumpAndSettle();

    expect(find.text('Friday lunch'), findsOneWidget);
    expect(find.text('To Africell House, floor 3'), findsOneWidget);
    expect(find.text('Already in: Aminata (2)'), findsOneWidget);
    expect(find.text('Check out my cart for this order'), findsOneWidget);
  });

  testWidgets('a rider says they are available with their spot, and food jobs are marked', (tester) async {
    RiderJobScreen.locate = () async => const LatLng(8.49, -13.23);
    final bodies = <String>[];
    var available = false;
    final session = await _session((request) async {
      if (request.method == 'POST') {
        bodies.add(request.body);
        available = true;
        return _json({'available_until': '2030-01-01T15:00:00Z'});
      }
      return _json({
        'open': [
          {'id': 1, 'status': 'open', 'status_label': 'Open', 'fee': '15.00', 'pickup_name': 'Mama Fatu Kitchen', 'is_instant': true, 'is_mine': false},
        ],
        'mine': [], 'delivered_count': 0, 'delivered_fees': '0.00', 'available_until': available ? '2030-01-01T15:00:00Z' : null,
      });
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: RiderJobsScreen())));
    await tester.pumpAndSettle();
    expect(find.text('⚡'), findsOneWidget);

    await tester.tap(find.text("I'm available"));
    await tester.pumpAndSettle();

    expect(jsonDecode(bodies.single), {'lat': 8.49, 'lng': -13.23});
    expect(find.text("I'm off"), findsOneWidget);
  });
}
