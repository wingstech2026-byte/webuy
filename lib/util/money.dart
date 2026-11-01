/// Money arrives from the API as exact strings ("1250.00"). It is only formatted here, never
/// calculated with doubles: totals always come from the server.
String formatLe(String? amount) {
  final raw = (amount == null || amount.isEmpty) ? '0.00' : amount;
  final negative = raw.startsWith('-');
  final unsigned = negative ? raw.substring(1) : raw;
  final parts = unsigned.split('.');
  final whole = parts[0].isEmpty ? '0' : parts[0];
  final cents = (parts.length > 1 ? parts[1] : '').padRight(2, '0').substring(0, 2);

  final buffer = StringBuffer();
  for (var i = 0; i < whole.length; i++) {
    if (i > 0 && (whole.length - i) % 3 == 0) {
      buffer.write(',');
    }
    buffer.write(whole[i]);
  }

  return '${negative ? '-' : ''}Le $buffer.$cents';
}

/// Sierra Leone numbers come back as 23276123456; people read them as 076 123 456.
String displayPhone(String? phone) {
  if (phone == null || phone.length != 11 || !phone.startsWith('232')) {
    return phone ?? '';
  }
  final national = phone.substring(3);

  return '0${national.substring(0, 2)} ${national.substring(2, 5)} ${national.substring(5)}';
}
