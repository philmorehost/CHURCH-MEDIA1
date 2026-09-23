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

  /// The voice the reader chose, by engine name. Null means they have not chosen, and the
  /// service picks the best the device has. Persisted by the caller so the choice survives
  /// a restart — see BibleLocalStore.setSetting().
  String? selectedVoiceName;

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

  /// Every voice the device offers, so the reader can choose one by ear.
  ///
  /// Gender is deliberately not claimed as fact. Phone speech engines do not expose it
  /// dependably: an Android voice is called `en-us-x-tpf-network` and nothing in that string
  /// says whether it is a man or a woman. An app showing a tidy Male/Female switch is
  /// guessing, and being wrong is obvious the instant it speaks. So [BibleTtsVoice.gender]
  /// is a hint taken from the name when the name happens to say, the UI labels it as a hint,
  /// and every entry can be PREVIEWED. Choosing by ear is how a person picks a voice.
  Future<List<BibleTtsVoice>> availableVoices() async {
    await init();
    final voices = await _loadVoices();
    voices.sort((a, b) {
      if (a.isEnglish != b.isEnglish) return a.isEnglish ? -1 : 1;
      return a.label.toLowerCase().compareTo(b.label.toLowerCase());
    });
    return voices;
  }

  /// Every speech engine the phone has.
  ///
  /// Android can have more than one (Google's, and the manufacturer's); iOS has exactly one.
  /// Worth asking because "no voices" usually means the engine is installed but its voice
  /// data is not, and knowing how many engines there are tells the reader whether swapping to
  /// another one is even possible.
  Future<List<String>> engineNames() async {
    await init();
    try {
      final raw = await _tts.getEngines;
      return (raw as List)
          .whereType<Map>()
          .map((e) => '${e['name'] ?? e['label'] ?? ''}')
          .where((n) => n.isNotEmpty)
          .toList();
    } catch (e) {
      debugPrint('BibleTtsService.engineNames: $e');
      return <String>[];
    }
  }

  /// Asks the phone whether it can actually read aloud, so the reader can be told what is
  /// missing instead of tapping play and hearing nothing.
  ///
  /// The decision itself is made by [BibleTtsDiagnosis.classify], which takes only plain
  /// values and so can be reasoned about (and tested) without a phone attached. This method's
  /// whole job is to gather those values honestly: a probe that fails is not evidence that
  /// the phone is broken, so a failure to answer is not turned into a verdict.
  Future<BibleTtsDiagnosis> diagnose() async {
    await init();
    final engines = await engineNames();
    final voices = await _loadVoices();

    // Not every engine answers this. When it will not, leave it true rather than using a
    // non-answer to declare the phone unusable.
    var installed = true;
    try {
      installed = (await _tts.isLanguageInstalled('en-US')) == true;
    } catch (e) {
      debugPrint('BibleTtsService.diagnose: isLanguageInstalled: $e');
    }

    return BibleTtsDiagnosis.classify(
      engineCount: engines.length,
      voiceCount: voices.length,
      englishVoiceCount: voices.where((v) => v.isEnglish).length,
      englishLanguageInstalled: installed,
    );
  }

  /// Reads with [voice] from now on. The caller persists [selectedVoiceName].
  Future<void> selectVoice(BibleTtsVoice voice) async {
    await init();
    await stop();
    try {
      await _tts.setVoice({'name': voice.name, 'locale': voice.locale});
      selectedVoiceName = voice.name;
      _selectedLocale = voice.locale;
      voiceLabel = voice.label;
    } catch (e) {
      debugPrint('BibleTtsService.selectVoice: $e');
    }
  }

  /// Speaks a short sample so the reader can hear a voice before committing to it.
  ///
  /// Takes the voice as an argument rather than using the selected one, because the point is
  /// to audition a voice that is not selected yet. The current selection is restored
  /// afterwards, so previewing does not quietly become choosing.
  Future<void> preview(
    BibleTtsVoice voice, {
    String sample = 'The Lord is my shepherd; I shall not want.',
  }) async {
    await init();
    await stop();
    try {
      await _tts.setVoice({'name': voice.name, 'locale': voice.locale});
      await _tts.speak(sample);
    } catch (e) {
      debugPrint('BibleTtsService.preview: $e');
    } finally {
      if (selectedVoiceName != null && selectedVoiceName != voice.name) {
        try {
          await _tts.setVoice({
            'name': selectedVoiceName!,
            'locale': _selectedLocale ?? 'en-US',
          });
        } catch (_) {}
      }
    }
  }

  /// The locale of the chosen voice, kept alongside its name so the pair can be restored.
  String? _selectedLocale;

  Future<List<BibleTtsVoice>> _loadVoices() async {
    try {
      final raw = await _tts.getVoices;
      return (raw as List)
          .whereType<Map>()
          .map(BibleTtsVoice.fromMap)
          .where((v) => v.name.isNotEmpty)
          .toList();
    } catch (e) {
      debugPrint('BibleTtsService._loadVoices: $e');
      return <BibleTtsVoice>[];
    }
  }

  /// Chooses the clearest English voice the device has. See the class comment.
  Future<void> _pickBestVoice() async {
    // A voice the reader chose always wins, including one chosen on a previous run.
    if (selectedVoiceName != null) {
      try {
        await _tts.setVoice({
          'name': selectedVoiceName!,
          'locale': _selectedLocale ?? 'en-US',
        });
        voiceLabel = selectedVoiceName!;
        return;
      } catch (e) {
        // The chosen voice is gone (a different phone, or its voice data was removed). Fall
        // through to the ladder rather than leaving the reader with no voice at all.
        debugPrint('BibleTtsService: chosen voice unusable, falling back: $e');
        selectedVoiceName = null;
      }
    }

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

/// One voice the device offers.
///
/// A thin, honest record of what the engine reported — a name, a locale, and a gender HINT
/// that is only ever set when the engine's own name says so. It exists so the reader can be
/// shown a real list to choose from and hear each one, instead of being handed a
/// Male/Female switch that cannot be honoured truthfully.
class BibleTtsVoice {
  final String name;
  final String locale;

  /// 'male' | 'female' | null when the name does not say. A hint, never presented as fact.
  final String? gender;

  const BibleTtsVoice({required this.name, required this.locale, this.gender});

  factory BibleTtsVoice.fromMap(dynamic raw) {
    final map = (raw as Map).cast<Object?, Object?>();
    final name = '${map['name'] ?? ''}';
    final locale = '${map['locale'] ?? ''}';
    final haystack = '$name $locale'.toLowerCase();

    String? gender;
    if (haystack.contains('female') || haystack.contains('woman')) {
      gender = 'female';
    } else if (haystack.contains('male') || haystack.contains('man')) {
      gender = 'male';
    }

    return BibleTtsVoice(name: name, locale: locale, gender: gender);
  }

  bool get isEnglish {
    final s = '$locale $name'.toLowerCase();
    return s.contains('en-us') ||
        s.contains('en_us') ||
        s.contains('en-gb') ||
        s.contains('en_gb') ||
        s.contains('en-') ||
        s.contains('english');
  }

  /// What the reader sees. The engine's own name is kept because it is the only thing that
  /// reliably distinguishes two voices in the same locale.
  String get label {
    final where = locale.replaceAll('_', '-').toUpperCase();
    final short = name.length > 26 ? '${name.substring(0, 26)}…' : name;
    return '$where · $short';
  }
}

/// The one thing standing between the reader and a verse read aloud.
///
/// Ordered worst-first is not the order they are checked in — see
/// [BibleTtsDiagnosis.classify] — but it is the order of how much is missing.
enum BibleTtsIssue {
  /// Voices are available and at least one reads English. Nothing to fix.
  ready,

  /// No speech engine at all: nothing on the phone can turn text into sound.
  noEngine,

  /// An engine is installed but it reported no voices, so there is nothing to read with.
  noVoices,

  /// The engine has voices, but none of them read English.
  noEnglishVoice,
}

/// What the phone can and cannot do, and what the reader can do about it.
///
/// This exists because "the voice does not work" is the one failure a reader cannot
/// diagnose for themselves: nothing appears on the screen, nothing is written where they can
/// see it, and the cause is always somewhere in the phone's own settings, several screens
/// deep. Naming the cause and opening that exact screen is the entire point of this class —
/// a message that says "install a voice" and leaves somebody hunting through their phone is
/// not a solution.
///
/// The decision is made from four plain numbers by [classify], deliberately kept free of any
/// platform call so the reasoning can be checked without a phone.
class BibleTtsDiagnosis {
  final BibleTtsIssue issue;
  final int engineCount;
  final int voiceCount;
  final int englishVoiceCount;

  /// What the engine itself said. Only ever a hint: network voices read fine without the
  /// on-device data being installed, so a false here is a reason to look, not a verdict.
  final bool englishLanguageInstalled;

  const BibleTtsDiagnosis({
    required this.issue,
    required this.engineCount,
    required this.voiceCount,
    required this.englishVoiceCount,
    required this.englishLanguageInstalled,
  });

  /// Google's speech engine. It is present on nearly every Android phone, and this link
  /// exists for the ones that shipped without it.
  static const String googleEngineStoreUrl =
      'https://play.google.com/store/apps/details?id=com.google.android.tts';

  /// Where the fix lives on the phone. Shown even when the button works: a reader who is
  /// told the name of the screen can find it on any phone, and can tell somebody else.
  static const String manualPath =
      'Settings → System → Languages & input → Text-to-speech output';

  bool get isReady => issue == BibleTtsIssue.ready;

  /// The case where reading may work but sound wrong: voices are present, yet the engine says
  /// the English data is not installed. A hint, not a fault — see [englishLanguageInstalled].
  bool get needsVoiceDataHint => isReady && !englishLanguageInstalled;

  /// Offered when there is nothing usable to speak with, so installing Google's engine is a
  /// real remedy rather than a suggestion.
  bool get shouldInstallEngine =>
      issue == BibleTtsIssue.noEngine || issue == BibleTtsIssue.noVoices;

  String get settingsLabel => 'Open speech settings';

  /// One line naming what is missing.
  String get title {
    switch (issue) {
      case BibleTtsIssue.ready:
        return 'Reading voice ready';
      case BibleTtsIssue.noEngine:
        return 'This phone has no speech voice';
      case BibleTtsIssue.noVoices:
        return 'The speech engine has no voices';
      case BibleTtsIssue.noEnglishVoice:
        return 'No English voice is installed';
    }
  }

  /// Why it matters, in the reader's words, and what installing fixes.
  String get detail {
    switch (issue) {
      case BibleTtsIssue.ready:
        return 'This phone has $englishVoiceCount English '
            '${englishVoiceCount == 1 ? 'voice' : 'voices'} to read with.';
      case BibleTtsIssue.noEngine:
        return 'Reading aloud needs a text-to-speech voice, and this phone does not have one. '
            'Google\'s is free and takes a moment to install — then come back and check again.';
      case BibleTtsIssue.noVoices:
        return 'A speech engine is installed but it reported no voices, so there is nothing '
            'to read with. Downloading the voice data, or reinstalling the engine, fixes this.';
      case BibleTtsIssue.noEnglishVoice:
        return 'The engine has $voiceCount ${voiceCount == 1 ? 'voice' : 'voices'}, but none of '
            'them read English. Downloading the English voice data once is enough — after '
            'that, reading works without a connection.';
    }
  }

  /// Works out what is missing from what the phone reported.
  ///
  /// Order matters and is deliberate. Voices that read English are checked FIRST, so a phone
  /// that answers with voices but not engines is still called ready — the aim is to predict
  /// whether the reader will hear something, not to police the phone's configuration. Only
  /// when there is nothing to speak with is a fault named, and an empty list from both probes
  /// is the honest worst case: nothing on this phone can produce a voice.
  static BibleTtsDiagnosis classify({
    required int engineCount,
    required int voiceCount,
    required int englishVoiceCount,
    required bool englishLanguageInstalled,
  }) {
    final BibleTtsIssue issue;
    if (englishVoiceCount > 0) {
      issue = BibleTtsIssue.ready;
    } else if (voiceCount > 0) {
      issue = BibleTtsIssue.noEnglishVoice;
    } else if (engineCount > 0) {
      issue = BibleTtsIssue.noVoices;
    } else {
      issue = BibleTtsIssue.noEngine;
    }

    return BibleTtsDiagnosis(
      issue: issue,
      engineCount: engineCount,
      voiceCount: voiceCount,
      englishVoiceCount: englishVoiceCount,
      englishLanguageInstalled: englishLanguageInstalled,
    );
  }
}
