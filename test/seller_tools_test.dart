import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/seller_screens.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body, [int status = 200]) => http.Response(jsonEncode(body), status, headers: {'content-type': 'application/json; charset=utf-8'});

Future<Session> _session(MockClient client) async {
  SharedPreferences.setMockInitialValues({});
  return Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
}

void main() {
  testWidgets('the product form edits options and sends them', (tester) async {
    tester.view.physicalSize = const Size(1080, 4000);
    addTearDown(tester.view.reset);
    final bodies = <String>[];
    final session = await _session(MockClient((request) async {
      bodies.add(request.body);
      return _json({'data': {}});
    }));
    final product = {
      'slug': 'ankara-dress', 'title': 'Ankara dress', 'price': '150.00', 'stock': 5, 'unit': 'piece', 'min_order_quantity': 1,
      'status': 'active', 'condition': 'new', 'photos': [], 'all_tiers': [],
      'variants': [{'id': 4, 'name': 'Small', 'price': null, 'stock': 3}, {'id': 5, 'name': 'Large', 'price': '180.00', 'stock': 2}],
    };

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: ProductFormScreen(product: product))));
    expect(find.text('Total of the options'), findsOneWidget);

    await tester.tap(find.byTooltip('Remove option').last);
    await tester.tap(find.text('Add option'));
    await tester.pump();
    await tester.enterText(find.widgetWithText(TextField, 'Option').last, 'Medium');
    await tester.enterText(find.widgetWithText(TextField, 'Stock').last, '6');
    await tester.tap(find.text('Save product'));
    await tester.pumpAndSettle();

    final body = bodies.single;
    expect(body, contains('name="variants_sent"'));
    expect(body, contains('name="variants[0][id]"\r\n\r\n4'));
    expect(body, contains('name="variants[1][name]"\r\n\r\nMedium'));
    expect(body, contains('name="variants[1][stock]"\r\n\r\n6'));
    expect(body, isNot(contains('Large')));
  });

  testWidgets('bulk upload sends pasted rows and lists rows to fix', (tester) async {
    final posts = <String>[];
    var fail = true;
    final session = await _session(MockClient((request) async {
      if (request.method == 'GET') {
        return _json({'columns': ['title', 'price', 'stock'], 'max_rows': 500, 'example': 'title,price,stock\nOil 5L,95,10\n'});
      }
      posts.add(request.body);
      return fail
          ? _json({'message': 'Nothing was added.', 'errors': {'rows': ['Row 3: the price must be at least 0.01.']}}, 422)
          : _json({'imported': 1, 'message': '1 products added as drafts.'}, 201);
    }));

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: ProductImportScreen())));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Fill in an example'));
    await tester.pump();
    await tester.tap(find.text('Add products'));
    await tester.pumpAndSettle();

    expect(jsonDecode(posts.single)['csv'], contains('Oil 5L,95,10'));
    expect(find.textContaining('Row 3: the price'), findsOneWidget);

    fail = false;
    await tester.tap(find.text('Add products'));
    await tester.pumpAndSettle();
    expect(posts, hasLength(2));
  });
}
