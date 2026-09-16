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
            val storeFilePath = signingProp("storeFile", "ANDROID_KEYSTORE_PATH")
            val f = storeFilePath?.let { rootProject.file(it).takeIf { file -> file.exists() } ?: file(it) }
            val p = signingProp("storePassword", "ANDROID_KEYSTORE_PASSWORD")
            val a = signingProp("keyAlias", "ANDROID_KEY_ALIAS")
            val k = signingProp("keyPassword", "ANDROID_KEY_PASSWORD")
            if (f != null && f.isFile && p != null && a != null && k != null) {
                storeFile = f
                storePassword = p
                keyAlias = a
                keyPassword = k
            } else {
                logger.warn("=========================================================================================")
                logger.warn("WARNING: Release keystore file or key.properties credentials not found.")
                logger.warn("Building release APK/AAB with default debug signature.")
                logger.warn("Google Play requires the app to be signed with the original release key certificate:")
                logger.warn("Expected SHA1: 11:4A:B8:41:2E:9C:60:58:4E:1A:53:64:24:00:FE:C4:DE:4D:C8:6B")
                logger.warn("Please create mobile/android/key.properties using mobile/android/key.properties.example")
                logger.warn("=========================================================================================")
            }
        }
    }

    buildTypes {
        release {
            val releaseConfig = signingConfigs.findByName("release")
            signingConfig = if (releaseConfig?.storeFile != null) releaseConfig else signingConfigs.getByName("debug")
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
