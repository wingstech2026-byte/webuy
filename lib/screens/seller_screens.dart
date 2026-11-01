import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/business_models.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/photo_picker.dart';

/// "My shop": the seller's overview, with orders, products, earnings and quote requests.
class SellerHomeScreen extends StatefulWidget {
  const SellerHomeScreen({super.key});

  @override
  State<SellerHomeScreen> createState() => _SellerHomeScreenState();
}

class _SellerHomeScreenState extends State<SellerHomeScreen> {
  int _reload = 0;

  Future<void> _open(Widget screen) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => screen));
    setState(() => _reload++);
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('My shop')),
      body: Loader<Map<String, dynamic>>(
        key: ValueKey(_reload),
        load: () async => await api.get('/seller/dashboard') as Map<String, dynamic>,
        builder: (context, d, reload) {
          final counts = d['counts'] as Map<String, dynamic>;
          final money = d['money'] as Map<String, dynamic>;

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(12), children: [
              Text(d['shop_name'] as String, style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
              Text('Delivery reliability: ${d['reliability_score'] ?? 'new shop'}${d['reliability_score'] != null ? '/100' : ''}', style: const TextStyle(color: WiBuyColors.muted)),
              const SizedBox(height: 10),
              Card(
                color: WiBuyColors.green,
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    Expanded(child: _Money('Available', money['available'] as String, white: true)),
                    Expanded(child: _Money('On hold', money['pending'] as String, white: true)),
                  ]),
                ),
              ),
              const SizedBox(height: 10),
              _Tile(Icons.receipt_long, 'Orders', '${counts['new']} new · ${counts['accepted']} to ship', () => _open(const SellerOrdersScreen())),
              _Tile(Icons.inventory_2_outlined, 'Products', '${d['product_count']} products${(d['low_stock'] as List).isNotEmpty ? ' · ${(d['low_stock'] as List).length} low on stock' : ''}',
                  () => _open(const SellerProductsScreen())),
              _Tile(Icons.payments_outlined, 'Earnings and payouts', 'Available ${formatLe(money['available'] as String)}', () => _open(const SellerEarningsScreen())),
              _Tile(Icons.request_quote_outlined, 'Quote requests', '${d['open_rfqs']} open request(s) from buyers', () => _open(const SellerRfqsScreen())),
            ]),
          );
        },
      ),
    );
  }
}

class _Money extends StatelessWidget {
  const _Money(this.label, this.amount, {this.white = false});

  final String label;
  final String amount;
  final bool white;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text(label, style: TextStyle(color: white ? Colors.white70 : WiBuyColors.muted)),
        Text(formatLe(amount), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w900, color: white ? Colors.white : WiBuyColors.text)),
      ]);
}

class _Tile extends StatelessWidget {
  const _Tile(this.icon, this.title, this.subtitle, this.onTap);

  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: Icon(icon, color: WiBuyColors.green),
          title: Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
          subtitle: Text(subtitle),
          trailing: const Icon(Icons.chevron_right),
          onTap: onTap,
        ),
      );
}

// ------------------------------------------------------------------ orders

class SellerOrdersScreen extends StatefulWidget {
  const SellerOrdersScreen({super.key});

  @override
  State<SellerOrdersScreen> createState() => _SellerOrdersScreenState();
}

class _SellerOrdersScreenState extends State<SellerOrdersScreen> {
  static const _tabs = {'new': 'New', 'accepted': 'To ship', 'shipped': 'Shipped', 'delivered': 'Delivered', 'problems': 'Problems', 'finished': 'Finished'};
  String _tab = 'new';
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Shop orders')),
      body: Column(children: [
        SizedBox(
          height: 52,
          child: ListView(scrollDirection: Axis.horizontal, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8), children: [
            for (final e in _tabs.entries)
              Padding(
                padding: const EdgeInsets.only(right: 8),
                child: ChoiceChip(label: Text(e.value), selected: _tab == e.key, onSelected: (_) => setState(() => _tab = e.key)),
              ),
          ]),
        ),
        Expanded(
          child: Loader<List<Order>>(
            key: ValueKey('$_tab$_reload'),
            load: () async => Paged.fromJson(await api.get('/seller/orders', query: {'tab': _tab}) as Map<String, dynamic>, Order.fromJson).items,
            builder: (context, orders, reload) => orders.isEmpty
                ? const EmptyState(icon: Icons.receipt_long_outlined, title: 'No orders here.')
                : ListView(padding: const EdgeInsets.all(12), children: [
                    for (final o in orders)
                      Card(
                        child: ListTile(
                          title: Text('${o.number} · ${formatLe(o.total)}', style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text('${o.items.map((i) => '${i.quantity} × ${i.displayTitle}').join(', ')}\n${o.isPickup ? 'Pickup' : 'Delivery to ${o.district ?? ''}'}'),
                          isThreeLine: true,
                          trailing: StatusChip(o.statusLabel, status: o.status),
                          onTap: () async {
                            await Navigator.of(context).push(MaterialPageRoute(builder: (_) => SellerOrderScreen(id: o.id)));
                            setState(() => _reload++);
                          },
                        ),
                      ),
                  ]),
          ),
        ),
      ]),
    );
  }
}

class SellerOrderScreen extends StatefulWidget {
  const SellerOrderScreen({super.key, required this.id});

  final int id;

  @override
  State<SellerOrderScreen> createState() => _SellerOrderScreenState();
}

class _SellerOrderScreenState extends State<SellerOrderScreen> {
  int _reload = 0;
  bool _busy = false;

  Future<void> _act(String action, {String? reason}) async {
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).api.post('/seller/orders/${widget.id}/$action', {if (reason != null) 'reason': reason});
      if (mounted) showMessage(context, 'Done. The buyer was told by SMS.');
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _reload++;
        });
      }
    }
  }

  /// A buyer's return: accept (with how to send it back), refuse (WiBuy decides), or confirm it came back.
  Future<void> _returnAct(int returnId, String action, Map<String, dynamic> body, String done) async {
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).api.post('/seller/returns/$returnId/$action', body);
      if (mounted) showMessage(context, done);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _reload++;
        });
      }
    }
  }

  List<Widget> _returnPanel(Map<String, dynamic> r) {
    final id = (r['id'] as num).toInt();
    return [
      const Divider(height: 28),
      Text('↩️ Return: ${r['status_label']}', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
      Text('${r['items']} · ${r['reason']}'),
      Text('Refund to the buyer: ${formatLe(r['refund_amount'] as String?)}'),
      if (r['return_instructions'] != null) Text('You told the buyer: ${r['return_instructions']}'),
      const SizedBox(height: 8),
      if (r['status'] == 'requested') ...[
        FilledButton(
          onPressed: _busy
              ? null
              : () async {
                  final how = await _ask('How should the buyer send it back?', 'Bring it to the shop, or our rider collects it');
                  if (how != null && how.length >= 5) await _returnAct(id, 'accept', {'instructions': how}, 'Return accepted. The buyer was told how to send it back.');
                },
          child: const Text('Accept the return'),
        ),
        const SizedBox(height: 8),
        OutlinedButton(
          onPressed: _busy
              ? null
              : () async {
                  final why = await _ask('Why do you refuse? (WiBuy decides)', 'It was fine when it left the shop');
                  if (why != null && why.length >= 5) await _returnAct(id, 'refuse', {'note': why}, 'Refused. The WiBuy team will decide.');
                },
          child: const Text('Refuse'),
        ),
      ],
      if (r['status'] == 'accepted')
        FilledButton(
          onPressed: _busy ? null : () => _returnAct(id, 'received', {}, 'Thanks. The stock is back and the buyer is being refunded.'),
          child: const Text('I have the items back'),
        ),
    ];
  }

  Future<String?> _ask(String title, String hint) {
    final text = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(title),
        content: TextField(controller: text, maxLength: 200, decoration: InputDecoration(hintText: hint)),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, text.text.trim()), child: const Text('OK')),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Shop order')),
      body: Loader<Map<String, dynamic>>(
        key: ValueKey(_reload),
        load: () async => await api.get('/seller/orders/${widget.id}') as Map<String, dynamic>,
        builder: (context, json, _) {
          final order = Order.fromJson(json['data'] as Map<String, dynamic>);
          final buyer = json['buyer'] as Map<String, dynamic>;

          return ListView(padding: const EdgeInsets.all(14), children: [
            Row(children: [
              Expanded(child: Text(order.number, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900))),
              StatusChip(order.statusLabel, status: order.status),
            ]),
            const SizedBox(height: 8),
            for (final item in order.items) Text('${item.quantity} × ${item.displayTitle} @ ${formatLe(item.unitPrice)}'),
            const Divider(),
            Text('Buyer: ${buyer['name']} · ${displayPhone(buyer['phone'] as String?)}'),
            Text(order.isPickup ? 'Pickup at your shop' : 'Deliver to: ${[order.address, order.district].whereType<String>().join(', ')}'),
            if (order.giftNote != null) Text('🎁 Include: ${order.giftNote}', style: const TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Text('You receive ${formatLe(json['earnings'] as String)} after the buyer confirms.', style: const TextStyle(color: WiBuyColors.green, fontWeight: FontWeight.w700)),
            if (order.milestonesJson.isNotEmpty)
              Text(order.milestonesJson.map(MilestoneInfo.fromJson).map((m) => '${m.label}: ${m.isPaid ? 'paid' : 'not paid yet'}').join(' · ')),
            const SizedBox(height: 16),
            if (json['can_accept'] == true) ...[
              FilledButton(onPressed: _busy ? null : () => _act('accept'), child: const Text('Accept order')),
              const SizedBox(height: 8),
              OutlinedButton(
                onPressed: _busy
                    ? null
                    : () async {
                        final reason = await _ask('Why can you not fulfil it?', 'Out of stock');
                        if (reason != null && reason.isNotEmpty) await _act('reject', reason: reason);
                      },
                child: const Text('Reject (the buyer is refunded)'),
              ),
            ],
            if (json['can_ship'] == true)
              FilledButton(
                onPressed: _busy
                    ? null
                    : () async {
                        final note = await _ask(order.isPickup ? 'Ready for pickup' : 'Shipped', order.isPickup ? 'Come after 2pm' : 'Okada rider Musa, 076 555 444');
                        if (note != null) await _act('ship', reason: note.isEmpty ? null : note);
                      },
                child: Text(order.isPickup ? 'Ready for pickup' : 'Mark as shipped'),
              ),
            if (order.status == 'accepted' && json['can_ship'] != true)
              const Text('Waiting for the buyer to pay the balance before you ship.', style: TextStyle(color: WiBuyColors.orange)),
            if (json['can_deliver'] == true) FilledButton(onPressed: _busy ? null : () => _act('deliver'), child: const Text('Mark as delivered')),
            if (order.returnJson != null) ..._returnPanel(order.returnJson!),
          ]);
        },
      ),
    );
  }
}

// ------------------------------------------------------------------ products

class SellerProductsScreen extends StatefulWidget {
  const SellerProductsScreen({super.key});

  @override
  State<SellerProductsScreen> createState() => _SellerProductsScreenState();
}

class _SellerProductsScreenState extends State<SellerProductsScreen> {
  int _reload = 0;

  Future<void> _edit([Map<String, dynamic>? product]) async {
    final saved = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => ProductFormScreen(product: product)));
    if (saved == true) setState(() => _reload++);
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('My products'), actions: [
        TextButton.icon(
          onPressed: () async {
            final added = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const ProductImportScreen()));
            if (added == true) setState(() => _reload++);
          },
          icon: const Icon(Icons.upload_file),
          label: const Text('Bulk upload'),
          style: TextButton.styleFrom(foregroundColor: Colors.white),
        ),
      ]),
      floatingActionButton: FloatingActionButton.extended(onPressed: () => _edit(), icon: const Icon(Icons.add), label: const Text('Add product')),
      body: Loader<List<Map<String, dynamic>>>(
        key: ValueKey(_reload),
        load: () async => ((await api.get('/seller/products') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().toList(),
        builder: (context, products, reload) => products.isEmpty
            ? const EmptyState(icon: Icons.inventory_2_outlined, title: 'Add your first product with photos.')
            : ListView(padding: const EdgeInsets.fromLTRB(12, 12, 12, 90), children: [
                for (final p in products)
                  Card(
                    child: ListTile(
                      leading: Photo(p['thumb_url'] as String?, size: 52),
                      title: Text(p['title'] as String, style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text('${formatLe(p['price'] as String)} · ${p['stock']} in stock'),
                      trailing: StatusChip(p['status'] == 'active' ? 'Live' : (p['status'] as String), status: p['status'] == 'active' ? 'completed' : 'paid'),
                      onTap: () => _edit(p),
                    ),
                  ),
              ]),
      ),
    );
  }
}

class ProductFormScreen extends StatefulWidget {
  const ProductFormScreen({super.key, this.product});

  final Map<String, dynamic>? product;

  @override
  State<ProductFormScreen> createState() => _ProductFormScreenState();
}

class _ProductFormScreenState extends State<ProductFormScreen> {
  Map<String, dynamic>? get p => widget.product;
  late final _title = TextEditingController(text: p?['title'] as String? ?? '');
  late final _description = TextEditingController(text: p?['description'] as String? ?? '');
  late final _price = TextEditingController(text: p?['price'] as String? ?? '');
  late final _stock = TextEditingController(text: '${p?['stock'] ?? ''}');
  late final _unit = TextEditingController(text: p?['unit'] as String? ?? 'piece');
  late final _moq = TextEditingController(text: '${p?['min_order_quantity'] ?? 1}');
  late bool _live = (p?['status'] ?? 'active') == 'active';
  late String _condition = p?['condition'] as String? ?? 'new';
  late final List<Map<String, dynamic>> _photos = ((p?['photos'] as List?) ?? []).whereType<Map<String, dynamic>>().toList();
  final List<int> _remove = [];
  List<PickedPhoto> _newPhotos = [];
  bool _busy = false;
  late final List<_OptionRow> _options = [
    for (final v in ((p?['variants'] as List?) ?? []).whereType<Map<String, dynamic>>())
      _OptionRow(id: v['id'] as int?, name: v['name'] as String? ?? '', price: v['price'] as String? ?? '', stock: '${v['stock'] ?? 0}'),
  ];

  @override
  void dispose() {
    for (final c in [_title, _description, _price, _stock, _unit, _moq]) {
      c.dispose();
    }
    for (final o in _options) {
      o.dispose();
    }
    super.dispose();
  }

  Future<void> _save() async {
    setState(() => _busy = true);
    final api = SessionScope.read(context).api;
    final fields = <String, String>{
      'title': _title.text.trim(),
      'description': _description.text.trim(),
      'price': _price.text.trim(),
      'stock': _stock.text.trim(),
      'unit': _unit.text.trim(),
      'min_order_quantity': _moq.text.trim(),
      'condition': _condition,
      'status': _live ? 'active' : 'draft',
      for (var i = 0; i < _remove.length; i++) 'remove_images[$i]': '${_remove[i]}',
      // Options (size, colour): an empty list switches them off.
      'variants_sent': '1',
      for (final (i, o) in _options.where((o) => o.name.text.trim().isNotEmpty).indexed) ...{
        if (o.id != null) 'variants[$i][id]': '${o.id}',
        'variants[$i][name]': o.name.text.trim(),
        'variants[$i][price]': o.price.text.trim(),
        'variants[$i][stock]': o.stock.text.trim().isEmpty ? '0' : o.stock.text.trim(),
      },
      // Keep the existing wholesale tiers (edit them on the website).
      for (final (i, t) in ((p?['all_tiers'] as List?) ?? []).whereType<Map<String, dynamic>>().indexed) ...{
        'tiers[$i][min_quantity]': '${t['min_quantity']}',
        'tiers[$i][unit_price]': '${t['unit_price']}',
        if (t['business_only'] == true) 'tiers[$i][business_only]': '1',
      },
    };
    try {
      await api.postMultipart(p == null ? '/seller/products' : '/seller/products/${p!['slug']}', fields, {
        'images[]': [for (final ph in _newPhotos) (ph.$1, ph.$2)],
      });
      if (!mounted) return;
      showMessage(context, p == null ? 'Product added. Photos take a moment to process.' : 'Product saved.');
      Navigator.pop(context, true);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(p == null ? 'Add product' : 'Edit product')),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          if (_photos.isNotEmpty) ...[
            const Text('Current photos (tap × to remove)', style: TextStyle(fontWeight: FontWeight.w700)),
            const SizedBox(height: 6),
            Wrap(spacing: 8, children: [
              for (final photo in _photos)
                Stack(children: [
                  Opacity(opacity: _remove.contains(photo['id']) ? 0.3 : 1, child: Photo(photo['thumb_url'] as String?, size: 72)),
                  Positioned(
                    right: 0,
                    child: InkWell(
                      onTap: () => setState(() => _remove.contains(photo['id']) ? _remove.remove(photo['id']) : _remove.add(photo['id'] as int)),
                      child: const CircleAvatar(radius: 11, backgroundColor: Colors.black54, child: Icon(Icons.close, size: 14, color: Colors.white)),
                    ),
                  ),
                ]),
            ]),
            const SizedBox(height: 12),
          ],
          PhotoPicker(onChanged: (ph) => _newPhotos = ph, max: 6, label: 'Add photos'),
          const SizedBox(height: 12),
          TextField(controller: _title, decoration: const InputDecoration(labelText: 'Product name')),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(child: TextField(controller: _price, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Price (Le)'))),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _unit, decoration: const InputDecoration(labelText: 'Unit (bag, piece…)'))),
          ]),
          const SizedBox(height: 10),
          Row(children: [
            Expanded(
              child: TextField(
                controller: _stock,
                enabled: _options.isEmpty,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(labelText: 'In stock', helperText: _options.isEmpty ? null : 'Total of the options'),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(child: TextField(controller: _moq, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Minimum order'))),
          ]),
          const SizedBox(height: 10),
          SegmentedButton<String>(
            segments: const [ButtonSegment(value: 'new', label: Text('New')), ButtonSegment(value: 'used', label: Text('Used'))],
            selected: {_condition},
            onSelectionChanged: (s) => setState(() => _condition = s.first),
          ),
          const SizedBox(height: 14),
          const Text('Options (size, colour…)', style: TextStyle(fontWeight: FontWeight.w700)),
          const Text('Leave empty when there is only one kind. Price empty = the main price.', style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
          for (final o in _options)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Row(children: [
                Expanded(flex: 3, child: TextField(controller: o.name, decoration: const InputDecoration(labelText: 'Option', hintText: 'Large / Red'))),
                const SizedBox(width: 6),
                Expanded(flex: 2, child: TextField(controller: o.price, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Price'))),
                const SizedBox(width: 6),
                Expanded(flex: 2, child: TextField(controller: o.stock, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Stock'))),
                IconButton(
                  tooltip: 'Remove option',
                  icon: const Icon(Icons.delete_outline),
                  onPressed: () => setState(() {
                    _options.remove(o);
                    o.dispose();
                  }),
                ),
              ]),
            ),
          Align(
            alignment: Alignment.centerLeft,
            child: TextButton.icon(onPressed: _options.length >= 30 ? null : () => setState(() => _options.add(_OptionRow())), icon: const Icon(Icons.add), label: const Text('Add option')),
          ),
          const SizedBox(height: 4),
          TextField(controller: _description, maxLines: 4, decoration: const InputDecoration(labelText: 'Description')),
          SwitchListTile(contentPadding: EdgeInsets.zero, value: _live, onChanged: (v) => setState(() => _live = v), title: const Text('Show to buyers now')),
          const Text('Wholesale prices, member prices and samples: edit on the website.', style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
          const SizedBox(height: 14),
          FilledButton(onPressed: _busy ? null : _save, child: Text(_busy ? 'Saving…' : 'Save product')),
        ]),
      );
}

/// One option row in the product form.
class _OptionRow {
  _OptionRow({this.id, String name = '', String price = '', String stock = ''})
      : name = TextEditingController(text: name),
        price = TextEditingController(text: price),
        stock = TextEditingController(text: stock);

  final int? id;
  final TextEditingController name;
  final TextEditingController price;
  final TextEditingController stock;

  void dispose() {
    name.dispose();
    price.dispose();
    stock.dispose();
  }
}

/// Bulk upload: paste rows copied from a spreadsheet (or CSV text). Every row is checked first;
/// products are added as drafts so the seller can add photos before showing them.
class ProductImportScreen extends StatefulWidget {
  const ProductImportScreen({super.key});

  @override
  State<ProductImportScreen> createState() => _ProductImportScreenState();
}

class _ProductImportScreenState extends State<ProductImportScreen> {
  final _rows = TextEditingController();
  List<String> _errors = [];
  bool _busy = false;

  @override
  void dispose() {
    _rows.dispose();
    super.dispose();
  }

  Future<void> _upload() async {
    setState(() {
      _busy = true;
      _errors = [];
    });
    try {
      final json = await SessionScope.read(context).api.post('/seller/products/import', {'csv': _rows.text}) as Map<String, dynamic>;
      if (!mounted) return;
      showMessage(context, json['message'] as String);
      Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (!mounted) return;
      final rows = e.errors['rows'];
      rows == null || rows.isEmpty ? showMessage(context, e) : setState(() => _errors = rows);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Bulk upload')),
        body: Loader<Map<String, dynamic>>(
          load: () async => await SessionScope.read(context).api.get('/seller/products/import') as Map<String, dynamic>,
          builder: (context, json, reload) => ListView(padding: const EdgeInsets.all(16), children: [
            const Text('Add many products at once', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            const SizedBox(height: 6),
            Text(
              'In your spreadsheet, select the rows (with the heading row first), copy, and paste them below. '
              'Up to ${json['max_rows']} products. They are added as drafts: add photos, then set each one live.',
            ),
            const SizedBox(height: 8),
            Text('Columns: ${(json['columns'] as List).join(', ')}. Only title, price and stock are needed.', style: const TextStyle(color: WiBuyColors.muted, fontSize: 12)),
            TextButton(onPressed: () => setState(() => _rows.text = json['example'] as String), child: const Text('Fill in an example')),
            TextField(
              controller: _rows,
              minLines: 6,
              maxLines: 14,
              style: const TextStyle(fontFamily: 'monospace', fontSize: 12),
              decoration: const InputDecoration(labelText: 'Rows', hintText: 'title,price,stock\nOil 5L,95,10', alignLabelWithHint: true),
            ),
            if (_errors.isNotEmpty) ...[
              const SizedBox(height: 10),
              const Text('Nothing was added. Fix these rows:', style: TextStyle(fontWeight: FontWeight.w700, color: Colors.red)),
              for (final e in _errors) Text('• $e', style: const TextStyle(color: Colors.red)),
            ],
            const SizedBox(height: 12),
            FilledButton(onPressed: _busy ? null : _upload, child: Text(_busy ? 'Checking…' : 'Add products')),
          ]),
        ),
      );
}

// ------------------------------------------------------------------ earnings

class SellerEarningsScreen extends StatelessWidget {
  const SellerEarningsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Earnings')),
      body: Loader<Map<String, dynamic>>(
        load: () async => await api.get('/seller/earnings') as Map<String, dynamic>,
        builder: (context, json, reload) {
          final money = json['money'] as Map<String, dynamic>;
          final to = json['payout_to'] as Map<String, dynamic>;

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(14), children: [
              Row(children: [
                Expanded(child: _Money('Available', money['available'] as String)),
                Expanded(child: _Money('On hold', money['pending'] as String)),
              ]),
              const SizedBox(height: 6),
              Text('Paid out: ${formatLe(money['paid_out'] as String)} · on the way: ${formatLe(money['in_transit'] as String)}'),
              const SizedBox(height: 12),
              FilledButton(
                onPressed: () async {
                  final amount = TextEditingController(text: money['available'] as String);
                  final ok = await showDialog<bool>(
                    context: context,
                    builder: (context) => AlertDialog(
                      title: const Text('Request a payout'),
                      content: Column(mainAxisSize: MainAxisSize.min, children: [
                        Text('To ${to['operator']} ${displayPhone(to['phone'] as String?)} · minimum ${formatLe(json['min_payout'] as String)}'),
                        TextField(controller: amount, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Amount (Le)')),
                      ]),
                      actions: [
                        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
                        FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Request')),
                      ],
                    ),
                  );
                  if (ok != true || !context.mounted) return;
                  try {
                    final res = await api.post('/seller/payouts', {'amount': amount.text.trim()}) as Map<String, dynamic>;
                    if (context.mounted) showMessage(context, res['message'] as String);
                  } on Object catch (e) {
                    if (context.mounted) showMessage(context, e);
                  }
                  await reload();
                },
                child: const Text('Request payout'),
              ),
              const SizedBox(height: 16),
              const Text('Payouts', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              for (final pay in (json['payouts'] as List).whereType<Map<String, dynamic>>())
                ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text(formatLe('${pay['amount']}')), trailing: StatusChip('${pay['status']}')),
              const SizedBox(height: 10),
              const Text('Latest money in and out', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
              for (final e in (json['entries'] as List).whereType<Map<String, dynamic>>())
                ListTile(dense: true, contentPadding: EdgeInsets.zero, title: Text('${e['description']}'), trailing: Text(formatLe('${e['amount']}'))),
            ]),
          );
        },
      ),
    );
  }
}

// ------------------------------------------------------------------ quote requests

class SellerRfqsScreen extends StatefulWidget {
  const SellerRfqsScreen({super.key});

  @override
  State<SellerRfqsScreen> createState() => _SellerRfqsScreenState();
}

class _SellerRfqsScreenState extends State<SellerRfqsScreen> {
  int _reload = 0;

  Future<void> _quote(RfqInfo rfq) async {
    final api = SessionScope.read(context).api;
    final products = ((await api.get('/seller/products') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().where((p) => p['status'] == 'active').toList();
    if (!mounted) return;
    if (products.isEmpty) {
      showMessage(context, 'Add a live product first.');
      return;
    }
    var productId = (products.first['id'] as num).toInt();
    final price = TextEditingController();
    final quantity = TextEditingController(text: '${rfq.quantity}');
    final days = TextEditingController(text: '1');
    final note = TextEditingController();

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
          child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            Text('Quote: ${rfq.title}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
            DropdownButtonFormField<int>(
              initialValue: productId,
              decoration: const InputDecoration(labelText: 'Your product'),
              items: [for (final p in products) DropdownMenuItem(value: (p['id'] as num).toInt(), child: Text(p['title'] as String, overflow: TextOverflow.ellipsis))],
              onChanged: (v) => setState(() => productId = v ?? productId),
            ),
            const SizedBox(height: 8),
            Row(children: [
              Expanded(child: TextField(controller: price, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Price each (Le)'))),
              const SizedBox(width: 8),
              Expanded(child: TextField(controller: quantity, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Quantity'))),
            ]),
            const SizedBox(height: 8),
            TextField(controller: days, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Days to deliver')),
            const SizedBox(height: 8),
            TextField(controller: note, decoration: const InputDecoration(labelText: 'Note (brand, quality)')),
            const SizedBox(height: 12),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Send quote (price locked 14 days)')),
          ]),
        ),
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await api.post('/seller/rfqs/${rfq.id}/quote', {
        'product_id': productId,
        'unit_price': price.text.trim(),
        'quantity': int.tryParse(quantity.text.trim()) ?? 0,
        'lead_time_days': int.tryParse(days.text.trim()) ?? 0,
        'note': note.text.trim(),
      });
      if (mounted) showMessage(context, 'Quote sent.');
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
    setState(() => _reload++);
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Quote requests')),
      body: Loader<List<RfqInfo>>(
        key: ValueKey(_reload),
        load: () async => ((await api.get('/seller/rfqs') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().map(RfqInfo.fromJson).toList(),
        builder: (context, rfqs, _) => rfqs.isEmpty
            ? const EmptyState(icon: Icons.request_quote_outlined, title: 'No new requests. Buyers post what they need here.')
            : ListView(padding: const EdgeInsets.all(12), children: [
                for (final r in rfqs)
                  Card(
                    child: ListTile(
                      title: Text(r.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text('${r.quantity} ${r.unit} · ${r.district}${r.targetPrice != null ? ' · target ${formatLe(r.targetPrice)}' : ''}${r.description != null ? '\n${r.description}' : ''}'),
                      trailing: FilledButton(style: FilledButton.styleFrom(minimumSize: const Size(80, 40)), onPressed: () => _quote(r), child: const Text('Quote')),
                    ),
                  ),
              ]),
      ),
    );
  }
}
