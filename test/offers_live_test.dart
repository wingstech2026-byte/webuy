import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/live_screen.dart';
import 'package:wibuy_mobile/screens/product_screen.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body, [int status = 200]) => http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(Future<http.Response> Function(http.Request) handler, {bool loggedIn = true}) async {
  SharedPreferences.setMockInitialValues(loggedIn
      ? {
          'wibuy.token': '1|abc',
          'wibuy.user': jsonEncode({'id': 1, 'name': 'Ami Kamara', 'phone': '23276123456', 'display_phone': '076 123 456', 'is_seller': false, 'is_admin': false, 'district': 'Western Area Urban', 'area': 'Lumley'}),
        }
      : {});
  return Session(ApiClient(httpClient: MockClient(handler), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

Map<String, dynamic> _product() =>
    {'id': 1, 'slug': 'jollof', 'title': 'Jollof rice', 'price': '50.00', 'unit': 'plate', 'stock': 9, 'min_order_quantity': 1, 'tiers': []};

void main() {
  testWidgets('a dish shows its extras and offers, and the extras go with it into the cart', (tester) async {
    tester.view.physicalSize = const Size(1000, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final posts = <String>[];
    final session = await _session((request) async {
      if (request.method == 'POST') {
        posts.add('${request.url.path} ${request.body}');
        return _json({'count': 1, 'groups': []}, 201);
      }
      return _json({
        'data': _product(), 'reviews': [], 'also_bought': [], 'questions': [],
        'addons': [{'id': 7, 'name': 'Extra meat', 'price': '15.00'}],
        'offers': [{'label': 'Buy 2 Jollof rice, get 1 free'}],
        'bundles': [{'id': 3, 'title': 'Lunch pack', 'price': '60.00', 'normal_price': '70.00', 'items': ['1 × Jollof rice', '1 × Juice']}],
      });
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: ProductScreen(slug: 'jollof'))));
    await tester.pumpAndSettle();

    expect(find.text('🏷 Buy 2 Jollof rice, get 1 free'), findsOneWidget);
    expect(find.textContaining('Lunch pack'), findsOneWidget);
    await tester.tap(find.textContaining('Extra meat'));
    await tester.pump();
    await tester.tap(find.text('Add to cart'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Add all'));
    await tester.pumpAndSettle();

    expect(posts.first, '/api/v1/cart/jollof {"quantity":1,"addons":[7]}');
    expect(posts.last, '/api/v1/bundles/3/cart ');
  });

  testWidgets('a live show shows the pinned product and sends comments', (tester) async {
    tester.view.physicalSize = const Size(1000, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final comments = <String>[];
    final session = await _session((request) async {
      if (request.method == 'POST') {
        comments.add(request.body);
        return _json({'status': 'live', 'pinned': null, 'comments': [{'id': 2, 'name': 'Musa', 'body': 'Price?'}]}, 201);
      }
      return _json({
        'data': {'id': 5, 'title': 'New phones', 'status': 'live', 'shop_name': 'Kissy Road Wholesale', 'stream_url': 'https://youtube.com/live/abc'},
        'products': [_product()],
        'feed': {'status': 'live', 'pinned': {'id': 1, 'title': 'Jollof rice', 'slug': 'jollof', 'price': '50.00'}, 'comments': [{'id': 1, 'name': 'Fatmata', 'body': 'Hello'}]},
      });
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: LiveShowScreen(id: 5))));
    await tester.pumpAndSettle();

    expect(find.text('🔴 LIVE'), findsOneWidget);
    expect(find.text('Showing now: Jollof rice'), findsOneWidget);
    expect(find.text('Fatmata: Hello'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'Price?');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();

    expect(jsonDecode(comments.single), {'body': 'Price?'});
    expect(find.text('Musa: Price?'), findsOneWidget);
    await tester.pumpWidget(const SizedBox()); // stops the refresh timer
  });
}
