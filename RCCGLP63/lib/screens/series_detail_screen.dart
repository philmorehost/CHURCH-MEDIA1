import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import '../widgets/event_sermon_cards.dart';
import 'sermon_detail_screen.dart';

/// One series and its episodes, in episode order.
///
/// The order comes from the server rather than being sorted here, so the app, the website and
/// the podcast feed all agree.
class SeriesDetailScreen extends StatefulWidget {
  final String slug;
  const SeriesDetailScreen({super.key, required this.slug});

  @override
  State<SeriesDetailScreen> createState() => _SeriesDetailScreenState();
}

class _SeriesDetailScreenState extends State<SeriesDetailScreen> {
  final _api = ApiClient();
  SermonSeries? _series;
  List<Sermon> _episodes = [];
  bool _loading = true;
  bool _notFound = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _notFound = false;
    });
    final result = await _api.fetchSeriesDetail(widget.slug);
    if (!mounted) return;
    setState(() {
      // A missing series is a real answer, not a failure to load — showing "no episodes" for a
      // series that does not exist would look like a bug in the app.
      _series = result?.series;
      _episodes = result?.episodes ?? const [];
      _notFound = result == null;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(_series?.title ?? 'Series')),
      body: _loading
          ? const LoadingView()
          : _notFound
              ? const EmptyState(message: 'That series is no longer available.')
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView(
                    padding: const EdgeInsets.only(bottom: 24),
                    children: [
                      if (_series!.coverImageUrl != null)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
                          child: ClipRRect(
                            borderRadius: BorderRadius.circular(16),
                            child: AspectRatio(
                              aspectRatio: 16 / 9,
                              child: CachedNetworkImage(imageUrl: _series!.coverImageUrl!, fit: BoxFit.cover),
                            ),
                          ),
                        ),
                      if (_series!.description != null && _series!.description!.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
                          child: Text(
                            _series!.description!,
                            style: const TextStyle(color: AppColors.inkDim, fontSize: 14, height: 1.6),
                          ),
                        ),
                      Padding(
                        padding: const EdgeInsets.fromLTRB(20, 14, 20, 6),
                        child: Text(
                          _episodes.length == 1 ? '1 episode' : '${_episodes.length} episodes',
                          style: const TextStyle(color: AppColors.inkFaint, fontSize: 12.5),
                        ),
                      ),
                      if (_episodes.isEmpty)
                        const Padding(
                          padding: EdgeInsets.only(top: 40),
                          child: EmptyState(message: 'No episodes published in this series yet.'),
                        )
                      else
                        ..._episodes.map(
                          (episode) => SermonCard(
                            sermon: episode,
                            onTap: () => Navigator.push(
                              context,
                              MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: episode.slug)),
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
    );
  }
}
