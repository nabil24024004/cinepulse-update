package com.example.ui.screens.settings

import android.content.Context
import android.widget.Toast
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.OpenInNew
import androidx.compose.material.icons.filled.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.ui.components.*
import com.example.ui.theme.*
import com.example.util.DevCreditManager

@Composable
fun SettingsScreen(
    onBackClick: () -> Unit,
    onClearHistory: () -> Unit,
    onClearWatchlist: () -> Unit,
    onOpenDownloads: () -> Unit,
    onShowGetStarted: () -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    var showInAppBrowser by remember { mutableStateOf(false) }
    var browserUrl by remember { mutableStateOf(DevCreditManager.DEV_URL) }
    var browserTitle by remember { mutableStateOf("Ahmed Ishtiaque Zarir") }

    var selectedQuality by remember { mutableStateOf("1080p") }
    var autoPlayNext by remember { mutableStateOf(true) }
    var cacheClearedMessage by remember { mutableStateOf<String?>(null) }

    if (showInAppBrowser) {
        InAppWebBrowserDialog(
            url = browserUrl,
            title = browserTitle,
            onDismiss = { showInAppBrowser = false }
        )
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(JetBlack)
    ) {
        // ── Top Bar ─────────────────────────────────────────────────────────
        Box(
            modifier = Modifier
                .fillMaxWidth()
                .statusBarsPadding()
                .padding(horizontal = 16.dp, vertical = 12.dp)
        ) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.CenterVertically
            ) {
                GlassIconButton(
                    icon = Icons.AutoMirrored.Filled.ArrowBack,
                    contentDescription = "Back",
                    onClick = onBackClick
                )
                Spacer(Modifier.width(14.dp))
                Column {
                    Text(
                        text = "Settings & About",
                        color = PureWhite,
                        fontSize = 20.sp,
                        fontWeight = FontWeight.Bold
                    )
                    Text(
                        text = "CinePulse Ultimate",
                        color = TextSecondary,
                        fontSize = 12.sp
                    )
                }
            }
        }

        // ── Content ─────────────────────────────────────────────────────────
        LazyColumn(
            modifier = Modifier.fillMaxSize(),
            contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 120.dp),
            verticalArrangement = Arrangement.spacedBy(16.dp)
        ) {
            // Group: Welcome & Onboarding
            item {
                SettingsSectionHeader(title = "Welcome")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(18.dp)) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Box(
                                modifier = Modifier
                                    .size(36.dp)
                                    .clip(RoundedCornerShape(10.dp))
                                    .background(Brush.horizontalGradient(BrandButtonGradient)),
                                contentAlignment = Alignment.Center
                            ) {
                                Icon(
                                    Icons.Default.Info,
                                    contentDescription = null,
                                    tint = PureWhite,
                                    modifier = Modifier.size(20.dp)
                                )
                            }
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text(
                                    text = "Get Started Guide",
                                    color = PureWhite,
                                    fontWeight = FontWeight.Bold,
                                    fontSize = 15.sp
                                )
                                Text(
                                    text = "Overview of features, gestures and streaming tips",
                                    color = TextSecondary,
                                    fontSize = 12.sp
                                )
                            }
                        }
                        Spacer(Modifier.height(14.dp))
                        SolidGlassSecondaryButton(
                            text = "View Get Started Tour",
                            onClick = onShowGetStarted,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                }
            }

            // Group: Playback & Streaming
            item {
                SettingsSectionHeader(title = "Playback & Streaming")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        SettingsOptionRow(
                            icon = Icons.Default.HighQuality,
                            title = "Default Stream Quality",
                            subtitle = "Preferred video resolution when playback starts"
                        ) {
                            Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                                listOf("1080p", "720p", "480p").forEach { res ->
                                    val isSel = selectedQuality == res
                                    GlassChip(
                                        text = res,
                                        selected = isSel,
                                        onClick = {
                                            selectedQuality = res
                                            Toast.makeText(context, "Preferred quality set to $res", Toast.LENGTH_SHORT).show()
                                        }
                                    )
                                }
                            }
                        }

                        Spacer(Modifier.height(14.dp))
                        HorizontalDivider(color = Color.White.copy(alpha = 0.07f))
                        Spacer(Modifier.height(14.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Row(
                                modifier = Modifier.weight(1f),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Icon(Icons.Default.PlayCircle, null, tint = PureWhite, modifier = Modifier.size(20.dp))
                                Spacer(Modifier.width(12.dp))
                                Column {
                                    Text("Autoplay Next Episode", color = PureWhite, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                                    Text("Seamlessly continue series playback", color = TextSecondary, fontSize = 12.sp)
                                }
                            }
                            Switch(
                                checked = autoPlayNext,
                                onCheckedChange = { autoPlayNext = it },
                                colors = SwitchDefaults.colors(
                                    checkedThumbColor = PureWhite,
                                    checkedTrackColor = BrandCoral,
                                    uncheckedThumbColor = TextSecondary,
                                    uncheckedTrackColor = Color.White.copy(alpha = 0.1f)
                                )
                            )
                        }
                    }
                }
            }

            // Group: Offline & Downloads
            item {
                SettingsSectionHeader(title = "Offline Playback")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Icon(Icons.Default.DownloadDone, null, tint = PureWhite, modifier = Modifier.size(22.dp))
                            Spacer(Modifier.width(12.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text("Downloaded Media", color = PureWhite, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                                Text("Stored in Downloads/CinePulse with zero internet barrier", color = TextSecondary, fontSize = 12.sp)
                            }
                        }
                        Spacer(Modifier.height(14.dp))
                        SolidGlassButton(
                            text = "Open Downloaded Movies Player",
                            onClick = onOpenDownloads,
                            leadingIcon = Icons.Default.PlayArrow,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                }
            }

            // Group: Storage & Caching
            item {
                SettingsSectionHeader(title = "Cache & Storage")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(16.dp)) {
                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text("Home Feed Cache", color = PureWhite, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                                Text("Instant 0ms launch & offline fallback cache", color = TextSecondary, fontSize = 12.sp)
                            }
                            SolidGlassSecondaryButton(
                                text = "Clear Cache",
                                onClick = {
                                    try {
                                        context.cacheDir.resolve("cached_home_feed.json").delete()
                                        cacheClearedMessage = "Home feed cache cleared"
                                        Toast.makeText(context, "Cache cleared successfully", Toast.LENGTH_SHORT).show()
                                    } catch (_: Exception) {}
                                }
                            )
                        }

                        Spacer(Modifier.height(14.dp))
                        HorizontalDivider(color = Color.White.copy(alpha = 0.07f))
                        Spacer(Modifier.height(14.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text("Watch History & Progress", color = PureWhite, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                                Text("Remove all saved playback resume positions", color = TextSecondary, fontSize = 12.sp)
                            }
                            SolidGlassSecondaryButton(
                                text = "Clear History",
                                onClick = onClearHistory
                            )
                        }

                        Spacer(Modifier.height(14.dp))
                        HorizontalDivider(color = Color.White.copy(alpha = 0.07f))
                        Spacer(Modifier.height(14.dp))

                        Row(
                            modifier = Modifier.fillMaxWidth(),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column(modifier = Modifier.weight(1f)) {
                                Text("Personal Watchlist", color = PureWhite, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                                Text("Clear all bookmarked titles", color = TextSecondary, fontSize = 12.sp)
                            }
                            SolidGlassSecondaryButton(
                                text = "Clear List",
                                onClick = onClearWatchlist
                            )
                        }
                    }
                }
            }

            // Group: Developer Credits & Component Attribution
            item {
                SettingsSectionHeader(title = "Developer Credits & Attribution")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(18.dp)) {
                        // Lead Developer
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            modifier = Modifier.fillMaxWidth()
                        ) {
                            Box(
                                modifier = Modifier
                                    .size(42.dp)
                                    .clip(CircleShape)
                                    .background(SolidGlassControlBg)
                                    .border(1.dp, SolidGlassBorderBrush, CircleShape),
                                contentAlignment = Alignment.Center
                            ) {
                                Icon(Icons.Default.Code, null, tint = BrandCoral, modifier = Modifier.size(22.dp))
                            }
                            Spacer(Modifier.width(14.dp))
                            Column(modifier = Modifier.weight(1f)) {
                                Text("Ahmed Ishtiaque Zarir", color = PureWhite, fontSize = 15.sp, fontWeight = FontWeight.Bold)
                                Text("Lead Creator & Architect", color = TextSecondary, fontSize = 12.sp)
                            }
                            SolidGlassSecondaryButton(
                                text = "Portfolio",
                                onClick = {
                                    browserUrl = DevCreditManager.DEV_URL
                                    browserTitle = "Ahmed Ishtiaque Zarir"
                                    showInAppBrowser = true
                                }
                            )
                        }

                        Spacer(Modifier.height(16.dp))
                        HorizontalDivider(color = Color.White.copy(alpha = 0.07f))
                        Spacer(Modifier.height(14.dp))

                        // Component: Solid Glass
                        ComponentCreditRow(
                            name = "Solid Glass",
                            author = "deeplethe",
                            desc = "Tactile physics, edge rim, hairline borders, and shadow hierarchy design system.",
                            url = "https://github.com/deeplethe/solid-glass",
                            onOpen = {
                                browserUrl = "https://github.com/deeplethe/solid-glass"
                                browserTitle = "Solid Glass by deeplethe"
                                showInAppBrowser = true
                            }
                        )

                        Spacer(Modifier.height(14.dp))
                        HorizontalDivider(color = Color.White.copy(alpha = 0.07f))
                        Spacer(Modifier.height(14.dp))

                        // Component: Liquid Glass Android
                        ComponentCreditRow(
                            name = "Liquid Glass Android",
                            author = "QWEA0",
                            desc = "Real-time SDF refraction optics, chromatic dispersion, and backdrop blur engine.",
                            url = "https://github.com/QWEA0/Liquid-Glass-Android",
                            onOpen = {
                                browserUrl = "https://github.com/QWEA0/Liquid-Glass-Android"
                                browserTitle = "Liquid Glass Android by QWEA0"
                                showInAppBrowser = true
                            }
                        )

                        Spacer(Modifier.height(14.dp))
                        HorizontalDivider(color = Color.White.copy(alpha = 0.07f))
                        Spacer(Modifier.height(14.dp))

                        // Component: Core Libraries
                        Text("Frameworks & Technologies", color = PureWhite, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(4.dp))
                        Text(
                            text = "AndroidX Media3 ExoPlayer, Jetpack Compose Material 3, Kotlin Coroutines & Flow, Coil Image Loader, Square Retrofit & OkHttp, Moshi, Room SQLite Database.",
                            color = TextSecondary,
                            fontSize = 12.sp,
                            lineHeight = 17.sp
                        )
                    }
                }
            }

            // Group: Support Developer & Donations
            item {
                SettingsSectionHeader(title = "Support Developer & Donations")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(18.dp)) {
                        Text(
                            text = "Help Upgrade Cloud Infrastructure",
                            color = PureWhite,
                            fontSize = 14.5.sp,
                            fontWeight = FontWeight.Bold
                        )
                        Spacer(Modifier.height(4.dp))
                        Text(
                            text = "CinePulse is free and open-source. Donations directly support upgrading the backend API from free-tier hosting to dedicated high-speed cloud servers.",
                            color = TextSecondary,
                            fontSize = 12.sp,
                            lineHeight = 16.sp
                        )
                        Spacer(Modifier.height(14.dp))
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(12.dp))
                                .background(SolidGlassControlBg)
                                .border(1.dp, SolidGlassBorderBrush, RoundedCornerShape(12.dp))
                                .clickable {
                                    browserUrl = "mailto:ahmedishtiaquezarir@gmail.com"
                                    browserTitle = "Donate via Email"
                                    showInAppBrowser = true
                                }
                                .padding(horizontal = 14.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Email Developer (Donations)", color = PureWhite, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                                Text("ahmedishtiaquezarir@gmail.com", color = BrandCoral, fontSize = 11.5.sp)
                            }
                            Icon(Icons.AutoMirrored.Filled.OpenInNew, contentDescription = null, tint = PureWhite, modifier = Modifier.size(16.dp))
                        }
                        Spacer(Modifier.height(10.dp))
                        Row(
                            modifier = Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(12.dp))
                                .background(SolidGlassControlBg)
                                .border(1.dp, SolidGlassBorderBrush, RoundedCornerShape(12.dp))
                                .clickable {
                                    browserUrl = "https://t.me/ahmedishtiaquezarir"
                                    browserTitle = "Telegram Contact"
                                    showInAppBrowser = true
                                }
                                .padding(horizontal = 14.dp, vertical = 10.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceBetween
                        ) {
                            Column {
                                Text("Telegram Contact", color = PureWhite, fontSize = 13.sp, fontWeight = FontWeight.SemiBold)
                                Text("t.me/ahmedishtiaquezarir (@ahmedishtiaquezarir)", color = BrandCoral, fontSize = 11.5.sp)
                            }
                            Icon(Icons.AutoMirrored.Filled.OpenInNew, contentDescription = null, tint = PureWhite, modifier = Modifier.size(16.dp))
                        }
                    }
                }
            }

            // Group: Legal & Privacy Disclosures
            item {
                SettingsSectionHeader(title = "Privacy, Legality & Hosting")
                SolidGlassCard(cornerRadius = 20.dp) {
                    Column(modifier = Modifier.padding(18.dp)) {
                        LegalNoticeBullet(
                            title = "Non-Hosting Policy & Content Legality",
                            desc = "CinePulse does not host, upload, or store any media files or illegal streams on its servers. The API functions purely as an educational metadata indexer. Streams resolve client-side from external third-party hosts."
                        )
                        Spacer(Modifier.height(10.dp))
                        LegalNoticeBullet(
                            title = "Free Cloud Hosting & DNS Advice",
                            desc = "The API runs on free community cloud hosting. If your local ISP or regional firewall blocks or drops connections, configure a custom secure DNS (Cloudflare 1.1.1.1, Google 8.8.8.8) or connect via VPN."
                        )
                        Spacer(Modifier.height(10.dp))
                        LegalNoticeBullet(
                            title = "Zero User Tracking",
                            desc = "The CinePulse API is completely free of tracking, telemetry, user profiling, and analytics collection. All history stays on your device."
                        )
                        Spacer(Modifier.height(10.dp))
                        LegalNoticeBullet(
                            title = "Non-Commercial Usage",
                            desc = "The API service and media index are provided strictly for free, non-commercial, personal, and educational use."
                        )
                        Spacer(Modifier.height(10.dp))
                        LegalNoticeBullet(
                            title = "Client Security Disclaimer",
                            desc = "The application source code is private. Client-side stream tokens and endpoints cannot be cryptographically secured against reverse-engineering on untrusted devices."
                        )
                        Spacer(Modifier.height(10.dp))
                        LegalNoticeBullet(
                            title = "Component Thanks",
                            desc = "Special gratitude to all open-source maintainers, contributors, and component designers who made this project possible."
                        )
                    }
                }
            }

            // Footer
            item {
                Column(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(vertical = 12.dp),
                    horizontalAlignment = Alignment.CenterHorizontally
                ) {
                    Text(
                        text = "CinePulse Ultimate • Version 1.0",
                        color = TextMuted,
                        fontSize = 12.sp
                    )
                    Text(
                        text = "Solid Glass + Liquid Glass Edition",
                        color = TextMuted,
                        fontSize = 11.sp
                    )
                }
            }
        }
    }
}

@Composable
private fun SettingsSectionHeader(title: String) {
    Text(
        text = title.uppercase(),
        color = TextSecondary,
        fontSize = 11.5.sp,
        fontWeight = FontWeight.Bold,
        letterSpacing = 1.sp,
        modifier = Modifier.padding(start = 4.dp, bottom = 6.dp)
    )
}

@Composable
private fun SettingsOptionRow(
    icon: ImageVector,
    title: String,
    subtitle: String,
    trailing: @Composable () -> Unit
) {
    Column {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(icon, null, tint = PureWhite, modifier = Modifier.size(19.dp))
            Spacer(Modifier.width(10.dp))
            Column {
                Text(title, color = PureWhite, fontSize = 14.5.sp, fontWeight = FontWeight.SemiBold)
                Text(subtitle, color = TextSecondary, fontSize = 12.sp)
            }
        }
        Spacer(Modifier.height(10.dp))
        trailing()
    }
}

@Composable
private fun ComponentCreditRow(
    name: String,
    author: String,
    desc: String,
    url: String,
    onOpen: () -> Unit
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.Top,
        horizontalArrangement = Arrangement.SpaceBetween
    ) {
        Column(modifier = Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(name, color = PureWhite, fontSize = 14.sp, fontWeight = FontWeight.Bold)
                Spacer(Modifier.width(8.dp))
                SolidGlassTag(text = author, textColor = BrandCoral)
            }
            Spacer(Modifier.height(3.dp))
            Text(desc, color = TextSecondary, fontSize = 12.sp, lineHeight = 16.sp)
        }
        Spacer(Modifier.width(10.dp))
        Box(
            modifier = Modifier
                .size(34.dp)
                .clip(CircleShape)
                .background(SolidGlassControlBg)
                .border(1.dp, SolidGlassBorderBrush, CircleShape)
                .clickable(onClick = onOpen),
            contentAlignment = Alignment.Center
        ) {
            Icon(
                Icons.AutoMirrored.Filled.OpenInNew,
                contentDescription = "Open $name",
                tint = PureWhite,
                modifier = Modifier.size(16.dp)
            )
        }
    }
}

@Composable
private fun LegalNoticeBullet(title: String, desc: String) {
    Column {
        Text(title, color = PureWhite, fontSize = 13.5.sp, fontWeight = FontWeight.Bold)
        Spacer(Modifier.height(2.dp))
        Text(desc, color = TextSecondary, fontSize = 12.sp, lineHeight = 16.sp)
    }
}
