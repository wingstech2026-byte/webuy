import 'package:flutter/material.dart';

import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// "My details": the buyer's name and where they live. The phone number is the login (checked by SMS).
class MyDetailsScreen extends StatefulWidget {
  const MyDetailsScreen({super.key});

  @override
  State<MyDetailsScreen> createState() => _MyDetailsScreenState();
}

class _MyDetailsScreenState extends State<MyDetailsScreen> {
  late final _user = SessionScope.read(context).user;
  late final _name = TextEditingController(text: _user?.name ?? '');
  late final _area = TextEditingController(text: _user?.area ?? '');
  late String? _district = _user?.district;
  bool _busy = false;

  @override
  void dispose() {
    _name.dispose();
    _area.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_district == null) {
      showMessage(context, 'Please choose the district where you live.');
      return;
    }
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).updateDetails(
          name: _name.text.trim(),
          district: _district!,
          area: _area.text.trim());
      if (!mounted) return;
      showMessage(context, 'Your details are saved.');
      Navigator.of(context).pop(true);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('My details')),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          Text('Phone: ${_user?.displayPhone ?? ''}',
              style: const TextStyle(fontWeight: FontWeight.w700)),
          const Text('Checked by SMS; it is your login.',
              style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
          const SizedBox(height: 14),
          TextField(
              controller: _name,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Full name')),
          const SizedBox(height: 10),
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
          const SizedBox(height: 10),
          TextField(
              controller: _area,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(
                  labelText: 'Town or area', hintText: 'Lumley, Bo town…')),
          const SizedBox(height: 18),
          FilledButton(
              onPressed: _busy ? null : _save,
              child: Text(_busy ? 'Saving…' : 'Save')),
        ]),
      );
}
