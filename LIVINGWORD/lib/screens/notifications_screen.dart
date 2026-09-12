import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'event_detail_screen.dart';
import 'sermon_detail_screen.dart';

/// In-app notifications center — lists recent activity (new reels, upcoming
/// events, new sermons). Fully anonymous; no push service required.
class NotificationsScreen extends StatefulWidget {
  final VoidCallback? onGoToFeed;
  const NotificationsScreen({super.key, this.onGoToFeed});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  final _api = ApiClient();
  List<Map<String, dynamic>> _items = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final list = await _api.fetchActivity();
      if (mounted) {
        setState(() {
          _items = list;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  String _thumbUrl(String? path) {
    if (path == null || path.isEmpty) return '';
    if (path.startsWith('http')) return path;
    return '${ApiClient.baseUrl}/uploads/${path.replaceFirst(RegExp(r'^/+'), '')}';
  }

  String _timeAgo(String iso) {
    try {
      final d = DateTime.parse(iso).toLocal();
      final diff = DateTime.now().difference(d);
      if (diff.inSeconds < 60) return 'just now';
      if (diff.inMinutes < 60) return '${diff.inMinutes}m ago';
      if (diff.inHours < 24) return '${diff.inHours}h ago';
      if (diff.inDays < 7) return '${diff.inDays}d ago';
      return '${d.day}/${d.month}/${d.year}';
    } catch (_) {
      return '';
    }
  }

  void _open(Map<String, dynamic> item) {
    final target = (item['target'] as Map<String, dynamic>?) ?? const {};
    final screen = target['screen'] as String? ?? '';
    final slug = target['slug'] as String? ?? '';
    switch (screen) {
      case 'event':
        if (slug.isNotEmpty) {
          Navigator.push(context, MaterialPageRoute(builder: (_) => EventDetailScreen(slug: slug)));
        }
        break;
      case 'sermon':
        if (slug.isNotEmpty) {
          Navigator.push(context, MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: slug)));
        }
        break;
      case 'feed':
      default:
        Navigator.pop(context);
        widget.onGoToFeed?.call();
    }
  }

  IconData _iconFor(String type) {
    switch (type) {
      case 'event':
        return Icons.event;
      case 'sermon':
        return Icons.menu_book;
      default:
        return Icons.play_circle_fill;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppColors.bg0,
      body: SafeArea(
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 10),
              decoration: const BoxDecoration(color: AppColors.bg1, border: Border(bottom: BorderSide(color: AppColors.border))),
              child: Row(
                children: [
                  IconButton(onPressed: () => Navigator.pop(context), icon: const Icon(Icons.arrow_back, color: Colors.white)),
                  const Text('Notifications', style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.w800)),
                  const Spacer(),
                  IconButton(onPressed: _load, icon: const Icon(Icons.refresh, color: AppColors.inkDim, size: 20)),
                ],
              ),
            ),
            Expanded(
              child: _loading
                  ? const LoadingView()
                  : _items.isEmpty
                      ? const EmptyState(message: 'No activity yet — new reels, events, and sermons will show up here.')
                      : RefreshIndicator(
                          color: AppColors.gold,
                          onRefresh: _load,
                          child: ListView.separated(
                            physics: const AlwaysScrollableScrollPhysics(),
                            padding: const EdgeInsets.symmetric(vertical: 4),
                            itemCount: _items.length,
                            separatorBuilder: (_, __) => const Divider(height: 1, indent: 72, color: AppColors.border),
                            itemBuilder: (context, i) {
                              final item = _items[i];
                              final type = (item['type'] as String?) ?? 'post';
                              final thumb = _thumbUrl(item['thumb'] as String?);
                              return ListTile(
                                onTap: () => _open(item),
                                leading: SizedBox(
                                  width: 48,
                                  height: 48,
                                  child: thumb.isNotEmpty
                                      ? ClipRRect(
                                          borderRadius: BorderRadius.circular(10),
                                          child: CachedNetworkImage(imageUrl: thumb, fit: BoxFit.cover, errorWidget: (_, __, ___) => _iconBox(_iconFor(type))),
                                        )
                                      : _iconBox(_iconFor(type)),
                                ),
                                title: Text(
                                  item['title'] as String? ?? '',
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(color: Colors.white, fontSize: 14.5, fontWeight: FontWeight.w700),
                                ),
                                subtitle: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(item['body'] as String? ?? '', maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: AppColors.inkDim, fontSize: 13)),
                                    const SizedBox(height: 2),
                                    Text(_timeAgo(item['created_at'] as String? ?? ''), style: const TextStyle(color: AppColors.inkFaint, fontSize: 11)),
                                  ],
                                ),
                                trailing: const Icon(Icons.chevron_right, color: AppColors.inkFaint, size: 20),
                              );
                            },
                          ),
                        ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _iconBox(IconData icon) {
    return Container(
      decoration: BoxDecoration(color: AppColors.bg2, borderRadius: BorderRadius.circular(10)),
      alignment: Alignment.center,
      child: Icon(icon, color: AppColors.goldSoft, size: 22),
    );
  }
}
