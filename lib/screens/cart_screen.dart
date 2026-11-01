import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'checkout_screen.dart';
import 'login_screen.dart';
import 'product_screen.dart';

class CartScreen extends StatefulWidget {
  const CartScreen({super.key, required this.onShop});

  final VoidCallback onShop;

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  Cart? _cart;
  Object? _error;
  bool _busy = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    // Rebuilds when the person logs in or out.
    if (SessionScope.of(context).isLoggedIn && _cart == null && _error == null && !_busy) {
      _load();
    }
  }

  Future<void> _load([Future<dynamic> Function()? change]) async {
    final session = SessionScope.read(context);
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final json = await (change ?? () => session.api.get('/cart'))() as Map<String, dynamic>;
      final cart = Cart.fromJson(json);
      session.setCartCount(cart.count);
      setState(() => _cart = cart);
    } on Object catch (e) {
      if (_cart == null) {
        setState(() => _error = e);
      } else if (mounted) {
        showMessage(context, e);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final api = session.api;

    Widget body;
    if (!session.isLoggedIn) {
      body = EmptyState(
        icon: Icons.shopping_cart_outlined,
        title: 'Log in to see your cart.',
        action: SizedBox(width: 200, child: FilledButton(onPressed: () => ensureLoggedIn(context), child: const Text('Log in'))),
      );
    } else if (_error != null) {
      body = ErrorRetry(message: _error.toString(), onRetry: _load);
    } else if (_cart == null) {
      body = const Center(child: CircularProgressIndicator());
    } else if (_cart!.isEmpty) {
      body = EmptyState(
        icon: Icons.shopping_cart_outlined,
        title: 'Your cart is empty.',
        action: SizedBox(width: 200, child: FilledButton(onPressed: widget.onShop, child: const Text('Start shopping'))),
      );
    } else {
      final cart = _cart!;
      body = Column(children: [
        Expanded(
          child: RefreshIndicator(
            onRefresh: _load,
            child: ListView(padding: const EdgeInsets.all(12), children: [
              for (final group in cart.groups) ...[
                Padding(
                  padding: const EdgeInsets.fromLTRB(4, 8, 4, 6),
                  child: Text('${group.seller.isVerified ? '✓ ' : ''}${group.seller.shopName}',
                      style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                ),
                for (final line in group.items)
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8),
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(10),
                        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          InkWell(
                            onTap: () => Navigator.of(context)
                                .push(MaterialPageRoute(builder: (_) => ProductScreen(slug: line.product.slug))),
                            child: Photo(line.product.thumbUrl, size: 72),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                              Text(line.product.title, maxLines: 2, overflow: TextOverflow.ellipsis),
                              if (line.variantName != null) Text(line.variantName!, style: const TextStyle(fontWeight: FontWeight.w700, color: WiBuyColors.green)),
                              Text('${formatLe(line.unitPrice)} each${line.isWholesale ? ' · bulk price' : ''}',
                                  style: const TextStyle(color: WiBuyColors.muted, fontSize: 13)),
                              if (line.problem != null)
                                Text(line.problem!, style: const TextStyle(color: Colors.red, fontSize: 13)),
                              Row(children: [
                                IconButton(
                                  visualDensity: VisualDensity.compact,
                                  onPressed: _busy
                                      ? null
                                      : () => _load(() => api.patch('/cart/items/${line.id}', {'quantity': line.quantity - 1})),
                                  icon: const Icon(Icons.remove_circle_outline),
                                ),
                                Text('${line.quantity}', style: const TextStyle(fontSize: 17)),
                                IconButton(
                                  visualDensity: VisualDensity.compact,
                                  onPressed: _busy
                                      ? null
                                      : () => _load(() => api.patch('/cart/items/${line.id}', {'quantity': line.quantity + 1})),
                                  icon: const Icon(Icons.add_circle_outline),
                                ),
                                const Spacer(),
                                Text(formatLe(line.lineTotal), style: const TextStyle(fontWeight: FontWeight.w800)),
                                IconButton(
                                  tooltip: 'Remove',
                                  onPressed: _busy ? null : () => _load(() => api.delete('/cart/items/${line.id}')),
                                  icon: const Icon(Icons.delete_outline),
                                ),
                              ]),
                            ]),
                          ),
                        ]),
                      ),
                    ),
                  ),
              ],
            ]),
          ),
        ),
        SafeArea(
          top: false,
          child: Container(
            color: Colors.white,
            padding: const EdgeInsets.all(12),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Row(children: [
                const Text('Items total', style: TextStyle(fontSize: 16)),
                const Spacer(),
                Text(formatLe(cart.total), style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
              ]),
              if (cart.hasProblems)
                const Padding(
                  padding: EdgeInsets.only(top: 4),
                  child: Text('Fix the items marked in red first.', style: TextStyle(color: Colors.red)),
                ),
              const SizedBox(height: 8),
              FilledButton(
                onPressed: cart.hasProblems || _busy
                    ? null
                    : () async {
                        await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const CheckoutScreen()));
                        if (mounted) _load();
                      },
                child: const Text('Checkout'),
              ),
            ]),
          ),
        ),
      ]);
    }

    return Scaffold(appBar: AppBar(title: const Text('Cart')), body: body);
  }
}
