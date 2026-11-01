import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/screens/product_screen.dart';
import 'package:wibuy_mobile/session.dart';

Map<String, dynamic> _shirt() => {
      'slug': 'shirt',
      'title': 'Shirt',
      'price': '100.00',
      'lowest_price': '100.00',
      'unit': 'piece',
      'stock': 5,
      'min_order_quantity': 1,
      'variants': [
        {'id': 1, 'name': 'Red, XL', 'price': null, 'stock': 2},
        {'id': 2, 'name': 'Blue, M', 'price': '120.00', 'stock': 3},
        {'id': 3, 'name': 'Green, S', 'price': null, 'stock': 0},
      ],
    };

void main() {
  test('order lines show the chosen option', () {
    final item = OrderItem.fromJson({'id': 1, 'title': 'Shirt', 'unit': 'piece', 'unit_price': '120.00', 'quantity': 1, 'line_total': '120.00', 'variant_name': 'Blue, M'});
    expect(item.displayTitle, 'Shirt (Blue, M)');
    expect(Product.fromJson(_shirt()).variants.map((v) => v.name), ['Red, XL', 'Blue, M', 'Green, S']);
  });

  testWidgets('a product with options shows them, and an option with its own price changes the price', (tester) async {
    // A tall screen, so the options below the photo are built.
    tester.view.physicalSize = const Size(800, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({});
    final client = MockClient((request) async => http.Response(
          jsonEncode({'data': _shirt(), 'reviews': []}),
          200,
          headers: {'content-type': 'application/json; charset=utf-8'},
        ));
    final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: ProductScreen(slug: 'shirt'))));
    await tester.pumpAndSettle();

    expect(find.text('Choose an option'), findsOneWidget);
    expect(find.text('Green, S (sold out)'), findsOneWidget);
    expect(find.text('Le 100.00'), findsWidgets);
    expect(find.text('Le 120.00'), findsNothing);

    await tester.tap(find.text('Blue, M · Le 120.00'));
    await tester.pumpAndSettle();
    expect(find.text('Le 120.00'), findsWidgets);
  });
}
