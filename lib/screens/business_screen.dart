import 'package:flutter/material.dart';

import '../api/business_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'payment_screen.dart';
import 'photo_forms.dart';
import '../api/models.dart';

/// Business buying: the business account, and requests for quotes.
class BusinessScreen extends StatelessWidget {
  const BusinessScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Business buying')),
      body: Loader<Map<String, dynamic>>(
        load: () async => await api.get('/business') as Map<String, dynamic>,
        builder: (context, json, reload) {
          final status = json['status'] as String?;

          return ListView(padding: const EdgeInsets.all(16), children: [
            Card(
              color: status == 'verified' ? WiBuyColors.greenLight : null,
              child: Padding(
                padding: const EdgeInsets.all(14),
                child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                  Text(switch (status) {
                    'verified' => '✓ ${json['business_name']} is verified',
                    'pending' => 'We are checking ${json['business_name']}',
                    'rejected' => 'Not approved: ${json['rejection_reason']}',
                    _ => 'Buying for a business?',
                  }, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                  const SizedBox(height: 6),
                  const Text('Verified businesses see business-only wholesale prices and can pay big orders in parts.'),
                  if (status != 'verified') ...[
                    const SizedBox(height: 10),
                    FilledButton(
                      onPressed: () async {
                        final sent = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const BusinessRegisterScreen()));
                        if (sent == true) await reload();
                      },
                      child: Text(status == null ? 'Register my business' : 'Send papers again'),
                    ),
                  ],
                ]),
              ),
            ),
            const SizedBox(height: 12),
            ListTile(
              leading: const Icon(Icons.request_quote_outlined),
              title: const Text('Requests for quotes'),
              subtitle: const Text('Tell sellers what you need and compare their prices'),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RfqListScreen())),
            ),
          ]);
        },
      ),
    );
  }
}

class RfqListScreen extends StatefulWidget {
  const RfqListScreen({super.key});

  @override
  State<RfqListScreen> createState() => _RfqListScreenState();
}

class _RfqListScreenState extends State<RfqListScreen> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Requests for quotes')),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () async {
          await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RfqCreateScreen()));
          setState(() => _reload++);
        },
        icon: const Icon(Icons.add),
        label: const Text('New request'),
      ),
      body: Loader<List<RfqInfo>>(
        key: ValueKey(_reload),
        load: () async => ((await api.get('/rfqs') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().map(RfqInfo.fromJson).toList(),
        builder: (context, rfqs, reload) => rfqs.isEmpty
            ? const EmptyState(icon: Icons.request_quote_outlined, title: 'Post what you need. Sellers answer with a price that stays the same for 14 days.')
            : ListView(padding: const EdgeInsets.all(12), children: [
                for (final rfq in rfqs)
                  Card(
                    child: ListTile(
                      title: Text(rfq.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text('${rfq.quantity} ${rfq.unit} · ${rfq.district} · ${rfq.quotesCount} quote(s)'),
                      trailing: StatusChip(rfq.status),
                      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => RfqDetailScreen(id: rfq.id))),
                    ),
                  ),
              ]),
      ),
    );
  }
}

class RfqCreateScreen extends StatefulWidget {
  const RfqCreateScreen({super.key, this.draft});

  /// Filled in by the AI assistant.
  final Map<String, dynamic>? draft;

  @override
  State<RfqCreateScreen> createState() => _RfqCreateScreenState();
}

class _RfqCreateScreenState extends State<RfqCreateScreen> {
  late final _title = TextEditingController(text: widget.draft?['title'] as String? ?? '');
  late final _description = TextEditingController(text: widget.draft?['description'] as String? ?? '');
  late final _quantity = TextEditingController(text: '${widget.draft?['quantity'] ?? ''}');
  late final _unit = TextEditingController(text: widget.draft?['unit'] as String? ?? 'piece');
  late final _target = TextEditingController(text: widget.draft?['target_price'] as String? ?? '');
  late String _district = widget.draft?['district'] as String? ?? 'Western Area Urban';
  bool _busy = false;

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).api.post('/rfqs', {
        'title': _title.text.trim(),
        'description': _description.text.trim(),
        'quantity': int.tryParse(_quantity.text.trim()) ?? 0,
        'unit': _unit.text.trim(),
        'district': _district,
        if (_target.text.trim().isNotEmpty) 'target_price': _target.text.trim(),
        if (widget.draft?['needed_by'] != null) 'needed_by': widget.draft!['needed_by'],
      });
      if (mounted) {
        showMessage(context, 'Request posted. Sellers will send quotes.');
        Navigator.pop(context);
      }
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final districts = SessionScope.of(context).meta?.districts ?? [_district];

    return Scaffold(
      appBar: AppBar(title: const Text('New request for quotes')),
      body: ListView(padding: const EdgeInsets.all(16), children: [
        TextField(controller: _title, decoration: const InputDecoration(labelText: 'What do you need?', hintText: '50 bags of cement')),
        const SizedBox(height: 10),
        Row(children: [
          Expanded(child: TextField(controller: _quantity, keyboardType: TextInputType.number, decoration: const InputDecoration(labelText: 'Quantity'))),
          const SizedBox(width: 10),
          Expanded(child: TextField(controller: _unit, decoration: const InputDecoration(labelText: 'Unit'))),
        ]),
        const SizedBox(height: 10),
        DropdownButtonFormField<String>(
          initialValue: districts.contains(_district) ? _district : null,
          decoration: const InputDecoration(labelText: 'Deliver to'),
          items: [for (final d in districts) DropdownMenuItem(value: d, child: Text(d))],
          onChanged: (v) => setState(() => _district = v ?? _district),
        ),
        const SizedBox(height: 10),
        TextField(controller: _target, keyboardType: const TextInputType.numberWithOptions(decimal: true), decoration: const InputDecoration(labelText: 'Target price per unit (Le, optional)')),
        const SizedBox(height: 10),
        TextField(controller: _description, maxLines: 3, decoration: const InputDecoration(labelText: 'Details (brand, quality, when)')),
        const SizedBox(height: 16),
        FilledButton(onPressed: _busy ? null : _send, child: const Text('Post request')),
      ]),
    );
  }
}

/// All quotes side by side: price, total with delivery, time, rating and reliability. Accept one.
class RfqDetailScreen extends StatelessWidget {
  const RfqDetailScreen({super.key, required this.id});

  final int id;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Compare quotes')),
      body: Loader<RfqInfo>(
        load: () async => RfqInfo.fromJson((await api.get('/rfqs/$id') as Map<String, dynamic>)['data'] as Map<String, dynamic>),
        builder: (context, rfq, reload) {
          final quotes = rfq.quotes ?? const [];

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(12), children: [
              Text(rfq.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              Text('${rfq.quantity} ${rfq.unit} to ${rfq.district}${rfq.targetPrice != null ? ' · target ${formatLe(rfq.targetPrice)} each' : ''}'),
              const SizedBox(height: 12),
              if (quotes.isEmpty) const Text('No quotes yet. Sellers usually answer within a day.', style: TextStyle(color: WiBuyColors.muted)),
              if (quotes.isNotEmpty)
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: DataTable(
                    columnSpacing: 18,
                    columns: const [
                      DataColumn(label: Text('Seller')),
                      DataColumn(label: Text('Price each'), numeric: true),
                      DataColumn(label: Text('Total'), numeric: true),
                      DataColumn(label: Text('Days'), numeric: true),
                      DataColumn(label: Text('Rating')),
                      DataColumn(label: Text('Reliability')),
                    ],
                    rows: [
                      for (final q in quotes)
                        DataRow(cells: [
                          DataCell(Text(q.seller.shopName)),
                          DataCell(Text(formatLe(q.unitPrice))),
                          DataCell(Text(q.canDeliver ? formatLe(q.total) : '${formatLe(q.itemsTotal)} (pickup)')),
                          DataCell(Text('${q.leadTimeDays}')),
                          DataCell(Text(q.seller.ratingCount > 0 ? '★ ${q.seller.ratingAvg.toStringAsFixed(1)}' : '–')),
                          DataCell(Text(q.reliability == null ? 'New' : '${q.reliability}/100')),
                        ]),
                    ],
                  ),
                ),
              const SizedBox(height: 12),
              for (final q in quotes)
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                      Text('${q.seller.shopName}: ${q.productTitle}', style: const TextStyle(fontWeight: FontWeight.w700)),
                      Text('${q.quantity} × ${formatLe(q.unitPrice)} = ${formatLe(q.itemsTotal)}${q.deliveryFee != null ? ' + delivery ${formatLe(q.deliveryFee)}' : ''}'),
                      if (q.note != null) Text(q.note!, style: const TextStyle(color: WiBuyColors.muted)),
                      Text(q.isValid ? 'Price locked until ${q.validUntil?.day}/${q.validUntil?.month}' : 'Expired or closed (${q.status})',
                          style: TextStyle(color: q.isValid ? WiBuyColors.green : Colors.red, fontSize: 13)),
                      if (q.isValid && rfq.status == 'open') ...[
                        const SizedBox(height: 8),
                        FilledButton(onPressed: () => _accept(context, q, rfq), child: Text('Accept for ${formatLe(q.canDeliver ? q.total : q.itemsTotal)}')),
                      ],
                    ]),
                  ),
                ),
            ]),
          );
        },
      ),
    );
  }

  Future<void> _accept(BuildContext context, RfqQuoteInfo quote, RfqInfo rfq) async {
    final session = SessionScope.read(context);
    final name = TextEditingController(text: session.user?.name ?? '');
    final phone = TextEditingController(text: displayPhone(session.user?.phone));
    final address = TextEditingController();
    var method = quote.canDeliver ? 'delivery' : 'pickup';
    var plan = 'full';
    var operator = 'ORANGE';

    final ok = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      builder: (context) => StatefulBuilder(
        builder: (context, setState) => Padding(
          padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
          child: SingleChildScrollView(
            child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
              const Text('Accept this quote', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
              const SizedBox(height: 8),
              SegmentedButton<String>(
                segments: [
                  if (quote.canDeliver) const ButtonSegment(value: 'delivery', label: Text('Delivery')),
                  const ButtonSegment(value: 'pickup', label: Text('Pickup')),
                ],
                selected: {method},
                onSelectionChanged: (s) => setState(() => method = s.first),
              ),
              const SizedBox(height: 8),
              TextField(controller: name, decoration: const InputDecoration(labelText: 'Receiver name')),
              const SizedBox(height: 8),
              TextField(controller: phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Phone (also pays)')),
              if (method == 'delivery') ...[
                const SizedBox(height: 8),
                TextField(controller: address, decoration: InputDecoration(labelText: 'Address in ${rfq.district}')),
              ],
              const SizedBox(height: 8),
              SegmentedButton<String>(
                segments: const [ButtonSegment(value: 'ORANGE', label: Text('Orange Money')), ButtonSegment(value: 'AFRIMONEY', label: Text('Afrimoney'))],
                selected: {operator},
                onSelectionChanged: (s) => setState(() => operator = s.first),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                value: plan == 'milestones',
                onChanged: (v) => setState(() => plan = v ? 'milestones' : 'full'),
                title: const Text('Pay in parts'),
                subtitle: const Text('A deposit now, the balance before delivery (big orders only)'),
              ),
              FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Order and pay')),
            ]),
          ),
        ),
      ),
    );
    if (ok != true || !context.mounted) return;

    try {
      final json = await session.api.post('/quotes/${quote.id}/accept', {
        'recipient_name': name.text.trim(),
        'recipient_phone': phone.text.trim(),
        'address': address.text.trim(),
        'method': method,
        'operator': operator,
        'payer_phone': phone.text.trim(),
        'payment_plan': plan,
      }) as Map<String, dynamic>;
      if (!context.mounted) return;
      Navigator.of(context).pushReplacement(MaterialPageRoute(
        builder: (_) => PaymentScreen(initial: CheckoutInfo.fromJson(json['data'] as Map<String, dynamic>)),
      ));
    } on Object catch (e) {
      if (context.mounted) showMessage(context, e);
    }
  }
}
