import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';
import '../services/api_client.dart';
import '../services/bible_local_store.dart';
import '../services/device_settings_service.dart';
import '../services/offline_bible_service.dart';
import '../services/share_service.dart';
import '../theme/app_theme.dart';

import '../services/bible_tts_service.dart';

/// Highlight color palette (key stored in the local DB).
const Map<String, Color> _highlightColors = {
  'yellow': Color(0xFFFFF59D),
  'green': Color(0xFFA5D6A7),
  'blue': Color(0xFF90CAF9),
  'pink': Color(0xFFF48FB1),
  'purple': Color(0xFFCE93D8),
};

const List<String> _onlineVersions = ['NIV', 'NLT', 'NKJV'];

class BibleScreen extends StatefulWidget {
  const BibleScreen({super.key});

  @override
  State<BibleScreen> createState() => _BibleScreenState();
}

class _BibleScreenState extends State<BibleScreen> {
  final _api = ApiClient();
  final _verseController = TextEditingController();
  final _chapterController = TextEditingController(text: '1');

  String _selectedVersion = 'KJV';
  String _selectedLang = 'en';
  String _selectedBook = 'Genesis';
  int _selectedChapter = 1;
  int _selectedVerse = 0; // 0 = whole chapter

  /// Available options for the Chapter / Verse dropdowns, derived from the
  /// bundled Bible structure (identical across translations).
  List<int> _chapterOptions = [1];
  List<int> _verseOptions = <int>[];

  List<String> _books = [];
  List<({int verse, String text})> _passage = [];
  Map<int, String> _highlights = {};
  Map<int, String> _notes = {};
  Set<int> _bookmarked = {};

  String _reference = 'Choose a passage';
  String _translation = '';
  String _errorMessage = '';
  bool _isLoading = false;
  bool _showSearch = true;
  double _fontSize = 18;
  OfflineVerseOfDay _verseOfDay = const OfflineVerseOfDay(text: '', reference: '');

  /// Scroll target for “search a verse → show the whole chapter, scrolled to it”.
  final ScrollController _scrollController = ScrollController();
  final GlobalKey _verseAnchorKey = GlobalKey();
  int _targetVerse = 0;
  Timer? _targetFadeTimer;
  bool _targetFading = false;

  /// Read-aloud state. `_speakingVerse` is the verse being spoken right now, which is what
  /// gets the highlight — the reader's eye should follow the voice, not hunt for it.
  bool _reading = false;
  int _speakingVerse = 0;
  double _ttsRate = 0.82;

  /// True once the phone has refused to open its own speech settings, so the sheet stops
  /// offering a button that does nothing and shows the way there by hand instead.
  bool _voiceSettingsUnavailable = false;

  @override
  void initState() {
    super.initState();
    _init();
  }

  Future<void> _init() async {
    _verseOfDay = OfflineBibleService.instance.verseOfDay();
    final font = await BibleLocalStore.instance.getSetting('bible_font_size');
    if (font != null) {
      final v = double.tryParse(font);
      if (v != null && v >= 12 && v <= 28) _fontSize = v;
    }

    // Reading voice and pace are the reader's own choices, so they are restored before the
    // engine is used for the first time — a voice that resets on every launch is worse than
    // no choice at all.
    final savedRate = await BibleLocalStore.instance.getSetting('bible_tts_rate');
    final savedVoice = await BibleLocalStore.instance.getSetting('bible_tts_voice');
    final rate = double.tryParse(savedRate ?? '');
    if (rate != null && rate >= 0.4 && rate <= 1.2) _ttsRate = rate;
    BibleTtsService.instance.selectedVoiceName = (savedVoice?.isEmpty ?? true) ? null : savedVoice;
    await BibleTtsService.instance.setRate(_ttsRate);
    final books = await OfflineBibleService.instance.books('kjv');
    if (!mounted) return;
    setState(() {
      _books = books.map((b) => b.name).toList();
      if (_books.isNotEmpty) _selectedBook = _books.first;
    });
    await _restorePosition();
    await _loadStructure();
    if (mounted) await _read();
  }

  Future<void> _restorePosition() async {
    final pos = await BibleLocalStore.instance.positionFor(_selectedVersion);
    if (pos == null) return;
    final idx = _books.indexWhere((n) => n.toLowerCase() == pos.book.toLowerCase());
    if (idx < 0) return;
    setState(() {
      _selectedBook = _books[idx];
      _selectedChapter = pos.chapter;
      _selectedVerse = pos.verse > 0 ? pos.verse : 0;
      _chapterController.text = '${pos.chapter}';
      _verseController.text = pos.verse > 0 ? '${pos.verse}' : '';
    });
  }

  @override
  void dispose() {
    // Leaving the screen must silence it. A chapter still being read aloud from a closed
    // reader is the worst possible version of this feature, and it is the easy mistake.
    BibleTtsService.instance.stop();
    _targetFadeTimer?.cancel();
    _verseController.dispose();
    _chapterController.dispose();
    _scrollController.dispose();
    super.dispose();
  }

  bool get _offline => OfflineBibleService.isOffline(_selectedVersion);

  Future<void> _read() async {
    final vText = _selectedVerse > 0 ? '$_selectedVerse' : '';
    setState(() {
      _isLoading = true;
      _errorMessage = '';
    });
    try {
      if (_offline) {
        await _readOffline(vText);
      } else {
        await _readOnline(vText);
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isLoading = false;
          _errorMessage = _offline
              ? 'An error occurred while reading scripture.'
              : 'Could not reach the online Bible. Check your connection or switch to KJV (offline).';
        });
      }
    }
  }

  Future<void> _readOffline(String vText) async {
    final key = _selectedVersion.toLowerCase();
    final books = await OfflineBibleService.instance.books(key);
    final idx = books.indexWhere((b) => b.name.toLowerCase() == _selectedBook.toLowerCase());
    if (idx < 0) {
      if (mounted) setState(() { _isLoading = false; _errorMessage = 'Book not found.'; });
      return;
    }
    final verses = await OfflineBibleService.instance.chapterVerses(key, idx, _selectedChapter);
    if (verses.isEmpty) {
      if (mounted) setState(() { _isLoading = false; _errorMessage = 'Chapter not found.'; });
      return;
    }
    // Always show the whole chapter; if a specific verse was requested, keep
    // that verse as the scroll target so it is highlighted in view (KJV-app style).
    final start = vText.isNotEmpty ? (int.tryParse(vText) ?? 1) : 1;
    final passage = [for (var i = 0; i < verses.length; i++) (verse: i + 1, text: verses[i])];
    final target = vText.isNotEmpty ? start : 0;

    final highlights = await BibleLocalStore.instance.highlightsForChapter(_selectedBook, _selectedChapter);
    final notes = await BibleLocalStore.instance.notesForChapter(_selectedBook, _selectedChapter);
    final bookmarked = await BibleLocalStore.instance.bookmarkedVerses(_selectedBook, _selectedChapter);
    if (!mounted) return;
    setState(() {
      _passage = passage;
      _highlights = highlights;
      _notes = notes;
      _bookmarked = bookmarked;
      _reference = '$_selectedBook $_selectedChapter${vText.isNotEmpty ? ':$start' : ''}';
      _translation = OfflineBibleService.versions[key] ?? _selectedVersion;
      _isLoading = false;
      _showSearch = false;
      _targetVerse = target;
    });
    await BibleLocalStore.instance.savePosition(_selectedVersion, _selectedBook, _selectedChapter, start);
    _scheduleTargetFade();
    _scrollToVerse();
  }

  Future<void> _readOnline(String vText) async {
    // Fetch the full chapter (no verse filter) so every verse is shown, then
    // scroll down to the requested verse.
    final response = await _api.fetchBible(
      book: _selectedBook,
      chapter: _selectedChapter,
      version: _selectedVersion,
      lang: _selectedLang,
    );
    if (response['error'] != null) {
      if (mounted) setState(() { _isLoading = false; _errorMessage = response['error'] as String; });
      return;
    }
    final verses = (response['verses'] as List<dynamic>? ?? []);
    final passage = <({int verse, String text})>[
      for (final v in verses) (verse: int.tryParse('${v['verse']}') ?? 1, text: '${v['text']}'),
    ];
    final start = vText.isNotEmpty ? (int.tryParse(vText) ?? 1) : 1;
    final target = vText.isNotEmpty ? start : 0;
    final highlights = await BibleLocalStore.instance.highlightsForChapter(_selectedBook, _selectedChapter);
    final notes = await BibleLocalStore.instance.notesForChapter(_selectedBook, _selectedChapter);
    final bookmarked = await BibleLocalStore.instance.bookmarkedVerses(_selectedBook, _selectedChapter);
    if (!mounted) return;
    setState(() {
      _passage = passage;
      _highlights = highlights;
      _notes = notes;
      _bookmarked = bookmarked;
      _reference = '$_selectedBook $_selectedChapter${vText.isNotEmpty ? ':$start' : ''}';
      _translation = response['translation'] as String? ?? '';
      _isLoading = false;
      _showSearch = false;
      _targetVerse = target;
    });
    await BibleLocalStore.instance.savePosition(_selectedVersion, _selectedBook, _selectedChapter, start);
    _scheduleTargetFade();
    _scrollToVerse();
  }

  /// Briefly highlights the searched verse, then fades it out so it doesn't
  /// stay gold. Scroll-to-verse is unaffected by the fade.
  void _scheduleTargetFade() {
    _targetFadeTimer?.cancel();
    _targetFading = false;
    if (_targetVerse <= 0) return;
    _targetFadeTimer = Timer(const Duration(seconds: 2), () {
      if (!mounted) return;
      setState(() => _targetFading = true); // fades over the next 1s
      _targetFadeTimer = Timer(const Duration(seconds: 1), () {
        if (mounted) setState(() => _targetVerse = 0);
      });
    });
  }

  /// Scrolls the chapter so `_targetVerse` is in view. When no verse was
  /// requested (whole chapter read), jumps back to the top.
  void _scrollToVerse() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted || !_scrollController.hasClients) return;
      final target = _targetVerse;
      if (target <= 0) {
        _scrollController.jumpTo(0);
        return;
      }
      final total = _passage.length;
      final maxExtent = _scrollController.position.maxScrollExtent;
      // 1) Jump near the verse (proportional estimate) so its tile gets built…
      if (total > 0 && maxExtent > 0) {
        final approx = (target - 1) / total * maxExtent;
        _scrollController.jumpTo(approx.clamp(0.0, maxExtent));
      }
      // 2) …then scroll exactly to it once the anchor tile exists.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (!mounted) return;
        final ctx = _verseAnchorKey.currentContext;
        if (ctx != null) {
          Scrollable.ensureVisible(ctx, alignment: 0.2, duration: const Duration(milliseconds: 350), curve: Curves.easeInOut);
        }
      });
    });
  }

  Future<void> _refreshChapterMeta() async {
    final highlights = await BibleLocalStore.instance.highlightsForChapter(_selectedBook, _selectedChapter);
    final notes = await BibleLocalStore.instance.notesForChapter(_selectedBook, _selectedChapter);
    final bookmarked = await BibleLocalStore.instance.bookmarkedVerses(_selectedBook, _selectedChapter);
    if (mounted) setState(() { _highlights = highlights; _notes = notes; _bookmarked = bookmarked; });
  }

  void _openSearchPanel() {
    setState(() => _showSearch = true);
  }

  void _onVersionChanged(String v) {
    setState(() {
      _selectedVersion = v;
      _selectedLang = 'en';
    });
    _loadStructure();
    _read();
  }

  /// Rebuilds the Chapter / Verse dropdown options for the selected book and
  /// chapter, using the bundled Bible structure (identical across versions).
  Future<void> _loadStructure() async {
    try {
      final books = await OfflineBibleService.instance.books('kjv');
      final idx = books.indexWhere((b) => b.name.toLowerCase() == _selectedBook.toLowerCase());
      if (idx < 0) {
        if (mounted) setState(() { _chapterOptions = [1]; _verseOptions = <int>[]; });
        return;
      }
      final book = books[idx];
      final chapterCount = book.chapterCount;
      final chapters = [for (var c = 1; c <= chapterCount; c++) c];
      // Clamp the current chapter into range and load its verses.
      var chapter = _selectedChapter;
      if (chapter < 1 || chapter > chapterCount) chapter = 1;
      var verses = <int>[];
      if (chapter >= 1 && chapter <= book.chapters.length) {
        final count = book.chapters[chapter - 1].length;
        verses = [for (var v = 1; v <= count; v++) v];
      }
      if (mounted) {
        setState(() {
          _chapterOptions = chapters;
          _verseOptions = verses;
          _selectedChapter = chapter;
          _chapterController.text = '$chapter';
          if (_selectedVerse > verses.length) _selectedVerse = 0;
        });
      }
    } catch (_) {
      if (mounted) setState(() { _chapterOptions = [1]; _verseOptions = <int>[]; });
    }
  }

  /// Refreshes only the Verse options when the chapter changes.
  Future<void> _loadVersesForChapter() async {
    try {
      final books = await OfflineBibleService.instance.books('kjv');
      final idx = books.indexWhere((b) => b.name.toLowerCase() == _selectedBook.toLowerCase());
      if (idx < 0) return;
      final book = books[idx];
      var verses = <int>[];
      if (_selectedChapter >= 1 && _selectedChapter <= book.chapters.length) {
        final count = book.chapters[_selectedChapter - 1].length;
        verses = [for (var v = 1; v <= count; v++) v];
      }
      if (mounted) {
        setState(() {
          _verseOptions = verses;
          if (_selectedVerse > verses.length) _selectedVerse = 0;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _verseOptions = <int>[]);
    }
  }

  void _navChapter(int delta) {
    final next = _selectedChapter + delta;
    if (next < 1 || next > _chapterOptions.length) return;
    setState(() {
      _selectedChapter = next;
      _chapterController.text = '$next';
      _selectedVerse = 0;
      _verseController.text = '';
    });
    _loadVersesForChapter();
    _read();
  }

  Future<void> _openSearchScreen() async {
    final versionKey = _offline ? _selectedVersion.toLowerCase() : 'kjv';
    final result = await Navigator.push<({String book, int chapter, int verse})>(
      context,
      MaterialPageRoute(builder: (_) => _BibleSearchScreen(versionKey: versionKey)),
    );
    if (result != null && mounted) {
      setState(() {
        _selectedBook = result.book;
        _selectedChapter = result.chapter;
        _selectedVerse = result.verse;
        _chapterController.text = '${result.chapter}';
        _verseController.text = result.verse > 0 ? '${result.verse}' : '';
        _showSearch = true;
      });
      await _loadStructure();
      await _read();
    }
  }

  Future<void> _showFontSizeSheet() async {
    await showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (context) => StatefulBuilder(builder: (context, setSheetState) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 24),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('Font size', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              const SizedBox(height: 4),
              Row(children: [
                const Icon(Icons.format_size),
                Expanded(
                  child: Slider(
                    min: 12,
                    max: 28,
                    divisions: 8,
                    value: _fontSize,
                    onChanged: (v) {
                      setSheetState(() {});
                      setState(() => _fontSize = v);
                      BibleLocalStore.instance.setSetting('bible_font_size', v.round().toString());
                    },
                  ),
                ),
                Text('${_fontSize.round()}'),
              ]),
              const SizedBox(height: 4),
              Text('The quick brown fox', style: TextStyle(fontSize: _fontSize)),
            ]),
          ),
        );
      }),
    );
  }

  Future<void> _showVerseActions(int verse, String text) async {
    final bookmarked = _bookmarked.contains(verse);
    final existingNote = _notes[verse];
    final currentColor = _highlights[verse];
    final reference = '$_selectedBook $_selectedChapter:$verse';

    await showModalBottomSheet(
      context: context,
      showDragHandle: true,
      builder: (sheetContext) {
        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(8, 0, 8, 12),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Text(reference, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                child: Text(text, maxLines: 3, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.inkDim)),
              ),
              const Divider(height: 16),
              ListTile(
                dense: true,
                leading: Icon(bookmarked ? Icons.bookmark : Icons.bookmark_border, color: AppColors.gold),
                title: Text(bookmarked ? 'Remove bookmark' : 'Bookmark'),
                onTap: () async {
                  Navigator.pop(sheetContext);
                  if (bookmarked) {
                    await BibleLocalStore.instance.removeBookmark(_selectedBook, _selectedChapter, verse);
                  } else {
                    await BibleLocalStore.instance.addBookmark(_selectedBook, _selectedChapter, verse, _selectedVersion);
                  }
                  await _refreshChapterMeta();
                },
              ),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
                child: Row(children: [
                  const Icon(Icons.brush, size: 24),
                  const SizedBox(width: 16),
                  const Expanded(child: Text('Highlight', style: TextStyle(fontWeight: FontWeight.w600))),
                  for (final e in _highlightColors.entries)
                    Padding(
                      padding: const EdgeInsets.only(left: 6),
                      child: GestureDetector(
                        onTap: () async {
                          Navigator.pop(sheetContext);
                          await BibleLocalStore.instance.setHighlight(_selectedBook, _selectedChapter, verse, e.key);
                          await _refreshChapterMeta();
                        },
                        child: CircleAvatar(
                          radius: 12,
                          backgroundColor: e.value,
                          child: currentColor == e.key ? const Icon(Icons.check, size: 14, color: Colors.black) : null,
                        ),
                      ),
                    ),
                  const SizedBox(width: 6),
                  GestureDetector(
                    onTap: () async {
                      Navigator.pop(sheetContext);
                      await BibleLocalStore.instance.setHighlight(_selectedBook, _selectedChapter, verse, null);
                      await _refreshChapterMeta();
                    },
                    child: const CircleAvatar(radius: 12, child: Icon(Icons.clear, size: 14, color: Colors.grey)),
                  ),
                ]),
              ),
              ListTile(
                dense: true,
                leading: const Icon(Icons.sticky_note_2_outlined),
                title: Text(existingNote != null && existingNote.isNotEmpty ? 'Edit note' : 'Add note'),
                onTap: () { Navigator.pop(sheetContext); _editNote(verse); },
              ),
              ListTile(
                dense: true,
                leading: const Icon(Icons.copy),
                title: const Text('Copy'),
                onTap: () async {
                  Navigator.pop(sheetContext);
                  await Clipboard.setData(ClipboardData(text: '$reference\n\n$text'));
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Verse copied'), duration: Duration(seconds: 1)));
                  }
                },
              ),
              ListTile(
                dense: true,
                leading: const Icon(Icons.ios_share),
                title: const Text('Share'),
                onTap: () {
                  Navigator.pop(sheetContext);
                  ShareService.share(text: '$reference\n\n$text');
                },
              ),
            ]),
          ),
        );
      },
    );
  }

  Future<void> _editNote(int verse) async {
    final controller = TextEditingController(text: _notes[verse] ?? '');
    final saved = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Note'),
        content: TextField(controller: controller, maxLines: 4, autofocus: true, decoration: const InputDecoration(hintText: 'Write a note...')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          TextButton(onPressed: () => Navigator.pop(context, controller.text.trim()), child: const Text('Save')),
        ],
      ),
    );
    if (saved != null && mounted) {
      await BibleLocalStore.instance.saveNote(_selectedBook, _selectedChapter, verse, saved);
      await _refreshChapterMeta();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('Holy Bible'),
        centerTitle: true,
        actions: [
          IconButton(
            icon: Icon(_reading ? Icons.stop_circle_outlined : Icons.play_circle_outline),
            tooltip: _reading ? 'Stop reading' : 'Read this chapter aloud',
            onPressed: _reading ? _stopReading : _readAloud,
          ),
          IconButton(icon: const Icon(Icons.record_voice_over), tooltip: 'Reading voice & speed', onPressed: _showVoiceSheet),
          IconButton(icon: const Icon(Icons.search), tooltip: 'Search', onPressed: _openSearchScreen),
          IconButton(icon: const Icon(Icons.text_fields), tooltip: 'Font size', onPressed: _showFontSizeSheet),
          if (!_showSearch)
            IconButton(icon: const Icon(Icons.my_location), tooltip: 'Open a new passage', onPressed: _openSearchPanel),
        ],
      ),
      body: Column(children: [
        AnimatedSize(
          duration: const Duration(milliseconds: 250),
          curve: Curves.easeInOut,
          alignment: Alignment.topCenter,
          child: _showSearch ? _searchCard() : const SizedBox(width: double.infinity, height: 0),
        ),
        const SizedBox(height: 4),
        Expanded(child: _buildContent(theme)),
        if (_passage.isNotEmpty && !_showSearch)
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 6, 16, 8),
              child: Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    icon: const Icon(Icons.chevron_left),
                    label: const Text('Previous', overflow: TextOverflow.ellipsis),
                    onPressed: () => _navChapter(-1),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton.icon(
                    icon: const Icon(Icons.chevron_right),
                    label: const Text('Next', overflow: TextOverflow.ellipsis),
                    onPressed: () => _navChapter(1),
                  ),
                ),
              ]),
            ),
          ),
      ]),
    );
  }

  Widget _searchCard() {
    return Card(
      elevation: 2,
      margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(children: [
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<String>(
                value: _selectedVersion,
                decoration: const InputDecoration(labelText: 'Version', isDense: true),
                items: [
                  for (final v in OfflineBibleService.versions.values) DropdownMenuItem(value: v, child: Text('$v (Offline)')),
                  for (final v in _onlineVersions) DropdownMenuItem(value: v, child: Text('$v (Online)')),
                ],
                onChanged: (val) { if (val != null) _onVersionChanged(val); },
              ),
            ),
            if (!_offline) ...[
              const SizedBox(width: 12),
              Expanded(
                child: DropdownButtonFormField<String>(
                  value: _selectedLang,
                  decoration: const InputDecoration(labelText: 'Language', isDense: true),
                  items: const [
                    DropdownMenuItem(value: 'en', child: Text('English')),
                    DropdownMenuItem(value: 'es', child: Text('Español')),
                    DropdownMenuItem(value: 'fr', child: Text('Français')),
                    DropdownMenuItem(value: 'yo', child: Text('Yorùbá')),
                    DropdownMenuItem(value: 'ig', child: Text('Igbo')),
                    DropdownMenuItem(value: 'ha', child: Text('Hausa')),
                  ],
                  onChanged: (val) { if (val != null) setState(() => _selectedLang = val); },
                ),
              ),
            ],
          ]),
          const SizedBox(height: 12),
          DropdownButtonFormField<String>(
            value: _selectedBook,
            isExpanded: true,
            decoration: const InputDecoration(labelText: 'Book', isDense: true),
            items: _books.map((b) => DropdownMenuItem(value: b, child: Text(b, overflow: TextOverflow.ellipsis))).toList(),
            onChanged: (val) {
              if (val != null && val != _selectedBook) {
                setState(() {
                  _selectedBook = val;
                  _selectedChapter = 1;
                  _selectedVerse = 0;
                  _chapterController.text = '1';
                  _verseController.text = '';
                });
                _loadStructure();
              }
            },
          ),
          const SizedBox(height: 12),
          Row(children: [
            Expanded(
              child: DropdownButtonFormField<int>(
                value: _selectedChapter,
                decoration: const InputDecoration(labelText: 'Chapter', isDense: true),
                items: _chapterOptions
                    .map((c) => DropdownMenuItem(value: c, child: Text('$c')))
                    .toList(),
                onChanged: (val) {
                  if (val != null && val != _selectedChapter) {
                    setState(() {
                      _selectedChapter = val;
                      _selectedVerse = 0;
                      _chapterController.text = '$val';
                      _verseController.text = '';
                    });
                    _loadVersesForChapter();
                  }
                },
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: DropdownButtonFormField<int>(
                value: _selectedVerse,
                decoration: const InputDecoration(labelText: 'Verse', isDense: true),
                hint: const Text('All'),
                items: [
                  const DropdownMenuItem(value: 0, child: Text('All')),
                  for (final v in _verseOptions) DropdownMenuItem(value: v, child: Text('$v')),
                ],
                onChanged: (val) {
                  if (val != null) {
                    setState(() {
                      _selectedVerse = val;
                      _verseController.text = val > 0 ? '$val' : '';
                    });
                  }
                },
              ),
            ),
          ]),
          const SizedBox(height: 12),
          SizedBox(
            width: double.infinity,
            child: FilledButton.icon(icon: const Icon(Icons.menu_book), label: const Text('Read'), onPressed: _read),
          ),
          const SizedBox(height: 4),
          const Text('Pick “All” in Verse to read the whole chapter from verse 1.', style: TextStyle(fontSize: 11, color: AppColors.inkFaint)),
        ]),
      ),
    );
  }

  Widget _buildContent(ThemeData theme) {
    if (_isLoading) return const Center(child: CircularProgressIndicator());
    if (_errorMessage.isNotEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(20),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.cloud_off, size: 40, color: AppColors.inkFaint),
            const SizedBox(height: 12),
            Text(_errorMessage, textAlign: TextAlign.center),
            const SizedBox(height: 12),
            if (!_offline)
              OutlinedButton(onPressed: () => _onVersionChanged('KJV'), child: const Text('Read KJV offline')),
          ]),
        ),
      );
    }
    if (_passage.isEmpty) return _emptyState(theme);
    return ListView.builder(
      controller: _scrollController,
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 16),
      itemCount: _passage.length + 1,
      itemBuilder: (context, index) {
        if (index == 0) {
          return Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Row(children: [
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(_reference, style: theme.textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.bold)),
                  if (_translation.isNotEmpty) Text(_translation, style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.outline)),
                ]),
              ),
              IconButton(icon: const Icon(Icons.my_location), tooltip: 'Open a new passage', onPressed: _openSearchPanel),
            ]),
          );
        }
        final p = _passage[index - 1];
        final tile = _verseTile(theme, p.verse, p.text);
        // Anchor the searched verse so the chapter can scroll straight to it.
        if (p.verse == _targetVerse && _targetVerse > 0) {
          return KeyedSubtree(key: _verseAnchorKey, child: tile);
        }
        return tile;
      },
    );
  }

  /// Reads the current chapter aloud, from the chosen verse to the end.
  ///
  /// The scroll-and-highlight reuses the mechanism the reader already has for "show the
  /// chapter scrolled to this verse" rather than adding a second one, so a verse spoken aloud
  /// behaves exactly like a verse jumped to.
  Future<void> _readAloud() async {
    if (_passage.isEmpty) return;

    // Ask the phone whether it can actually speak BEFORE starting, rather than letting the
    // reader tap play and hear nothing. Silence is the one failure they cannot diagnose for
    // themselves: there is no error to read, and the cause is always a screen deep inside the
    // phone's settings. So when something is missing, the voice sheet opens on the explanation
    // and the fix instead of the reader being left to guess.
    final diagnosis = await BibleTtsService.instance.diagnose();
    if (!mounted) return;
    if (!diagnosis.isReady) {
      await _showVoiceSheet();
      return;
    }

    final verses = _passage.map((v) => v.text).toList();
    final start = (_selectedVerse > 0 && _selectedVerse <= verses.length) ? _selectedVerse - 1 : 0;

    setState(() => _reading = true);

    await BibleTtsService.instance.readChapter(
      verses: verses.sublist(start),
      firstVerseNumber: start + 1,
      onVerse: (verseNumber) {
        if (!mounted) return;
        setState(() {
          _speakingVerse = verseNumber;
          _targetVerse = verseNumber;
          _targetFading = false;
        });
        _scrollToVerse();
      },
      onDone: () {
        if (!mounted) return;
        setState(() {
          _reading = false;
          _speakingVerse = 0;
        });
      },
      onError: (message) {
        if (!mounted) return;
        setState(() {
          _reading = false;
          _speakingVerse = 0;
        });
        // The diagnosis said this phone could read, but it would not. Offer the fix where the
        // reader already is, instead of telling them to go and find it.
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
          content: Text(message),
          action: SnackBarAction(label: 'Fix it', onPressed: _showVoiceSheet),
        ));
      },
    );
  }

  Future<void> _stopReading() async {
    await BibleTtsService.instance.stop();
    if (!mounted) return;
    setState(() {
      _reading = false;
      _speakingVerse = 0;
    });
  }

  /// The reading voice and pace, chosen by ear — and, when the phone cannot read aloud at all,
  /// what is missing and the one button that fixes it.
  ///
  /// The voices listed are the ones the device actually has — not a Male/Female pair chosen by
  /// this app. Phone speech engines do not report gender, so any such switch would be a guess,
  /// and a wrong guess is obvious the moment it speaks. Every entry can be heard first, which
  /// is how a person really picks a voice.
  Future<void> _showVoiceSheet() async {
    // Re-probed every time the sheet opens, because phones change underneath an app: voice
    // data gets installed or reclaimed for storage, and a reader who has just followed the
    // instructions deserves to see the result rather than a stale verdict.
    var voices = await BibleTtsService.instance.availableVoices();
    var diagnosis = await BibleTtsService.instance.diagnose();
    _voiceSettingsUnavailable = false;
    if (!mounted) return;

    await showModalBottomSheet(
      context: context,
      showDragHandle: true,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(builder: (context, setSheetState) {
        // The values above live outside this builder on purpose: the builder re-runs on every
        // setSheetState, so anything declared in here would be reset each time it rebuilt.
        final d = diagnosis;
        final listed = voices;

        Future<void> recheck() async {
          final v = await BibleTtsService.instance.availableVoices();
          final refreshed = await BibleTtsService.instance.diagnose();
          if (!context.mounted) return;
          setSheetState(() {
            voices = v;
            diagnosis = refreshed;
            _voiceSettingsUnavailable = false;
          });
        }

        return SafeArea(
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 0, 20, 16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Reading voice', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                const SizedBox(height: 4),
                if (!d.isReady)
                  _voiceProblemCard(
                    d,
                    showManualPath: _voiceSettingsUnavailable,
                    onOpenSettings: () async {
                      final opened = await DeviceSettingsService.openVoiceSettings();
                      if (!context.mounted) return;
                      setSheetState(() => _voiceSettingsUnavailable = !opened);
                    },
                    onInstallEngine: _installSpeechEngine,
                    onRecheck: recheck,
                  )
                else ...[
                  Text(
                    listed.isEmpty
                        ? 'This device reported no voices.'
                        : 'Tap a voice to use it, or the play icon to hear it first.',
                    style: const TextStyle(fontSize: 12.5, color: AppColors.inkFaint),
                  ),
                  if (d.needsVoiceDataHint) ...[
                    const SizedBox(height: 6),
                    const Text(
                      'If the reading sounds wrong or stays silent, the English voice data may not '
                      'be downloaded on this phone yet.',
                      style: TextStyle(fontSize: 11.5, color: AppColors.inkFaint),
                    ),
                  ],
                ],
                const SizedBox(height: 10),
                Row(children: [
                  const Icon(Icons.speed, size: 20),
                  Expanded(
                    child: Slider(
                      min: 0.5,
                      max: 1.2,
                      divisions: 14,
                      value: _ttsRate,
                      label: '${(_ttsRate * 100).round()}%',
                      onChanged: (v) {
                        setSheetState(() {});
                        setState(() => _ttsRate = v);
                        BibleTtsService.instance.setRate(v);
                        BibleLocalStore.instance.setSetting('bible_tts_rate', v.toStringAsFixed(2));
                      },
                    ),
                  ),
                  Text('${(_ttsRate * 100).round()}%'),
                ]),
                const SizedBox(height: 6),
                if (listed.isNotEmpty)
                  Flexible(
                    child: ListView.builder(
                      shrinkWrap: true,
                      itemCount: listed.length,
                      itemBuilder: (context, i) {
                        final v = listed[i];
                        final selected = BibleTtsService.instance.selectedVoiceName == v.name;
                        return ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          leading: Icon(
                            selected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                            size: 20,
                            color: selected ? AppColors.gold : AppColors.inkFaint,
                          ),
                          title: Text(v.label, style: const TextStyle(fontSize: 13.5)),
                          subtitle: v.gender == null
                              ? null
                              : Text('sounds like a ${v.gender=="male" ? "man" : "woman"} (from the voice name — a hint, not a guarantee)', style: const TextStyle(fontSize: 11, color: AppColors.inkFaint)),
                          trailing: IconButton(
                            icon: const Icon(Icons.play_arrow, size: 20),
                            tooltip: 'Hear it',
                            onPressed: () => BibleTtsService.instance.preview(v),
                          ),
                          onTap: () async {
                            await BibleTtsService.instance.selectVoice(v);
                            await BibleLocalStore.instance.setSetting('bible_tts_voice', v.name);
                            setSheetState(() {});
                            if (context.mounted) setState(() {});
                          },
                        );
                      },
                    ),
                  ),
              ],
            ),
          ),
        );
      }),
    );
  }

  /// What is missing, in the reader's own words, with the button that fixes it.
  ///
  /// This is the whole answer to "the voice does not work": the cause named plainly, the one
  /// action that resolves it, and — because that button can fail on a phone whose build does
  /// not expose the screen — the route there by hand, so there is always something to act on.
  Widget _voiceProblemCard(
    BibleTtsDiagnosis d, {
    required bool showManualPath,
    required VoidCallback onOpenSettings,
    required VoidCallback onInstallEngine,
    required VoidCallback onRecheck,
  }) {
    const danger = Color(0xFF8C1D18);
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0x14B3261E),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0x33B3261E)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(children: [
            const Icon(Icons.volume_off_outlined, size: 18, color: danger),
            const SizedBox(width: 8),
            Expanded(
              child: Text(d.title,
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 13.5, color: danger)),
            ),
          ]),
          const SizedBox(height: 6),
          Text(d.detail, style: const TextStyle(fontSize: 12, height: 1.4)),
          const SizedBox(height: 8),
          Text(
            // The button was pressed and no screen appeared: say so, and give the route.
            showManualPath
                ? 'This phone would not open that screen. Open ${BibleTtsDiagnosis.manualPath}.'
                : 'On the phone: ${BibleTtsDiagnosis.manualPath}.',
            style: const TextStyle(fontSize: 11, color: AppColors.inkFaint),
          ),
          const SizedBox(height: 10),
          Wrap(spacing: 8, runSpacing: 8, children: [
            if (d.shouldInstallEngine)
              FilledButton.icon(
                onPressed: onInstallEngine,
                icon: const Icon(Icons.download_outlined, size: 18),
                label: const Text('Install a voice'),
              ),
            OutlinedButton.icon(
              onPressed: onOpenSettings,
              icon: const Icon(Icons.settings_outlined, size: 18),
              label: Text(d.settingsLabel),
            ),
            TextButton.icon(
              onPressed: onRecheck,
              icon: const Icon(Icons.refresh, size: 18),
              label: const Text('Check again'),
            ),
          ]),
        ],
      ),
    );
  }

  /// Google's speech engine on the store, for a phone that shipped without one.
  Future<void> _installSpeechEngine() async {
    await launchUrl(
      Uri.parse(BibleTtsDiagnosis.googleEngineStoreUrl),
      mode: LaunchMode.externalApplication,
    );
  }

  Widget _verseTile(ThemeData theme, int verse, String text) {
    final color = _highlights[verse];
    final isTarget = _targetVerse > 0 && verse == _targetVerse;
    final isSpeaking = _speakingVerse == verse;
    final tileColor = isSpeaking
        // The verse being read. Takes precedence over a manual highlight, because while the
        // voice is moving the reader needs to see WHERE it is more than where they left off.
        ? const Color(0x33E8B95F)
        : (isTarget && color == null
            // Fade from a golden tint to transparent once the search highlight
            // has served its purpose (manual highlights still win).
            ? (_targetFading
                ? const Color(0x00E8B95F)
                : const Color(0x33E8B95F))
            : (color != null ? (_highlightColors[color] ?? Colors.yellow).withValues(alpha: 0.35) : null));
    return GestureDetector(
      onLongPress: () => _showVerseActions(verse, text),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 1000),
        curve: Curves.easeOut,
        color: tileColor,
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Expanded(
            child: RichText(
              text: TextSpan(
                style: TextStyle(color: theme.colorScheme.onSurface, fontSize: _fontSize, height: 1.6),
                children: [
                  TextSpan(
                    text: '$verse ',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: _fontSize * 0.65, color: theme.colorScheme.outline),
                  ),
                  TextSpan(text: text),
                ],
              ),
            ),
          ),
          if (_bookmarked.contains(verse) || _notes.containsKey(verse))
            Padding(
              padding: const EdgeInsets.only(left: 6, top: 2),
              child: Column(children: [
                if (_bookmarked.contains(verse)) const Icon(Icons.bookmark, size: 13, color: AppColors.gold),
                if (_notes.containsKey(verse)) const Padding(padding: EdgeInsets.only(top: 2), child: Icon(Icons.sticky_note_2_outlined, size: 13, color: AppColors.inkFaint)),
              ]),
            ),
        ]),
      ),
    );
  }

  Widget _emptyState(ThemeData theme) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          const Icon(Icons.menu_book, size: 44, color: AppColors.inkFaint),
          const SizedBox(height: 12),
          Text(_verseOfDay.reference, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
          const SizedBox(height: 8),
          Text('“${_verseOfDay.text}”', textAlign: TextAlign.center, style: const TextStyle(fontStyle: FontStyle.italic, fontSize: 15, color: AppColors.inkDim)),
          const SizedBox(height: 20),
          const Text('Select a book, chapter, and verse above to begin reading.', textAlign: TextAlign.center, style: TextStyle(color: AppColors.inkFaint)),
        ]),
      ),
    );
  }
}

/// Full-screen offline Bible search. Returns a record via Navigator.pop.
class _BibleSearchScreen extends StatefulWidget {
  final String versionKey;
  const _BibleSearchScreen({required this.versionKey});

  @override
  State<_BibleSearchScreen> createState() => _BibleSearchScreenState();
}

class _BibleSearchScreenState extends State<_BibleSearchScreen> {
  final _controller = TextEditingController();
  List<OfflineSearchHit> _results = [];
  bool _searching = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _run(String q) async {
    setState(() => _searching = true);
    final r = await OfflineBibleService.instance.search(widget.versionKey, q);
    if (mounted) setState(() { _results = r; _searching = false; });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Search Bible')),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            controller: _controller,
            autofocus: true,
            decoration: const InputDecoration(hintText: 'Search verses...', prefixIcon: Icon(Icons.search), border: OutlineInputBorder()),
            onChanged: _run,
          ),
        ),
        Expanded(
          child: _searching
              ? const Center(child: CircularProgressIndicator())
              : _results.isEmpty
                  ? const Center(child: Padding(padding: EdgeInsets.all(20), child: Text('Type a word or phrase to search the whole Bible.')))
                  : ListView.builder(
                      itemCount: _results.length,
                      itemBuilder: (context, i) {
                        final r = _results[i];
                        return ListTile(
                          dense: true,
                          leading: const Icon(Icons.menu_book, color: AppColors.gold),
                          title: Text(r.reference, style: const TextStyle(fontWeight: FontWeight.bold)),
                          subtitle: Text(r.text, maxLines: 2, overflow: TextOverflow.ellipsis),
                          onTap: () => Navigator.pop(context, (book: r.book, chapter: r.chapter, verse: r.verse)),
                        );
                      },
                    ),
        ),
      ]),
    );
  }
}
