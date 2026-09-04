import 'package:flutter/material.dart';
import 'app.dart';
import 'services/in_app_update_service.dart';
import 'services/offline_bible_service.dart';
import 'services/push_service.dart';

void main() {
  WidgetsFlutterBinding.ensureInitialized();
  // Start Firebase push + check for Play in-app updates in the background —
  // neither blocks first paint.
  PushService.init();
  InAppUpdateService.check();
  // Warm the offline Bible into memory so it opens & searches instantly —
  // decoding the bundled JSON on first tap is what made it feel slow.
  OfflineBibleService.instance.warmUp();
  runApp(const ChurchMediaApp());
}
