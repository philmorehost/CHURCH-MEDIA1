import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

class EventDetailScreen extends StatefulWidget {
  final String slug;
  const EventDetailScreen({super.key, required this.slug});
  @override
  State<EventDetailScreen> createState() => _EventDetailScreenState();
}

class _EventDetailScreenState extends State<EventDetailScreen> {
  final _api = ApiClient();
  ChurchEvent? _event;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _api.fetchEvent(widget.slug).then((e) {
      if (mounted) setState(() {
        _event = e;
        _loading = false;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Scaffold(body: LoadingView());
    final event = _event;
    if (event == null) {
      return Scaffold(appBar: AppBar(), body: const EmptyState(message: 'Event not found.'));
    }
    final start = DateTime.tryParse(event.startAt);
    final end = event.endAt != null ? DateTime.tryParse(event.endAt!) : null;

    return Scaffold(
      body: CustomScrollView(
        slivers: [
          SliverAppBar(
            expandedHeight: event.coverImageUrl != null ? 240 : 100,
            pinned: true,
            flexibleSpace: FlexibleSpaceBar(
              background: event.coverImageUrl != null
                  ? CachedNetworkImage(imageUrl: event.coverImageUrl!, fit: BoxFit.cover)
                  : Container(color: AppColors.bg1),
            ),
          ),
          SliverPadding(
            padding: const EdgeInsets.all(24),
            sliver: SliverList.list(children: [
              Text(event.title, style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 12),
              Wrap(spacing: 16, runSpacing: 8, children: [
                if (start != null) _metaChip('🗓 ${DateFormat('EEEE, MMMM d, yyyy').format(start)}'),
                if (start != null) _metaChip('🕘 ${DateFormat('g:mm a').format(start)}${end != null ? ' – ${DateFormat('g:mm a, MMM d').format(end)}' : ''}'),
                if (event.location != null) _metaChip('📍 ${event.location}'),
              ]),
              if (event.description != null) ...[
                const SizedBox(height: 20),
                Text(event.description!, style: const TextStyle(color: AppColors.inkDim, height: 1.6)),
              ],
              const SizedBox(height: 28),
              if (event.rsvpEnabled && event.rsvpUrl != null)
                ElevatedButton(
                  onPressed: () => launchUrl(Uri.parse(event.rsvpUrl!), mode: LaunchMode.externalApplication),
                  child: const Text('RSVP Now'),
                ),
            ]),
          ),
        ],
      ),
    );
  }

  Widget _metaChip(String text) => Text(text, style: const TextStyle(color: AppColors.inkFaint, fontSize: 13));
}
