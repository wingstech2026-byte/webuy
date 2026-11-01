import 'package:flutter/material.dart';

import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/photo_picker.dart';

/// A delivery job as the rider may see it: areas and fee first, the buyer's details once it is theirs.
class RiderJob {
  RiderJob(this.json);

  final Map<String, dynamic> json;

  int get id => (json['id'] as num).toInt();
  String get status => json['status'] as String;
  String get statusLabel => json['status_label'] as String;
  String get fee => json['fee'] as String;
  bool get isMine => json['is_mine'] == true;
  String? get(String key) => json[key]?.toString();
}

/// "Rider jobs": my deliveries, then open jobs to take.
class RiderJobsScreen extends StatefulWidget {
  const RiderJobsScreen({super.key});

  @override
  State<RiderJobsScreen> createState() => _RiderJobsScreenState();
}

class _RiderJobsScreenState extends State<RiderJobsScreen> {
  int _version = 0;

  Future<void> _open(RiderJob job) async {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => RiderJobScreen(job: job)));
    if (mounted) setState(() => _version++);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Rider jobs'), actions: [
          TextButton.icon(
            onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const RiderEarningsScreen())),
            icon: const Icon(Icons.account_balance_wallet_outlined),
            label: const Text('Earnings'),
            style: TextButton.styleFrom(foregroundColor: Colors.white),
          ),
        ]),
        body: Loader<Map<String, dynamic>>(
          key: ValueKey(_version),
          load: () async => await SessionScope.read(context).api.get('/rider/jobs') as Map<String, dynamic>,
          builder: (context, json, reload) {
            final mine = (json['mine'] as List).whereType<Map<String, dynamic>>().map(RiderJob.new).toList();
            final open = (json['open'] as List).whereType<Map<String, dynamic>>().map(RiderJob.new).toList();
            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(12), children: [
                Text('${json['delivered_count']} delivered · ${formatLe(json['delivered_fees'] as String?)} in delivery fees',
                    style: const TextStyle(color: WiBuyColors.muted)),
                if (mine.isNotEmpty) ...[
                  const Padding(padding: EdgeInsets.fromLTRB(2, 14, 2, 6), child: Text('My deliveries', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
                  for (final job in mine)
                    Card(
                      child: ListTile(
                        title: Text('${job.get('order_number')} · ${job.get('pickup_name')} → ${job.get('dropoff_district') ?? ''}'),
                        subtitle: Text(job.statusLabel),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => _open(job),
                      ),
                    ),
                ],
                const Padding(padding: EdgeInsets.fromLTRB(2, 14, 2, 6), child: Text('Open jobs', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
                if (open.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(14), child: Text('No open jobs right now. Pull down to refresh.'))),
                for (final job in open)
                  Card(
                    child: ListTile(
                      title: Text(formatLe(job.fee), style: const TextStyle(fontWeight: FontWeight.w800, color: WiBuyColors.green)),
                      subtitle: Text('From ${job.get('pickup_name')} (${job.get('pickup_address') ?? ''}) → ${job.get('dropoff_district') ?? '?'}'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => _open(job),
                    ),
                  ),
              ]),
            );
          },
        ),
      );
}

/// One delivery, step by step: take it, collected, delivered (code, or photo when there is no code).
class RiderJobScreen extends StatefulWidget {
  const RiderJobScreen({super.key, required this.job});

  final RiderJob job;

  @override
  State<RiderJobScreen> createState() => _RiderJobScreenState();
}

class _RiderJobScreenState extends State<RiderJobScreen> {
  late RiderJob _job = widget.job;
  final _code = TextEditingController();
  final _note = TextEditingController();
  List<PickedPhoto> _photos = [];
  bool _busy = false;

  @override
  void dispose() {
    _code.dispose();
    _note.dispose();
    super.dispose();
  }

  Future<void> _act(String action, {Map<String, dynamic>? body}) async {
    setState(() => _busy = true);
    try {
      final api = SessionScope.read(context).api;
      final json = (action == 'deliver' && _photos.isNotEmpty
          ? await api.postMultipart('/rider/jobs/${_job.id}/deliver', {'note': _note.text.trim()}, {'photo': [for (final p in _photos.take(1)) (p.$1, p.$2)]})
          : await api.post('/rider/jobs/${_job.id}/$action', body)) as Map<String, dynamic>;
      if (!mounted) return;
      if (action == 'release') {
        Navigator.of(context).pop();
        return;
      }
      setState(() => _job = RiderJob(json['data'] as Map<String, dynamic>));
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final job = _job;

    return Scaffold(
      appBar: AppBar(title: Text(job.get('order_number') ?? 'Delivery job')),
      body: ListView(padding: const EdgeInsets.all(14), children: [
        Text(job.statusLabel, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
        const SizedBox(height: 8),
        Text('Pay: ${formatLe(job.fee)}', style: const TextStyle(fontWeight: FontWeight.w700, color: WiBuyColors.green)),
        const SizedBox(height: 6),
        Text('Collect at: ${job.get('pickup_name')}, ${job.get('pickup_address') ?? ''}'),
        const SizedBox(height: 6),
        if (job.isMine) ...[
          Text('Deliver to: ${job.get('buyer_name')}, ${displayPhone(job.get('buyer_phone'))}'),
          Text('${job.get('dropoff_address') ?? ''} (${job.get('dropoff_district') ?? ''})'),
          if (job.get('items') != null) Text(job.get('items')!, style: const TextStyle(color: WiBuyColors.muted)),
        ] else
          Text('Deliver to: ${job.get('dropoff_district') ?? '?'} (the address shows after you take the job)'),
        const SizedBox(height: 18),
        if (job.status == 'open') FilledButton(onPressed: _busy ? null : () => _act('accept'), child: const Text('Take this job')),
        if (job.status == 'assigned') ...[
          FilledButton(onPressed: _busy ? null : () => _act('picked-up'), child: const Text('I collected it at the shop')),
          const SizedBox(height: 8),
          OutlinedButton(onPressed: _busy ? null : () => _act('release'), child: const Text('Give the job back')),
        ],
        if (job.status == 'picked_up') ...[
          TextField(
            controller: _code,
            keyboardType: TextInputType.number,
            maxLength: 4,
            textAlign: TextAlign.center,
            style: const TextStyle(fontSize: 26, letterSpacing: 8),
            decoration: InputDecoration(labelText: "The buyer's 4-digit code", helperText: '${job.get('code_attempts_left')} tries left'),
          ),
          FilledButton(onPressed: _busy ? null : () => _act('deliver', body: {'code': _code.text.trim()}), child: const Text('Delivered')),
          const Divider(height: 32),
          const Text('The buyer cannot give the code? Take a photo of the handover instead.', style: TextStyle(fontWeight: FontWeight.w600)),
          PhotoPicker(onChanged: (p) => setState(() => _photos = p), label: 'Handover photo'),
          TextField(controller: _note, maxLength: 300, decoration: const InputDecoration(labelText: 'Who received it?')),
          OutlinedButton(onPressed: _busy || _photos.isEmpty ? null : () => _act('deliver'), child: const Text('Delivered (photo)')),
        ],
        if (job.status == 'delivered') const Text('Delivered ✓ Thank you.', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700, color: WiBuyColors.green)),
      ]),
    );
  }
}

/// "My earnings": delivery fees (pending until the buyer confirms), payouts, and withdrawing to mobile money.
class RiderEarningsScreen extends StatefulWidget {
  const RiderEarningsScreen({super.key});

  @override
  State<RiderEarningsScreen> createState() => _RiderEarningsScreenState();
}

class _RiderEarningsScreenState extends State<RiderEarningsScreen> {
  int _version = 0;
  final _amount = TextEditingController();
  final _phone = TextEditingController();
  String? _operator;
  bool _busy = false;

  @override
  void dispose() {
    _amount.dispose();
    _phone.dispose();
    super.dispose();
  }

  Future<void> _withdraw() async {
    setState(() => _busy = true);
    try {
      await SessionScope.read(context).api.post('/rider/payouts', {'amount': _amount.text.trim(), 'operator': _operator, 'phone': _phone.text.trim()});
      if (!mounted) return;
      showMessage(context, 'Payout requested. We will check it and send it to your mobile money.');
      setState(() {
        _amount.clear();
        _version++;
      });
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Widget _tile(String label, String? amount, {bool strong = false}) => Expanded(
        child: Card(
          child: Padding(
            padding: const EdgeInsets.all(10),
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(label, style: const TextStyle(color: WiBuyColors.muted, fontSize: 12)),
              Text(formatLe(amount), style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16, color: strong ? WiBuyColors.green : null)),
            ]),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('My earnings')),
        body: Loader<Map<String, dynamic>>(
          key: ValueKey(_version),
          load: () async => await SessionScope.read(context).api.get('/rider/earnings') as Map<String, dynamic>,
          builder: (context, json, reload) {
            final money = json['money'] as Map<String, dynamic>;
            final payTo = json['pay_to'] as Map<String, dynamic>;
            _operator ??= payTo['operator'] as String? ?? 'ORANGE';
            if (_phone.text.isEmpty) _phone.text = displayPhone(payTo['phone'] as String?);
            final payouts = (json['payouts'] as List).whereType<Map<String, dynamic>>();
            final entries = (json['entries'] as List).whereType<Map<String, dynamic>>();

            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(12), children: [
                const Text('You earn the delivery fee once the buyer confirms the order.', style: TextStyle(color: WiBuyColors.muted)),
                const SizedBox(height: 8),
                Row(children: [_tile('Available', money['available'] as String?, strong: true), _tile('Pending', money['pending'] as String?)]),
                Row(children: [_tile('On the way', money['in_transit'] as String?), _tile('Paid out', money['paid_out'] as String?)]),
                const Padding(padding: EdgeInsets.fromLTRB(2, 14, 2, 6), child: Text('Withdraw to mobile money', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
                SegmentedButton<String>(
                  segments: const [ButtonSegment(value: 'ORANGE', label: Text('Orange Money')), ButtonSegment(value: 'AFRIMONEY', label: Text('Afrimoney'))],
                  selected: {_operator!},
                  onSelectionChanged: (s) => setState(() => _operator = s.first),
                ),
                const SizedBox(height: 8),
                TextField(controller: _phone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Mobile money number')),
                const SizedBox(height: 8),
                TextField(
                  controller: _amount,
                  keyboardType: const TextInputType.numberWithOptions(decimal: true),
                  decoration: InputDecoration(labelText: 'Amount', helperText: 'Minimum ${formatLe(json['min_payout'] as String?)}'),
                ),
                const SizedBox(height: 8),
                FilledButton(onPressed: _busy ? null : _withdraw, child: const Text('Withdraw')),
                if (payouts.isNotEmpty) ...[
                  const Padding(padding: EdgeInsets.fromLTRB(2, 18, 2, 6), child: Text('Payouts', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
                  for (final p in payouts)
                    Card(
                      child: ListTile(
                        title: Text(formatLe(p['amount'] as String?), style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text('${p['operator']} ${displayPhone(p['phone'] as String?)}${p['rejection_reason'] != null ? '\n${p['rejection_reason']}' : ''}'),
                        trailing: Text(p['status_label'] as String),
                      ),
                    ),
                ],
                const Padding(padding: EdgeInsets.fromLTRB(2, 18, 2, 6), child: Text('Credits and debits', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
                if (entries.isEmpty) const Card(child: Padding(padding: EdgeInsets.all(14), child: Text('Nothing yet. Delivery fees are added here when an order completes.'))),
                for (final e in entries)
                  ListTile(
                    dense: true,
                    title: Text(e['type'] as String),
                    subtitle: Text(e['description'] as String),
                    trailing: Text(formatLe(e['amount'] as String?), style: const TextStyle(fontWeight: FontWeight.w700)),
                  ),
              ]),
            );
          },
        ),
      );
}
