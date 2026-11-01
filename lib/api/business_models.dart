// Business buying: requests for quotes, quotes, part payments, inspections, WiBuy Global and the AI assistant.

import 'models.dart';

String _s(dynamic v) => v?.toString() ?? '';

int _i(dynamic v) => (v as num?)?.toInt() ?? 0;

List<Map<String, dynamic>> _maps(dynamic v) => v is List ? v.whereType<Map<String, dynamic>>().toList() : const [];

class RfqQuoteInfo {
  RfqQuoteInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        seller = Seller.fromJson(j['seller'] as Map<String, dynamic>),
        productTitle = _s((j['product'] as Map<String, dynamic>?)?['title']),
        unitPrice = _s(j['unit_price']),
        quantity = _i(j['quantity']),
        itemsTotal = _s(j['items_total']),
        deliveryFee = j['delivery_fee'] as String?,
        total = _s(j['total']),
        canDeliver = j['can_deliver'] == true,
        leadTimeDays = _i(j['lead_time_days']),
        note = j['note'] as String?,
        validUntil = DateTime.tryParse(_s(j['valid_until']))?.toLocal(),
        status = _s(j['status']),
        isValid = j['is_valid'] == true,
        reliability = (j['seller'] as Map<String, dynamic>)['reliability_score'] as int?;

  final int id;
  final Seller seller;
  final String productTitle;
  final String unitPrice;
  final int quantity;
  final String itemsTotal;
  final String? deliveryFee;
  final String total;
  final bool canDeliver;
  final int leadTimeDays;
  final String? note;
  final DateTime? validUntil;
  final String status;
  final bool isValid;
  final int? reliability;
}

class RfqInfo {
  RfqInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        title = _s(j['title']),
        description = j['description'] as String?,
        quantity = _i(j['quantity']),
        unit = _s(j['unit']),
        district = _s(j['district']),
        targetPrice = j['target_price'] as String?,
        neededBy = j['needed_by'] as String?,
        status = _s(j['status']),
        quotesCount = _i(j['quotes_count']),
        quotes = j['quotes'] is List ? _maps(j['quotes']).map(RfqQuoteInfo.fromJson).toList() : null;

  final int id;
  final String title;
  final String? description;
  final int quantity;
  final String unit;
  final String district;
  final String? targetPrice;
  final String? neededBy;

  /// open | awarded | closed
  final String status;
  final int quotesCount;
  final List<RfqQuoteInfo>? quotes;
}

class MilestoneInfo {
  MilestoneInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        label = _s(j['label']),
        percent = _i(j['percent']),
        amount = _s(j['amount']),
        status = _s(j['status']);

  final int id;
  final String label;
  final int percent;
  final String amount;
  final String status;

  bool get isPaid => status == 'paid';
}

class InspectionInfo {
  InspectionInfo.fromJson(Map<String, dynamic> j)
      : status = _s(j['status']),
        fee = _s(j['fee']),
        report = j['report'] as String?,
        photos = (j['photos'] as List? ?? const []).map((p) => p.toString()).toList();

  /// pending_payment | waiting | assigned | passed | failed
  final String status;
  final String fee;
  final String? report;
  final List<String> photos;
}

class ImportStep {
  ImportStep(this.label, this.at);

  final String label;
  final String? at;
}

class ImportProductInfo {
  ImportProductInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        title = _s(j['title']),
        description = j['description'] as String?,
        origin = _s(j['origin']),
        unit = _s(j['unit']),
        minQuantity = _i(j['min_quantity']),
        depositPercent = _i(j['deposit_percent']),
        imageUrl = j['image_url'] as String?,
        options = (j['options'] is Map<String, dynamic>)
            ? (j['options'] as Map<String, dynamic>).map((k, v) => MapEntry(k, (_s((v as Map)['price']), _i(v['days']))))
            : const {};

  final int id;
  final String title;
  final String? description;
  final String origin;
  final String unit;
  final int minQuantity;
  final int depositPercent;
  final String? imageUrl;

  /// sea | air => (landed price per unit, days)
  final Map<String, (String, int)> options;
}

class ImportOrderInfo {
  ImportOrderInfo.fromJson(Map<String, dynamic> j)
      : id = _i(j['id']),
        number = _s(j['number']),
        product = _s(j['product']),
        quantity = _i(j['quantity']),
        shipping = _s(j['shipping']),
        total = _s(j['total']),
        depositAmount = _s(j['deposit_amount']),
        balanceAmount = _s(j['balance_amount']),
        statusLabel = _s(j['status_label']),
        estimatedArrival = j['estimated_arrival'] as String?,
        depositPaid = j['deposit_paid'] == true,
        balancePaid = j['balance_paid'] == true,
        canPayBalance = j['can_pay_balance'] == true,
        paymentId = j['payment_id'] == null ? null : _i(j['payment_id']),
        steps = _maps(j['steps']).map((s) => ImportStep(_s(s['label']), s['at'] as String?)).toList();

  final int id;
  final String number;
  final String product;
  final int quantity;
  final String shipping;
  final String total;
  final String depositAmount;
  final String balanceAmount;
  final String statusLabel;
  final String? estimatedArrival;
  final bool depositPaid;
  final bool balancePaid;
  final bool canPayBalance;
  final int? paymentId;
  final List<ImportStep> steps;
}
