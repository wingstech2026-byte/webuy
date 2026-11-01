import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/api/rewards_models.dart';
import 'package:wibuy_mobile/main.dart';
import 'package:wibuy_mobile/screens/rewards_screen.dart';
import 'package:wibuy_mobile/session.dart';

Map<String, dynamic> _product() => {
      'slug': 'rice',
      'title': 'Rice 50kg',
      'price': '100.00',
      'lowest_price': '100.00',
      'member_price': '90.00',
      'price_cut_campaign_id': 4,
      'flash_deal': {
        'id': 1,
        'state': 'live',
        'deal_price': '70.00',
        'starts_at': DateTime.now().subtract(const Duration(minutes: 5)).toUtc().toIso8601String(),
        'ends_at': DateTime.now().add(const Duration(hours: 1)).toUtc().toIso8601String(),
        'max_per_order': 2,
      },
    };

Map<String, dynamic> _hub() => {
      'points': 650,
      'points_per_coupon': 500,
      'points_coupon_amount': '5.00',
      'check_in': {'done_today': false, 'streak': 3},
      'spins': {'allowed': 2, 'used': 1, 'left': 1, 'from_shares': 1, 'from_pass': 0},
      'farm': {'code': 'f1', 'plant': 'rice', 'plant_label': 'Rice plant', 'water': 150, 'target': 300, 'progress_percent': 50, 'stage': 'Flowering', 'harvests': 0, 'owner': 'Ami'},
      'farm_missions': [
        {'key': 'check_in', 'label': 'Daily check-in', 'water': 10, 'daily_limit': 1, 'done_today': 1},
      ],
      'price_cuts': [],
      'coupons': [
        {'id': 7, 'title': 'Welcome to WiBuy', 'description': 'Le 10.00 off orders over Le 50.00', 'source': 'welcome', 'status': 'available', 'min_spend': '50.00'},
      ],
      'locked_coupons': [
        {'id': 8, 'title': 'Welcome bonus', 'description': 'Le 20.00 off orders over Le 100.00', 'source': 'welcome_bonus', 'status': 'locked', 'unlock_hint': 'Share WiBuy on WhatsApp.'},
      ],
      'pass': null,
      'referral_code': 'ABC1234',
    };

void main() {
  test('parses lightning deals, member prices and price cut eligibility on a product', () {
    final product = Product.fromJson(_product());

    expect(product.memberPrice, '90.00');
    expect(product.priceCutCampaignId, 4);
    expect(product.flashDeal!.dealPrice, '70.00');
    expect(product.flashDeal!.isLive, isTrue);
  });

  test('a quote keeps coupon discounts and the amount really due', () {
    final quote = Quote.fromJson({
      'groups': [],
      'items_total': '100.00',
      'delivery_total': '20.00',
      'grand_total': '120.00',
      'coupon_discount': '10.00',
      'pass_savings': '20.00',
      'amount_due': '90.00',
      'coupon_error': null,
      'coupons': [
        {'id': 7, 'title': 'Welcome', 'description': 'Le 10 off', 'source': 'welcome', 'status': 'available'},
      ],
    });

    expect(quote.amountDue, '90.00');
    expect(quote.couponJson.map(Coupon.fromJson).single.id, 7);
    expect(Quote.fromJson({'groups': [], 'items_total': '5.00', 'delivery_total': '0.00', 'grand_total': '5.00'}).amountDue, '5.00',
        reason: 'older backends without amount_due still work');
  });

  test('the rewards hub parses points, spins, farm and locked coupons', () {
    final hub = RewardsHub.fromJson(_hub());

    expect(hub.points, 650);
    expect(hub.spins.left, 1);
    expect(hub.farm!.stage, 'Flowering');
    expect(hub.missions.single.isDone, isTrue);
    expect(hub.lockedCoupons.single.isLocked, isTrue);
  });

  test('deep links open the right screens', () {
    expect(routeFor('/products/rice-50kg'), isNotNull);
    expect(routeFor('/p/rice-50kg'), isNotNull);
    expect(routeFor('/cut/abc123'), isNotNull);
    expect(routeFor('/'), isNull);
    expect(routeFor('/unknown/x'), isNull);
  });

  test('every request carries a stable device id', () async {
    SharedPreferences.setMockInitialValues({});
    final prefs = await SharedPreferences.getInstance();
    final seen = <String?>[];
    final client = MockClient((request) async {
      seen.add(request.headers['X-Device-Id']);
      return http.Response('{}', 200);
    });

    await Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), prefs).api.get('/meta');
    await Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), prefs).api.get('/meta');

    expect(seen[0], isNotNull);
    expect(seen[0], seen[1]);
  });

  testWidgets('the Rewards tab shows points, check-in, spins, the farm and coupons', (tester) async {
    SharedPreferences.setMockInitialValues({
      'wibuy.token': '1|abc',
      'wibuy.user': jsonEncode({'id': 1, 'name': 'Ami', 'phone': '23276123456', 'display_phone': '076 123 456', 'is_seller': false, 'is_admin': false}),
    });
    final client = MockClient((request) async {
      final body = request.url.path == '/api/v1/rewards' ? _hub() : <String, dynamic>{};
      return http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});
    });
    final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: RewardsScreen())));
    await tester.pumpAndSettle();

    expect(find.text('650'), findsOneWidget);
    expect(find.text('Check in'), findsOneWidget);
    expect(find.text('1 spin left'), findsOneWidget);
    expect(find.text('Flowering · 50%'), findsOneWidget);

    await tester.scrollUntilVisible(find.textContaining('Welcome bonus'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.textContaining('Welcome bonus'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('Welcome to WiBuy'), 200, scrollable: find.byType(Scrollable).first);
    expect(find.text('Welcome to WiBuy'), findsOneWidget);
  });
}
