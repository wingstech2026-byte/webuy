// Typed views of the WiBuy API's JSON. Money stays a String ("650.00") end to end.

List<T> _list<T>(dynamic json, T Function(Map<String, dynamic>) parse) =>
    json is List ? json.whereType<Map<String, dynamic>>().map(parse).toList() : <T>[];

String _s(dynamic v) => v?.toString() ?? '';

class User {
  User({required this.id, required this.name, required this.phone, required this.displayPhone, required this.isSeller, required this.isAdmin, this.isAgent = false, this.isRider = false});

  /// WiBuy inspection agent.
  final bool isAgent;

  /// WiBuy delivery rider.
  final bool isRider;

  factory User.fromJson(Map<String, dynamic> j) => User(
        id: j['id'] as int,
        name: j['name'] as String?,
        phone: _s(j['phone']),
        displayPhone: _s(j['display_phone']),
        isSeller: j['is_seller'] == true,
        isAdmin: j['is_admin'] == true,
        isAgent: j['is_agent'] == true,
        isRider: j['is_rider'] == true,
      );

  final int id;
  final String? name;
  final String phone;
  final String displayPhone;
  final bool isSeller;
  final bool isAdmin;

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'phone': phone,
        'display_phone': displayPhone,
        'is_seller': isSeller,
        'is_admin': isAdmin,
        'is_agent': isAgent,
        'is_rider': isRider,
      };
}

class Category {
  Category({required this.name, required this.slug, this.emoji, this.children = const []});

  factory Category.fromJson(Map<String, dynamic> j) => Category(
        name: _s(j['name']),
        slug: _s(j['slug']),
        emoji: j['emoji'] as String?,
        children: _list(j['children'], Category.fromJson),
      );

  final String name;
  final String slug;
  final String? emoji;
  final List<Category> children;
}

class Seller {
  Seller({
    required this.slug,
    required this.shopName,
    required this.location,
    required this.isVerified,
    required this.ratingAvg,
    required this.ratingCount,
    this.description,
    this.pickupAddress,
    this.logoUrl,
    this.webUrl,
    this.reliabilityScore,
    this.userId,
  });

  factory Seller.fromJson(Map<String, dynamic> j) => Seller(
        slug: _s(j['slug']),
        shopName: _s(j['shop_name']),
        location: _s(j['location']),
        isVerified: j['is_verified'] == true,
        ratingAvg: (j['rating_avg'] as num?)?.toDouble() ?? 0,
        ratingCount: (j['rating_count'] as num?)?.toInt() ?? 0,
        description: j['description'] as String?,
        pickupAddress: j['pickup_address'] as String?,
        logoUrl: j['logo_url'] as String?,
        webUrl: j['web_url'] as String?,
        reliabilityScore: (j['reliability_score'] as num?)?.toInt(),
        userId: (j['user_id'] as num?)?.toInt(),
      );

  /// The seller's account, to start a chat.
  final int? userId;

  /// Delivery reliability 0-100 (last 90 days); null = new shop.
  final int? reliabilityScore;
  final String slug;
  final String shopName;
  final String location;
  final bool isVerified;
  final double ratingAvg;
  final int ratingCount;
  final String? description;
  final String? pickupAddress;
  final String? logoUrl;
  final String? webUrl;
}

class PriceTier {
  PriceTier(this.minQuantity, this.unitPrice);

  final int minQuantity;
  final String unitPrice;
}

class Product {
  Product({
    required this.slug,
    required this.title,
    required this.price,
    required this.unit,
    required this.stock,
    required this.minOrderQuantity,
    required this.ratingAvg,
    required this.ratingCount,
    required this.soldCount,
    required this.tiers,
    required this.lowestPrice,
    this.compareAtPrice,
    this.discountPercent = 0,
    this.thumbUrl,
    this.seller,
    this.description,
    this.imageUrls = const [],
    this.webUrl,
    this.memberPrice,
    this.flashDeal,
    this.priceCutCampaignId,
    this.trialCampaignId,
    this.leadTimeDays,
    this.samplePrice,
    this.hasBusinessPrices = false,
    this.riskWarnings = const [],
    this.variants = const [],
  });

  factory Product.fromJson(Map<String, dynamic> j) => Product(
        slug: _s(j['slug']),
        title: _s(j['title']),
        price: _s(j['price']),
        compareAtPrice: j['compare_at_price'] as String?,
        discountPercent: (j['discount_percent'] as num?)?.toInt() ?? 0,
        lowestPrice: _s(j['lowest_price']),
        unit: _s(j['unit']),
        stock: (j['stock'] as num?)?.toInt() ?? 0,
        minOrderQuantity: (j['min_order_quantity'] as num?)?.toInt() ?? 1,
        ratingAvg: (j['rating_avg'] as num?)?.toDouble() ?? 0,
        ratingCount: (j['rating_count'] as num?)?.toInt() ?? 0,
        soldCount: (j['sold_count'] as num?)?.toInt() ?? 0,
        thumbUrl: j['thumb_url'] as String?,
        tiers: j['tiers'] is List
            ? (j['tiers'] as List)
                .whereType<Map<String, dynamic>>()
                .map((t) => PriceTier((t['min_quantity'] as num).toInt(), _s(t['unit_price'])))
                .toList()
            : [],
        seller: j['seller'] is Map<String, dynamic> ? Seller.fromJson(j['seller'] as Map<String, dynamic>) : null,
        description: j['description'] as String?,
        imageUrls: j['images'] is List
            ? (j['images'] as List).whereType<Map<String, dynamic>>().map((i) => _s(i['url'])).toList()
            : const [],
        webUrl: j['web_url'] as String?,
        memberPrice: j['member_price'] as String?,
        flashDeal: j['flash_deal'] is Map<String, dynamic> ? ProductDeal.fromJson(j['flash_deal'] as Map<String, dynamic>) : null,
        priceCutCampaignId: (j['price_cut_campaign_id'] as num?)?.toInt(),
        trialCampaignId: (j['trial_campaign_id'] as num?)?.toInt(),
        leadTimeDays: (j['lead_time_days'] as num?)?.toInt(),
        samplePrice: j['sample_price'] as String?,
        hasBusinessPrices: j['has_business_prices'] == true,
        variants: j['variants'] is List ? (j['variants'] as List).whereType<Map<String, dynamic>>().map(ProductOption.fromJson).toList() : const [],
        riskWarnings: j['risk_warnings'] is List
            ? (j['risk_warnings'] as List).whereType<Map<String, dynamic>>().map((w) => _s(w['message'])).toList()
            : const [],
      );

  final String slug;
  final String title;
  final String price;
  final String? compareAtPrice;
  final int discountPercent;
  final String lowestPrice;
  final String unit;
  final int stock;
  final int minOrderQuantity;
  final double ratingAvg;
  final int ratingCount;
  final int soldCount;
  final String? thumbUrl;
  final List<PriceTier> tiers;
  final Seller? seller;
  final String? description;
  final List<String> imageUrls;
  final String? webUrl;

  /// WiBuy Pass member price, when the seller set one.
  final String? memberPrice;

  /// A lightning deal running now or later today.
  final ProductDeal? flashDeal;

  /// "Help me cut the price" is possible on this product.
  final int? priceCutCampaignId;

  /// A free trial people can apply for.
  final int? trialCampaignId;

  /// Made to order: "Ready in X days".
  final int? leadTimeDays;

  /// One unit before a bulk order.
  final String? samplePrice;

  /// Lower prices exist for verified business buyers.
  final bool hasBusinessPrices;

  /// Low price compared to similar products, new seller...
  final List<String> riskWarnings;

  /// Sizes, colours...: when not empty, the buyer must choose one.
  final List<ProductOption> variants;

  bool get hasTiers => tiers.isNotEmpty;
}

class ProductDeal {
  ProductDeal.fromJson(Map<String, dynamic> j)
      : id = (j['id'] as num).toInt(),
        state = _s(j['state']),
        dealPrice = _s(j['deal_price']),
        startsAt = DateTime.parse(j['starts_at'] as String).toLocal(),
        endsAt = DateTime.parse(j['ends_at'] as String).toLocal(),
        maxPerOrder = (j['max_per_order'] as num?)?.toInt() ?? 1;

  final int id;
  final String state;
  final String dealPrice;
  final DateTime startsAt;
  final DateTime endsAt;
  final int maxPerOrder;

  bool get isLive => state == 'live' && endsAt.isAfter(DateTime.now()) && startsAt.isBefore(DateTime.now());
}

class Review {
  Review({required this.rating, this.comment, this.buyer, this.isFreeTrial = false});

  factory Review.fromJson(Map<String, dynamic> j) => Review(
        rating: (j['rating'] as num).toInt(),
        comment: j['comment'] as String?,
        buyer: j['buyer'] as String?,
        isFreeTrial: j['is_free_trial'] == true,
      );

  /// Shown as "Free trial": the buyer got it free or almost free to test it.
  final bool isFreeTrial;
  final int rating;
  final String? comment;
  final String? buyer;
}

/// One option of a product ("Red, XL"), with its own stock and maybe its own price.
class ProductOption {
  ProductOption({required this.id, required this.name, required this.stock, this.price});

  factory ProductOption.fromJson(Map<String, dynamic> j) => ProductOption(
        id: (j['id'] as num).toInt(),
        name: _s(j['name']),
        stock: (j['stock'] as num?)?.toInt() ?? 0,
        price: j['price'] as String?,
      );

  final int id;
  final String name;
  final int stock;

  /// null: the product's price.
  final String? price;
}

class CartLine {
  CartLine({required this.id, required this.quantity, required this.unitPrice, required this.lineTotal, required this.isWholesale, required this.product, this.problem, this.variantName});

  factory CartLine.fromJson(Map<String, dynamic> j) => CartLine(
        id: j['id'] as int,
        quantity: (j['quantity'] as num).toInt(),
        unitPrice: _s(j['unit_price']),
        lineTotal: _s(j['line_total']),
        isWholesale: j['is_wholesale_price'] == true,
        problem: j['problem'] as String?,
        product: Product.fromJson(j['product'] as Map<String, dynamic>),
        variantName: (j['variant'] as Map<String, dynamic>?)?['name'] as String?,
      );

  final String? variantName;
  final int id;
  final int quantity;
  final String unitPrice;
  final String lineTotal;
  final bool isWholesale;
  final String? problem;
  final Product product;
}

class CartGroup {
  CartGroup({required this.seller, required this.items, required this.subtotal});

  factory CartGroup.fromJson(Map<String, dynamic> j) => CartGroup(
        seller: Seller.fromJson(j['seller'] as Map<String, dynamic>),
        items: _list(j['items'], CartLine.fromJson),
        subtotal: _s(j['subtotal']),
      );

  final Seller seller;
  final List<CartLine> items;
  final String subtotal;
}

class Cart {
  Cart({required this.groups, required this.total, required this.count, required this.hasProblems});

  factory Cart.fromJson(Map<String, dynamic> j) => Cart(
        groups: _list(j['groups'], CartGroup.fromJson),
        total: _s(j['total']),
        count: (j['count'] as num?)?.toInt() ?? 0,
        hasProblems: j['has_problems'] == true,
      );

  final List<CartGroup> groups;
  final String total;
  final int count;
  final bool hasProblems;

  bool get isEmpty => groups.isEmpty;
}

class QuoteItem {
  QuoteItem({required this.title, required this.quantity, required this.unitPrice, required this.lineTotal});

  final String title;
  final int quantity;
  final String unitPrice;
  final String lineTotal;
}

class QuoteGroup {
  QuoteGroup({
    required this.sellerId,
    required this.seller,
    required this.items,
    required this.subtotal,
    required this.method,
    required this.canDeliver,
    required this.canPickup,
    required this.districtFee,
    required this.deliveryFee,
    required this.total,
    this.discount = '0.00',
    this.freeDelivery = false,
    this.deliveryReason,
    this.distanceKm,
    this.deliveryWindow,
  });

  factory QuoteGroup.fromJson(Map<String, dynamic> j) {
    final seller = j['seller'] as Map<String, dynamic>;

    return QuoteGroup(
      sellerId: (seller['id'] as num).toInt(),
      seller: Seller.fromJson(seller),
      items: (j['items'] as List).whereType<Map<String, dynamic>>().map((i) {
        final product = i['product'] as Map<String, dynamic>;
        return QuoteItem(
          title: _s(product['title']),
          quantity: (i['quantity'] as num).toInt(),
          unitPrice: _s(i['unit_price']),
          lineTotal: _s(i['line_total']),
        );
      }).toList(),
      subtotal: _s(j['subtotal']),
      method: j['method'] as String?,
      canDeliver: j['can_deliver'] == true,
      canPickup: j['can_pickup'] == true,
      districtFee: j['district_fee'] as String?,
      deliveryFee: _s(j['delivery_fee']),
      total: _s(j['total']),
      discount: _s(j['discount'] ?? '0.00'),
      freeDelivery: j['free_delivery'] == true,
      deliveryReason: j['delivery_reason'] as String?,
      distanceKm: (j['distance_km'] as num?)?.toDouble(),
      deliveryWindow: j['delivery_window'] as String?,
    );
  }

  /// Why delivery is not possible (outside the area, below the minimum order...).
  final String? deliveryReason;

  /// Distance from the shop when the buyer's address has a map point.
  final double? distanceKm;

  /// The shop's usual delivery time: an estimate, not a promise.
  final String? deliveryWindow;

  final String discount;

  /// WiBuy Pass pays this shop's delivery fee.
  final bool freeDelivery;

  final int sellerId;
  final Seller seller;
  final List<QuoteItem> items;
  final String subtotal;
  final String? method;
  final bool canDeliver;
  final bool canPickup;
  final String? districtFee;
  final String deliveryFee;
  final String total;
}

class Quote {
  Quote({
    required this.groups,
    required this.itemsTotal,
    required this.deliveryTotal,
    required this.grandTotal,
    String? amountDue,
    this.couponDiscount = '0.00',
    this.couponError,
    this.passSavings = '0.00',
    this.isMember = false,
    this.couponJson = const [],
    this.canPayInParts = false,
    this.depositPercent = 30,
    this.depositAmount = '0.00',
  }) : amountDue = amountDue ?? grandTotal;

  factory Quote.fromJson(Map<String, dynamic> j) => Quote(
        groups: _list(j['groups'], QuoteGroup.fromJson),
        itemsTotal: _s(j['items_total']),
        deliveryTotal: _s(j['delivery_total']),
        grandTotal: _s(j['grand_total']),
        amountDue: j['amount_due'] as String?,
        couponDiscount: _s(j['coupon_discount'] ?? '0.00'),
        couponError: j['coupon_error'] as String?,
        passSavings: _s(j['pass_savings'] ?? '0.00'),
        isMember: j['is_member'] == true,
        couponJson: j['coupons'] is List ? (j['coupons'] as List).whereType<Map<String, dynamic>>().toList() : const [],
        canPayInParts: j['can_pay_in_parts'] == true,
        depositPercent: (j['deposit_percent'] as num?)?.toInt() ?? 30,
        depositAmount: _s(j['deposit_amount'] ?? '0.00'),
      );

  /// Big orders from one shop: deposit now, balance before delivery.
  final bool canPayInParts;
  final int depositPercent;
  final String depositAmount;

  /// What is paid by mobile money, after coupons and WiBuy Pass free delivery.
  final String amountDue;
  final String couponDiscount;
  final String? couponError;
  final String passSavings;
  final bool isMember;

  /// Coupons the buyer can choose (parsed by the checkout screen).
  final List<Map<String, dynamic>> couponJson;

  final List<QuoteGroup> groups;
  final String itemsTotal;
  final String deliveryTotal;
  final String grandTotal;
}

class TimelineStep {
  TimelineStep(this.label, this.at);

  final String label;
  final DateTime? at;
}

class OrderItem {
  OrderItem({required this.id, required this.title, required this.unit, required this.unitPrice, required this.quantity, required this.lineTotal, this.thumbUrl, this.reviewed, this.variantName});

  factory OrderItem.fromJson(Map<String, dynamic> j) => OrderItem(
        id: j['id'] as int,
        title: _s(j['title']),
        unit: _s(j['unit']),
        unitPrice: _s(j['unit_price']),
        quantity: (j['quantity'] as num).toInt(),
        lineTotal: _s(j['line_total']),
        thumbUrl: j['thumb_url'] as String?,
        reviewed: j['reviewed'] as bool?,
        variantName: j['variant_name'] as String?,
      );

  /// The option chosen (size, colour...), if any.
  final String? variantName;

  /// "Shirt (Red, XL)" or just the title.
  String get displayTitle => variantName == null ? title : '$title ($variantName)';

  final int id;
  final String title;
  final String unit;
  final String unitPrice;
  final int quantity;
  final String lineTotal;
  final String? thumbUrl;
  final bool? reviewed;
}

class Order {
  Order({
    required this.id,
    required this.number,
    required this.status,
    required this.statusLabel,
    required this.subtotal,
    required this.deliveryFee,
    required this.total,
    required this.deliveryMethod,
    required this.items,
    required this.timeline,
    required this.canConfirmDelivery,
    required this.canReportProblem,
    required this.canReview,
    required this.reportProblemUrl,
    this.seller,
    this.district,
    this.address,
    this.deliveryNote,
    this.rejectionReason,
    this.refundReason,
    this.createdAt,
    String? amountPaid,
    this.discount = '0.00',
    this.giftNote,
    this.milestonesJson = const [],
    this.inspectionJson,
    this.canPayBalance = false,
    this.canRequestInspection = false,
    this.canReturn = false,
    this.returnJson,
  }) : amountPaid = amountPaid ?? total;

  factory Order.fromJson(Map<String, dynamic> j) => Order(
        id: j['id'] as int,
        number: _s(j['number']),
        status: _s(j['status']),
        statusLabel: _s(j['status_label']),
        subtotal: _s(j['subtotal']),
        deliveryFee: _s(j['delivery_fee']),
        total: _s(j['total']),
        deliveryMethod: _s(j['delivery_method']),
        seller: j['seller'] is Map<String, dynamic> ? Seller.fromJson(j['seller'] as Map<String, dynamic>) : null,
        items: _list(j['items'], OrderItem.fromJson),
        timeline: j['timeline'] is List
            ? (j['timeline'] as List)
                .whereType<Map<String, dynamic>>()
                .map((t) => TimelineStep(_s(t['label']), t['at'] == null ? null : DateTime.tryParse(t['at'] as String)))
                .toList()
            : [],
        canConfirmDelivery: j['can_confirm_delivery'] == true,
        canReportProblem: j['can_report_problem'] == true,
        canReview: j['can_review'] == true,
        reportProblemUrl: _s(j['report_problem_url']),
        district: j['district'] as String?,
        address: j['address'] as String?,
        deliveryNote: j['delivery_note'] as String?,
        rejectionReason: j['rejection_reason'] as String?,
        refundReason: j['refund_reason'] as String?,
        createdAt: j['created_at'] == null ? null : DateTime.tryParse(j['created_at'] as String),
        amountPaid: j['amount_paid'] as String?,
        discount: _s(j['discount'] ?? '0.00'),
        giftNote: j['gift_note'] as String?,
        milestonesJson: j['milestones'] is List ? (j['milestones'] as List).whereType<Map<String, dynamic>>().toList() : const [],
        inspectionJson: j['inspection'] as Map<String, dynamic>?,
        canPayBalance: j['can_pay_balance'] == true,
        canRequestInspection: j['can_request_inspection'] == true,
        canReturn: j['can_return'] == true,
        returnJson: j['return'] as Map<String, dynamic>?,
      );

  /// Delivered and not completed yet: the buyer may send items back.
  final bool canReturn;

  /// The return on this order (status_label, items, refund_amount, return_instructions...), if any.
  final Map<String, dynamic>? returnJson;

  /// The total minus coupons and WiBuy Pass savings (paid by WiBuy).
  final String amountPaid;
  final String discount;

  /// A WiBuy Farm prize delivered with this order.
  final String? giftNote;

  /// Paid in parts (parsed with MilestoneInfo), and the pre-delivery inspection.
  final List<Map<String, dynamic>> milestonesJson;
  final Map<String, dynamic>? inspectionJson;
  final bool canPayBalance;
  final bool canRequestInspection;
  final int id;
  final String number;
  final String status;
  final String statusLabel;
  final String subtotal;
  final String deliveryFee;
  final String total;
  final String deliveryMethod;
  final Seller? seller;
  final List<OrderItem> items;
  final List<TimelineStep> timeline;
  final bool canConfirmDelivery;
  final bool canReportProblem;
  final bool canReview;
  final String reportProblemUrl;
  final String? district;
  final String? address;
  final String? deliveryNote;
  final String? rejectionReason;
  final String? refundReason;
  final DateTime? createdAt;

  bool get isPickup => deliveryMethod == 'pickup';
}

class CheckoutInfo {
  CheckoutInfo({
    required this.id,
    required this.payment,
    required this.total,
    required this.payerPhone,
    required this.operatorLabel,
    required this.canSimulate,
    required this.orders,
    this.failureReason,
  });

  factory CheckoutInfo.fromJson(Map<String, dynamic> j) => CheckoutInfo(
        id: j['id'] as int,
        payment: _s(j['payment']),
        total: _s(j['total']),
        payerPhone: _s(j['payer_phone']),
        operatorLabel: _s(j['operator_label']),
        canSimulate: j['can_simulate'] == true,
        failureReason: j['failure_reason'] as String?,
        orders: _list(j['orders'], Order.fromJson),
      );

  final int id;

  /// waiting | paid | failed | cancelled
  final String payment;
  final String total;
  final String payerPhone;
  final String operatorLabel;
  final bool canSimulate;
  final String? failureReason;
  final List<Order> orders;
}

class AppMeta {
  AppMeta({required this.districts, required this.operators, required this.webUrl, this.mapTiles = defaultMapTiles});

  static const defaultMapTiles = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';

  factory AppMeta.fromJson(Map<String, dynamic> j) => AppMeta(
        districts: (j['districts'] as List).map((d) => d.toString()).toList(),
        operators: (j['operators'] as List)
            .whereType<Map<String, dynamic>>()
            .map((o) => MapEntry(_s(o['value']), _s(o['label'])))
            .toList(),
        webUrl: _s(j['web_url']),
        mapTiles: (j['map_tiles'] as String?) ?? defaultMapTiles,
      );

  final List<String> districts;

  /// value (ORANGE) => label (Orange Money)
  final List<MapEntry<String, String>> operators;
  final String webUrl;

  /// Map image address with {z}/{x}/{y}, set on the server.
  final String mapTiles;
}

/// Pages of results from Laravel's paginator.
class Paged<T> {
  Paged(this.items, this.currentPage, this.lastPage, this.total);

  factory Paged.fromJson(Map<String, dynamic> j, T Function(Map<String, dynamic>) parse) {
    final meta = (j['meta'] as Map<String, dynamic>?) ?? const {};

    return Paged(
      _list(j['data'], parse),
      (meta['current_page'] as num?)?.toInt() ?? 1,
      (meta['last_page'] as num?)?.toInt() ?? 1,
      (meta['total'] as num?)?.toInt() ?? 0,
    );
  }

  final List<T> items;
  final int currentPage;
  final int lastPage;
  final int total;

  bool get hasMore => currentPage < lastPage;
}
