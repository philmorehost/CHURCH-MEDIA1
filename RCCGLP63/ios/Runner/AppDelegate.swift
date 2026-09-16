import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    let channel = FlutterMethodChannel(
      name: "church_media/share",
      binaryMessenger: engineBridge.applicationRegistrar.messenger
    )
    channel.setMethodCallHandler { call, result in
      guard call.method == "shareText" else {
        result(FlutterMethodNotImplemented)
        return
      }
      guard
        let args = call.arguments as? [String: Any],
        let text = args["text"] as? String
      else {
        result(FlutterError(code: "bad_args", message: "text is required", details: nil))
        return
      }
      let uri = args["uri"] as? String
      var items: [Any] = [text]
      if let uri = uri, !uri.isEmpty, let url = URL(string: uri) {
        items.append(url)
      }
      DispatchQueue.main.async {
        let activity = UIActivityViewController(activityItems: items, applicationActivities: nil)
        guard let root = self.keyRootViewController() else {
          result(FlutterError(code: "no_window", message: "No window to present from", details: nil))
          return
        }
        if let popover = activity.popoverPresentationController {
          popover.sourceView = root.view
          popover.sourceRect = CGRect(
            x: root.view.bounds.midX, y: root.view.bounds.midY, width: 0, height: 0)
          popover.permittedArrowDirections = []
        }
        root.present(activity, animated: true, completion: nil)
      }
      result(nil)
    }
  }

  private func keyRootViewController() -> UIViewController? {
    for scene in UIApplication.shared.connectedScenes {
      guard let windowScene = scene as? UIWindowScene, scene.activationState == .foregroundActive else {
        continue
      }
      if let root = windowScene.windows.first(where: { $0.isKeyWindow })?.rootViewController {
        return root
      }
    }
    return nil
  }
}
