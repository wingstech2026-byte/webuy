// Rewards: coupons, lightning deals, price cuts, WiBuy Farm, free trials and WiBuy Pass.
// Rewards are always coupons or WiBuy Points, never cash.

import 'models.dart';

String _s(dynamic v) => v?.toString() ?? '';

int _i(dynamic v) => (v as num?)?.toInt() ?? 0;

DateTime? _d(dynamic v) => v == null ? null : DateTime.tryParse(v as String)?.toLocal();

List<Map<String, dynamic>> _maps(dynamic v) => v is List ? v.whereType<Map<String, dynamic>>().toList() : const [];

class Coupon {
  Coupon.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        title = _s(j['title']),
        description = _s(j['description']),
        source = _s(j['source']),
        status = _s(j['status']),
        unlockHint = j['unlock_hint'] as String?,
        appOnly = j['app_only'] == true,
        productSlug = j['product_slug'] as String?,
        expiresAt = _d(j['expires_at']);

  final int id;
  final String title;
  final String description;
  final String source;

  /// locked | available | reserved | used | expired
  final String status;
  final String? unlockHint;
  final bool appOnly;
  final String? productSlug;
  final DateTime? expiresAt;

  bool get isLocked => status == 'locked';
}

class FlashDealInfo {
  FlashDealInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        state = _s(j['state']),
        dealPrice = _s(j['deal_price']),
        startsAt = _d(j['starts_at'])!,
        endsAt = _d(j['ends_at'])!,
        maxPerOrder = _i(j['max_per_order']),
        left = j['left'] == null ? null : _i(j['left']),
        reminded = j['reminded'] == true,
        product = j['product'] is Map<String, dynamic> ? Product.fromJson(j['product'] as Map<String, dynamic>) : null;

  final int id;

  /// upcoming | live | ended
  final String state;
  final String dealPrice;
  final DateTime startsAt;
  final DateTime endsAt;
  final int maxPerOrder;
  final int? left;
  final bool reminded;
  final Product? product;

  bool get isLive => state == 'live' && endsAt.isAfter(DateTime.now());
}

class PriceCutHelperInfo {
  PriceCutHelperInfo(this.name, this.amount);

  final String name;
  final String amount;
}

class PriceCutInfo {
  PriceCutInfo.fromJson(Map<String, dynamic> j)
      : token = _s(j['token']),
        status = _s(j['status']),
        startPrice = _s(j['start_price']),
        currentPrice = _s(j['current_price']),
        floorPrice = _s(j['floor_price']),
        progressPercent = _i(j['progress_percent']),
        expiresAt = _d(j['expires_at'])!,
        isMine = j['is_mine'] == true,
        owner = _s(j['owner']),
        helpers = _maps(j['helpers']).map((h) => PriceCutHelperInfo(_s(h['name']), _s(h['amount']))).toList(),
        couponId = j['coupon_id'] == null ? null : _i(j['coupon_id']),
        product = Product.fromJson(j['product'] as Map<String, dynamic>);

  final String token;

  /// active | reached | expired | used
  final String status;
  final String startPrice;
  final String currentPrice;
  final String floorPrice;
  final int progressPercent;
  final DateTime expiresAt;
  final bool isMine;
  final String owner;
  final List<PriceCutHelperInfo> helpers;
  final int? couponId;
  final Product product;

  bool get isActive => status == 'active' && expiresAt.isAfter(DateTime.now());
}

class PriceCutCampaignInfo {
  PriceCutCampaignInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        floorPrice = _s(j['floor_price']),
        hours = _i(j['hours']),
        saving = _s(j['saving']),
        product = Product.fromJson(j['product'] as Map<String, dynamic>);

  final int id;
  final String floorPrice;
  final int hours;
  final String saving;
  final Product product;
}

class FarmMissionInfo {
  FarmMissionInfo.fromJson(Map<String, dynamic> j)
      : key = _s(j['key']),
        label = _s(j['label']),
        water = _i(j['water']),
        dailyLimit = _i(j['daily_limit']),
        doneToday = _i(j['done_today']);

  final String key;
  final String label;
  final int water;
  final int dailyLimit;
  final int doneToday;

  bool get isDone => doneToday >= dailyLimit;
}

class FarmInfo {
  FarmInfo.fromJson(Map<String, dynamic> j)
      : code = _s(j['code']),
        plant = _s(j['plant']),
        plantLabel = _s(j['plant_label']),
        water = _i(j['water']),
        target = _i(j['target']),
        progressPercent = _i(j['progress_percent']),
        stage = _s(j['stage']),
        harvests = _i(j['harvests']),
        owner = _s(j['owner']),
        rewardLabel = (j['reward'] as Map<String, dynamic>?)?['label'] as String?;

  final String code;
  final String plant;
  final String plantLabel;
  final int water;
  final int target;
  final int progressPercent;
  final String stage;
  final int harvests;
  final String owner;
  final String? rewardLabel;

  String get emoji => plant == 'mango' ? '🥭' : '🌾';
}

class TrialInfo {
  TrialInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        trialPrice = _s(j['trial_price']),
        slots = _i(j['slots']),
        appliedCount = _i(j['applied_count']),
        applyUntil = _d(j['apply_until'])!,
        reviewDays = _i(j['review_days']),
        isOpen = j['is_open'] == true,
        myStatus = j['my_status'] as String?,
        reviewUrl = _s(j['review_url']),
        product = Product.fromJson(j['product'] as Map<String, dynamic>);

  final int id;
  final String trialPrice;
  final int slots;
  final int appliedCount;
  final DateTime applyUntil;
  final int reviewDays;
  final bool isOpen;

  /// null | applied | selected | rejected | reviewed | overdue
  final String? myStatus;
  final String reviewUrl;
  final Product product;
}

class MembershipInfo {
  MembershipInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        status = _s(j['status']),
        price = _s(j['price']),
        payerPhone = _s(j['payer_phone']),
        operatorLabel = _s(j['operator_label']),
        endsAt = _d(j['ends_at']),
        canSimulate = j['can_simulate'] == true;

  final int id;

  /// pending | active | failed
  final String status;
  final String price;
  final String payerPhone;
  final String operatorLabel;
  final DateTime? endsAt;
  final bool canSimulate;
}

class SpinAllowance {
  SpinAllowance.fromJson(Map<String, dynamic> j)
      : allowed = _i(j['allowed']),
        used = _i(j['used']),
        left = _i(j['left']),
        fromShares = _i(j['from_shares']),
        fromPass = _i(j['from_pass']);

  final int allowed;
  final int used;
  final int left;
  final int fromShares;
  final int fromPass;
}

class RewardsHub {
  RewardsHub.fromJson(Map<String, dynamic> j)
      : points = _i(j['points']),
        pointsPerCoupon = _i(j['points_per_coupon']),
        pointsCouponAmount = _s(j['points_coupon_amount']),
        checkedInToday = (j['check_in'] as Map<String, dynamic>)['done_today'] == true,
        streak = _i((j['check_in'] as Map<String, dynamic>)['streak']),
        spins = SpinAllowance.fromJson(j['spins'] as Map<String, dynamic>),
        farm = j['farm'] is Map<String, dynamic> ? FarmInfo.fromJson(j['farm'] as Map<String, dynamic>) : null,
        missions = _maps(j['farm_missions']).map(FarmMissionInfo.fromJson).toList(),
        priceCuts = _maps(j['price_cuts']).map(PriceCutInfo.fromJson).toList(),
        coupons = _maps(j['coupons']).map(Coupon.fromJson).toList(),
        lockedCoupons = _maps(j['locked_coupons']).map(Coupon.fromJson).toList(),
        pass = j['pass'] is Map<String, dynamic> ? MembershipInfo.fromJson(j['pass'] as Map<String, dynamic>) : null,
        referralCode = _s(j['referral_code']);

  final int points;
  final int pointsPerCoupon;
  final String pointsCouponAmount;
  final bool checkedInToday;
  final int streak;
  final SpinAllowance spins;
  final FarmInfo? farm;
  final List<FarmMissionInfo> missions;
  final List<PriceCutInfo> priceCuts;
  final List<Coupon> coupons;
  final List<Coupon> lockedCoupons;
  final MembershipInfo? pass;
  final String referralCode;
}
