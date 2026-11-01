import 'dart:async';

import 'package:flutter/material.dart';

import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import 'common.dart';

/// Ask for an operator and number, for payments outside checkout (balance, inspection, import).
Future<({String operator, String phone})?> askPayer(BuildContext context, String title, String amount) {
  final session = SessionScope.read(context);
  final phone = TextEditingController(text: displayPhone(session.user?.phone));
  var operator = 'ORANGE';
  final operators = session.meta?.operators ?? const [MapEntry('ORANGE', 'Orange Money'), MapEntry('AFRIMONEY', 'Afrimoney')];

  return showModalBottomSheet<({String operator, String phone})>(
    context: context,
    isScrollControlled: true,
    builder: (context) => StatefulBuilder(
      builder: (context, setState) => Padding(
        padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Text(title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          Text('Amount: ${formatLe(amount)}', style: const TextStyle(color: WiBuyColors.green, fontWeight: FontWeight.w800)),
          const SizedBox(height: 10),
          SegmentedButton<String>(
            segments: [for (final o in operators) ButtonSegment(value: o.key, label: Text(o.value))],
            selected: {operator},
            onSelectionChanged: (s) => setState(() => operator = s.first),
          ),
          const SizedBox(height: 10),
          TextField(controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Mobile money number')),
          const SizedBox(height: 12),
          FilledButton(onPressed: () => Navigator.pop(context, (operator: operator, phone: phone.text.trim())), child: Text('Pay ${formatLe(amount)}')),
        ]),
      ),
    ),
  );
}

/// "Approve on your phone" for a charge payment. Polls until it finishes. Returns true when paid.
Future<bool> waitForCharge(BuildContext context, int paymentId) async {
  final result = await showDialog<bool>(context: context, barrierDismissible: false, builder: (_) => _ChargeDialog(paymentId));
  return result == true;
}

class _ChargeDialog extends StatefulWidget {
  const _ChargeDialog(this.paymentId);

  final int paymentId;

  @override
  State<_ChargeDialog> createState() => _ChargeDialogState();
}

class _ChargeDialogState extends State<_ChargeDialog> {
  Timer? _timer;
  bool _canSimulate = false;

  @override
  void initState() {
    super.initState();
    _check();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _check());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _check([String? simulate]) async {
    final api = SessionScope.read(context).api;
    try {
      final json = (simulate == null
          ? await api.get('/payments/${widget.paymentId}')
          : await api.post('/payments/${widget.paymentId}/simulate', {'outcome': simulate})) as Map<String, dynamic>;
      if (!mounted) return;
      setState(() => _canSimulate = json['can_simulate'] == true);
      if (json['status'] != 'pending') {
        Navigator.pop(context, json['status'] == 'completed');
      }
    } on Object catch (e) {
      if (mounted && simulate != null) showMessage(context, e);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
        title: const Text('Approve on your phone'),
        content: const Column(mainAxisSize: MainAxisSize.min, children: [
          CircularProgressIndicator(),
          SizedBox(height: 12),
          Text('Enter your PIN in the mobile money prompt on your phone.'),
        ]),
        actions: [
          if (_canSimulate) TextButton(onPressed: () => _check('decline'), child: const Text('Simulate decline')),
          if (_canSimulate) FilledButton(onPressed: () => _check('approve'), child: const Text('Simulate approve')),
          if (!_canSimulate) TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Close')),
        ],
      );
}
