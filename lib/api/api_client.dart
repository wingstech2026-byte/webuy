import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../config.dart';

/// An error from the backend, with a message people can read.
class ApiException implements Exception {
  ApiException(this.status, this.message, [this.errors = const {}]);

  /// HTTP status, or 0 when there was no connection.
  final int status;
  final String message;

  /// Field errors from Laravel validation (field => messages).
  final Map<String, List<String>> errors;

  bool get isUnauthorized => status == 401;

  /// The most useful single line to show: the first field error, else the message.
  String get display {
    for (final messages in errors.values) {
      if (messages.isNotEmpty) {
        return messages.first;
      }
    }
    return message;
  }

  @override
  String toString() => display;
}

/// Small JSON client for the WiBuy REST API (Laravel + Sanctum token).
class ApiClient {
  ApiClient({http.Client? httpClient, String? baseUrl, this.token})
      : _http = httpClient ?? http.Client(),
        baseUrl = baseUrl ?? apiBaseUrl;

  final http.Client _http;
  final String baseUrl;

  /// Sanctum token, sent as "Authorization: Bearer ...".
  String? token;

  /// A random id for this phone (X-Device-Id). The backend uses it to stop one person using many
  /// accounts for price cuts and referrals.
  String? deviceId;

  /// Called when the backend says the token is no longer valid.
  void Function()? onUnauthorized;

  static const _timeout = Duration(seconds: 25);

  Future<dynamic> get(String path, {Map<String, String?>? query}) => _send('GET', path, query: query);

  Future<dynamic> post(String path, [Map<String, dynamic>? body]) => _send('POST', path, body: body);

  Future<dynamic> patch(String path, [Map<String, dynamic>? body]) => _send('PATCH', path, body: body);

  Future<dynamic> put(String path, [Map<String, dynamic>? body]) => _send('PUT', path, body: body);

  Future<dynamic> delete(String path) => _send('DELETE', path);

  /// A form with files (photos). [files] maps a field such as "photos[]" to (file name, bytes) pairs.
  Future<dynamic> postMultipart(String path, Map<String, String> fields, Map<String, List<(String, List<int>)>> files) async {
    final request = http.MultipartRequest('POST', uri(path))
      ..headers['Accept'] = 'application/json'
      ..fields.addAll(fields);
    if (token != null) request.headers['Authorization'] = 'Bearer $token';
    if (deviceId != null) request.headers['X-Device-Id'] = deviceId!;
    files.forEach((field, list) {
      for (final (name, bytes) in list) {
        request.files.add(http.MultipartFile.fromBytes(field, bytes, filename: name));
      }
    });

    final http.Response response;
    try {
      response = await http.Response.fromStream(await _http.send(request).timeout(const Duration(seconds: 90)));
    } on SocketException {
      throw ApiException(0, 'No connection to WiBuy. Check your data or Wi-Fi and try again.');
    } on TimeoutException {
      throw ApiException(0, 'The upload is slow. Please try again on a better connection.');
    } on http.ClientException {
      throw ApiException(0, 'No connection to WiBuy. Check your data or Wi-Fi and try again.');
    }

    final decoded = response.body.isEmpty ? null : _tryDecode(response.body);
    if (response.statusCode >= 400) {
      if (response.statusCode == 401) onUnauthorized?.call();
      throw _error(response.statusCode, decoded);
    }
    return decoded;
  }

  Uri uri(String path, [Map<String, String?>? query]) {
    final cleaned = <String, String>{};
    query?.forEach((key, value) {
      if (value != null && value.isNotEmpty) {
        cleaned[key] = value;
      }
    });

    return Uri.parse('$baseUrl$path').replace(queryParameters: cleaned.isEmpty ? null : cleaned);
  }

  Future<dynamic> _send(String method, String path, {Map<String, String?>? query, Map<String, dynamic>? body}) async {
    final request = http.Request(method, uri(path, query))
      ..headers['Accept'] = 'application/json'
      ..headers['Content-Type'] = 'application/json';

    if (token != null) {
      request.headers['Authorization'] = 'Bearer $token';
    }
    if (deviceId != null) {
      request.headers['X-Device-Id'] = deviceId!;
    }
    if (body != null) {
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      response = await http.Response.fromStream(await _http.send(request).timeout(_timeout));
    } on SocketException {
      throw ApiException(0, 'No connection to WiBuy. Check your data or Wi-Fi and try again.');
    } on TimeoutException {
      throw ApiException(0, 'The connection is slow. Please try again.');
    } on http.ClientException {
      throw ApiException(0, 'No connection to WiBuy. Check your data or Wi-Fi and try again.');
    }

    final decoded = response.body.isEmpty ? null : _tryDecode(response.body);

    if (response.statusCode >= 400) {
      if (response.statusCode == 401) {
        onUnauthorized?.call();
      }
      throw _error(response.statusCode, decoded);
    }

    return decoded;
  }

  static dynamic _tryDecode(String body) {
    try {
      return jsonDecode(body);
    } on FormatException {
      return null;
    }
  }

  static ApiException _error(int status, dynamic body) {
    final fallback = switch (status) {
      401 => 'Please log in again.',
      403 => 'You cannot do that.',
      404 => 'We could not find that.',
      429 => 'Too many tries. Please wait a moment.',
      >= 500 => 'Something went wrong on our side. Please try again.',
      _ => 'Something went wrong. Please try again.',
    };

    if (body is! Map<String, dynamic>) {
      return ApiException(status, fallback);
    }

    final errors = <String, List<String>>{};
    final raw = body['errors'];
    if (raw is Map<String, dynamic>) {
      raw.forEach((field, messages) {
        if (messages is List) {
          errors[field] = messages.map((m) => m.toString()).toList();
        }
      });
    }

    final message = body['message'];

    return ApiException(status, message is String && message.isNotEmpty ? message : fallback, errors);
  }
}
