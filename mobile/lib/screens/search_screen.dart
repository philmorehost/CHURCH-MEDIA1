import 'package:flutter/material.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'event_detail_screen.dart';
import 'sermon_detail_screen.dart';

class SearchScreen extends StatefulWidget {
  const SearchScreen({super.key});
  @override
  State<SearchScreen> createState() => _SearchScreenState();
}

class _SearchScreenState extends State<SearchScreen> {
  final _api = ApiClient();
  final _controller = TextEditingController();
  Map<String, List<dynamic>> _results = {};
  bool _loading = false;
  bool _searched = false;

  Future<void> _search(String query) async {
    if (query.trim().length < 2) {
      setState(() {
        _results = {};
        _searched = false;
      });
      return;
    }
    setState(() => _loading = true);
    try {
      final results = await _api.search(query.trim());
      setState(() {
        _results = results;
        _loading = false;
        _searched = true;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final sermons = _results['sermons'] ?? [];
    final events = _results['events'] ?? [];
    final posts = _results['posts'] ?? [];
    final totalResults = sermons.length + events.length + posts.length;

    return Scaffold(
      appBar: AppBar(title: const Text('Search')),
      body: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            TextField(
              controller: _controller,
              decoration: const InputDecoration(labelText: 'Search sermons, events, posts…', prefixIcon: Icon(Icons.search)),
              onSubmitted: _search,
            ),
            const SizedBox(height: 20),
            if (_loading) const Expanded(child: LoadingView()),
            if (!_loading && _searched && totalResults == 0) const Expanded(child: EmptyState(message: 'No results found.')),
            if (!_loading && !_searched) const Expanded(child: EmptyState(message: 'Start typing to search across sermons, events, and the media feed.')),
            if (!_loading && totalResults > 0)
              Expanded(
                child: ListView(
                  children: [
                    if (sermons.isNotEmpty) _sectionLabel('Sermons'),
                    ...sermons.map((r) => _resultTile(r['title'] as String, () => Navigator.push(context, MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: r['slug'] as String))))),
                    if (events.isNotEmpty) _sectionLabel('Events'),
                    ...events.map((r) => _resultTile(r['title'] as String, () => Navigator.push(context, MaterialPageRoute(builder: (_) => EventDetailScreen(slug: r['slug'] as String))))),
                    if (posts.isNotEmpty) _sectionLabel('Feed Posts'),
                    ...posts.map((r) => _resultTile((r['title'] as String?)?.isNotEmpty == true ? r['title'] as String : 'Untitled post', null)),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _sectionLabel(String label) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: Text(label, style: const TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w700, fontSize: 13)),
      );

  Widget _resultTile(String title, VoidCallback? onTap) => Card(
        margin: const EdgeInsets.only(bottom: 8),
        child: ListTile(title: Text(title), onTap: onTap, trailing: onTap != null ? const Icon(Icons.chevron_right, color: AppColors.inkFaint) : null),
      );
}
