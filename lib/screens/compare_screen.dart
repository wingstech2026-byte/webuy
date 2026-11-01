import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'product_screen.dart';

/// Loads products by slug for the lists kept on the phone; ones that are gone are skipped.
Future<List<Product>> _load(ApiClient api, List<String> slugs) async {
  final products = <Product>[];
  for (final slug in slugs) {
    try {
      final json = await api.get('/products/$slug') as Map<String, dynamic>;
      products.add(Product.fromJson(json['data'] as Map<String, dynamic>));
    } on Object {
      // No longer listed.
    }
  }
  return products;
}

/// Up to 3 products side by side, and the products recently looked at on this phone.
class CompareScreen extends StatefulWidget {
  const CompareScreen({super.key});

  @override
  State<CompareScreen> createState() => _CompareScreenState();
}

class _CompareScreenState extends State<CompareScreen> {
  int _version = 0;

  static final _rows = <String, String Function(Product)>{
    'Price': (p) => '${formatLe(p.price)} / ${p.unit}',
    'Bulk prices': (p) => p.tiers.isEmpty ? '—' : p.tiers.map((t) => '${t.minQuantity}+: ${formatLe(t.unitPrice)}').join('\n'),
    'Minimum order': (p) => '${p.minOrderQuantity} ${p.unit}',
    'In stock': (p) => p.stock > 0 ? '${p.stock}' : 'Out of stock',
    'Options': (p) => p.variants.isEmpty ? '—' : p.variants.map((v) => v.name).join(', '),
    'Rating': (p) => p.ratingCount > 0 ? '★ ${p.ratingAvg.toStringAsFixed(1)} (${p.ratingCount})' : 'No ratings yet',
    'Sold': (p) => '${p.soldCount}',
    'Shop': (p) => '${p.seller?.shopName ?? ''}${p.seller?.isVerified == true ? ' ✓' : ''}',
    'Ready in': (p) => p.leadTimeDays != null ? '${p.leadTimeDays} days' : 'In stock now',
  };

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Compare')),
      body: Loader<(List<Product>, List<Product>)>(
        key: ValueKey('$_version-${session.compareList.join(',')}'),
        load: () async => (await _load(session.api, session.compareList), await _load(session.api, session.recentlyViewed.take(10).toList())),
        builder: (context, data, _) {
          final (compared, recent) = data;
          return ListView(padding: const EdgeInsets.all(12), children: [
            if (compared.isEmpty)
              const Card(child: Padding(padding: EdgeInsets.all(14), child: Text('Nothing to compare yet. On a product, tap "Compare" (up to 3).')))
            else
              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: DataTable(
                  columnSpacing: 18,
                  dataRowMaxHeight: double.infinity,
                  columns: [
                    const DataColumn(label: Text('')),
                    for (final p in compared)
                      DataColumn(
                        label: SizedBox(
                          width: 130,
                          child: Row(children: [
                            Expanded(child: Text(p.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800))),
                            IconButton(
                              tooltip: 'Remove',
                              icon: const Icon(Icons.close, size: 18),
                              onPressed: () => setState(() => session.toggleCompare(p.slug)),
                            ),
                          ]),
                        ),
                      ),
                  ],
                  rows: [
                    for (final row in _rows.entries)
                      DataRow(cells: [
                        DataCell(Text(row.key, style: const TextStyle(color: WiBuyColors.muted, fontWeight: FontWeight.w600))),
                        for (final p in compared) DataCell(SizedBox(width: 130, child: Text(row.value(p)))),
                      ]),
                  ],
                ),
              ),
            if (recent.isNotEmpty) ...[
              const Padding(
                padding: EdgeInsets.fromLTRB(2, 20, 2, 8),
                child: Text('Recently viewed', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              ),
              for (final p in recent)
                Card(
                  child: ListTile(
                    leading: Photo(p.thumbUrl, size: 48),
                    title: Text(p.title, maxLines: 1, overflow: TextOverflow.ellipsis),
                    subtitle: Text(formatLe(p.price)),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () async {
                      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ProductScreen(slug: p.slug)));
                      if (mounted) setState(() => _version++);
                    },
                  ),
                ),
            ],
          ]);
        },
      ),
    );
  }
}
