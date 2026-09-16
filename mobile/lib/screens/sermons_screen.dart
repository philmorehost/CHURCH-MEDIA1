import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../widgets/common.dart';
import '../widgets/event_sermon_cards.dart';
import 'sermon_detail_screen.dart';

class SermonsScreen extends StatefulWidget {
  const SermonsScreen({super.key});
  @override
  State<SermonsScreen> createState() => _SermonsScreenState();
}

class _SermonsScreenState extends State<SermonsScreen> {
  final _api = ApiClient();
  List<Sermon> _sermons = [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = true;
  int _page = 1;
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
    final result = await _api.fetchSermons(page: 1);
    setState(() {
      _sermons = result.sermons;
      _hasMore = result.hasMore;
      _loading = false;
      _page = 2;
    });
  }

  Future<void> _loadMore() async {
    if (_loadingMore || !_hasMore) return;
    setState(() => _loadingMore = true);
    final result = await _api.fetchSermons(page: _page);
    setState(() {
      _sermons.addAll(result.sermons);
      _hasMore = result.hasMore;
      _page++;
      _loadingMore = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Sermons')),
      body: _loading
          ? const LoadingView()
          : _sermons.isEmpty
              ? const EmptyState(message: 'No sermons published yet.')
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
                        onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: _sermons[i].slug))),
                      );
                    },
                  ),
                ),
    );
  }
}
