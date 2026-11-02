import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../api/models.dart';
import '../session.dart';
import '../screens/product_screen.dart';
import '../theme.dart';
import '../util/money.dart';

/// Loads data once, shows a spinner, and a friendly "Try again" on errors.
class Loader<T> extends StatefulWidget {
  const Loader({super.key, required this.load, required this.builder});

  final Future<T> Function() load;
  final Widget Function(
      BuildContext context, T data, Future<void> Function() reload) builder;

  @override
  State<Loader<T>> createState() => _LoaderState<T>();
}

class _LoaderState<T> extends State<Loader<T>> {
  late Future<T> _future = widget.load();

  Future<void> _reload() async {
    final next = widget.load();
    setState(() => _future = next);
    await next.then((_) {}, onError: (_) {});
  }

  @override
  Widget build(BuildContext context) => FutureBuilder<T>(
        future: _future,
        builder: (context, snapshot) {
          if (snapshot.hasError) {
            return ErrorRetry(
                message: snapshot.error.toString(), onRetry: _reload);
          }
          if (!snapshot.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          return widget.builder(context, snapshot.data as T, _reload);
        },
      );
}

class ErrorRetry extends StatelessWidget {
  const ErrorRetry({super.key, required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.wifi_off, size: 48, color: WiBuyColors.muted),
            const SizedBox(height: 12),
            Text(message,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16)),
            const SizedBox(height: 16),
            SizedBox(
                width: 200,
                child: FilledButton(
                    onPressed: onRetry, child: const Text('Try again'))),
          ]),
        ),
      );
}

class EmptyState extends StatelessWidget {
  const EmptyState(
      {super.key, required this.icon, required this.title, this.action});

  final IconData icon;
  final String title;
  final Widget? action;

  @override
  Widget build(BuildContext context) => Center(
        child: Padding(
          padding: const EdgeInsets.all(32),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Icon(icon, size: 56, color: WiBuyColors.muted),
            const SizedBox(height: 12),
            Text(title,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 17, color: WiBuyColors.text)),
            if (action != null) ...[const SizedBox(height: 16), action!],
          ]),
        ),
      );
}

/// Network photo with a grey box while loading or when the image is missing.
class Photo extends StatelessWidget {
  const Photo(this.url, {super.key, this.size, this.radius = 10});

  final String? url;
  final double? size;
  final double radius;

  @override
  Widget build(BuildContext context) {
    const placeholder = ColoredBox(
        color: Color(0xFFE7E5E4),
        child: Center(
            child: Icon(Icons.image_outlined, color: WiBuyColors.muted)));

    return ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: SizedBox(
        width: size,
        height: size,
        child: url == null || url!.isEmpty
            ? placeholder
            : Image.network(
                url!,
                fit: BoxFit.cover,
                // Browser preview only: an <img> tag shows Laravel's /storage photos without CORS headers.
                webHtmlElementStrategy: WebHtmlElementStrategy.prefer,
                errorBuilder: (_, __, ___) => placeholder,
              ),
      ),
    );
  }
}

class ProductCard extends StatelessWidget {
  const ProductCard(this.product, {super.key});

  final Product product;

  @override
  Widget build(BuildContext context) => Card(
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => Navigator.of(context).push(MaterialPageRoute(
              builder: (_) => ProductScreen(slug: product.slug))),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
            // The photo takes whatever height the text leaves, so cards never overflow.
            Expanded(
              child: Stack(fit: StackFit.expand, children: [
                Photo(product.thumbUrl, radius: 0),
                if (product.isSponsored)
                  Positioned(
                    left: 6,
                    bottom: 6,
                    child: Container(
                      padding: const EdgeInsets.symmetric(
                          horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.9),
                          borderRadius: BorderRadius.circular(6)),
                      child: const Text('Sponsored',
                          style: TextStyle(
                              fontSize: 11, color: WiBuyColors.muted)),
                    ),
                  ),
              ]),
            ),
            Padding(
              padding: const EdgeInsets.all(8),
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(product.title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(fontSize: 14)),
                    const SizedBox(height: 4),
                    Text(formatLe(product.price),
                        style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.w800,
                            color: WiBuyColors.green)),
                    if (product.hasTiers)
                      Text('From ${formatLe(product.lowestPrice)} in bulk',
                          style: const TextStyle(
                              fontSize: 12, color: WiBuyColors.orange)),
                    if (product.seller != null)
                      Text(
                        '${product.seller!.isVerified ? '✓ ' : ''}${product.seller!.shopName}',
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontSize: 12, color: WiBuyColors.muted),
                      ),
                  ]),
            ),
          ]),
        ),
      );
}

/// Two-column product grid inside a scroll view.
class ProductGrid extends StatelessWidget {
  const ProductGrid(this.products, {super.key});

  final List<Product> products;

  @override
  Widget build(BuildContext context) => GridView.builder(
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        padding: const EdgeInsets.symmetric(horizontal: 12),
        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
          crossAxisCount: 2,
          mainAxisSpacing: 10,
          crossAxisSpacing: 10,
          childAspectRatio: 0.62,
        ),
        itemCount: products.length,
        itemBuilder: (_, i) => ProductCard(products[i]),
      );
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.label, {super.key, this.status});

  final String label;
  final String? status;

  @override
  Widget build(BuildContext context) {
    final bad = status == 'cancelled' ||
        status == 'refunded' ||
        status == 'rejected' ||
        status == 'disputed';
    final done = status == 'completed' || status == 'delivered';

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: bad
            ? const Color(0xFFFEE2E2)
            : (done ? WiBuyColors.greenLight : const Color(0xFFFEF3C7)),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Text(label,
          style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600)),
    );
  }
}

void showMessage(BuildContext context, Object message) {
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
        content: Text(
            message is ApiException ? message.display : message.toString())));
}

/// Pages that stay on the website (selling, problem reports with photos, chat).
Future<void> openLink(BuildContext context, String url) async {
  final ok =
      await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  if (!ok && context.mounted) {
    showMessage(context, 'Could not open $url');
  }
}

/// Make a share link on the backend (so a friend's open counts) and open WhatsApp with it.
Future<void> shareOnWhatsApp(BuildContext context, String kind,
    {String? target}) async {
  final session = SessionScope.read(context);
  if (!session.isLoggedIn) {
    showMessage(
        context, 'Log in first, so your friends\' visits count for you.');
    return;
  }
  try {
    final json = await session.api.post(
            '/shares', {'kind': kind, if (target != null) 'target': target})
        as Map<String, dynamic>;
    if (context.mounted)
      await openLink(context, json['whatsapp_url'] as String);
  } on Object catch (e) {
    if (context.mounted) showMessage(context, e);
  }
}

/// "1h 05m 12s" until a time, updated every second.
class Countdown extends StatefulWidget {
  const Countdown(this.until, {super.key, this.style, this.prefix = ''});

  final DateTime until;
  final TextStyle? style;
  final String prefix;

  @override
  State<Countdown> createState() => _CountdownState();
}

class _CountdownState extends State<Countdown> {
  late final Stream<int> _ticks =
      Stream.periodic(const Duration(seconds: 1), (i) => i);

  @override
  Widget build(BuildContext context) => StreamBuilder<int>(
        stream: _ticks,
        builder: (context, _) {
          final left = widget.until.difference(DateTime.now());
          if (left.isNegative) {
            return Text('${widget.prefix}0s', style: widget.style);
          }
          final h = left.inHours;
          final m = left.inMinutes.remainder(60).toString().padLeft(2, '0');
          final s = left.inSeconds.remainder(60).toString().padLeft(2, '0');
          return Text('${widget.prefix}${h > 0 ? '${h}h ' : ''}${m}m ${s}s',
              style: widget.style);
        },
      );
}

/// A green progress bar with a label, for price cuts and the farm.
class ProgressBar extends StatelessWidget {
  const ProgressBar(this.percent, {super.key});

  final int percent;

  @override
  Widget build(BuildContext context) => ClipRRect(
        borderRadius: BorderRadius.circular(8),
        child: LinearProgressIndicator(
          value: (percent.clamp(0, 100)) / 100,
          minHeight: 12,
          backgroundColor: const Color(0xFFE7E5E4),
          color: WiBuyColors.green,
        ),
      );
}

/// A sideways list with a scrollbar that is always visible and can be dragged, so people can see
/// there is more to the left or right (categories, deals, shops).
class HorizontalStrip extends StatefulWidget {
  const HorizontalStrip(
      {super.key,
      required this.height,
      required this.itemCount,
      required this.itemBuilder,
      this.spacing = 10});

  /// Height of the items (the scrollbar adds a little below).
  final double height;
  final int itemCount;
  final IndexedWidgetBuilder itemBuilder;
  final double spacing;

  @override
  State<HorizontalStrip> createState() => _HorizontalStripState();
}

class _HorizontalStripState extends State<HorizontalStrip> {
  final _controller = ScrollController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => SizedBox(
        height: widget.height + 16,
        child: RawScrollbar(
          controller: _controller,
          thumbVisibility: true,
          trackVisibility: true,
          interactive: true,
          thickness: 6,
          radius: const Radius.circular(3),
          thumbColor: WiBuyColors.green,
          trackColor: WiBuyColors.greenLight,
          trackRadius: const Radius.circular(3),
          mainAxisMargin: 12,
          child: ListView.separated(
            controller: _controller,
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.fromLTRB(12, 0, 12, 16),
            itemCount: widget.itemCount,
            separatorBuilder: (_, __) => SizedBox(width: widget.spacing),
            itemBuilder: widget.itemBuilder,
          ),
        ),
      );
}
