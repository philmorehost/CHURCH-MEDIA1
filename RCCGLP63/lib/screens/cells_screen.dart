import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'unit_screen.dart';

/// "Home Cells" — the midweek gatherings, from /api/cells.
///
/// Search rather than a dropdown. This organisation has hundreds of units and a
/// picker listing them all is unusable on a phone — the same reason the member
/// dashboard asks people to type their church instead of choosing it. The
/// website can narrow by branch of the hierarchy because it has the width for a
/// select; here, typing is the filter that actually gets used.
class CellsScreen extends StatefulWidget {
  const CellsScreen({super.key});

  @override
  State<CellsScreen> createState() => _CellsScreenState();
}

class _CellsScreenState extends State<CellsScreen> {
  final _api = ApiClient();
  final _search = TextEditingController();
  List<HomeCellInfo> _cells = const [];
  bool _loading = true;
  String _error = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = '';
    });
    try {
      final cells = await _api.fetchCells(query: _search.text);
      if (!mounted) return;
      setState(() {
        _cells = cells;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _loading = false;
        _error = "Couldn't load the home cells";
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Home Cells'), centerTitle: true),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: TextField(
              controller: _search,
              textInputAction: TextInputAction.search,
              onSubmitted: (_) => _load(),
              decoration: InputDecoration(
                hintText: 'Leader, street or area',
                prefixIcon: const Icon(Icons.search, size: 20),
                isDense: true,
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
              ),
            ),
          ),
          Expanded(child: _body()),
        ],
      ),
    );
  }

  Widget _body() {
    if (_loading) return const LoadingView();

    if (_error.isNotEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Text(_error, textAlign: TextAlign.center, style: const TextStyle(color: AppColors.inkDim)),
            const SizedBox(height: 14),
            OutlinedButton(onPressed: _load, child: const Text('Try again')),
          ]),
        ),
      );
    }

    if (_cells.isEmpty) {
      // Two different emptinesses, and telling them apart matters: a church
      // that has listed nothing needs an invitation, not a search suggestion.
      return EmptyState(
        message: _search.text.trim().isEmpty
            ? 'No home cells have been listed yet.\n\n'
                'In the meantime, get in touch through the app and we will help you find your people.'
            : 'No home cells match that.\n\n'
                'Try a different search, or get in touch — there is almost certainly one closer than this list suggests.',
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.fromLTRB(16, 8, 16, 28),
      itemCount: _cells.length,
      itemBuilder: (context, index) => _card(_cells[index]),
    );
  }

  Widget _card(HomeCellInfo cell) {
    final when = cell.whenLabel;
    final address = cell.meetingAddress;
    final leader = cell.leaderName;
    final phone = cell.leaderPhone;
    final capacity = cell.capacityLabel;
    final slug = cell.slug;

    return Container(
      margin: const EdgeInsets.only(bottom: 12),
      decoration: BoxDecoration(
        color: AppColors.panel,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        // Opens the church's media page, so somebody who has just found their
        // cell can go straight on to listening to what that church has posted.
        onTap: slug == null || slug.isEmpty
            ? null
            : () => Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => UnitScreen(
                      unitSlug: slug,
                      unitName: cell.name,
                      unitPath: cell.pathParts,
                      unitId: cell.id,
                    ),
                  ),
                ),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(cell.name, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16)),
            if (cell.pathLabel.isNotEmpty) ...[
              const SizedBox(height: 2),
              Text(cell.pathLabel, style: const TextStyle(color: AppColors.inkFaint, fontSize: 12)),
            ],
            if (when != null || address != null || leader != null || capacity != null) const SizedBox(height: 12),
            if (when != null) _detail(Icons.event_outlined, when),
            if (address != null) _detail(Icons.place_outlined, address),
            if (leader != null) _detail(Icons.person_outline, leader),
            if (capacity != null) _detail(Icons.groups_outlined, capacity),
            if (phone != null) ...[
              const SizedBox(height: 10),
              Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    onPressed: () => launchUrl(Uri.parse('tel:$phone')),
                    icon: const Icon(Icons.call, size: 18),
                    label: Text(cell.leaderPhoneDisplay ?? phone),
                  ),
                ),
                const SizedBox(width: 10),
                OutlinedButton(
                  // wa.me rather than any WhatsApp API — no account, no token,
                  // and it works from the phone's own WhatsApp.
                  onPressed: () => launchUrl(
                    Uri.parse('https://wa.me/$phone'),
                    mode: LaunchMode.externalApplication,
                  ),
                  child: const Text('WhatsApp'),
                ),
              ]),
            ],
          ]),
        ),
      ),
    );
  }

  Widget _detail(IconData icon, String text) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Padding(
            padding: const EdgeInsets.only(top: 1),
            child: Icon(icon, size: 16, color: AppColors.goldSoft),
          ),
          const SizedBox(width: 9),
          Expanded(
            child: Text(text, style: const TextStyle(color: AppColors.inkDim, fontSize: 13.5, height: 1.4)),
          ),
        ]),
      );
}
