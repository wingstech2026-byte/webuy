import 'models.dart';

String _s(dynamic v) => v?.toString() ?? '';

double? _d(dynamic v) => v == null ? null : (v as num).toDouble();

/// A delivery address the buyer saved. The map point is optional: a landmark is enough.
class SavedAddress {
  SavedAddress({
    required this.id,
    required this.label,
    required this.recipientName,
    required this.recipientPhone,
    required this.district,
    required this.oneLine,
    required this.isDefault,
    this.town,
    this.neighbourhood,
    this.street,
    this.landmark,
    this.directions,
    this.latitude,
    this.longitude,
  });

  factory SavedAddress.fromJson(Map<String, dynamic> j) => SavedAddress(
        id: (j['id'] as num).toInt(),
        label: _s(j['label']),
        recipientName: _s(j['recipient_name']),
        recipientPhone: _s(j['recipient_phone']),
        district: _s(j['district']),
        town: j['town'] as String?,
        neighbourhood: j['neighbourhood'] as String?,
        street: j['street'] as String?,
        landmark: j['landmark'] as String?,
        directions: j['directions'] as String?,
        latitude: _d(j['latitude']),
        longitude: _d(j['longitude']),
        oneLine: _s(j['one_line']),
        isDefault: j['is_default'] == true,
      );

  final int id;
  final String label;
  final String recipientName;
  final String recipientPhone;
  final String district;
  final String? town;
  final String? neighbourhood;
  final String? street;
  final String? landmark;
  final String? directions;
  final double? latitude;
  final double? longitude;
  final String oneLine;
  final bool isDefault;

  bool get hasPin => latitude != null && longitude != null;
}

/// A shop place buyers may see. For a home business there is no address and the point is rounded.
class ShopPlace {
  ShopPlace({required this.name, required this.district, required this.pickup, required this.verified, this.address, this.pickupInstructions, this.distanceKm});

  factory ShopPlace.fromJson(Map<String, dynamic> j) => ShopPlace(
        name: _s(j['name']),
        address: j['address'] as String?,
        district: _s(j['district']),
        pickup: j['pickup'] == true,
        verified: j['verified'] == true,
        pickupInstructions: j['pickup_instructions'] as String?,
        distanceKm: _d(j['distance_km']),
      );

  final String name;
  final String? address;
  final String district;
  final bool pickup;
  final bool verified;
  final String? pickupInstructions;
  final double? distanceKm;
}

/// A seller's group price: lower when enough people each buy and pay.
class GroupOffer {
  GroupOffer({
    required this.id,
    required this.product,
    required this.groupPrice,
    required this.normalPrice,
    required this.savingPerUnit,
    required this.minGroupSize,
    required this.maxQuantityEach,
    required this.hours,
    this.eligibleDistricts,
    this.collectionPoint,
  });

  factory GroupOffer.fromJson(Map<String, dynamic> j) => GroupOffer(
        id: (j['id'] as num).toInt(),
        product: Product.fromJson(j['product'] as Map<String, dynamic>),
        groupPrice: _s(j['group_price']),
        normalPrice: _s(j['normal_price']),
        savingPerUnit: _s(j['saving_per_unit']),
        minGroupSize: (j['min_group_size'] as num).toInt(),
        maxQuantityEach: (j['max_quantity_each'] as num).toInt(),
        hours: (j['hours'] as num).toInt(),
        eligibleDistricts: (j['eligible_districts'] as List?)?.map((d) => d.toString()).toList(),
        collectionPoint: j['collection_point'] is Map<String, dynamic> ? ShopPlace.fromJson(j['collection_point'] as Map<String, dynamic>) : null,
      );

  final int id;
  final Product product;
  final String groupPrice;
  final String normalPrice;
  final String savingPerUnit;
  final int minGroupSize;
  final int maxQuantityEach;
  final int hours;

  /// null means every district the shop delivers to.
  final List<String>? eligibleDistricts;
  final ShopPlace? collectionPoint;
}

/// One group's progress. Only paid members count; members are shown by first name only.
class GroupInfo {
  GroupInfo({
    required this.token,
    required this.status,
    required this.paidCount,
    required this.minGroupSize,
    required this.needed,
    required this.expiresAt,
    required this.members,
    required this.isLeader,
    required this.groupPrice,
    required this.normalPrice,
    required this.shareUrl,
    this.myStatus,
    this.productTitle,
    this.quantity,
  });

  factory GroupInfo.fromJson(Map<String, dynamic> j) => GroupInfo(
        token: _s(j['token']),
        status: _s(j['status']),
        paidCount: (j['paid_count'] as num).toInt(),
        minGroupSize: (j['min_group_size'] as num).toInt(),
        needed: (j['needed'] as num).toInt(),
        expiresAt: DateTime.parse(_s(j['expires_at'])).toLocal(),
        members: (j['members'] as List? ?? const []).map((m) => m.toString()).toList(),
        myStatus: j['my_status'] as String?,
        isLeader: j['is_leader'] == true,
        groupPrice: _s(j['group_price']),
        normalPrice: _s(j['normal_price']),
        shareUrl: _s(j['share_url']),
        productTitle: j['product'] as String?,
        quantity: (j['quantity'] as num?)?.toInt(),
      );

  final String token;

  /// open | succeeded | failed | expiring
  final String status;
  final int paidCount;
  final int minGroupSize;
  final int needed;
  final DateTime expiresAt;
  final List<String> members;

  /// pending | paid | cancelled | refunded, or null when not in the group
  final String? myStatus;
  final bool isLeader;
  final String groupPrice;
  final String normalPrice;
  final String shareUrl;
  final String? productTitle;
  final int? quantity;

  bool get isOpen => status == 'open';

  int get percent => minGroupSize == 0 ? 0 : (paidCount * 100 ~/ minGroupSize).clamp(0, 100);
}
