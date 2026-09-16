import 'dart:async';
import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';
import 'package:sqflite/sqflite.dart';

import '../models/models.dart';

/// "820 KB" / "12.4 MB" / "1.32 GB". Used for each download and for the total.
String formatBytes(int bytes) {
  if (bytes < 1024) return '$bytes B';
  final kb = bytes / 1024;
  if (kb < 1024) return '${kb.toStringAsFixed(kb < 10 ? 1 : 0)} KB';
  final mb = kb / 1024;
  if (mb < 1024) return '${mb.toStringAsFixed(mb < 10 ? 1 : 0)} MB';
  return '${(mb / 1024).toStringAsFixed(2)} GB';
}

/// A sermon whose audio is on this device.
///
/// Enough of the sermon is kept to rebuild it, so a saved message still opens —
/// and still has its title, speaker and series — with no network at all.
@immutable
class DownloadedSermon {
  final int sermonId;
  final String slug;
  final String title;
  final String? speaker;
  final String? series;
  final String? scriptureRef;
  final int? durationSeconds;
  final String? publishedAt;
  final String filePath;
  final int bytes;
  final int savedAt;

  const DownloadedSermon({
    required this.sermonId,
    required this.slug,
    required this.title,
    required this.filePath,
    required this.bytes,
    required this.savedAt,
    this.speaker,
    this.series,
    this.scriptureRef,
    this.durationSeconds,
    this.publishedAt,
  });

  String get sizeLabel => formatBytes(bytes);

  /// The stored row as a [Sermon], so the detail screen can render a saved
  /// message from this alone.
  ///
  /// `publishedAt` is non-nullable on [Sermon] while a sermon saved before the
  /// date was ever recorded would have none, so an empty string stands in —
  /// `DateTime.tryParse('')` is null and the date is simply left out.
  Sermon toSermon() => Sermon(
        id: sermonId,
        title: title,
        slug: slug,
        speaker: speaker,
        series: series,
        scriptureRef: scriptureRef,
        durationSeconds: durationSeconds,
        publishedAt: publishedAt ?? '',
      );

  static DownloadedSermon fromRow(Map<String, Object?> row) => DownloadedSermon(
        sermonId: (row['sermon_id'] as num).toInt(),
        slug: row['slug'] as String,
        title: row['title'] as String,
        speaker: row['speaker'] as String?,
        series: row['series'] as String?,
        scriptureRef: row['scripture_ref'] as String?,
        durationSeconds: (row['duration_seconds'] as num?)?.toInt(),
        publishedAt: row['published_at'] as String?,
        filePath: row['file_path'] as String,
        bytes: (row['bytes'] as num).toInt(),
        savedAt: (row['saved_at'] as num).toInt(),
      );
}

/// Holds sermon audio on the device so a message plays without a connection.
///
/// A [ChangeNotifier] rather than a plain helper because a download is long
/// running: the button that started it and the downloads list both have to
/// redraw as it progresses. Both read this one instance, so the two screens can
/// never disagree about what is saved.
class DownloadStore extends ChangeNotifier {
  DownloadStore._();
  static final DownloadStore instance = DownloadStore._();

  Database? _db;
  Directory? _dir;
  bool _started = false;

  List<DownloadedSermon> _items = const <DownloadedSermon>[];
  final Map<String, double> _progress = <String, double>{};
  final Map<String, String> _failures = <String, String>{};

  List<DownloadedSermon> get items => _items;
  int get totalBytes => _items.fold<int>(0, (sum, d) => sum + d.bytes);
  String get totalLabel => formatBytes(totalBytes);
  bool get isEmpty => _items.isEmpty;

  bool isDownloaded(String slug) => bySlug(slug) != null;
  bool isDownloading(String slug) => _progress.containsKey(slug);
  double? progressFor(String slug) => _progress[slug];
  String? failureFor(String slug) => _failures[slug];

  DownloadedSermon? bySlug(String slug) {
    for (final item in _items) {
      if (item.slug == slug) return item;
    }
    return null;
  }

  /// The on-device file for [slug], or null when it is not saved.
  String? localPathFor(String slug) => bySlug(slug)?.filePath;

  /// Opens the database and the folder, then reconciles the two. Safe to call
  /// more than once — the second call returns immediately.
  Future<void> init() async {
    if (_started) return;
    _started = true;
    try {
      final support = await getApplicationSupportDirectory();
      final dir = Directory(p.join(support.path, 'sermons'));
      if (!await dir.exists()) await dir.create(recursive: true);
      _dir = dir;

      _db = await openDatabase(
        p.join(await getDatabasesPath(), 'church_downloads.db'),
        version: 1,
        onCreate: (db, _) async {
          await db.execute('''
            CREATE TABLE downloads(
              slug TEXT PRIMARY KEY,
              sermon_id INTEGER NOT NULL,
              title TEXT NOT NULL,
              speaker TEXT,
              series TEXT,
              scripture_ref TEXT,
              duration_seconds INTEGER,
              published_at TEXT,
              file_path TEXT NOT NULL,
              bytes INTEGER NOT NULL,
              saved_at INTEGER NOT NULL
            )
          ''');
        },
      );
      await _reconcile();
      await _reload();
    } catch (e) {
      // A store that cannot open is not a reason to fail the app: messages stay
      // streamable from the network. Clearing the flag lets a later call retry,
      // which is what a device that was briefly out of space needs.
      debugPrint('DownloadStore.init failed: $e');
      _started = false;
      _items = const <DownloadedSermon>[];
      notifyListeners();
    }
  }

  /// Saves the audio for [sermon]. Returns true when the file is on the device.
  Future<bool> download(Sermon sermon) async {
    await init();
    final url = sermon.audioUrl;
    final dir = _dir;
    final db = _db;
    if (url == null || url.isEmpty || dir == null || db == null) return false;
    // A second tap while the first is still running would write the same file
    // twice, so it is ignored rather than queued.
    if (isDownloading(sermon.slug)) return false;

    _failures.remove(sermon.slug);
    _progress[sermon.slug] = 0;
    notifyListeners();

    final target = File(p.join(dir.path, _fileName(sermon.slug, url)));
    final partial = File('${target.path}.part');
    final client = http.Client();
    IOSink? sink;
    try {
      final response = await client.send(http.Request('GET', Uri.parse(url)));
      if (response.statusCode != 200) {
        throw HttpException('Server returned ${response.statusCode}');
      }
      final expected = response.contentLength ?? 0;
      var received = 0;
      sink = partial.openWrite();
      await for (final chunk in response.stream) {
        sink.add(chunk);
        received += chunk.length;
        if (expected > 0) {
          _progress[sermon.slug] = (received / expected).clamp(0.0, 1.0);
          notifyListeners();
        }
      }
      await sink.flush();
      await sink.close();
      sink = null;

      // Renamed only once it is whole. Writing straight to the real name would
      // leave a truncated file behind a "downloaded" tick if the connection
      // dropped halfway through.
      if (await target.exists()) await target.delete();
      await partial.rename(target.path);

      await db.insert(
        'downloads',
        <String, Object?>{
          'slug': sermon.slug,
          'sermon_id': sermon.id,
          'title': sermon.title,
          'speaker': sermon.speaker,
          'series': sermon.series,
          'scripture_ref': sermon.scriptureRef,
          'duration_seconds': sermon.durationSeconds,
          'published_at': sermon.publishedAt,
          'file_path': target.path,
          'bytes': await target.length(),
          'saved_at': DateTime.now().millisecondsSinceEpoch,
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );
      await _reload();
      return true;
    } catch (e) {
      _failures[sermon.slug] = _readableError(e);
      try {
        if (await partial.exists()) await partial.delete();
      } catch (_) {
        // Best effort: the file is swept on the next init() regardless.
      }
      return false;
    } finally {
      try {
        await sink?.close();
      } catch (_) {}
      _progress.remove(sermon.slug);
      client.close();
      notifyListeners();
    }
  }

  /// Deletes one download, file and record.
  Future<void> remove(String slug) async {
    await init();
    final db = _db;
    if (db == null) return;
    final rows = await db.query('downloads', where: 'slug = ?', whereArgs: <Object?>[slug], limit: 1);
    if (rows.isNotEmpty) {
      await _deleteFile(rows.first['file_path'] as String?);
    }
    // The record goes even when the file could not be removed. Otherwise a
    // deletion that half failed leaves an entry the user can never get rid of.
    await db.delete('downloads', where: 'slug = ?', whereArgs: <Object?>[slug]);
    _failures.remove(slug);
    await _reload();
  }

  /// Deletes every download. Backs the "free up space" control.
  Future<void> wipeAll() async {
    await init();
    final db = _db;
    if (db == null) return;
    await db.delete('downloads');
    final dir = _dir;
    if (dir != null && await dir.exists()) {
      await for (final entity in dir.list()) {
        if (entity is! File) continue;
        try {
          await entity.delete();
        } catch (_) {}
      }
    }
    _failures.clear();
    await _reload();
  }

  /// Drops records whose file is gone, and files with no record.
  ///
  /// Both directions matter. A record without a file would report a size for
  /// something that cannot be played, and a file with no record would hold space
  /// that "delete all" could never reclaim. This is also what clears `.part`
  /// files left by a download that was interrupted.
  Future<void> _reconcile() async {
    final db = _db;
    final dir = _dir;
    if (db == null || dir == null) return;

    final keep = <String>{};
    for (final row in await db.query('downloads')) {
      final path = row['file_path'] as String?;
      final slug = row['slug'] as String?;
      if (path == null || slug == null) continue;
      if (await File(path).exists()) {
        keep.add(p.basename(path));
      } else {
        await db.delete('downloads', where: 'slug = ?', whereArgs: <Object?>[slug]);
      }
    }

    await for (final entity in dir.list()) {
      if (entity is! File) continue;
      if (keep.contains(p.basename(entity.path))) continue;
      try {
        await entity.delete();
      } catch (_) {}
    }
  }

  Future<void> _deleteFile(String? path) async {
    if (path == null) return;
    try {
      final file = File(path);
      if (await file.exists()) await file.delete();
    } catch (_) {
      // Ignored on purpose — see remove().
    }
  }

  Future<void> _reload() async {
    final db = _db;
    if (db == null) {
      _items = const <DownloadedSermon>[];
    } else {
      final rows = await db.query('downloads', orderBy: 'saved_at DESC');
      _items = rows.map(DownloadedSermon.fromRow).toList(growable: false);
    }
    notifyListeners();
  }

  /// A filesystem-safe name for a slug that came from the API.
  ///
  /// The slug becomes part of a path, so anything that is not a letter, digit,
  /// dash or underscore is replaced. Dots are dropped deliberately, which makes
  /// `..` unrepresentable — without that a slug of `../../x` would write outside
  /// the downloads folder.
  static String _fileName(String slug, String url) {
    final safe = slug.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '_');
    return '${safe.isEmpty ? 'sermon' : safe}${_extensionFor(url)}';
  }

  /// The source extension, so a player picks the right decoder. Anything odd —
  /// a query string, no extension at all — falls back to `.mp3`, which is what
  /// the media processor produces.
  static String _extensionFor(String url) {
    final ext = p.extension(Uri.tryParse(url)?.path ?? '');
    return RegExp(r'^\.[A-Za-z0-9]{1,5}$').hasMatch(ext) ? ext.toLowerCase() : '.mp3';
  }

  static String _readableError(Object e) {
    final text = e.toString();
    if (text.contains('SocketException') || text.contains('Failed host lookup')) {
      return 'No connection.';
    }
    if (text.contains('Server returned')) {
      return text.replaceFirst('Exception: ', '').replaceFirst('HttpException: ', '');
    }
    return 'Download failed.';
  }
}
