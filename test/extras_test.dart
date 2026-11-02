import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/screens/order_detail_screen.dart';
import 'package:wibuy_mobile/session.dart';
import 'package:wibuy_mobile/widgets/common.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(Future<http.Response> Function(http.Request) handler) async {
  SharedPreferences.setMockInitialValues({});
  return Session(ApiClient(httpClient: MockClient(handler), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

Map<String, dynamic> _order({bool canCancel = false, Map<String, dynamic>? rider}) => {
      'id': 9, 'number': 'WB-9', 'status': canCancel ? 'paid' : 'delivered', 'status_label': 'x', 'subtotal': '100.00', 'delivery_fee': '0.00',
      'total': '100.00', 'delivery_method': 'delivery', 'items': [], 'can_cancel': canCancel, 'rider_delivery': rider,
    };

void main() {
  testWidgets('a sponsored product is labelled', (tester) async {
    final session = await _session((_) async => _json({}));
    final product = Product.fromJson({'id': 1, 'slug': 'rice', 'title': 'Rice B', 'price': '10.00', 'unit': 'bag', 'stock': 5, 'min_order_quantity': 1, 'tiers': [], 'is_sponsored': true});

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: Scaffold(body: SizedBox(width: 200, height: 320, child: ProductCard(product))))));

    expect(find.text('Sponsored'), findsOneWidget);
  });

  testWidgets('the buyer cancels before the shop accepts', (tester) async {
    final posts = <String>[];
    final session = await _session((request) async {
      if (request.method == 'POST') posts.add(request.url.path);
      return _json({'data': _order(canCancel: request.method != 'POST')});
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: OrderDetailScreen(orderId: 9))));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Cancel my order (full refund)'), 200);
    await tester.tap(find.text('Cancel my order (full refund)'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel order'));
    await tester.pumpAndSettle();

    expect(posts, ['/api/v1/orders/9/cancel']);
    expect(find.text('Cancel my order (full refund)'), findsNothing);
  });

  testWidgets('the buyer rates the rider with stars', (tester) async {
    final bodies = <String>[];
    final rider = {'stage': 'delivered', 'label': 'Delivered by the WiBuy rider', 'rider_name': 'Ibrahim', 'steps': [], 'can_rate': true, 'rating': null};
    final session = await _session((request) async {
      if (request.method == 'POST') bodies.add(request.body);
      return _json({'data': _order(rider: request.method == 'POST' ? {...rider, 'can_rate': false, 'rating': 4} : rider)});
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: OrderDetailScreen(orderId: 9))));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Rate the rider Ibrahim'), 200);
    await tester.tap(find.text('Rate the rider Ibrahim'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('★★★★'));
    await tester.pumpAndSettle();

    expect(jsonDecode(bodies.single), {'stars': 4});
    expect(find.text('Rate the rider Ibrahim'), findsNothing);
  });
}
