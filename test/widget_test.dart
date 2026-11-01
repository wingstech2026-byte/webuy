import 'dart:convert';

import 'package:flutter/widgets.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/main.dart';
import 'package:wibuy_mobile/session.dart';
import 'package:wibuy_mobile/util/money.dart';

/// A fake WiBuy backend answering like the Laravel /api/v1 routes.
MockClient fakeBackend({List<http.Request>? log}) => MockClient((request) async {
      log?.add(request);
      final path = request.url.path;

      final body = switch (path) {
        '/api/v1/meta' => {
            'districts': ['Western Area Urban', 'Bo'],
            'operators': [
              {'value': 'ORANGE', 'label': 'Orange Money'},
              {'value': 'AFRIMONEY', 'label': 'Afrimoney'},
            ],
            'web_url': 'http://test',
          },
        '/api/v1/home' => {
            'categories': [
              {'name': 'Food', 'slug': 'food', 'emoji': '🍚', 'children': []},
            ],
            'deals': [],
            'featured': [
              {
                'slug': 'rice-50kg',
                'title': 'Rice 50kg bag',
                'price': '650.00',
                'lowest_price': '600.00',
                'unit': 'bag',
                'stock': 10,
                'min_order_quantity': 1,
                'rating_avg': 4.5,
                'rating_count': 2,
                'sold_count': 7,
                'tiers': [
                  {'min_quantity': 5, 'unit_price': '600.00'},
                ],
                'seller': {'slug': 'kamara', 'shop_name': 'Kamara Foods', 'location': 'Freetown', 'is_verified': true},
              },
            ],
            'new_arrivals': [],
            'top_sellers': [],
          },
        '/api/v1/auth/code' => {'message': 'We sent a code by SMS.', 'phone': '23276123456', 'needs_name': false, 'dev_code': '123456'},
        '/api/v1/auth/verify' => {
            'token': '1|abc',
            'user': {'id': 1, 'name': 'Aminata', 'phone': '23276123456', 'display_phone': '076 123 456'},
          },
        '/api/v1/cart' => {'groups': [], 'total': '0.00', 'count': 3, 'has_problems': false},
        '/api/v1/orders/9' => null,
        _ => null,
      };

      if (path == '/api/v1/orders/9') {
        return http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401);
      }
      if (body == null) {
        return http.Response(jsonEncode({'message': 'Not found'}), 404);
      }

      return http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});
    });

void main() {
  group('money and phones', () {
    test('formats leones from exact strings', () {
      expect(formatLe('650.00'), 'Le 650.00');
      expect(formatLe('1250000.5'), 'Le 1,250,000.50');
      expect(formatLe(null), 'Le 0.00');
      expect(formatLe('-20'), '-Le 20.00');
    });

    test('shows Sierra Leone numbers the local way', () {
      expect(displayPhone('23276123456'), '076 123 456');
      expect(displayPhone('12345'), '12345');
    });
  });

  group('api client', () {
    test('sends the token and JSON headers', () async {
      final log = <http.Request>[];
      final api = ApiClient(httpClient: fakeBackend(log: log), baseUrl: 'http://test/api/v1', token: 'secret');

      await api.get('/cart');

      expect(log.single.headers['Authorization'], 'Bearer secret');
      expect(log.single.headers['Accept'], 'application/json');
    });

    test('turns Laravel validation errors into one readable message', () async {
      final api = ApiClient(
        baseUrl: 'http://test/api/v1',
        httpClient: MockClient((_) async => http.Response(
              jsonEncode({
                'message': 'The given data was invalid.',
                'errors': {
                  'code': ['That code is wrong or has expired.'],
                },
              }),
              422,
            )),
      );

      expect(
        () => api.post('/auth/verify', {}),
        throwsA(isA<ApiException>()
            .having((e) => e.status, 'status', 422)
            .having((e) => e.display, 'display', 'That code is wrong or has expired.')),
      );
    });

    test('drops empty query values', () {
      final api = ApiClient(baseUrl: 'http://test/api/v1', httpClient: fakeBackend());

      expect(api.uri('/products', {'q': '', 'sort': 'newest', 'category': null}).toString(), 'http://test/api/v1/products?sort=newest');
    });
  });

  group('session', () {
    setUp(() => SharedPreferences.setMockInitialValues({}));

    test('logs in with the SMS code, keeps the token, and forgets it on 401', () async {
      final prefs = await SharedPreferences.getInstance();
      final session = Session(ApiClient(httpClient: fakeBackend(), baseUrl: 'http://test/api/v1'), prefs);

      await session.verify('23276123456', '123456');

      expect(session.isLoggedIn, isTrue);
      expect(session.user!.name, 'Aminata');
      expect(session.cartCount, 3);
      expect(prefs.getString('wibuy.token'), '1|abc');

      await expectLater(session.api.get('/orders/9'), throwsA(isA<ApiException>()));
      expect(session.isLoggedIn, isFalse);
      expect(prefs.getString('wibuy.token'), isNull);
    });
  });

  test('parses a product with bulk price tiers', () {
    final product = Product.fromJson({
      'slug': 'rice',
      'title': 'Rice',
      'price': '650.00',
      'lowest_price': '600.00',
      'tiers': [
        {'min_quantity': 5, 'unit_price': '600.00'},
      ],
    });

    expect(product.hasTiers, isTrue);
    expect(product.tiers.single.unitPrice, '600.00');
    expect(product.seller, isNull);
  });

  testWidgets('home screen shows products from the backend', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final session = Session(ApiClient(httpClient: fakeBackend(), baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(WiBuyApp(session: session));
    await tester.pumpAndSettle();
    expect(find.text('Food'), findsOneWidget);

    // The home page has banners above the products: scroll down to them.
    await tester.scrollUntilVisible(find.text('Rice 50kg bag'), 300, scrollable: find.byType(Scrollable).first);

    expect(find.text('Rice 50kg bag'), findsOneWidget);
    expect(find.text('Le 650.00'), findsOneWidget);
    expect(find.text('From Le 600.00 in bulk'), findsOneWidget);

    await tester.tap(find.text('Account'));
    await tester.pumpAndSettle();
    expect(find.text('Log in'), findsWidgets);
  });
}
