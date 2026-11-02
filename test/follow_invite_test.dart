import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/following_screen.dart';
import 'package:wibuy_mobile/screens/shop_screen.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(Future<http.Response> Function(http.Request) handler) async {
  SharedPreferences.setMockInitialValues({
    'wibuy.token': '1|abc',
    'wibuy.user': jsonEncode({'id': 1, 'name': 'Ami Kamara', 'phone': '23276123456', 'display_phone': '076 123 456', 'is_seller': false, 'is_admin': false}),
  });
  return Session(ApiClient(httpClient: MockClient(handler), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

void main() {
  testWidgets('a buyer follows a shop from its page', (tester) async {
    final posts = <String>[];
    final session = await _session((request) async {
      if (request.method == 'POST') {
        posts.add(request.url.path);
        return _json({'is_following': true, 'followers': 13});
      }
      return _json({
        'data': {'id': 3, 'slug': 'kissy-road', 'shop_name': 'Kissy Road Wholesale', 'location': 'Freetown', 'is_verified': true},
        'products': [], 'followers': 12, 'is_following': false,
      });
    });

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: ShopScreen(slug: 'kissy-road'))));
    await tester.pumpAndSettle();
    expect(find.text('12 follower(s)'), findsOneWidget);

    await tester.tap(find.text('+ Follow'));
    await tester.pumpAndSettle();

    expect(posts, ['/api/v1/shops/kissy-road/follow']);
    expect(find.text('✓ Following'), findsOneWidget);
    expect(find.text('13 follower(s)'), findsOneWidget);
  });

  testWidgets('invite friends shows the link and how many joined and ordered', (tester) async {
    final session = await _session((_) async => _json({
          'data': {'link': 'http://test/?ref=ABC1234', 'code': 'ABC1234', 'joined': 3, 'ordered': 1, 'coupon': '20.00', 'points_join': 100, 'points_order': 200},
        }));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: InviteScreen())));
    await tester.pumpAndSettle();

    expect(find.text('http://test/?ref=ABC1234'), findsOneWidget);
    expect(find.text('3'), findsOneWidget);
    expect(find.textContaining('you both get a Le 20.00 coupon'), findsOneWidget);
  });
}
