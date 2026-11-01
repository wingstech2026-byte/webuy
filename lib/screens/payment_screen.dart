import 'dart:async';

import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'order_detail_screen.dart';

/// "Approve on your phone" screen. Polls the checkout every 5 seconds until paid or failed.
class PaymentScreen extends StatefulWidget {
  const PaymentScreen({super.key, required this.initial});

  final CheckoutInfo initial;

  @override
  State<PaymentScreen> createState() => _PaymentScreenState();
}

class _PaymentScreenState extends State<PaymentScreen> {
  late CheckoutInfo _checkout = widget.initial;
  Timer? _timer;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 5), (_) => _refresh());
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> _call(Future<dynamic> Function() request) async {
    try {
      final json = await request() as Map<String, dynamic>;
      if (!mounted) return;
      setState(() => _checkout = CheckoutInfo.fromJson(json['data'] as Map<String, dynamic>));
      if (_checkout.payment != 'waiting') {
        _timer?.cancel();
      }
    } on Object catch (e) {
      if (mounted && _busy) showMessage(context, e);
    }
  }

  Future<void> _refresh() => _call(() => SessionScope.read(context).api.get('/checkouts/${_checkout.id}'));

  Future<void> _action(String path, [Map<String, dynamic>? body]) async {
    setState(() => _busy = true);
    await _call(() => SessionScope.read(context).api.post('/checkouts/${_checkout.id}/$path', body));
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    final c = _checkout;

    final (IconData icon, Color color, String title, String text) = switch (c.payment) {
      'paid' => (Icons.check_circle, WiBuyColors.green, 'Payment received', 'Thank you! The seller has your order.'),
      'failed' => (Icons.error, Colors.red, 'Payment failed', c.failureReason ?? 'The payment did not go through. Nothing was taken.'),
      'cancelled' => (Icons.cancel, WiBuyColors.muted, 'Cancelled', 'This order was cancelled. Nothing was taken.'),
      _ => (
          Icons.phone_android,
          WiBuyColors.orange,
          'Approve on your phone',
          'Check ${displayPhone(c.payerPhone)} for the ${c.operatorLabel} prompt and enter your PIN to pay ${formatLe(c.total)}.',
        ),
    };

    return Scaffold(
      appBar: AppBar(title: const Text('Payment')),
      body: ListView(padding: const EdgeInsets.all(20), children: [
        const SizedBox(height: 20),
        Icon(icon, size: 84, color: color),
        const SizedBox(height: 12),
        Text(title, textAlign: TextAlign.center, style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        Text(text, textAlign: TextAlign.center, style: const TextStyle(fontSize: 16)),
        if (c.payment == 'waiting') ...[
          const SizedBox(height: 24),
          const Center(child: CircularProgressIndicator()),
          const SizedBox(height: 24),
          if (c.canSimulate) ...[
            const Text('Development: the fake gateway is on. Pretend the phone answered:',
                textAlign: TextAlign.center, style: TextStyle(color: WiBuyColors.muted)),
            const SizedBox(height: 8),
            FilledButton(onPressed: _busy ? null : () => _action('simulate', {'outcome': 'approve'}), child: const Text('Simulate approve')),
            const SizedBox(height: 8),
            OutlinedButton(onPressed: _busy ? null : () => _action('simulate', {'outcome': 'decline'}), child: const Text('Simulate decline')),
            const SizedBox(height: 8),
          ],
          TextButton(onPressed: _busy ? null : () => _action('cancel'), child: const Text('Cancel this order')),
        ],
        if (c.payment != 'waiting') ...[
          const SizedBox(height: 24),
          for (final order in c.orders)
            Card(
              child: ListTile(
                title: Text('Order ${order.number}'),
                subtitle: Text('${order.seller?.shopName ?? ''} · ${formatLe(order.amountPaid)}'),
                trailing: StatusChip(order.statusLabel, status: order.status),
                onTap: c.payment == 'paid'
                    ? () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: order.id)))
                    : null,
              ),
            ),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: () => Navigator.of(context).popUntil((route) => route.isFirst),
            child: const Text('Back to shopping'),
          ),
        ],
      ]),
    );
  }
}
