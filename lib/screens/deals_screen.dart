import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'login_screen.dart';
import 'product_screen.dart';

/// "Today's deals": lightning deals with countdowns and "Remind me".
class DealsScreen extends StatelessWidget {
  const DealsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text("⚡ Today's deals")),
      body: Loader<List<FlashDealInfo>>(
        load: () async {
          final json = await api.get('/lightning-deals') as Map<String, dynamic>;
          return (json['data'] as List).whereType<Map<String, dynamic>>().map(FlashDealInfo.fromJson).toList();
        },
        builder: (context, deals, reload) => deals.isEmpty
            ? const EmptyState(icon: Icons.bolt, title: 'No lightning deals right now. Check again later today.')
            : RefreshIndicator(
                onRefresh: reload,
                child: ListView.separated(
                  padding: const EdgeInsets.all(12),
                  itemCount: deals.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (_, i) => _DealCard(deal: deals[i], onChanged: reload),
                ),
              ),
      ),
    );
  }
}

class _DealCard extends StatelessWidget {
  const _DealCard({required this.deal, required this.onChanged});

  final FlashDealInfo deal;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final product = deal.product!;

    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ProductScreen(slug: product.slug))),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Row(children: [
            Photo(product.thumbUrl, size: 96),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(product.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w700)),
                Row(children: [
                  Text(formatLe(deal.dealPrice), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900, color: WiBuyColors.orange)),
                  const SizedBox(width: 6),
                  Text(formatLe(product.price), style: const TextStyle(decoration: TextDecoration.lineThrough, color: WiBuyColors.muted)),
                ]),
                if (deal.isLive)
                  Countdown(deal.endsAt, prefix: 'Ends in ', style: const TextStyle(color: Colors.red, fontWeight: FontWeight.w700))
                else
                  Countdown(deal.startsAt, prefix: 'Starts in ', style: const TextStyle(color: WiBuyColors.muted, fontWeight: FontWeight.w700)),
                Text('Up to ${deal.maxPerOrder} per order${deal.left != null ? ' · ${deal.left} left' : ''}', style: const TextStyle(fontSize: 12, color: WiBuyColors.muted)),
                if (!deal.isLive)
                  TextButton.icon(
                    style: TextButton.styleFrom(padding: EdgeInsets.zero),
                    onPressed: () => _remind(context),
                    icon: Icon(deal.reminded ? Icons.notifications_active : Icons.notifications_none),
                    label: Text(deal.reminded ? 'Reminder set' : 'Remind me'),
                  ),
              ]),
            ),
          ]),
        ),
      ),
    );
  }

  Future<void> _remind(BuildContext context) async {
    if (!await ensureLoggedIn(context) || !context.mounted) return;
    try {
      final json = await SessionScope.read(context).api.post('/lightning-deals/${deal.id}/remind') as Map<String, dynamic>;
      if (context.mounted) showMessage(context, json['message'] as String);
    } on Object catch (e) {
      if (context.mounted) showMessage(context, e);
    }
    await onChanged();
  }
}
