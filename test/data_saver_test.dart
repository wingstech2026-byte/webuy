import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/l10n.dart';
import 'package:wibuy_mobile/session.dart';

void main() {
  test('save data is kept on the phone and products carry small pictures for it', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final session = Session(ApiClient(httpClient: MockClient((_) async => http.Response('{}', 200)), baseUrl: 'http://test/api/v1'), prefs);
    var changed = 0;
    session.addListener(() => changed++);

    expect(session.dataSaver, isFalse);
    session.dataSaver = true;
    expect(changed, 1);

    // A new start of the app remembers it.
    final again = Session(ApiClient(httpClient: MockClient((_) async => http.Response('{}', 200)), baseUrl: 'http://test/api/v1'), prefs);
    expect(again.dataSaver, isTrue);

    final product = Product.fromJson({
      'id': 1, 'slug': 'rice', 'title': 'Rice', 'price': '10.00', 'unit': 'bag', 'stock': 5, 'min_order_quantity': 1, 'tiers': [],
      'images': [
        {'url': 'http://x/big.jpg', 'thumb_url': 'http://x/small.jpg'},
      ],
    });
    expect(product.imageUrls, ['http://x/big.jpg']);
    expect(product.imageThumbUrls, ['http://x/small.jpg']);
  });

  testWidgets('choosing Krio changes the words, and phrases without Krio stay in English', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final session = Session(ApiClient(httpClient: MockClient((_) async => http.Response('{}', 200)), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(SessionScope(
      session: session,
      child: MaterialApp(home: Builder(builder: (context) => Column(children: [Text(tr(context, 'Cart')), Text(tr(context, 'Not translated yet'))]))),
    ));
    expect(find.text('Cart'), findsOneWidget);

    session.language = 'kri';
    await tester.pump();
    expect(find.text('Baskit'), findsOneWidget);
    expect(find.text('Not translated yet'), findsOneWidget);
    expect(session.language, 'kri');
  });
}
