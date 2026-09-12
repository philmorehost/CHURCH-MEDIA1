import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'series_detail_screen.dart';

/// The list of sermon series, newest activity first.
///
/// A listener arriving here wants to start a teaching run from the beginning, which is why this
/// is a first-class screen rather than a filter on the sermon list.
class SeriesScreen extends StatefulWidget {
  const SeriesScreen({super.key});

  @override
  State<SeriesScreen> createState() => _SeriesScreenState();
}

class _SeriesScreenState extends State<SeriesScreen> {
  final _api = ApiClient();
  List<SermonSeries> _series = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    final series = await _api.fetchSeries();
    if (!mounted) return;
    setState(() {
      _series = series;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Sermon Series')),
      body: _loading
          ? const LoadingView()
          : _series.isEmpty
              ? const EmptyState(message: 'No series published yet.')
              : RefreshIndicator(
                  onRefresh: _load,
                  child: ListView.builder(
                    padding: const EdgeInsets.symmetric(vertical: 12),
                    itemCount: _series.length,
                    itemBuilder: (context, i) => _SeriesTile(
                      series: _series[i],
                      onTap: () => Navigator.push(
                        context,
                        MaterialPageRoute(builder: (_) => SeriesDetailScreen(slug: _series[i].slug)),
                      ),
                    ),
                  ),
                ),
    );
  }
}

class _SeriesTile extends StatelessWidget {
  final SermonSeries series;
  final VoidCallback onTap;

  const _SeriesTile({required this.series, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final latest = series.latestAt != null ? DateTime.tryParse(series.latestAt!) : null;
    return Card(
      clipBehavior: Clip.antiAlias,
      margin: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
      child: InkWell(
        onTap: onTap,
        child: Row(
          children: [
            SizedBox(
              width: 100,
              height: 100,
              child: series.coverImageUrl != null
                  ? CachedNetworkImage(imageUrl: series.coverImageUrl!, fit: BoxFit.cover)
                  : Container(color: AppColors.bg2, child: const Icon(Icons.library_books, color: AppColors.inkFaint)),
            ),
            Expanded(
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      series.title,
                      style: Theme.of(context).textTheme.titleLarge,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                    const SizedBox(height: 4),
                    Text(
                      [
                        // Wording matters at one: "1 episodes" reads as a bug.
                        series.sermonCount == 1 ? '1 episode' : '${series.sermonCount} episodes',
                        if (latest != null) 'latest ${DateFormat('MMM d, yyyy').format(latest)}',
                      ].join(' · '),
                      style: const TextStyle(color: AppColors.inkFaint, fontSize: 12),
                    ),
                    if (series.description != null && series.description!.isNotEmpty) ...[
                      const SizedBox(height: 4),
                      Text(
                        series.description!,
                        style: const TextStyle(color: AppColors.inkDim, fontSize: 12),
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
