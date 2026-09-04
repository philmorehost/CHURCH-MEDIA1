import 'package:flutter_test/flutter_test.dart';

import 'package:church_media_app/services/api_client.dart';

void main() {
  test('ApiClient default base URL points at the production server', () {
    expect(ApiClient.baseUrl, 'https://rccglp63yaya.org.ng');
  });
}
