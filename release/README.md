# CinePulse Release Artifacts (Solid & Liquid Glass Edition)

## Release Artifact Details
- **File Name:** `cinepulse-v1.0-release.apk`
- **File Size:** ~3.92 MB
- **Minimum OS Version:** Android 7.0 (API 24)
- **Target OS Version:** Android 16 (API 36)
- **Architecture:** Universal APK (ARM64, ARMv7, x86_64)

---

## Design and Visual Highlights
- **Strict 3-Color Cinema Palette:**
  - CinePulse Crimson Red (`#EE1E50` / `#FF2A55`)
  - True OLED Pitch Black (`#000000`)
  - Crisp Pure White (`#FFFFFF`)
- **Solid Glass & Liquid Glass UI:** Translucent specular borders, physical rim highlights, inner hairlines, and fluid spring physics on all interactive elements.
- **Zero-Emoji User Interface:** Professional vector iconography across all components, badges, and menus.
- **Unified Ecosystem Symmetry:** 100% visual and functional alignment with the CinePulse Web application.

---

## Performance and Media Engine
- **AndroidX Media3 ExoPlayer:** Adaptive 1080p MP4 streaming with instant seeking and resilient container parsers.
- **Picture-in-Picture (PiP):** Dedicated floating window mode with automated lifecycle audio termination upon dismissal.
- **Subtitles and Multi-Audio:** UTF-8 SRT subtitle parser with auto-selected default language and 250ms sync intervals.
- **Offline Barrier & Downloads:** Seamless offline fallback banner and integrated local downloaded media manager.
- **Homepage Caching & Real-Time Search:** Instant launch via disk-cached catalog feed, with strictly uncached real-time search and streaming.
- **Ultra-Low Memory Footprint:** Tuned for 60fps operation on low-RAM devices without GPU strain.

---

## Verification
You can verify the integrity of the release APK using SHA-256 checksum tools:

Linux / macOS:
```bash
sha256sum cinepulse-v1.0-release.apk
```

Windows PowerShell:
```powershell
Get-FileHash cinepulse-v1.0-release.apk -Algorithm SHA256
```
