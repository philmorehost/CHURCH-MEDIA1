import java.util.Properties

plugins {
    id("com.android.application")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
    // Reads google-services.json (android/app) for Firebase Cloud Messaging.
    id("com.google.gms.google-services")
}

// Release signing credentials come from android/key.properties (gitignored) or
// the ANDROID_* environment variables (set by CI from GitHub secrets). When
// neither exists, release builds fall back to the debug key so the build is
// never blocked locally — upload that APK is for testing only.
val keystoreProperties = Properties()
val keystorePropertiesFile = rootProject.file("key.properties")
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(keystorePropertiesFile.inputStream())
}
fun signingProp(name: String, env: String): String? =
    keystoreProperties.getProperty(name)?.takeIf { it.isNotBlank() } ?: System.getenv(env)?.takeIf { it.isNotBlank() }

android {
    namespace = "com.churchmedia.app"
    // Pinned so releases always satisfy the Google Play target-API requirement
    // (Android 16 / API 36), regardless of the installed Flutter SDK default.
    compileSdk = 36
    ndkVersion = "28.2.13676358"

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        // TODO: Specify your own unique Application ID (https://developer.android.com/studio/build/application-id.html).
        applicationId = "com.churchmedia.app"
        // You can update the following values to match your application needs.
        // For more information, see: https://flutter.dev/to/review-gradle-config.
        minSdk = 24
        targetSdk = 36
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    signingConfigs {
        create("release") {
            val f = signingProp("storeFile", "ANDROID_KEYSTORE_PATH")?.let { file(it) }
            val p = signingProp("storePassword", "ANDROID_KEYSTORE_PASSWORD")
            val a = signingProp("keyAlias", "ANDROID_KEY_ALIAS")
            val k = signingProp("keyPassword", "ANDROID_KEY_PASSWORD")
            if (f != null && f.isFile && p != null && a != null && k != null) {
                storeFile = f
                storePassword = p
                keyAlias = a
                keyPassword = k
            }
        }
    }

    buildTypes {
        release {
            val release = signingConfigs.findByName("release")
            signingConfig = if (release?.storeFile != null) release else signingConfigs.getByName("debug")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
