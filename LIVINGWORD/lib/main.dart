import 'package:flutter/material.dart';
import 'app.dart';
import 'services/analytics_beacon.dart';
import 'services/in_app_update_service.dart';
import 'services/offline_bible_service.dart';
import 'services/push_service.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // Start Firebase push + check for Play in-app updates in the background —
  // neither blocks first paint.
  PushService.init();
  // One analytics hit per launch. Without it the dashboard's "App opens" card
  // and its web-vs-app split can only ever read zero. Fire-and-forget.
  AnalyticsBeacon.appOpen();
  InAppUpdateService.check();
  // Warm the offline Bible into memory so it opens & searches instantly —
  // decoding the bundled JSON on first tap is what made it feel slow.
  OfflineBibleService.instance.warmUp();
  runApp(const LivingWordApp());
}
