import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../widgets/common.dart';
import '../widgets/photo_picker.dart';
import 'business_screen.dart';
import 'search_screen.dart';

/// Asks for a photo (camera or gallery), then opens the results. Logged-in shoppers only.
Future<void> startPhotoSearch(BuildContext context) async {
  if (!SessionScope.read(context).isLoggedIn) {
    showMessage(context, 'Log in to search with a photo.');
    return;
  }
  final source = await showModalBottomSheet<ImageSource>(
    context: context,
    builder: (sheet) => SafeArea(
      child: Column(mainAxisSize: MainAxisSize.min, children: [
        const Padding(padding: EdgeInsets.all(14), child: Text('Search with a photo', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
        ListTile(leading: const Icon(Icons.photo_camera), title: const Text('Take a photo'), onTap: () => Navigator.pop(sheet, ImageSource.camera)),
        ListTile(leading: const Icon(Icons.photo_library), title: const Text('Choose from my photos'), onTap: () => Navigator.pop(sheet, ImageSource.gallery)),
      ]),
    ),
  );
  if (source == null) return;
  // Resized on the phone first: a smaller upload on slow data.
  final file = await ImagePicker().pickImage(source: source, maxWidth: 1280, imageQuality: 80);
  if (file == null || !context.mounted) return;
  final bytes = await file.readAsBytes();
  if (!context.mounted) return;
  await Navigator.of(context).push(MaterialPageRoute(builder: (_) => PhotoSearchScreen(photo: (file.name, bytes))));
}

/// Search by photo: WiBuy says what the item is and shows who sells it. The photo is not saved.
class PhotoSearchScreen extends StatefulWidget {
  const PhotoSearchScreen({super.key, required this.photo});

  final PickedPhoto photo;

  @override
  State<PhotoSearchScreen> createState() => _PhotoSearchScreenState();
}

class _PhotoSearchScreenState extends State<PhotoSearchScreen> {
  late Future<Map<String, dynamic>> _result = _send();

  Future<Map<String, dynamic>> _send() async =>
      await SessionScope.read(context).api.postMultipart('/search/photo', const {}, {'photo': [widget.photo]}) as Map<String, dynamic>;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Search with a photo')),
        body: ListView(padding: const EdgeInsets.only(bottom: 24), children: [
          Padding(
            padding: const EdgeInsets.all(12),
            child: ClipRRect(borderRadius: BorderRadius.circular(10), child: Image.memory(widget.photo.$2, height: 180, fit: BoxFit.cover)),
          ),
          FutureBuilder<Map<String, dynamic>>(
            future: _result,
            builder: (context, snap) {
              if (snap.connectionState != ConnectionState.done) {
                return const Padding(
                  padding: EdgeInsets.all(24),
                  child: Column(children: [CircularProgressIndicator(), SizedBox(height: 10), Text('Looking at your photo…')]),
                );
              }
              if (snap.hasError) {
                final e = snap.error;
                return ErrorRetry(
                  message: e is ApiException ? (e.status == 404 ? 'Search by photo is not switched on yet.' : e.display) : e.toString(),
                  onRetry: () => setState(() => _result = _send()),
                );
              }
              return _results(snap.data!);
            },
          ),
        ]),
      );

  Widget _results(Map<String, dynamic> json) {
    final u = json['understood'] as Map<String, dynamic>;
    final words = (u['search_words'] as List).whereType<String>().toList();
    final products = (json['products'] as List).whereType<Map<String, dynamic>>().map(Product.fromJson).toList();
    final name = u['name'] as String? ?? '';

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12),
        child: Text(u['reply'] as String? ?? '', style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
      ),
      if (u['is_item'] != true)
        const Padding(padding: EdgeInsets.all(12), child: Text('We could not see an item to buy. Try a closer photo of just the item.'))
      else ...[
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Wrap(spacing: 8, runSpacing: 4, children: [
            for (final w in words)
              ActionChip(
                label: Text(w),
                onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => SearchScreen(title: w, initialQuery: w))),
              ),
          ]),
        ),
        if (products.isNotEmpty) ...[
          const Padding(padding: EdgeInsets.fromLTRB(12, 8, 12, 8), child: Text('Available on WiBuy', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800))),
          ProductGrid(products),
        ] else
          Card(
            margin: const EdgeInsets.all(12),
            child: Padding(
              padding: const EdgeInsets.all(14),
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Text('No seller has "$name" on WiBuy right now.', style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 4),
                const Text('Ask sellers for it: they can send you a price.', style: TextStyle(color: WiBuyColors.muted)),
                const SizedBox(height: 8),
                FilledButton(
                  onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => RfqCreateScreen(draft: {'title': name, 'description': u['reply'], 'quantity': 1, 'unit': 'piece'}),
                  )),
                  child: const Text('Ask sellers for it'),
                ),
              ]),
            ),
          ),
      ],
      Padding(
        padding: const EdgeInsets.all(12),
        child: Text('${json['left_today']} photo searches left today.', style: const TextStyle(color: WiBuyColors.muted, fontSize: 12)),
      ),
    ]);
  }
}
