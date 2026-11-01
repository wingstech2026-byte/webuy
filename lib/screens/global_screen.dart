import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/business_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/payment_prompt.dart';

/// WiBuy Global: order from China by sea or air. Deposit now, the balance when it arrives in Freetown.
class GlobalScreen extends StatefulWidget {
  const GlobalScreen({super.key});

  @override
  State<GlobalScreen> createState() => _GlobalScreenState();
}

class _GlobalScreenState extends State<GlobalScreen> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('🌍 WiBuy Global')),
      body: Loader<(List<ImportProductInfo>, List<ImportOrderInfo>)>(
        key: ValueKey(_reload),
        load: () async {
          try {
            final products = ((await api.get('/global/products') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().map(ImportProductInfo.fromJson).toList();
            final orders = ((await api.get('/global/orders') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().map(ImportOrderInfo.fromJson).toList();
            return (products, orders);
          } on ApiException catch (e) {
            if (e.status == 404) throw ApiException(404, 'WiBuy Global is not switched on yet.');
            rethrow;
          }
        },
        builder: (context, data, reload) {
          final (products, orders) = data;

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(12), children: [
              const Text('Prices include shipping and duties to Freetown. Pay a deposit to order and the balance when it arrives.', style: TextStyle(color: WiBuyColors.muted)),
              if (orders.isNotEmpty) ...[
                const SizedBox(height: 12),
                const Text('My imports', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                for (final order in orders) _OrderCard(order: order, onChanged: () => setState(() => _reload++)),
              ],
              const SizedBox(height: 12),
              const Text('Products', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              for (final product in products)
                Card(
                  child: ListTile(
                    leading: Photo(product.imageUrl, size: 52),
                    title: Text(product.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text([
                      for (final e in product.options.entries) '${e.key == 'sea' ? '🚢 Sea' : '✈️ Air'} ${formatLe(e.value.$1)} · ${e.value.$2} days',
                      'min ${product.minQuantity} ${product.unit}',
                    ].join('\n')),
                    isThreeLine: true,
                    onTap: () => _order(context, product),
                  ),
                ),
            ]),
          );
        },
      ),
    );
  }

  Future<void> _order(BuildContext context, ImportProductInfo product) async {
    final quantity = TextEditingController(text: '${product.minQuantity}');
    var shipping = product.options.keys.first;
    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) {
          final qty = int.tryParse(quantity.text) ?? 0;
          final unit = product.options[shipping]!.$1;
          final totalCents = (double.parse(unit) * 100).round() * qty;
          final total = '${totalCents ~/ 100}.${(totalCents % 100).toString().padLeft(2, '0')}';
          return Padding(
            padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              Text(product.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              SegmentedButton<String>(
                segments: [for (final k in product.options.keys) ButtonSegment(value: k, label: Text(k == 'sea' ? 'Sea' : 'Air'))],
                selected: {shipping},
                onSelectionChanged: (s) => setState(() => shipping = s.first),
              ),
              const SizedBox(height: 8),
              TextField(controller: quantity, keyboardType: TextInputType.number, onChanged: (_) => setState(() {}), decoration: InputDecoration(labelText: 'Quantity (${product.unit})')),
              const SizedBox(height: 8),
              Text('Total ${formatLe(total)} · deposit ${product.depositPercent}% now · arrives in about ${product.options[shipping]!.$2} days'),
              const SizedBox(height: 12),
              FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Order and pay deposit')),
            ]),
          );
        },
      ),
    );
    if (ok != true || !context.mounted) return;

    final qty = int.tryParse(quantity.text) ?? 0;
    final depositCents = (double.parse(product.options[shipping]!.$1) * 100).round() * qty * product.depositPercent ~/ 100;
    final deposit = '${depositCents ~/ 100}.${(depositCents % 100).toString().padLeft(2, '0')}';
    final payer = await askPayer(context, 'Deposit for ${product.title}', deposit);
    if (payer == null || !context.mounted) return;
    try {
      final json = await SessionScope.read(context).api.post('/global/orders', {
        'import_product_id': product.id,
        'quantity': qty,
        'shipping': shipping,
        'operator': payer.operator,
        'payer_phone': payer.phone,
      }) as Map<String, dynamic>;
      final order = ImportOrderInfo.fromJson(json['data'] as Map<String, dynamic>);
      if (context.mounted && order.paymentId != null) {
        final paid = await waitForCharge(context, order.paymentId!);
        if (context.mounted) showMessage(context, paid ? 'Deposit paid. We will text you at every step.' : 'The deposit did not go through.');
      }
    } on Object catch (e) {
      if (context.mounted) showMessage(context, e);
    }
    setState(() => _reload++);
  }
}

class _OrderCard extends StatelessWidget {
  const _OrderCard({required this.order, required this.onChanged});

  final ImportOrderInfo order;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('${order.number}: ${order.quantity} × ${order.product}', style: const TextStyle(fontWeight: FontWeight.w700)),
            Text('${order.statusLabel} · ETA ${order.estimatedArrival ?? '?'} · by ${order.shipping}'),
            Text('Deposit ${formatLe(order.depositAmount)} ${order.depositPaid ? '✓' : '(not paid)'} · balance ${formatLe(order.balanceAmount)} ${order.balancePaid ? '✓' : ''}'),
            const SizedBox(height: 6),
            for (final step in order.steps)
              Row(children: [
                Icon(step.at != null ? Icons.check_circle : Icons.radio_button_unchecked, size: 18, color: step.at != null ? WiBuyColors.green : WiBuyColors.muted),
                const SizedBox(width: 6),
                Text(step.label),
              ]),
            if (order.canPayBalance) ...[
              const SizedBox(height: 8),
              FilledButton(
                onPressed: () async {
                  final payer = await askPayer(context, 'Balance for ${order.number}', order.balanceAmount);
                  if (payer == null || !context.mounted) return;
                  try {
                    final json = await SessionScope.read(context).api.post('/global/orders/${order.id}/balance', {'operator': payer.operator, 'payer_phone': payer.phone}) as Map<String, dynamic>;
                    if (context.mounted) await waitForCharge(context, (json['payment_id'] as num).toInt());
                  } on Object catch (e) {
                    if (context.mounted) showMessage(context, e);
                  }
                  onChanged();
                },
                child: Text('Pay balance ${formatLe(order.balanceAmount)}'),
              ),
            ],
          ]),
        ),
      );
}
