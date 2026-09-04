import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:video_player/video_player.dart';
import 'package:youtube_player_iframe/youtube_player_iframe.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';

/// A parish/area/zone/province media gallery: all images + videos beneath the
/// unit (roll-up), shown in a mixed grid with a shuffle toggle.
class UnitScreen extends StatefulWidget {
  final String unitSlug;
  final String unitName;
  final List<String> unitPath; // [province, zone, area, parish] names
  const UnitScreen({super.key, required this.unitSlug, required this.unitName, required this.unitPath});

  @override
  State<UnitScreen> createState() => _UnitScreenState();
}

class _UnitScreenState extends State<UnitScreen> {
  final _api = ApiClient();
  List<Post> _posts = [];
  bool _loading = true;
  bool _shuffle = true;
  String _error = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final result = await _api.fetchUnitPosts(widget.unitSlug, shuffle: _shuffle);
      if (mounted) {
        setState(() {
          _posts = result.posts;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'Could not load media.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(widget.unitName, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold)),
          if (widget.unitPath.isNotEmpty)
            Text(
              widget.unitPath.join(' · '),
              style: const TextStyle(fontSize: 11, color: AppColors.inkFaint),
              overflow: TextOverflow.ellipsis,
            ),
        ]),
      ),
      body: Column(children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 4),
          child: Row(children: [
            Text('${_posts.length} items', style: const TextStyle(color: AppColors.inkFaint, fontSize: 12.5)),
            const Spacer(),
            TextButton.icon(
              onPressed: () {
                setState(() => _shuffle = !_shuffle);
                _load();
              },
              icon: const Icon(Icons.shuffle, size: 18),
              label: Text(_shuffle ? 'Shuffle: On' : 'Shuffle: Off'),
            ),
          ]),
        ),
        Expanded(
          child: _loading
              ? const Center(child: CircularProgressIndicator())
              : _error.isNotEmpty
                  ? Center(child: Text(_error))
                  : _posts.isEmpty
                      ? const Center(child: Text('No media in this unit yet.'))
                      : GridView.builder(
                          padding: const EdgeInsets.all(12),
                          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                            crossAxisCount: 3,
                            mainAxisSpacing: 8,
                            crossAxisSpacing: 8,
                            childAspectRatio: 9 / 16,
                          ),
                          itemCount: _posts.length,
                          itemBuilder: (context, i) => _tile(_posts[i]),
                        ),
        ),
      ]),
    );
  }

  Widget _tile(Post post) {
    final media = post.mediaItems.isNotEmpty ? post.mediaItems.first : null;
    final isVideo = media?.type == 'video';
    final thumb = media == null ? null : (isVideo ? (media.thumbnailUrl ?? media.fileUrl) : media.fileUrl);
    return GestureDetector(
      onTap: () => _openMedia(post),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(10),
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (thumb != null && thumb.isNotEmpty)
              CachedNetworkImage(imageUrl: thumb, fit: BoxFit.cover, placeholder: (_, __) => Container(color: AppColors.bg2))
            else
              Container(color: AppColors.bg2, child: const Icon(Icons.music_note, color: AppColors.goldSoft)),
            if (isVideo) Container(color: Colors.black26, child: const Icon(Icons.play_circle_fill, color: Colors.white, size: 32)),
          ],
        ),
      ),
    );
  }

  Future<void> _openMedia(Post post) async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => _MediaViewer(post: post)));
  }
}

/// Simple full-screen viewer for a single post's first media item.
class _MediaViewer extends StatefulWidget {
  final Post post;
  const _MediaViewer({required this.post});

  @override
  State<_MediaViewer> createState() => _MediaViewerState();
}

class _MediaViewerState extends State<_MediaViewer> {
  VideoPlayerController? _videoController;
  YoutubePlayerController? _youtubeController;

  MediaItem? get _media => widget.post.mediaItems.isEmpty ? null : widget.post.mediaItems.first;

  @override
  void initState() {
    super.initState();
    final media = _media;
    if (media == null) return;
    if (media.type == 'video' && media.source == 'youtube') {
      final id = RegExp(r'/embed/([a-zA-Z0-9_-]+)').firstMatch(media.fileUrl ?? '')?.group(1) ?? '';
      if (id.isNotEmpty) {
        _youtubeController = YoutubePlayerController.fromVideoId(
          videoId: id,
          autoPlay: true,
          params: const YoutubePlayerParams(mute: false, showControls: true),
        );
      }
    } else if (media.type == 'video' && media.fileUrl != null && media.fileUrl!.isNotEmpty) {
      _videoController = VideoPlayerController.networkUrl(Uri.parse(media.fileUrl!));
      _videoController!.setLooping(true);
      _videoController!.initialize().then((_) {
        if (mounted) setState(() {});
      });
      _videoController!.play();
    }
  }

  @override
  void dispose() {
    _videoController?.dispose();
    _youtubeController?.close();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final media = _media;
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(backgroundColor: Colors.black, title: Text(widget.post.caption ?? 'Media')),
      body: Center(child: _buildBody(media)),
    );
  }

  Widget _buildBody(MediaItem? media) {
    if (media == null) return const SizedBox.shrink();
    if (media.type == 'image') {
      return InteractiveViewer(
        maxScale: 4,
        child: CachedNetworkImage(imageUrl: media.fileUrl ?? '', fit: BoxFit.contain),
      );
    }
    if (media.type == 'video' && media.source == 'youtube' && _youtubeController != null) {
      return YoutubePlayer(controller: _youtubeController!, aspectRatio: 16 / 9);
    }
    if (media.type == 'video' && _videoController != null && _videoController!.value.isInitialized) {
      return FittedBox(
        fit: BoxFit.contain,
        child: SizedBox(
          width: _videoController!.value.size.width,
          height: _videoController!.value.size.height,
          child: VideoPlayer(_videoController!),
        ),
      );
    }
    return const Center(child: CircularProgressIndicator());
  }
}
