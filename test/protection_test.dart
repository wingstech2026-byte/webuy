import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/home_screen.dart';
import 'package:wibuy_mobile/screens/product_screen.dart';
import 'package:wibuy_mobile/screens/seller_screens.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

Map<String, dynamic> _product(int id, String title, {bool genuine = false, String? video}) => {
      'id': id, 'slug': 'p$id', 'title': title, 'price': '100.00', 'unit': 'piece', 'stock': 5, 'min_order_quantity': 1, 'tiers': [], 'variants': [],
      'genuine_guarantee': genuine, 'video_url': video, 'video_seconds': video == null ? null : 20,
    };

Future<Session> _session(Future<http.Response> Function(http.Request) handler, [Map<String, Object> prefs = const {}]) async {
  SharedPreferences.setMockInitialValues(prefs);
  return Session(ApiClient(httpClient: MockClient(handler), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

void main() {
  testWidgets('a product shows the genuine guarantee, the video button and what others also bought', (tester) async {
    // Wide enough for the test font (wider than Roboto) in the Save/Share rows.
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(700, 3000);
    addTearDown(tester.view.reset);
    final session = await _session((_) async => _json({
          'data': _product(1, 'Tecno Spark', genuine: true, video: 'http://x/v.mp4'),
          'reviews': [],
          'also_bought': [_product(2, 'Phone case')],
        }));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: ProductScreen(slug: 'p1'))));
    await tester.pumpAndSettle();

    expect(find.textContaining('Genuine guarantee'), findsOneWidget);
    expect(find.text("Watch the seller's video (20s)"), findsOneWidget);
    expect(find.text('People who bought this also bought'), findsOneWidget);
    expect(find.textContaining('Phone case'), findsOneWidget);
  });

  testWidgets('the home screen asks for "For you" with the phone\'s recently viewed list', (tester) async {
    tester.view.physicalSize = const Size(1080, 6000);
    addTearDown(tester.view.reset);
    final asked = <Uri>[];
    final session = await _session((request) async {
      asked.add(request.url);
      return _json({'categories': [], 'deals': [], 'featured': [], 'new_arrivals': [], 'top_sellers': [], 'for_you': [_product(3, 'Itel A70')]});
    }, {'wibuy.recently_viewed': ['p1', 'p2']});

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: HomeScreen(onSearch: () {}))));
    await tester.pumpAndSettle();

    expect(asked.firstWhere((u) => u.path.endsWith('/home')).queryParameters['viewed'], 'p1,p2');
    expect(find.text('✨ For you'), findsOneWidget);
    expect(find.textContaining('Itel A70'), findsOneWidget);
  });

  testWidgets('the seller form sends the genuine guarantee and can remove a video', (tester) async {
    tester.view.physicalSize = const Size(1080, 5000);
    addTearDown(tester.view.reset);
    final bodies = <String>[];
    final session = await _session((request) async {
      bodies.add(request.body);
      return _json({'data': {}});
    });
    final product = {..._product(1, 'Tecno Spark', video: 'http://x/v.mp4'), 'status': 'active', 'condition': 'new', 'photos': [], 'all_tiers': []};

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: ProductFormScreen(product: product))));
    expect(find.text('This product has a video (20s).'), findsOneWidget);
    await tester.tap(find.text('✅ I guarantee this product is genuine'));
    await tester.tap(find.text('Remove the video'));
    await tester.pump();
    await tester.tap(find.text('Save product'));
    await tester.pumpAndSettle();

    expect(bodies.single, contains('name="genuine_guarantee"\r\n\r\n1'));
    expect(bodies.single, contains('name="remove_video"\r\n\r\n1'));
  });
}
