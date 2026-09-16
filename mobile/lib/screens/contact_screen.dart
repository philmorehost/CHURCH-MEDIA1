import 'package:flutter/material.dart';
import '../services/api_client.dart';
import '../theme/app_theme.dart';

class ContactScreen extends StatefulWidget {
  const ContactScreen({super.key});
  @override
  State<ContactScreen> createState() => _ContactScreenState();
}

class _ContactScreenState extends State<ContactScreen> {
  final _api = ApiClient();
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _email = TextEditingController();
  final _subject = TextEditingController();
  final _message = TextEditingController();
  bool _sending = false;
  String? _resultMessage;
  bool _resultOk = true;

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _sending = true;
      _resultMessage = null;
    });
    try {
      final msg = await _api.sendContactMessage(name: _name.text, email: _email.text, subject: _subject.text, message: _message.text);
      setState(() {
        _resultOk = true;
        _resultMessage = msg;
      });
      _formKey.currentState!.reset();
      _name.clear();
      _email.clear();
      _subject.clear();
      _message.clear();
    } catch (_) {
      setState(() {
        _resultOk = false;
        _resultMessage = 'Network error — please try again.';
      });
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Contact')),
      body: Padding(
        padding: const EdgeInsets.all(24),
        child: Form(
          key: _formKey,
          child: ListView(
            children: [
              if (_resultMessage != null)
                Container(
                  padding: const EdgeInsets.all(14),
                  margin: const EdgeInsets.only(bottom: 16),
                  decoration: BoxDecoration(
                    color: (_resultOk ? AppColors.success : AppColors.danger).withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: (_resultOk ? AppColors.success : AppColors.danger).withValues(alpha: 0.3)),
                  ),
                  child: Text(_resultMessage!, style: TextStyle(color: _resultOk ? AppColors.success : AppColors.danger)),
                ),
              TextFormField(controller: _name, decoration: const InputDecoration(labelText: 'Name'), validator: (v) => v == null || v.isEmpty ? 'Required' : null),
              const SizedBox(height: 14),
              TextFormField(controller: _email, decoration: const InputDecoration(labelText: 'Email'), keyboardType: TextInputType.emailAddress, validator: (v) => v == null || !v.contains('@') ? 'Enter a valid email' : null),
              const SizedBox(height: 14),
              TextFormField(controller: _subject, decoration: const InputDecoration(labelText: 'Subject (optional)')),
              const SizedBox(height: 14),
              TextFormField(controller: _message, decoration: const InputDecoration(labelText: 'Message'), maxLines: 5, validator: (v) => v == null || v.isEmpty ? 'Required' : null),
              const SizedBox(height: 22),
              ElevatedButton(
                onPressed: _sending ? null : _submit,
                child: _sending ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2)) : const Text('Send Message'),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
