import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'business_screen.dart';

/// The AI shopping assistant: "I need 50 bags of cement delivered to Bo next week under Le 30,000".
/// The app only talks to WiBuy; WiBuy's server calls the AI.
class AssistantScreen extends StatefulWidget {
  const AssistantScreen({super.key});

  @override
  State<AssistantScreen> createState() => _AssistantScreenState();
}

class _AssistantScreenState extends State<AssistantScreen> {
  final _message = TextEditingController();
  Map<String, dynamic>? _answer;
  bool _busy = false;
  String? _off;

  @override
  void dispose() {
    _message.dispose();
    super.dispose();
  }

  Future<void> _ask() async {
    if (_message.text.trim().length < 3) return;
    setState(() => _busy = true);
    try {
      final json = await SessionScope.read(context).api.post('/assistant', {'message': _message.text.trim()}) as Map<String, dynamic>;
      setState(() => _answer = json);
    } on ApiException catch (e) {
      if (e.status == 404) {
        setState(() => _off = 'The shopping assistant is not switched on yet.');
      } else if (mounted) {
        showMessage(context, e);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final answer = _answer;
    final shortlist = answer == null
        ? const <Product>[]
        : (answer['shortlist'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('🤖 Shopping assistant')),
      body: _off != null
          ? EmptyState(icon: Icons.smart_toy_outlined, title: _off!)
          : ListView(padding: const EdgeInsets.all(16), children: [
              const Text('Say what you need, the quantity, where and your budget.', style: TextStyle(color: WiBuyColors.muted)),
              const SizedBox(height: 8),
              TextField(
                controller: _message,
                maxLines: 3,
                maxLength: 500,
                decoration: const InputDecoration(hintText: 'I need 50 bags of cement delivered to Bo next week under Le 30,000'),
              ),
              FilledButton(onPressed: _busy ? null : _ask, child: Text(_busy ? 'Thinking…' : 'Ask')),
              if (answer != null) ...[
                const SizedBox(height: 16),
                Card(
                  color: WiBuyColors.greenLight,
                  child: Padding(padding: const EdgeInsets.all(12), child: Text(answer['reply'] as String, style: const TextStyle(fontSize: 16))),
                ),
                const SizedBox(height: 10),
                Text(shortlist.isEmpty ? 'No matching products yet.' : 'Shortlist', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                if (shortlist.isNotEmpty) ProductGrid(shortlist),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => RfqCreateScreen(draft: answer['rfq_draft'] as Map<String, dynamic>),
                  )),
                  icon: const Icon(Icons.request_quote_outlined),
                  label: const Text('Ask sellers for quotes'),
                ),
                Text('${answer['left_today']} questions left today', textAlign: TextAlign.center, style: const TextStyle(color: WiBuyColors.muted, fontSize: 12)),
              ],
            ]),
    );
  }
}
