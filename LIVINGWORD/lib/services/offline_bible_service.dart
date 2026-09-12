import 'dart:convert';
import 'package:flutter/services.dart' show rootBundle;

/// A single book of the Bible loaded from the bundled JSON asset.
class OfflineBook {
  final String abbrev;
  final String name;

  /// chapters[chapter - 1][verse - 1] -> verse text
  final List<List<String>> chapters;

  OfflineBook({required this.abbrev, required this.name, required this.chapters});

  int get chapterCount => chapters.length;
}

/// A single verse found by offline search.
class OfflineSearchHit {
  final String book;
  final int chapter;
  final int verse;
  final String text;

  OfflineSearchHit({required this.book, required this.chapter, required this.verse, required this.text});

  String get reference => '$book $chapter:$verse';
}

/// A curated verse for the home/empty state, fully offline.
class OfflineVerseOfDay {
  final String text;
  final String reference;
  const OfflineVerseOfDay({required this.text, required this.reference});
}

/// Loads the public-domain Bible translations bundled with the app
/// (KJV, BBE) so scripture is fully readable offline.
///
/// Other public-domain versions (WEB, ASV, YLT) can be added by dropping a JSON
/// asset in the same `[{abbrev, chapters, name}]` format under assets/bible and
/// registering it in [versions].
class OfflineBibleService {
  OfflineBibleService._();
  static final OfflineBibleService instance = OfflineBibleService._();

  /// offline version key -> display abbreviation.
  static const Map<String, String> versions = {
    'kjv': 'KJV',
    'bbe': 'BBE',
  };

  /// Whether the given abbreviation (e.g. 'KJV') is available offline.
  static bool isOffline(String abbrev) => versions.containsKey(abbrev.toLowerCase());

  final Map<String, List<OfflineBook>> _cache = {};

  /// version -> book -> chapter -> lowercased verse text. Built once per version
  /// at load time so a whole-Bible search never re-lowercases every verse.
  final Map<String, List<List<List<String>>>> _lowerCache = {};

  /// Preloads every bundled version into memory so the Bible opens and searches
  /// instantly (no decode delay on first read/search). Call once at startup.
  Future<void> warmUp() async {
    for (final key in versions.keys) {
      await books(key);
    }
  }

  Future<List<OfflineBook>> books(String versionKey) async {
    final key = versionKey.toLowerCase();
    final cached = _cache[key];
    if (cached != null) return cached;

    final raw = await rootBundle.loadString('assets/bible/$key.json');
    final decoded = jsonDecode(raw) as List<dynamic>;
    final list = <OfflineBook>[];
    final lower = <List<List<String>>>[];
    for (final b in decoded) {
      final map = b as Map<String, dynamic>;
      final chapters = (map['chapters'] as List<dynamic>)
          .map((c) => (c as List<dynamic>).cast<String>())
          .toList();
      list.add(OfflineBook(
        abbrev: (map['abbrev'] as String?) ?? '',
        name: (map['name'] as String?) ?? (map['abbrev'] as String?) ?? '',
        chapters: chapters,
      ));
      lower.add(chapters
          .map((c) => c.map((s) => s.toLowerCase()).toList())
          .toList());
    }

    _cache[key] = list;
    _lowerCache[key] = lower;
    return list;
  }

  /// Chapter verses (1-based chapter) for the given book index.
  Future<List<String>> chapterVerses(String versionKey, int bookIndex, int chapter) async {
    final all = await books(versionKey);
    if (bookIndex < 0 || bookIndex >= all.length) return [];
    final ch = all[bookIndex].chapters;
    if (chapter < 1 || chapter > ch.length) return [];
    return ch[chapter - 1];
  }

  Future<String> verse(String versionKey, int bookIndex, int chapter, int verse) async {
    final verses = await chapterVerses(versionKey, bookIndex, chapter);
    if (verse < 1 || verse > verses.length) return '';
    return verses[verse - 1];
  }

  /// Case-insensitive whole-Bible search. Returns up to [limit] hits.
  /// Fast: scans a precomputed lowercase index instead of lowercasing every
  /// verse on each keystroke.
  Future<List<OfflineSearchHit>> search(String versionKey, String query, {int limit = 50}) async {
    final q = query.toLowerCase().trim();
    if (q.isEmpty) return const [];
    final key = versionKey.toLowerCase();
    final all = await books(key); // ensures both caches are built
    final lower = _lowerCache[key] ?? [];
    final hits = <OfflineSearchHit>[];
    for (var bi = 0; bi < all.length && hits.length < limit; bi++) {
      final book = all[bi];
      final bookLower = lower.isNotEmpty ? lower[bi] : null;
      final chapters = bookLower ?? book.chapters;
      for (var c = 0; c < chapters.length && hits.length < limit; c++) {
        final verses = chapters[c];
        for (var v = 0; v < verses.length && hits.length < limit; v++) {
          if (verses[v].contains(q)) {
            hits.add(OfflineSearchHit(
              book: book.name,
              chapter: c + 1,
              verse: v + 1,
              text: book.chapters[c][v],
            ));
          }
        }
      }
    }
    return hits;
  }

  /// Rotating verse of the day (public-domain KJV), fully offline.
  OfflineVerseOfDay verseOfDay() {
    const list = [
      OfflineVerseOfDay(
        text: 'For God so loved the world, that he gave his only begotten Son, that whosoever believeth in him should not perish, but have everlasting life.',
        reference: 'John 3:16',
      ),
      OfflineVerseOfDay(
        text: 'I can do all things through Christ which strengtheneth me.',
        reference: 'Philippians 4:13',
      ),
      OfflineVerseOfDay(
        text: 'Trust in the LORD with all thine heart; and lean not unto thine own understanding. In all thy ways acknowledge him, and he shall direct thy paths.',
        reference: 'Proverbs 3:5–6',
      ),
      OfflineVerseOfDay(
        text: 'The LORD is my shepherd; I shall not want.',
        reference: 'Psalm 23:1',
      ),
      OfflineVerseOfDay(
        text: 'For I know the thoughts that I think toward you, saith the LORD, thoughts of peace, and not of evil, to give you an expected end.',
        reference: 'Jeremiah 29:11',
      ),
      OfflineVerseOfDay(
        text: 'And we know that all things work together for good to them that love God, to them who are the called according to his purpose.',
        reference: 'Romans 8:28',
      ),
      OfflineVerseOfDay(
        text: 'Be strong and of a good courage; be not afraid, neither be thou dismayed: for the LORD thy God is with thee whithersoever thou goest.',
        reference: 'Joshua 1:9',
      ),
      OfflineVerseOfDay(
        text: 'But they that wait upon the LORD shall renew their strength; they shall mount up with wings as eagles; they shall run, and not be weary; and they shall walk, and not faint.',
        reference: 'Isaiah 40:31',
      ),
    ];
    return list[DateTime.now().day % list.length];
  }
}
