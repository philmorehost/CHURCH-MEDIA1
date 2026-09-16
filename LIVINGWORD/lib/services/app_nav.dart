import 'package:flutter/material.dart';

/// Global navigation helpers so push taps (which can fire before/outside the
/// normal widget tree) can move the app to the right tab.
class AppNav {
  static final GlobalKey<NavigatorState> navigatorKey = GlobalKey<NavigatorState>();
  static void Function(int index)? onSwitchTab;

  /// Switches the root bottom-nav tab (Home=0, Reels=1, Events=2, Sermons=3, More=4).
  static void goToTab(int index) {
    final cb = onSwitchTab;
    if (cb != null) {
      cb(index);
      return;
    }
    // Fallback: pop back to the shell's home route.
    navigatorKey.currentState?.popUntil((r) => r.isFirst);
  }
}
