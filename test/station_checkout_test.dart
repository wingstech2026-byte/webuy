import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/checkout_screen.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

Map<String, dynamic> _quote(String? method) => {
      'source': 'buy',
      'district': 'Western Area Urban',
      'groups': [
        {
          'seller': {'id': 7, 'slug': 'kissy', 'shop_name': 'Kissy Road Wholesale'},
          'items': [
            {'product': {'id': 1, 'slug': 'sugar', 'title': 'Sugar 50kg', 'price': '500.00'}, 'quantity': 1, 'unit_price': '500.00', 'line_total': '500.00'},
          ],
          'subtotal': '500.00', 'method': method, 'can_deliver': true, 'can_pickup': true, 'district_fee': '20.00',
          'delivery_fee': method == 'station' ? '25.00' : '20.00', 'total': '525.00', 'discount': '0.00',
          'can_station': true, 'station_fee': '25.00', 'station_door_fee': '30.00',
        },
      ],
      'items_total': '500.00', 'delivery_total': '25.00', 'grand_total': '525.00', 'amount_due': '525.00', 'coupons': [],
      'stations': [
        {'id': 3, 'name': 'Bo Phone Stand', 'address': 'Main Motor Road', 'district': 'Bo', 'opening_hours': '8am–8pm'},
        {'id': 4, 'name': 'Kissy Mini Mart', 'address': 'Kissy Road', 'district': 'Western Area Urban', 'opening_hours': null},
      ],
    };

void main() {
  testWidgets('the buyer chooses a WiBuy station and it goes with the quote', (tester) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(800, 3000);
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({});
    final quotes = <Map<String, dynamic>>[];
    final session = Session(
      ApiClient(
        httpClient: MockClient((request) async {
          if (request.url.path.endsWith('/checkout/quote')) {
            final body = jsonDecode(request.body) as Map<String, dynamic>;
            quotes.add(body);
            return _json(_quote((body['methods'] as Map?)?['7'] as String?));
          }
          return _json({'data': []}); // saved addresses
        }),
        baseUrl: 'http://test/api/v1',
      ),
      await SharedPreferences.getInstance(),
    );

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: CheckoutScreen(productSlug: 'sugar', quantity: 1))));
    await tester.pumpAndSettle();

    expect(find.text('📦 WiBuy station (Le 25.00)'), findsOneWidget);
    expect(find.text('🛵 WiBuy to my door (Le 30.00)'), findsOneWidget);

    await tester.tap(find.text('📦 WiBuy station (Le 25.00)'));
    await tester.pumpAndSettle();
    expect(quotes.last['methods'], {'7': 'station'});

    await tester.tap(find.text('Collect at which WiBuy station?'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bo Phone Stand · Bo').last);
    await tester.pumpAndSettle();

    expect(quotes.last['station_id'], 3);
    expect(find.textContaining('We text you a code when it arrives'), findsOneWidget);
  });
}
