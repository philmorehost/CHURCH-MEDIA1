import 'dart:io' show Platform;

import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../screens/notifications_screen.dart';
import 'api_client.dart';
import 'app_nav.dart';

/// Wires Firebase Cloud Messaging into the app: notification permission,
/// device-token registration, broadcast-topic subscription, foreground
/// snackbars, and tap-to-open navigation. Every call is guarded so a missing
/// or misconfigured Firebase never breaks the app.
class PushService {
  static bool _initialized = false;

  static Future<void> init() async {
    if (_initialized) return;
    try {
      await Firebase.initializeApp();
    } catch (_) {
      return; // Firebase not configured for this platform yet — app runs normally.
    }
    _initialized = true;

    final messaging = FirebaseMessaging.instance;

    // Ask for permission (no-op where it isn't needed).
    try {
      final settings = await messaging.requestPermission(alert: true, badge: true, sound: true);
      if (settings.authorizationStatus == AuthorizationStatus.denied) return;
    } catch (_) {}

    // Foreground message → brief floating snackbar.
    FirebaseMessaging.onMessage.listen((RemoteMessage m) {
      final ctx = AppNav.navigatorKey.currentContext;
      final n = m.notification;
      if (ctx == null || !ctx.mounted || n == null) return;
      ScaffoldMessenger.of(ctx).showSnackBar(SnackBar(
        content: Text('${n.title ?? ''} — ${n.body ?? ''}'),
        behavior: SnackBarBehavior.floating,
        backgroundColor: const Color(0xFF1F1F1F),
      ));
    });

    // Taps while the app is open or in the background.
    FirebaseMessaging.onMessageOpenedApp.listen(_handleMessage);
    // Taps that launched the app from a terminated state.
    try {
      final initial = await messaging.getInitialMessage();
      if (initial != null) _handleMessage(initial);
    } catch (_) {}

    // Register the device token and join the broadcast topic.
    try {
      final token = await messaging.getToken();
      if (token != null) {
        await ApiClient().registerDevice(
          token: token,
          platform: kIsWeb ? 'web' : (Platform.isIOS ? 'ios' : 'android'),
        );
      }
      await messaging.subscribeToTopic('all');
    } catch (_) {}
  }

  /// How many church topics one device stays subscribed to.
  ///
  /// A device only follows churches whose page someone actually opens, and it
  /// should not sit on those forever — browsing a dozen churches would
  /// otherwise leave a dozen live subscriptions. The oldest is dropped as a new
  /// one is added.
  static const int _maxUnitTopics = 10;
  static const String _unitTopicsKey = 'push_unit_topics';

  /// Subscribes this device to a church's topic.
  ///
  /// Without this, a notification an admin aims at one church reaches nobody:
  /// `admin/notifications.php` and `core/Notifier.php` both push to a per-church
  /// topic rather than the broadcast topic, so until a device joins that topic
  /// the send is silently dropped. Called when someone opens a church's page.
  static Future<void> followUnit(int unitId) async {
    if (unitId <= 0) return;
    // Firebase may simply not be configured for this platform — init() leaves
    // this false in that case, and there is nothing to subscribe with.
    if (!_initialized) return;
    try {
      final prefs = await SharedPreferences.getInstance();
      final followed = prefs.getStringList(_unitTopicsKey) ?? <String>[];
      final topic = 'unit-$unitId';
      if (followed.contains(topic)) return;

      final messaging = FirebaseMessaging.instance;
      await messaging.subscribeToTopic(topic);
      followed.add(topic);

      while (followed.length > _maxUnitTopics) {
        await messaging.unsubscribeFromTopic(followed.removeAt(0));
      }
      await prefs.setStringList(_unitTopicsKey, followed);
    } catch (_) {
      // Push plumbing must never interrupt navigation.
    }
  }

  static void _handleMessage(RemoteMessage message) {
    final data = message.data;
    final type = data['type'];
    final nav = AppNav.navigatorKey.currentState;
    if (nav == null) return;
    if (type == 'post') {
      // A new reel → jump straight to the Reels tab.
      AppNav.goToTab(1);
      return;
    }
    // Everything else (event, sermon, admin notice) → the notifications center.
    nav.push(MaterialPageRoute(builder: (_) => const NotificationsScreen()));
  }
}
