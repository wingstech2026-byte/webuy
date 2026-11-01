import 'package:flutter/material.dart';

import '../api/rewards_models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// WiBuy Farm: grow a rice plant or mango tree with missions; a fully grown plant wins a real prize.
class FarmScreen extends StatefulWidget {
  const FarmScreen({super.key});

  @override
  State<FarmScreen> createState() => _FarmScreenState();
}

class _FarmScreenState extends State<FarmScreen> {
  int _reload = 0;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('🌱 WiBuy Farm')),
      body: Loader<Map<String, dynamic>>(
        key: ValueKey(_reload),
        load: () async => await api.get('/farm') as Map<String, dynamic>,
        builder: (context, json, reload) {
          final farm = json['farm'] is Map<String, dynamic> ? FarmInfo.fromJson(json['farm'] as Map<String, dynamic>) : null;
          final missions = (json['missions'] as List).whereType<Map<String, dynamic>>().map(FarmMissionInfo.fromJson).toList();
          final prizes = (json['prizes'] as List).whereType<Map<String, dynamic>>().toList();

          if (farm == null) {
            final plants = (json['plants'] as List).whereType<Map<String, dynamic>>().toList();
            return ListView(padding: const EdgeInsets.all(16), children: [
              const Text('Choose what to grow', style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800)),
              const SizedBox(height: 6),
              const Text('Water it by doing missions: check in, browse deals, share, order and review. Friends can water it too.'),
              const SizedBox(height: 14),
              for (final plant in plants)
                Card(
                  child: ListTile(
                    leading: Text(plant['key'] == 'mango' ? '🥭' : '🌾', style: const TextStyle(fontSize: 32)),
                    title: Text(plant['label'] as String, style: const TextStyle(fontWeight: FontWeight.w700)),
                    subtitle: Text('Prize: ${plant['reward'] ?? 'set by WiBuy'}'),
                    trailing: FilledButton(
                      style: FilledButton.styleFrom(minimumSize: const Size(80, 40)),
                      onPressed: () async {
                        try {
                          await api.post('/farm/plant', {'plant': plant['key']});
                        } on Object catch (e) {
                          if (context.mounted) showMessage(context, e);
                        }
                        setState(() => _reload++);
                      },
                      child: const Text('Plant'),
                    ),
                  ),
                ),
            ]);
          }

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(padding: const EdgeInsets.all(16), children: [
              Card(
                color: WiBuyColors.greenLight,
                child: Padding(
                  padding: const EdgeInsets.all(18),
                  child: Column(children: [
                    Text(farm.emoji, style: TextStyle(fontSize: 40 + farm.progressPercent * 0.6)),
                    Text('${farm.plantLabel}: ${farm.stage}', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
                    const SizedBox(height: 8),
                    ProgressBar(farm.progressPercent),
                    const SizedBox(height: 6),
                    Text('${farm.water} / ${farm.target} water · ${farm.progressPercent}%'),
                    if (farm.rewardLabel != null) Text('Prize when grown: ${farm.rewardLabel}', style: const TextStyle(fontWeight: FontWeight.w700)),
                    if (farm.harvests > 0) Text('Harvested ${farm.harvests} time(s)', style: const TextStyle(color: WiBuyColors.muted)),
                  ]),
                ),
              ),
              const SizedBox(height: 10),
              FilledButton.icon(
                onPressed: () => shareOnWhatsApp(context, 'farm', target: farm.code),
                icon: const Icon(Icons.water_drop),
                label: const Text('Ask friends to water it'),
              ),
              const SizedBox(height: 16),
              const Text("Today's missions", style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
              for (final mission in missions)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: Icon(mission.isDone ? Icons.check_circle : Icons.water_drop_outlined, color: mission.isDone ? WiBuyColors.green : WiBuyColors.muted),
                  title: Text(mission.label),
                  subtitle: Text('+${mission.water} water · ${mission.doneToday}/${mission.dailyLimit} today'),
                ),
              if (prizes.isNotEmpty) ...[
                const SizedBox(height: 10),
                const Text('My prizes', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                for (final prize in prizes)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    leading: const Text('🎁', style: TextStyle(fontSize: 24)),
                    title: Text(prize['description'] as String),
                    subtitle: Text(switch (prize['status']) {
                      'pending' => 'Comes with your next order',
                      'attached' => 'On its way with your order',
                      _ => 'Done',
                    }),
                  ),
              ],
            ]),
          );
        },
      ),
    );
  }
}
