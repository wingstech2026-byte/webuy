import 'dart:async';
import 'dart:ui' show PointerDeviceKind;

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api/api_client.dart';
import 'config.dart';
import 'screens/account_screen.dart';
import 'screens/cart_screen.dart';
import 'screens/farm_screen.dart';
import 'screens/group_buy_screen.dart';
import 'screens/home_screen.dart';
import 'screens/orders_screen.dart';
import 'screens/price_cut_screen.dart';
import 'screens/product_screen.dart';
import 'screens/rewards_screen.dart';
import 'screens/search_screen.dart';
import 'session.dart';
import 'theme.dart';

final navigatorKey = GlobalKey<NavigatorState>();

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final session = Session(ApiClient(), await SharedPreferences.getInstance());
  session.bootstrap();

  runApp(WiBuyApp(session: session));

  // Installed from a "Get the app" banner? Open the product the person was looking at.
  final path = await session.claimDeferredLink();
  final route = path == null ? null : routeFor(path);
  if (route != null) {
    navigatorKey.currentState?.push(route);
  }
}

/// Deep links (`wibuy://app/products/SLUG`, `/p/SLUG`, `/cut/TOKEN`, `/farm/CODE`) to screens.
Route<void>? routeFor(String? path) {
  final segments = Uri.tryParse(path ?? '')?.pathSegments.where((s) => s.isNotEmpty).toList() ?? const [];
  if (segments.length < 2) {
    return null;
  }

  final Widget? screen = switch (segments[0]) {
    'products' || 'p' => ProductScreen(slug: segments[1]),
    'cut' || 'price-cuts' => PriceCutDetailScreen(token: segments[1]),
    'farm' => const FarmScreen(),
    'groups' || 'g' => GroupScreen(token: segments[1]),
    _ => null,
  };

  return screen == null ? null : MaterialPageRoute(builder: (_) => screen);
}

class WiBuyApp extends StatelessWidget {
  const WiBuyApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) => SessionScope(
        session: session,
        child: MaterialApp(
          title: appName,
          debugShowCheckedModeBanner: false,
          theme: wibuyTheme(),
          navigatorKey: navigatorKey,
          scrollBehavior: const _DragAnywhereScrollBehavior(),
          home: const HomeShell(),
          // Android passes opened links here (flutter_deeplinking_enabled in the manifest).
          onGenerateRoute: (settings) => settings.name == '/' ? null : routeFor(settings.name),
        ),
      );
}

/// Lists can be dragged with a mouse or trackpad too (the browser preview), not only with a finger.
class _DragAnywhereScrollBehavior extends MaterialScrollBehavior {
  const _DragAnywhereScrollBehavior();

  @override
  Set<PointerDeviceKind> get dragDevices => PointerDeviceKind.values.toSet();
}

/// Bottom tabs: Home, Rewards, Cart, Orders, Account.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _tab = 0;
  Timer? _counts;

  @override
  void initState() {
    super.initState();
    _counts = Timer.periodic(const Duration(seconds: 30), (_) => SessionScope.read(context).refreshCounts());
  }

  @override
  void dispose() {
    _counts?.cancel();
    super.dispose();
  }

  // A new key each visit reloads Rewards, Cart and Orders, so they are never stale.
  int _visit = 0;

  void _go(int tab) => setState(() {
        _tab = tab;
        _visit++;
      });

  @override
  Widget build(BuildContext context) {
    final cartCount = SessionScope.of(context).cartCount;

    final pages = <Widget>[
      HomeScreen(onSearch: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const SearchScreen()))),
      RewardsScreen(key: ValueKey('rewards$_visit')),
      CartScreen(key: ValueKey('cart$_visit'), onShop: () => _go(0)),
      OrdersScreen(key: ValueKey('orders$_visit')),
      const AccountScreen(),
    ];

    return Scaffold(
      body: IndexedStack(index: _tab, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: _go,
        destinations: [
          const NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Home'),
          const NavigationDestination(icon: Icon(Icons.card_giftcard_outlined), selectedIcon: Icon(Icons.card_giftcard), label: 'Rewards'),
          NavigationDestination(
            icon: Badge(isLabelVisible: cartCount > 0, label: Text('$cartCount'), child: const Icon(Icons.shopping_cart_outlined)),
            selectedIcon: Badge(isLabelVisible: cartCount > 0, label: Text('$cartCount'), child: const Icon(Icons.shopping_cart)),
            label: 'Cart',
          ),
          const NavigationDestination(icon: Icon(Icons.receipt_long_outlined), selectedIcon: Icon(Icons.receipt_long), label: 'Orders'),
          const NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Account'),
        ],
      ),
    );
  }
}
