import 'package:flutter/material.dart';

import '../api/models.dart';
import '../l10n.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'chat_screen.dart';
import 'checkout_screen.dart';
import 'compare_screen.dart';
import 'login_screen.dart';
import 'price_cut_screen.dart';
import 'shop_screen.dart';
import 'trials_screen.dart';

class ProductDetail {
  ProductDetail(Map<String, dynamic> j)
      : product = Product.fromJson(j['data'] as Map<String, dynamic>),
        reviews = (j['reviews'] as List)
            .whereType<Map<String, dynamic>>()
            .map(Review.fromJson)
            .toList(),
        alsoBought = ((j['also_bought'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(Product.fromJson)
            .toList(),
        questions = ((j['questions'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .toList(),
        addons = ((j['addons'] as List?) ?? const []).whereType<Map<String, dynamic>>().toList(),
        offers = ((j['offers'] as List?) ?? const []).whereType<Map<String, dynamic>>().map((o) => '${o['label']}').toList(),
        bundles = ((j['bundles'] as List?) ?? const []).whereType<Map<String, dynamic>>().toList(),
        foodScores = j['food_scores'] as Map<String, dynamic>?;

  /// Food: average taste, portion and packaging ({count, taste, portion, packaging}).
  final Map<String, dynamic>? foodScores;

  /// Extras for a dish: {id, name, price}.
  final List<Map<String, dynamic>> addons;

  /// The shop's own offers ("Buy 2 Oil, get 1 free") and bundles with this product.
  final List<String> offers;
  final List<Map<String, dynamic>> bundles;

  final Product product;

  /// Answered questions about the product: {question, answer, answered_at}.
  final List<Map<String, dynamic>> questions;
  final List<Review> reviews;

  /// What other buyers of this product also paid for.
  final List<Product> alsoBought;
}

class ProductScreen extends StatelessWidget {
  const ProductScreen({super.key, required this.slug});

  final String slug;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;

    return Scaffold(
      appBar: AppBar(title: const Text('Product')),
      body: Loader<ProductDetail>(
        load: () async => ProductDetail(
            await api.get('/products/$slug') as Map<String, dynamic>),
        builder: (context, data, _) => _ProductBody(data),
      ),
    );
  }
}

class _ProductBody extends StatefulWidget {
  const _ProductBody(this.data);

  final ProductDetail data;

  @override
  State<_ProductBody> createState() => _ProductBodyState();
}

class _ProductBodyState extends State<_ProductBody> {
  late int _qty = widget.data.product.minOrderQuantity;

  /// The chosen option, when the product has options (sizes, colours...).
  ProductOption? _option;

  /// Extras ticked (extra meat...).
  final Set<int> _addons = {};

  @override
  void initState() {
    super.initState();
    SessionScope.read(context).viewed(widget.data.product.slug);
  }

  bool _busy = false;

  Product get p => widget.data.product;

  /// The price for this quantity: the best tier reached, else the normal price. Display only;
  /// the server prices the cart and the checkout.
  String get _unitPrice {
    // An option with its own price is sold at exactly that price.
    if (_option?.price != null) return _option!.price!;
    var price = p.price;
    for (final tier in p.tiers) {
      if (_qty >= tier.minQuantity) {
        price = tier.unitPrice;
      }
    }
    final deal = p.flashDeal;
    if (deal != null &&
        deal.isLive &&
        _qty <= deal.maxPerOrder &&
        _cents(deal.dealPrice) < _cents(price)) {
      price = deal.dealPrice;
    }
    return price;
  }

  static int _cents(String amount) {
    final parts = amount.split('.');
    return int.parse(parts[0]) * 100 +
        int.parse((parts.length > 1 ? parts[1] : '0')
            .padRight(2, '0')
            .substring(0, 2));
  }

  bool _optionMissing() {
    if (p.variants.isEmpty || _option != null) return false;
    showMessage(context, 'Choose an option first (size, colour...).');
    return true;
  }

  Future<void> _addToCart() async {
    if (_optionMissing()) return;
    if (!await ensureLoggedIn(context) || !mounted) return;
    final session = SessionScope.read(context);
    setState(() => _busy = true);
    try {
      final cart = await session.api.post('/cart/${p.slug}', {
        'quantity': _qty,
        if (_option != null) 'variant': _option!.id,
        if (_addons.isNotEmpty) 'addons': _addons.toList(),
      }) as Map<String, dynamic>;
      session.setCartCount((cart['count'] as num?)?.toInt() ?? 0);
      if (mounted) showMessage(context, 'Added to your cart.');
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _buyNow() async {
    if (_optionMissing()) return;
    if (!await ensureLoggedIn(context) || !mounted) return;
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => CheckoutScreen(
            productSlug: p.slug, quantity: _qty, variantId: _option?.id, addonIds: _addons.toList())));
  }

  /// Every item of a bundle into the cart; the bundle price is taken off at checkout.
  Future<void> _addBundle(Map<String, dynamic> bundle) async {
    if (!await ensureLoggedIn(context) || !mounted) return;
    final session = SessionScope.read(context);
    try {
      final cart = await session.api.post('/bundles/${bundle['id']}/cart') as Map<String, dynamic>;
      session.setCartCount((cart['count'] as num?)?.toInt() ?? 0);
      if (mounted) showMessage(context, '${bundle['title']} is in your cart: the bundle price is taken off at checkout.');
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  Future<void> _toggleSave() async {
    if (!await ensureLoggedIn(context) || !mounted) return;
    try {
      final result = await SessionScope.read(context)
          .api
          .post('/wishlist/${p.slug}') as Map<String, dynamic>;
      if (mounted)
        showMessage(
            context,
            result['saved'] == true
                ? 'Saved to your wishlist.'
                : 'Removed from your wishlist.');
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  /// Ask the shop a question; it shows here for everyone once answered.
  Future<void> _ask() async {
    if (!SessionScope.read(context).isLoggedIn) {
      showMessage(context, 'Log in to ask the shop a question.');
      return;
    }
    final text = TextEditingController();
    final question = await showDialog<String>(
      context: context,
      builder: (dialog) => AlertDialog(
        title: const Text('Ask the shop'),
        content: TextField(
          controller: text,
          autofocus: true,
          maxLength: 500,
          maxLines: 3,
          decoration: const InputDecoration(
              hintText: 'Does it come with a charger?',
              helperText: 'No phone numbers: keep it to the product.'),
        ),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(dialog),
              child: const Text('Cancel')),
          FilledButton(
              onPressed: () => Navigator.pop(dialog, text.text.trim()),
              child: const Text('Send')),
        ],
      ),
    );
    if (question == null || question.isEmpty || !mounted) return;
    try {
      await SessionScope.read(context)
          .api
          .post('/products/${p.slug}/questions', {'question': question});
      if (mounted)
        showMessage(
            context, 'Question sent. You will be told when the shop answers.');
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    }
  }

  void _share() {
    if (SessionScope.read(context).isLoggedIn) {
      // A tracked link: a friend opening it gives you an extra spin and farm water.
      shareOnWhatsApp(context, 'product', target: p.slug);
      return;
    }
    final text = Uri.encodeComponent(
        '${p.title} - ${formatLe(p.price)} on WiBuy ${p.webUrl ?? ''}');
    openLink(context, 'https://wa.me/?text=$text');
  }

  @override
  Widget build(BuildContext context) {
    final full =
        SessionScope.of(context).dataSaver ? p.imageThumbUrls : p.imageUrls;
    final images = full.isEmpty ? <String?>[p.thumbUrl] : full;
    final stock = _option?.stock ?? p.stock;
    final outOfStock = stock < 1;
    final maxQty = stock < 1 ? 1 : stock;

    return Column(children: [
      Expanded(
        child: ListView(children: [
          AspectRatio(
            aspectRatio: 1,
            child: PageView(
                children: [for (final url in images) Photo(url, radius: 0)]),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child:
                Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(p.title,
                  style: const TextStyle(
                      fontSize: 20, fontWeight: FontWeight.w700)),
              if (p.seller?.freeDeliveryFrom != null)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text(
                      '🚚 Free delivery from ${formatLe(p.seller!.freeDeliveryFrom)} in this shop',
                      style: const TextStyle(
                          color: WiBuyColors.orange,
                          fontWeight: FontWeight.w700,
                          fontSize: 13)),
                ),
              if (p.genuineGuarantee)
                const Padding(
                  padding: EdgeInsets.only(top: 4),
                  child: Text(
                      '✅ Genuine guarantee: if it is fake, you get your money back plus compensation',
                      style: TextStyle(
                          color: WiBuyColors.green,
                          fontWeight: FontWeight.w700,
                          fontSize: 13)),
                ),
              if (p.videoUrl != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: TextButton.icon(
                    // Opens in the phone's video player: nothing downloads until the buyer taps.
                    onPressed: () => openLink(context, p.videoUrl!),
                    icon: const Icon(Icons.play_circle_outline),
                    label: Text(
                        "Watch the seller's video${p.videoSeconds != null ? ' (${p.videoSeconds}s)' : ''}"),
                  ),
                ),
              const SizedBox(height: 8),
              Row(crossAxisAlignment: CrossAxisAlignment.end, children: [
                Text(formatLe(_unitPrice),
                    style: const TextStyle(
                        fontSize: 26,
                        fontWeight: FontWeight.w900,
                        color: WiBuyColors.green)),
                Text(' / ${p.unit}',
                    style: const TextStyle(color: WiBuyColors.muted)),
                if (p.compareAtPrice != null) ...[
                  const SizedBox(width: 8),
                  Text(formatLe(p.compareAtPrice),
                      style: const TextStyle(
                          decoration: TextDecoration.lineThrough,
                          color: WiBuyColors.muted)),
                ],
              ]),
              if (p.flashDeal != null)
                Container(
                  margin: const EdgeInsets.only(top: 6),
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                      color: const Color(0xFFFFEDD5),
                      borderRadius: BorderRadius.circular(8)),
                  child: Row(children: [
                    const Text('⚡ Lightning deal ',
                        style: TextStyle(
                            fontWeight: FontWeight.w800,
                            color: WiBuyColors.orange)),
                    Text(formatLe(p.flashDeal!.dealPrice),
                        style: const TextStyle(fontWeight: FontWeight.w800)),
                    const Spacer(),
                    Countdown(
                        p.flashDeal!.isLive
                            ? p.flashDeal!.endsAt
                            : p.flashDeal!.startsAt,
                        prefix: p.flashDeal!.isLive ? 'ends ' : 'starts ',
                        style: const TextStyle(fontSize: 13)),
                  ]),
                ),
              for (final warning in p.riskWarnings)
                Container(
                  margin: const EdgeInsets.only(top: 6),
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                      color: const Color(0xFFFEF3C7),
                      borderRadius: BorderRadius.circular(8)),
                  child: Text('⚠️ $warning',
                      style: const TextStyle(fontWeight: FontWeight.w600)),
                ),
              if (p.leadTimeDays != null)
                Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                        '🛠️ Made to order: ready in ${p.leadTimeDays} days')),
              if (p.hasBusinessPrices)
                const Padding(
                    padding: EdgeInsets.only(top: 4),
                    child: Text('🏢 Lower prices for verified business buyers',
                        style: TextStyle(color: WiBuyColors.muted))),
              if (p.memberPrice != null)
                Padding(
                  padding: const EdgeInsets.only(top: 4),
                  child: Text('⭐ ${formatLe(p.memberPrice)} with WiBuy Pass',
                      style: const TextStyle(
                          color: WiBuyColors.green,
                          fontWeight: FontWeight.w700)),
                ),
              const SizedBox(height: 4),
              Text(
                '${p.ratingCount > 0 ? '★ ${p.ratingAvg.toStringAsFixed(1)} (${p.ratingCount}) · ' : ''}${p.soldCount} sold · '
                '${outOfStock ? 'Out of stock' : '$stock in stock'}',
                style: const TextStyle(color: WiBuyColors.muted),
              ),
              if (p.hasTiers) ...[
                const SizedBox(height: 14),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                      color: const Color(0xFFFFF7ED),
                      borderRadius: BorderRadius.circular(12)),
                  child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('Buy more, pay less',
                            style: TextStyle(
                                fontWeight: FontWeight.w800,
                                color: WiBuyColors.orange)),
                        const SizedBox(height: 6),
                        Text(
                            '1 – ${p.tiers.first.minQuantity - 1}: ${formatLe(p.price)} each'),
                        for (final tier in p.tiers)
                          Text(
                              '${tier.minQuantity}+: ${formatLe(tier.unitPrice)} each'),
                      ]),
                ),
              ],
              if (p.variants.isNotEmpty) ...[
                const SizedBox(height: 14),
                const Text('Choose an option',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                const SizedBox(height: 6),
                Wrap(spacing: 8, runSpacing: 6, children: [
                  for (final option in p.variants)
                    ChoiceChip(
                      label: Text(
                          '${option.name}${option.price != null ? ' · ${formatLe(option.price)}' : ''}${option.stock < 1 ? ' (sold out)' : ''}'),
                      selected: _option?.id == option.id,
                      onSelected: option.stock < 1
                          ? null
                          : (_) => setState(() {
                                _option = option;
                                if (_qty > option.stock) _qty = option.stock;
                              }),
                    ),
                ]),
              ],
              if (widget.data.offers.isNotEmpty) ...[
                const SizedBox(height: 10),
                Wrap(spacing: 6, runSpacing: 4, children: [
                  for (final offer in widget.data.offers) Chip(label: Text('🏷 $offer'), backgroundColor: Colors.red.shade50),
                ]),
              ],
              if (widget.data.addons.isNotEmpty) ...[
                const SizedBox(height: 14),
                const Text('Extras (optional, for each one)', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                Wrap(spacing: 8, runSpacing: 6, children: [
                  for (final addon in widget.data.addons)
                    FilterChip(
                      label: Text('${addon['name']} +${formatLe(addon['price'] as String?)}'),
                      selected: _addons.contains((addon['id'] as num).toInt()),
                      onSelected: (on) => setState(() => on ? _addons.add((addon['id'] as num).toInt()) : _addons.remove((addon['id'] as num).toInt())),
                    ),
                ]),
              ],
              for (final bundle in widget.data.bundles)
                Card(
                  child: ListTile(
                    leading: const Text('🎁', style: TextStyle(fontSize: 24)),
                    title: Text('${bundle['title']}: ${formatLe(bundle['price'] as String?)} (instead of ${formatLe(bundle['normal_price'] as String?)})'),
                    subtitle: Text(((bundle['items'] as List?) ?? const []).join(' + ')),
                    trailing: TextButton(onPressed: () => _addBundle(bundle), child: const Text('Add all')),
                  ),
                ),
              const SizedBox(height: 16),
              Row(children: [
                const Text('Quantity',
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.w600)),
                const Spacer(),
                IconButton.filledTonal(
                  onPressed: _qty > p.minOrderQuantity
                      ? () => setState(() => _qty--)
                      : null,
                  icon: const Icon(Icons.remove),
                ),
                SizedBox(
                    width: 48,
                    child: Text('$_qty',
                        textAlign: TextAlign.center,
                        style: const TextStyle(fontSize: 20))),
                IconButton.filledTonal(
                    onPressed:
                        _qty < maxQty ? () => setState(() => _qty++) : null,
                    icon: const Icon(Icons.add)),
              ]),
              if (p.minOrderQuantity > 1)
                Text('Minimum order: ${p.minOrderQuantity}',
                    style: const TextStyle(color: WiBuyColors.muted)),
              Padding(
                padding: const EdgeInsets.only(top: 6),
                child: Text('Total: ${formatLe(_lineTotal)}',
                    style: const TextStyle(
                        fontSize: 16, fontWeight: FontWeight.w700)),
              ),
              const SizedBox(height: 12),
              Row(children: [
                TextButton.icon(
                    onPressed: _toggleSave,
                    icon: const Icon(Icons.favorite_border),
                    label: const Text('Save')),
                TextButton.icon(
                    onPressed: _share,
                    icon: const Icon(Icons.share),
                    label: const Text('Share on WhatsApp')),
              ]),
              Builder(builder: (context) {
                final session = SessionScope.of(context);
                final inCompare = session.compareList.contains(p.slug);
                return Row(children: [
                  TextButton.icon(
                    icon: const Icon(Icons.compare_arrows),
                    label: Text(inCompare ? 'In compare (remove)' : 'Compare'),
                    onPressed: () {
                      if (!session.toggleCompare(p.slug)) {
                        showMessage(context,
                            'You can compare up to ${Session.compareMax} products. Remove one first.');
                      }
                    },
                  ),
                  if (session.compareList.length > 1)
                    TextButton(
                      onPressed: () => Navigator.of(context).push(
                          MaterialPageRoute(
                              builder: (_) => const CompareScreen())),
                      child: Text(
                          'See ${session.compareList.length} side by side'),
                    ),
                ]);
              }),
              if (p.priceCutCampaignId != null)
                OutlinedButton.icon(
                  onPressed: () =>
                      startPriceCut(context, p.priceCutCampaignId!),
                  icon: const Text('✂️'),
                  label: const Text('Help me cut the price'),
                ),
              if (p.samplePrice != null)
                TextButton.icon(
                  onPressed: () async {
                    if (!await ensureLoggedIn(context) || !context.mounted)
                      return;
                    Navigator.of(context).push(MaterialPageRoute(
                        builder: (_) => CheckoutScreen(
                            productSlug: p.slug, quantity: 1, sample: true)));
                  },
                  icon: const Text('📦'),
                  label: Text(
                      'Order a sample (${formatLe(p.samplePrice)}), credited on your bulk order'),
                ),
              if (p.trialCampaignId != null)
                TextButton.icon(
                  onPressed: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => const TrialsScreen())),
                  icon: const Text('🧪'),
                  label: const Text('Free trial: apply to test it'),
                ),
              if (p.seller != null) ...[
                const Divider(height: 28),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: p.seller!.logoUrl == null
                      ? const CircleAvatar(child: Icon(Icons.storefront))
                      : Photo(p.seller!.logoUrl, size: 44, radius: 22),
                  title: Text(
                      '${p.seller!.shopName}${p.seller!.isVerified ? '  ✓ Verified' : ''}'),
                  subtitle: Text(
                      '${p.seller!.location} · reliability ${p.seller!.reliabilityScore == null ? 'new shop' : '${p.seller!.reliabilityScore}/100'}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(MaterialPageRoute(
                      builder: (_) => ShopScreen(slug: p.seller!.slug))),
                ),
                TextButton.icon(
                  onPressed: () => openChatWith(context,
                      userId: p.seller!.userId, title: p.seller!.shopName),
                  icon: const Icon(Icons.chat_bubble_outline),
                  label: const Text('Chat about this product'),
                ),
              ],
              if (p.seller?.holidayNotice != null)
                Card(
                  color: Colors.amber.shade50,
                  child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Text('🏖 ${p.seller!.holidayNotice}',
                          style: const TextStyle(fontWeight: FontWeight.w700))),
                ),
              if ((p.description ?? '').isNotEmpty) ...[
                const Divider(height: 28),
                const Text('Description',
                    style:
                        TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                const SizedBox(height: 6),
                Text(p.description!,
                    style: const TextStyle(fontSize: 15, height: 1.4)),
              ],
              const Divider(height: 28),
              Row(children: [
                const Expanded(
                    child: Text('Questions and answers',
                        style: TextStyle(
                            fontSize: 17, fontWeight: FontWeight.w700))),
                TextButton.icon(
                    onPressed: _ask,
                    icon: const Icon(Icons.help_outline),
                    label: const Text('Ask')),
              ]),
              if (widget.data.questions.isEmpty)
                const Text(
                    'No questions yet. Ask the shop anything about this product.',
                    style: TextStyle(color: WiBuyColors.muted)),
              for (final q in widget.data.questions)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('Q: ${q['question']}',
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text('A: ${q['answer']}'),
                ),
              if (widget.data.reviews.isNotEmpty) ...[
                const Divider(height: 28),
                const Text('Reviews',
                    style:
                        TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                if (widget.data.foodScores != null)
                  Text(
                      [
                        '😋 Taste ${widget.data.foodScores!['taste']}/5',
                        if (widget.data.foodScores!['portion'] != null) '🍽 Portion ${widget.data.foodScores!['portion']}/5',
                        if (widget.data.foodScores!['packaging'] != null) '📦 Packaging ${widget.data.foodScores!['packaging']}/5',
                      ].join('  ·  '),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                for (final review in widget.data.reviews)
                  ListTile(
                    contentPadding: EdgeInsets.zero,
                    title: Text(
                        '${'★' * review.rating}${'☆' * (5 - review.rating)}  ${review.buyer ?? ''}${review.isFreeTrial ? '  · Free trial' : ''}'),
                    subtitle: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      if (review.comment != null) Text(review.comment!),
                      if (review.taste != null)
                        Text('Taste ${review.taste}/5${review.portion != null ? ' · Portion ${review.portion}/5' : ''}${review.packaging != null ? ' · Packaging ${review.packaging}/5' : ''}',
                            style: const TextStyle(fontSize: 12)),
                      if (review.sellerReply != null)
                        Container(
                          margin: const EdgeInsets.only(top: 4),
                          padding: const EdgeInsets.all(8),
                          color: Colors.grey.shade100,
                          child: Text('Reply from the shop: ${review.sellerReply}'),
                        ),
                    ]),
                  ),
              ],
              if (widget.data.alsoBought.isNotEmpty) ...[
                const Divider(height: 28),
                const Text('People who bought this also bought',
                    style:
                        TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
              ],
            ]),
          ),
          if (widget.data.alsoBought.isNotEmpty) ...[
            ProductGrid(widget.data.alsoBought),
            const SizedBox(height: 16)
          ],
        ]),
      ),
      SafeArea(
        top: false,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
          child: Row(children: [
            Expanded(
              child: OutlinedButton(
                  onPressed: _busy || outOfStock ? null : _addToCart,
                  child: Text(tr(context, 'Add to cart'))),
            ),
            const SizedBox(width: 10),
            Expanded(
                child: FilledButton(
                    onPressed: _busy || outOfStock ? null : _buyNow,
                    child: Text(tr(context, 'Buy now')))),
          ]),
        ),
      ),
    ]);
  }

  /// Unit price × quantity, from the exact strings (cents as integers, no doubles).
  String get _lineTotal {
    final parts = _unitPrice.split('.');
    final cents = int.parse(parts[0]) * 100 +
        int.parse((parts.length > 1 ? parts[1] : '0')
            .padRight(2, '0')
            .substring(0, 2));
    final total = cents * _qty;

    return '${total ~/ 100}.${(total % 100).toString().padLeft(2, '0')}';
  }
}
