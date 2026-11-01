/// Where the app finds the WiBuy Laravel backend.
///
/// Set it when running:
///   flutter run --dart-define=API_BASE_URL=http://192.168.1.20:8000/api/v1
/// The VS Code launch configurations in WiBuy.code-workspace already pass it.
///   - Real phone on the same Wi-Fi: your computer's Wi-Fi address.
///   - Android emulator: http://10.0.2.2:8000/api/v1 (the computer's localhost).
///   - Production: https://your-domain/api/v1
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://113.54.254.124:8000/api/v1',
);

const String appName = 'WiBuy';
const String appTagline = 'Buy and sell easy for Salone.';
