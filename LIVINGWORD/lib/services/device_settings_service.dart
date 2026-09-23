import 'package:flutter/services.dart';

/// Opens the phone's own settings screens from inside the app.
///
/// **Why this needs native code at all.** The voice data the Bible reader depends on lives in
/// the phone's speech engine, and only the system settings can install it. So when reading
/// aloud cannot work, the fix is always on a screen this app does not own — and there is no
/// way to get there from Dart:
///
///   * `url_launcher` sends a plain `ACTION_VIEW` (`new Intent(ACTION_VIEW).setData(Uri.parse(url))`
///     — verified in url_launcher_android 6.3.32), and the speech screen is registered by
///     intent ACTION, not by a URI scheme. An `intent:` URI cannot reach it either.
///   * The plugin `app_settings` looks like the answer and is not: its `AppSettingsType` enum
///     has 26 members and **none of them is text-to-speech**, and the package contains no
///     reference to TTS anywhere.
///   * Android's public `Settings` class has no TTS action either. `ACTION_VOICE_INPUT_SETTINGS`
///     is speech *input* (dictation), not text-to-speech output, and the screen that installs
///     voice data is reached by the non-public action `com.android.settings.TTS_SETTINGS`.
///
/// Hence one small method on the channel this app already uses for sharing, rather than a new
/// dependency that would not have worked.
class DeviceSettingsService {
  DeviceSettingsService._();

  /// Must match CHANNEL in android/app/src/main/kotlin/.../MainActivity.kt.
  static const MethodChannel _channel = MethodChannel('livingword/share');

  /// Opens the screen where voice data is installed and the engine and default voice are
  /// chosen.
  ///
  /// Returns false when it could not be opened — on iOS and desktop, which have no such
  /// screen, and on a phone whose build does not expose it. The caller must then show the
  /// reader where to look by hand; reporting success for a screen that never appeared would
  /// leave them staring at an unchanged page with nothing to act on.
  static Future<bool> openVoiceSettings() async {
    try {
      final opened = await _channel.invokeMethod<bool>('openTtsSettings');
      return opened ?? false;
    } catch (_) {
      // MissingPluginException on a platform that does not implement it. Not an error worth
      // reporting: it simply means the manual directions are the answer.
      return false;
    }
  }
}
