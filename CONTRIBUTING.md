# Contributing to CinePulse

Thank you for your interest in contributing to CinePulse. CinePulse is an open-source, dual-platform media streaming ecosystem consisting of a native Android application (Jetpack Compose, AndroidX Media3) and a unified single-file PHP web application.

To maintain code quality, visual coherence, and high performance across both platforms, all contributors must adhere to the guidelines set out below.

---

## 1. Core Principles & Constraints

### Zero-Emoji Policy
CinePulse maintains a strict zero-emoji standard across the entire project. This applies to:
- Source code (Kotlin, Java, PHP, JavaScript, CSS, HTML, XML).
- User interface text, badges, dialogs, buttons, and error states.
- Documentation, Markdown files (`README.md`, `CONTRIBUTING.md`, `CinePulse_API_Docs.md`), commit messages, and PR descriptions.
Always use vector icons (SVG or Android Vector Drawables) or descriptive textual labels.

### Ecosystem Symmetry (Android & Web UI Parity)
The Android client and the PHP web application represent the same unified ecosystem. Every major feature, layout pattern, and interaction must remain in sync:
- If a UI component or control is added to the Android app, its counterpart must be added to the webapp, and vice versa.
- Navigation paradigms (Bottom Navigation Bar with Home, Browse, Search, Library, and Settings) must match.
- Color palettes, typography hierarchies, and glass materials must be identical across platforms.

### Architecture: Solid Glass & Liquid Glass Hybrid
CinePulse combines two complementary glassmorphism systems:
1. **Solid Glass Tokens (Physical Edge Hierarchy):**
   - Pure black OLED background (`#000000`).
   - Physical rim reflection (`0 1px 0 rgba(255, 255, 255, 0.22)` inset).
   - Inner hairline border (`inset 0 0 0 1px rgba(255, 255, 255, 0.08)`).
   - Contact drop shadows for depth and visual separation.
   - Distinct structural thickness hierarchy for Panels, Cards, Popovers, and Controls.
2. **Liquid Glass Components (Fluid Dynamic Behavior):**
   - Translucent backdrop refraction and blur.
   - Specular radial highlight tracking cursor/touch position.
   - Bouncy spring physics (`DampingRatioMediumBouncy` + `StiffnessMedium`) on interactive elements.

### Caching vs. Real-Time Rules
- **Home Feed & Catalog Metadata:** Must support instant-launch disk caching and offline access. On app startup, cached content renders immediately while a background network pull checks for fresh data.
- **Search & Streaming:** Must remain strictly real-time and uncached. Search queries and stream URLs must never be persisted to long-term disk storage to guarantee live source resolution.
- **Offline Barrier:** When no internet connection is detected, the app must present a clean offline barrier banner and give the user direct access to saved Watchlist items and downloaded offline media.

---

## 2. Developer Credits & Attributions

Every release and distribution of CinePulse honors the architects and open-source projects that make it possible:
- **Ahmed Ishtiaque Zarir:** Founder, Core Architecture & API Integration.
- **QWEA0:** Author of the Liquid-Glass-Android component library (backdrop blur & specular refraction).
- **deeplethe:** Author of Solid Glass (physical edge finishes, tokens, and structural hierarchy).
- **Google AndroidX Media3 / ExoPlayer Team:** Native video playback engine, resilient extractors, and subtitle handling.
- **Coil Contributors:** Efficient Kotlin Coroutine-backed image loading pipeline.
- **shadcn / Radix UI:** Accessible component architecture inspiration.

When contributing new components or third-party dependencies, ensure all authors are credited in the documentation and in the in-app Settings screen.

---

## 3. Privacy, Security, Legal Terms & Donations

When submitting changes that touch network calls or storage, verify that you adhere to CinePulse terms:
- **Non-Hosting & Legal Architecture:** CinePulse does not host, store, upload, or transmit any copyrighted audio or video media. The API functions purely as an educational metadata indexer and stateless proxy. Streams resolve directly from third-party hosts on the client side. CinePulse operates strictly within search engine and metadata aggregation legality.
- **Free Cloud Hosting & Regional DNS:** The live API runs on free cloud hosting infrastructure. Because free-tier IPs are occasionally throttled or blocked by regional firewalls and local ISPs, contributors should test with custom DNS (`1.1.1.1`, `8.8.8.8`) or VPNs when experiencing connection drops.
- **Zero Tracking:** CinePulse never tracks users, does not record IP addresses for analytics, and transmits no viewing activity to external telemetry servers. All watch history and progress reside strictly on local storage (`Room` SQLite on Android, `localStorage` on Web).
- **Non-Commercial Educational Use:** The CinePulse API and applications are free to use strictly for non-commercial educational purposes.
- **Client Security Disclosure:** Client source code is private and client connections cannot be cryptographically secured on untrusted environments. Contributors should avoid making cryptographic assumptions about client-side tokens.
- **Developer Donations & Infrastructure Support:** To support server hosting costs and upgrades, donations and inquiries can be sent directly to ImAhmedZarir (Ahmed Ishtiaque Zarir) via email (`ahmedishtiaquezarir@gmail.com`) or Telegram (`https://t.me/@imahmedzarir`).

---

## 4. Development Workflow

### Setting Up the Environment
- **Java:** OpenJDK 17 or OpenJDK 21.
- **Android SDK:** Compile SDK 36, Target SDK 36, Minimum SDK 24 (Android 7.0+).
- **Gradle:** Gradle 9.3.1 via the included `./gradlew` wrapper.
- **PHP:** PHP 8.2+ with `curl` and `openssl` extensions.

### Building and Testing
```bash
# Check Kotlin code formatting and build dry-run
./gradlew tasks --dry-run

# Assemble Debug APK for local testing
./gradlew assembleDebug

# Build release APK with ProGuard optimization
./gradlew assembleRelease
```

### Git Commit Guidelines
- Use clear, descriptive imperative commit messages.
- Do not use emojis in commit messages or pull request titles.
- Example: `feat(player): add dual-platform gesture controls for playback speed`
- Example: `fix(cache): enforce real-time bypass on search endpoint`

---

## 5. Submitting Pull Requests

1. Fork the repository and create your feature branch:
   ```bash
   git checkout -b feature/solid-glass-enhancement
   ```
2. Make your changes adhering to the Zero-Emoji and Dual-Platform symmetry rules.
3. Test your build on both Android and Web platforms.
4. Push your branch and open a Pull Request against the `main` branch.
5. Provide a clear summary of your changes, referencing any related issues.

Thank you for helping keep CinePulse clean, lightweight, and cinematic.
