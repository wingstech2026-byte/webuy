import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import '../widgets/photo_picker.dart';

/// Shared layout for forms that send photos: a scrollable form and one big send button.
class _PhotoForm extends StatefulWidget {
  const _PhotoForm({required this.title, required this.children, required this.onSend, required this.sendLabel});

  final String title;
  final List<Widget> children;
  final Future<String?> Function() onSend;
  final String sendLabel;

  @override
  State<_PhotoForm> createState() => _PhotoFormState();
}

class _PhotoFormState extends State<_PhotoForm> {
  bool _busy = false;

  Future<void> _send() async {
    setState(() => _busy = true);
    try {
      final message = await widget.onSend();
      if (!mounted) return;
      if (message != null) showMessage(context, message);
      Navigator.pop(context, true);
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: ListView(padding: const EdgeInsets.all(16), children: [
          ...widget.children,
          const SizedBox(height: 18),
          FilledButton(onPressed: _busy ? null : _send, child: Text(_busy ? 'Sending…' : widget.sendLabel)),
        ]),
      );
}

Map<String, List<(String, List<int>)>> _files(String field, List<PickedPhoto> photos) => {field: [for (final p in photos) (p.$1, p.$2)]};

/// Report a problem with an order, with photos. The money stays on hold until WiBuy decides.
class ProblemReportScreen extends StatefulWidget {
  const ProblemReportScreen({super.key, required this.orderId});

  final int orderId;

  @override
  State<ProblemReportScreen> createState() => _ProblemReportScreenState();
}

class _ProblemReportScreenState extends State<ProblemReportScreen> {
  final _description = TextEditingController();
  List<PickedPhoto> _photos = [];
  String? _reason;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Loader<List<Map<String, dynamic>>>(
      load: () async => ((await api.get('/problem-reasons') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().toList(),
      builder: (context, reasons, _) => _PhotoForm(
        title: 'Report a problem',
        sendLabel: 'Send report',
        onSend: () async {
          final json = await api.postMultipart('/orders/${widget.orderId}/problem', {
            'reason': _reason ?? '',
            'description': _description.text.trim(),
          }, _files('photos[]', _photos)) as Map<String, dynamic>;
          return json['message'] as String;
        },
        children: [
          const Text('Your money stays on hold until the WiBuy team decides.', style: TextStyle(color: WiBuyColors.muted)),
          const SizedBox(height: 10),
          RadioGroup<String>(
            groupValue: _reason,
            onChanged: (v) => setState(() => _reason = v),
            child: Column(children: [
              for (final r in reasons)
                RadioListTile<String>(contentPadding: EdgeInsets.zero, value: r['value'] as String, title: Text(r['label'] as String)),
            ]),
          ),
          TextField(controller: _description, maxLines: 4, maxLength: 2000, decoration: const InputDecoration(labelText: 'Explain what went wrong')),
          PhotoPicker(onChanged: (p) => _photos = p, label: 'Photos (optional)'),
        ],
      ),
    );
  }
}

/// Send items back: how many of each, why, and photos. Refunded when the seller has them back.
class ReturnRequestScreen extends StatefulWidget {
  const ReturnRequestScreen({super.key, required this.order});

  final Order order;

  @override
  State<ReturnRequestScreen> createState() => _ReturnRequestScreenState();
}

class _ReturnRequestScreenState extends State<ReturnRequestScreen> {
  final _description = TextEditingController();
  late final Map<int, int> _quantities = {for (final i in widget.order.items) i.id: widget.order.items.length == 1 ? i.quantity : 0};
  List<PickedPhoto> _photos = [];
  String? _reason;

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Loader<List<Map<String, dynamic>>>(
      load: () async => ((await api.get('/return-reasons') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().toList(),
      builder: (context, reasons, _) => _PhotoForm(
        title: 'Return items',
        sendLabel: 'Ask for a return',
        onSend: () async {
          final json = await api.postMultipart('/orders/${widget.order.id}/return', {
            for (final e in _quantities.entries) 'items[${e.key}]': '${e.value}',
            'reason': _reason ?? '',
            'description': _description.text.trim(),
          }, _files('photos[]', _photos)) as Map<String, dynamic>;
          return json['message'] as String;
        },
        children: [
          const Text('Your money stays with WiBuy. You are refunded when the seller has the items back. If the seller refuses, the WiBuy team decides.',
              style: TextStyle(color: WiBuyColors.muted)),
          const SizedBox(height: 10),
          for (final item in widget.order.items)
            Row(children: [
              Expanded(child: Text('${item.title}\n${formatLe(item.unitPrice)} each · you got ${item.quantity}')),
              IconButton(
                tooltip: 'Fewer',
                onPressed: (_quantities[item.id] ?? 0) > 0 ? () => setState(() => _quantities[item.id] = _quantities[item.id]! - 1) : null,
                icon: const Icon(Icons.remove_circle_outline),
              ),
              Text('${_quantities[item.id]}', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              IconButton(
                tooltip: 'More',
                onPressed: (_quantities[item.id] ?? 0) < item.quantity ? () => setState(() => _quantities[item.id] = _quantities[item.id]! + 1) : null,
                icon: const Icon(Icons.add_circle_outline),
              ),
            ]),
          const SizedBox(height: 8),
          RadioGroup<String>(
            groupValue: _reason,
            onChanged: (v) => setState(() => _reason = v),
            child: Column(children: [
              for (final r in reasons)
                RadioListTile<String>(contentPadding: EdgeInsets.zero, value: r['value'] as String, title: Text(r['label'] as String)),
            ]),
          ),
          TextField(controller: _description, maxLines: 4, maxLength: 1000, decoration: const InputDecoration(labelText: 'Tell the seller what is wrong')),
          PhotoPicker(onChanged: (p) => _photos = p, label: 'Photos (up to 4, helps a lot)'),
        ],
      ),
    );
  }
}

/// The free trial tester's honest review, with photos.
class TrialReviewScreen extends StatefulWidget {
  const TrialReviewScreen({super.key, required this.campaignId, required this.productTitle});

  final int campaignId;
  final String productTitle;

  @override
  State<TrialReviewScreen> createState() => _TrialReviewScreenState();
}

class _TrialReviewScreenState extends State<TrialReviewScreen> {
  final _comment = TextEditingController();
  List<PickedPhoto> _photos = [];
  int _rating = 5;

  @override
  Widget build(BuildContext context) => _PhotoForm(
        title: 'Free trial review',
        sendLabel: 'Post my review',
        onSend: () async {
          final json = await SessionScope.read(context).api.postMultipart('/trials/${widget.campaignId}/review', {
            'rating': '$_rating',
            'comment': _comment.text.trim(),
          }, _files('photos[]', _photos)) as Map<String, dynamic>;
          return json['message'] as String;
        },
        children: [
          Text(widget.productTitle, style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
          const Text('Be honest: say what is good and what is not. Your review shows a "Free trial" label.', style: TextStyle(color: WiBuyColors.muted)),
          Row(mainAxisAlignment: MainAxisAlignment.center, children: [
            for (var i = 1; i <= 5; i++)
              IconButton(iconSize: 38, onPressed: () => setState(() => _rating = i), icon: Icon(i <= _rating ? Icons.star : Icons.star_border, color: Colors.amber)),
          ]),
          TextField(controller: _comment, maxLines: 5, maxLength: 1000, decoration: const InputDecoration(labelText: 'Your review (at least 20 letters)')),
          PhotoPicker(onChanged: (p) => _photos = p, label: 'Photos (at least 1)'),
        ],
      );
}

/// Register a business buyer account with photos of the papers.
class BusinessRegisterScreen extends StatefulWidget {
  const BusinessRegisterScreen({super.key});

  @override
  State<BusinessRegisterScreen> createState() => _BusinessRegisterScreenState();
}

class _BusinessRegisterScreenState extends State<BusinessRegisterScreen> {
  final _name = TextEditingController();
  final _registration = TextEditingController();
  final _address = TextEditingController();
  List<PickedPhoto> _documents = [];

  @override
  Widget build(BuildContext context) => _PhotoForm(
        title: 'Register my business',
        sendLabel: 'Send for checking',
        onSend: () async {
          final json = await SessionScope.read(context).api.postMultipart('/business', {
            'business_name': _name.text.trim(),
            'registration_no': _registration.text.trim(),
            'address': _address.text.trim(),
          }, _files('documents[]', _documents)) as Map<String, dynamic>;
          return json['message'] as String;
        },
        children: [
          TextField(controller: _name, decoration: const InputDecoration(labelText: 'Business name')),
          const SizedBox(height: 10),
          TextField(controller: _registration, decoration: const InputDecoration(labelText: 'Registration number')),
          const SizedBox(height: 10),
          TextField(controller: _address, decoration: const InputDecoration(labelText: 'Business address')),
          const SizedBox(height: 10),
          PhotoPicker(onChanged: (p) => _documents = p, label: 'Photos of your registration papers'),
        ],
      );
}

/// WiBuy agents: inspection jobs and the pass/fail report with photos.
class AgentJobsScreen extends StatelessWidget {
  const AgentJobsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('My inspections')),
      body: Loader<List<Map<String, dynamic>>>(
        load: () async => ((await api.get('/agent/inspections') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().toList(),
        builder: (context, jobs, reload) => jobs.isEmpty
            ? const EmptyState(icon: Icons.fact_check_outlined, title: 'No inspections assigned to you yet.')
            : ListView(padding: const EdgeInsets.all(12), children: [
                for (final job in jobs)
                  Card(
                    child: ListTile(
                      title: Text('Order ${job['order_number']} · ${job['shop']}', style: const TextStyle(fontWeight: FontWeight.w700)),
                      subtitle: Text('${job['pickup_address']}\n${(job['items'] as List).join(', ')}'),
                      isThreeLine: true,
                      trailing: StatusChip(job['status'] as String),
                      onTap: job['status'] == 'assigned'
                          ? () async {
                              await Navigator.of(context).push(MaterialPageRoute(builder: (_) => _AgentReportScreen(id: job['id'] as int)));
                              reload();
                            }
                          : null,
                    ),
                  ),
              ]),
      ),
    );
  }
}

class _AgentReportScreen extends StatefulWidget {
  const _AgentReportScreen({required this.id});

  final int id;

  @override
  State<_AgentReportScreen> createState() => _AgentReportScreenState();
}

class _AgentReportScreenState extends State<_AgentReportScreen> {
  final _report = TextEditingController();
  List<PickedPhoto> _photos = [];
  bool? _passed;

  @override
  Widget build(BuildContext context) => _PhotoForm(
        title: 'Inspection report',
        sendLabel: 'Send report',
        onSend: () async {
          await SessionScope.read(context).api.postMultipart('/agent/inspections/${widget.id}/report', {
            'passed': _passed == null ? '' : (_passed! ? '1' : '0'),
            'report': _report.text.trim(),
          }, _files('photos[]', _photos));
          return 'Report sent. The buyer got an SMS.';
        },
        children: [
          SegmentedButton<bool>(
            emptySelectionAllowed: true,
            segments: const [ButtonSegment(value: true, label: Text('✅ Pass')), ButtonSegment(value: false, label: Text('❌ Fail'))],
            selected: {if (_passed != null) _passed!},
            onSelectionChanged: (s) => setState(() => _passed = s.isEmpty ? null : s.first),
          ),
          const SizedBox(height: 10),
          TextField(controller: _report, maxLines: 5, decoration: const InputDecoration(labelText: 'What you checked and found')),
          const SizedBox(height: 10),
          PhotoPicker(onChanged: (p) => _photos = p, max: 8, label: 'Photos of the goods'),
        ],
      );
}
