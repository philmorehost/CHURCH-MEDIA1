package com.livingwordgospelmission.app

import android.content.Intent
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

class MainActivity : FlutterActivity() {
    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, CHANNEL).setMethodCallHandler { call, result ->
            when (call.method) {
                "shareText" -> {
                    val text = call.argument<String>("text") ?: ""
                    val uri = call.argument<String>("uri")
                    try {
                        val body = if (!uri.isNullOrEmpty()) "$text\n$uri" else text
                        val intent = Intent(Intent.ACTION_SEND).apply {
                            type = "text/plain"
                            putExtra(Intent.EXTRA_TEXT, body)
                        }
                        startActivity(Intent.createChooser(intent, "Share"))
                        result.success(true)
                    } catch (e: Exception) {
                        result.error("share_error", e.message, null)
                    }
                }

                "openTtsSettings" -> {
                    // The screen where voice data is installed — Android's "Text-to-speech
                    // output". It has to be opened by intent ACTION, not by a URI, which is
                    // why this cannot be done from Dart at all. The action is also not part of
                    // the public API — there is no Settings.ACTION_TTS_SETTINGS — so it is
                    // written out, and because it is not public a manufacturer's build may not
                    // have it.
                    try {
                        startActivity(Intent("com.android.settings.TTS_SETTINGS"))
                        result.success(true)
                    } catch (e: Exception) {
                        try {
                            // On Android 11 and later the speech output screen sits under
                            // Accessibility, so this lands one step away rather than nowhere.
                            startActivity(Intent(android.provider.Settings.ACTION_ACCESSIBILITY_SETTINGS))
                            result.success(true)
                        } catch (e2: Exception) {
                            // Report it honestly: the app then shows the reader how to get there
                            // by hand instead of leaving them on an unchanged page.
                            result.success(false)
                        }
                    }
                }

                else -> result.notImplemented()
            }
        }
    }

    companion object {
        // Must match the channel name in lib/services/share_service.dart.
        private const val CHANNEL = "livingword/share"
    }
}
