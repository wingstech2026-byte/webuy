import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/chat_screen.dart';
import 'package:wibuy_mobile/screens/seller_screens.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body, [int status = 200]) =>
    http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(MockClient client) async {
  SharedPreferences.setMockInitialValues({
    'wibuy.token': '1|abc',
    'wibuy.user': jsonEncode({'id': 1, 'name': 'Fatu', 'phone': '23276123456', 'display_phone': '076 123 456', 'is_seller': true, 'is_admin': false}),
  });
  return Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

void main() {
  test('photos are uploaded as multipart with the token', () async {
    late http.BaseRequest sent;
    final client = MockClient.streaming((request, body) async {
      sent = request;
      await body.drain<void>();
      return http.StreamedResponse(Stream.value(utf8.encode('{"id": 3}')), 201, headers: {'content-type': 'application/json'});
    });
    final api = ApiClient(httpClient: client, baseUrl: 'http://test/api/v1', token: 'secret');

    final json = await api.postMultipart('/orders/9/problem', {'reason': 'damaged'}, {
      'photos[]': [('a.jpg', [1, 2, 3])],
    }) as Map<String, dynamic>;

    expect(json['id'], 3);
    final multipart = sent as http.MultipartRequest;
    expect(multipart.files.single.field, 'photos[]');
    expect(multipart.fields['reason'], 'damaged');
    expect(multipart.headers['Authorization'], 'Bearer secret');
  });

  testWidgets('the seller overview shows money, orders and quote requests', (tester) async {
    final session = await _session(MockClient((request) async => _json({
          'shop_name': 'Kamara Foods',
          'reliability_score': 88,
          'counts': {'new': 2, 'accepted': 1, 'shipped': 0, 'delivered': 0, 'problems': 0, 'finished': 5},
          'money': {'available': '1200.00', 'pending': '300.00', 'paid_out': '0.00', 'in_transit': '0.00'},
          'min_payout': '100.00',
          'product_count': 12,
          'low_stock': [],
          'open_rfqs': 3,
        })));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: SellerHomeScreen())));
    await tester.pumpAndSettle();

    expect(find.text('Kamara Foods'), findsOneWidget);
    expect(find.text('Le 1,200.00'), findsOneWidget);
    expect(find.text('2 new · 1 to ship'), findsOneWidget);
    expect(find.text('3 open request(s) from buyers'), findsOneWidget);
  });

  testWidgets('chat shows messages, safety warnings and sends text', (tester) async {
    final posted = <String>[];
    final session = await _session(MockClient((request) async {
      if (request.method == 'POST') {
        posted.add(request.body);
        return _json({'data': {'id': 3, 'type': 'text', 'mine': true, 'author': 'You', 'body': 'Thank you'}}, 201);
      }
      return _json({
        'data': [
          {'id': 1, 'type': 'text', 'mine': false, 'author': 'WiBuy team', 'body': 'Hello! How can we help?'},
          {'id': 2, 'type': 'system', 'mine': false, 'author': '', 'body': '⚠️ Safety: never pay outside WiBuy.'},
        ],
        'can_post': true,
      });
    }));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: ChatScreen(id: 7, title: 'WiBuy support'))));
    await tester.pumpAndSettle();

    expect(find.text('Hello! How can we help?'), findsOneWidget);
    expect(find.textContaining('never pay outside WiBuy'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'Thank you');
    await tester.tap(find.byIcon(Icons.send));
    await tester.pumpAndSettle();

    expect(posted.single, contains('Thank you'));
    expect(find.text('Thank you'), findsOneWidget);

    await tester.pumpWidget(const SizedBox()); // stop the polling timer
  });
}
