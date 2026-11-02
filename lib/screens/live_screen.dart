import 'dart:async';

import 'package:flutter/material.dart';

import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'login_screen.dart';
import 'product_screen.dart';

/// 🔴 Live selling: shops showing goods live (on YouTube or Facebook), what is coming up, and replays.
class LiveListScreen extends StatelessWidget {
  const LiveListScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('🔴 Live selling')),
        body: Loader<Map<String, dynamic>>(
          load: () async => await SessionScope.read(context).api.get('/live') as Map<String, dynamic>,
          builder: (context, json, reload) {
            final shows = (json['data'] as List).whereType<Map<String, dynamic>>().toList();
            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(12), children: [
                if (shows.isEmpty) const Padding(padding: EdgeInsets.all(20), child: Text('No live shows yet.')),
                for (final show in shows)
                  Card(
                    child: ListTile(
                      leading: Text(show['status'] == 'live' ? '🔴' : (show['status'] == 'scheduled' ? '🕐' : '▶️'), style: const TextStyle(fontSize: 26)),
                      title: Text('${show['title']}', style: const TextStyle(fontWeight: FontWeight.w800)),
                      subtitle: Text('${show['shop_name']} · ${show['status'] == 'live' ? 'LIVE now' : (show['status'] == 'scheduled' ? 'Coming up' : 'Replay')}'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => LiveShowScreen(id: (show['id'] as num).toInt()))),
                    ),
                  ),
              ]),
            );
          },
        ),
      );
}

/// One show: watch the video (opens YouTube or Facebook), the pinned product, products and comments.
class LiveShowScreen extends StatefulWidget {
  const LiveShowScreen({super.key, required this.id});

  final int id;

  @override
  State<LiveShowScreen> createState() => _LiveShowScreenState();
}

class _LiveShowScreenState extends State<LiveShowScreen> {
  Map<String, dynamic>? _show;
  List<Product> _products = const [];
  Map<String, dynamic>? _pinned;
  final List<Map<String, dynamic>> _comments = [];
  final _comment = TextEditingController();
  Object? _error;
  Timer? _timer;

  int get _last => _comments.isEmpty ? 0 : (_comments.last['id'] as num).toInt();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _timer?.cancel();
    _comment.dispose();
    super.dispose();
  }

  void _apply(Map<String, dynamic> feed) {
    _pinned = feed['pinned'] as Map<String, dynamic>?;
    for (final c in (feed['comments'] as List).whereType<Map<String, dynamic>>()) {
      if ((c['id'] as num).toInt() > _last) _comments.add(c);
    }
  }

  Future<void> _load() async {
    try {
      final json = await SessionScope.read(context).api.get('/live/${widget.id}?after=$_last') as Map<String, dynamic>;
      if (!mounted) return;
      setState(() {
        _show = json['data'] as Map<String, dynamic>;
        _products = (json['products'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();
        _apply(json['feed'] as Map<String, dynamic>);
      });
      // New comments and the pinned product every few seconds while it is live.
      if (_show!['status'] == 'live') _timer ??= Timer.periodic(const Duration(seconds: 5), (_) => _load());
    } on Object catch (e) {
      if (mounted && _show == null) setState(() => _error = e);
    }
  }

  Future<void> _send() async {
    final text = _comment.text.trim();
    if (text.isEmpty || !await ensureLoggedIn(context) || !mounted) return;
    try {
      final feed = await SessionScope.read(context).api.post('/live/${widget.id}/comments?after=$_last', {'body': text}) as Map<String, dynamic>;
      _comment.clear();
      if (mounted) setState(() => _apply(feed));
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  @override
  Widget build(BuildContext context) {
    final show = _show;
    if (show == null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Live')),
        body: _error != null ? ErrorRetry(message: _error.toString(), onRetry: _load) : const Center(child: CircularProgressIndicator()),
      );
    }
    final live = show['status'] == 'live';
    final pinned = _pinned;

    return Scaffold(
      appBar: AppBar(title: Text('${show['shop_name']}')),
      body: ListView(padding: const EdgeInsets.all(12), children: [
        Text(live ? '🔴 LIVE' : (show['status'] == 'scheduled' ? 'Coming up' : 'Replay'), style: TextStyle(color: live ? Colors.red : WiBuyColors.muted, fontWeight: FontWeight.w800)),
        Text('${show['title']}', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
        const SizedBox(height: 8),
        FilledButton.icon(
          onPressed: () => openLink(context, show['stream_url'] as String),
          icon: const Icon(Icons.play_circle_fill),
          label: Text(live ? 'Watch the live video' : 'Watch the video'),
        ),
        if (pinned != null)
          Card(
            color: Colors.red.shade50,
            child: ListTile(
              leading: const Text('📌', style: TextStyle(fontSize: 22)),
              title: Text('Showing now: ${pinned['title']}'),
              subtitle: Text(formatLe(pinned['price'] as String?)),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => ProductScreen(slug: pinned['slug'] as String))),
            ),
          ),
        const SizedBox(height: 8),
        const Text('Comments', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
        if (_comments.isEmpty) const Text('No comments yet.', style: TextStyle(color: WiBuyColors.muted)),
        for (final c in _comments.length > 30 ? _comments.sublist(_comments.length - 30) : _comments)
          Padding(padding: const EdgeInsets.symmetric(vertical: 2), child: Text('${c['name']}: ${c['body']}')),
        if (live)
          Row(children: [
            Expanded(child: TextField(controller: _comment, maxLength: 200, decoration: const InputDecoration(hintText: 'Ask about the goods...', counterText: ''))),
            IconButton(onPressed: _send, icon: const Icon(Icons.send)),
          ]),
        const SizedBox(height: 12),
        const Text('In this show', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
        ProductGrid(_products),
      ]),
    );
  }
}
