import 'dart:async';

import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';

/// WiBuy Pass: one month at a time by mobile money. Never charged automatically.
class PassScreen extends StatefulWidget {
  const PassScreen({super.key});

  @override
  State<PassScreen> createState() => _PassScreenState();
}

class _PassScreenState extends State<PassScreen> {
  int _reload = 0;
  String _operator = 'ORANGE';
  late final _phone = TextEditingController(text: displayPhone(SessionScope.read(context).user?.phone));
  MembershipInfo? _pending;
  Timer? _poll;

  @override
  void dispose() {
    _poll?.cancel();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _buy() async {
    try {
      final json = await SessionScope.read(context).api.post('/pass', {'operator': _operator, 'payer_phone': _phone.text.trim()}) as Map<String, dynamic>;
      setState(() => _pending = MembershipInfo.fromJson(json['data'] as Map<String, dynamic>));
      _poll = Timer.periodic(const Duration(seconds: 5), (_) => _check());
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  Future<void> _check([String? simulate]) async {
    final api = SessionScope.read(context).api;
    try {
      final json = simulate == null
          ? await api.get('/pass/${_pending!.id}')
          : await api.post('/pass/${_pending!.id}/simulate', {'outcome': simulate});
      final membership = MembershipInfo.fromJson((json as Map<String, dynamic>)['data'] as Map<String, dynamic>);
      if (!mounted) return;
      if (membership.status != 'pending') {
        _poll?.cancel();
        showMessage(context, membership.status == 'active' ? 'Welcome to WiBuy Pass! 🎉' : 'The payment did not go through. Nothing was taken.');
        setState(() {
          _pending = null;
          _reload++;
        });
      }
    } on Object catch (e) {
      if (mounted && simulate != null) showMessage(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;
    final operators = SessionScope.of(context).meta?.operators ?? const [MapEntry('ORANGE', 'Orange Money'), MapEntry('AFRIMONEY', 'Afrimoney')];

    return Scaffold(
      appBar: AppBar(title: const Text('⭐ WiBuy Pass')),
      body: Loader<Map<String, dynamic>>(
        key: ValueKey(_reload),
        load: () async => await api.get('/pass') as Map<String, dynamic>,
        builder: (context, json, _) {
          final active = json['active'] is Map<String, dynamic> ? MembershipInfo.fromJson(json['active'] as Map<String, dynamic>) : null;
          final benefits = (json['benefits'] as List).map((b) => b.toString()).toList();

          return ListView(padding: const EdgeInsets.all(16), children: [
            Text('${formatLe(json['price'] as String)} for ${json['days']} days', style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w900, color: WiBuyColors.green)),
            const SizedBox(height: 8),
            for (final benefit in benefits) ListTile(dense: true, contentPadding: EdgeInsets.zero, leading: const Icon(Icons.check, color: WiBuyColors.green), title: Text(benefit)),
            const SizedBox(height: 10),
            if (active != null)
              Card(
                color: WiBuyColors.greenLight,
                child: ListTile(
                  leading: const Icon(Icons.verified, color: WiBuyColors.green),
                  title: const Text('Your pass is active', style: TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text('Until ${active.endsAt?.day}/${active.endsAt?.month}/${active.endsAt?.year}. You can add another month any time.'),
                ),
              ),
            const SizedBox(height: 10),
            if (_pending != null) ...[
              const Center(child: CircularProgressIndicator()),
              const SizedBox(height: 10),
              Text('Approve ${formatLe(_pending!.price)} on ${displayPhone(_pending!.payerPhone)} (${_pending!.operatorLabel}).', textAlign: TextAlign.center),
              if (_pending!.canSimulate) ...[
                const SizedBox(height: 10),
                FilledButton(onPressed: () => _check('approve'), child: const Text('Simulate approve (development)')),
                OutlinedButton(onPressed: () => _check('decline'), child: const Text('Simulate decline')),
              ],
            ] else ...[
              SegmentedButton<String>(
                segments: [for (final o in operators) ButtonSegment(value: o.key, label: Text(o.value))],
                selected: {_operator},
                onSelectionChanged: (s) => setState(() => _operator = s.first),
              ),
              const SizedBox(height: 10),
              TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Mobile money number')),
              const SizedBox(height: 12),
              FilledButton(onPressed: _buy, child: Text(active == null ? 'Join for ${formatLe(json['price'] as String)}' : 'Add a month')),
            ],
          ]);
        },
      ),
    );
  }
}
