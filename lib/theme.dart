import 'package:flutter/material.dart';

/// Same green as the website, big tap targets for first-time smartphone users.
class WiBuyColors {
  static const green = Color(0xFF15803D);
  static const greenDark = Color(0xFF166534);
  static const greenLight = Color(0xFFDCFCE7);
  static const orange = Color(0xFFEA580C);
  static const text = Color(0xFF1F2937);
  static const muted = Color(0xFF6B7280);
  static const background = Color(0xFFF5F5F4);
}

ThemeData wibuyTheme() {
  final scheme = ColorScheme.fromSeed(seedColor: WiBuyColors.green, primary: WiBuyColors.green, surface: Colors.white);

  return ThemeData(
    useMaterial3: true,
    fontFamily: 'Roboto', // bundled in the app (pubspec.yaml), so no font download
    colorScheme: scheme,
    scaffoldBackgroundColor: WiBuyColors.background,
    appBarTheme: const AppBarTheme(
      backgroundColor: WiBuyColors.green,
      foregroundColor: Colors.white,
      centerTitle: false,
      titleTextStyle: TextStyle(fontSize: 20, fontWeight: FontWeight.w700, color: Colors.white),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: WiBuyColors.green,
        minimumSize: const Size.fromHeight(52),
        textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: WiBuyColors.green,
        minimumSize: const Size.fromHeight(52),
        side: const BorderSide(color: WiBuyColors.green, width: 1.5),
        textStyle: const TextStyle(fontSize: 17, fontWeight: FontWeight.w700),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: Colors.white,
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
      contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 16),
    ),
    cardTheme: CardThemeData(
      color: Colors.white,
      elevation: 0,
      margin: EdgeInsets.zero,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
    ),
  );
}
