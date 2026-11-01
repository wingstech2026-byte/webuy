import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'deals_screen.dart';
import 'farm_screen.dart';
import 'login_screen.dart';
import 'pass_screen.dart';
import 'price_cut_screen.dart';
import 'spin_screen.dart';
import 'trials_screen.dart';

/// The Rewards tab: check-in, spin, farm, cut the price, points and coupons in one place.
/// Every reward is a coupon or WiBuy Points, never cash.
class RewardsScreen extends StatefulWidget {
  const RewardsScreen({super.key});

  @override
  State<RewardsScreen> createState() => _RewardsScreenState();
}

class _RewardsScreenState extends State<RewardsScreen> {
  int _reload = 0;

  void _refresh() => setState(() => _reload++);

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    if (mounted) _refresh();
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Rewards')),
      body: !session.isLoggedIn
          ? EmptyState(
              icon: Icons.card_giftcard,
              title: 'Log in to get your welcome coupons, check in every day, spin the wheel and grow your farm.',
              action: SizedBox(width: 200, child: FilledButton(onPressed: () => ensureLoggedIn(context), child: const Text('Log in'))),
            )
          : Loader<RewardsHub>(
              key: ValueKey(_reload),
              load: () async => RewardsHub.fromJson(await session.api.get('/rewards') as Map<String, dynamic>),
              builder: (context, hub, reload) => RefreshIndicator(
                onRefresh: reload,
                child: ListView(padding: const EdgeInsets.all(12), children: [
                  _PointsCard(hub: hub, onChanged: _refresh),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(
                      child: _Tile(
                        emoji: '📅',
                        title: hub.checkedInToday ? 'Checked in' : 'Check in',
                        subtitle: 'Day ${hub.streak} streak',
                        done: hub.checkedInToday,
                        onTap: hub.checkedInToday ? null : () => _checkIn(),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _Tile(
                        emoji: '🎡',
                        title: 'Spin',
                        subtitle: '${hub.spins.left} spin${hub.spins.left == 1 ? '' : 's'} left',
                        onTap: () => _open(const SpinScreen()),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(
                      child: _Tile(
                        emoji: hub.farm?.emoji ?? '🌱',
                        title: 'WiBuy Farm',
                        subtitle: hub.farm == null ? 'Plant a seed' : '${hub.farm!.stage} · ${hub.farm!.progressPercent}%',
                        onTap: () => _open(const FarmScreen()),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: _Tile(
                        emoji: '✂️',
                        title: 'Cut the price',
                        subtitle: hub.priceCuts.where((c) => c.isActive).isEmpty ? 'Friends help you pay less' : '${hub.priceCuts.where((c) => c.isActive).length} running',
                        onTap: () => _open(const PriceCutsScreen()),
                      ),
                    ),
                  ]),
                  const SizedBox(height: 10),
                  Row(children: [
                    Expanded(child: _Tile(emoji: '⚡', title: "Today's deals", subtitle: 'Short, low prices', onTap: () => _open(const DealsScreen()))),
                    const SizedBox(width: 10),
                    Expanded(child: _Tile(emoji: '🧪', title: 'Free trials', subtitle: 'Try and review', onTap: () => _open(const TrialsScreen()))),
                  ]),
                  const SizedBox(height: 10),
                  _Tile(
                    emoji: '⭐',
                    title: hub.pass == null ? 'WiBuy Pass' : 'WiBuy Pass: active',
                    subtitle: hub.pass?.endsAt == null
                        ? 'Free delivery, member prices and extra spins'
                        : 'Until ${hub.pass!.endsAt!.day}/${hub.pass!.endsAt!.month}',
                    onTap: () => _open(const PassScreen()),
                  ),
                  const SizedBox(height: 16),
                  _WelcomeCard(hub: hub),
                  const SizedBox(height: 16),
                  Text('Your coupons (${hub.coupons.length})', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  if (hub.coupons.isEmpty)
                    const Text('No coupons yet. Spin the wheel or exchange points.', style: TextStyle(color: WiBuyColors.muted))
                  else
                    for (final coupon in hub.coupons) CouponTile(coupon),
                ]),
              ),
            ),
    );
  }

  Future<void> _checkIn() async {
    try {
      final json = await SessionScope.read(context).api.post('/rewards/check-in') as Map<String, dynamic>;
      if (mounted) showMessage(context, json['message'] as String);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
    _refresh();
  }
}

class _PointsCard extends StatelessWidget {
  const _PointsCard({required this.hub, required this.onChanged});

  final RewardsHub hub;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final canExchange = hub.points >= hub.pointsPerCoupon;

    return Card(
      color: WiBuyColors.green,
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              const Text('WiBuy Points', style: TextStyle(color: Colors.white70)),
              Text('${hub.points}', style: const TextStyle(color: Colors.white, fontSize: 32, fontWeight: FontWeight.w900)),
              Text('${hub.pointsPerCoupon} points = ${formatLe(hub.pointsCouponAmount)} coupon',
                  style: const TextStyle(color: Colors.white70, fontSize: 13)),
            ]),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: WiBuyColors.green, minimumSize: const Size(110, 46)),
            onPressed: canExchange
                ? () async {
                    try {
                      await SessionScope.read(context).api.post('/points/exchange');
                      if (context.mounted) showMessage(context, 'You got a ${formatLe(hub.pointsCouponAmount)} coupon.');
                    } on Object catch (e) {
                      if (context.mounted) showMessage(context, e);
                    }
                    onChanged();
                  }
                : null,
            child: const Text('Exchange'),
          ),
        ]),
      ),
    );
  }
}

class _WelcomeCard extends StatelessWidget {
  const _WelcomeCard({required this.hub});

  final RewardsHub hub;

  @override
  Widget build(BuildContext context) => Card(
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            const Text('Invite friends', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 4),
            for (final locked in hub.lockedCoupons)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Text('🔒 ${locked.title}: ${locked.description}. ${locked.unlockHint ?? ''}'),
              ),
            const Text('When a friend opens your link and joins WiBuy, you get WiBuy Points. Each friend who opens it also gives you an extra spin today.'),
            const SizedBox(height: 10),
            FilledButton.icon(
              onPressed: () => shareOnWhatsApp(context, 'app'),
              icon: const Icon(Icons.share),
              label: const Text('Share WiBuy on WhatsApp'),
            ),
          ]),
        ),
      );
}

class _Tile extends StatelessWidget {
  const _Tile({required this.emoji, required this.title, required this.subtitle, this.onTap, this.done = false});

  final String emoji;
  final String title;
  final String subtitle;
  final VoidCallback? onTap;
  final bool done;

  @override
  Widget build(BuildContext context) => Card(
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Padding(
            padding: const EdgeInsets.all(14),
            child: Row(children: [
              Text(emoji, style: const TextStyle(fontSize: 30)),
              const SizedBox(width: 10),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(title, style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                  Text(subtitle, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(color: WiBuyColors.muted, fontSize: 13)),
                ]),
              ),
              if (done) const Icon(Icons.check_circle, color: WiBuyColors.green),
            ]),
          ),
        ),
      );
}

class CouponTile extends StatelessWidget {
  const CouponTile(this.coupon, {super.key, this.selected = false, this.onTap});

  final Coupon coupon;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) => Card(
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(14),
          side: BorderSide(color: selected ? WiBuyColors.green : const Color(0xFFFED7AA), width: selected ? 2 : 1),
        ),
        child: ListTile(
          onTap: onTap,
          leading: Text(coupon.isLocked ? '🔒' : '🎟️', style: const TextStyle(fontSize: 26)),
          title: Text(coupon.title, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text([
            coupon.description,
            if (coupon.appOnly) 'app only',
            if (coupon.expiresAt != null) 'until ${coupon.expiresAt!.day}/${coupon.expiresAt!.month}',
          ].join(' · ')),
          trailing: selected ? const Icon(Icons.check_circle, color: WiBuyColors.green) : null,
        ),
      );
}
