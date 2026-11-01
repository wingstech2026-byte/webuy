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

Map<String, dynamic> _report(int days) => {
      'days': days,
      'totals': {'orders': 3, 'sales': '1400.00', 'kept': '1330.00', 'average_order': '466.66', 'items_sold': 4, 'buyers': 2, 'repeat_buyers': 1, 'lost_orders': 1},
      'previous_sales': '600.00',
      'weeks': [
        for (var i = 0; i < 8; i++) {'week_start': '2026-09-0$i', 'label': '${i + 1} Sep', 'sales': i == 7 ? '1300.00' : '0.00', 'orders': i == 7 ? 2 : 0},
      ],
      'best_sellers': [
        {'product_id': 1, 'title': 'Rice 50kg', 'quantity': 2, 'sales': '1200.00'},
      ],
      'low_stock': [
        {'id': 3, 'slug': 'sugar', 'title': 'Sugar', 'stock': 0, 'unit': 'bag', 'status': 'active'},
      ],
    };

void main() {
  testWidgets('the sales report shows totals, best sellers and low stock, and changes period', (tester) async {
    final asked = <String>[];
    final session = await _session((request) async {
      asked.add(request.url.query);
      return _json(_report(int.parse(request.url.queryParameters['days']!)));
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: SellerReportScreen())));
    await tester.pumpAndSettle();

    expect(asked.single, 'days=30');
    expect(find.textContaining('1,400'), findsOneWidget);
    expect(find.text('▲ 133% on the 30 days before'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('1. Rice 50kg'), 200);
    expect(find.text('1. Rice 50kg'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Sold out'), 200);
    expect(find.text('Sold out'), findsOneWidget);

    await tester.drag(find.byType(ListView), const Offset(0, 3000));
    await tester.pumpAndSettle();
    await tester.tap(find.text('7 days'));
    await tester.pumpAndSettle();
    expect(asked.last, 'days=7');
  });

  testWidgets('rating an order sends a multipart review for every product', (tester) async {
    final posts = <String>[];
    final order = {
      'id': 9, 'number': 'WB-9', 'status': 'completed', 'status_label': 'Completed', 'subtotal': '600.00', 'delivery_fee': '0.00', 'total': '600.00',
      'delivery_method': 'pickup', 'can_review': true,
      'items': [
        {'id': 21, 'title': 'Rice 50kg', 'quantity': 1, 'unit_price': '600.00', 'line_total': '600.00', 'unit': 'bag'},
      ],
    };
    final session = await _session((request) async {
      if (request.method == 'POST') posts.add('${request.headers['content-type']}\n${request.body}');
      return _json({'data': order});
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: OrderDetailScreen(orderId: 9))));
    await tester.pumpAndSettle();
    await tester.scrollUntilVisible(find.text('Rate this order'), 200);
    await tester.tap(find.text('Rate this order'));
    await tester.pumpAndSettle();
    expect(find.text('Photos of what you got (optional) (0/4)'), findsOneWidget);
    await tester.tap(find.text('Send review'));
    await tester.pumpAndSettle();

    expect(posts.single, startsWith('multipart/form-data'));
    expect(posts.single, contains('name="items[21][rating]"\r\n\r\n5'));
    expect(posts.single, contains('name="seller_rating"\r\n\r\n5'));
  });

  testWidgets('the order shows the rider and their steps', (tester) async {
    final order = {
      'id': 9, 'number': 'WB-9', 'status': 'shipped', 'status_label': 'On the way', 'subtotal': '600.00', 'delivery_fee': '20.00', 'total': '620.00',
      'delivery_method': 'delivery', 'items': [],
      'rider_delivery': {
        'stage': 'picked_up', 'label': 'The rider has your order and is coming to you', 'rider_name': 'Ibrahim Rider', 'rider_phone': '23277100001',
        'tracking': {
          'rider': {'lat': 8.48, 'lng': -13.23}, 'destination': {'lat': 8.47, 'lng': -13.26}, 'km': 2.4, 'minutes': 8, 'live': true,
          'updated_at': '2026-11-01T10:40:00Z', 'to_station': null,
        },
        'steps': [
          {'label': 'Rider found', 'at': '2026-11-01T10:00:00Z'},
          {'label': 'Rider collected your order', 'at': '2026-11-01T10:30:00Z'},
          {'label': 'Handed over to you', 'at': null},
        ],
      },
    };
    final session = await _session((request) async => _json({'data': order}));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: OrderDetailScreen(orderId: 9))));
    await tester.pumpAndSettle();

    expect(find.text('🛵 The rider has your order and is coming to you'), findsOneWidget);
    expect(find.text('Rider: Ibrahim Rider · 077 100 001'), findsOneWidget);
    expect(find.text('Handed over to you'), findsOneWidget);
    expect(find.textContaining('4-digit code'), findsOneWidget);
    expect(find.text('About 2.4 km away · about 8 min'), findsOneWidget);
    expect(find.text('🛵'), findsOneWidget); // the rider on the map
    await tester.pumpWidget(const SizedBox()); // stop the 20-second refresh
  });
}
