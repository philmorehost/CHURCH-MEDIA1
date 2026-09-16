package com.rccglp63yaya.church_media

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

                else -> result.notImplemented()
            }
        }
    }

    companion object {
        private const val CHANNEL = "church_media/share"
    }
}
