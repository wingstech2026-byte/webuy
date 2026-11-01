import 'package:flutter/material.dart';

import '../main.dart' show routeFor;
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import 'chat_screen.dart';
import 'order_detail_screen.dart';

/// Order, payment, deal and chat updates. Tapping one opens the right screen.
class NotificationsScreen extends StatefulWidget {
  const NotificationsScreen({super.key});

  @override
  State<NotificationsScreen> createState() => _NotificationsScreenState();
}

class _NotificationsScreenState extends State<NotificationsScreen> {
  @override
  void dispose() {
    // Opening the list marks everything as read.
    final session = SessionScope.read(context);
    session.api.post('/notifications/read').then((_) => session.refreshCounts(), onError: (_) {});
    super.dispose();
  }

  void _open(String? link) {
    if (link == null) return;
    final orderId = RegExp(r'^/orders/(\d+)$').firstMatch(link)?.group(1);
    final Route<void>? route = orderId != null
        ? MaterialPageRoute(builder: (_) => OrderDetailScreen(orderId: int.parse(orderId)))
        : link == '/chat'
            ? MaterialPageRoute(builder: (_) => const ChatListScreen())
            : routeFor(link);
    if (route != null) Navigator.of(context).push(route);
  }

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Notifications')),
      body: Loader<List<Map<String, dynamic>>>(
        load: () async => ((await api.get('/notifications') as Map<String, dynamic>)['data'] as List).whereType<Map<String, dynamic>>().toList(),
        builder: (context, items, reload) => items.isEmpty
            ? const EmptyState(icon: Icons.notifications_none, title: 'No notifications yet. Order updates, payments and deals show here.')
            : RefreshIndicator(
                onRefresh: reload,
                child: ListView.separated(
                  itemCount: items.length,
                  separatorBuilder: (_, __) => const Divider(height: 1),
                  itemBuilder: (_, i) {
                    final n = items[i];
                    final unread = n['read'] != true;
                    final at = DateTime.tryParse(n['created_at'] as String? ?? '')?.toLocal();
                    return ListTile(
                      tileColor: unread ? WiBuyColors.greenLight : null,
                      leading: Icon(unread ? Icons.notifications_active : Icons.notifications_none, color: WiBuyColors.green),
                      title: Text(n['title'] as String, style: TextStyle(fontWeight: unread ? FontWeight.w800 : FontWeight.w500)),
                      subtitle: Text('${n['body']}${at != null ? '\n${at.day}/${at.month} ${at.hour.toString().padLeft(2, '0')}:${at.minute.toString().padLeft(2, '0')}' : ''}'),
                      isThreeLine: true,
                      onTap: () => _open(n['link'] as String?),
                    );
                  },
                ),
              ),
      ),
    );
  }
}
