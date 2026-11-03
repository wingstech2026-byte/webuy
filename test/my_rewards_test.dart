import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/my_rewards_screen.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

void main() {
  testWidgets('my rewards shows points, what ends soon, and changes points for a coupon', (tester) async {
    tester.view.physicalSize = const Size(1000, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final posts = <String>[];
    var points = 600;
    SharedPreferences.setMockInitialValues({});
    final session = Session(
      ApiClient(
        httpClient: MockClient((request) async {
          if (request.method == 'POST') {
            posts.add(request.url.path);
            points = 100;
            return _json({'data': {'id': 9, 'title': 'Points coupon', 'amount': '5.00'}});
          }
          return _json({
            'points': points, 'points_per_coupon': 500, 'coupon_from_points': '5.00', 'can_exchange': points >= 500, 'saved': '30.00',
            'expiring_soon': [{'id': 1, 'title': 'Welcome', 'description': 'Le 10.00 off any order', 'expires_at': '2030-01-04T10:00:00Z'}],
            'usable': [{'id': 1, 'title': 'Welcome', 'description': 'Le 10.00 off any order', 'expires_at': '2030-01-04T10:00:00Z'}],
            'locked': [],
            'history': [{'points': 600, 'reason': 'Daily check-in'}],
          });
        }),
        baseUrl: 'http://test/api/v1',
      ),
      await SharedPreferences.getInstance(),
    );

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: MyRewardsScreen())));
    await tester.pumpAndSettle();

    expect(find.text('600'), findsOneWidget);
    expect(find.text('⏰ Ends soon: use them'), findsOneWidget);
    expect(find.text('Daily check-in'), findsOneWidget);

    await tester.tap(find.textContaining('Change 500 points'));
    await tester.pumpAndSettle();

    expect(posts, ['/api/v1/points/exchange']);
    expect(find.text('100'), findsOneWidget);
  });
}
