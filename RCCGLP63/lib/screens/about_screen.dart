import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

class AboutScreen extends StatefulWidget {
  const AboutScreen({super.key});
  @override
  State<AboutScreen> createState() => _AboutScreenState();
}

class _AboutScreenState extends State<AboutScreen> {
  final _api = ApiClient();
  ChurchSettings? _settings;
  List<TeamMember> _team = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    Future.wait([_api.fetchSettings(), _api.fetchTeam()]).then((r) {
      if (!mounted) return;
      setState(() {
        _settings = r[0] as ChurchSettings;
        _team = r[1] as List<TeamMember>;
        _loading = false;
      });
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('About Us')),
      body: _loading
          ? const LoadingView()
          : ListView(
              padding: const EdgeInsets.all(24),
              children: [
                Text('About ${_settings?.siteTitle ?? ''}', style: Theme.of(context).textTheme.headlineMedium),
                const SizedBox(height: 10),
                Text(_settings?.siteTagline ?? '', style: const TextStyle(color: AppColors.inkDim, fontStyle: FontStyle.italic)),
                const SizedBox(height: 24),
                _infoCard('Our Mission', 'To lead people into a growing relationship with God, build authentic community, and serve our city with the love of Christ.'),
                const SizedBox(height: 14),
                _infoCard('Our Vision', 'A church without walls — reaching every generation, in the room and online, with hope that lasts.'),
                if (_team.isNotEmpty) ...[
                  const SizedBox(height: 30),
                  Text('Leadership', style: Theme.of(context).textTheme.titleLarge),
                  const SizedBox(height: 14),
                  ..._team.map((m) => Padding(
                        padding: const EdgeInsets.only(bottom: 14),
                        child: Row(children: [
                          CircleAvatar(
                            radius: 28,
                            backgroundColor: AppColors.bg2,
                            backgroundImage: m.photoUrl != null ? CachedNetworkImageProvider(m.photoUrl!) : null,
                          ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(m.name, style: const TextStyle(fontWeight: FontWeight.w700)),
                              if (m.roleTitle != null) Text(m.roleTitle!, style: const TextStyle(color: AppColors.goldSoft, fontSize: 12.5)),
                            ]),
                          ),
                        ]),
                      )),
                ],
              ],
            ),
    );
  }

  Widget _infoCard(String title, String body) => Card(
        child: Padding(
          padding: const EdgeInsets.all(18),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
            const SizedBox(height: 8),
            Text(body, style: const TextStyle(color: AppColors.inkDim, height: 1.5)),
          ]),
        ),
      );
}
