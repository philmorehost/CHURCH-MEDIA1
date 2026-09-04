import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import 'package:webview_flutter/webview_flutter.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

/// Embeds the livestream in-app via WebView (YouTube/Facebook embed URLs),
/// with an "open externally" fallback so viewers can still launch the native
/// YouTube app if they prefer.
class LiveScreen extends StatefulWidget {
  const LiveScreen({super.key});
  @override
  State<LiveScreen> createState() => _LiveScreenState();
}

class _LiveScreenState extends State<LiveScreen> {
  ChurchSettings? _settings;
  WebViewController? _controller;
  String? _embedUrl;

  @override
  void initState() {
    super.initState();
    ApiClient().fetchSettings().then((s) {
      if (!mounted) return;
      final embed = toEmbedUrl(s.livestreamEmbedUrl);
      setState(() {
        _settings = s;
        _embedUrl = embed;
        if (embed != null) {
          _controller = WebViewController()
            ..setJavaScriptMode(JavaScriptMode.unrestricted)
            ..setBackgroundColor(Colors.black)
            ..loadRequest(Uri.parse('$embed?autoplay=1&playsinline=1&rel=0'));
        }
      });
    });
  }

  /// Converts a YouTube watch / youtu.be / live URL to an embeddable URL.
  /// Non-YouTube links (e.g. Facebook Live embeds) pass through unchanged.
  static String? toEmbedUrl(String? url) {
    if (url == null || url.trim().isEmpty) return null;
    final trimmed = url.trim();

    final watch = RegExp(r'youtube\.com/watch\?.*[?&]v=([A-Za-z0-9_-]{6,})').firstMatch(trimmed);
    if (watch != null) return 'https://www.youtube.com/embed/${watch.group(1)}';

    final be = RegExp(r'youtu\.be/([A-Za-z0-9_-]{6,})').firstMatch(trimmed);
    if (be != null) return 'https://www.youtube.com/embed/${be.group(1)}';

    final path = RegExp(r'youtube\.com/(?:embed|live|shorts|v)/([A-Za-z0-9_-]{6,})').firstMatch(trimmed);
    if (path != null) return 'https://www.youtube.com/embed/${path.group(1)}';

    return trimmed;
  }

  @override
  Widget build(BuildContext context) {
    final s = _settings;
    if (s == null) return const Scaffold(body: LoadingView());
    final isLive = s.livestreamIsLive;

    return Scaffold(
      appBar: AppBar(title: const Text('Live')),
      body: ListView(
        padding: const EdgeInsets.all(24),
        children: [
          if (isLive)
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
              decoration: BoxDecoration(color: AppColors.danger.withValues(alpha: 0.15), borderRadius: BorderRadius.circular(20)),
              child: const Row(mainAxisSize: MainAxisSize.min, children: [
                Icon(Icons.circle, color: AppColors.danger, size: 10),
                SizedBox(width: 8),
                Text('LIVE NOW', style: TextStyle(color: AppColors.danger, fontWeight: FontWeight.w800, fontSize: 12)),
              ]),
            ),
          const SizedBox(height: 16),
          Text(isLive ? 'We Are Live' : 'Watch Online', style: Theme.of(context).textTheme.headlineMedium),
          const SizedBox(height: 8),
          Text(
            isLive ? "Join the service right now — glad you're here." : 'Check back at service time, or catch up on our channel.',
            style: const TextStyle(color: AppColors.inkDim),
          ),
          const SizedBox(height: 24),
          if (_embedUrl != null && _controller != null)
            ClipRRect(
              borderRadius: BorderRadius.circular(14),
              child: AspectRatio(
                aspectRatio: 16 / 9,
                child: WebViewWidget(controller: _controller!),
              ),
            )
          else
            const EmptyState(message: 'No stream configured yet.'),
          if (_embedUrl != null) ...[
            const SizedBox(height: 14),
            OutlinedButton.icon(
              onPressed: () => launchUrl(Uri.parse(_embedUrl!), mode: LaunchMode.externalApplication),
              icon: const Icon(Icons.open_in_new),
              label: const Text('Open in YouTube app'),
            ),
          ],
          if (s.serviceTimes.isNotEmpty) ...[
            const SizedBox(height: 30),
            Text('Service Times', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 12),
            ...s.serviceTimes.map((st) => Padding(
                  padding: const EdgeInsets.only(bottom: 10),
                  child: Row(children: [
                    Expanded(child: Text(st.label, style: const TextStyle(color: AppColors.inkDim))),
                    Text(st.time, style: const TextStyle(color: AppColors.goldSoft, fontWeight: FontWeight.w600)),
                  ]),
                )),
          ],
        ],
      ),
    );
  }
}
