import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/order_detail_screen.dart';
import 'package:wibuy_mobile/screens/seller_screens.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(Future<http.Response> Function(http.Request) handler) async {
  SharedPreferences.setMockInitialValues({});
  return Session(ApiClient(httpClient: MockClient(handler), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

Map<String, dynamic> _order({int? repeat}) => {
      'id': 9, 'number': 'WB-9', 'status': 'completed', 'status_label': 'x', 'subtotal': '100.00', 'delivery_fee': '0.00',
      'total': '100.00', 'delivery_method': 'pickup', 'items': [], 'receipt_url': 'http://test/orders/WB-9/receipt', 'repeat_every_days': repeat,
    };

void main() {
  testWidgets('the buyer buys again and asks to be reminded every 14 days', (tester) async {
    tester.view.physicalSize = const Size(1000, 2400);
    addTearDown(tester.view.reset);
    final posts = <String>[];
    final session = await _session((request) async {
      if (request.method == 'POST') posts.add('${request.url.path} ${request.body}');
      if (request.url.path.endsWith('buy-again')) return _json({'added': 2, 'skipped': [], 'message': 'Added 2 item(s) to your cart.'});
      if (request.url.path.endsWith('/cart')) return _json({'data': {'items': []}});
      return _json({'data': _order(repeat: request.url.path.endsWith('repeat') ? 14 : null)});
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: OrderDetailScreen(orderId: 9))));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Buy again'));
    await tester.pumpAndSettle();
    expect(find.text('Added 2 item(s) to your cart.'), findsOneWidget);

    await tester.tap(find.text('Remind me to reorder'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Every 14 days'));
    await tester.pumpAndSettle();

    expect(posts, ['/api/v1/orders/9/buy-again ', '/api/v1/orders/9/repeat {"every_days":14}']);
    expect(find.text('Reminder: every 14 days'), findsOneWidget);
  });

  testWidgets('a shop helper sees no money on the seller dashboard', (tester) async {
    final session = await _session((_) async => _json({
          'shop_name': 'Kissy Road Wholesale', 'reliability_score': null, 'counts': {'new': 1, 'accepted': 0}, 'product_count': 3,
          'low_stock': [], 'open_rfqs': 0, 'money': null, 'is_owner': false,
        }));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: SellerHomeScreen())));
    await tester.pumpAndSettle();

    expect(find.text('You are a helper in this shop.'), findsOneWidget);
    expect(find.text('Earnings and payouts'), findsNothing);
    expect(find.text('1 new · 0 to ship'), findsOneWidget);
  });
}
