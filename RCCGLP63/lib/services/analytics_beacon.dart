import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'api_client.dart';

/// Reports this app's activity to the church's own analytics.
///
/// The website has sent these hits since the analytics dashboard shipped
/// (`public/assets/js/analytics.js`); the app never did. That made two panels on
/// the dashboard permanently wrong — "App opens" could only ever read zero, and
/// the web-vs-app split showed every visitor as web no matter who was actually
/// in the app. Sending `device: 'app'` is what puts a hit in the app column.
///
/// Fire-and-forget by design. No caller awaits anything, every failure is
/// swallowed, and no personal data is sent: the server derives the visitor
/// identity from its own rotating device hash and stores no IP address, exactly
/// as it does for the website beacon.
class AnalyticsBeacon {
  /// Short on purpose. A dropped hit is not worth a slow screen, and this must
  /// never be something a reader waits on.
  static const Duration _timeout = Duration(seconds: 5);

  /// Identifies the app in the server's logs and in `Fingerprint`, which hashes
  /// the user-agent. The default `Dart/x.y` works too, but naming the client
  /// makes a hit traceable back to here. Deliberately contains no word from the
  /// server's bot filter, which would get every hit dropped as a crawler.
  static const String _userAgent = 'ChurchMediaApp/1.0 (Flutter)';

  static bool _enabled = true;

  /// Switches reporting off. Nothing calls this today — it exists so a future
  /// preferences screen has somewhere honest to turn it off.
  static void setEnabled(bool value) => _enabled = value;

  /// One hit per launch. This is the figure the dashboard's "App opens" card
  /// counts, and the app's side of the web-vs-app split.
  static void appOpen() => _send('app_open');

  /// A reader opened a piece of content.
  ///
  /// [type] must be one of post, sermon, event, testimony — anything else is
  /// dropped by the server, so it is dropped here first rather than burning a
  /// request. [unitId] attributes the view to a church when it is known.
  static void contentView(String type, int id, {int? unitId}) {
    if (id <= 0 || !_contentTypes.contains(type)) return;
    _send('${type}_view', {
      'entity_type': type,
      'entity_id': id,
      if (unitId != null && unitId > 0) 'org_unit_id': unitId,
    });
  }

  /// A video started playing — the app's counterpart to the
  /// `data-analytics-play` marker on the website.
  static void videoPlay(String type, int id) {
    if (id <= 0 || !_contentTypes.contains(type)) return;
    _send('video_play', {'entity_type': type, 'entity_id': id});
  }

  /// A search was run.
  ///
  /// The term goes in `meta`, which is where the dashboard's "What people
  /// searched for" panel reads it from. That panel is the clearest signal of
  /// content the church does not have yet, so it is worth feeding from the app.
  static void search(String term) {
    final trimmed = term.trim();
    if (trimmed.isEmpty) return;
    // The server clips meta at 255; clipping here just keeps the payload small.
    _send('search', {'meta': trimmed.length > 200 ? trimmed.substring(0, 200) : trimmed});
  }

  static const Set<String> _contentTypes = {'post', 'sermon', 'event', 'testimony'};

  static void _send(String event, [Map<String, dynamic> extra = const {}]) {
    if (!_enabled) return;
    // Deliberately not awaited. The caller is a screen, and a screen must never
    // wait on analytics or fail because of it. The request outlives the call.
    unawaited(_post(event, extra));
  }

  static Future<void> _post(String event, Map<String, dynamic> extra) async {
    try {
      final body = <String, dynamic>{'event': event, 'device': 'app'};
      body.addAll(extra);

      await http
          .post(
            Uri.parse('${ApiClient.baseUrl}/api/analytics'),
            headers: {'Content-Type': 'application/json', 'User-Agent': _userAgent},
            body: jsonEncode(body),
          )
          .timeout(_timeout);
      // The response is ignored on purpose: the endpoint answers 204 whether it
      // recorded the hit or refused it, and nothing here should react either way.
    } catch (_) {
      // Never rethrow, never log. Analytics is invisible to the reader by design.
    }
  }
}
