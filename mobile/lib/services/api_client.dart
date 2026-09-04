import 'dart:convert';
import 'package:http/http.dart' as http;
import '../models/models.dart';

/// Talks to the church's REST API (api/*.php on the PHP backend). No auth —
/// every endpoint here is public-read or anonymous-write, matching the
/// "front-end only, no login" requirement for this app.
///
/// Override the host per build:
///   flutter run --dart-define=API_BASE_URL=https://yourchurch.org
/// Defaults to the production server — swap via --dart-define for local
/// development (e.g. Android emulator uses http://10.0.2.2:8080).
class ApiClient {
  static const String _configuredBase = String.fromEnvironment('API_BASE_URL', defaultValue: '');
  static String get baseUrl => _configuredBase.isNotEmpty ? _configuredBase : 'https://rccglp63yaya.org.ng';

  Uri _uri(String path, [Map<String, dynamic>? query]) {
    final clean = query?.map((k, v) => MapEntry(k, v?.toString())) ?? {};
    clean.removeWhere((k, v) => v == null);
    return Uri.parse('$baseUrl$path').replace(queryParameters: clean.isEmpty ? null : clean);
  }

  Future<Map<String, dynamic>> _get(String path, [Map<String, dynamic>? query]) async {
    final res = await http.get(_uri(path, query));
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  Future<Map<String, dynamic>> _post(String path, Map<String, dynamic> body) async {
    final res = await http.post(_uri(path), headers: {'Content-Type': 'application/json'}, body: jsonEncode(body));
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  Future<ChurchSettings> fetchSettings() async {
    final json = await _get('/api/settings');
    return ChurchSettings.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<List<Category>> fetchCategories() async {
    final json = await _get('/api/categories');
    return (json['data'] as List<dynamic>? ?? []).map((e) => Category.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<({List<Post> posts, bool hasMore})> fetchFeed({int page = 1, String? category, String? unit, bool saved = false}) async {
    final json = await _get('/api/feed', {
      'page': page,
      'category': category,
      'unit': unit,
      if (saved) 'saved': '1',
    });
    final posts = (json['data'] as List<dynamic>? ?? []).map((e) => Post.fromJson(e as Map<String, dynamic>)).toList();
    return (posts: posts, hasMore: json['has_more'] as bool? ?? false);
  }

  /// The whole Province → Zone → Area → Parish hierarchy (flat, with labels).
  Future<List<UnitInfo>> fetchUnits() async {
    final json = await _get('/api/units');
    return (json['data'] as List<dynamic>? ?? []).map((e) => UnitInfo.fromJson(e as Map<String, dynamic>)).toList();
  }

  /// A unit's info + all media beneath it (roll-up), optionally shuffled.
  Future<({UnitInfo? unit, List<Post> posts})> fetchUnitPosts(String slug, {bool shuffle = true}) async {
    final json = await _get('/api/unit', {
      'slug': slug,
      if (shuffle) 'shuffle': '1',
      'per_page': '100',
    });
    final unitMap = json['unit'] as Map<String, dynamic>?;
    final posts = (json['data'] as List<dynamic>? ?? []).map((e) => Post.fromJson(e as Map<String, dynamic>)).toList();
    return (unit: unitMap != null ? UnitInfo.fromJson(unitMap) : null, posts: posts);
  }

  Future<void> pingView(int postId) => _get('/api/post', {'id': postId});

  Future<({bool liked, int likesCount})> toggleLike(int postId) async {
    final json = await _post('/api/like', {'post_id': postId});
    return (liked: json['liked'] as bool? ?? false, likesCount: json['likes_count'] as int? ?? 0);
  }

  Future<({bool saved, int savesCount})> toggleSave(int postId) async {
    final json = await _post('/api/save', {'post_id': postId});
    return (saved: json['saved'] as bool? ?? false, savesCount: json['saves_count'] as int? ?? 0);
  }

  Future<List<Map<String, dynamic>>> fetchComments(int postId) async {
    final json = await _get('/api/comments', {'post_id': postId});
    return (json['data'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
  }

  /// Post a comment or reply. If [imagePath] is given it is uploaded as
  /// multipart so the server can auto-compress it; otherwise a JSON body is used.
  Future<Map<String, dynamic>> postComment({
    required int postId,
    String? name,
    String? message,
    int? parentId,
    String? imagePath,
  }) async {
    if (imagePath != null) {
      final req = http.MultipartRequest('POST', _uri('/api/comments'));
      req.fields['post_id'] = postId.toString();
      if (name != null && name.isNotEmpty) req.fields['name'] = name;
      if (message != null && message.isNotEmpty) req.fields['message'] = message;
      if (parentId != null) req.fields['parent_id'] = parentId.toString();
      req.files.add(await http.MultipartFile.fromPath('image', imagePath));
      final streamed = await req.send();
      final res = await http.Response.fromStream(streamed);
      final json = jsonDecode(res.body) as Map<String, dynamic>;
      return (json['data'] as Map<String, dynamic>?) ?? {};
    }
    final json = await _post('/api/comments', {
      'post_id': postId,
      'name': name,
      'message': message,
      if (parentId != null) 'parent_id': parentId,
    });
    return (json['data'] as Map<String, dynamic>?) ?? {};
  }

  Future<({bool liked, int likesCount})> toggleCommentLike(int commentId) async {
    final json = await _post('/api/comments', {'action': 'like', 'comment_id': commentId});
    return (liked: json['liked'] as bool? ?? false, likesCount: json['likes_count'] as int? ?? 0);
  }

  /// Unified recent activity (new reels, events, sermons) for the
  /// notifications center. Public + anonymous.
  Future<List<Map<String, dynamic>>> fetchActivity() async {
    final json = await _get('/api/activity');
    return (json['data'] as List<dynamic>? ?? []).cast<Map<String, dynamic>>();
  }

  /// Registers (or updates) this device's push token with the backend.
  Future<void> registerDevice({required String token, String? platform, String? unitSlug}) async {
    try {
      await _post('/api/devices', {
        'token': token,
        'platform': platform,
        'unit_slug': unitSlug,
      });
    } catch (_) {
      // Registration is best-effort; never block the app on it.
    }
  }

  Future<({List<ChurchEvent> events, bool hasMore})> fetchEvents({String scope = 'upcoming', int page = 1}) async {
    final json = await _get('/api/events', {'scope': scope, 'page': page});
    final events = (json['data'] as List<dynamic>? ?? []).map((e) => ChurchEvent.fromJson(e as Map<String, dynamic>)).toList();
    return (events: events, hasMore: json['has_more'] as bool? ?? false);
  }

  Future<ChurchEvent?> fetchEvent(String slug) async {
    final json = await _get('/api/events', {'slug': slug});
    if (json['status'] != 'success') return null;
    return ChurchEvent.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<Sermon?> fetchSermon(String slug) async {
    final json = await _get('/api/sermons', {'slug': slug});
    if (json['status'] != 'success') return null;
    return Sermon.fromJson(json['data'] as Map<String, dynamic>);
  }

  Future<({List<Sermon> sermons, bool hasMore})> fetchSermons({int page = 1, String? series}) async {
    final json = await _get('/api/sermons', {'page': page, 'series': series});
    final sermons = (json['data'] as List<dynamic>? ?? []).map((e) => Sermon.fromJson(e as Map<String, dynamic>)).toList();
    return (sermons: sermons, hasMore: json['has_more'] as bool? ?? false);
  }

  Future<Map<String, List<dynamic>>> search(String query) async {
    final json = await _get('/api/search', {'q': query});
    final data = json['data'] as Map<String, dynamic>? ?? {};
    return data.map((k, v) => MapEntry(k, v as List<dynamic>));
  }

  Future<List<TeamMember>> fetchTeam() async {
    final json = await _get('/api/team');
    return (json['data'] as List<dynamic>? ?? []).map((e) => TeamMember.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<List<PrayerRequest>> fetchPublicPrayers() async {
    final json = await _get('/api/prayer');
    return (json['data'] as List<dynamic>? ?? []).map((e) => PrayerRequest.fromJson(e as Map<String, dynamic>)).toList();
  }

  Future<String> submitPrayer({String? name, String? email, required String message, bool isPublic = false}) async {
    final json = await _post('/api/prayer', {'name': name, 'email': email, 'message': message, 'is_public': isPublic});
    return json['message'] as String? ?? '';
  }

  Future<String> subscribeNewsletter(String email) async {
    final json = await _post('/api/newsletter', {'email': email});
    return json['message'] as String? ?? '';
  }

  Future<String> sendContactMessage({required String name, required String email, String? subject, required String message}) async {
    final json = await _post('/api/contact', {'name': name, 'email': email, 'subject': subject, 'message': message});
    return json['message'] as String? ?? '';
  }

  /// Fetches scripture from the Bible page API. Returns a normalized map with
  /// `reference`, `translation`, `verses` (list of {verse, text}), and `copyright`.
  Future<Map<String, dynamic>> fetchBible({
    required String book,
    required int chapter,
    String version = 'KJV',
    String lang = 'en',
    String? verse,
  }) async {
    return _get('/api/bible.php', {
      'book': book,
      'chapter': chapter,
      'version': version,
      'lang': lang,
      if (verse != null) 'verse': verse,
    });
  }
}
