import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/photo_search_screen.dart';
import 'package:wibuy_mobile/session.dart';

// A 1×1 PNG, so Image.memory can draw the preview.
final _png = Uint8List.fromList(base64Decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg=='));

Future<Session> _session(Map<String, dynamic> answer, List<String> requests) async {
  SharedPreferences.setMockInitialValues({});
  final client = MockClient((request) async {
    requests.add('${request.method} ${request.url.path} ${request.headers['content-type']}');
    return http.Response(jsonEncode(answer), 200, headers: {'content-type': 'application/json; charset=utf-8'});
  });
  return Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

Map<String, dynamic> _answer(List<Map<String, dynamic>> products) => {
      'understood': {'is_item': true, 'name': 'White plastic chair', 'search_words': ['plastic chair', 'white'], 'category': null, 'reply': 'This looks like a white plastic chair.'},
      'products': products,
      'left_today': 19,
    };

void main() {
  testWidgets('a photo shows what it is and the products that match', (tester) async {
    final requests = <String>[];
    final session = await _session(_answer([
      {'id': 1, 'slug': 'plastic-chairs', 'title': 'Plastic chairs (white)', 'price': '120.00', 'unit': 'piece', 'stock': 50, 'min_order_quantity': 1, 'tiers': [], 'variants': []},
    ]), requests);

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: PhotoSearchScreen(photo: ('chair.jpg', _png)))));
    await tester.pumpAndSettle();

    expect(requests.single, startsWith('POST /api/v1/search/photo multipart/form-data'));
    expect(find.text('This looks like a white plastic chair.'), findsOneWidget);
    expect(find.text('plastic chair'), findsOneWidget);
    expect(find.text('Available on WiBuy'), findsOneWidget);
    expect(find.textContaining('Plastic chairs (white)'), findsOneWidget);
  });

  testWidgets('when nobody sells it, the shopper can ask sellers', (tester) async {
    final session = await _session(_answer([]), []);

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: PhotoSearchScreen(photo: ('chair.jpg', _png)))));
    await tester.pumpAndSettle();

    expect(find.text('No seller has "White plastic chair" on WiBuy right now.'), findsOneWidget);
    await tester.ensureVisible(find.text('Ask sellers for it'));
    await tester.tap(find.text('Ask sellers for it'));
    await tester.pumpAndSettle();
    expect(find.widgetWithText(TextField, 'White plastic chair'), findsOneWidget);
  });
}
