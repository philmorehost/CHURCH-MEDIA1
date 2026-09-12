import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import '../widgets/event_sermon_cards.dart';
import 'sermon_detail_screen.dart';
import 'series_screen.dart';

class SermonsScreen extends StatefulWidget {
  const SermonsScreen({super.key});
  @override
  State<SermonsScreen> createState() => _SermonsScreenState();
}

class _SermonsScreenState extends State<SermonsScreen> {
  final _api = ApiClient();
  List<Sermon> _sermons = [];
  List<SermonSeries> _series = [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = true;
  int _page = 1;

  /// Null means every sermon, which is not the same as "belongs to no series" — at a small
  /// church most sermons belong to no series, so there is deliberately no chip for that.
  SermonSeries? _filter;

  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _load();
    _scrollController.addListener(() {
      if (_scrollController.position.pixels > _scrollController.position.maxScrollExtent - 200) {
        _loadMore();
      }
    });
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _page = 1;
    });
    // The series are fetched alongside the sermons rather than after them, so the filter bar
    // does not appear a moment later and shove the list down the screen.
    final sermonsFuture = _api.fetchSermons(page: 1, series: _filter?.slug);
    final seriesFuture = _api.fetchSeries();
    final result = await sermonsFuture;
    final series = await seriesFuture;
    if (!mounted) return;
    setState(() {
      _sermons = result.sermons;
      _hasMore = result.hasMore;
      _series = series;
      _loading = false;
      _page = 2;
    });
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore) return;
    setState(() => _loadingMore = true);
    final result = await _api.fetchSermons(page: _page, series: _filter?.slug);
    if (!mounted) return;
    setState(() {
      _sermons.addAll(result.sermons);
      _hasMore = result.hasMore;
      _page++;
      _loadingMore = false;
    });
  }

  void _selectFilter(SermonSeries? series) {
    if (_filter?.id == series?.id) return;
    setState(() => _filter = series);
    _load();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Sermons'),
        actions: [
          if (_series.isNotEmpty)
            IconButton(
              tooltip: 'Sermon series',
              icon: const Icon(Icons.library_books_outlined),
              onPressed: () => Navigator.push(
                context,
                MaterialPageRoute(builder: (_) => const SeriesScreen()),
              ),
            ),
        ],
      ),
      body: _loading
          ? const LoadingView()
          : Column(
              children: [
                if (_series.isNotEmpty) _filterBar(),
                Expanded(
                  child: _sermons.isEmpty
                      ? EmptyState(
                          message: _filter == null
                              ? 'No sermons published yet.'
                              : 'No episodes published in this series yet.',
                        )
                      : RefreshIndicator(
                          onRefresh: _load,
                          child: ListView.builder(
                            controller: _scrollController,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            itemCount: _sermons.length + (_hasMore ? 1 : 0),
                            itemBuilder: (context, i) {
                              if (i >= _sermons.length) {
                                return const Padding(padding: EdgeInsets.all(20), child: LoadingView());
                              }
                              return SermonCard(
                                sermon: _sermons[i],
                                onTap: () => Navigator.push(
                                  context,
                                  MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: _sermons[i].slug)),
                                ),
                              );
                            },
                          ),
                        ),
                ),
              ],
            ),
    );
  }

  Widget _filterBar() {
    return SizedBox(
      height: 52,
      child: ListView(
        scrollDirection: Axis.horizontal,
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        children: [
          _chip(label: 'All', selected: _filter == null, onTap: () => _selectFilter(null)),
          ..._series.map(
            (s) => _chip(label: s.title, selected: _filter?.id == s.id, onTap: () => _selectFilter(s)),
          ),
        ],
      ),
    );
  }

  Widget _chip({required String label, required bool selected, required VoidCallback onTap}) {
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) => onTap(),
        labelStyle: TextStyle(
          fontSize: 12.5,
          color: selected ? AppColors.bg0 : AppColors.inkDim,
        ),
        backgroundColor: AppColors.bg2,
        selectedColor: AppColors.goldSoft,
        side: BorderSide.none,
      ),
    );
  }
}
