import 'package:path/path.dart';
import 'package:sqflite/sqflite.dart';

/// SQLite-backed local storage for the offline Bible: bookmarks, highlights,
/// notes, reading position, and small app settings (e.g. font size).
class BibleLocalStore {
  BibleLocalStore._();
  static final BibleLocalStore instance = BibleLocalStore._();

  Database? _db;

  Future<Database> get _database async {
    if (_db != null) return _db!;
    final dir = await getDatabasesPath();
    _db = await openDatabase(
      join(dir, 'church_bible.db'),
      version: 1,
      onCreate: (db, version) async {
        await db.execute('''
          CREATE TABLE bookmarks(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book TEXT NOT NULL,
            chapter INTEGER NOT NULL,
            verse INTEGER NOT NULL,
            version TEXT NOT NULL,
            created_at INTEGER NOT NULL
          )
        ''');
        await db.execute('''
          CREATE TABLE highlights(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book TEXT NOT NULL,
            chapter INTEGER NOT NULL,
            verse INTEGER NOT NULL,
            color TEXT NOT NULL,
            UNIQUE(book, chapter, verse)
          )
        ''');
        await db.execute('''
          CREATE TABLE notes(
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            book TEXT NOT NULL,
            chapter INTEGER NOT NULL,
            verse INTEGER NOT NULL,
            text TEXT NOT NULL,
            updated_at INTEGER NOT NULL,
            UNIQUE(book, chapter, verse)
          )
        ''');
        await db.execute('''
          CREATE TABLE reading_position(
            version TEXT PRIMARY KEY,
            book TEXT NOT NULL,
            chapter INTEGER NOT NULL,
            verse INTEGER NOT NULL DEFAULT 0
          )
        ''');
        await db.execute('''
          CREATE TABLE settings(
            key TEXT PRIMARY KEY,
            value TEXT
          )
        ''');
      },
    );
    return _db!;
  }

  // --- Bookmarks ----------------------------------------------------------

  Future<bool> isBookmarked(String book, int chapter, int verse) async {
    final db = await _database;
    final rows = await db.query('bookmarks',
        where: 'book = ? AND chapter = ? AND verse = ?',
        whereArgs: [book, chapter, verse],
        limit: 1);
    return rows.isNotEmpty;
  }

  Future<Set<int>> bookmarkedVerses(String book, int chapter) async {
    final db = await _database;
    final rows = await db.query('bookmarks',
        where: 'book = ? AND chapter = ?', whereArgs: [book, chapter]);
    return {for (final r in rows) r['verse'] as int};
  }

  Future<void> addBookmark(String book, int chapter, int verse, String version) async {
    final db = await _database;
    await db.insert('bookmarks', {
      'book': book,
      'chapter': chapter,
      'verse': verse,
      'version': version.toLowerCase(),
      'created_at': DateTime.now().millisecondsSinceEpoch,
    }, conflictAlgorithm: ConflictAlgorithm.ignore);
  }

  Future<void> removeBookmark(String book, int chapter, int verse) async {
    final db = await _database;
    await db.delete('bookmarks',
        where: 'book = ? AND chapter = ? AND verse = ?',
        whereArgs: [book, chapter, verse]);
  }

  // --- Highlights ---------------------------------------------------------

  /// verse number -> color for the given chapter.
  Future<Map<int, String>> highlightsForChapter(String book, int chapter) async {
    final db = await _database;
    final rows = await db.query('highlights',
        where: 'book = ? AND chapter = ?', whereArgs: [book, chapter]);
    return {for (final r in rows) r['verse'] as int: r['color'] as String};
  }

  /// Pass [color] null/empty to remove the highlight.
  Future<void> setHighlight(String book, int chapter, int verse, String? color) async {
    final db = await _database;
    if (color == null || color.isEmpty) {
      await db.delete('highlights',
          where: 'book = ? AND chapter = ? AND verse = ?',
          whereArgs: [book, chapter, verse]);
    } else {
      await db.insert('highlights', {
        'book': book,
        'chapter': chapter,
        'verse': verse,
        'color': color,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  // --- Notes --------------------------------------------------------------

  Future<String?> noteFor(String book, int chapter, int verse) async {
    final db = await _database;
    final rows = await db.query('notes',
        where: 'book = ? AND chapter = ? AND verse = ?',
        whereArgs: [book, chapter, verse],
        limit: 1);
    return rows.isEmpty ? null : rows.first['text'] as String;
  }

  /// verse number -> note text for the given chapter.
  Future<Map<int, String>> notesForChapter(String book, int chapter) async {
    final db = await _database;
    final rows = await db.query('notes',
        where: 'book = ? AND chapter = ?', whereArgs: [book, chapter]);
    return {for (final r in rows) r['verse'] as int: r['text'] as String};
  }

  /// Pass empty/whitespace text to delete the note.
  Future<void> saveNote(String book, int chapter, int verse, String text) async {
    final db = await _database;
    if (text.trim().isEmpty) {
      await db.delete('notes',
          where: 'book = ? AND chapter = ? AND verse = ?',
          whereArgs: [book, chapter, verse]);
    } else {
      await db.insert('notes', {
        'book': book,
        'chapter': chapter,
        'verse': verse,
        'text': text.trim(),
        'updated_at': DateTime.now().millisecondsSinceEpoch,
      }, conflictAlgorithm: ConflictAlgorithm.replace);
    }
  }

  // --- Reading position ---------------------------------------------------

  Future<({String book, int chapter, int verse})?> positionFor(String version) async {
    final db = await _database;
    final rows = await db.query('reading_position',
        where: 'version = ?', whereArgs: [version.toLowerCase()], limit: 1);
    if (rows.isEmpty) return null;
    final r = rows.first;
    return (book: r['book'] as String, chapter: r['chapter'] as int, verse: r['verse'] as int);
  }

  Future<void> savePosition(String version, String book, int chapter, int verse) async {
    final db = await _database;
    await db.insert('reading_position', {
      'version': version.toLowerCase(),
      'book': book,
      'chapter': chapter,
      'verse': verse,
    }, conflictAlgorithm: ConflictAlgorithm.replace);
  }

  // --- Settings -----------------------------------------------------------

  Future<String?> getSetting(String key) async {
    final db = await _database;
    final rows = await db.query('settings', where: 'key = ?', whereArgs: [key], limit: 1);
    return rows.isEmpty ? null : rows.first['value'] as String;
  }

  Future<void> setSetting(String key, String value) async {
    final db = await _database;
    await db.insert('settings', {'key': key, 'value': value}, conflictAlgorithm: ConflictAlgorithm.replace);
  }
}
