import 'package:flutter/material.dart';
import '../theme/app_theme.dart';
import 'about_screen.dart';
import 'contact_screen.dart';
import 'events_screen.dart';
import 'give_screen.dart';
import 'live_screen.dart';
import 'prayer_screen.dart';
import 'search_screen.dart';

class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final items = <_MoreItem>[
      _MoreItem('Events', Icons.event, const EventsScreen()),
      _MoreItem('Live', Icons.live_tv, const LiveScreen()),
      _MoreItem('Prayer Wall', Icons.favorite_outline, const PrayerScreen()),
      _MoreItem('About Us', Icons.info_outline, const AboutScreen()),
      _MoreItem('Contact', Icons.mail_outline, const ContactScreen()),
      _MoreItem('Give', Icons.volunteer_activism_outlined, const GiveScreen()),
      _MoreItem('Search', Icons.search, const SearchScreen()),
    ];

    return Scaffold(
      appBar: AppBar(title: const Text('More')),
      body: ListView.separated(
        padding: const EdgeInsets.all(16),
        itemCount: items.length,
        separatorBuilder: (_, __) => const SizedBox(height: 10),
        itemBuilder: (context, i) {
          final item = items[i];
          return Card(
            child: ListTile(
              leading: Icon(item.icon, color: AppColors.gold),
              title: Text(item.label),
              trailing: const Icon(Icons.chevron_right, color: AppColors.inkFaint),
              onTap: () => Navigator.push(context, MaterialPageRoute(builder: (_) => item.screen)),
            ),
          );
        },
      ),
    );
  }
}

class _MoreItem {
  final String label;
  final IconData icon;
  final Widget screen;
  _MoreItem(this.label, this.icon, this.screen);
}
