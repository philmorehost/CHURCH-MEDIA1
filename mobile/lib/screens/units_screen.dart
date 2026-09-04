import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import 'unit_screen.dart';

/// "Find Your Parish" — browse the Province → Zone → Area → Parish hierarchy
/// and open any unit's media gallery.
class UnitsScreen extends StatefulWidget {
  const UnitsScreen({super.key});

  @override
  State<UnitsScreen> createState() => _UnitsScreenState();
}

class _UnitsScreenState extends State<UnitsScreen> {
  final _api = ApiClient();
  List<UnitInfo> _units = [];
  bool _loading = true;
  String _error = '';

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    try {
      final units = await _api.fetchUnits();
      if (mounted) {
        setState(() {
          _units = units;
          _loading = false;
        });
      }
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _error = 'Could not load parishes.';
        });
      }
    }
  }

  Map<int, List<UnitInfo>> get _childrenByParent {
    final map = <int, List<UnitInfo>>{};
    for (final u in _units) {
      final p = u.parentId;
      map.putIfAbsent(p ?? 0, () => []).add(u);
    }
    for (final list in map.values) {
      list.sort((a, b) => a.name.toLowerCase().compareTo(b.name.toLowerCase()));
    }
    return map;
  }

  List<String> _pathFor(UnitInfo unit) {
    final byId = {for (final u in _units) u.id: u};
    final path = <String>[unit.name];
    var cur = unit;
    while (cur.parentId != null && byId.containsKey(cur.parentId!)) {
      cur = byId[cur.parentId!]!;
      path.insert(0, cur.name);
    }
    return path;
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Find Your Parish'), centerTitle: true),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error.isNotEmpty
              ? Center(child: Text(_error))
              : _units.isEmpty
                  ? const Center(child: Text('No parishes set up yet.'))
                  : _buildTree(_childrenByParent),
    );
  }

  Widget _buildTree(Map<int, List<UnitInfo>> children) {
    final roots = children[0] ?? [];
    if (roots.isEmpty) {
      return const Center(child: Text('No parishes set up yet.'));
    }
    return ListView(
      padding: const EdgeInsets.symmetric(vertical: 8),
      children: [for (final province in roots) _nodeTile(province, children, 0)],
    );
  }

  Widget _nodeTile(UnitInfo unit, Map<int, List<UnitInfo>> children, int depth) {
    final kids = children[unit.id] ?? [];
    final isLeaf = kids.isEmpty;
    final pad = EdgeInsets.only(left: 8.0 + depth * 20, top: 2, bottom: 2, right: 8);
    final icon = switch (unit.type) {
      'province' => Icons.account_balance,
      'zone' => Icons.location_city,
      'area' => Icons.map,
      _ => Icons.church,
    };

    if (isLeaf) {
      return ListTile(
        dense: true,
        contentPadding: pad,
        leading: Icon(icon, size: 20, color: AppColors.goldSoft),
        title: Text(unit.name, style: const TextStyle(fontWeight: FontWeight.w600)),
        trailing: const Icon(Icons.chevron_right, size: 18, color: AppColors.inkFaint),
        onTap: () => _openUnit(unit),
      );
    }

    return Theme(
      data: Theme.of(context).copyWith(dividerColor: Colors.transparent),
      child: ExpansionTile(
        leading: Icon(icon, size: 20, color: AppColors.goldSoft),
        title: Text(unit.name, style: const TextStyle(fontWeight: FontWeight.w700)),
        initiallyExpanded: depth == 0,
        children: [for (final kid in kids) _nodeTile(kid, children, depth + 1)],
      ),
    );
  }

  void _openUnit(UnitInfo unit) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => UnitScreen(
          unitSlug: unit.slug,
          unitName: unit.name,
          unitPath: _pathFor(unit),
        ),
      ),
    );
  }
}
