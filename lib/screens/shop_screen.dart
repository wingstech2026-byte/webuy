import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';

class ShopScreen extends StatelessWidget {
  const ShopScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Shop')),
      body: Loader<Map<String, dynamic>>(
        load: () async => await api.get('/shops/$slug') as Map<String, dynamic>,
        builder: (context, json, _) {
          final seller = Seller.fromJson(json['data'] as Map<String, dynamic>);
          final products = (json['products'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();

          return ListView(padding: const EdgeInsets.only(bottom: 24), children: [
            ListTile(
              contentPadding: const EdgeInsets.all(16),
              leading: seller.logoUrl == null
                  ? const CircleAvatar(radius: 28, child: Icon(Icons.storefront))
                  : Photo(seller.logoUrl, size: 56, radius: 28),
              title: Text(seller.shopName, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              subtitle: Text([
                if (seller.isVerified) '✓ Verified',
                seller.location,
                if (seller.ratingCount > 0) '★ ${seller.ratingAvg.toStringAsFixed(1)} (${seller.ratingCount})',
              ].join(' · ')),
            ),
            if ((seller.description ?? '').isNotEmpty)
              Padding(
                padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                child: Text(seller.description!, style: const TextStyle(color: WiBuyColors.text)),
              ),
            if (products.isEmpty)
              const EmptyState(icon: Icons.inventory_2_outlined, title: 'No products yet.')
            else
              ProductGrid(products),
          ]);
        },
      ),
    );
  }
}
