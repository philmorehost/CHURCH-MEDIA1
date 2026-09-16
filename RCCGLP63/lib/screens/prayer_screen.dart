import 'package:flutter/material.dart';
import '../models/models.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';
import '../widgets/common.dart';

class PrayerScreen extends StatefulWidget {
  const PrayerScreen({super.key});
  @override
  State<PrayerScreen> createState() => _PrayerScreenState();
}

class _PrayerScreenState extends State<PrayerScreen> {
  final _api = ApiClient();
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _message = TextEditingController();
  bool _isPublic = false;
  bool _sending = false;
  String? _resultMessage;

  List<PrayerRequest> _prayers = [];
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _loadPrayers();
  }

  Future<void> _loadPrayers() async {
    setState(() => _loading = true);
    try {
      final prayers = await _api.fetchPublicPrayers();
      setState(() {
        _prayers = prayers;
        _loading = false;
      });
    } catch (_) {
      if (mounted) setState(() => _loading = false);
    }
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _sending = true;
      _resultMessage = null;
    });
    try {
      final msg = await _api.submitPrayer(name: _name.text, email: _email.text, message: _message.text, isPublic: _isPublic);
      setState(() => _resultMessage = msg);
      _name.clear();
      _email.clear();
      _message.clear();
      _formKey.currentState!.reset();
      _loadPrayers();
    } catch (_) {
      setState(() => _resultMessage = 'Network error — please try again.');
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Prayer Wall')),
      body: RefreshIndicator(
        onRefresh: _loadPrayers,
        child: ListView(
          padding: const EdgeInsets.all(24),
          children: [
            Text('We\'re With You', style: Theme.of(context).textTheme.headlineMedium),
            const SizedBox(height: 8),
            const Text('Share a request — our team prays over every submission.', style: TextStyle(color: AppColors.inkDim)),
            const SizedBox(height: 20),
            Form(
              key: _formKey,
              child: Card(
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(children: [
                    if (_resultMessage != null)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 14),
                        child: Text(_resultMessage!, style: const TextStyle(color: AppColors.success)),
                      ),
                    TextFormField(controller: _name, decoration: const InputDecoration(labelText: 'Name (optional)')),
                    const SizedBox(height: 12),
                    TextFormField(controller: _email, decoration: const InputDecoration(labelText: 'Email (optional)')),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: _message,
                      decoration: const InputDecoration(labelText: 'Your Prayer Request'),
                      maxLines: 4,
                      validator: (v) => v == null || v.isEmpty ? 'Required' : null,
                    ),
                    CheckboxListTile(
                      value: _isPublic,
                      onChanged: (v) => setState(() => _isPublic = v ?? false),
                      title: const Text('Share on the public prayer wall', style: TextStyle(fontSize: 13)),
                      controlAffinity: ListTileControlAffinity.leading,
                      contentPadding: EdgeInsets.zero,
                    ),
                    const SizedBox(height: 8),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: _sending ? null : _submit,
                        child: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Submit Request'),
                      ),
                    ),
                  ]),
                ),
              ),
            ),
            const SizedBox(height: 30),
            Text('Praying Together', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 14),
            if (_loading)
              const LoadingView()
            else if (_prayers.isEmpty)
              const EmptyState(message: 'No public prayers yet — be the first to share.')
            else
              ..._prayers.map((p) => Card(
                    margin: const EdgeInsets.only(bottom: 10),
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                        Text('"${p.message}"', style: const TextStyle(color: AppColors.inkDim)),
                        const SizedBox(height: 8),
                        Text('— ${p.name ?? 'Anonymous'} · ${_timeAgo(p.createdAt)}', style: const TextStyle(color: AppColors.inkFaint, fontSize: 11)),
                      ]),
                    ),
                  )),
          ],
        ),
      ),
    );
  }

  String _timeAgo(String iso) {
    final dt = DateTime.tryParse(iso);
    if (dt == null) return '';
    final diff = DateTime.now().difference(dt);
    if (diff.inDays > 0) return '${diff.inDays}d ago';
    if (diff.inHours > 0) return '${diff.inHours}h ago';
    if (diff.inMinutes > 0) return '${diff.inMinutes}m ago';
    return 'just now';
  }
}
