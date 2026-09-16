/// Data models mirroring the JSON shapes returned by /api/* (see api/*.php).
library;

/// One node of the church hierarchy (a province, zone, area, parish, or any
/// custom level the church has configured).
class UnitInfo {
  final int id;
  final int? parentId;
  final String type;
  final String name;
  final String slug;

  UnitInfo({required this.id, this.parentId, required this.type, required this.name, required this.slug});

  factory UnitInfo.fromJson(Map<String, dynamic> json) => UnitInfo(
        id: int.tryParse(json['id'].toString()) ?? 0,
        parentId: json['parent_id'] != null ? int.tryParse(json['parent_id'].toString()) : null,
        type: json['type'] as String? ?? '',
        name: json['name'] as String? ?? '',
        slug: json['slug'] as String? ?? '',
      );
}

/// One configured level of the hierarchy, root → leaf. The church can rename,
/// reorder, add and remove these on the website's Unit Levels screen, so every
/// label in the app is read from here rather than hard-coded.
class UnitLevel {
  final String type;
  final String label;
  final String plural;

  const UnitLevel({required this.type, required this.label, required this.plural});

  factory UnitLevel.fromJson(Map<String, dynamic> json) => UnitLevel(
        type: json['type'] as String? ?? '',
        label: json['label'] as String? ?? '',
        plural: json['plural'] as String? ?? '',
      );
}

/// The classic RCCG hierarchy — shown until /api/units reports the levels the
/// church has actually configured.
const List<UnitLevel> kFallbackUnitLevels = <UnitLevel>[
  UnitLevel(type: 'province', label: 'Province', plural: 'Provinces'),
  UnitLevel(type: 'zone', label: 'Zone', plural: 'Zones'),
  UnitLevel(type: 'area', label: 'Area', plural: 'Areas'),
  UnitLevel(type: 'parish', label: 'Parish', plural: 'Parishes'),
];

/// One home cell — a midweek gathering, from /api/cells.
///
/// [leaderPhone] is only ever populated when the church marked that number as
/// publishable. The API omits it otherwise, so nothing on this side has to
/// remember to hide it — there is simply nothing to hide.
class HomeCellInfo {
  final int id;
  final String name;
  final String? slug;
  final String pathLabel;
  final String? meetingDay;
  final String? meetingTime;
  final String? meetingAddress;
  final String? leaderName;
  final String? leaderPhone;
  final String? leaderPhoneDisplay;
  final int? capacity;

  HomeCellInfo({
    required this.id,
    required this.name,
    required this.pathLabel,
    this.slug,
    this.meetingDay,
    this.meetingTime,
    this.meetingAddress,
    this.leaderName,
    this.leaderPhone,
    this.leaderPhoneDisplay,
    this.capacity,
  });

  /// Ancestor names, root → this cell.
  ///
  /// Split from the label the API already sends rather than reassembled from
  /// the hierarchy, so the app shows the church's own level names without
  /// needing to know them.
  List<String> get pathParts => pathLabel
      .split(' · ')
      .map((part) => part.trim())
      .where((part) => part.isNotEmpty)
      .toList();

  /// "Tuesday, 6:30 PM", or just the day when no time was recorded.
  String? get whenLabel {
    final day = meetingDay;
    if (day == null || day.isEmpty) return null;
    final time = meetingTime;
    return time == null || time.isEmpty ? day : '$day, $time';
  }

  String? get capacityLabel {
    final seats = capacity;
    return seats == null || seats <= 0 ? null : 'Space for about $seats';
  }

  factory HomeCellInfo.fromJson(Map<String, dynamic> json) => HomeCellInfo(
        id: int.tryParse(json['id'].toString()) ?? 0,
        name: json['name'] as String? ?? '',
        slug: json['slug'] as String?,
        pathLabel: json['path_label'] as String? ?? '',
        meetingDay: json['meeting_day'] as String?,
        meetingTime: json['meeting_time'] as String?,
        meetingAddress: json['meeting_address'] as String?,
        leaderName: json['leader_name'] as String?,
        leaderPhone: json['leader_phone'] as String?,
        leaderPhoneDisplay: json['leader_phone_display'] as String?,
        capacity: json['capacity'] != null ? int.tryParse(json['capacity'].toString()) : null,
      );
}

class MediaItem {
  final String type; // 'image' | 'video'
  final String source; // 'upload' | 'youtube'
  final String? fileUrl;
  final String? thumbnailUrl;
  final String? altText;
  final String processingStatus;

  MediaItem({
    required this.type,
    this.source = 'upload',
    this.fileUrl,
    this.thumbnailUrl,
    this.altText,
    this.processingStatus = 'ready',
  });

  factory MediaItem.fromJson(Map<String, dynamic> json) => MediaItem(
        type: json['type'] as String? ?? 'image',
        source: json['source'] as String? ?? 'upload',
        fileUrl: json['file_url'] as String?,
        thumbnailUrl: json['thumbnail_url'] as String?,
        altText: json['alt_text'] as String?,
        processingStatus: json['processing_status'] as String? ?? 'ready',
      );
}

class Category {
  final int id;
  final String name;
  final String slug;
  Category({required this.id, required this.name, required this.slug});
  factory Category.fromJson(Map<String, dynamic> json) =>
      Category(id: int.tryParse(json['id'].toString()) ?? 0, name: json['name'] as String? ?? '', slug: json['slug'] as String? ?? '');
}

class Post {
  final int id;
  final String? slug;
  final String? caption;
  final String postType;
  final int likesCount;
  final int viewsCount;
  int savesCount;
  int commentsCount;
  final String createdAt;
  final String authorName;
  final String authorUsername;
  final List<MediaItem> mediaItems;
  final List<Category> categories;
  final List<UnitInfo> unit;
  final String unitLabel;
  final bool isPinned;
  bool likedByViewer;
  bool savedByViewer;

  Post({
    required this.id,
    this.slug,
    this.caption,
    required this.postType,
    required this.likesCount,
    required this.viewsCount,
    this.savesCount = 0,
    this.commentsCount = 0,
    required this.createdAt,
    required this.authorName,
    this.authorUsername = '',
    required this.mediaItems,
    required this.categories,
    this.unit = const [],
    this.unitLabel = '',
    this.isPinned = false,
    required this.likedByViewer,
    this.savedByViewer = false,
  });

  factory Post.fromJson(Map<String, dynamic> json) => Post(
        id: json['id'] as int,
        slug: json['slug'] as String?,
        caption: json['caption'] as String?,
        postType: json['post_type'] as String? ?? 'single_image',
        likesCount: json['likes_count'] as int? ?? 0,
        viewsCount: json['views_count'] as int? ?? 0,
        savesCount: json['saves_count'] as int? ?? 0,
        commentsCount: json['comments_count'] as int? ?? 0,
        createdAt: json['created_at'] as String? ?? '',
        authorName: json['author_name'] as String? ?? '',
        authorUsername: json['author_username'] as String? ?? '',
        mediaItems: (json['media_items'] as List<dynamic>? ?? [])
            .map((e) => MediaItem.fromJson(e as Map<String, dynamic>))
            .toList(),
        categories: (json['categories'] as List<dynamic>? ?? [])
            .map((e) => Category.fromJson(e as Map<String, dynamic>))
            .toList(),
        unit: (json['unit'] as List<dynamic>? ?? [])
            .map((e) => UnitInfo.fromJson(e as Map<String, dynamic>))
            .toList(),
        unitLabel: json['unit_label'] as String? ?? '',
        // is_pinned may arrive as a real bool, an int, or a string ('1'/'0')
        // depending on how the server serialises TINYINT — accept all three
        // so a type mismatch can never blank the feed.
        isPinned: json['is_pinned'] == true || json['is_pinned'] == 1 || json['is_pinned'] == '1',
        likedByViewer: json['liked_by_viewer'] as bool? ?? false,
        savedByViewer: json['saved_by_viewer'] as bool? ?? false,
      );
}

class ChurchEvent {
  final int id;
  final String title;
  final String slug;
  final String? description;
  final String? coverImageUrl;
  final String startAt;
  final String? endAt;
  final String? location;
  final bool rsvpEnabled;
  final String? rsvpUrl;

  ChurchEvent({
    required this.id,
    required this.title,
    required this.slug,
    this.description,
    this.coverImageUrl,
    required this.startAt,
    this.endAt,
    this.location,
    required this.rsvpEnabled,
    this.rsvpUrl,
  });

  factory ChurchEvent.fromJson(Map<String, dynamic> json) => ChurchEvent(
        id: json['id'] as int,
        title: json['title'] as String,
        slug: json['slug'] as String,
        description: json['description'] as String?,
        coverImageUrl: json['cover_image_url'] as String?,
        startAt: json['start_at'] as String,
        endAt: json['end_at'] as String?,
        location: json['location'] as String?,
        rsvpEnabled: json['rsvp_enabled'] as bool? ?? false,
        rsvpUrl: json['rsvp_url'] as String?,
      );
}

class Sermon {
  final int id;
  final String title;
  final String slug;
  final String? speaker;
  final String? series;
  final int? seriesId;
  final String? seriesSlug;
  final int? seriesPosition;
  final int? durationSeconds;
  final bool isExplicit;
  final String? scriptureRef;
  final String? description;
  final String? audioUrl;
  final String? videoEmbedUrl;
  final String? coverImageUrl;
  final String publishedAt;

  Sermon({
    required this.id,
    required this.title,
    required this.slug,
    this.speaker,
    this.series,
    this.seriesId,
    this.seriesSlug,
    this.seriesPosition,
    this.durationSeconds,
    this.isExplicit = false,
    this.scriptureRef,
    this.description,
    this.audioUrl,
    this.videoEmbedUrl,
    this.coverImageUrl,
    required this.publishedAt,
  });

  /// True when the sermon belongs to a real series rather than carrying an old free-text name.
  bool get isInSeries => seriesId != null;

  /// "42 min", or null when the duration was never recorded.
  String? get durationLabel {
    final seconds = durationSeconds;
    if (seconds == null || seconds <= 0) return null;
    if (seconds < 60) return '$seconds sec';
    final minutes = seconds ~/ 60;
    if (minutes < 60) return '$minutes min';
    final hours = minutes ~/ 60;
    final remainder = minutes % 60;
    return remainder == 0 ? '$hours h' : '$hours h $remainder min';
  }

  factory Sermon.fromJson(Map<String, dynamic> json) => Sermon(
        id: json['id'] as int,
        title: json['title'] as String,
        slug: json['slug'] as String,
        speaker: json['speaker'] as String?,
        series: json['series'] as String?,
        seriesId: json['series_id'] as int?,
        seriesSlug: json['series_slug'] as String?,
        seriesPosition: json['series_position'] as int?,
        durationSeconds: json['duration_seconds'] as int?,
        isExplicit: json['is_explicit'] as bool? ?? false,
        scriptureRef: json['scripture_ref'] as String?,
        description: json['description'] as String?,
        audioUrl: json['audio_url'] as String?,
        videoEmbedUrl: json['video_embed_url'] as String?,
        coverImageUrl: json['cover_image_url'] as String?,
        publishedAt: json['published_at'] as String,
      );
}

/// A named run of sermons. The app shows these as a list, and each one opens its episodes.
class SermonSeries {
  final int id;
  final String title;
  final String slug;
  final String? description;
  final String? coverImageUrl;
  final int sermonCount;
  final String? latestAt;

  SermonSeries({
    required this.id,
    required this.title,
    required this.slug,
    this.description,
    this.coverImageUrl,
    this.sermonCount = 0,
    this.latestAt,
  });

  factory SermonSeries.fromJson(Map<String, dynamic> json) => SermonSeries(
        id: json['id'] as int,
        title: json['title'] as String,
        slug: json['slug'] as String,
        description: json['description'] as String?,
        coverImageUrl: json['cover_image_url'] as String?,
        sermonCount: json['sermon_count'] as int? ?? 0,
        latestAt: json['latest_at'] as String?,
      );
}

class ServiceTime {
  final String label;
  final String time;
  ServiceTime({required this.label, required this.time});
  factory ServiceTime.fromJson(Map<String, dynamic> json) =>
      ServiceTime(label: json['label'] as String? ?? '', time: json['time'] as String? ?? '');
}

class ChurchSettings {
  final String siteTitle;
  final String? siteTagline;
  final String? heroTagline;
  final String? heroScripture;
  final String? logoUrl;
  final String? contactEmail;
  final String? contactPhone;
  final String? address;
  final List<ServiceTime> serviceTimes;
  final Map<String, String?> social;
  final String? livestreamEmbedUrl;
  final bool livestreamIsLive;
  final String? givingUrl;

  ChurchSettings({
    required this.siteTitle,
    this.siteTagline,
    this.heroTagline,
    this.heroScripture,
    this.logoUrl,
    this.contactEmail,
    this.contactPhone,
    this.address,
    required this.serviceTimes,
    required this.social,
    this.livestreamEmbedUrl,
    required this.livestreamIsLive,
    this.givingUrl,
  });

  factory ChurchSettings.fromJson(Map<String, dynamic> json) => ChurchSettings(
        siteTitle: json['site_title'] as String? ?? 'Church',
        siteTagline: json['site_tagline'] as String?,
        heroTagline: json['hero_tagline'] as String?,
        heroScripture: json['hero_scripture'] as String?,
        logoUrl: json['logo_url'] as String?,
        contactEmail: json['contact_email'] as String?,
        contactPhone: json['contact_phone'] as String?,
        address: json['address'] as String?,
        serviceTimes: (json['service_times'] as List<dynamic>? ?? [])
            .map((e) => ServiceTime.fromJson(e as Map<String, dynamic>))
            .toList(),
        social: Map<String, String?>.from(json['social'] as Map<String, dynamic>? ?? {}),
        livestreamEmbedUrl: (json['livestream'] as Map<String, dynamic>?)?['embed_url'] as String?,
        livestreamIsLive: (json['livestream'] as Map<String, dynamic>?)?['is_live'] as bool? ?? false,
        givingUrl: json['giving_url'] as String?,
      );
}

class TeamMember {
  final int id;
  final String name;
  final String? roleTitle;
  final String? photoUrl;
  final String? bio;
  TeamMember({required this.id, required this.name, this.roleTitle, this.photoUrl, this.bio});
  factory TeamMember.fromJson(Map<String, dynamic> json) => TeamMember(
        id: json['id'] as int,
        name: json['name'] as String,
        roleTitle: json['role_title'] as String?,
        photoUrl: json['photo_url'] as String?,
        bio: json['bio'] as String?,
      );
}

class PrayerRequest {
  final int id;
  final String? name;
  final String message;
  final String createdAt;
  PrayerRequest({required this.id, this.name, required this.message, required this.createdAt});
  factory PrayerRequest.fromJson(Map<String, dynamic> json) => PrayerRequest(
        id: int.tryParse(json['id'].toString()) ?? 0,
        name: json['name'] as String?,
        message: json['message'] as String? ?? '',
        createdAt: json['created_at'] as String? ?? '',
      );
}

/// One day's devotional.
///
/// A single class covers both the full entry and the archive list — the list omits the body and
/// the scripture text, and those simply arrive empty.
class Devotional {
  final int id;
  final String title;
  final String scriptureReference;
  final String scriptureText;
  final String body;
  final String publishOn;
  final int unitId;
  final String? audioUrl;
  final int? sermonId;

  const Devotional({
    required this.id,
    required this.title,
    required this.scriptureReference,
    required this.scriptureText,
    required this.body,
    required this.publishOn,
    required this.unitId,
    this.audioUrl,
    this.sermonId,
  });

  factory Devotional.fromJson(Map<String, dynamic> json) => Devotional(
        id: (json['id'] as num?)?.toInt() ?? 0,
        title: json['title'] as String? ?? '',
        scriptureReference: json['scripture_reference'] as String? ?? '',
        scriptureText: json['scripture_text'] as String? ?? '',
        body: json['body'] as String? ?? '',
        publishOn: json['publish_on'] as String? ?? '',
        unitId: (json['unit_id'] as num?)?.toInt() ?? 0,
        audioUrl: (json['audio_url'] as String? ?? '').isEmpty ? null : json['audio_url'] as String,
        sermonId: (json['sermon_id'] as num?)?.toInt(),
      );

  bool get hasAudio => audioUrl != null && audioUrl!.isNotEmpty;
  bool get hasScriptureText => scriptureText.trim().isNotEmpty;
  bool get hasBody => body.trim().isNotEmpty;
}

/// Today's devotional and the days around it, as /api/devotional answers.
///
/// [entry] is null when nothing has been published for [date]. That is a normal answer rather
/// than a failure, so screens show their empty state instead of an error.
class DevotionalDay {
  final String date;
  final bool isToday;
  final Devotional? entry;
  final List<Devotional> recent;

  const DevotionalDay({required this.date, required this.isToday, this.entry, this.recent = const []});

  factory DevotionalDay.fromJson(Map<String, dynamic> json) {
    final entry = json['data'] as Map<String, dynamic>?;
    return DevotionalDay(
      date: json['date'] as String? ?? '',
      isToday: json['is_today'] as bool? ?? false,
      entry: entry != null ? Devotional.fromJson(entry) : null,
      recent: (json['recent'] as List<dynamic>? ?? [])
          .map((e) => Devotional.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}
