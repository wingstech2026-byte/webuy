import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'api/api_client.dart';
import 'api/models.dart';

/// Who is logged in, plus app-wide bits (settings from the backend, cart badge).
/// The token is the same Sanctum token the Laravel API issues at /auth/verify.
class Session extends ChangeNotifier {
  Session(this.api, this._prefs) {
    api.token = _prefs.getString(_tokenKey);
    final saved = _prefs.getString(_userKey);
    if (api.token != null && saved != null) {
      try {
        user = User.fromJson(jsonDecode(saved) as Map<String, dynamic>);
      } on Object {
        user = null;
      }
    }
    api.onUnauthorized = _forget;

    var device = _prefs.getString(_deviceKey);
    if (device == null) {
      final random = Random.secure();
      device = List.generate(16, (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0')).join();
      _prefs.setString(_deviceKey, device);
    }
    api.deviceId = device;
  }

  /// The friend's code from a shared link (deferred link), sent once at sign-up.
  String? get referralCode => _prefs.getString(_referralKey);

  /// On the very first start after installing: which product did the person look at on the website?
  /// Returns a path like /products/rice-50kg, once.
  Future<String?> claimDeferredLink() async {
    if (_prefs.getBool(_firstRunKey) == true) {
      return null;
    }
    await _prefs.setBool(_firstRunKey, true);
    try {
      final json = await api.get('/app/deferred-link') as Map<String, dynamic>;
      final ref = json['referral_code'] as String?;
      if (ref != null && ref.isNotEmpty) {
        await _prefs.setString(_referralKey, ref);
      }
      return json['path'] as String?;
    } on ApiException {
      return null;
    }
  }

  static const _tokenKey = 'wibuy.token';
  static const _userKey = 'wibuy.user';
  static const _deviceKey = 'wibuy.device';
  static const _referralKey = 'wibuy.referral';
  static const _firstRunKey = 'wibuy.first_run_done';
  static const _recentKey = 'wibuy.recently_viewed';
  static const _compareKey = 'wibuy.compare';

  /// Products looked at on this phone (slugs, most recent first). Kept only on the phone.
  List<String> get recentlyViewed => _prefs.getStringList(_recentKey) ?? const [];

  void viewed(String slug) {
    final list = [slug, ...recentlyViewed.where((s) => s != slug)].take(20).toList();
    _prefs.setStringList(_recentKey, list);
  }

  /// Up to 3 products to compare side by side.
  List<String> get compareList => _prefs.getStringList(_compareKey) ?? const [];

  static const compareMax = 3;

  /// Adds or removes a product. Returns false when the list is already full.
  bool toggleCompare(String slug) {
    final list = compareList.toList();
    if (list.remove(slug)) {
      _prefs.setStringList(_compareKey, list);
      notifyListeners();
      return true;
    }
    if (list.length >= compareMax) return false;
    _prefs.setStringList(_compareKey, [...list, slug]);
    notifyListeners();
    return true;
  }

  final ApiClient api;
  final SharedPreferences _prefs;

  User? user;
  AppMeta? meta;
  int cartCount = 0;

  /// Unread notifications and chat messages (badges on the home screen).
  int notificationCount = 0;
  int chatCount = 0;

  Future<void> refreshCounts() async {
    if (!isLoggedIn) return;
    try {
      final json = await api.get('/notifications/counts') as Map<String, dynamic>;
      final n = (json['notifications'] as num?)?.toInt() ?? 0;
      final c = (json['chat'] as num?)?.toInt() ?? 0;
      if (n != notificationCount || c != chatCount) {
        notificationCount = n;
        chatCount = c;
        notifyListeners();
      }
    } on ApiException {
      // Badges stay as they were.
    }
  }

  bool get isLoggedIn => api.token != null && user != null;

  /// Called once at start: settings, and a fresh copy of the user if logged in.
  Future<void> bootstrap() async {
    try {
      meta = AppMeta.fromJson(await api.get('/meta') as Map<String, dynamic>);
    } on ApiException {
      // Screens retry on their own; the app still opens without a connection.
    }
    if (api.token != null) {
      try {
        final me = await api.get('/me') as Map<String, dynamic>;
        await _saveUser(User.fromJson(me['data'] as Map<String, dynamic>));
        await refreshCartCount();
        await refreshCounts();
      } on ApiException {
        // 401 already logged out through onUnauthorized; offline keeps the saved user.
      }
    }
    notifyListeners();
  }

  /// Step 1: send an SMS code. Returns the backend's answer (needs_name, dev_code...).
  Future<Map<String, dynamic>> sendCode(String phone) async =>
      await api.post('/auth/code', {'phone': phone}) as Map<String, dynamic>;

  /// Step 2: check the code and keep the token.
  Future<void> verify(String phone, String code, {String? name}) async {
    final result = await api.post('/auth/verify', {
      'phone': phone,
      'code': code,
      if (name != null && name.isNotEmpty) 'name': name,
      if (referralCode != null) 'referral_code': referralCode,
      'device_name': 'WiBuy app (${defaultTargetPlatform.name})',
    }) as Map<String, dynamic>;

    api.token = result['token'] as String;
    await _prefs.setString(_tokenKey, api.token!);
    await _saveUser(User.fromJson(result['user'] as Map<String, dynamic>));
    await refreshCartCount();
    notifyListeners();
  }

  Future<void> logout() async {
    try {
      await api.post('/auth/logout');
    } on ApiException {
      // Still forget the token on this phone.
    }
    _forget();
  }

  Future<void> refreshCartCount() async {
    if (api.token == null) {
      return;
    }
    try {
      final cart = await api.get('/cart') as Map<String, dynamic>;
      setCartCount((cart['count'] as num?)?.toInt() ?? 0);
    } on ApiException {
      // Badge stays as it was.
    }
  }

  void setCartCount(int count) {
    if (count != cartCount) {
      cartCount = count;
      notifyListeners();
    }
  }

  Future<void> _saveUser(User value) async {
    user = value;
    await _prefs.setString(_userKey, jsonEncode(value.toJson()));
  }

  void _forget() {
    api.token = null;
    user = null;
    cartCount = 0;
    _prefs.remove(_tokenKey);
    _prefs.remove(_userKey);
    notifyListeners();
  }
}

/// Gives every screen the Session without extra packages.
class SessionScope extends InheritedNotifier<Session> {
  const SessionScope({super.key, required Session session, required super.child}) : super(notifier: session);

  static Session of(BuildContext context) => context.dependOnInheritedWidgetOfExactType<SessionScope>()!.notifier!;

  /// For button handlers: no rebuild subscription.
  static Session read(BuildContext context) => context.getInheritedWidgetOfExactType<SessionScope>()!.notifier!;
}
