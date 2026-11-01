import 'package:flutter/material.dart';

import '../api/models.dart';
import '../config.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chat_screen.dart';
import 'deals_screen.dart';
import 'group_buy_screen.dart';
import 'login_screen.dart';
import 'nearby_screen.dart';
import 'notifications_screen.dart';
import 'search_screen.dart';
import 'shop_screen.dart';

class HomeData {
  HomeData(Map<String, dynamic> j)
      : categories = (j['categories'] as List).whereType<Map<String, dynamic>>().map(Category.fromJson).toList(),
        deals = _products(j['deals']),
        featured = _products(j['featured']),
        newArrivals = _products(j['new_arrivals']),
        topSellers = (j['top_sellers'] as List).whereType<Map<String, dynamic>>().map(Seller.fromJson).toList();

  static List<Product> _products(dynamic list) =>
      (list as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();

  final List<Category> categories;
  final List<Product> deals;
  final List<Product> featured;
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
        title: const Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(appName, style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900)),
          Text(appTagline, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w400)),
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
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ChatListScreen()));
                    session.refreshCounts();
                  }
                },
                icon: Badge(isLabelVisible: session.chatCount > 0, label: Text('${session.chatCount}'), child: const Icon(Icons.chat_bubble_outline)),
              ),
              IconButton(
                tooltip: 'Notifications',
                onPressed: () async {
                  if (await ensureLoggedIn(context) && context.mounted) {
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen()));
                    session.refreshCounts();
                  }
                },
                icon: Badge(isLabelVisible: session.notificationCount > 0, label: Text('${session.notificationCount}'), child: const Icon(Icons.notifications_none)),
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
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                child: const Row(children: [
                  Icon(Icons.search, color: WiBuyColors.muted),
                  SizedBox(width: 8),
                  Text('Search rice, phones, cloth…', style: TextStyle(color: WiBuyColors.muted, fontSize: 16)),
                ]),
              ),
            ),
          ),
        ),
      ),
      body: Loader<HomeData>(
        load: () async => HomeData(await api.get('/home') as Map<String, dynamic>),
        builder: (context, data, reload) => RefreshIndicator(
          onRefresh: reload,
          child: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            const SizedBox(height: 12),
            HorizontalStrip(
                height: 92,
                itemCount: data.categories.length,
                itemBuilder: (_, i) {
                  final category = data.categories[i];
                  return InkWell(
                    onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => SearchScreen(category: category.slug, title: category.name),
                    )),
                    child: SizedBox(
                      width: 76,
                      child: Column(children: [
                        CircleAvatar(
                          radius: 28,
                          backgroundColor: WiBuyColors.greenLight,
                          child: Text(category.emoji ?? '🛍️', style: const TextStyle(fontSize: 26)),
                        ),
                        const SizedBox(height: 4),
                        Text(category.name, maxLines: 1, overflow: TextOverflow.ellipsis, style: const TextStyle(fontSize: 12)),
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
                  title: const Text("Today's lightning deals", style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                  subtitle: const Text('Low prices for a few hours only', style: TextStyle(color: Colors.white70)),
                  trailing: const Icon(Icons.chevron_right, color: Colors.white),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const DealsScreen())),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                color: WiBuyColors.green,
                child: ListTile(
                  leading: const Text('👥', style: TextStyle(fontSize: 28)),
                  title: const Text('Buy together, pay less', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800)),
                  subtitle: const Text('Group prices when enough people buy. Money back if the group does not fill.', style: TextStyle(color: Colors.white70)),
                  trailing: const Icon(Icons.chevron_right, color: Colors.white),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const GroupOffersScreen())),
                ),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
              child: Card(
                child: ListTile(
                  leading: const Icon(Icons.near_me, color: WiBuyColors.green, size: 30),
                  title: const Text('Shops near me', style: TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: const Text('See the closest shops on the map'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NearbyShopsScreen())),
                ),
              ),
            ),
            if (data.deals.isNotEmpty) ...[_title('🔥 Deals'), _strip(data.deals)],
            if (data.topSellers.isNotEmpty) ...[
              _title('Top shops'),
              HorizontalStrip(
                  height: 48,
                  itemCount: data.topSellers.length,
                  itemBuilder: (_, i) {
                    final seller = data.topSellers[i];
                    return ActionChip(
                      avatar: seller.logoUrl == null ? const Icon(Icons.storefront) : Photo(seller.logoUrl, size: 24, radius: 12),
                      label: Text('${seller.isVerified ? '✓ ' : ''}${seller.shopName}'),
                      onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ShopScreen(slug: seller.slug))),
                    );
                  },
              ),
            ],
            if (data.featured.isNotEmpty) ...[_title('Popular'), ProductGrid(data.featured)],
            if (data.newArrivals.isNotEmpty) ...[_title('New'), ProductGrid(data.newArrivals)],
          ]),
        ),
      ),
    );
  }

  Widget _title(String text) => Padding(
        padding: const EdgeInsets.fromLTRB(14, 18, 14, 8),
        child: Text(text, style: const TextStyle(fontSize: 19, fontWeight: FontWeight.w800)),
      );

  Widget _strip(List<Product> products) => HorizontalStrip(
        height: 270,
        itemCount: products.length,
        itemBuilder: (_, i) => SizedBox(width: 160, child: ProductCard(products[i])),
      );
}
