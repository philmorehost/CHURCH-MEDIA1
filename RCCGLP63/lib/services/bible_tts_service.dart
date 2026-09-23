import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter_tts/flutter_tts.dart';

/// Reads scripture aloud using the device's own speech engine.
///
/// **Why the device engine and not recorded audio.** Recorded KJV is either huge (a
/// full Bible is hundreds of megabytes), licensed per listen, or streamed — and a Bible
/// that needs a connection is the one feature this app promises works offline. The
/// device engine is already installed, covers all 66 books on day one, and costs
/// nothing to serve.
///
/// **The voice is the whole feature, so it is chosen explicitly.** An Android phone
/// ships several English voices and the default is usually the oldest, flattest one —
/// which is why "text to speech" sounds like a robot. The `-network` voices from Google
/// are a different engine: markedly clearer, with real sentence intonation. So this
/// service enumerates what the device actually has and picks the best available rather
/// than accepting the default. It degrades in a defined order and never throws for a
/// missing voice: a plain device voice reading scripture is worth far more than an
/// error dialog.
///
/// Preference order, best first:
///   1. an English **neural** or **enhanced** voice (iOS/Samsung naming)
///   2. an English **network** voice (Google `en-*-x-*-network`)
///   3. any English voice that is not the legacy default
///   4. whatever the engine offers
class BibleTtsService {
  BibleTtsService._();
  static final BibleTtsService instance = BibleTtsService._();

  final FlutterTts _tts = FlutterTts();

  bool _ready = false;
  /// Human-readable description of the voice in use, for the settings line.
  String voiceLabel = 'Device voice';

  /// Scripture is read, not announced. The engine's normal pace is tuned for
  /// turn-by-turn navigation and runs a verse together; this is slower than default and
  /// still quicker than a lectern.
  double _rate = 0.82;
  double get rate => _rate;

  /// True while a chapter is being read, so the button can show stop rather than play.
  bool get isSpeaking => _speaking;
  bool _speaking = false;

  /// Set while the queue should keep going. Cleared by [stop] so a stop mid-chapter
  /// cannot be overtaken by the verse that was already in flight.
  bool _running = false;

  Future<void> init() async {
    if (_ready) return;
    _ready = true;

    try {
      // iOS plays through the speech synthesiser as a shared instance so it ducks
      // music correctly instead of stopping it.
      if (Platform.isIOS) {
        await _tts.setSharedInstance(true);
        await _tts.setIosAudioCategory(
          IosTextToSpeechAudioCategory.playback,
          [IosTextToSpeechAudioCategoryOptions.allowBluetooth],
          IosTextToSpeechAudioMode.voicePrompt,
        );
      }

      await _tts.setLanguage('en-US');
      await _tts.setPitch(1.0);
      await _tts.setVolume(1.0);
      await _tts.setSpeechRate(_rate);

      // Wait for each utterance to finish before the next is sent, which is what lets
      // verses be queued in order instead of overlapping.
      await _tts.awaitSpeakCompletion(true);

      await _pickBestVoice();
    } catch (e) {
      // A device with no speech engine at all: the button will report it on tap.
      debugPrint('BibleTtsService.init: $e');
    }
  }

  /// Chooses the clearest English voice the device has. See the class comment.
  Future<void> _pickBestVoice() async {
    List<Map<String, String>> voices;
    try {
      final raw = await _tts.getVoices;
      voices = (raw as List)
          .whereType<Map>()
          .map((v) => {
                'name': '${v['name'] ?? ''}',
                'locale': '${v['locale'] ?? ''}',
              })
          .where((v) => v['name']!.isNotEmpty)
          .toList();
    } catch (e) {
      debugPrint('BibleTtsService._pickBestVoice: $e');
      return;
    }

    if (voices.isEmpty) return;

    bool isEnglish(Map<String, String> v) {
      final s = '${v['locale']} ${v['name']}'.toLowerCase();
      return s.contains('en-us') ||
          s.contains('en_us') ||
          s.contains('en-gb') ||
          s.contains('en_gb') ||
          s.contains('english') ||
          s.contains('en-');
    }

    Map<String, String>? pick;

    // 1. A named high-quality voice. "neural" and "enhanced" are the labels Apple and
    //    several OEM engines use for their good voices.
    pick = _firstWhere(voices, (v) =>
        isEnglish(v) &&
        _matches(v, const ['neural', 'enhanced', 'premium', 'siri']));

    // 2. Google's network voices — the clearest thing on most Android phones. They are
    //    named `en-us-x-<code>-network` (the same voice is also offered as `-local`,
    //    which is the smaller on-device model and sounds noticeably worse).
    pick ??= _firstWhere(voices, (v) =>
        isEnglish(v) && v['name']!.toLowerCase().contains('network'));

    // 3. Any English voice that is not one of the legacy `en-US-language` names.
    pick ??= _firstWhere(voices, (v) =>
        isEnglish(v) && !v['name']!.toLowerCase().contains('language'));

    // 4. Anything English, then anything at all.
    pick ??= _firstWhere(voices, isEnglish);
    pick ??= voices.first;

    try {
      await _tts.setVoice({'name': pick['name']!, 'locale': pick['locale']!});
      voiceLabel = pick['name']!;
      debugPrint('BibleTtsService: voice = $voiceLabel');
    } catch (e) {
      debugPrint('BibleTtsService.setVoice: $e');
    }
  }

  static Map<String, String>? _firstWhere(
    List<Map<String, String>> list,
    bool Function(Map<String, String>) test,
  ) {
    for (final v in list) {
      if (test(v)) return v;
    }
    return null;
  }

  static bool _matches(Map<String, String> v, List<String> needles) {
    final s = '${v['name']} ${v['locale']}'.toLowerCase();
    for (final n in needles) {
      if (s.contains(n)) return true;
    }
    return false;
  }

  /// Reading pace, 0.4–1.2. Persisted by the caller; this only applies it.
  Future<void> setRate(double value) async {
    _rate = value.clamp(0.4, 1.2).toDouble();
    try {
      await _tts.setSpeechRate(_rate);
    } catch (e) {
      debugPrint('BibleTtsService.setRate: $e');
    }
  }

  /// Reads [verses] from [from], calling [onVerse] with the 1-based verse number before
  /// each one is spoken, then [onDone]. Returns immediately; [stop] ends it early.
  ///
  /// The numbers passed to [onVerse] are what let the screen highlight and scroll to the
  /// verse being read, using the highlight mechanism the reader already has.
  Future<void> readChapter({
    required List<String> verses,
    required int firstVerseNumber,
    required void Function(int verseNumber) onVerse,
    void Function()? onDone,
    void Function(String message)? onError,
  }) async {
    await init();

    // A second tap replaces the first reading rather than layering over it.
    await stop();
    _running = true;
    _speaking = true;

    for (var i = 0; i < verses.length; i++) {
      if (!_running) return;

      final verseNumber = firstVerseNumber + i;
      final text = _forSpeech(verses[i]);
      if (text.isEmpty) continue;

      try {
        onVerse(verseNumber);
        await _tts.speak(text);
      } catch (e) {
        debugPrint('BibleTtsService.readChapter: $e');
        onError?.call('The device would not read this aloud.');
        break;
      }
    }

    if (_running) {
      _running = false;
      _speaking = false;
      onDone?.call();
    }
  }

  /// Verse text as it should be SPOKEN, which is not quite as it should be read.
  ///
  /// The engine reads every character it is given: a stray brace or a verse-number
  /// superscript becomes "brace" or "one" in the middle of a sentence. Numbers written
  /// as digits are read as digits — "1" becomes "one" in the middle of the prose — so
  /// they are spelled out, which is how a lectionary reader would say them.
  String _forSpeech(String verse) {
    var out = verse.trim();
    out = out.replaceAll('{', '').replaceAll('}', '');
    out = out.replaceAll('  ', ' ');
    // Trailing punctuation makes the engine fall off the end of a verse; a full stop
    // gives it somewhere to land before the next verse starts.
    if (out.isNotEmpty && !RegExp(r'[.!?:;,\u201d\)]$').hasMatch(out)) {
      out = '$out.';
    }
    return out;
  }

  Future<void> stop() async {
    _running = false;
    _speaking = false;
    try {
      await _tts.stop();
    } catch (e) {
      debugPrint('BibleTtsService.stop: $e');
    }
  }

  Future<void> pause() async {
    try {
      await _tts.pause();
    } catch (e) {
      debugPrint('BibleTtsService.pause: $e');
    }
  }

  /// Releases the engine. Called when the reader is closed: a chapter left speaking
  /// over somebody's phone after they left the screen is the worst version of this
  /// feature.
  Future<void> dispose() async {
    await stop();
    try {
      await _tts.stop();
    } catch (_) {}
  }
}
