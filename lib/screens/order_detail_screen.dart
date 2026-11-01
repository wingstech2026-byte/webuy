import 'dart:async';

import 'package:flutter/material.dart';
import 'package:latlong2/latlong.dart';

import '../api/business_models.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/map_widgets.dart';
import '../widgets/payment_prompt.dart';
import '../widgets/photo_picker.dart';
import 'photo_forms.dart';

class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({super.key, required this.orderId});

  final int orderId;

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  Order? _order;
  Object? _error;
  bool _busy = false;

  /// While a rider carries the parcel, the order refreshes every 20 seconds (live distance and map).
  Timer? _live;

  @override
  void dispose() {
    _live?.cancel();
    super.dispose();
  }

  void _followLive() {
    final onTheWay = _order?.riderDelivery?['stage'] == 'picked_up';
    if (onTheWay && _live == null) {
      _live = Timer.periodic(const Duration(seconds: 20), (_) async {
        try {
          final json = await SessionScope.read(context).api.get('/orders/${widget.orderId}') as Map<String, dynamic>;
          if (mounted) setState(() => _order = Order.fromJson(json['data'] as Map<String, dynamic>));
          _followLive();
        } on Object {
          // try again in 20 seconds
        }
      });
    } else if (!onTheWay) {
      _live?.cancel();
      _live = null;
    }
  }

  @override
  void initState() {
    super.initState();
    _run(() => SessionScope.read(context).api.get('/orders/${widget.orderId}'));
  }

  Future<void> _run(Future<dynamic> Function() request) async {
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final json = await request() as Map<String, dynamic>;
      setState(() => _order = Order.fromJson(json['data'] as Map<String, dynamic>));
      _followLive();
    } on Object catch (e) {
      if (_order == null) {
        setState(() => _error = e);
      } else if (mounted) {
        showMessage(context, e);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _confirm() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Did you get your order?'),
        content: const Text('Only confirm when you have everything. The seller is then paid.'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Not yet')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Yes, I got it')),
        ],
      ),
    );
    if (yes == true && mounted) {
      await _run(() => SessionScope.read(context).api.post('/orders/${widget.orderId}/confirm'));
    }
  }

  Future<void> _review(Order order) async {
    final result = await showModalBottomSheet<(int, String, List<PickedPhoto>)>(
      context: context,
      isScrollControlled: true,
      builder: (_) => const _ReviewSheet(),
    );
    if (result == null || !mounted) return;
    final (rating, comment, photos) = result;
    // Photos go with the first product (the order's "what I got" pictures).
    await _run(() => SessionScope.read(context).api.postMultipart('/orders/${widget.orderId}/review', {
          'seller_rating': '$rating',
          if (comment.isNotEmpty) 'seller_comment': comment,
          for (final item in order.items) 'items[${item.id}][rating]': '$rating',
        }, {
          if (photos.isNotEmpty && order.items.isNotEmpty) 'items[${order.items.first.id}][photos][]': photos,
        }));
    if (mounted) showMessage(context, 'Thank you for your review.');
  }

  @override
  Widget build(BuildContext context) {
    final order = _order;

    return Scaffold(
      appBar: AppBar(title: Text(order == null ? 'Order' : 'Order ${order.number}')),
      body: order == null
          ? (_error != null
              ? ErrorRetry(message: _error.toString(), onRetry: () => _run(() => SessionScope.read(context).api.get('/orders/${widget.orderId}')))
              : const Center(child: CircularProgressIndicator()))
          : ListView(padding: const EdgeInsets.all(14), children: [
              Row(children: [
                Expanded(
                  child: Text(order.seller?.shopName ?? '', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                ),
                StatusChip(order.statusLabel, status: order.status),
              ]),
              if (order.rejectionReason != null) Text('Reason: ${order.rejectionReason}', style: const TextStyle(color: Colors.red)),
              if (order.refundReason != null) Text('Refund: ${order.refundReason}'),
              const SizedBox(height: 12),
              Card(
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(children: [
                    for (final step in order.timeline)
                      Row(children: [
                        Icon(step.at != null ? Icons.check_circle : Icons.radio_button_unchecked,
                            color: step.at != null ? WiBuyColors.green : WiBuyColors.muted),
                        const SizedBox(width: 10),
                        Expanded(child: Padding(padding: const EdgeInsets.symmetric(vertical: 6), child: Text(step.label))),
                        if (step.at != null)
                          Text(_date(step.at!.toLocal()), style: const TextStyle(color: WiBuyColors.muted, fontSize: 13)),
                      ]),
                  ]),
                ),
              ),
              const SizedBox(height: 12),
              for (final item in order.items)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Photo(item.thumbUrl, size: 52),
                  title: Text(item.displayTitle),
                  subtitle: Text('${item.quantity} × ${formatLe(item.unitPrice)}'),
                  trailing: Text(formatLe(item.lineTotal), style: const TextStyle(fontWeight: FontWeight.w700)),
                ),
              const Divider(),
              _row('Items', order.subtotal),
              _row(order.isPickup ? 'Pickup' : 'Delivery', order.deliveryFee),
              if (order.discount != '0.00') _row('Coupon and WiBuy Pass savings', '-${order.discount}'),
              _row('Total paid', order.amountPaid, bold: true),
              if (order.giftNote != null)
                Padding(padding: const EdgeInsets.only(top: 6), child: Text('🎁 ${order.giftNote}', style: const TextStyle(color: WiBuyColors.green, fontWeight: FontWeight.w700))),
              const SizedBox(height: 8),
              Text(
                order.isPickup
                    ? 'Pick up at: ${order.seller?.pickupAddress ?? order.seller?.location ?? ''}'
                    : 'Deliver to: ${[order.address, order.district].whereType<String>().join(', ')}',
                style: const TextStyle(color: WiBuyColors.muted),
              ),
              const SizedBox(height: 18),
              if (order.collectStation != null) ...[
                Card(
                  child: ListTile(
                    leading: const Icon(Icons.storefront, color: WiBuyColors.green),
                    title: Text('📦 Collect at ${order.collectStation!['name']}'),
                    subtitle: Text('${order.collectStation!['address']} (${order.collectStation!['district']})'
                        '${order.collectStation!['opening_hours'] != null ? ' · ${order.collectStation!['opening_hours']}' : ''}\n'
                        '${order.collectStation!['ready'] == true ? 'It is there now. Show the code from our SMS.' : 'We text you a code when it arrives.'}'),
                  ),
                ),
                const SizedBox(height: 12),
              ],
              if (order.riderDelivery != null) ...[
                _riderCard(order.riderDelivery!),
                const SizedBox(height: 12),
              ],
              if (order.milestonesJson.isNotEmpty) ...[
                const Text('Paid in parts', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w800)),
                for (final m in order.milestonesJson.map(MilestoneInfo.fromJson))
                  ListTile(
                    dense: true,
                    contentPadding: EdgeInsets.zero,
                    leading: Icon(m.isPaid ? Icons.check_circle : Icons.schedule, color: m.isPaid ? WiBuyColors.green : WiBuyColors.muted),
                    title: Text(m.label),
                    trailing: Text(formatLe(m.amount), style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: !m.isPaid && order.canPayBalance
                        ? Align(
                            alignment: Alignment.centerLeft,
                            child: FilledButton(
                              style: FilledButton.styleFrom(minimumSize: const Size(140, 40)),
                              onPressed: _busy ? null : () => _payPart(m),
                              child: const Text('Pay now'),
                            ),
                          )
                        : null,
                  ),
                if (order.inspectionJson != null) _inspectionCard(InspectionInfo.fromJson(order.inspectionJson!)),
                if (order.canRequestInspection)
                  OutlinedButton.icon(
                    onPressed: _busy ? null : _requestInspection,
                    icon: const Icon(Icons.fact_check_outlined),
                    label: const Text('Book a WiBuy inspection before paying the balance'),
                  ),
                const SizedBox(height: 10),
              ],
              if (order.canConfirmDelivery) ...[
                FilledButton(onPressed: _busy ? null : _confirm, child: const Text('I got my order')),
                const SizedBox(height: 10),
              ],
              if (order.canReview) ...[
                OutlinedButton(onPressed: _busy ? null : () => _review(order), child: const Text('Rate this order')),
                const SizedBox(height: 10),
              ],
              if (order.returnJson != null) ...[
                Card(
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text('↩️ Return: ${order.returnJson!['status_label']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                      Text('${order.returnJson!['items']} · ${order.returnJson!['reason']}'),
                      Text('Refund: ${formatLe(order.returnJson!['refund_amount'] as String?)}'),
                      if (order.returnJson!['return_instructions'] != null)
                        Text('How to send it back: ${order.returnJson!['return_instructions']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    ]),
                  ),
                ),
                const SizedBox(height: 10),
              ],
              if (order.canReturn) ...[
                OutlinedButton.icon(
                  onPressed: () async {
                    final sent = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => ReturnRequestScreen(order: order)));
                    if (sent == true && mounted) await _run(() => SessionScope.read(context).api.get('/orders/${widget.orderId}'));
                  },
                  icon: const Icon(Icons.assignment_return_outlined),
                  label: const Text('Return items'),
                ),
                const SizedBox(height: 10),
              ],
              if (order.canReportProblem)
                TextButton.icon(
                  onPressed: () async {
                    final sent = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => ProblemReportScreen(orderId: order.id)));
                    if (sent == true && mounted) await _run(() => SessionScope.read(context).api.get('/orders/${widget.orderId}'));
                  },
                  icon: const Icon(Icons.report_problem_outlined),
                  label: const Text('Report a problem'),
                ),
            ]),
    );
  }

  Future<void> _payPart(MilestoneInfo milestone) async {
    final payer = await askPayer(context, milestone.label, milestone.amount);
    if (payer == null || !mounted) return;
    try {
      final json = await SessionScope.read(context).api.post('/orders/${widget.orderId}/milestones/${milestone.id}/pay',
          {'operator': payer.operator, 'payer_phone': payer.phone}) as Map<String, dynamic>;
      if (mounted) await waitForCharge(context, (json['id'] as num).toInt());
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
    if (mounted) await _run(() => SessionScope.read(context).api.get('/orders/${widget.orderId}'));
  }

  Future<void> _requestInspection() async {
    final payer = await askPayer(context, 'Inspection fee', '50.00');
    if (payer == null || !mounted) return;
    try {
      final json = await SessionScope.read(context).api.post('/orders/${widget.orderId}/inspection',
          {'operator': payer.operator, 'payer_phone': payer.phone}) as Map<String, dynamic>;
      final payment = json['payment'] as Map<String, dynamic>?;
      if (payment != null && mounted) await waitForCharge(context, (payment['id'] as num).toInt());
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
    if (mounted) await _run(() => SessionScope.read(context).api.get('/orders/${widget.orderId}'));
  }

  Widget _inspectionCard(InspectionInfo inspection) => Card(
        color: inspection.status == 'failed' ? const Color(0xFFFEE2E2) : WiBuyColors.greenLight,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text('Inspection: ${switch (inspection.status) {
              'pending_payment' => 'waiting for the fee',
              'waiting' || 'assigned' => 'an agent will visit the seller',
              'passed' => 'PASSED ✓',
              _ => 'FAILED: do not pay; report a problem',
            }}', style: const TextStyle(fontWeight: FontWeight.w800)),
            if (inspection.report != null) Text(inspection.report!),
            if (inspection.photos.isNotEmpty)
              Wrap(spacing: 6, children: [for (final url in inspection.photos) Photo(url, size: 64)]),
          ]),
        ),
      );

  static String _date(DateTime d) => '${d.day}/${d.month} ${d.hour.toString().padLeft(2, '0')}:${d.minute.toString().padLeft(2, '0')}';

  Widget _row(String label, String amount, {bool bold = false}) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 2),
        child: Row(children: [
          Text(label, style: TextStyle(fontWeight: bold ? FontWeight.w800 : FontWeight.w400)),
          const Spacer(),
          Text(formatLe(amount), style: TextStyle(fontWeight: bold ? FontWeight.w900 : FontWeight.w600)),
        ]),
      );
}

/// The WiBuy rider's steps, from the rider's own taps (no location tracking).
Widget _riderCard(Map<String, dynamic> rider) {
  final steps = (rider['steps'] as List).whereType<Map<String, dynamic>>();
  final tracking = rider['tracking'] as Map<String, dynamic>?;
  final phone = rider['rider_phone'] as String?;

  return Card(
    child: Padding(
      padding: const EdgeInsets.all(14),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Text('🛵 ${rider['label']}', style: const TextStyle(fontWeight: FontWeight.w800)),
        if (tracking != null) ...[
          const SizedBox(height: 6),
          Text(
            tracking['km'] != null
                ? 'About ${tracking['km']} km away · about ${tracking['minutes']} min${tracking['to_station'] != null ? ' to ${tracking['to_station']}' : ''}'
                : 'The rider is on the way.',
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: WiBuyColors.green),
          ),
          const SizedBox(height: 6),
          TrackingMap(
            rider: LatLng((tracking['rider']['lat'] as num).toDouble(), (tracking['rider']['lng'] as num).toDouble()),
            destination: tracking['destination'] == null
                ? null
                : LatLng((tracking['destination']['lat'] as num).toDouble(), (tracking['destination']['lng'] as num).toDouble()),
          ),
          Text('${tracking['live'] == true ? 'Live' : 'Last seen'} · distance and time are estimates', style: const TextStyle(color: WiBuyColors.muted, fontSize: 12)),
        ],
        if (rider['rider_name'] != null) Text('Rider: ${rider['rider_name']}${phone != null ? ' · ${displayPhone(phone)}' : ''}'),
        const SizedBox(height: 8),
        for (final step in steps)
          Row(children: [
            Icon(step['at'] != null ? Icons.check_circle : Icons.radio_button_unchecked, size: 18, color: step['at'] != null ? WiBuyColors.green : WiBuyColors.muted),
            const SizedBox(width: 6),
            Text(step['label'] as String, style: TextStyle(color: step['at'] != null ? null : WiBuyColors.muted)),
          ]),
        if (rider['stage'] == 'picked_up')
          const Padding(
            padding: EdgeInsets.only(top: 8),
            child: Text('Give the rider the 4-digit code from our SMS only when you have your goods.', style: TextStyle(color: WiBuyColors.muted, fontSize: 12)),
          ),
      ]),
    ),
  );
}

class _ReviewSheet extends StatefulWidget {
  const _ReviewSheet();

  @override
  State<_ReviewSheet> createState() => _ReviewSheetState();
}

class _ReviewSheetState extends State<_ReviewSheet> {
  int _rating = 5;
  final _comment = TextEditingController();
  List<PickedPhoto> _photos = [];

  @override
  void dispose() {
    _comment.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.fromLTRB(20, 20, 20, 20 + MediaQuery.of(context).viewInsets.bottom),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          const Text('How was it?', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
          const SizedBox(height: 8),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (var i = 1; i <= 5; i++)
              IconButton(
                iconSize: 40,
                onPressed: () => setState(() => _rating = i),
                icon: Icon(i <= _rating ? Icons.star : Icons.star_border, color: Colors.amber),
              ),
          ]),
          TextField(controller: _comment, maxLines: 3, decoration: const InputDecoration(hintText: 'Tell others about the seller (optional)')),
          const SizedBox(height: 10),
          PhotoPicker(onChanged: (p) => _photos = p, label: 'Photos of what you got (optional)'),
          const SizedBox(height: 14),
          FilledButton(onPressed: () => Navigator.pop(context, (_rating, _comment.text.trim(), _photos)), child: const Text('Send review')),
        ]),
      );
}
