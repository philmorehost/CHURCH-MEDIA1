# LIVINGWORD — mobile app

The Flutter app for **Living Word Gospel Mission**. Front-end only: every screen is a
client of the REST API the website already serves (`api/*.php`). No login, no admin
surface.

| | |
|---|---|
| Name shown on the phone | **LIVINGWORD** |
| Android package / iOS bundle id | `com.livingwordgospelmission.app` |
| Dart package | `livingword_app` |
| Default API | `https://livingwordgospelmission.org.ng` |
| Version | `1.0.0+1` |
| Icon source | `assets/icon/app_icon.png` (from `livingword-logo2.jpeg`) |

## ⚠️ Before the first build — Firebase

`android/app/build.gradle.kts` applies the **Google Services** plugin, because the app
receives push notifications through Firebase Cloud Messaging. That plugin **fails the
build** when `android/app/google-services.json` is missing — and it *is* missing here,
deliberately.

It was not copied from the other church's app. Two churches sharing one Firebase
project means one audience: a sermon pushed for RCCG would arrive on Living Word
phones, and the reverse. Living Word needs its own project.

1. <https://console.firebase.google.com> → **Add project** — e.g. `livingword-app`.
2. **Add app → Android**, package name exactly `com.livingwordgospelmission.app`.
   (Skip the SHA-1 unless you add Google Sign-In; push does not need it.)
3. Download `google-services.json` and put it at `android/app/google-services.json`.
4. **Build → Cloud Messaging** → the app is now able to receive pushes.
5. In the website admin, open **Firebase** and upload the same project's
   **service-account key** (Project settings → Service accounts → Generate new private
   key). That is the key the website uses to *send* the push. The app cannot be
   notified for this church until that is done.

`android/app/google-services.json`, `ios/Runner/GoogleService-Info.plist` and
`android/key.properties` are all listed in `.gitignore` — keep them there. The last one
holds your keystore password.

## Run it

```bash
flutter pub get
flutter devices
flutter run                       # or -d chrome for a quick UI preview
```

Without any flags the app talks to `https://livingwordgospelmission.org.ng`. Point it
somewhere else for local work:

```bash
# Android emulator — 10.0.2.2 is the emulator's alias for your machine's localhost
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080
```

## Build for release

Needs the Flutter SDK, JDK 17, and the Android SDK with **API 36** (`compileSdk` and
`targetSdk` are both pinned to 36 so the Play target-API requirement is always met).

```bash
flutter build apk --release
flutter build appbundle --release      # this is what Play accepts
```

**Signing.** Release builds look for `android/key.properties` (gitignored) or the
`ANDROID_KEYSTORE_PATH`, `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` and
`ANDROID_KEY_PASSWORD` environment variables. With neither present the build falls back
to the **debug key** so it is never blocked — that APK is for testing only and Play will
reject it. Create a keystore once:
<https://docs.flutter.dev/deployment/android#signing-the-app>

**iOS** can only be built on a Mac with Xcode — an Apple requirement, not a Flutter one.

## App icon

Replace `assets/icon/app_icon.png` (square, 1024×1024), then regenerate every size:

```bash
dart run flutter_launcher_icons
```

`pubspec.yaml` drives it. `adaptive_icon_background` is `#FAF8F1`, sampled from the
logo's own edge rather than plain white, so the Android adaptive icon does not show a
faint square behind the artwork. `remove_alpha_ios` is on because the App Store rejects
launcher icons that carry an alpha channel.

## Structure

```
lib/
  main.dart                  entry point — push, in-app update, offline Bible warm-up
  app.dart                   MaterialApp + bottom-nav shell (Home/Feed/Events/Sermons/More)
  theme/app_theme.dart       design tokens
  models/models.dart         JSON models matching the api/*.php response shapes
  services/api_client.dart   every REST call — the only place that knows the API's URLs
  services/analytics_beacon.dart  reports app_open and content views to the admin dashboard
  services/push_service.dart      FCM token, 'all' topic, and per-church topics
  screens/                   one file per screen
  widgets/                   shared cards, empty states, loading
```

Church name, logo, colours and service times are **not** hardcoded — they are read from
the API, so they follow whatever is set in the admin panel.

## Notes

- **Per-church push.** The app subscribes to the `all` topic on launch and to a
  `unit-{id}` topic when a church page is opened. Both are scoped to this app's own
  Firebase project, so they cannot cross with another church's app.
- **The share sheet** uses a `MethodChannel` named `livingword/share`, which must match
  `CHANNEL` in `android/app/src/main/kotlin/com/livingwordgospelmission/app/MainActivity.kt`.
- `flutter analyze` reports **12 `info` lints, no errors or warnings** — pre-existing
  style hints in `bible_screen.dart`, `event_detail_screen.dart` and `home_screen.dart`.
