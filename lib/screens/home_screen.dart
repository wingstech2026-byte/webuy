import 'package:flutter/material.dart';

import '../api/models.dart';
import '../config.dart';
import '../l10n.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chat_screen.dart';
import 'deals_screen.dart';
import 'group_buy_screen.dart';
import 'instant_screen.dart';
import 'live_screen.dart';
import 'login_screen.dart';
import 'nearby_screen.dart';
import 'notifications_screen.dart';
import 'photo_search_screen.dart';
import 'search_screen.dart';
import 'shop_screen.dart';

class HomeData {
  HomeData(Map<String, dynamic> j)
      : categories = (j['categories'] as List)
            .whereType<Map<String, dynamic>>()
            .map(Category.fromJson)
            .toList(),
        deals = _products(j['deals']),
        forYou = _products(j['for_you'] ?? const []),
        sponsored = _products(j['sponsored'] ?? const []),
        featured = _products(j['featured']),
        newArrivals = _products(j['new_arrivals']),
        topSellers = (j['top_sellers'] as List)
            .whereType<Map<String, dynamic>>()
            .map(Seller.fromJson)
            .toList();

  static List<Product> _products(dynamic list) => (list as List)
      .whereType<Map<String, dynamic>>()
      .map(Product.fromJson)
      .toList();

  final List<Category> categories;
  final List<Product> deals;
  final List<Product> featured;

  /// From what this person looked at or bought; empty at first.
  final List<Product> forYou;

  /// Products sellers paid to show (labelled "Sponsored").
  final List<Product> sponsored;
  final List<Product> newArrivals;
  final List<Seller> topSellers;
}

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key, required this.onSearch});

  final VoidCallback onSearch;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(
        title: const Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(appName,
                  style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
              Text(appTagline,
                  style: TextStyle(fontSize: 12, fontWeight: FontWeight.w400)),
            ]),
        toolbarHeight: 64,
        actions: [
          Builder(builder: (context) {
            final session = SessionScope.of(context);
            return Row(children: [
              IconButton(
                tooltip: 'Chat',
                onPressed: () async {
                  if (await ensureLoggedIn(context) && context.mounted) {
                    await Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => const ChatListScreen()));
                    session.refreshCounts();
                  }
                },
                icon: Badge(
                    isLabelVisible: session.chatCount > 0,
                    label: Text('${session.chatCount}'),
                    child: const Icon(Icons.chat_bubble_outline)),
              ),
              IconButton(
                tooltip: 'Notifications',
                onPressed: () async {
                  if (await ensureLoggedIn(context) && context.mounted) {
                    await Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => const NotificationsScreen()));
                    session.refreshCounts();
                  }
                },
                icon: Badge(
                    isLabelVisible: session.notificationCount > 0,
                    label: Text('${session.notificationCount}'),
                    child: const Icon(Icons.notifications_none)),
              ),
            ]);
          }),
        ],
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(60),
          child: Padding(
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 10),
            child: InkWell(
              onTap: onSearch,
              child: Container(
                height: 48,
                padding: const EdgeInsets.symmetric(horizontal: 14),
                decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(12)),
                child: Row(children: [
                  const Icon(Icons.search, color: WiBuyColors.muted),
                  const SizedBox(width: 8),
                  Expanded(
                      child: Text(tr(context, 'Search rice, phones, cloth…'),
                          style: const TextStyle(
                              color: WiBuyColors.muted, fontSize: 16))),
                  IconButton(
                    tooltip: 'Search with a photo',
                    icon: const Icon(Icons.photo_camera_outlined,
                        color: WiBuyColors.green),
                    onPressed: () => startPhotoSearch(context),
                  ),
                ]),
              ),
            ),
          ),
        ),
      ),
      body: Loader<HomeData>(
        // The phone's own "recently viewed" list helps "For you" before logging in.
        load: () async => HomeData(await api.get('/home', query: {
          'viewed': SessionScope.read(context).recentlyViewed.take(20).join(',')
        }) as Map<String, dynamic>),
        builder: (context, data, reload) => RefreshIndicator(
          onRefresh: reload,
          child:
              ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            const SizedBox(height: 12),
            HorizontalStrip(
              height: 92,
              itemCount: data.categories.length,
              itemBuilder: (_, i) {
                final category = data.categories[i];
                return InkWell(
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => SearchScreen(
                        category: category.slug, title: category.name),
                  )),
                  child: SizedBox(
                    width: 76,
                    child: Column(children: [
                      CircleAvatar(
                        radius: 28,
                        backgroundColor: WiBuyColors.greenLight,
                        child: Text(category.emoji ?? '🛍️',
                            style: const TextStyle(fontSize: 26)),
                      ),
                      const SizedBox(height: 4),
                      Text(category.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontSize: 12)),
                    ]),
                  ),
                );
              },
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                color: WiBuyColors.orange,
                child: ListTile(
                  leading: const Text('⚡', style: TextStyle(fontSize: 28)),
                  title: Text(tr(context, "Today's lightning deals"),
                      style: const TextStyle(
                          color: Colors.white, fontWeight: FontWeight.w800)),
                  subtitle: Text(tr(context, 'Low prices for a few hours only'),
                      style: const TextStyle(color: Colors.white70)),
                  trailing:
                      const Icon(Icons.chevron_right, color: Colors.white),
                  onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const DealsScreen())),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                child: ListTile(
                  leading: const Text('🍲', style: TextStyle(fontSize: 28)),
                  title: Text(tr(context, 'Instant food delivery'), style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(tr(context, 'Hot food near you in 30-60 min · office orders')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const InstantScreen())),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                child: ListTile(
                  leading: const Text('🔴', style: TextStyle(fontSize: 26)),
                  title: Text(tr(context, 'Live selling'), style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(tr(context, 'Watch shops show their goods and buy live')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LiveListScreen())),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                color: WiBuyColors.green,
                child: ListTile(
                  leading: const Text('👥', style: TextStyle(fontSize: 28)),
                  title: Text(tr(context, 'Buy together, pay less'),
                      style: const TextStyle(
                          color: Colors.white, fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      tr(context,
                          'Group prices when enough people buy. Money back if the group does not fill.'),
                      style: const TextStyle(color: Colors.white70)),
                  trailing:
                      const Icon(Icons.chevron_right, color: Colors.white),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => const GroupOffersScreen())),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                child: ListTile(
                  leading: const Icon(Icons.near_me,
                      color: WiBuyColors.green, size: 30),
                  title: Text(tr(context, 'Shops near me'),
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle:
                      Text(tr(context, 'See the closest shops on the map')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => const NearbyShopsScreen())),
                ),
              ),
            ),
            if (data.deals.isNotEmpty) ...[
              _title('🔥 Deals'),
              _strip(data.deals)
            ],
            if (data.topSellers.isNotEmpty) ...[
              _title('Top shops'),
              HorizontalStrip(
                height: 48,
                itemCount: data.topSellers.length,
                itemBuilder: (_, i) {
                  final seller = data.topSellers[i];
                  return ActionChip(
                    avatar: seller.logoUrl == null
                        ? const Icon(Icons.storefront)
                        : Photo(seller.logoUrl, size: 24, radius: 12),
                    label: Text(
                        '${seller.isVerified ? '✓ ' : ''}${seller.shopName}'),
                    onPressed: () => Navigator.of(context).push(
                        MaterialPageRoute(
                            builder: (_) => ShopScreen(slug: seller.slug))),
                  );
                },
              ),
            ],
            if (data.sponsored.isNotEmpty) ...[
              _title('📣 Sponsored'),
              ProductGrid(data.sponsored)
            ],
            if (data.forYou.isNotEmpty) ...[
              _title('✨ For you'),
              ProductGrid(data.forYou)
            ],
            if (data.featured.isNotEmpty) ...[
              _title('Popular'),
              ProductGrid(data.featured)
            ],
            if (data.newArrivals.isNotEmpty) ...[
              _title('New'),
              ProductGrid(data.newArrivals)
            ],
          ]),
        ),
      ),
    );
  }

  Widget _title(String text) => Padding(
        padding: const EdgeInsets.fromLTRB(14, 18, 14, 8),
        child: Builder(
            builder: (context) => Text(tr(context, text),
                style: const TextStyle(
                    fontSize: 19, fontWeight: FontWeight.w800))),
      );

  Widget _strip(List<Product> products) => HorizontalStrip(
        height: 270,
        itemCount: products.length,
        itemBuilder: (_, i) =>
            SizedBox(width: 160, child: ProductCard(products[i])),
      );
}
