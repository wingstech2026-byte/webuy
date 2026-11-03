import 'package:flutter/material.dart';

import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';

/// "My rewards": WiBuy Points and coupons in one place, what ends soon, and the points history.
class MyRewardsScreen extends StatefulWidget {
  const MyRewardsScreen({super.key});

  @override
  State<MyRewardsScreen> createState() => _MyRewardsScreenState();
}

class _MyRewardsScreenState extends State<MyRewardsScreen> {
  int _version = 0;

  Future<void> _exchange() async {
    try {
      final json = await SessionScope.read(context).api.post('/points/exchange') as Map<String, dynamic>;
      final amount = (json['data'] as Map<String, dynamic>?)?['amount'] as String?;
      if (mounted) {
        showMessage(context, 'You got a ${formatLe(amount)} coupon for your next order.');
        setState(() => _version++);
      }
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  Widget _coupon(Map<String, dynamic> c, {String? note}) => ListTile(
        dense: true,
        leading: const Icon(Icons.local_offer_outlined, color: WiBuyColors.green),
        title: Text('${c['title']}'),
        subtitle: Text('${c['description'] ?? ''}${c['app_only'] == true ? ' · in the app' : ''}'),
        trailing: note == null ? null : Text(note, style: const TextStyle(color: WiBuyColors.muted, fontSize: 12)),
      );

  static String _until(String? iso) {
    final d = iso == null ? null : DateTime.tryParse(iso)?.toLocal();
    return d == null ? 'no end date' : 'until ${d.day}/${d.month}';
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('🎟 My rewards')),
        body: Loader<Map<String, dynamic>>(
          key: ValueKey(_version),
          load: () async => await SessionScope.read(context).api.get('/my-rewards') as Map<String, dynamic>,
          builder: (context, json, reload) {
            final soon = (json['expiring_soon'] as List).whereType<Map<String, dynamic>>().toList();
            final usable = (json['usable'] as List).whereType<Map<String, dynamic>>().toList();
            final locked = (json['locked'] as List).whereType<Map<String, dynamic>>().toList();
            final history = (json['history'] as List).whereType<Map<String, dynamic>>().toList();

            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(12), children: [
                const Text('Points and coupons lower the price of your orders. They are never cash.', style: TextStyle(color: WiBuyColors.muted)),
                const SizedBox(height: 8),
                Row(children: [
                  Expanded(child: Card(child: Padding(padding: const EdgeInsets.all(14), child: Column(children: [Text('${json['points']}', style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w900, color: WiBuyColors.green)), const Text('WiBuy Points')])))),
                  Expanded(child: Card(child: Padding(padding: const EdgeInsets.all(14), child: Column(children: [Text('${usable.length}', style: const TextStyle(fontSize: 26, fontWeight: FontWeight.w900)), const Text('coupons to use')])))),
                ]),
                Text('Saved with coupons: ${formatLe(json['saved'] as String?)}'),
                const SizedBox(height: 6),
                FilledButton(
                  onPressed: json['can_exchange'] == true ? _exchange : null,
                  child: Text(json['can_exchange'] == true
                      ? 'Change ${json['points_per_coupon']} points for a ${formatLe(json['coupon_from_points'] as String?)} coupon'
                      : '${json['points_per_coupon']} points = a ${formatLe(json['coupon_from_points'] as String?)} coupon'),
                ),
                if (soon.isNotEmpty)
                  Card(
                    color: Colors.amber.shade50,
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      const Padding(padding: EdgeInsets.fromLTRB(14, 12, 14, 0), child: Text('⏰ Ends soon: use them', style: TextStyle(fontWeight: FontWeight.w800))),
                      for (final c in soon) _coupon(c, note: _until(c['expires_at'] as String?)),
                    ]),
                  ),
                const Padding(padding: EdgeInsets.fromLTRB(2, 12, 2, 4), child: Text('Coupons you can use', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
                if (usable.isEmpty) const Text('None right now. Check in daily, spin the wheel or invite friends to get some.', style: TextStyle(color: WiBuyColors.muted)),
                for (final c in usable) _coupon(c, note: _until(c['expires_at'] as String?)),
                if (locked.isNotEmpty) ...[
                  const Padding(padding: EdgeInsets.fromLTRB(2, 12, 2, 4), child: Text('🔒 Not unlocked yet', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
                  for (final c in locked) _coupon(c, note: c['unlock_hint'] as String?),
                ],
                const Padding(padding: EdgeInsets.fromLTRB(2, 12, 2, 4), child: Text('Points history', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800))),
                if (history.isEmpty) const Text('No points yet.', style: TextStyle(color: WiBuyColors.muted)),
                for (final h in history)
                  ListTile(
                    dense: true,
                    title: Text('${h['reason']}'),
                    trailing: Text('${(h['points'] as num) >= 0 ? '+' : ''}${h['points']}',
                        style: TextStyle(fontWeight: FontWeight.w800, color: (h['points'] as num) >= 0 ? WiBuyColors.green : Colors.red)),
                  ),
              ]),
            );
          },
        ),
      );
}
