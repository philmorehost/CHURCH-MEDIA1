import 'dart:async';
import 'dart:io' show Platform;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:in_app_update/in_app_update.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../theme/app_theme.dart';
import 'app_nav.dart';

/// Google Play in-app updates — prompts the user to update inside the app
/// instead of waiting for (or depending on) automatic background updates.
///
/// - **Flexible**: a dialog offers "Update" (Play downloads in the background,
///   app keeps working, then restarts to install) or "Later".
/// - **Immediate** (when the developer flags the update as mandatory): the
///   system force-update screen blocks the app until it is updated.
///
/// Only runs on Android devices that came from the Play Store; every step is
/// guarded so any failure is silently ignored.
class InAppUpdateService {
  static bool _checked = false;
  static StreamSubscription<InstallStatus>? _installSub;

  /// Check once per launch. Call early (fire-and-forget) — it waits for the
  /// widget tree internally before showing any UI.
  static Future<void> check() async {
    if (_checked) return;
    _checked = true;

    if (kIsWeb) return;
    try {
      if (!Platform.isAndroid) return;
    } catch (_) {
      return;
    }

    AppUpdateInfo info;
    try {
      info = await InAppUpdate.checkForUpdate();
    } catch (_) {
      return; // Not from Play, offline, or Play services unavailable.
    }
    if (info.updateAvailability != UpdateAvailability.updateAvailable) {
      return;
    }

    final immediate = info.immediateUpdateAllowed;
    final flexible = info.flexibleUpdateAllowed;
    if (!immediate && !flexible) return;

    // Only prompt once per available version, so we never nag every launch.
    final prefs = await SharedPreferences.getInstance();
    final lastPrompted = prefs.getInt('last_prompted_update_version') ?? 0;
    final versionCode = info.availableVersionCode ?? 0;
    if (versionCode > 0 && versionCode <= lastPrompted) return;
    if (versionCode > 0) {
      await prefs.setInt('last_prompted_update_version', versionCode);
    }

    // Give the app a moment to finish first paint before showing the dialog.
    await Future<void>.delayed(const Duration(milliseconds: 500));
    final ctx = AppNav.navigatorKey.currentContext;
    if (ctx == null || !ctx.mounted) return;

    final choice = await showDialog<String>(
      context: ctx,
      barrierDismissible: flexible,
      builder: (context) => AlertDialog(
        backgroundColor: const Color(0xFF1F1F1F),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text('📲 Update available', style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w700)),
        content: const Text(
          'A newer version of the app is ready. Update now for the latest features, fixes, and improvements.',
          style: TextStyle(color: Colors.white70, fontSize: 14, height: 1.5),
        ),
        actions: [
          if (flexible)
            TextButton(
              onPressed: () => Navigator.pop(context, 'later'),
              child: const Text('Later', style: TextStyle(color: Colors.white54)),
            ),
          TextButton(
            onPressed: () => Navigator.pop(context, 'update'),
            child: const Text('Update', style: TextStyle(color: AppColors.gold, fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );

    if (choice != 'update') return;

    try {
      if (immediate) {
        await InAppUpdate.performImmediateUpdate();
        return;
      }
      // Flexible: start the download, then complete it once it has finished.
      await InAppUpdate.startFlexibleUpdate();
      _installSub ??= InAppUpdate.installUpdateListener.listen((status) {
        if (status == InstallStatus.downloaded) {
          _installSub?.cancel();
          _installSub = null;
          InAppUpdate.completeFlexibleUpdate().catchError((_) {});
        }
      });
    } catch (_) {
      // Update flow failed — the app simply continues.
    }
  }
}
