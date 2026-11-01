import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:wibuy_mobile/api/api_client.dart';
import 'package:wibuy_mobile/session.dart';

void main() {
  test('recently viewed keeps the latest first without repeats; compare holds at most 3', () async {
    SharedPreferences.setMockInitialValues({});
    final session = Session(ApiClient(baseUrl: 'http://test/api/v1'), await SharedPreferences.getInstance());

    session.viewed('rice');
    session.viewed('oil');
    session.viewed('rice');
    expect(session.recentlyViewed, ['rice', 'oil']);

    expect(session.toggleCompare('a'), isTrue);
    expect(session.toggleCompare('b'), isTrue);
    expect(session.toggleCompare('c'), isTrue);
    expect(session.toggleCompare('d'), isFalse);
    expect(session.compareList, ['a', 'b', 'c']);

    expect(session.toggleCompare('b'), isTrue);
    expect(session.compareList, ['a', 'c']);
  });
}
