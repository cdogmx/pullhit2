# CardFoo mobile app (iOS + Android)

The native apps are the existing web app wrapped in a **Capacitor** shell. Because
CardFoo is a server-rendered **Inertia** app, the native WebView loads the live
Laravel site (`server.url` in `capacitor.config.ts`) and layers native
capabilities on top — right now the **card scanner uses the device camera**
natively (`resources/js/lib/native-camera.ts`), with the web `<input capture>` as
the fallback in a browser.

- **App ID:** `com.cardfoo.app`  •  **Name:** CardFoo
- **Loads:** `https://cardfoo.com` (override with `CAP_SERVER_URL` for local dev)

There is no offline JS bundle to ship — the UI always comes from the server, so
deploying web changes updates the app instantly (no store re-submit for UI-only
changes). Store re-submits are only needed for native changes (plugins, icons,
permissions, config).

## Prerequisites

- **Node** (already set up for the web build).
- **iOS:** a **Mac** with **Xcode** + CocoaPods, an Apple Developer account.
- **Android:** **Android Studio** + the Android SDK, a Google Play Console account.

## One-time setup (per machine)

The native project folders (`ios/`, `android/`) are generated locally — they need
the platform toolchains, so they aren't created in CI/Windows:

```bash
npm install
npx cap add ios        # macOS only
npx cap add android
```

Set the camera permission strings the OS requires (Capacitor scaffolds most, but
confirm):

- **iOS** `ios/App/App/Info.plist` → `NSCameraUsageDescription` = "Scan your cards
  to identify and value them." (and `NSPhotoLibraryUsageDescription` if you keep
  the photo-library picker).
- **Android** `android/app/src/main/AndroidManifest.xml` → `<uses-permission
  android:name="android.permission.CAMERA" />` (Capacitor's camera plugin adds it).

### Live scanner (continuous `getUserMedia`) — extra requirements

The **Live scan** mode runs a real-time camera preview *inside the WebView* via
`navigator.mediaDevices.getUserMedia` (not the single-shot Camera plugin), and
auto-captures cards. On top of the strings above:

- **iOS**: works in WKWebView on **iOS 14.5+**. `NSCameraUsageDescription` must be
  present or the stream is denied silently. Capacitor already sets
  `allowsInlineMediaPlayback` (our `<video>` is `playsInline muted`), so no extra
  config — the OS shows the camera prompt on first use.
- **Android**: the WebView must **grant the page's camera request**. Capacitor's
  bridge `WebChromeClient` handles `onPermissionRequest` (grants
  `RESOURCE_VIDEO_CAPTURE`) since Capacitor 3, but the app still needs the runtime
  `CAMERA` permission — keep the Camera plugin installed (it requests it) or
  request it on launch. Without the runtime grant the viewfinder shows the
  "Camera unavailable — upload from your library" fallback.

If `getUserMedia` is blocked (old OS, denied permission, desktop with no camera),
the scanner degrades gracefully to the gallery/upload flow and the Single/Bulk
modes — nothing hard-fails.

App icon + splash: drop a 1024×1024 `icon.png` (and optional `splash.png`) and run
`npx @capacitor/assets generate` to produce every size.

## Everyday build / run

```bash
npm run build          # build web assets (not strictly required in server mode,
                       # but keeps the fallback shell current)
npm run cap:sync       # copy config + native deps into ios/ and android/
npm run cap:ios        # open Xcode        →  Run on a simulator/device
npm run cap:android    # open Android Studio →  Run
```

**Local dev against your machine** (so you can test unreleased web changes):

```bash
# serve the app on your LAN, then:
CAP_SERVER_URL=http://192.168.1.20:8000 npm run cap:sync && npm run cap:ios
```

(`CAP_SERVER_URL` also enables cleartext so a `http://` LAN host works.)

## Releasing to the stores

1. **Version bump** in the native projects (Xcode target version/build; Android
   `versionCode`/`versionName`).
2. **iOS:** Xcode → *Product ▸ Archive* → distribute to **App Store Connect** →
   submit for review in App Store Connect.
3. **Android:** Android Studio → *Build ▸ Generate Signed Bundle (AAB)* → upload to
   the **Play Console** → roll out.

**App-review note:** a pure website wrapper can be rejected. CardFoo uses the
**native camera** for scanning (a real device capability), which is the usual
thing reviewers look for — keep the scanner prominent. Push notifications /
biometric unlock are easy future adds (`@capacitor/push-notifications`, etc.) if
more native surface is ever needed.

## What's wired today

- `capacitor.config.ts` — app id/name, `server.url`, splash.
- `resources/js/lib/native-camera.ts` — `isNativeApp()` + `captureNativePhoto()`.
- The scanner (`resources/js/pages/scan/index.tsx`) calls the native camera when
  running in the app, else the web file input.
- `npm run cap:sync` / `cap:ios` / `cap:android` scripts.
