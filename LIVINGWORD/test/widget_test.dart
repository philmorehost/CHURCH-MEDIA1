import 'package:flutter_test/flutter_test.dart';

import 'package:livingword_app/services/api_client.dart';

void main() {
  test('ApiClient default base URL points at the production server', () {
    expect(ApiClient.baseUrl, 'https://livingwordgospelmission.org.ng');
  });
}
