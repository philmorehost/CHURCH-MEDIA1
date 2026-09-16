import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

/// Today's devotional, with the days around it one tap away.
///
/// Laid out like the website's page: the day in full, and an archive underneath for catching up
/// rather than for finding today. A day with nothing published says so plainly, because an empty
/// shell reads as a broken screen.
class DevotionalScreen extends StatefulWidget {
  /// The day to open on, as Y-m-d. Null means today.
  final String? date;

  const DevotionalScreen({super.key, this.date});

  @override
  State<DevotionalScreen> createState() => _DevotionalScreenState();
}

class _DevotionalScreenState extends State<DevotionalScreen> {
  final _api = ApiClient();
  String? _date;
  DevotionalDay? _day;
  bool _loading = true;

  static const _months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ];

  @override
  void initState() {
    super.initState();
    _date = widget.date;
    _load();
  }

  Future<void> _load() async {
    try {
      final day = await _api.fetchDevotional(date: _date);
      if (!mounted) return;
      setState(() {
        _day = day;
        // The server decides what "today" is, so a device with a wrong clock cannot ask for a day
        // the church has never heard of.
        _date = day.date;
        _loading = false;
      });
    } catch (_) {
      // _day stays null, which is how the view tells "nothing published" from "never arrived".
      if (mounted) setState(() => _loading = false);
    }
  }

  void _open(String date) {
    if (date == _date) return;
    setState(() {
      _date = date;
      _day = null;
      _loading = true;
    });
    _load();
  }

  /// Shifts a Y-m-d date by a number of days, for the previous/next links.
  String _shift(String date, int days) {
    final parsed = DateTime.tryParse(date);
    if (parsed == null) return date;
    final moved = parsed.add(Duration(days: days));
    return '${moved.year.toString().padLeft(4, '0')}-'
        '${moved.month.toString().padLeft(2, '0')}-'
        '${moved.day.toString().padLeft(2, '0')}';
  }

  String _friendly(String date) {
    final parsed = DateTime.tryParse(date);
    if (parsed == null) return date;
    return '${parsed.day} ${_months[parsed.month - 1]} ${parsed.year}';
  }

  @override
  Widget build(BuildContext context) {
    final day = _day;

    return Scaffold(
      appBar: AppBar(title: const Text('Daily Devotional')),
      body: _loading
          ? const LoadingView()
          : RefreshIndicator(
              onRefresh: _load,
              color: AppColors.gold,
              child: ListView(
                children: day == null ? _unreachable() : _content(day),
              ),
            ),
    );
  }

  /// Shown only when the request itself failed — a day with no devotional is `_day != null`.
  List<Widget> _unreachable() {
    return [
      const EmptyState(message: 'Could not reach the church just now.'),
      Center(
        child: TextButton(
          onPressed: _load,
          child: const Text('Try again'),
        ),
      ),
    ];
  }

  List<Widget> _content(DevotionalDay day) {
    final entry = day.entry;

    return [
      _header(day),
      if (entry != null)
        _entry(entry)
      else
        EmptyState(
          message: day.isToday
              ? 'No devotional has been written for today yet.'
              : 'There is no devotional for this day.',
        ),
      if (day.recent.isNotEmpty) ...[
        const SectionHeader(eyebrow: 'Catch up', title: 'Earlier Devotionals'),
        ...day.recent.map(_recentTile),
      ],
      const SizedBox(height: 36),
    ];
  }

  Widget _header(DevotionalDay day) {
    final date = day.date;
    if (date.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 18, 12, 0),
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  day.isToday ? 'TODAY' : 'DEVOTIONAL',
                  style: const TextStyle(
                    color: AppColors.goldSoft,
                    fontSize: 11,
                    fontWeight: FontWeight.w700,
                    letterSpacing: 1.2,
                  ),
                ),
                const SizedBox(height: 4),
                Text(_friendly(date), style: const TextStyle(color: AppColors.inkDim, fontSize: 13)),
              ],
            ),
          ),
          IconButton(
            tooltip: 'Previous day',
            onPressed: () => _open(_shift(date, -1)),
            icon: const Icon(Icons.chevron_left),
          ),
          // Disabled on today rather than hidden, so the pair does not shift about as the reader
          // moves through the days.
          IconButton(
            tooltip: 'Next day',
            onPressed: day.isToday ? null : () => _open(_shift(date, 1)),
            icon: const Icon(Icons.chevron_right),
          ),
        ],
      ),
    );
  }

  Widget _entry(Devotional entry) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 10, 20, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(entry.title, style: Theme.of(context).textTheme.headlineSmall),
          if (entry.scriptureReference.isNotEmpty) ...[
            const SizedBox(height: 10),
            Text(
              entry.scriptureReference.toUpperCase(),
              style: const TextStyle(
                color: AppColors.goldSoft,
                fontSize: 12,
                fontWeight: FontWeight.w700,
                letterSpacing: 1.0,
              ),
            ),
          ],
          if (entry.hasScriptureText) ...[
            const SizedBox(height: 16),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(16),
              decoration: BoxDecoration(
                color: AppColors.gold.withValues(alpha: 0.10),
                border: const Border(left: BorderSide(color: AppColors.gold, width: 3)),
                borderRadius: const BorderRadius.only(
                  topRight: Radius.circular(10),
                  bottomRight: Radius.circular(10),
                ),
              ),
              child: Text(
                entry.scriptureText,
                style: const TextStyle(fontStyle: FontStyle.italic, height: 1.65),
              ),
            ),
          ],
          if (entry.hasBody) ...[
            const SizedBox(height: 18),
            Text(entry.body, style: const TextStyle(fontSize: 16, height: 1.7)),
          ],
        ],
      ),
    );
  }

  Widget _recentTile(Devotional entry) {
    final subtitle = entry.scriptureReference.isNotEmpty
        ? '${_friendly(entry.publishOn)} · ${entry.scriptureReference}'
        : _friendly(entry.publishOn);

    return ListTile(
      title: Text(entry.title, maxLines: 2, overflow: TextOverflow.ellipsis),
      subtitle: Text(subtitle),
      trailing: const Icon(Icons.chevron_right),
      onTap: () => _open(entry.publishOn),
    );
  }
}
