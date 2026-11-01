import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'login_screen.dart';
import 'order_detail_screen.dart';

class OrdersScreen extends StatelessWidget {
  const OrdersScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('My orders')),
      body: !session.isLoggedIn
          ? EmptyState(
              icon: Icons.receipt_long_outlined,
              title: 'Log in to see your orders.',
              action: SizedBox(width: 200, child: FilledButton(onPressed: () => ensureLoggedIn(context), child: const Text('Log in'))),
            )
          : Loader<Paged<Order>>(
              load: () async => Paged.fromJson(await session.api.get('/orders') as Map<String, dynamic>, Order.fromJson),
              builder: (context, page, reload) => page.items.isEmpty
                  ? const EmptyState(icon: Icons.receipt_long_outlined, title: 'No orders yet.')
                  : RefreshIndicator(
                      onRefresh: reload,
                      child: ListView.separated(
                        padding: const EdgeInsets.all(12),
                        itemCount: page.items.length,
                        separatorBuilder: (_, __) => const SizedBox(height: 8),
                        itemBuilder: (_, i) {
                          final order = page.items[i];
                          return Card(
                            child: ListTile(
                              contentPadding: const EdgeInsets.all(12),
                              leading: Photo(order.items.isEmpty ? null : order.items.first.thumbUrl, size: 56),
                              title: Text(order.seller?.shopName ?? 'Order ${order.number}',
                                  style: const TextStyle(fontWeight: FontWeight.w700)),
                              subtitle: Text(
                                '${order.number} · ${order.items.length} item${order.items.length == 1 ? '' : 's'}\n${formatLe(order.total)}',
                                style: const TextStyle(color: WiBuyColors.muted),
                              ),
                              isThreeLine: true,
                              trailing: StatusChip(order.statusLabel, status: order.status),
                              onTap: () async {
                                await Navigator.of(context)
                                    .push(MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: order.id)));
                                reload();
                              },
                            ),
                          );
                        },
                      ),
                    ),
            ),
    );
  }
}
