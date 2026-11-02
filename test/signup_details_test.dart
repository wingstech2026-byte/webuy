import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/api/models.dart';
import 'package:wibuy_mobile/screens/login_screen.dart';
import 'package:wibuy_mobile/session.dart';

http.Response _json(Object body) => http.Response(jsonEncode(body), 200, headers: {'content-type': 'application/json; charset=utf-8'});

void main() {
  testWidgets('a new person gives their name and where they live with the SMS code', (tester) async {
    tester.view.devicePixelRatio = 1;
    tester.view.physicalSize = const Size(800, 1600);
    addTearDown(tester.view.reset);
    SharedPreferences.setMockInitialValues({});
    Map<String, dynamic>? verified;
    final session = Session(
      ApiClient(
        httpClient: MockClient((request) async {
          if (request.url.path.endsWith('/auth/code')) {
            return _json({'phone': '23276123456', 'needs_name': true, 'needs_location': true, 'dev_code': '123456'});
          }
          if (request.url.path.endsWith('/auth/verify')) {
            verified = jsonDecode(request.body) as Map<String, dynamic>;
            return _json({'token': 't', 'user': {'id': 1, 'name': 'Aminata', 'phone': '23276123456', 'display_phone': '076 123 456', 'district': 'Bo', 'area': 'Bo town'}});
          }
          return _json({'data': [], 'count': 0});
        }),
        baseUrl: 'http://test/api/v1',
      ),
      await SharedPreferences.getInstance(),
    )..meta = AppMeta.fromJson({'districts': ['Western Area Urban', 'Bo'], 'operators': [], 'web_url': 'http://test'});

    await tester.pumpWidget(SessionScope(session: session, child: const MaterialApp(home: LoginScreen())));
    await tester.enterText(find.byType(TextField).first, '076123456');
    await tester.tap(find.byType(FilledButton));
    await tester.pumpAndSettle();

    expect(find.text('Your full name'), findsOneWidget);
    expect(find.text('Where do you live?'), findsOneWidget);

    await tester.enterText(find.byType(TextField).at(0), '123456');
    await tester.enterText(find.widgetWithText(TextField, 'Aminata Kamara'), 'Aminata Kamara');
    await tester.tap(find.text('District'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Bo').last);
    await tester.pumpAndSettle();
    await tester.enterText(find.widgetWithText(TextField, 'Town or area'), 'Bo town');
    await tester.tap(find.widgetWithText(FilledButton, 'Log in'));
    await tester.pumpAndSettle();

    expect(verified, containsPair('name', 'Aminata Kamara'));
    expect(verified, containsPair('district', 'Bo'));
    expect(verified, containsPair('area', 'Bo town'));
    expect(session.user?.hasDetails, isTrue);
  });
}
