import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

/// Mirrors the website's design system (public/assets/css/site.css) so the
/// app and site feel like one product: dark charcoal/navy base, warm gold
/// accent, serif display type for headings.
class AppColors {
  static const bg0 = Color(0xFF0A0912);
  static const bg1 = Color(0xFF120F22);
  static const bg2 = Color(0xFF181530);
  static const panel = Color(0xFF161428);
  static const border = Color(0xFF2C2850);
  static const gold = Color(0xFFE8B95F);
  static const goldSoft = Color(0xFFF3D38F);
  static const ink = Color(0xFFFFFFFF);
  static const inkDim = Color(0xFFDCD8F2);
  static const inkFaint = Color(0xFF9B96BC);
  static const danger = Color(0xFFFF6B6B);
  static const success = Color(0xFF5FE0A4);
}

class AppTheme {
  static ThemeData get dark {
    final base = ThemeData.dark(useMaterial3: true);
    final bodyFont = GoogleFonts.interTextTheme(base.textTheme);
    final headingFont = GoogleFonts.playfairDisplayTextTheme(base.textTheme);

    return base.copyWith(
      scaffoldBackgroundColor: AppColors.bg0,
      colorScheme: base.colorScheme.copyWith(
        primary: AppColors.gold,
        secondary: AppColors.goldSoft,
        surface: AppColors.panel,
        error: AppColors.danger,
      ),
      textTheme: bodyFont.copyWith(
        displayLarge: headingFont.displayLarge?.copyWith(color: AppColors.ink, fontWeight: FontWeight.w700),
        displayMedium: headingFont.displayMedium?.copyWith(color: AppColors.ink, fontWeight: FontWeight.w700),
        headlineLarge: headingFont.headlineLarge?.copyWith(color: AppColors.ink, fontWeight: FontWeight.w700),
        headlineMedium: headingFont.headlineMedium?.copyWith(color: AppColors.ink, fontWeight: FontWeight.w700),
        headlineSmall: headingFont.headlineSmall?.copyWith(color: AppColors.ink, fontWeight: FontWeight.w700),
        titleLarge: headingFont.titleLarge?.copyWith(color: AppColors.ink, fontWeight: FontWeight.w600),
        bodyLarge: bodyFont.bodyLarge?.copyWith(color: AppColors.ink),
        bodyMedium: bodyFont.bodyMedium?.copyWith(color: AppColors.inkDim),
        bodySmall: bodyFont.bodySmall?.copyWith(color: AppColors.inkFaint),
      ),
      appBarTheme: const AppBarTheme(
        backgroundColor: AppColors.bg0,
        elevation: 0,
        centerTitle: false,
      ),
      cardTheme: CardThemeData(
        color: AppColors.panel,
        elevation: 0,
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(18),
          side: const BorderSide(color: AppColors.border),
        ),
      ),
      dividerColor: AppColors.border,
      elevatedButtonTheme: ElevatedButtonThemeData(
        style: ElevatedButton.styleFrom(
          backgroundColor: AppColors.gold,
          foregroundColor: const Color(0xFF1A1530),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
          padding: const EdgeInsets.symmetric(horizontal: 26, vertical: 14),
          textStyle: bodyFont.labelLarge?.copyWith(fontWeight: FontWeight.w700),
        ),
      ),
      outlinedButtonTheme: OutlinedButtonThemeData(
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.goldSoft,
          side: const BorderSide(color: AppColors.gold),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(999)),
          padding: const EdgeInsets.symmetric(horizontal: 26, vertical: 14),
        ),
      ),
      bottomNavigationBarTheme: const BottomNavigationBarThemeData(
        backgroundColor: AppColors.bg1,
        selectedItemColor: AppColors.goldSoft,
        unselectedItemColor: AppColors.inkFaint,
        type: BottomNavigationBarType.fixed,
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white.withValues(alpha: 0.05),
        border: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: AppColors.border)),
        enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: AppColors.border)),
        focusedBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(12), borderSide: const BorderSide(color: AppColors.gold)),
        labelStyle: const TextStyle(color: AppColors.inkDim),
      ),
    );
  }
}
