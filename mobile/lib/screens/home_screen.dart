import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import '../widgets/event_sermon_cards.dart';
import 'bible_screen.dart';
import 'notifications_screen.dart';
import 'units_screen.dart';
import 'event_detail_screen.dart';
import 'sermon_detail_screen.dart';

class HomeScreen extends StatefulWidget {
  final void Function(int tabIndex) onNavigate;
  const HomeScreen({super.key, required this.onNavigate});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  final _api = ApiClient();
  ChurchSettings? _settings;
  List<Post> _posts = [];
  List<ChurchEvent> _events = [];
  List<Sermon> _sermons = [];
  bool _loading = true;
  bool _unread = false;
  String? _newestAt;

  @override
  void initState() {
    super.initState();
    _load();
    _loadActivity();
  }

  Future<void> _loadActivity() async {
    try {
      final list = await _api.fetchActivity();
      if (!mounted || list.isEmpty) return;
      final newest = (list.first['created_at'] as String?) ?? '';
      _newestAt = newest;
      final prefs = await SharedPreferences.getInstance();
      final lastSeen = prefs.getString('last_seen_activity_at') ?? '';
      setState(() => _unread = newest != '' && newest.compareTo(lastSeen) > 0);
    } catch (_) {}
  }

  Future<void> _openNotifications() async {
    await Navigator.push(context, MaterialPageRoute(builder: (_) => NotificationsScreen(onGoToFeed: () => widget.onNavigate(1))));
    if (_newestAt != null && _newestAt!.isNotEmpty) {
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('last_seen_activity_at', _newestAt!);
    }
    if (mounted) setState(() => _unread = false);
  }

  Widget _bellButton() {
    return Stack(
      clipBehavior: Clip.none,
      children: [
        IconButton(
          icon: const Icon(Icons.notifications_none, color: Colors.white),
          tooltip: 'Notifications',
          onPressed: _openNotifications,
        ),
        if (_unread)
          Positioned(
            right: 6,
            top: 6,
            child: Container(
              width: 10,
              height: 10,
              decoration: BoxDecoration(
                color: const Color(0xFFFF3B5C),
                shape: BoxShape.circle,
                border: Border.all(color: AppColors.bg1, width: 2),
              ),
            ),
          ),
      ],
    );
  }

  Future<void> _load() async {
    try {
      final results = await Future.wait([
        _api.fetchSettings(),
        _api.fetchFeed(page: 1),
        _api.fetchEvents(scope: 'upcoming'),
        _api.fetchSermons(page: 1),
      ]);
      setState(() {
        _settings = results[0] as ChurchSettings;
        _posts = (results[1] as ({List<Post> posts, bool hasMore})).posts.take(6).toList();
        _events = (results[2] as ({List<ChurchEvent> events, bool hasMore})).events.take(3).toList();
        _sermons = (results[3] as ({List<Sermon> sermons, bool hasMore})).sermons.take(3).toList();
        _loading = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Scaffold(body: LoadingView());
    final s = _settings;

    return Scaffold(
      body: RefreshIndicator(
        onRefresh: _load,
        color: AppColors.gold,
        child: CustomScrollView(
          slivers: [
            SliverToBoxAdapter(child: _buildHero(s)),
            SliverToBoxAdapter(child: _buildBibleCard()),
            SliverToBoxAdapter(child: _buildParishCard()),
            SliverToBoxAdapter(child: SectionHeader(eyebrow: 'Community', title: 'From Our Feed')),
            SliverToBoxAdapter(child: _buildFeedStrip()),
            SliverToBoxAdapter(child: SectionHeader(eyebrow: 'Save the Date', title: "What's Happening")),
            if (_events.isEmpty)
              const SliverToBoxAdapter(child: EmptyState(message: 'No upcoming events right now.'))
            else
              SliverList.builder(
                itemCount: _events.length,
                itemBuilder: (context, i) => EventCard(
                  event: _events[i],
                  onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => EventDetailScreen(slug: _events[i].slug))),
                ),
              ),
            SliverToBoxAdapter(child: SectionHeader(eyebrow: 'Sunday Word', title: 'Latest Sermons')),
            if (_sermons.isEmpty)
              const SliverToBoxAdapter(child: EmptyState(message: 'No sermons published yet.'))
            else
              SliverList.builder(
                itemCount: _sermons.length,
                itemBuilder: (context, i) => SermonCard(
                  sermon: _sermons[i],
                  onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: _sermons[i].slug))),
                ),
              ),
            if (s != null && s.serviceTimes.isNotEmpty) SliverToBoxAdapter(child: _buildServiceTimes(s)),
            const SliverToBoxAdapter(child: SizedBox(height: 40)),
          ],
        ),
      ),
    );
  }

  Widget _buildHero(ChurchSettings? s) {
    return Stack(
      children: [
        Container(
          padding: const EdgeInsets.fromLTRB(24, 60, 24, 48),
          decoration: const BoxDecoration(
            gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [AppColors.bg1, AppColors.bg0]),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.center,
            children: [
              const Text('WELCOME HOME', style: TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w700, fontSize: 12, letterSpacing: 1.4)),
              const SizedBox(height: 14),
              Text(
                s?.heroTagline ?? s?.siteTagline ?? s?.siteTitle ?? 'Welcome',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.displaySmall,
              ),
              if (s?.heroScripture != null) ...[
                const SizedBox(height: 14),
                Text(s!.heroScripture!, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.inkDim, fontStyle: FontStyle.italic)),
              ],
              const SizedBox(height: 26),
              Wrap(
                spacing: 12,
                runSpacing: 12,
                alignment: WrapAlignment.center,
                children: [
                  ElevatedButton(onPressed: () => widget.onNavigate(1), child: const Text('Watch the Feed')),
                  OutlinedButton(onPressed: () => widget.onNavigate(4), child: const Text('More')),
                ],
              ),
            ],
          ),
        ),
        Positioned(
          top: 44,
          right: 12,
          child: _bellButton(),
        ),
      ],
    );
  }

  Widget _buildBibleCard() {
    return GestureDetector(
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const BibleScreen())),
      child: Container(
        margin: const EdgeInsets.fromLTRB(16, 20, 16, 0),
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [Color(0xFF3A2A6D), Color(0xFF5A3A8A)]),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Row(children: [
          const Icon(Icons.menu_book, size: 40, color: AppColors.goldSoft),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Holy Bible', style: Theme.of(context).textTheme.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.bold)),
              const SizedBox(height: 2),
              const Text('Read the Word of God — works offline', style: TextStyle(color: Colors.white70, fontSize: 13)),
            ]),
          ),
          const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 18),
        ]),
      ),
    );
  }

  Widget _buildParishCard() {
    return GestureDetector(
      onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => const UnitsScreen())),
      child: Container(
        margin: const EdgeInsets.fromLTRB(16, 12, 16, 0),
        padding: const EdgeInsets.all(18),
        decoration: BoxDecoration(
          gradient: const LinearGradient(colors: [Color(0xFF0F3D3E), Color(0xFF1B6B6A)]),
          borderRadius: BorderRadius.circular(18),
        ),
        child: Row(children: [
          const Icon(Icons.church, size: 40, color: AppColors.goldSoft),
          const SizedBox(width: 14),
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text('Find Your Parish', style: Theme.of(context).textTheme.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.bold)),
              const SizedBox(height: 2),
              const Text('Browse media by province, zone, area & parish', style: TextStyle(color: Colors.white70, fontSize: 13)),
            ]),
          ),
          const Icon(Icons.arrow_forward_ios, color: Colors.white, size: 18),
        ]),
      ),
    );
  }

  Widget _buildFeedStrip() {
    if (_posts.isEmpty) return const EmptyState(message: 'No posts yet.');
    return SizedBox(
      height: 220,
      child: ListView.builder(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16),
        itemCount: _posts.length,
        itemBuilder: (context, i) {
          final post = _posts[i];
          final cover = post.mediaItems.isNotEmpty ? (post.mediaItems.first.type == 'video' ? post.mediaItems.first.thumbnailUrl : post.mediaItems.first.fileUrl) : null;
          return GestureDetector(
            onTap: () => widget.onNavigate(1),
            child: Container(
              width: 140,
              margin: const EdgeInsets.only(right: 12),
              decoration: BoxDecoration(borderRadius: BorderRadius.circular(16), color: AppColors.bg2),
              clipBehavior: Clip.antiAlias,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  if (cover != null) CachedNetworkImage(imageUrl: cover, fit: BoxFit.cover),
                  const DecoratedBox(
                    decoration: BoxDecoration(gradient: LinearGradient(begin: Alignment.topCenter, end: Alignment.bottomCenter, colors: [Colors.transparent, Color(0xCC000000)])),
                  ),
                  Positioned(
                    left: 10,
                    right: 10,
                    bottom: 10,
                    child: Text(post.caption ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600)),
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildServiceTimes(ChurchSettings s) {
    return Container(
      color: AppColors.bg1,
      padding: const EdgeInsets.fromLTRB(20, 28, 20, 40),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text('JOIN US', style: TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w700, fontSize: 12, letterSpacing: 1.2)),
          const SizedBox(height: 6),
          Text('Service Times', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 16),
          Wrap(
            spacing: 12,
            runSpacing: 12,
            children: s.serviceTimes
                .map((st) => Container(
                      padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 14),
                      decoration: BoxDecoration(border: Border.all(color: AppColors.border), borderRadius: BorderRadius.circular(14)),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text(st.label, style: const TextStyle(color: AppColors.inkDim, fontSize: 12)),
                        Text(st.time, style: const TextStyle(color: AppColors.goldSoft, fontSize: 15)),
                      ]),
                    ))
                .toList(),
          ),
        ],
      ),
    );
  }
}
