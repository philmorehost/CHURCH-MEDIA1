import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

class SermonDetailScreen extends StatefulWidget {
  final String slug;
  const SermonDetailScreen({super.key, required this.slug});
  @override
  State<SermonDetailScreen> createState() => _SermonDetailScreenState();
}

class _SermonDetailScreenState extends State<SermonDetailScreen> {
  final _api = ApiClient();
  Sermon? _sermon;
  bool _loading = true;
  VideoPlayerController? _audioController;

  @override
  void initState() {
    super.initState();
    _api.fetchSermon(widget.slug).then((s) {
      if (!mounted) return;
      setState(() {
        _sermon = s;
        _loading = false;
      });
      if (s?.audioUrl != null) {
        _audioController = VideoPlayerController.networkUrl(Uri.parse(s!.audioUrl!))..initialize().then((_) => setState(() {}));
      }
    });
  }

  @override
  void dispose() {
    _audioController?.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Scaffold(body: LoadingView());
    final sermon = _sermon;
    if (sermon == null) {
      return Scaffold(appBar: AppBar(), body: const EmptyState(message: 'Sermon not found.'));
    }
    final published = DateTime.tryParse(sermon.publishedAt);

    return Scaffold(
      appBar: AppBar(title: Text(sermon.series ?? 'Sermon')),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          Text(sermon.title, style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 10),
          Wrap(spacing: 16, runSpacing: 8, children: [
            if (sermon.speaker != null) Text('🎙 ${sermon.speaker}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
            if (published != null) Text('🗓 ${DateFormat('MMMM d, yyyy').format(published)}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
            if (sermon.scriptureRef != null) Text('📖 ${sermon.scriptureRef}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
          ]),
          const SizedBox(height: 20),
          if (sermon.coverImageUrl != null)
            ClipRRect(
              borderRadius: BorderRadius.circular(18),
              child: AspectRatio(aspectRatio: 16 / 9, child: CachedNetworkImage(imageUrl: sermon.coverImageUrl!, fit: BoxFit.cover)),
            ),
          if (sermon.videoEmbedUrl != null) ...[
            const SizedBox(height: 16),
            OutlinedButton.icon(
              onPressed: () => launchUrl(Uri.parse(sermon.videoEmbedUrl!), mode: LaunchMode.externalApplication),
              icon: const Icon(Icons.play_circle_outline),
              label: const Text('Watch Video'),
            ),
          ],
          if (_audioController != null) ...[
            const SizedBox(height: 20),
            _AudioPlayerBar(controller: _audioController!),
          ],
          if (sermon.description != null) ...[
            const SizedBox(height: 24),
            Text(sermon.description!, style: const TextStyle(color: AppColors.inkDim, height: 1.6)),
          ],
        ],
      ),
    );
  }
}

class _AudioPlayerBar extends StatefulWidget {
  final VideoPlayerController controller;
  const _AudioPlayerBar({required this.controller});
  @override
  State<_AudioPlayerBar> createState() => _AudioPlayerBarState();
}

class _AudioPlayerBarState extends State<_AudioPlayerBar> {
  @override
  void initState() {
    super.initState();
    widget.controller.addListener(_onTick);
  }

  @override
  void dispose() {
    widget.controller.removeListener(_onTick);
    super.dispose();
  }

  void _onTick() {
    if (mounted) setState(() {});
  }

  String _fmt(Duration d) => '${d.inMinutes}:${(d.inSeconds % 60).toString().padLeft(2, '0')}';

  @override
  Widget build(BuildContext context) {
    final value = widget.controller.value;
    if (!value.isInitialized) return const LoadingView();
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(color: AppColors.panel, borderRadius: BorderRadius.circular(16), border: Border.all(color: AppColors.border)),
      child: Row(children: [
        IconButton(
          icon: Icon(value.isPlaying ? Icons.pause_circle_filled : Icons.play_circle_fill, color: AppColors.gold, size: 40),
          onPressed: () => value.isPlaying ? widget.controller.pause() : widget.controller.play(),
        ),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Slider(
              value: value.position.inMilliseconds.clamp(0, value.duration.inMilliseconds).toDouble(),
              max: value.duration.inMilliseconds.toDouble() == 0 ? 1 : value.duration.inMilliseconds.toDouble(),
              activeColor: AppColors.gold,
              inactiveColor: AppColors.border,
              onChanged: (v) => widget.controller.seekTo(Duration(milliseconds: v.toInt())),
            ),
            Text('${_fmt(value.position)} / ${_fmt(value.duration)}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 11)),
          ]),
        ),
      ]),
    );
  }
}
