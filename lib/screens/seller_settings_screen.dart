import 'package:flutter/material.dart';

import '../session.dart';
import '../widgets/common.dart';

Future<String?> _editText(
  BuildContext context, {
  required String title,
  required String label,
  String initial = '',
  int maxLength = 200,
  TextInputType? keyboardType,
}) {
  var value = initial;
  return showDialog<String>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: TextFormField(
        initialValue: initial,
        onChanged: (text) => value = text,
        decoration: InputDecoration(labelText: label),
        maxLength: maxLength,
        maxLines: keyboardType == null ? 3 : 1,
        keyboardType: keyboardType,
      ),
      actions: [
        TextButton(
            onPressed: () => Navigator.pop(context),
            child: const Text('Cancel')),
        FilledButton(
            onPressed: () => Navigator.pop(context, value.trim()),
            child: const Text('Save')),
      ],
    ),
  );
}

class SellerSettingsScreen extends StatelessWidget {
  const SellerSettingsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Shop settings')),
      body: Loader<Map<String, dynamic>>(
        load: () async =>
            await api.get('/seller/settings') as Map<String, dynamic>,
        builder: (context, data, reload) {
          final holiday = data['holiday_until'] as String?;
          final staff =
              (data['staff'] as List).whereType<Map<String, dynamic>>();

          Future<void> save(Future<dynamic> Function() action) async {
            try {
              await action();
              if (context.mounted) await reload();
            } on Object catch (error) {
              if (context.mounted) showMessage(context, error);
            }
          }

          return ListView(padding: const EdgeInsets.all(16), children: [
            ListTile(
              title: const Text('Holiday mode'),
              subtitle: Text(
                  holiday == null ? 'Shop is open' : 'Closed through $holiday'),
              trailing: const Icon(Icons.calendar_month),
              onTap: () async {
                final now = DateTime.now().toUtc();
                final today = DateTime(now.year, now.month, now.day);
                final selectedDate =
                    holiday == null ? today : DateTime.parse(holiday);
                final lastDate = today.add(const Duration(days: 89));
                final initialDate = selectedDate.isBefore(today) ||
                        selectedDate.isAfter(lastDate)
                    ? today
                    : selectedDate;
                final until = await showDatePicker(
                  context: context,
                  initialDate: initialDate,
                  firstDate: today,
                  lastDate: lastDate,
                );
                if (until == null || !context.mounted) return;
                final message = await _editText(
                  context,
                  title: 'Holiday mode',
                  label: 'Message for buyers (optional)',
                  initial: data['holiday_message'] as String? ?? '',
                );
                if (message == null || !context.mounted) return;
                await save(() => api.put('/seller/settings/holiday', {
                      'holiday_until': until.toIso8601String().split('T').first,
                      'holiday_message': message,
                    }));
              },
            ),
            if (holiday != null)
              TextButton(
                onPressed: () => save(() => api
                    .put('/seller/settings/holiday', {'holiday_until': null})),
                child: const Text('Reopen shop'),
              ),
            const Divider(),
            const ListTile(title: Text('Shop helpers')),
            for (final helper in staff)
              ListTile(
                title: Text(helper['name'].toString()),
                subtitle: Text(helper['phone'].toString()),
                trailing: IconButton(
                  tooltip: 'Remove helper',
                  icon: const Icon(Icons.person_remove_outlined),
                  onPressed: () => save(() =>
                      api.delete('/seller/settings/staff/${helper['id']}')),
                ),
              ),
            TextButton.icon(
              onPressed: () async {
                final phone = await _editText(
                  context,
                  title: 'Add shop helper',
                  label: 'WiBuy phone number',
                  maxLength: 20,
                  keyboardType: TextInputType.phone,
                );
                if (phone == null || phone.isEmpty || !context.mounted) return;
                await save(
                    () => api.post('/seller/settings/staff', {'phone': phone}));
              },
              icon: const Icon(Icons.person_add_outlined),
              label: const Text('Add helper'),
            ),
          ]);
        },
      ),
    );
  }
}

class SellerQuestionsScreen extends StatelessWidget {
  const SellerQuestionsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Product questions')),
      body: Loader<List<Map<String, dynamic>>>(
        load: () async {
          final response =
              await api.get('/seller/questions') as Map<String, dynamic>;
          return (response['data'] as List)
              .whereType<Map<String, dynamic>>()
              .toList();
        },
        builder: (context, questions, reload) {
          if (questions.isEmpty) {
            return const EmptyState(
                icon: Icons.question_answer_outlined,
                title: 'No product questions yet.');
          }

          Future<void> update(Future<dynamic> Function() action) async {
            try {
              await action();
              if (context.mounted) await reload();
            } on Object catch (error) {
              if (context.mounted) showMessage(context, error);
            }
          }

          return ListView(padding: const EdgeInsets.all(12), children: [
            for (final question in questions)
              Card(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      ListTile(
                        title: Text(question['question'].toString()),
                        subtitle: Text(question['product'].toString()),
                      ),
                      if (question['answer'] != null)
                        Padding(
                          padding: const EdgeInsets.symmetric(horizontal: 16),
                          child: Text(question['answer'].toString()),
                        ),
                      ButtonBar(children: [
                        TextButton(
                          onPressed: () => update(() => api
                              .delete('/seller/questions/${question['id']}')),
                          child: const Text('Hide'),
                        ),
                        TextButton(
                          onPressed: () async {
                            final answer = await _editText(
                              context,
                              title: 'Answer question',
                              label: 'Answer shown on the product page',
                              initial: question['answer'] as String? ?? '',
                              maxLength: 1000,
                            );
                            if (answer == null ||
                                answer.isEmpty ||
                                !context.mounted) return;
                            await update(() => api.post(
                                '/seller/questions/${question['id']}/answer',
                                {'answer': answer}));
                          },
                          child: const Text('Answer'),
                        ),
                      ]),
                    ]),
              ),
          ]);
        },
      ),
    );
  }
}
