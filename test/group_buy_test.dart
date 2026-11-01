import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/addresses_screen.dart';
import 'package:wibuy_mobile/screens/group_buy_screen.dart';
import 'package:wibuy_mobile/session.dart';

Map<String, dynamic> _group(String status, int paid) => {
      'token': 'abc123',
      'status': status,
      'paid_count': paid,
      'min_group_size': 3,
      'needed': 3 - paid,
      'expires_at': DateTime.now().add(const Duration(hours: 5)).toUtc().toIso8601String(),
      'members': ['Aminata', 'Musa'].take(paid).toList(),
      'my_status': null,
      'is_leader': false,
      'group_price': '90.00',
      'normal_price': '100.00',
      'saving_per_unit': '10.00',
      'share_url': 'http://test/g/abc123',
    };

Map<String, dynamic> _campaign() => {
      'id': 7,
      'group_price': '90.00',
      'normal_price': '100.00',
      'saving_per_unit': '10.00',
      'min_group_size': 3,
      'max_group_size': null,
      'max_quantity_each': 5,
      'hours': 24,
      'eligible_districts': null,
      'collection_point': {'name': 'Central shop', 'address': 'Siaka Stevens Street', 'district': 'Western Area Urban', 'pickup': true, 'verified': false},
      'product': {'slug': 'rice', 'title': 'Local rice 50kg', 'price': '100.00', 'lowest_price': '100.00', 'unit': 'bag'},
    };

Future<void> _open(WidgetTester tester, Map<String, dynamic> group) async {
  SharedPreferences.setMockInitialValues({});
  final client = MockClient((request) async => http.Response(
        jsonEncode({'data': group, 'campaign': _campaign()}),
        200,
        headers: {'content-type': 'application/json; charset=utf-8'},
      ));
  final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());
  await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: GroupScreen(token: 'abc123'))));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 100));
}

void main() {
  test('a map point copied from Google Maps is read, anything else is refused', () {
    expect(parseMapPoint('8.4844, -13.2299'), (8.4844, -13.2299));
    expect(parseMapPoint(' 8.4844 -13.2299 '), (8.4844, -13.2299));
    expect(parseMapPoint('near the mosque'), isNull);
    expect(parseMapPoint('8.48'), isNull);
  });

  testWidgets('an open group shows who paid, how many are still needed, and a join button', (tester) async {
    await _open(tester, _group('open', 2));

    expect(find.text('2 of 3 paid · 1 more needed'), findsOneWidget);
    expect(find.text('In the group: Aminata, Musa'), findsOneWidget);
    expect(find.textContaining('Join and pay'), findsOneWidget);
    expect(find.text('Invite friends on WhatsApp'), findsOneWidget);
  });

  testWidgets('a failed group says everyone is refunded and offers a new group instead of joining', (tester) async {
    await _open(tester, _group('failed', 1));

    expect(find.textContaining('Everyone who paid is refunded'), findsOneWidget);
    expect(find.textContaining('Join and pay'), findsNothing);
    expect(find.text('Start a new group'), findsOneWidget);
  });
}
