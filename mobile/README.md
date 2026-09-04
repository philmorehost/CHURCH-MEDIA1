# Church Media App (Flutter)

A single Dart codebase that builds to real native Android and iOS apps. Front-end only — no login, no admin surface. Every screen is a client of the same REST API the website uses (`api/*.php`).

This folder contains only the Dart application (`lib/`, `pubspec.yaml`) — it was written without the Flutter SDK installed on the build machine, so the `android/`, `ios/`, and `web/` platform scaffolding (which `flutter create` generates and which you should NOT hand-write) doesn't exist yet. Setup below creates it in about two minutes.

## One-time setup

1. Install the Flutter SDK: https://docs.flutter.dev/get-started/install (includes Dart — no separate install). Run `flutter doctor` and resolve anything it flags.
2. Scaffold the platform folders into this directory:
   ```bash
   cd mobile
   flutter create --org com.gracelifechurch --project-name church_media_app .
   ```
   This adds `android/`, `ios/`, `web/`, etc. next to the existing `lib/` and `pubspec.yaml` — it will NOT overwrite the files already here (it only fills in what's missing).
3. Install dependencies:
   ```bash
   flutter pub get
   ```

## Point the app at your backend

The API base URL is compile-time configurable (`lib/services/api_client.dart`):

```bash
# Production server
flutter run --dart-define=API_BASE_URL=https://rccglp63yaya.org.ng

# Android emulator (10.0.2.2 is the emulator's alias for your host machine's localhost)
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8080
```

Without `--dart-define`, it defaults to `https://rccglp63yaya.org.ng` (the production server).

## Run it

```bash
flutter devices          # see what's available
flutter run               # pick a device, or -d chrome for a quick web preview
```

`-d chrome` is the fastest way to sanity-check UI changes without an emulator, but ship on Android/iOS for real video/share/launch-URL behavior.

## Build for release

```bash
# Android — signed APK/AAB needs a keystore first: https://docs.flutter.dev/deployment/android
flutter build apk --release
flutter build appbundle --release

# iOS — must be run on a Mac with Xcode installed; this is an Apple platform
# requirement, not specific to this app or to Flutter.
flutter build ios --release
```

The release commands above already point at `https://rccglp63yaya.org.ng` by default; pass `--dart-define=API_BASE_URL=...` only if you need to override it for a specific build.

### Publish to Google Play (manual)

The GitHub Actions workflow (`.github/workflows/build-apk.yml`) builds a **signed release APK and AAB** on every push to `main` and uploads them as artifacts (`church-media-apk`, `church-media-aab`). Publishing to Google Play is done manually:

1. Open **Actions → "Build APK & AAB" → latest run**.
2. Download the **`church-media-aab`** artifact (already signed with the release keystore — production-ready).
3. In **Google Play Console** → your app → **Production** (or a test track) → **Create new release**.
4. **Upload the `.aab`**, add release notes, then **Review → Roll out**.

No Play service-account key is required in CI — uploads are done through the Play Console web UI, so there's nothing to fail in the build.
```

## Structure

```
lib/
  main.dart                 entry point
  app.dart                  MaterialApp + bottom-nav shell (Home/Feed/Events/Sermons/More)
  theme/app_theme.dart       design tokens mirrored from public/assets/css/site.css
  models/models.dart         JSON models matching api/*.php response shapes
  services/api_client.dart   all REST calls — the only place that knows the API's URLs
  screens/                   one file per screen
  widgets/                   shared cards/empty-states/loading
```

## After scaffolding, verify it builds

I could not run `flutter analyze` / `flutter pub get` / `flutter build` myself — no Flutter SDK was available in the environment this was written in. Once you've run the setup steps above, please run:

```bash
flutter analyze
```

and fix anything it flags before shipping — if something doesn't compile, it's almost certainly a package API drift (this was written against `share_plus ^10.0.2`, `video_player ^2.9.1`, `cached_network_image ^3.3.1`, `google_fonts ^6.2.1` — bump/adjust versions in `pubspec.yaml` if `flutter pub get` resolves something incompatible).
