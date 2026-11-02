import 'package:flutter/material.dart';

import '../config.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Opens the login screen if needed. True when the person is logged in afterwards.
Future<bool> ensureLoggedIn(BuildContext context) async {
  if (SessionScope.read(context).isLoggedIn) {
    return true;
  }
  final ok = await Navigator.of(context)
      .push<bool>(MaterialPageRoute(builder: (_) => const LoginScreen()));

  return ok == true;
}

/// Same login as the website: phone number, then the 6-digit SMS code. No passwords.
class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phone = TextEditingController();
  final _code = TextEditingController();
  final _name = TextEditingController();
  final _area = TextEditingController();

  String? _sentTo;
  bool _needsName = false;

  /// Where they live: asked at sign-up (and once from older accounts without it).
  bool _needsLocation = false;
  String? _district;
  String? _devCode;
  bool _busy = false;

  @override
  void dispose() {
    _phone.dispose();
    _code.dispose();
    _name.dispose();
    _area.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      final result =
          await SessionScope.read(context).sendCode(_phone.text.trim());
      setState(() {
        _sentTo = result['phone'] as String;
        _needsName = result['needs_name'] == true;
        _needsLocation = result['needs_location'] == true;
        _devCode = result['dev_code'] as String?;
      });
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _verify() async {
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).verify(_sentTo!, _code.text.trim(),
          name: _needsName ? _name.text.trim() : null,
          district: _needsLocation ? _district : null,
          area: _needsLocation ? _area.text.trim() : null);
      if (mounted) Navigator.of(context).pop(true);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final codeStep = _sentTo != null;

    return Scaffold(
      appBar: AppBar(title: const Text('Log in')),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        const Text(appName,
            style: TextStyle(
                fontSize: 32,
                fontWeight: FontWeight.w900,
                color: WiBuyColors.green)),
        const Text(appTagline,
            style: TextStyle(fontSize: 16, color: WiBuyColors.muted)),
        const SizedBox(height: 28),
        if (!codeStep) ...[
          const Text('Your phone number',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          const SizedBox(height: 8),
          TextField(
            controller: _phone,
            keyboardType: TextInputType.phone,
            autofocus: true,
            style: const TextStyle(fontSize: 20),
            decoration: const InputDecoration(
                hintText: '076 123 456', prefixIcon: Icon(Icons.phone)),
          ),
          const SizedBox(height: 8),
          const Text('We send a 6-digit code by SMS. No password needed.',
              style: TextStyle(color: WiBuyColors.muted)),
          const SizedBox(height: 20),
          FilledButton(
              onPressed: _busy ? null : _send,
              child: Text(_busy ? 'Sending…' : 'Send me a code')),
        ] else ...[
          Text('Enter the code we sent to ${_phone.text.trim()}',
              style:
                  const TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
          if (_devCode != null)
            Container(
              margin: const EdgeInsets.only(top: 10),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                  color: const Color(0xFFFEF3C7),
                  borderRadius: BorderRadius.circular(10)),
              child: Text(
                  'Development: no real SMS is sent. Your code is $_devCode'),
            ),
          const SizedBox(height: 10),
          TextField(
            controller: _code,
            keyboardType: TextInputType.number,
            maxLength: 6,
            autofocus: true,
            style: const TextStyle(fontSize: 26, letterSpacing: 8),
            decoration:
                const InputDecoration(hintText: '000000', counterText: ''),
          ),
          if (_needsName) ...[
            const SizedBox(height: 16),
            const Text('Your full name',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            TextField(
                controller: _name,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(hintText: 'Aminata Kamara')),
          ],
          if (_needsLocation) ...[
            const SizedBox(height: 16),
            const Text('Where do you live?',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
            const SizedBox(height: 8),
            DropdownButtonFormField<String>(
              isExpanded: true,
              initialValue: _district,
              decoration: const InputDecoration(labelText: 'District'),
              items: [
                for (final d in SessionScope.of(context).meta?.districts ??
                    const <String>[])
                  DropdownMenuItem(value: d, child: Text(d))
              ],
              onChanged: (d) => setState(() => _district = d),
            ),
            const SizedBox(height: 8),
            TextField(
                controller: _area,
                textCapitalization: TextCapitalization.words,
                decoration: const InputDecoration(
                    labelText: 'Town or area', hintText: 'Lumley, Bo town…')),
          ],
          const SizedBox(height: 20),
          FilledButton(
              onPressed: _busy ? null : _verify,
              child: Text(_busy ? 'Checking…' : 'Log in')),
          TextButton(
            onPressed: _busy ? null : () => setState(() => _sentTo = null),
            child: const Text('Change number or send a new code'),
          ),
        ],
      ]),
    );
  }
}
