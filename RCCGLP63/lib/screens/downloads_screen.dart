import 'package:flutter/material.dart';

import '../services/download_store.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';
import 'sermon_detail_screen.dart';

/// Everything saved on this device: what it is costing in space, and how to get
/// that space back.
class DownloadsScreen extends StatefulWidget {
  const DownloadsScreen({super.key});
  @override
  State<DownloadsScreen> createState() => _DownloadsScreenState();
}

class _DownloadsScreenState extends State<DownloadsScreen> {
  final _store = DownloadStore.instance;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _store.init();
  }

  Future<void> _confirmWipe() async {
    final count = _store.items.length;
    final ok = await confirmDestructive(
      context,
      title: 'Delete all downloads?',
      body: 'This removes $count saved message${count == 1 ? '' : 's'} '
          '(${_store.totalLabel}) from this device. They can be downloaded again, '
          'but that uses data.',
      action: 'Delete all',
    );
    if (!ok || !mounted) return;
    setState(() => _busy = true);
    await _store.wipeAll();
    if (mounted) setState(() => _busy = false);
  }

  Future<void> _confirmRemove(DownloadedSermon item) async {
    final ok = await confirmDestructive(
      context,
      title: 'Delete this download?',
      body: '"${item.title}" (${item.sizeLabel}) will be removed from this device. '
          'Downloading it again uses data.',
      action: 'Delete',
    );
    if (!ok || !mounted) return;
    await _store.remove(item.slug);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Downloads')),
      body: ListenableBuilder(
        listenable: _store,
        builder: (context, _) {
          final items = _store.items;
          if (items.isEmpty) {
            return const EmptyState(
              message: 'Nothing saved yet.\n\n'
                  'Open a sermon and tap Download to keep it on this device — '
                  'it then plays with no connection at all.',
            );
          }
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
            children: [
              _summary(items.length),
              const SizedBox(height: 18),
              for (final item in items) _tile(item),
            ],
          );
        },
      ),
    );
  }

  Widget _summary(int count) {
    return Container(
      padding: const EdgeInsets.fromLTRB(16, 14, 8, 14),
      decoration: BoxDecoration(
        color: AppColors.panel,
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(children: [
        const Icon(Icons.sd_storage_outlined, color: AppColors.gold, size: 30),
        const SizedBox(width: 14),
        Expanded(
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('$count message${count == 1 ? '' : 's'} saved',
                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
            const SizedBox(height: 2),
            Text('${_store.totalLabel} on this device',
                style: const TextStyle(color: AppColors.inkFaint, fontSize: 13)),
          ]),
        ),
        TextButton(
          onPressed: _busy ? null : _confirmWipe,
          child: _busy
              ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: AppColors.gold))
              : const Text('Delete all', style: TextStyle(color: AppColors.danger)),
        ),
      ]),
    );
  }

  Widget _tile(DownloadedSermon item) {
    final parts = <String>[
      if (item.speaker != null && item.speaker!.isNotEmpty) item.speaker!,
      if (item.series != null && item.series!.isNotEmpty) item.series!,
      item.sizeLabel,
    ];
    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      decoration: BoxDecoration(
        color: AppColors.panel,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: AppColors.border),
      ),
      child: ListTile(
        contentPadding: const EdgeInsets.fromLTRB(14, 4, 4, 4),
        leading: const Icon(Icons.play_circle_outline, color: AppColors.gold, size: 28),
        title: Text(item.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w600)),
        subtitle: Padding(
          padding: const EdgeInsets.only(top: 3),
          child: Text(parts.join(' · '), style: const TextStyle(color: AppColors.inkFaint, fontSize: 12.5)),
        ),
        trailing: IconButton(
          tooltip: 'Delete download',
          icon: const Icon(Icons.delete_outline, color: AppColors.inkFaint),
          onPressed: () => _confirmRemove(item),
        ),
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(builder: (_) => SermonDetailScreen(slug: item.slug)),
        ),
      ),
    );
  }
}
