<div align="center">

# CinePulse Ultimate (Solid & Liquid Glass Edition)

**Unified Ultra-Lightweight Streaming Ecosystem for Android & Web**  
*Crafted with Jetpack Compose, Media3 ExoPlayer, and Pure Solid + Liquid Glass Architecture*

[![Kotlin](https://img.shields.io/badge/Kotlin-2.0.0-7F52FF?style=for-the-badge&logo=kotlin&logoColor=white)](https://kotlinlang.org)
[![Android](https://img.shields.io/badge/Android-7.0%2B%20(API%2024%2B)-3DDC84?style=for-the-badge&logo=android&logoColor=white)](https://developer.android.com)
[![Jetpack Compose](https://img.shields.io/badge/Jetpack%20Compose-Material%203-4285F4?style=for-the-badge&logo=jetpackcompose&logoColor=white)](https://developer.android.com/jetpack/compose)
[![Media3](https://img.shields.io/badge/Media3-ExoPlayer-E50914?style=for-the-badge&logo=youtube&logoColor=white)](https://developer.android.com/guide/topics/media/media3)
[![License: MIT / Modding](https://img.shields.io/badge/License-MIT%20%2F%20Modding-blue?style=for-the-badge)](LICENSE)
[![APK Size](https://img.shields.io/badge/APK%20Size-~3.9%20MB-success?style=for-the-badge)](release/cinepulse-v1.0-release.apk)

<br/>

[Download Latest APK](release/cinepulse-v1.0-release.apk) • [Features](#key-features) • [Design System](#solid-and-liquid-glass-architecture) • [Web & App Symmetry](#ecosystem-symmetry) • [Credits & Attributions](#developer-credits--attributions) • [Build from Source](#building-from-source)

</div>

---

## Overview

**CinePulse Ultimate** is an open-source, dual-platform media streaming ecosystem designed for speed, visual clarity, and resource efficiency. Comprising both a native Android application and a single-file PHP web application, CinePulse provides a 100% symmetric user experience across mobile and browser environments.

The project implements a hybrid **Solid Glass & Liquid Glass UI framework**, fusing physical edge reflections, inner hairlines, and contact shadows with translucent specular highlights and fluid spring physics. CinePulse is engineered to run seamlessly across all devices, from high-end displays to memory-constrained hardware (2GB RAM, Android 7.0+), maintaining an ultra-lightweight APK footprint of ~3.9 MB.

---

## Key Features

- **Solid & Liquid Glass Hybrid Design:** Translucent frosted materials, physical rim highlights, inner hairlines, contact shadows, and responsive touch springs without heavy GPU load.
- **Strict 3-Color Cinema Palette:**
  - **CinePulse Crimson Red (`#EE1E50` / `#FF2A55`):** High-energy primary cinema accent.
  - **True OLED Pitch Black (`#000000`):** Pure black surfaces for maximum power efficiency and infinite contrast.
  - **Crisp Pure White (`#FFFFFF`):** High-readability typography, specular borders, and clean vector iconography.
- **Ultra-Low Memory & Compact Footprint:** Releases maintain a compact ~3.9 MB binary size with aggressive ProGuard optimization and zero redundant dependencies.
- **Advanced Media3 ExoPlayer Engine:**
  - Adaptive 1080p MP4 streaming with instant seeking.
  - Picture-in-Picture (PiP) support with automatic audio termination upon window dismissal.
  - Multi-language subtitle engine with auto-selection and 250ms sync intervals.
  - Comprehensive player controls: seekbar capsule with buffer track, volume/speed/quality selectors, and 10s rewind/forward.
- **Offline Barrier & Downloaded Media Player:**
  - Clean offline detection banner allowing seamless access to cached titles and local media when network connectivity is lost.
  - Dedicated Offline Downloads manager and built-in local media player for downloaded MP4 content.
- **Homepage Caching & Real-Time Search:**
  - Instant application boot via persistent disk caching for the homepage catalog.
  - Strictly real-time, uncached streaming and search queries for live accuracy.
- **Zero-Emoji Interface:** Clean, professional aesthetics utilizing vector SVG and Android Vector Drawables across all screens, dialogs, badges, and documentation.
- **Dedicated Settings & About Screen:** Startup credit popups have been removed in favor of a centralized Settings and Credits screen, complete with cache controls, data clearing, and an onboarding Get Started banner toggle.

---

## Ecosystem Symmetry

The Android app and the PHP webapp are designed as two halves of the same unified ecosystem:

| Feature | Android Client (`cinepulse-main/app`) | Web Application (`cinepulse-main/web/index.php`) |
|---|---|---|
| **Design Language** | Solid Glass & Liquid Glass Compose Components | Pure CSS Solid Glass & Liquid Glass with SVG Maps |
| **Navigation** | Floating iOS Frosted Dock (Home, Browse, Search, Library, Settings) | Frosted Floating Bottom Dock & Top Desktop Pill |
| **Video Player** | ExoPlayer Media3 with Glass Tool Capsule & Seekbar | Custom HTML5 Video Player with Glass Capsule & Controls |
| **Catalog Caching** | Local JSON Feed Disk Cache with Instant Launch | localStorage Feed Cache with Instant Fallback |
| **Search & Stream** | Uncached Real-Time Network Execution | Uncached Real-Time Network Execution |
| **Offline Handling** | Dynamic Connectivity Banner & Offline Downloads Player | Offline Indicator Banner & Local Storage Media List |
| **Startup Behavior** | Direct Launch without Popups (Credit in Settings) | Direct Launch without Popups (Credit in Settings) |
| **Attribution** | Centralized in Settings Screen | Centralized in Settings Screen & Full Credit Sheet |

---

## Solid and Liquid Glass Architecture

CinePulse synthesizes two complementary glassmorphism design systems:

1. **Solid Glass Tokens (Physical Edge Hierarchy):**
   - **Outer Rim Reflection:** Top physical edge highlight reflecting directional ceiling lighting (`box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.22)`).
   - **Inner Hairline Border:** Subtle 1px translucent border defining container bounds (`box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.08)`).
   - **Contact Drop Shadow:** Deep ambient drop shadow anchoring elements to the OLED black plane.
   - **Structural Hierarchy:** Distinct visual thickness for Panels, Cards, Popovers, and Interactive Controls.
2. **Liquid Glass Engine (Fluid Dynamic Behavior):**
   - **Backdrop Refraction:** Real-time translucent background blur and chromatic dispersion.
   - **Specular Highlight Tracking:** Smooth radial glare tracking user touches or cursor coordinates.
   - **Spring Physics:** Interactive buttons and poster cards employ bouncy physics damping (`DampingRatioMediumBouncy` + `StiffnessMedium`).

---

## Developer Credits & Attributions

CinePulse is made possible through the contributions of exceptional developers and open-source foundations:

- **Ahmed Ishtiaque Zarir:** Founder, Core Architecture & API Integration. ([Portfolio](https://ahmedishtiaquezarir.pro.bd/))
- **QWEA0:** Creator of [Liquid-Glass-Android](https://github.com/QWEA0/Liquid-Glass-Android) (translucent backdrop blur and specular refraction engine).
- **deeplethe:** Creator of [Solid Glass](https://github.com/deeplethe/solid-glass) (physical rim highlights, inner hairlines, and design token hierarchy).
- **Google AndroidX Media3 Team:** High-performance ExoPlayer media engine, resilient extractors, and subtitle parsers.
- **Coil Contributors:** Coroutine-based asynchronous image loading pipeline.
- **shadcn / Radix UI:** Inspiration for clean, unstyled, accessible UI components.

---

## Privacy, Security & Legal Terms

### Content Indexing & Non-Hosting Disclosure
- **Pure Indexing & Aggregation:** CinePulse is an open-source educational media exploration client and metadata indexer.
- **Zero Media Hosting:** The author, developer, and CinePulse servers do **not** host, upload, store, encode, or distribute any video files, movie streams, or copyrighted media.
- **Client-Side Resolution:** All stream endpoints and metadata are parsed from public third-party sources and resolved directly by the user's client device. The CinePulse API server serves purely as a stateless bridge to provide index data. CinePulse functions strictly within the legal bounds of internet search and aggregation protocols and does not participate in illegal file hosting or media piracy.

### Free Cloud Hosting & Regional Connectivity Notice
- **Community Free-Tier Infrastructure:** The live CinePulse API (`movieapi.zarhosting.dpdns.org`) is currently deployed on free-tier cloud hosting infrastructure to maintain 100% free, non-commercial public access.
- **Regional Restrictions & ISP Blocks:** Due to geo-routing restrictions, free-tier IP reputation blocks, or regional ISP filtering, users in certain countries or networks may encounter connection drops or blocked API requests.
- **Bypassing Regional Blocks:** If the API does not load in your region, we recommend configuring a secure custom DNS (such as Cloudflare DNS `1.1.1.1` or Google Public DNS `8.8.8.8`) or using a reputable VPN service to establish uninterrupted connectivity.

### Privacy & Data Ownership
- **Zero User Tracking:** CinePulse collects no personal telemetry, keeps no request logs linked to personal identities, and maintains zero tracking databases. All watch history, resume positions, and watchlist entries remain strictly on your local device (`Room` SQLite database on Android, `localStorage` on Web).
- **Non-Commercial Educational Scope:** The CinePulse API and client applications are distributed free of charge strictly for non-commercial educational and research purposes.
- **Client Security Disclosure:** Client source code is private and client connections cannot be cryptographically secured on untrusted public networks. Users are advised to connect over secure networks.

---

## Support the Developer & Donations

Maintaining the live API server, optimizing stream parsers, and keeping the open-source client updated requires continuous infrastructure costs. If you find CinePulse useful and want to help upgrade the server from free hosting to dedicated, high-speed cloud instances:

- **Developer:** Ahmed Ishtiaque Zarir
- **Email (Donations & Inquiries):** `ahmedishtiaquezarir@gmail.com`
- **Telegram Contact:** [t.me/ahmedishtiaquezarir](https://t.me/ahmedishtiaquezarir) (`@ahmedishtiaquezarir`)
- **Portfolio & Official Site:** [ahmedishtiaquezarir.pro.bd](https://ahmedishtiaquezarir.pro.bd/)

Every contribution helps keep the CinePulse ecosystem alive, fast, and free for the community.

---

## Building from Source

### Prerequisites
- **JDK:** OpenJDK 17 or OpenJDK 21
- **Android SDK:** Compile SDK 36, Minimum SDK 24 (Android 7.0+)
- **Gradle:** 9.3.1 (included via Gradle Wrapper)

### Quick Start
```bash
# Clone the repository
git clone https://github.com/ImAhmedZarir/cinepulse.git
cd cinepulse

# Build Debug APK
./gradlew assembleDebug

# Build Optimized Release APK
./gradlew assembleRelease
```

The compiled release artifact will be generated at:
```
app/build/outputs/apk/release/app-release.apk
```

---

## Project Structure

```
cinepulse/
├── app/
│   ├── src/main/java/com/example/
│   │   ├── MainActivity.kt               # Main navigation, Edge-to-Edge & PiP
│   │   ├── MainViewModel.kt              # App state, offline caching & playback controller
│   │   ├── data/
│   │   │   ├── local/                    # Room SQLite database (History & Watchlist)
│   │   │   ├── model/                    # Kotlin Moshi data models
│   │   │   └── repository/               # Repository with offline cache fallback
│   │   ├── ui/
│   │   │   ├── components/               # Solid Glass & Liquid Glass UI primitives
│   │   │   ├── player/                   # ExoPlayer engine, Subtitles, Gestures
│   │   │   ├── screens/                  # Home, Catalog, Detail, Search, Library, Settings
│   │   │   └── theme/                    # Color tokens, Solid Glass brushes, typography
│   │   └── util/                         # Download helper & device utilities
│   ├── build.gradle.kts                  # App dependencies & ProGuard config
│   └── proguard-rules.pro                # Aggressive code & resource shrinking
├── web/
│   └── index.php                         # Unified single-file PHP web application
├── release/
│   ├── cinepulse-v1.0-release.apk        # Pre-built signed release APK
│   └── README.md                         # Release notes & SHA-256 verification
├── CONTRIBUTING.md                       # Contributor guide & PR standards
├── CinePulse_API_Docs.md                 # API specification & endpoint documentation
├── gradle/                               # Wrapper & version catalogs
└── LICENSE                               # Client Modding License
```

---

## License

This project is licensed under the **MIT / CinePulse Client Modding License** — see the [LICENSE](LICENSE) file for complete details.

You are welcome to fork, customize, and study the client applications. Respect the cloud media infrastructure and enjoy streaming!
