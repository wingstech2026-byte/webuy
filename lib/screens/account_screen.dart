import 'package:flutter/material.dart';

import '../api/models.dart';
import '../config.dart';
import '../l10n.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'addresses_screen.dart';
import 'assistant_screen.dart';
import 'business_screen.dart';
import 'chat_screen.dart';
import 'compare_screen.dart';
import 'global_screen.dart';
import 'group_buy_screen.dart';
import 'login_screen.dart';
import 'notifications_screen.dart';
import 'photo_forms.dart';
import 'rider_screen.dart';
import 'seller_screens.dart';

class AccountScreen extends StatelessWidget {
  const AccountScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final user = session.user;
    final base = session.meta?.webUrl ?? apiBaseUrl.replaceFirst(RegExp(r'/api/v1/?$'), '');
    final webUrl = base.endsWith('/') ? base : '$base/';

    return Scaffold(
      appBar: AppBar(title: const Text('Account')),
      body: ListView(padding: const EdgeInsets.all(14), children: [
        if (user == null)
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(children: [
                Text(tr(context, 'Log in with your phone number to buy, track orders and save products.'), textAlign: TextAlign.center),
                const SizedBox(height: 12),
                FilledButton(onPressed: () => ensureLoggedIn(context), child: Text(tr(context, 'Log in'))),
              ]),
            ),
          )
        else
          Card(
            child: ListTile(
              contentPadding: const EdgeInsets.all(14),
              leading: const CircleAvatar(radius: 26, backgroundColor: WiBuyColors.greenLight, child: Icon(Icons.person, color: WiBuyColors.green)),
              title: Text(user.name ?? 'WiBuy customer', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              subtitle: Text(user.displayPhone),
            ),
          ),
        const SizedBox(height: 12),
        Card(
          child: Column(children: [
            if (user != null)
              ListTile(
                leading: const Icon(Icons.favorite_border),
                title: Text(tr(context, 'Saved products')),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const WishlistScreen())),
              ),
            SwitchListTile(
              secondary: const Icon(Icons.signal_cellular_alt),
              title: Text(tr(context, 'Save data')),
              subtitle: Text(tr(context, 'Smaller pictures, for slow or costly data')),
              value: SessionScope.of(context).dataSaver,
              onChanged: (on) => SessionScope.read(context).dataSaver = on,
            ),
            ListTile(
              leading: const Icon(Icons.translate),
              title: Text(tr(context, 'Language')),
              trailing: SegmentedButton<String>(
                showSelectedIcon: false,
                segments: [for (final e in appLanguages.entries) ButtonSegment(value: e.key, label: Text(e.value))],
                selected: {SessionScope.of(context).language},
                onSelectionChanged: (s) => SessionScope.read(context).language = s.first,
              ),
            ),
            ListTile(
              leading: const Icon(Icons.compare_arrows),
              title: const Text('Compare & recently viewed'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CompareScreen())),
            ),
            if (user != null) ...[
              if (user.isSeller)
                ListTile(
                  leading: const Icon(Icons.storefront, color: WiBuyColors.green),
                  title: Text(tr(context, 'My shop'), style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: const Text('Orders, products, earnings, quote requests'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SellerHomeScreen())),
                ),
              ListTile(
                leading: const Icon(Icons.location_on_outlined),
                title: const Text('My addresses'),
                subtitle: const Text('Home, shop, office, with a landmark or map point'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const AddressesScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.groups_outlined),
                title: const Text('My groups'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const MyGroupsScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.chat_bubble_outline),
                title: Text(tr(context, 'Chat')),
                subtitle: const Text('WiBuy support and sellers'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const ChatListScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.notifications_none),
                title: Text(tr(context, 'Notifications')),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const NotificationsScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.business_center_outlined),
                title: const Text('Business buying'),
                subtitle: const Text('Business prices, requests for quotes, pay in parts'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const BusinessScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.smart_toy_outlined),
                title: const Text('Shopping assistant'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const AssistantScreen())),
              ),
              ListTile(
                leading: const Icon(Icons.public),
                title: const Text('WiBuy Global (import from China)'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const GlobalScreen())),
              ),
              if (user.isRider)
                ListTile(
                  leading: const Icon(Icons.delivery_dining, color: WiBuyColors.green),
                  title: const Text('Rider jobs', style: TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: const Text('Take deliveries and hand them over with the code'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RiderJobsScreen())),
                ),
              if (user.isAgent)
                ListTile(
                  leading: const Icon(Icons.fact_check_outlined),
                  title: const Text('My inspections (agent)'),
                  subtitle: const Text('Send pass/fail reports with photos'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const AgentJobsScreen())),
                ),
            ],
            ListTile(
              leading: const Icon(Icons.storefront_outlined),
              title: Text(user?.isSeller == true ? 'Seller dashboard on the website' : 'Sell on WiBuy'),
              subtitle: const Text('Opens the website'),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => openLink(context, '$webUrl${user?.isSeller == true ? 'seller' : 'sell'}'),
            ),
            ListTile(
              leading: const Icon(Icons.language),
              title: const Text('Open the WiBuy website'),
              trailing: const Icon(Icons.open_in_new),
              onTap: () => openLink(context, webUrl),
            ),
          ]),
        ),
        if (user != null) ...[
          const SizedBox(height: 12),
          OutlinedButton(onPressed: session.logout, child: const Text('Log out')),
        ],
        const SizedBox(height: 20),
        const Text('$appName · $appTagline\nServer: $apiBaseUrl', textAlign: TextAlign.center, style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
      ]),
    );
  }
}

class WishlistScreen extends StatelessWidget {
  const WishlistScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Saved products')),
      body: Loader<List<Product>>(
        load: () async {
          final json = await api.get('/wishlist') as Map<String, dynamic>;
          return (json['data'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();
        },
        builder: (context, products, reload) => products.isEmpty
            ? const EmptyState(icon: Icons.favorite_border, title: 'Tap Save on a product to keep it here.')
            : RefreshIndicator(onRefresh: reload, child: ListView(padding: const EdgeInsets.only(top: 12), children: [ProductGrid(products)])),
      ),
    );
  }
}
