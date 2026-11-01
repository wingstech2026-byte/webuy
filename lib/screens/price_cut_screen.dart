import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'checkout_screen.dart';
import 'login_screen.dart';

/// "Help me cut the price": products you can cut, and your running cuts.
class PriceCutsScreen extends StatefulWidget {
  const PriceCutsScreen({super.key});

  @override
  State<PriceCutsScreen> createState() => _PriceCutsScreenState();
}

class _PriceCutsScreenState extends State<PriceCutsScreen> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('✂️ Cut the price')),
      body: Loader<Map<String, dynamic>>(
        key: ValueKey(_reload),
        load: () async => await api.get('/price-cuts') as Map<String, dynamic>,
        builder: (context, json, reload) {
          final mine = (json['mine'] as List).whereType<Map<String, dynamic>>().map(PriceCutInfo.fromJson).toList();
          final campaigns = (json['campaigns'] as List).whereType<Map<String, dynamic>>().map(PriceCutCampaignInfo.fromJson).toList();

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(12), children: [
              const Text('Pick a product, share the link on WhatsApp, and each friend who opens it and logs in cuts the price. At the lowest price you buy it at that price.',
                  style: TextStyle(color: WiBuyColors.muted)),
              if (mine.isNotEmpty) ...[
                const SizedBox(height: 14),
                const Text('My price cuts', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                for (final cut in mine)
                  Card(
                    child: ListTile(
                      leading: Photo(cut.product.thumbUrl, size: 48),
                      title: Text(cut.product.title, maxLines: 1, overflow: TextOverflow.ellipsis),
                      subtitle: Text('${formatLe(cut.currentPrice)} · ${cut.progressPercent}% · ${cut.status}'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () async {
                        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PriceCutDetailScreen(token: cut.token)));
                        setState(() => _reload++);
                      },
                    ),
                  ),
              ],
              const SizedBox(height: 14),
              const Text('Products you can cut', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              if (campaigns.isEmpty) const Text('None right now.', style: TextStyle(color: WiBuyColors.muted)),
              for (final campaign in campaigns)
                Card(
                  child: ListTile(
                    leading: Photo(campaign.product.thumbUrl, size: 48),
                    title: Text(campaign.product.title, maxLines: 2, overflow: TextOverflow.ellipsis),
                    subtitle: Text('${formatLe(campaign.product.price)} → as low as ${formatLe(campaign.floorPrice)} in ${campaign.hours}h'),
                    trailing: FilledButton(
                      style: FilledButton.styleFrom(minimumSize: const Size(80, 40)),
                      onPressed: () => startPriceCut(context, campaign.id, onDone: () => setState(() => _reload++)),
                      child: const Text('Start'),
                    ),
                  ),
                ),
            ]),
          );
        },
      ),
    );
  }
}

/// Start (or reopen) a price cut and show it.
Future<void> startPriceCut(BuildContext context, int campaignId, {VoidCallback? onDone}) async {
  if (!await ensureLoggedIn(context) || !context.mounted) return;
  try {
    final json = await SessionScope.read(context).api.post('/price-cuts', {'campaign_id': campaignId}) as Map<String, dynamic>;
    final cut = PriceCutInfo.fromJson(json['data'] as Map<String, dynamic>);
    if (!context.mounted) return;
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PriceCutDetailScreen(token: cut.token)));
    onDone?.call();
  } on Object catch (e) {
    if (context.mounted) showMessage(context, e);
  }
}

class PriceCutDetailScreen extends StatefulWidget {
  const PriceCutDetailScreen({super.key, required this.token});

  final String token;

  @override
  State<PriceCutDetailScreen> createState() => _PriceCutDetailScreenState();
}

class _PriceCutDetailScreenState extends State<PriceCutDetailScreen> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Cut the price')),
      body: Loader<PriceCutInfo>(
        key: ValueKey(_reload),
        load: () async => PriceCutInfo.fromJson((await api.get('/price-cuts/${widget.token}') as Map<String, dynamic>)['data'] as Map<String, dynamic>),
        builder: (context, cut, reload) => RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.all(16), children: [
            Row(children: [
              Photo(cut.product.thumbUrl, size: 84),
              const SizedBox(width: 12),
              Expanded(child: Text(cut.product.title, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700))),
            ]),
            const SizedBox(height: 16),
            Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
              Text(formatLe(cut.currentPrice), style: const TextStyle(fontSize: 30, fontWeight: FontWeight.w900, color: WiBuyColors.green)),
              const SizedBox(width: 8),
              Text(formatLe(cut.startPrice), style: const TextStyle(decoration: TextDecoration.lineThrough, color: WiBuyColors.muted)),
            ]),
            Text('Lowest price: ${formatLe(cut.floorPrice)}'),
            const SizedBox(height: 10),
            ProgressBar(cut.progressPercent),
            const SizedBox(height: 6),
            if (cut.isActive)
              Countdown(cut.expiresAt, prefix: '${cut.progressPercent}% done · ends in ', style: const TextStyle(color: WiBuyColors.muted))
            else
              Text(cut.status == 'reached' || cut.status == 'used' ? 'Lowest price reached! 🎉' : 'This price cut has ended.',
                  style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: 16),
            if (cut.isMine && cut.isActive)
              FilledButton.icon(
                onPressed: () => shareOnWhatsApp(context, 'price_cut', target: cut.token),
                icon: const Icon(Icons.share),
                label: const Text('Ask friends on WhatsApp'),
              ),
            if (cut.isMine && cut.status == 'reached')
              FilledButton(
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => CheckoutScreen(productSlug: cut.product.slug, quantity: 1, couponId: cut.couponId),
                )),
                child: Text('Buy now for ${formatLe(cut.currentPrice)}'),
              ),
            if (!cut.isMine && cut.isActive)
              FilledButton(
                onPressed: () async {
                  if (!await ensureLoggedIn(context) || !context.mounted) return;
                  try {
                    final json = await api.post('/price-cuts/${cut.token}/cut') as Map<String, dynamic>;
                    if (context.mounted) showMessage(context, json['message'] as String);
                  } on Object catch (e) {
                    if (context.mounted) showMessage(context, e);
                  }
                  setState(() => _reload++);
                },
                child: Text('✂️ Cut the price for ${cut.owner}'),
              ),
            const SizedBox(height: 16),
            if (cut.helpers.isNotEmpty) const Text('Friends who helped', style: TextStyle(fontWeight: FontWeight.w800)),
            for (final helper in cut.helpers)
              ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(helper.name), trailing: Text('-${formatLe(helper.amount)}')),
          ]),
        ),
      ),
    );
  }
}
