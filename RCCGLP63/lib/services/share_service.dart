import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

/// Shares the reel via the native share sheet (Android Intent / iOS
/// UIActivityViewController) using a small MethodChannel — avoids the
/// share_plus plugin, which applies the Kotlin Gradle Plugin itself.
class ShareService {
  ShareService._();

  static const MethodChannel _channel = MethodChannel('church_media/share');

  /// Returns false when there is no platform handler (web/desktop) or the
  /// native share could not be shown.
  static Future<bool> share({required String text, String? uri}) async {
    if (kIsWeb) {
      return false;
    }
    final args = <String, dynamic>{'text': text};
    if (uri != null && uri.isNotEmpty) {
      args['uri'] = uri;
    }
    try {
      await _channel.invokeMethod<void>('shareText', args);
      return true;
    } catch (_) {
      return false;
    }
  }
}
