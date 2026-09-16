import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

class GiveScreen extends StatefulWidget {
  const GiveScreen({super.key});
  @override
  State<GiveScreen> createState() => _GiveScreenState();
}

class _GiveScreenState extends State<GiveScreen> {
  ChurchSettings? _settings;

  @override
  void initState() {
    super.initState();
    ApiClient().fetchSettings().then((s) {
      if (mounted) setState(() => _settings = s);
    });
  }

  @override
  Widget build(BuildContext context) {
    final givingUrl = _settings?.givingUrl;
    return Scaffold(
      appBar: AppBar(title: const Text('Give')),
      body: _settings == null
          ? const LoadingView()
          : ListView(
              padding: const EdgeInsets.all(24),
              children: [
                Text('Give Online', style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 12),
                const Text(
                  '"Each of you should give what you have decided in your heart to give, not reluctantly or under compulsion, for God loves a cheerful giver." — 2 Corinthians 9:7',
                  style: TextStyle(color: AppColors.inkDim, fontStyle: FontStyle.italic, height: 1.5),
                ),
                const SizedBox(height: 26),
                ElevatedButton(
                  onPressed: givingUrl != null ? () => launchUrl(Uri.parse(givingUrl), mode: LaunchMode.externalApplication) : null,
                  child: Text(givingUrl != null ? 'Give Now ↗' : 'Giving link not configured yet'),
                ),
                const SizedBox(height: 30),
                _card('Tithes & Offerings', 'Support the ongoing life and ministry of our church family.'),
                const SizedBox(height: 12),
                _card('Missions', 'Help take the message beyond our walls, near and far.'),
                const SizedBox(height: 12),
                _card('Building Fund', 'Invest in spaces where lives are changed for generations.'),
              ],
            ),
    );
  }

  Widget _card(String title, String body) => Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text(body, style: const TextStyle(color: AppColors.inkDim, fontSize: 13)),
          ]),
        ),
      );
}
