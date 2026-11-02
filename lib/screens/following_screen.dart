import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'live_screen.dart';
import 'login_screen.dart';

/// Follow / Following button for a shop page.
class FollowButton extends StatefulWidget {
  const FollowButton({super.key, required this.slug, required this.following, required this.followers});

  final String slug;
  final bool following;
  final int followers;

  @override
  State<FollowButton> createState() => _FollowButtonState();
}

class _FollowButtonState extends State<FollowButton> {
  late bool _following = widget.following;
  late int _followers = widget.followers;
  bool _busy = false;

  Future<void> _toggle() async {
    if (!await ensureLoggedIn(context) || !mounted) return;
    setState(() => _busy = true);
    try {
      final json = await SessionScope.read(context).api.post('/shops/${widget.slug}/follow') as Map<String, dynamic>;
      setState(() {
        _following = json['is_following'] == true;
        _followers = (json['followers'] as num).toInt();
      });
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Row(children: [
        Text('$_followers follower(s)', style: const TextStyle(color: WiBuyColors.muted)),
        const SizedBox(width: 10),
        _following
            ? OutlinedButton(onPressed: _busy ? null : _toggle, child: const Text('✓ Following'))
            : FilledButton(onPressed: _busy ? null : _toggle, child: const Text('+ Follow')),
      ]);
}

/// News from the shops you follow: live shows, offers and new products.
class FollowingScreen extends StatelessWidget {
  const FollowingScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('❤ Following')),
        body: Loader<Map<String, dynamic>>(
          load: () async => await SessionScope.read(context).api.get('/following') as Map<String, dynamic>,
          builder: (context, json, reload) {
            final shops = (json['shops'] as List).whereType<Map<String, dynamic>>().toList();
            final live = (json['live'] as List).whereType<Map<String, dynamic>>().toList();
            final offers = (json['offers'] as List).whereType<Map<String, dynamic>>().toList();
            final products = (json['products'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();

            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(12), children: [
                if (shops.isEmpty)
                  const Padding(padding: EdgeInsets.all(20), child: Text('You do not follow any shop yet. Tap "+ Follow" on a shop page to see its news here.')),
                if (shops.isNotEmpty) Text('You follow: ${shops.map((s) => s['shop_name']).join(', ')}', style: const TextStyle(color: WiBuyColors.muted)),
                for (final show in live)
                  Card(
                    child: ListTile(
                      leading: Text(show['status'] == 'live' ? '🔴' : '🕐', style: const TextStyle(fontSize: 22)),
                      title: Text('${show['title']}'),
                      subtitle: Text('${show['shop']} · ${show['status'] == 'live' ? 'LIVE now' : 'Coming up'}'),
                      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => LiveShowScreen(id: (show['id'] as num).toInt()))),
                    ),
                  ),
                for (final offer in offers) Card(child: ListTile(leading: const Text('🏷', style: TextStyle(fontSize: 22)), title: Text('${offer['label']}'), subtitle: Text('${offer['shop']}'))),
                if (products.isNotEmpty) ...[
                  const Padding(padding: EdgeInsets.fromLTRB(2, 12, 2, 6), child: Text('🆕 New products', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
                  ProductGrid(products),
                ],
              ]),
            );
          },
        ),
      );
}

/// Invite friends: your link, how many joined and ordered, and what you both get.
class InviteScreen extends StatelessWidget {
  const InviteScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('🎁 Invite friends')),
        body: Loader<Map<String, dynamic>>(
          load: () async => await SessionScope.read(context).api.get('/invite') as Map<String, dynamic>,
          builder: (context, json, _) {
            final invite = json['data'] as Map<String, dynamic>;
            final link = invite['link'] as String;

            return ListView(padding: const EdgeInsets.all(16), children: [
              Text('When a friend joins from your link you get ${invite['points_join']} WiBuy Points. '
                  'When their first order arrives, you both get a ${formatLe(invite['coupon'] as String?)} coupon and you get ${invite['points_order']} more points.'),
              const SizedBox(height: 6),
              const Text('Coupons and points lower the price of orders; they are never cash.', style: TextStyle(color: WiBuyColors.muted)),
              const SizedBox(height: 14),
              SelectableText(link, style: const TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              FilledButton.icon(
                onPressed: () => openLink(context, 'https://wa.me/?text=${Uri.encodeComponent("Join me on WiBuy, Salone's marketplace: $link")}'),
                icon: const Icon(Icons.share),
                label: const Text('Share on WhatsApp'),
              ),
              const SizedBox(height: 16),
              Row(children: [
                Expanded(child: Card(child: Padding(padding: const EdgeInsets.all(14), child: Column(children: [Text('${invite['joined']}', style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w900)), const Text('friends joined')])))),
                Expanded(child: Card(child: Padding(padding: const EdgeInsets.all(14), child: Column(children: [Text('${invite['ordered']}', style: const TextStyle(fontSize: 28, fontWeight: FontWeight.w900, color: WiBuyColors.green)), const Text('got their first order')])))),
              ]),
            ]);
          },
        ),
      );
}
