import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/business_models.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/screens/business_screen.dart';
import 'package:wibuy_mobile/session.dart';

Map<String, dynamic> _seller(String name, int? reliability) => {
      'id': name.length,
      'slug': name.toLowerCase(),
      'shop_name': name,
      'location': 'Bo',
      'is_verified': true,
      'rating_avg': 4.5,
      'rating_count': 3,
      'reliability_score': reliability,
    };

Map<String, dynamic> _rfq() => {
      'id': 5,
      'title': '50 bags of cement',
      'quantity': 50,
      'unit': 'bag',
      'district': 'Bo',
      'status': 'open',
      'quotes_count': 2,
      'quotes': [
        {
          'id': 1, 'seller': _seller('Near Shop', 92), 'product': {'title': 'Leocem'}, 'unit_price': '102.00', 'quantity': 50,
          'items_total': '5100.00', 'delivery_fee': '20.00', 'total': '5120.00', 'can_deliver': true, 'lead_time_days': 1,
          'valid_until': DateTime.now().add(const Duration(days: 14)).toUtc().toIso8601String(), 'status': 'submitted', 'is_valid': true,
        },
        {
          'id': 2, 'seller': _seller('Cheap Shop', null), 'product': {'title': 'Cement'}, 'unit_price': '100.00', 'quantity': 50,
          'items_total': '5000.00', 'delivery_fee': '200.00', 'total': '5200.00', 'can_deliver': true, 'lead_time_days': 3,
          'valid_until': DateTime.now().add(const Duration(days: 14)).toUtc().toIso8601String(), 'status': 'submitted', 'is_valid': true,
        },
      ],
    };

void main() {
  test('parses quotes, part payments, inspections and import orders', () {
    final rfq = RfqInfo.fromJson(_rfq());
    expect(rfq.quotes!.first.total, '5120.00');
    expect(rfq.quotes!.first.reliability, 92);
    expect(rfq.quotes!.last.reliability, isNull);

    final order = Order.fromJson({
      'id': 1, 'number': 'WB-1', 'status': 'accepted', 'status_label': 'Accepted', 'subtotal': '3000.00', 'delivery_fee': '0.00',
      'total': '3000.00', 'delivery_method': 'pickup', 'report_problem_url': '', 'can_pay_balance': true,
      'milestones': [
        {'id': 1, 'label': 'Deposit (30%)', 'percent': 30, 'amount': '900.00', 'status': 'paid'},
        {'id': 2, 'label': 'Balance before delivery', 'percent': 70, 'amount': '2100.00', 'status': 'pending'},
      ],
      'inspection': {'status': 'passed', 'fee': '50.00', 'report': 'All good', 'photos': ['http://x/1.jpg']},
    });
    expect(order.milestonesJson.map(MilestoneInfo.fromJson).where((m) => !m.isPaid).single.amount, '2100.00');
    expect(InspectionInfo.fromJson(order.inspectionJson!).status, 'passed');

    final import = ImportOrderInfo.fromJson({
      'id': 3, 'number': 'WG-1', 'product': 'Solar panel', 'quantity': 10, 'shipping': 'sea', 'total': '7000.00', 'deposit_amount': '2100.00',
      'balance_amount': '4900.00', 'status_label': 'Shipped', 'deposit_paid': true, 'balance_paid': false, 'can_pay_balance': false,
      'steps': [{'key': 'ordered', 'label': 'Ordered', 'at': '2026-10-27T10:00:00Z'}],
    });
    expect(import.steps.single.at, isNotNull);

    final product = Product.fromJson({
      'slug': 'x', 'title': 'X', 'price': '10.00', 'lowest_price': '10.00', 'lead_time_days': 7, 'sample_price': '12.00',
      'has_business_prices': true, 'risk_warnings': [{'type': 'new_seller', 'message': 'New seller'}],
    });
    expect(product.leadTimeDays, 7);
    expect(product.riskWarnings.single, 'New seller');
  });

  testWidgets('quotes are compared side by side with totals, days and reliability', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final client = MockClient((request) async => http.Response(jsonEncode({'data': _rfq()}), 200, headers: {'content-type': 'application/json; charset=utf-8'}));
    final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: RfqDetailScreen(id: 5))));
    await tester.pumpAndSettle();

    expect(find.text('Near Shop'), findsOneWidget);
    expect(find.text('Le 5,120.00'), findsOneWidget);
    expect(find.text('92/100'), findsOneWidget);
    expect(find.text('New'), findsOneWidget);
    expect(find.text('Accept for Le 5,120.00'), findsOneWidget);
  });
}
