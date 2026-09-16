import 'dart:async';
import 'dart:io';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';
import 'package:visibility_detector/visibility_detector.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../services/share_service.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'unit_screen.dart';
import 'units_screen.dart';

/// Vertical, full-screen, infinite-scrolling feed — behaves like Instagram
/// Reels: swipe up for the next post, tap a video to mute/unmute, double-tap
/// to like, and use the right-hand rail for like/comment/share/save.
/// Mirrors views/feed.php + assets/js/feed.js.
class FeedScreen extends StatefulWidget {
  const FeedScreen({super.key});
  @override
  State<FeedScreen> createState() => FeedScreenState();
}

class FeedScreenState extends State<FeedScreen> {
  final _api = ApiClient();
  final _pageController = PageController();
  final List<Post> _posts = [];
  List<Category> _categories = [];
  String? _activeCategory;
  bool _savedOnly = false;
  int _page = 1;
  bool _hasMore = true;
  bool _loading = false;
  final Set<int> _viewedIds = {};
  int _newestId = 0;
  bool _showNewPosts = false;
  Timer? _feedPollTimer;

  @override
  void initState() {
    super.initState();
    _api.fetchCategories().then((c) => setState(() => _categories = c));
    _loadMore();
    // Live "new posts" check while the feed is on screen.
    _feedPollTimer = Timer.periodic(const Duration(seconds: 30), (_) => _checkNewPosts());
  }

  @override
  void dispose() {
    _feedPollTimer?.cancel();
    _pageController.dispose();
    super.dispose();
  }

  void _trackNewest(List<Post> posts) {
    for (final p in posts) {
      if (p.id > _newestId) _newestId = p.id;
    }
  }

  Future<void> _checkNewPosts() async {
    if (_savedOnly || _posts.isEmpty || _loading) return;
    try {
      final result = await _api.fetchFeed(page: 1, category: _activeCategory);
      if (!mounted || result.posts.isEmpty) return;
      if (result.posts.first.id > _newestId) setState(() => _showNewPosts = true);
    } catch (_) {}
  }

  Future<void> _resetFeed() async {
    setState(() {
      _showNewPosts = false;
      _newestId = 0;
      _posts.clear();
      _page = 1;
      _hasMore = true;
      _viewedIds.clear();
    });
    _pageController.jumpToPage(0);
    await _loadMore();
  }

  /// Re-runs the initial load (categories + first feed page). Called by the
  /// shell when the Feed tab is (re)tapped, and by pull-to-refresh/retry, so a
  /// silently failed first load recovers without needing an app restart.
  Future<void> refresh() async {
    if (_loading) return;
    _api.fetchCategories().then((c) => setState(() => _categories = c));
    await _loadMore();
  }

  Future<void> _loadMore() async {
    if (_loading || !_hasMore) return;
    setState(() => _loading = true);
    try {
      final result = await _api.fetchFeed(page: _page, category: _activeCategory, saved: _savedOnly);
      setState(() {
        _posts.addAll(result.posts);
        _hasMore = result.hasMore;
        _page++;
      });
      _trackNewest(result.posts);
    } catch (_) {
      // Network hiccup — silently allow a retry on the next scroll.
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  void _selectCategory(String? slug) {
    setState(() {
      _activeCategory = slug;
      _posts.clear();
      _page = 1;
      _hasMore = true;
      _viewedIds.clear();
      _newestId = 0;
      _showNewPosts = false;
    });
    _pageController.jumpToPage(0);
    _loadMore();
  }

  void _setSavedOnly(bool saved) {
    setState(() {
      _savedOnly = saved;
      _posts.clear();
      _page = 1;
      _hasMore = true;
      _viewedIds.clear();
      _newestId = 0;
      _showNewPosts = false;
    });
    _pageController.jumpToPage(0);
    _loadMore();
  }

  void _onPageChanged(int index) {
    if (index >= _posts.length - 2) _loadMore();
    if (index < _posts.length) {
      final post = _posts[index];
      if (_viewedIds.add(post.id)) _api.pingView(post.id);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        bottom: false,
        child: Column(
          children: [
            _buildTopBar(),
            if (_categories.isNotEmpty) _buildChips(),
            Expanded(
              child: Stack(
                children: [
                  _posts.isEmpty
                      ? (_loading
                          ? const LoadingView()
                          : RefreshIndicator(
                              onRefresh: refresh,
                              color: AppColors.gold,
                              child: ListView(
                                physics: const AlwaysScrollableScrollPhysics(),
                                children: [
                                  const SizedBox(height: 80),
                                  const EmptyState(message: 'No reels in the feed yet.'),
                                  const SizedBox(height: 16),
                                  Center(
                                    child: OutlinedButton.icon(
                                      onPressed: refresh,
                                      icon: const Icon(Icons.refresh),
                                      label: const Text('Retry'),
                                    ),
                                  ),
                                ],
                              ),
                            ))
                      : PageView.builder(
                          controller: _pageController,
                          scrollDirection: Axis.vertical,
                          itemCount: _posts.length,
                          onPageChanged: _onPageChanged,
                          itemBuilder: (context, index) => _FeedSlide(
                            post: _posts[index],
                            api: _api,
                            onLikeChanged: (liked, count) => setState(() {
                              _posts[index].likedByViewer = liked;
                            }),
                            onCommentAdded: (count) => setState(() {
                              _posts[index].commentsCount = count;
                            }),
                          ),
                        ),
                  if (_showNewPosts)
                    Positioned(
                      top: 8,
                      left: 0,
                      right: 0,
                      child: Center(
                        child: GestureDetector(
                          onTap: _resetFeed,
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 10),
                            decoration: BoxDecoration(
                              gradient: const LinearGradient(colors: [Color(0xFFF7C46A), Color(0xFFD99B2B)]),
                              borderRadius: BorderRadius.circular(999),
                              boxShadow: const [BoxShadow(color: Colors.black54, blurRadius: 18, offset: Offset(0, 6))],
                            ),
                            child: const Text('⬆ New posts — tap to refresh', style: TextStyle(color: Color(0xFF1A1530), fontWeight: FontWeight.w800, fontSize: 13)),
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildTopBar() {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
      decoration: const BoxDecoration(color: Colors.black, border: Border(bottom: BorderSide(color: Color(0xFF1F1F1F)))),
      child: Row(
        children: [
          const Text('Reels', style: TextStyle(fontWeight: FontWeight.w800, fontSize: 18, color: Colors.white)),
          const Spacer(),
          _toggle('For You', !_savedOnly, () => _setSavedOnly(false)),
          const SizedBox(width: 16),
          _toggle('Saved', _savedOnly, () => _setSavedOnly(true)),
          const SizedBox(width: 8),
          IconButton(
            icon: const Icon(Icons.location_on_outlined, color: Colors.white),
            tooltip: 'Find your parish',
            onPressed: () {
              Navigator.push(context, MaterialPageRoute(builder: (_) => const UnitsScreen()));
            },
          ),
        ],
      ),
    );
  }

  Widget _toggle(String label, bool active, VoidCallback onTap) {
    return GestureDetector(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
        decoration: BoxDecoration(
          color: active ? Colors.white : Colors.transparent,
          borderRadius: BorderRadius.circular(999),
        ),
        child: Text(
          label,
          style: TextStyle(
            color: active ? Colors.black : AppColors.inkDim,
            fontSize: 12.5,
            fontWeight: FontWeight.w700,
          ),
        ),
      ),
    );
  }

  Widget _buildChips() {
    return SizedBox(
      height: 46,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 6),
        children: [
          _chip('All', null),
          for (final c in _categories) _chip(c.name, c.slug),
        ],
      ),
    );
  }

  Widget _chip(String label, String? slug) {
    final active = _activeCategory == slug;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 4),
      child: ChoiceChip(
        label: Text(label),
        selected: active,
        onSelected: (_) => _selectCategory(slug),
        backgroundColor: Colors.white.withValues(alpha: 0.08),
        selectedColor: AppColors.gold.withValues(alpha: 0.22),
        labelStyle: TextStyle(color: active ? AppColors.goldSoft : AppColors.inkDim, fontSize: 12.5),
        side: BorderSide(color: active ? AppColors.gold.withValues(alpha: 0.4) : Colors.transparent),
      ),
    );
  }
}

class _FeedSlide extends StatefulWidget {
  final Post post;
  final ApiClient api;
  final void Function(bool liked, int count) onLikeChanged;
  final void Function(int count) onCommentAdded;
  const _FeedSlide({required this.post, required this.api, required this.onLikeChanged, required this.onCommentAdded});

  @override
  State<_FeedSlide> createState() => _FeedSlideState();
}

class _FeedSlideState extends State<_FeedSlide> {
  VideoPlayerController? _videoController;
  int _mediaIndex = 0;
  bool _muted = false; // default sound ON — videos are not muted by default
  bool _liking = false;
  bool _saving = false;
  bool _burst = false;
  bool _likePop = false;

  MediaItem? get _activeMedia => widget.post.mediaItems.isEmpty ? null : widget.post.mediaItems[_mediaIndex];

  @override
  void dispose() {
    _videoController?.dispose();
    super.dispose();
  }

  void _setupVideoIfNeeded() {
    final media = _activeMedia;
    if (media == null || media.type != 'video' || media.fileUrl == null) return;
    if (_videoController != null) return;
    // Uploaded videos autoplay WITH sound — ExoPlayer has no browser-style
    // autoplay policy, so there's no reason to start them muted. This default
    // is applied only on first setup so the user's mute-button/tap choice is
    // preserved afterwards.
    if (_muted) _muted = false;
    final controller = VideoPlayerController.networkUrl(Uri.parse(media.fileUrl!));
    _videoController = controller;
    controller.setLooping(true);
    controller.setVolume(_muted ? 0 : 1);
    controller.initialize().then((_) {
      // Reinforce sound after init in case a device resets the volume to 0
      // (some ExoPlayer builds start silent). Respects the mute toggle.
      if (mounted) _videoController?.setVolume(_muted ? 0 : 1);
      if (mounted) setState(() {});
    });
  }

  /// Extracts a YouTube video id from an embed URL (used to build the watch
  /// link for existing YouTube posts, which open in the YouTube app/browser).
  String _youtubeId(String url) =>
      RegExp(r'/embed/([a-zA-Z0-9_-]+)').firstMatch(url)?.group(1) ?? url.trim();

  void _onVisibilityChanged(VisibilityInfo info) {
    final visible = info.visibleFraction > 0.6;
    final vc = _videoController;
    if (vc != null) {
      if (visible) {
        vc.play();
      } else {
        vc.pause();
      }
    }
  }

  void _toggleMute() {
    setState(() => _muted = !_muted);
    _videoController?.setVolume(_muted ? 0 : 1);
  }

  Future<void> _toggleLike({bool doubleTap = false}) async {
    if (_liking) return;
    if (doubleTap && widget.post.likedByViewer) return;
    setState(() => _liking = true);
    try {
      final result = await widget.api.toggleLike(widget.post.id);
      widget.onLikeChanged(result.liked, result.likesCount);
      setState(() {
        widget.post.likedByViewer = result.liked;
      });
      if (result.liked) {
        _fireBurst();
        setState(() => _likePop = true);
        Future.delayed(const Duration(milliseconds: 320), () {
          if (mounted) setState(() => _likePop = false);
        });
      }
    } catch (_) {
    } finally {
      if (mounted) setState(() => _liking = false);
    }
  }

  void _fireBurst() {
    setState(() => _burst = true);
    Future.delayed(const Duration(milliseconds: 480), () {
      if (mounted) setState(() => _burst = false);
    });
  }

  Future<void> _toggleSave() async {
    if (_saving) return;
    setState(() => _saving = true);
    try {
      final result = await widget.api.toggleSave(widget.post.id);
      setState(() {
        widget.post.savedByViewer = result.saved;
        widget.post.savesCount = result.savesCount;
      });
    } catch (_) {
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  void _openComments() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _CommentSheet(
        postId: widget.post.id,
        api: widget.api,
        onAdded: (count) => widget.onCommentAdded(count),
      ),
    );
  }

  String _formatCount(int n) {
    if (n >= 1000000) return '${(n / 1000000).toStringAsFixed(1)}M';
    if (n >= 1000) return '${(n / 1000).toStringAsFixed(1)}K';
    return '$n';
  }

  @override
  Widget build(BuildContext context) {
    _setupVideoIfNeeded();
    final post = widget.post;

    return VisibilityDetector(
      key: Key('feed-slide-${post.id}'),
      onVisibilityChanged: _onVisibilityChanged,
      child: Stack(
        fit: StackFit.expand,
        children: [
          _buildMedia(),
          const DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topCenter,
                end: Alignment.bottomCenter,
                colors: [Color(0x66000000), Colors.transparent, Colors.transparent, Color(0xDD000000)],
                stops: [0, 0.25, 0.55, 1],
              ),
            ),
          ),
          Positioned(
            top: 12,
            left: 16,
            child: Row(
              children: [
                if (post.isPinned)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: _badge('📌 Pinned'),
                  ),
                _badge(post.postType == 'vertical_reel' ? 'Reel' : (post.postType == 'carousel' ? 'Carousel' : 'Photo')),
              ],
            ),
          ),
          Positioned(
            top: 12,
            right: 16,
            child: GestureDetector(
              onTap: _toggleMute,
              child: Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: Colors.black.withValues(alpha: 0.4),
                  shape: BoxShape.circle,
                  border: Border.all(color: Colors.white24),
                ),
                child: Icon(
                  _muted ? Icons.volume_off : Icons.volume_up,
                  color: Colors.white,
                  size: 20,
                ),
              ),
            ),
          ),
          Positioned(
            left: 16,
            right: 96,
            bottom: 20,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    _avatar(post.authorName),
                    const SizedBox(width: 8),
                    Flexible(
                      child: Text(
                        '@${post.authorUsername.isNotEmpty ? post.authorUsername : post.authorName.toLowerCase().replaceAll(' ', '.')}',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 13.5),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    const SizedBox(width: 4),
                    _verified(),
                  ],
                ),
                if (post.unit.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  GestureDetector(
                    onTap: () {
                      final p = post.unit.last;
                      Navigator.push(
                        context,
                        MaterialPageRoute(
                          builder: (_) => UnitScreen(
                            unitSlug: p.slug,
                            unitName: p.name,
                            unitPath: post.unit.map((u) => u.name).toList(),
                          ),
                        ),
                      );
                    },
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.location_on, color: AppColors.goldSoft, size: 14),
                        const SizedBox(width: 4),
                        Flexible(
                          child: Text(
                            post.unit.last.name,
                            style: const TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w700, fontSize: 13),
                            overflow: TextOverflow.ellipsis,
                          ),
                        ),
                      ],
                    ),
                  ),
                ],
                const SizedBox(height: 8),
                if (post.caption != null && post.caption!.isNotEmpty)
                  Text(post.caption!, style: const TextStyle(color: Colors.white, fontSize: 14), maxLines: 3, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 10),
                const Row(
                  children: [
                    Icon(Icons.music_note, color: AppColors.goldSoft, size: 15),
                    SizedBox(width: 5),
                    Text('Original audio', style: TextStyle(color: Colors.white, fontSize: 12)),
                  ],
                ),
              ],
            ),
          ),
          Positioned(
            right: 12,
            bottom: 90,
            child: Column(
              children: [
                _actionButton(
                  icon: post.likedByViewer ? Icons.favorite : Icons.favorite_border,
                  color: post.likedByViewer ? const Color(0xFFFF4D6D) : Colors.white,
                  label: _formatCount(post.likesCount),
                  onTap: () => _toggleLike(),
                  pop: _likePop,
                ),
                const SizedBox(height: 18),
                _actionButton(
                  icon: Icons.mode_comment_outlined,
                  label: _formatCount(post.commentsCount),
                  onTap: _openComments,
                ),
                const SizedBox(height: 18),
                _actionButton(
                  icon: Icons.ios_share,
                  label: '',
                  onTap: () => ShareService.share(
                    text: post.caption ?? 'Check this out',
                    uri: '${ApiClient.baseUrl}/feed',
                  ),
                  iconOnly: true,
                ),
                const SizedBox(height: 18),
                _actionButton(
                  icon: post.savedByViewer ? Icons.bookmark : Icons.bookmark_border,
                  color: post.savedByViewer ? AppColors.goldSoft : Colors.white,
                  label: '',
                  onTap: _toggleSave,
                  iconOnly: true,
                ),
              ],
            ),
          ),
          if (widget.post.mediaItems.length > 1) ...[
            Positioned.fill(
              child: Row(children: [
                Expanded(child: GestureDetector(onTap: _prevMedia, behavior: HitTestBehavior.translucent)),
                const Expanded(flex: 2, child: SizedBox()),
                Expanded(child: GestureDetector(onTap: _nextMedia, behavior: HitTestBehavior.translucent)),
              ]),
            ),
          ],
          AnimatedOpacity(
            opacity: _burst ? 1 : 0,
            duration: const Duration(milliseconds: 200),
            child: IgnorePointer(
              child: Center(
                child: AnimatedScale(
                  scale: _burst ? 1 : 0.3,
                  duration: const Duration(milliseconds: 300),
                  curve: Curves.easeOutBack,
                  child: const Icon(Icons.favorite, size: 96, color: Color(0xFFFF4D6D)),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _avatar(String name) {
    return Container(
      width: 34,
      height: 34,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        gradient: const LinearGradient(colors: [Color(0xFFF09433), Color(0xFFE6683C), Color(0xFFDC2743)]),
        border: Border.all(color: Colors.white, width: 2),
      ),
      alignment: Alignment.center,
      child: Text(
        _initial(name),
        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 13),
      ),
    );
  }

  Widget _verified() {
    return Container(
      width: 15,
      height: 15,
      decoration: const BoxDecoration(color: Color(0xFF3897F0), shape: BoxShape.circle),
      alignment: Alignment.center,
      child: const Icon(Icons.check, size: 10, color: Colors.white),
    );
  }

  void _prevMedia() => setState(() {
        _videoController?.dispose();
        _videoController = null;
        _mediaIndex = (_mediaIndex - 1 + widget.post.mediaItems.length) % widget.post.mediaItems.length;
      });

  void _nextMedia() => setState(() {
        _videoController?.dispose();
        _videoController = null;
        _mediaIndex = (_mediaIndex + 1) % widget.post.mediaItems.length;
      });

  Widget _buildMedia() {
    final media = _activeMedia;
    if (media == null) return Container(color: AppColors.bg2);

    if (media.type == 'video' && media.source == 'youtube') {
      final id = _youtubeId(media.fileUrl ?? '');
      // Existing YouTube reels render as a tappable thumbnail that opens the
      // video in the YouTube app/browser — no webview is ever mounted, so it
      // can never block the vertical swipe.
      return GestureDetector(
        onTap: () => launchUrl(
          Uri.parse('https://www.youtube.com/watch?v=$id'),
          mode: LaunchMode.externalApplication,
        ),
        onDoubleTap: () => _toggleLike(doubleTap: true),
        child: Container(
          color: Colors.black,
          child: Stack(
            fit: StackFit.expand,
            children: [
              if (media.thumbnailUrl != null)
                CachedNetworkImage(imageUrl: media.thumbnailUrl!, fit: BoxFit.cover, errorWidget: (_, __, ___) => Container(color: AppColors.bg2))
              else
                Container(color: AppColors.bg2),
              const Center(
                child: DecoratedBox(
                  decoration: BoxDecoration(color: Colors.black45, shape: BoxShape.circle),
                  child: Padding(
                    padding: EdgeInsets.all(14),
                    child: Icon(Icons.play_arrow, size: 44, color: Colors.white),
                  ),
                ),
              ),
              const Positioned(
                bottom: 12,
                left: 0,
                right: 0,
                child: Center(
                  child: Text(
                    '▶ Watch on YouTube',
                    style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w700, shadows: [Shadow(color: Colors.black, blurRadius: 6)]),
                  ),
                ),
              ),
            ],
          ),
        ),
      );
    }

    if (media.type == 'video') {
      final controller = _videoController;
      return GestureDetector(
        onTap: _toggleMute,
        onDoubleTap: () => _toggleLike(doubleTap: true),
        child: Container(
          color: Colors.black,
          child: controller != null && controller.value.isInitialized
              ? FittedBox(
                  fit: BoxFit.cover,
                  child: SizedBox(width: controller.value.size.width, height: controller.value.size.height, child: VideoPlayer(controller)),
                )
              : (media.thumbnailUrl != null ? CachedNetworkImage(imageUrl: media.thumbnailUrl!, fit: BoxFit.cover, width: double.infinity, height: double.infinity) : const LoadingView()),
        ),
      );
    }
    return GestureDetector(
      onDoubleTap: () => _toggleLike(doubleTap: true),
      child: CachedNetworkImage(
        imageUrl: media.fileUrl ?? '',
        fit: BoxFit.cover,
        width: double.infinity,
        height: double.infinity,
        placeholder: (_, __) => Container(color: AppColors.bg2),
        errorWidget: (_, __, ___) => Container(color: AppColors.bg2),
      ),
    );
  }

  Widget _badge(String text) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 5),
        decoration: BoxDecoration(color: Colors.black.withValues(alpha: 0.5), borderRadius: BorderRadius.circular(20)),
        child: Text(text, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700)),
      );

  Widget _actionButton({required IconData icon, Color color = Colors.white, required String label, VoidCallback? onTap, bool iconOnly = false, bool pop = false}) {
    return GestureDetector(
      onTap: onTap,
      child: Column(
        children: [
          AnimatedScale(
            scale: pop ? 1.4 : 1.0,
            duration: const Duration(milliseconds: 280),
            curve: Curves.easeOutBack,
            child: Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(color: Colors.white.withValues(alpha: 0.12), shape: BoxShape.circle),
              child: Icon(icon, color: color, size: 22),
            ),
          ),
          if (!iconOnly) ...[
            const SizedBox(height: 4),
            Text(label, style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w600)),
          ],
        ],
      ),
    );
  }
}

class _CommentSheet extends StatefulWidget {
  final int postId;
  final ApiClient api;
  final void Function(int count) onAdded;
  const _CommentSheet({required this.postId, required this.api, required this.onAdded});

  @override
  State<_CommentSheet> createState() => _CommentSheetState();
}

class _CommentSheetState extends State<_CommentSheet> {
  final _message = TextEditingController();
  final _name = TextEditingController();
  List<Map<String, dynamic>> _comments = [];
  bool _loading = true;
  bool _posting = false;
  bool _hasNewComments = false;
  int? _replyToId;
  String? _replyToName;
  XFile? _image;
  bool _emojiOpen = false;
  Timer? _pollTimer;
  Timer? _newHintTimer;

  static const _emojis = ['😂','😍','😊','🙏','❤️','🔥','👍','👏','🙌','😮','🥰','😢','😎','🤣','💯','🎉','✝️','💒','😇','🤗','😅','🥹','😴','🤔','✨','💖','🕊️','🎶'];

  @override
  void initState() {
    super.initState();
    _name.text = '';
    _load();
    // Real-time: light polling while the sheet is open.
    _pollTimer = Timer.periodic(const Duration(seconds: 5), (_) => _poll());
  }

  String _signature(List<Map<String, dynamic>> list) {
    return list.map((c) {
      final replies = ((c['replies'] as List<dynamic>?) ?? const [])
          .cast<Map<String, dynamic>>()
          .map((r) => '${r['id']}:${(r['message'] as String? ?? '').length}')
          .join(',');
      return '${c['id']}:${(c['message'] as String? ?? '').length}:${c['reply_count'] ?? 0}:$replies';
    }).join('|');
  }

  int _totalCount(List<Map<String, dynamic>> list) {
    return list.fold<int>(0, (acc, c) => acc + 1 + (((c['replies'] as List<dynamic>?) ?? const []).length));
  }

  Future<void> _poll() async {
    if (_loading) return;
    try {
      final list = await widget.api.fetchComments(widget.postId);
      if (!mounted) return;
      final sig = _signature(list);
      final current = _signature(_comments);
      if (sig == current) return;
      setState(() {
        _comments = list;
        _hasNewComments = true;
      });
      widget.onAdded(_totalCount(list));
      _newHintTimer?.cancel();
      _newHintTimer = Timer(const Duration(seconds: 3), () {
        if (mounted) setState(() => _hasNewComments = false);
      });
    } catch (_) {}
  }

  Future<void> _load() async {
    try {
      final list = await widget.api.fetchComments(widget.postId);
      if (mounted) {
        setState(() {
          _comments = list;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _commentImageUrl(String? path) {
    if (path == null || path.isEmpty) return '';
    if (path.startsWith('http')) return path;
    return '${ApiClient.baseUrl}/uploads/${path.replaceFirst(RegExp(r'^/+'), '')}';
  }

  Future<void> _submit() async {
    final message = _message.text.trim();
    if ((message.isEmpty && _image == null) || _posting) return;
    setState(() => _posting = true);
    try {
      await widget.api.postComment(
        postId: widget.postId,
        name: _name.text.trim().isEmpty ? null : _name.text.trim(),
        message: message,
        parentId: _replyToId,
        imagePath: _image?.path,
      );
      _message.clear();
      _image = null;
      _replyToId = null;
      _replyToName = null;
      await _load();
      widget.onAdded(_totalCount(_comments));
    } catch (_) {
    } finally {
      if (mounted) setState(() => _posting = false);
    }
  }

  Future<void> _toggleLike(int commentId) async {
    final res = await widget.api.toggleCommentLike(commentId);
    if (!mounted) return;
    setState(() {
      void update(Map<String, dynamic> c) {
        if ((c['id'] as int? ?? 0) == commentId) {
          c['liked'] = res.liked;
          c['likes_count'] = res.likesCount;
        }
      }

      for (final c in _comments) {
        update(c);
        final replies = (c['replies'] as List<dynamic>?) ?? const [];
        for (final r in replies.cast<Map<String, dynamic>>()) {
          update(r);
        }
      }
    });
  }

  Future<void> _pickImage() async {
    try {
      final picked = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85, maxWidth: 1400);
      if (picked != null) setState(() => _image = picked);
    } catch (_) {
      if (mounted) ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Could not open the photo library.')));
    }
  }

  void _insertEmoji(String emoji) {
    final start = _message.selection.start;
    final end = _message.selection.end;
    final text = _message.text;
    _message.value = TextEditingValue(
      text: text.replaceRange(start, end, emoji),
      selection: TextSelection.collapsed(offset: start + emoji.length),
    );
    setState(() => _emojiOpen = false);
  }

  Widget _commentTile(Map<String, dynamic> c, {required bool isReply}) {
    final name = (c['name'] as String?) ?? 'Anonymous';
    final message = (c['message'] as String?) ?? '';
    final created = (c['created_at'] as String?) ?? '';
    final imagePath = c['image_path'] as String?;
    final likes = (c['likes_count'] as int? ?? 0);
    final liked = (c['liked'] as bool? ?? false);
    final replies = (c['replies'] as List<dynamic>?)?.cast<Map<String, dynamic>>() ?? const [];

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Container(
            width: 30,
            height: 30,
            decoration: const BoxDecoration(color: Color(0xFF262626), shape: BoxShape.circle),
            alignment: Alignment.center,
            child: Text(_initial(name), style: const TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w700, fontSize: 12)),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  crossAxisAlignment: CrossAxisAlignment.baseline,
                  textBaseline: TextBaseline.alphabetic,
                  children: [
                    Flexible(child: Text(name, style: const TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w700, fontSize: 13), overflow: TextOverflow.ellipsis)),
                    const SizedBox(width: 6),
                    Text(created, style: const TextStyle(color: Colors.white38, fontSize: 11)),
                  ],
                ),
                if (message.isNotEmpty) Text(message, style: const TextStyle(color: Colors.white, fontSize: 13.5)),
                if (imagePath != null && imagePath.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(12),
                      child: GestureDetector(
                        onTap: () {
                          final url = _commentImageUrl(imagePath);
                          if (url.isNotEmpty) openImageLightbox(context, url);
                        },
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 200, maxHeight: 200),
                          child: CachedNetworkImage(imageUrl: _commentImageUrl(imagePath), fit: BoxFit.cover, placeholder: (_, __) => const SizedBox(width: 60, height: 60, child: Center(child: SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.gold)))), errorWidget: (_, __, ___) => const SizedBox(width: 60, height: 60)),
                        ),
                      ),
                    ),
                  ),
                Row(
                  children: [
                    GestureDetector(
                      onTap: () => _toggleLike(c['id'] as int? ?? 0),
                      child: Row(
                        children: [
                          Icon(liked ? Icons.favorite : Icons.favorite_border, size: 15, color: liked ? const Color(0xFFFF3B5C) : Colors.white54),
                          const SizedBox(width: 4),
                          Text(likes.toString(), style: const TextStyle(color: Colors.white54, fontSize: 12)),
                        ],
                      ),
                    ),
                    const SizedBox(width: 18),
                    GestureDetector(
                      onTap: () => setState(() {
                        _replyToId = c['id'] as int?;
                        _replyToName = name;
                      }),
                      child: const Text('Reply', style: TextStyle(color: Colors.white54, fontSize: 12)),
                    ),
                  ],
                ),
                if (!isReply && replies.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4, left: 14),
                    child: Column(
                      children: replies.map((r) => _commentTile(r, isReply: true)).toList(),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  @override
  void dispose() {
    _pollTimer?.cancel();
    _newHintTimer?.cancel();
    _message.dispose();
    _name.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).viewInsets.bottom;
    return Padding(
      padding: EdgeInsets.only(bottom: bottomInset),
      child: Container(
        height: MediaQuery.of(context).size.height * 0.62,
        decoration: const BoxDecoration(
          color: Color(0xFF181818),
          borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
        ),
        child: Column(
          children: [
            Padding(
              padding: const EdgeInsets.all(14),
              child: Row(
                children: [
                  const Text('Comments', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w700, fontSize: 15)),
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                    decoration: BoxDecoration(
                      color: const Color(0x26FF3B5C),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        SizedBox(width: 6, height: 6, child: DecoratedBox(decoration: BoxDecoration(color: Color(0xFFFF3B5C), shape: BoxShape.circle))),
                        SizedBox(width: 4),
                        Text('LIVE', style: TextStyle(color: Color(0xFFFF3B5C), fontSize: 9, fontWeight: FontWeight.w800, letterSpacing: 0.6)),
                      ],
                    ),
                  ),
                  const Spacer(),
                  GestureDetector(onTap: () => Navigator.pop(context), child: const Icon(Icons.close, color: Colors.white70, size: 20)),
                ],
              ),
            ),
            const Divider(height: 1, color: Color(0xFF2A2A2A)),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator(color: AppColors.gold))
                  : Column(
                      children: [
                        if (_hasNewComments)
                          GestureDetector(
                            onTap: () => setState(() => _hasNewComments = false),
                            child: Container(
                              margin: const EdgeInsets.fromLTRB(16, 8, 16, 0),
                              padding: const EdgeInsets.symmetric(vertical: 7),
                              decoration: BoxDecoration(color: const Color(0x26FF3B5C), borderRadius: BorderRadius.circular(10)),
                              child: const Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(Icons.arrow_downward, size: 14, color: Color(0xFFFF3B5C)),
                                  SizedBox(width: 6),
                                  Text('New comments just arrived', style: TextStyle(color: Color(0xFFFF3B5C), fontSize: 12, fontWeight: FontWeight.w600)),
                                ],
                              ),
                            ),
                          ),
                        Expanded(
                          child: _comments.isEmpty
                              ? const Center(child: Text('Be the first to comment. 💬', style: TextStyle(color: Colors.white54)))
                              : ListView.builder(
                                  padding: const EdgeInsets.symmetric(horizontal: 16),
                                  itemCount: _comments.length,
                                  itemBuilder: (_, i) => _commentTile(_comments[i], isReply: false),
                                ),
                        ),
                      ],
                    ),
            ),
            const Divider(height: 1, color: Color(0xFF2A2A2A)),
            Padding(
              padding: const EdgeInsets.all(12),
              child: Column(
                children: [
                  if (_replyToName != null)
                    Container(
                      margin: const EdgeInsets.only(bottom: 8),
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                      decoration: BoxDecoration(color: const Color(0xFF202020), borderRadius: BorderRadius.circular(8)),
                      child: Row(
                        children: [
                          Expanded(child: Text('Replying to $_replyToName', style: const TextStyle(color: AppColors.goldSoft, fontSize: 12.5), overflow: TextOverflow.ellipsis)),
                          GestureDetector(
                            onTap: () => setState(() {
                              _replyToId = null;
                              _replyToName = null;
                            }),
                            child: const Icon(Icons.close, size: 15, color: Colors.white54),
                          ),
                        ],
                      ),
                    ),
                  TextField(
                    controller: _name,
                    maxLength: 100,
                    style: const TextStyle(color: Colors.white, fontSize: 13),
                    decoration: _inputDeco('Your name (optional)'),
                  ),
                  const SizedBox(height: 8),
                  if (_emojiOpen)
                    SizedBox(
                      height: 44,
                      child: ListView(
                        scrollDirection: Axis.horizontal,
                        children: _emojis
                            .map((e) => InkWell(
                                  onTap: () => _insertEmoji(e),
                                  child: Padding(
                                    padding: const EdgeInsets.symmetric(horizontal: 4),
                                    child: Center(child: Text(e, style: const TextStyle(fontSize: 22))),
                                  ),
                                ))
                            .toList(),
                      ),
                    ),
                  if (_image != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 6),
                      child: Row(
                        children: [
                          ClipRRect(
                            borderRadius: BorderRadius.circular(8),
                            child: Image.file(File(_image!.path), width: 56, height: 56, fit: BoxFit.cover),
                          ),
                          const SizedBox(width: 8),
                          GestureDetector(
                            onTap: () => setState(() => _image = null),
                            child: const Icon(Icons.close, size: 16, color: Colors.white54),
                          ),
                        ],
                      ),
                    ),
                  Row(
                    crossAxisAlignment: CrossAxisAlignment.end,
                    children: [
                      IconButton(
                        onPressed: () => setState(() => _emojiOpen = !_emojiOpen),
                        icon: const Text('😊', style: TextStyle(fontSize: 20)),
                      ),
                      IconButton(
                        onPressed: _posting ? null : _pickImage,
                        icon: const Icon(Icons.photo_library_outlined, color: Colors.white70, size: 20),
                      ),
                      Expanded(
                        child: TextField(
                          controller: _message,
                          maxLines: 2,
                          maxLength: 1000,
                          style: const TextStyle(color: Colors.white, fontSize: 13.5),
                          decoration: _inputDeco(_replyToName != null ? 'Reply to $_replyToName…' : 'Add a comment…'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      IconButton(
                        onPressed: _posting ? null : _submit,
                        icon: const Icon(Icons.send, color: AppColors.gold),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  InputDecoration _inputDeco(String hint) {
    return InputDecoration(
      hintText: hint,
      counterText: '',
      hintStyle: const TextStyle(color: Colors.white38, fontSize: 13),
      filled: true,
      fillColor: const Color(0xFF262626),
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 9),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(10), borderSide: BorderSide.none),
    );
  }
}

String _initial(String? name) {
  final s = (name ?? '').trim();
  return s.isEmpty ? 'C' : s[0].toUpperCase();
}
