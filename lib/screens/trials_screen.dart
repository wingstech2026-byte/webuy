import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'checkout_screen.dart';
import 'photo_forms.dart';

/// Free trials: apply; if picked, get the product free (or almost) and post an honest review with photos.
class TrialsScreen extends StatefulWidget {
  const TrialsScreen({super.key});

  @override
  State<TrialsScreen> createState() => _TrialsScreenState();
}

class _TrialsScreenState extends State<TrialsScreen> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('🧪 Free trials')),
      body: Loader<List<TrialInfo>>(
        key: ValueKey(_reload),
        load: () async {
          final json = await api.get('/trials') as Map<String, dynamic>;
          return (json['data'] as List).whereType<Map<String, dynamic>>().map(TrialInfo.fromJson).toList();
        },
        builder: (context, trials, reload) => trials.isEmpty
            ? const EmptyState(icon: Icons.science_outlined, title: 'No free trials right now.')
            : RefreshIndicator(
                onRefresh: reload,
                child: ListView(padding: const EdgeInsets.all(12), children: [
                  const Text('Testers must post an honest review with photos within a few days of receiving it. Reviews show a "Free trial" label.',
                      style: TextStyle(color: WiBuyColors.muted)),
                  const SizedBox(height: 10),
                  for (final trial in trials) _TrialCard(trial: trial, onChanged: () => setState(() => _reload++)),
                ]),
              ),
      ),
    );
  }
}

class _TrialCard extends StatelessWidget {
  const _TrialCard({required this.trial, required this.onChanged});

  final TrialInfo trial;
  final VoidCallback onChanged;

  @override
  Widget build(BuildContext context) {
    final price = trial.trialPrice == '0.00' ? 'Free' : formatLe(trial.trialPrice);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
          Row(children: [
            Photo(trial.product.thumbUrl, size: 72),
            const SizedBox(width: 10),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text(trial.product.title, style: const TextStyle(fontWeight: FontWeight.w700)),
                Text('$price (normally ${formatLe(trial.product.price)})', style: const TextStyle(color: WiBuyColors.green, fontWeight: FontWeight.w800)),
                Text('${trial.slots} places · ${trial.appliedCount} applied', style: const TextStyle(color: WiBuyColors.muted, fontSize: 13)),
              ]),
            ),
          ]),
          const SizedBox(height: 8),
          switch (trial.myStatus) {
            null => FilledButton(onPressed: trial.isOpen ? () => _apply(context) : null, child: const Text('Apply')),
            'applied' => const Text('You applied. The seller picks testers soon.', textAlign: TextAlign.center),
            'rejected' => const Text('Not picked this time.', textAlign: TextAlign.center),
            'reviewed' => const Text('Thank you for your review!', textAlign: TextAlign.center),
            _ => Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                Text(trial.myStatus == 'overdue' ? 'Your review is late. Please write it now.' : 'You were picked! 🎉', textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(height: 6),
                FilledButton(
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => CheckoutScreen(productSlug: trial.product.slug, quantity: 1))),
                  child: Text('Order it for $price'),
                ),
                OutlinedButton(
                  onPressed: () async {
                    final sent = await Navigator.of(context).push<bool>(MaterialPageRoute(
                      builder: (_) => TrialReviewScreen(campaignId: trial.id, productTitle: trial.product.title),
                    ));
                    if (sent == true) onChanged();
                  },
                  child: const Text('Write the review (with photos)'),
                ),
              ]),
          },
        ]),
      ),
    );
  }

  Future<void> _apply(BuildContext context) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Why do you want to test it?'),
        content: TextField(controller: reason, maxLength: 300, maxLines: 3, decoration: const InputDecoration(hintText: 'Optional')),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Apply')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      final json = await SessionScope.read(context).api.post('/trials/${trial.id}/apply', {'reason': reason.text.trim()}) as Map<String, dynamic>;
      if (context.mounted) showMessage(context, json['message'] as String);
    } on Object catch (e) {
      if (context.mounted) showMessage(context, e);
    }
    onChanged();
  }
}
