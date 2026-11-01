import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:latlong2/latlong.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/screens/rider_screen.dart';
import 'package:wibuy_mobile/session.dart';

Map<String, dynamic> _job(String status, {bool mine = false}) => {
      'id': 9, 'status': status, 'status_label': status == 'open' ? 'Waiting for a rider' : 'Rider on the way to the buyer',
      'fee': '20.00', 'pickup_name': 'Kissy Wholesale', 'pickup_address': 'Kissy Road', 'dropoff_district': 'Western Area Urban',
      'is_mine': mine, 'order_number': mine ? 'WB-1001' : null, 'buyer_name': mine ? 'Aminata' : null,
      'buyer_phone': mine ? '23276111222' : null, 'dropoff_address': mine ? '12 Wilkinson Road' : null, 'code_attempts_left': 5,
    };

void main() {
  testWidgets('an open job hides the buyer; after pickup the rider types the code', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final requests = <String>[];
    final client = MockClient((request) async {
      requests.add('${request.method} ${request.url.path} ${request.body}');
      return http.Response(jsonEncode({'data': _job('delivered', mine: true)}), 200, headers: {'content-type': 'application/json; charset=utf-8'});
    });
    final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: RiderJobScreen(job: RiderJob(_job('open'))))));
    expect(find.textContaining('the address shows after you take the job'), findsOneWidget);
    expect(find.textContaining('Aminata'), findsNothing);

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: RiderJobScreen(key: UniqueKey(), job: RiderJob(_job('picked_up', mine: true))))));
    expect(find.textContaining('Aminata'), findsOneWidget);
    await tester.enterText(find.byType(TextField).first, '4821');
    await tester.tap(find.text('Delivered'));
    await tester.pumpAndSettle();

    expect(requests.single, contains('/rider/jobs/9/deliver'));
    expect(requests.single, contains('4821'));
    expect(find.text('Delivered ✓ Thank you.'), findsOneWidget);
  });

  testWidgets('the rider sees earnings and withdraws to mobile money', (tester) async {
    SharedPreferences.setMockInitialValues({});
    final requests = <String>[];
    final client = MockClient((request) async {
      requests.add('${request.method} ${request.url.path} ${request.body}');
      final body = request.method == 'POST'
          ? {'data': {'id': 1, 'amount': '20.00', 'status': 'requested'}}
          : {
              'money': {'pending': '15.00', 'available': '20.00', 'paid_out': '0.00', 'in_transit': '0.00'},
              'min_payout': '10.00',
              'pay_to': {'operator': null, 'phone': '23276111222'},
              'payouts': [],
              'entries': [{'type': 'Delivery fee', 'description': 'Delivery of order WB-1001', 'amount': '20.00', 'created_at': null}],
            };
      return http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});
    });
    final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: RiderEarningsScreen())));
    await tester.pumpAndSettle();
    expect(find.text('Delivery of order WB-1001'), findsOneWidget);

    await tester.tap(find.text('Afrimoney'));
    await tester.enterText(find.widgetWithText(TextField, 'Amount'), '20');
    await tester.ensureVisible(find.text('Withdraw'));
    await tester.tap(find.text('Withdraw'));
    await tester.pumpAndSettle();

    final post = requests.firstWhere((r) => r.startsWith('POST'));
    expect(post, contains('/rider/payouts'));
    expect(post, contains('"operator":"AFRIMONEY"'));
    expect(post, contains('"amount":"20"'));
    expect(requests.where((r) => r.startsWith('GET')).length, 2); // reloaded after the request
  });

  testWidgets('while carrying the parcel the rider shares the location with the buyer', (tester) async {
    SharedPreferences.setMockInitialValues({});
    RiderJobScreen.locate = () async => const LatLng(8.48, -13.23);
    addTearDown(() => RiderJobScreen.locate = () async => null);
    final posts = <String>[];
    final client = MockClient((request) async {
      posts.add('${request.url.path} ${request.body}');
      return http.Response(jsonEncode({'shared': true}), 200, headers: {'content-type': 'application/json'});
    });
    final session = Session(ApiClient(httpClient: client, baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    await tester.pumpWidget(SessionScope(session: session, child: MaterialApp(home: RiderJobScreen(job: RiderJob(_job('picked_up', mine: true))))));
    await tester.pumpAndSettle();

    expect(posts.single, contains('/rider/jobs/9/location'));
    expect(posts.single, contains('"lat":8.48'));
    expect(find.textContaining('Sharing your location with the buyer'), findsOneWidget);

    await tester.pump(const Duration(seconds: 21));
    expect(posts, hasLength(2)); // again every 20 seconds
    await tester.pumpWidget(const SizedBox()); // leaving the screen stops it
    await tester.pump(const Duration(seconds: 25));
    expect(posts, hasLength(2));
  });
}
