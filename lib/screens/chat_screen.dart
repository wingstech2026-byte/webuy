import 'dart:async';

import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/photo_picker.dart';
import 'login_screen.dart';

/// My chats: the WiBuy support team, and chats with sellers or buyers (when switched on).
class ChatListScreen extends StatelessWidget {
  const ChatListScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Chat')),
      body: Loader<Map<String, dynamic>>(
        load: () async => await api.get('/chat') as Map<String, dynamic>,
        builder: (context, json, reload) {
          final rooms = [json['support'] as Map<String, dynamic>, ...(json['rooms'] as List).whereType<Map<String, dynamic>>()];

          return RefreshIndicator(
            onRefresh: reload,
            child: ListView(children: [
              for (final room in rooms)
                ListTile(
                  leading: CircleAvatar(
                    backgroundColor: WiBuyColors.greenLight,
                    child: Icon(room == rooms.first ? Icons.support_agent : Icons.storefront, color: WiBuyColors.green),
                  ),
                  title: Text(room['title'] as String, style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text((room['last_message'] as String?) ?? 'Say hello', maxLines: 1, overflow: TextOverflow.ellipsis),
                  trailing: (room['unread'] as int) > 0 ? Badge(label: Text('${room['unread']}')) : null,
                  onTap: () async {
                    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen(id: room['id'] as int, title: room['title'] as String)));
                    reload();
                  },
                ),
            ]),
          );
        },
      ),
    );
  }
}

/// Open a chat with a seller (buyer-seller chat), or WiBuy support when that is switched off.
Future<void> openChatWith(BuildContext context, {int? userId, String? title}) async {
  if (!await ensureLoggedIn(context) || !context.mounted) return;
  final api = SessionScope.read(context).api;
  try {
    if (userId != null) {
      try {
        final json = await api.post('/chat/direct/$userId') as Map<String, dynamic>;
        if (context.mounted) {
          await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen(id: json['id'] as int, title: title ?? 'Chat')));
        }
        return;
      } on ApiException catch (e) {
        if (e.status != 404) rethrow; // 404: buyer-seller chat is off; ask WiBuy support instead
      }
    }
    final json = await api.get('/chat') as Map<String, dynamic>;
    final support = json['support'] as Map<String, dynamic>;
    if (context.mounted) {
      await Navigator.of(context).push(MaterialPageRoute(builder: (_) => ChatScreen(id: support['id'] as int, title: 'WiBuy support')));
    }
  } on Object catch (e) {
    if (context.mounted) showMessage(context, e);
  }
}

class ChatScreen extends StatefulWidget {
  const ChatScreen({super.key, required this.id, required this.title});

  final int id;
  final String title;

  @override
  State<ChatScreen> createState() => _ChatScreenState();
}

class _ChatScreenState extends State<ChatScreen> {
  final _text = TextEditingController();
  final _scroll = ScrollController();
  final List<Map<String, dynamic>> _messages = [];
  Timer? _poll;
  bool _canPost = true;
  bool _sending = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    _load();
    _poll = Timer.periodic(const Duration(seconds: 5), (_) => _load(newer: true));
  }

  @override
  void dispose() {
    _poll?.cancel();
    _text.dispose();
    _scroll.dispose();
    super.dispose();
  }

  Future<void> _load({bool newer = false}) async {
    final after = newer && _messages.isNotEmpty ? _messages.last['id'] as int : null;
    try {
      final json = await SessionScope.read(context).api.get('/chat/${widget.id}/messages', query: {if (after != null) 'after_id': '$after'}) as Map<String, dynamic>;
      final fresh = (json['data'] as List).whereType<Map<String, dynamic>>().toList();
      if (!mounted) return;
      setState(() {
        if (!newer) _messages.clear();
        _messages.addAll(fresh.where((m) => !_messages.any((old) => old['id'] == m['id'])));
        _canPost = json['can_post'] != false;
        _loading = false;
      });
      if (fresh.isNotEmpty) _toBottom();
    } on Object catch (e) {
      if (mounted && !newer) {
        setState(() => _loading = false);
        showMessage(context, e);
      }
    }
  }

  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.jumpTo(_scroll.position.maxScrollExtent);
      });

  Future<void> _send({PickedPhoto? photo}) async {
    final text = _text.text.trim();
    if (text.isEmpty && photo == null) return;
    setState(() => _sending = true);
    final api = SessionScope.read(context).api;
    try {
      final json = (photo == null
          ? await api.post('/chat/${widget.id}/messages', {'body': text})
          : await api.postMultipart('/chat/${widget.id}/messages', {if (text.isNotEmpty) 'body': text}, {'image': [(photo.$1, photo.$2)]})) as Map<String, dynamic>;
      _text.clear();
      setState(() => _messages.add(json['data'] as Map<String, dynamic>));
      _toBottom();
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _sendPhoto() async {
    PickedPhoto? picked;
    await showModalBottomSheet<void>(
      context: context,
      builder: (context) => Padding(
        padding: const EdgeInsets.all(20),
        child: PhotoPicker(max: 1, label: 'Send a photo', onChanged: (p) {
          if (p.isNotEmpty) {
            picked = p.first;
            Navigator.pop(context);
          }
        }),
      ),
    );
    if (picked != null) await _send(photo: picked);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(widget.title)),
        body: Column(children: [
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _messages.isEmpty
                    ? const EmptyState(icon: Icons.chat_bubble_outline, title: 'No messages yet. Say hello!')
                    : ListView.builder(
                        controller: _scroll,
                        padding: const EdgeInsets.all(12),
                        itemCount: _messages.length,
                        itemBuilder: (_, i) => _Bubble(_messages[i]),
                      ),
          ),
          SafeArea(
            top: false,
            child: _canPost
                ? Padding(
                    padding: const EdgeInsets.fromLTRB(8, 4, 8, 8),
                    child: Row(children: [
                      IconButton(onPressed: _sending ? null : _sendPhoto, icon: const Icon(Icons.photo_camera_outlined, color: WiBuyColors.green)),
                      Expanded(
                        child: TextField(
                          controller: _text,
                          minLines: 1,
                          maxLines: 4,
                          textInputAction: TextInputAction.send,
                          onSubmitted: (_) => _send(),
                          decoration: const InputDecoration(hintText: 'Write a message', contentPadding: EdgeInsets.symmetric(horizontal: 14, vertical: 10)),
                        ),
                      ),
                      IconButton(onPressed: _sending ? null : () => _send(), icon: const Icon(Icons.send, color: WiBuyColors.green)),
                    ]),
                  )
                : const Padding(padding: EdgeInsets.all(16), child: Text('You cannot send messages in this chat.', style: TextStyle(color: WiBuyColors.muted))),
          ),
        ]),
      );
}

class _Bubble extends StatelessWidget {
  const _Bubble(this.m);

  final Map<String, dynamic> m;

  @override
  Widget build(BuildContext context) {
    if (m['type'] == 'system') {
      return Container(
        margin: const EdgeInsets.symmetric(vertical: 6),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: const Color(0xFFFEF3C7), borderRadius: BorderRadius.circular(10)),
        child: Text(m['body'] as String? ?? '', textAlign: TextAlign.center),
      );
    }
    final mine = m['mine'] == true;

    return Align(
      alignment: mine ? Alignment.centerRight : Alignment.centerLeft,
      child: Container(
        constraints: const BoxConstraints(maxWidth: 280),
        margin: const EdgeInsets.symmetric(vertical: 4),
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(color: mine ? WiBuyColors.greenLight : Colors.white, borderRadius: BorderRadius.circular(12)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          if (!mine) Text(m['author'] as String? ?? '', style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: WiBuyColors.green)),
          if (m['thumb_url'] != null) Padding(padding: const EdgeInsets.only(bottom: 4), child: Photo(m['thumb_url'] as String, size: 180)),
          if ((m['body'] as String?)?.isNotEmpty ?? false) Text(m['body'] as String),
          if (m['audio_url'] != null) const Text('🎤 Voice message (open on the website)', style: TextStyle(color: WiBuyColors.muted)),
        ]),
      ),
    );
  }
}
