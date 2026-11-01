import 'package:flutter/material.dart';

import '../api/geo_models.dart';
import '../api/models.dart';
import '../session.dart';
import '../theme.dart';
import '../util/money.dart';
import '../widgets/common.dart';
import 'addresses_screen.dart';
import 'login_screen.dart';
import 'payment_screen.dart';

/// Open group offers: a lower price when enough people each buy and pay.
class GroupOffersScreen extends StatelessWidget {
  const GroupOffersScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Buy together')),
        body: Loader<List<GroupOffer>>(
          load: () async {
            final json = await SessionScope.read(context).api.get('/group-buys') as Map<String, dynamic>;
            return (json['data'] as List).whereType<Map<String, dynamic>>().map(GroupOffer.fromJson).toList();
          },
          builder: (context, offers, reload) => offers.isEmpty
              ? const EmptyState(icon: Icons.groups_outlined, title: 'No group offers right now. Check again soon.')
              : RefreshIndicator(
                  onRefresh: reload,
                  child: ListView(padding: const EdgeInsets.all(12), children: [
                    const Padding(
                      padding: EdgeInsets.only(bottom: 8),
                      child: Text('Each person pays for their own order. If the group does not fill up in time, everyone gets their money back.',
                          style: TextStyle(color: WiBuyColors.muted)),
                    ),
                    for (final offer in offers)
                      Card(
                        child: ListTile(
                          leading: Photo(offer.product.thumbUrl, size: 56),
                          title: Text(offer.product.title, maxLines: 2, overflow: TextOverflow.ellipsis, style: const TextStyle(fontWeight: FontWeight.w800)),
                          subtitle: Text('${formatLe(offer.groupPrice)} each with ${offer.minGroupSize} people (normal ${formatLe(offer.normalPrice)})'),
                          trailing: const Icon(Icons.chevron_right),
                          onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => GroupOfferScreen(offerId: offer.id))),
                        ),
                      ),
                  ]),
                ),
        ),
      );
}

/// One offer: join a group that is nearly full, or start your own and share it.
class GroupOfferScreen extends StatelessWidget {
  const GroupOfferScreen({super.key, required this.offerId});

  final int offerId;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Buy together')),
        body: Loader<(GroupOffer, List<GroupInfo>)>(
          load: () async {
            final json = await SessionScope.read(context).api.get('/group-buys/$offerId') as Map<String, dynamic>;
            return (
              GroupOffer.fromJson(json['data'] as Map<String, dynamic>),
              (json['open_groups'] as List).whereType<Map<String, dynamic>>().map(GroupInfo.fromJson).toList(),
            );
          },
          builder: (context, data, reload) {
            final (offer, groups) = data;
            return ListView(padding: const EdgeInsets.all(14), children: [
              _OfferHeader(offer),
              const SizedBox(height: 14),
              FilledButton.icon(
                icon: const Icon(Icons.group_add),
                label: Text('Start a group: ${formatLe(offer.groupPrice)}'),
                onPressed: () => joinGroup(context, offer, null),
              ),
              if (groups.isNotEmpty) ...[
                const Padding(
                  padding: EdgeInsets.fromLTRB(2, 20, 2, 8),
                  child: Text('Or join a group that is filling up', style: TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                ),
                for (final g in groups)
                  Card(
                    child: ListTile(
                      title: Text('${g.members.isEmpty ? 'New group' : '${g.members.first}\'s group'}: ${g.needed} more needed'),
                      subtitle: Countdown(g.expiresAt, prefix: 'Ends in '),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => GroupScreen(token: g.token))),
                    ),
                  ),
              ],
            ]);
          },
        ),
      );
}

class _OfferHeader extends StatelessWidget {
  const _OfferHeader(this.offer);

  final GroupOffer offer;

  @override
  Widget build(BuildContext context) => Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Photo(offer.product.thumbUrl, size: 84),
          const SizedBox(width: 12),
          Expanded(child: Text(offer.product.title, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800))),
        ]),
        const SizedBox(height: 10),
        Text.rich(TextSpan(children: [
          TextSpan(text: formatLe(offer.groupPrice), style: const TextStyle(fontSize: 24, fontWeight: FontWeight.w900, color: WiBuyColors.green)),
          TextSpan(text: '  each  ·  normal price ${formatLe(offer.normalPrice)}', style: const TextStyle(color: WiBuyColors.muted)),
        ])),
        const SizedBox(height: 6),
        Text('Needs ${offer.minGroupSize} people who pay within ${offer.hours} hours. Up to ${offer.maxQuantityEach} each.'),
        if (offer.eligibleDistricts != null) Text('Delivery only to: ${offer.eligibleDistricts!.join(', ')}', style: const TextStyle(color: WiBuyColors.muted)),
        if (offer.collectionPoint != null)
          Text('Or collect at ${offer.collectionPoint!.name}${offer.collectionPoint!.address != null ? ', ${offer.collectionPoint!.address}' : ''}',
              style: const TextStyle(color: WiBuyColors.muted)),
        const SizedBox(height: 6),
        const Text('Only people who have paid count. If the group does not fill up in time, you get your money back automatically.',
            style: TextStyle(fontSize: 13, color: WiBuyColors.muted)),
      ]);
}

/// A group's progress (also opened from a shared link /groups/{token}).
class GroupScreen extends StatelessWidget {
  const GroupScreen({super.key, required this.token});

  final String token;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Group')),
        body: Loader<(GroupInfo, GroupOffer)>(
          load: () async {
            final json = await SessionScope.read(context).api.get('/groups/$token') as Map<String, dynamic>;
            return (GroupInfo.fromJson(json['data'] as Map<String, dynamic>), GroupOffer.fromJson(json['campaign'] as Map<String, dynamic>));
          },
          builder: (context, data, reload) {
            final (group, offer) = data;
            final inGroup = group.myStatus == 'pending' || group.myStatus == 'paid';
            return RefreshIndicator(
              onRefresh: reload,
              child: ListView(padding: const EdgeInsets.all(14), children: [
                _OfferHeader(offer),
                const SizedBox(height: 18),
                ProgressBar(group.percent),
                const SizedBox(height: 6),
                Text(switch (group.status) {
                  'succeeded' => 'Complete! ${group.paidCount} people paid. The seller is preparing the orders. 🎉',
                  'failed' => 'This group closed without enough people. Everyone who paid is refunded.',
                  _ => '${group.paidCount} of ${group.minGroupSize} paid · ${group.needed} more needed',
                }, style: const TextStyle(fontWeight: FontWeight.w700)),
                if (group.isOpen) Countdown(group.expiresAt, prefix: 'Ends in ', style: const TextStyle(color: WiBuyColors.orange)),
                if (group.members.isNotEmpty) Text('In the group: ${group.members.join(', ')}', style: const TextStyle(color: WiBuyColors.muted)),
                if (group.myStatus != null) Text('You: ${_myStatus(group.myStatus!)}', style: const TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 16),
                if (group.isOpen && !inGroup)
                  FilledButton(onPressed: () => joinGroup(context, offer, group.token), child: Text('Join and pay ${formatLe(offer.groupPrice)}')),
                if (group.isOpen)
                  OutlinedButton.icon(
                    icon: const Icon(Icons.share),
                    label: const Text('Invite friends on WhatsApp'),
                    onPressed: () => openLink(context, 'https://wa.me/?text=${Uri.encodeComponent('Join my WiBuy group: ${offer.product.title} for ${formatLe(offer.groupPrice)} each. ${group.shareUrl}')}'),
                  ),
                if (!group.isOpen)
                  OutlinedButton(
                    onPressed: () => Navigator.of(context).pushReplacement(MaterialPageRoute(builder: (_) => GroupOfferScreen(offerId: offer.id))),
                    child: const Text('Start a new group'),
                  ),
              ]),
            );
          },
        ),
      );

  static String _myStatus(String status) => switch (status) {
        'paid' => 'paid ✓',
        'pending' => 'waiting for your payment',
        'refunded' => 'refunded',
        _ => 'not in the group',
      };
}

/// "My groups": every group the buyer started or joined.
class MyGroupsScreen extends StatelessWidget {
  const MyGroupsScreen({super.key});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('My groups')),
        body: Loader<List<GroupInfo>>(
          load: () async {
            final json = await SessionScope.read(context).api.get('/my-groups') as Map<String, dynamic>;
            return (json['data'] as List).whereType<Map<String, dynamic>>().map(GroupInfo.fromJson).toList();
          },
          builder: (context, groups, reload) => groups.isEmpty
              ? EmptyState(
                  icon: Icons.groups_outlined,
                  title: 'You are not in any group yet.',
                  action: FilledButton(
                    onPressed: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => const GroupOffersScreen())),
                    child: const Text('See group offers'),
                  ),
                )
              : ListView(padding: const EdgeInsets.all(12), children: [
                  for (final g in groups)
                    Card(
                      child: ListTile(
                        title: Text('${g.quantity ?? 1} × ${g.productTitle ?? ''}', style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text('${g.paidCount}/${g.minGroupSize} paid · ${g.status}'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => Navigator.of(context).push(MaterialPageRoute(builder: (_) => GroupScreen(token: g.token))),
                      ),
                    ),
                ]),
        ),
      );
}

/// Start ([token] null) or join a group: quantity, delivery or collection, and mobile money.
Future<void> joinGroup(BuildContext context, GroupOffer offer, String? token) async {
  final session = SessionScope.read(context);
  if (!session.isLoggedIn) {
    await Navigator.of(context).push(MaterialPageRoute(builder: (_) => const LoginScreen()));
    if (!context.mounted || !session.isLoggedIn) return;
  }
  await showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (_) => Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: _JoinSheet(offer: offer, token: token, outer: context),
    ),
  );
}

class _JoinSheet extends StatefulWidget {
  const _JoinSheet({required this.offer, required this.token, required this.outer});

  final GroupOffer offer;
  final String? token;
  final BuildContext outer;

  @override
  State<_JoinSheet> createState() => _JoinSheetState();
}

class _JoinSheetState extends State<_JoinSheet> {
  int _quantity = 1;
  int? _variantId;
  late String _method = widget.offer.collectionPoint != null ? 'pickup' : 'delivery';
  String _operator = 'ORANGE';
  late final _payerPhone = TextEditingController(text: displayPhone(SessionScope.read(context).user?.phone));
  List<SavedAddress>? _addresses;
  int? _addressId;
  bool _sending = false;

  @override
  void initState() {
    super.initState();
    loadAddresses(context).then((list) {
      if (!mounted) return;
      setState(() {
        _addresses = list;
        _addressId = list.isEmpty ? null : list.first.id;
      });
    }, onError: (_) {
      if (mounted) setState(() => _addresses = const []);
    });
  }

  @override
  void dispose() {
    _payerPhone.dispose();
    super.dispose();
  }

  Future<void> _send() async {
    if (widget.offer.product.variants.isNotEmpty && _variantId == null) {
      showMessage(context, 'Choose an option first.');
      return;
    }
    if (_method == 'delivery' && _addressId == null) {
      showMessage(context, 'Add a delivery address first.');
      return;
    }
    setState(() => _sending = true);
    // The screen under this sheet, taken before waiting (the sheet closes after).
    final nav = Navigator.of(widget.outer);
    try {
      final path = widget.token == null ? '/group-buys/${widget.offer.id}/start' : '/groups/${widget.token}/join';
      final json = await SessionScope.read(context).api.post(path, {
        'quantity': _quantity,
        if (_variantId != null) 'variant_id': _variantId,
        'method': _method,
        if (_method == 'delivery') 'address_id': _addressId,
        'operator': _operator,
        'payer_phone': _payerPhone.text.trim(),
      }) as Map<String, dynamic>;
      final checkout = CheckoutInfo.fromJson(json['checkout'] as Map<String, dynamic>);
      final group = GroupInfo.fromJson(json['group'] as Map<String, dynamic>);
      if (!mounted) return;
      Navigator.of(context).pop();
      nav.push(MaterialPageRoute(builder: (_) => GroupScreen(token: group.token)));
      nav.push(MaterialPageRoute(builder: (_) => PaymentScreen(initial: checkout)));
    } on Object catch (e) {
      if (mounted) showMessage(context, e);
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final offer = widget.offer;
    final operators = SessionScope.of(context).meta?.operators ?? const [MapEntry('ORANGE', 'Orange Money'), MapEntry('AFRIMONEY', 'Afrimoney')];

    return SafeArea(
      child: ListView(shrinkWrap: true, padding: const EdgeInsets.all(16), children: [
        Text(widget.token == null ? 'Start a group' : 'Join the group', style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900)),
        const SizedBox(height: 10),
        // Products with sizes or colours: each member picks their own, all at the group price.
        if (offer.product.variants.isNotEmpty) ...[
          const Text('Choose an option', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          Wrap(spacing: 8, runSpacing: 4, children: [
            for (final v in offer.product.variants)
              ChoiceChip(
                label: Text(v.stock > 0 ? v.name : '${v.name} (sold out)'),
                selected: _variantId == v.id,
                onSelected: v.stock > 0 ? (_) => setState(() => _variantId = v.id) : null,
              ),
          ]),
          const SizedBox(height: 6),
        ],
        Row(children: [
          const Text('How many?'),
          const Spacer(),
          IconButton(onPressed: _quantity > 1 ? () => setState(() => _quantity--) : null, icon: const Icon(Icons.remove_circle_outline)),
          Text('$_quantity', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800)),
          IconButton(onPressed: _quantity < offer.maxQuantityEach ? () => setState(() => _quantity++) : null, icon: const Icon(Icons.add_circle_outline)),
        ]),
        Wrap(spacing: 8, children: [
          ChoiceChip(label: const Text('Deliver to me'), selected: _method == 'delivery', onSelected: (_) => setState(() => _method = 'delivery')),
          ChoiceChip(
            label: Text(offer.collectionPoint != null ? 'Collect at ${offer.collectionPoint!.name}' : 'Pick up at the shop'),
            selected: _method == 'pickup',
            onSelected: (_) => setState(() => _method = 'pickup'),
          ),
        ]),
        if (_method == 'delivery') ...[
          const SizedBox(height: 8),
          if (_addresses == null)
            const LinearProgressIndicator()
          else if (_addresses!.isEmpty)
            OutlinedButton.icon(
              icon: const Icon(Icons.add_location_alt),
              label: const Text('Add a delivery address'),
              onPressed: () async {
                final added = await Navigator.of(context).push<bool>(MaterialPageRoute(builder: (_) => const AddressFormScreen()));
                if (added == true && mounted) {
                  final list = await loadAddresses(this.context);
                  setState(() {
                    _addresses = list;
                    _addressId = list.isEmpty ? null : list.first.id;
                  });
                }
              },
            )
          else
            DropdownButtonFormField<int>(
              initialValue: _addressId,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'Deliver to'),
              items: [for (final a in _addresses!) DropdownMenuItem(value: a.id, child: Text('${a.label}: ${a.oneLine}', overflow: TextOverflow.ellipsis))],
              onChanged: (v) => setState(() => _addressId = v),
            ),
        ],
        const SizedBox(height: 12),
        SegmentedButton<String>(
          segments: [for (final o in operators) ButtonSegment(value: o.key, label: Text(o.value))],
          selected: {_operator},
          onSelectionChanged: (s) => setState(() => _operator = s.first),
        ),
        const SizedBox(height: 10),
        TextField(controller: _payerPhone, keyboardType: TextInputType.phone, decoration: const InputDecoration(labelText: 'Mobile money number')),
        const SizedBox(height: 14),
        FilledButton(
          onPressed: _sending ? null : _send,
          child: Text(_sending ? 'Sending payment request…' : 'Pay $_quantity × ${formatLe(offer.groupPrice)}${_method == 'delivery' ? ' + delivery' : ''}'),
        ),
        const SizedBox(height: 6),
        const Text('Your place counts once you approve the payment. If the group does not fill up in time, the money comes back to this number.',
            textAlign: TextAlign.center, style: TextStyle(fontSize: 13, color: WiBuyColors.muted)),
      ]),
    );
  }
}
