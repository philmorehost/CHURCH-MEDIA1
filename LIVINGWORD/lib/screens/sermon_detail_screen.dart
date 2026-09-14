import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:video_player/video_player.dart';
import '../models/models.dart';
import '../services/analytics_beacon.dart';
import '../services/api_client.dart';
import '../services/download_store.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'series_detail_screen.dart';

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
  bool _playingSaved = false;
  bool _unreachable = false;
  VideoPlayerController? _audioController;

  @override
  void initState() {
    super.initState();
    _load();
  }

  /// Loads the sermon, taking what is already on the device into account.
  ///
  /// The saved index is read first so a downloaded message opens even with no
  /// network at all. The server copy is then requested and, when it arrives,
  /// replaces the saved one — which keeps a message that has since been edited
  /// current. Before this, a request that failed left the screen spinning
  /// forever, and offline is exactly when that happened.
  Future<void> _load() async {
    final store = DownloadStore.instance;
    await store.init();
    if (!mounted) return;
    final saved = store.bySlug(widget.slug);

    Sermon? fetched;
    Object? failure;
    try {
      fetched = await _api.fetchSermon(widget.slug);
    } catch (e) {
      failure = e;
    }
    if (!mounted) return;

    final sermon = fetched ?? saved?.toSermon();
    setState(() {
      _sermon = sermon;
      _playingSaved = fetched == null && saved != null;
      _unreachable = failure != null;
      _loading = false;
    });

    // Report the listen — only for a sermon the server actually returned, so a
    // dead link is not counted as a view.
    if (fetched != null) AnalyticsBeacon.contentView('sermon', fetched.id);
    _startAudio(sermon);
  }

  void _startAudio(Sermon? sermon) {
    final local = DownloadStore.instance.localPathFor(widget.slug);
    final remote = sermon?.audioUrl;
    if (local == null && (remote == null || remote.isEmpty)) return;
    _audioController?.dispose();
    // The file on the device wins when there is one: it plays with no network,
    // and it costs no data.
    final controller = local != null
        ? VideoPlayerController.file(File(local))
        : VideoPlayerController.networkUrl(Uri.parse(remote!));
    _audioController = controller;
    controller.initialize().then((_) {
      if (mounted) setState(() {});
    }).catchError((Object error) {
      // A file that will not decode leaves the player hidden rather than taking
      // the whole screen down.
      debugPrint('audio init failed: $error');
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
      return Scaffold(
        appBar: AppBar(),
        body: EmptyState(
          message: _unreachable
              ? "Couldn't reach the church server, and this message isn't saved on this "
                  'device. Open it once with a connection and tap Download to keep it.'
              : 'Sermon not found.',
        ),
      );
    }
    final published = DateTime.tryParse(sermon.publishedAt);

    return Scaffold(
      appBar: AppBar(title: Text(sermon.series ?? 'Sermon')),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          if (sermon.isInSeries)
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: InkWell(
                // Opens the series, so a listener who lands on episode 4 by a shared link can
                // find the other three without going back through the sermon list. Not tappable
                // when the slug is missing, because an empty slug would land on "not available".
                onTap: sermon.seriesSlug == null
                    ? null
                    : () => Navigator.push(
                          context,
                          MaterialPageRoute(builder: (_) => SeriesDetailScreen(slug: sermon.seriesSlug!)),
                        ),
                child: Text(
                  '${sermon.series}${sermon.seriesPosition != null ? ' · Episode ${sermon.seriesPosition}' : ''}',
                  style: const TextStyle(color: AppColors.goldSoft, fontSize: 12.5, fontWeight: FontWeight.w700, letterSpacing: 0.6),
                ),
              ),
            ),
          Text(sermon.title, style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 10),
          Wrap(spacing: 16, runSpacing: 8, children: [
            if (sermon.speaker != null) Text('🎙 ${sermon.speaker}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
            if (published != null) Text('🗓 ${DateFormat('MMMM d, yyyy').format(published)}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
            if (sermon.scriptureRef != null) Text('📖 ${sermon.scriptureRef}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
            if (sermon.durationLabel != null) Text('⏱ ${sermon.durationLabel}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
          ]),
          if (_playingSaved) ...[
            const SizedBox(height: 16),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: AppColors.panel,
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: AppColors.border),
              ),
              child: Row(children: [
                const Icon(Icons.offline_pin, size: 17, color: AppColors.success),
                const SizedBox(width: 10),
                Expanded(
                  child: Text(
                    _unreachable
                        ? "Showing your saved copy — the church server couldn't be reached."
                        : 'Showing your saved copy.',
                    style: const TextStyle(fontSize: 12.5, color: AppColors.inkDim, height: 1.4),
                  ),
                ),
              ]),
            ),
          ],
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
          if (sermon.audioUrl != null) ...[
            const SizedBox(height: 10),
            _DownloadAction(sermon: sermon),
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

/// Save-to-device control for one sermon.
///
/// It reads the shared store rather than keeping its own copy of the state, so
/// this button and the Downloads list can never disagree about what is saved.
class _DownloadAction extends StatelessWidget {
  final Sermon sermon;
  const _DownloadAction({required this.sermon});

  @override
  Widget build(BuildContext context) {
    final store = DownloadStore.instance;
    return ListenableBuilder(
      listenable: store,
      builder: (context, _) {
        final url = sermon.audioUrl;
        if (url == null || url.isEmpty) return const SizedBox.shrink();

        if (store.isDownloading(sermon.slug)) {
          final progress = store.progressFor(sermon.slug) ?? 0;
          return Container(
            padding: const EdgeInsets.fromLTRB(14, 12, 14, 14),
            decoration: BoxDecoration(
              color: AppColors.panel,
              borderRadius: BorderRadius.circular(14),
              border: Border.all(color: AppColors.border),
            ),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Row(children: [
                const Icon(Icons.downloading, size: 17, color: AppColors.gold),
                const SizedBox(width: 8),
                Text(
                  // Not every server sends Content-Length. Without it there is no
                  // percentage to quote, so the bar animates instead of lying about
                  // a position it does not know.
                  progress > 0 ? 'Downloading… ${(progress * 100).round()}%' : 'Downloading…',
                  style: const TextStyle(fontSize: 13, color: AppColors.inkDim),
                ),
              ]),
              const SizedBox(height: 10),
              ClipRRect(
                borderRadius: BorderRadius.circular(4),
                child: LinearProgressIndicator(
                  value: progress > 0 ? progress : null,
                  minHeight: 5,
                  backgroundColor: AppColors.border,
                  valueColor: const AlwaysStoppedAnimation<Color>(AppColors.gold),
                ),
              ),
            ]),
          );
        }

        final saved = store.bySlug(sermon.slug);
        if (saved != null) {
          return Row(children: [
            const Icon(Icons.offline_pin, size: 18, color: AppColors.success),
            const SizedBox(width: 8),
            Expanded(
              child: Text('Saved on this device · ${saved.sizeLabel}',
                  style: const TextStyle(fontSize: 13, color: AppColors.inkDim)),
            ),
            TextButton(
              onPressed: () async {
                final ok = await confirmDestructive(
                  context,
                  title: 'Delete this download?',
                  body: '"${sermon.title}" (${saved.sizeLabel}) will be removed from this device. '
                      'Downloading it again uses data.',
                  action: 'Delete',
                );
                if (ok) await store.remove(sermon.slug);
              },
              child: const Text('Remove', style: TextStyle(color: AppColors.inkFaint)),
            ),
          ]);
        }

        final failure = store.failureFor(sermon.slug);
        return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          TextButton.icon(
            onPressed: () => store.download(sermon),
            icon: const Icon(Icons.download, size: 18),
            label: Text(failure == null ? 'Download to this device' : 'Try the download again'),
          ),
          if (failure != null)
            Padding(
              padding: const EdgeInsets.only(left: 8, top: 2),
              child: Text('$failure The message still plays over the network.',
                  style: const TextStyle(fontSize: 12, color: AppColors.inkFaint, height: 1.4)),
            ),
        ]);
      },
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
