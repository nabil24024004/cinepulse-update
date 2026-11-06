package com.example

import android.app.Activity
import android.app.PictureInPictureParams
import android.content.Context
import android.content.ContextWrapper
import android.content.pm.ActivityInfo
import android.content.res.Configuration
import android.os.Build
import android.os.Bundle
import android.util.Rational
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.animation.*
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.*
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import kotlinx.coroutines.delay
import com.example.ui.*
import com.example.ui.screens.catalog.CatalogScreen
import com.example.ui.screens.detail.DetailScreen
import com.example.ui.screens.home.HomeScreen
import com.example.ui.screens.library.LibraryScreen
import com.example.ui.screens.actor.ActorScreen
import com.example.ui.screens.search.SearchScreen
import com.example.ui.screens.settings.SettingsScreen
import com.example.ui.theme.*

class MainActivity : ComponentActivity() {
    var isInPipMode by mutableStateOf(false)
        private set

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MyApplicationTheme {
                MainApp(isInPipMode = isInPipMode)
            }
        }
    }

    override fun onPictureInPictureModeChanged(
        isInPictureInPictureMode: Boolean,
        newConfig: Configuration
    ) {
        super.onPictureInPictureModeChanged(isInPictureInPictureMode, newConfig)
        isInPipMode = isInPictureInPictureMode
        if (!isInPictureInPictureMode && !isChangingConfigurations) {
            // When leaving PiP, if the user closed/dismissed the popup window, immediately halt playback
            if (isFinishing || !lifecycle.currentState.isAtLeast(Lifecycle.State.STARTED)) {
                val viewModel = ViewModelProvider(this)[MainViewModel::class.java]
                viewModel.closePlayer()
            }
        }
    }

    override fun onStop() {
        super.onStop()
        // Stop playback when activity is stopped and not actively displaying PiP
        if ((!isInPipMode || isFinishing) && !isChangingConfigurations) {
            val viewModel = ViewModelProvider(this)[MainViewModel::class.java]
            viewModel.closePlayer()
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        val viewModel = ViewModelProvider(this)[MainViewModel::class.java]
        if (!isChangingConfigurations) {
            viewModel.closePlayer()
        }
    }

    override fun onUserLeaveHint() {
        super.onUserLeaveHint()
        val viewModel = ViewModelProvider(this)[MainViewModel::class.java]
        if (viewModel.detailPlayerState.value.isPlaying) {
            enterPipMode()
        }
    }

    fun enterPipMode() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            try {
                val params = PictureInPictureParams.Builder()
                    .setAspectRatio(Rational(16, 9))
                    .build()
                enterPictureInPictureMode(params)
            } catch (_: Exception) {}
        }
    }
}

@Composable
fun MainApp(
    viewModel: MainViewModel = viewModel(),
    isInPipMode: Boolean = false
) {
    var showSplash by rememberSaveable { mutableStateOf(true) }
    LaunchedEffect(Unit) {
        delay(850)
        showSplash = false
    }

    if (showSplash && !isInPipMode) {
        CinePulseSplash()
        return
    }

    val context = LocalContext.current
    val activity = remember(context) { context.findActivity() }
    val configuration = LocalConfiguration.current
    val isLandscape = configuration.orientation == Configuration.ORIENTATION_LANDSCAPE

    val currentTab by viewModel.currentTab.collectAsStateWithLifecycle()
    val homeState by viewModel.homeState.collectAsStateWithLifecycle()
    val catalogState by viewModel.catalogState.collectAsStateWithLifecycle()
    val searchState by viewModel.searchState.collectAsStateWithLifecycle()
    val detailState by viewModel.detailPlayerState.collectAsStateWithLifecycle()
    val actorState by viewModel.actorState.collectAsStateWithLifecycle()

    val watchHistory by viewModel.watchHistory.collectAsStateWithLifecycle()
    val watchlist by viewModel.watchlist.collectAsStateWithLifecycle()
    val isNetworkAvailable by viewModel.isNetworkAvailable.collectAsStateWithLifecycle()
    val isGetStartedDismissed by viewModel.isGetStartedDismissed.collectAsStateWithLifecycle()
    val downloadedItems by viewModel.downloadedItems.collectAsStateWithLifecycle()
    val defaultQuality by viewModel.defaultQuality.collectAsStateWithLifecycle()
    val autoplayNext by viewModel.autoplayNext.collectAsStateWithLifecycle()

    val isDetailOpen = detailState.slug != null
    val isActorOpen = actorState.name != null
    val isFullscreenPlaying = isLandscape && detailState.isPlaying
    val isTablet = configuration.smallestScreenWidthDp >= 600
    val showNavigation = !isInPipMode && !isDetailOpen && !isActorOpen && !isFullscreenPlaying
    val showTabletNavigation = isTablet && showNavigation

    val exoPlayer = remember(context) { viewModel.getPlayer(context) }

    // Handle back button: In landscape, exit fullscreen back to portrait first
    BackHandler(enabled = !isInPipMode && (isActorOpen || isLandscape || isDetailOpen || currentTab != AppTab.HOME)) {
        if (isActorOpen) {
            viewModel.closeActor()
        } else if (isLandscape) {
            activity?.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
        } else if (isDetailOpen) {
            viewModel.closeDetail()
        } else {
            viewModel.selectTab(AppTab.HOME)
        }
    }

    Scaffold(
        containerColor = JetBlack,
        bottomBar = {
            if (!isTablet && showNavigation) {
                Box(
                    modifier = Modifier
                        .fillMaxWidth()
                        .windowInsetsPadding(
                            WindowInsets.navigationBars.only(WindowInsetsSides.Bottom)
                        )
                        .padding(start = 24.dp, top = 8.dp, end = 24.dp, bottom = 2.dp),
                    contentAlignment = Alignment.Center
                ) {
                    // Authentic iOS Frosted Glass Floating Dock
                    Surface(
                        shape = RoundedCornerShape(32.dp),
                        color = LiquidGlassNavBg,
                        border = BorderStroke(1.dp, LiquidGlassBorderBrush),
                        shadowElevation = 24.dp,
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(64.dp)
                            .testTag("bottom_nav_bar")
                    ) {
                        Row(
                            modifier = Modifier
                                .fillMaxSize()
                                .padding(horizontal = 6.dp),
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.SpaceEvenly
                        ) {
                            LiquidGlassNavItem(
                                selected = currentTab == AppTab.HOME,
                                onClick = { viewModel.selectTab(AppTab.HOME) },
                                icon = if (currentTab == AppTab.HOME) Icons.Filled.Home else Icons.Outlined.Home,
                                label = "Home",
                                testTag = "nav_home",
                                modifier = Modifier.weight(1f).fillMaxHeight()
                            )

                            LiquidGlassNavItem(
                                selected = currentTab == AppTab.CATALOG,
                                onClick = { viewModel.selectTab(AppTab.CATALOG) },
                                icon = if (currentTab == AppTab.CATALOG) Icons.Filled.Explore else Icons.Outlined.Explore,
                                label = "Browse",
                                testTag = "nav_browse",
                                modifier = Modifier.weight(1f).fillMaxHeight()
                            )

                            LiquidGlassNavItem(
                                selected = currentTab == AppTab.SEARCH,
                                onClick = { viewModel.selectTab(AppTab.SEARCH) },
                                icon = Icons.Filled.Search,
                                label = "Search",
                                testTag = "nav_search",
                                modifier = Modifier.weight(1f).fillMaxHeight()
                            )

                            LiquidGlassNavItem(
                                selected = currentTab == AppTab.LIBRARY,
                                onClick = { viewModel.selectTab(AppTab.LIBRARY) },
                                icon = if (currentTab == AppTab.LIBRARY) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder,
                                label = "Library",
                                testTag = "nav_library",
                                modifier = Modifier.weight(1f).fillMaxHeight()
                            )

                            LiquidGlassNavItem(
                                selected = currentTab == AppTab.SETTINGS,
                                onClick = { viewModel.selectTab(AppTab.SETTINGS) },
                                icon = if (currentTab == AppTab.SETTINGS) Icons.Filled.Settings else Icons.Outlined.Settings,
                                label = "Settings",
                                testTag = "nav_settings",
                                modifier = Modifier.weight(1f).fillMaxHeight()
                            )
                        }
                    }
                }
            }
        },
        // Edge-to-edge content: screens manage their own top status bar clearance to avoid top black voids
        contentWindowInsets = WindowInsets(0, 0, 0, 0)
    ) { _ ->
        Box(
            modifier = Modifier
                .fillMaxSize()
                .background(JetBlack)
        ) {
            if (showTabletNavigation) {
                TabletNavigationRail(
                    currentTab = currentTab,
                    onTabSelected = { viewModel.selectTab(it) }
                )
            }

            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .padding(start = if (showTabletNavigation) 104.dp else 0.dp)
            ) {
                if (isActorOpen) {
                ActorScreen(
                    uiState = actorState,
                    onBackClick = { viewModel.closeActor() },
                    onMovieClick = { movie ->
                        viewModel.closeActor()
                        viewModel.openDetail(movie.safeSlug, movie.subjectId, movie.safeName, movie.type)
                    },
                    onLoadMore = { viewModel.loadMoreActorTitles() }
                )
                } else if (isDetailOpen) {
                DetailScreen(
                    uiState = detailState,
                    exoPlayer = exoPlayer,
                    onBackClick = {
                        if (isLandscape) {
                            activity?.requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_PORTRAIT
                        }
                        viewModel.closeDetail()
                    },
                    onPlayClick = { se, ep ->
                        val s = detailState.slug
                        val id = detailState.subjectId
                        if (s != null && id != null) {
                            viewModel.playItem(s, id, se, ep)
                        }
                    },
                    onSelectEpisode = { se, ep ->
                        viewModel.selectEpisode(se, ep)
                    },
                    onToggleWatchlist = { viewModel.toggleWatchlist() },
                    preferredQuality = defaultQuality,
                    autoplayNextEpisode = autoplayNext,
                    onProgressUpdate = { pos, dur ->
                        viewModel.saveProgress(pos, dur)
                    },
                    onFetchStreamForDownload = { se, ep ->
                        val slug = detailState.slug
                        val id = detailState.subjectId
                        if (slug != null && id != null) {
                            viewModel.getStreamForDownload(id, slug, se, ep)
                        } else {
                            emptyList()
                        }
                    },
                    onDownloadEnqueued = { downloadId, title, resolution, season, episode ->
                        viewModel.registerDownload(downloadId, title, resolution, season, episode)
                    },
                    onRefreshStream = { position -> viewModel.refreshCurrentStream(position) },
                    onActorClick = { name, avatarUrl -> viewModel.openActor(name, avatarUrl) }
                )
                } else {
                    when (currentTab) {
                    AppTab.HOME -> {
                        HomeScreen(
                            uiState = homeState,
                            watchHistory = watchHistory,
                            isOffline = !isNetworkAvailable,
                            isGetStartedDismissed = isGetStartedDismissed,
                            onDismissGetStarted = { viewModel.dismissGetStarted() },
                            onExploreClick = { viewModel.selectTab(AppTab.CATALOG) },
                            onLibraryClick = { viewModel.selectTab(AppTab.LIBRARY) },
                            onOpenDownloads = { viewModel.selectTab(AppTab.LIBRARY) },
                            onMovieClick = { movie ->
                                viewModel.openDetail(movie.safeSlug, movie.subjectId, movie.safeName, movie.type)
                            },
                            onWatchHeroClick = { movie ->
                                val isSeries = movie.isTvSeries
                                viewModel.openDetail(movie.safeSlug, movie.subjectId, movie.safeName, movie.type)
                                viewModel.playItem(
                                    slug = movie.safeSlug,
                                    subjectId = movie.subjectId,
                                    season = if (isSeries) 1 else 0,
                                    episode = if (isSeries) 1 else 0
                                )
                            },
                            onResumeHistoryClick = { history ->
                                viewModel.openDetail(history.slug, history.subjectId, history.title, history.type)
                                viewModel.playItem(history.slug, history.subjectId, history.season, history.episode, history.positionMs)
                            },
                            onDeleteHistoryClick = { subId ->
                                viewModel.deleteHistory(subId)
                            },
                            onRetry = { viewModel.loadHomeFeed() },
                            onSearchClick = { viewModel.selectTab(AppTab.SEARCH) }
                        )
                    }

                    AppTab.CATALOG -> {
                        CatalogScreen(
                            uiState = catalogState,
                            onBackClick = { viewModel.selectTab(AppTab.HOME) },
                            onTypeSelected = { type -> viewModel.setCatalogType(type) },
                            onGenreSelected = { genre -> viewModel.setCatalogGenre(genre) },
                            onSortSelected = { sort -> viewModel.setCatalogSort(sort) },
                            onMovieClick = { movie ->
                                viewModel.openDetail(movie.safeSlug, movie.subjectId, movie.safeName, movie.type)
                            },
                            onLoadMore = { viewModel.loadCatalog(reset = false) }
                        )
                    }

                    AppTab.SEARCH -> {
                        SearchScreen(
                            uiState = searchState,
                            onQueryChanged = { q -> viewModel.onSearchQueryChanged(q) },
                            onSearchSubmit = { q -> viewModel.performSearch(q) },
                            onBackClick = { viewModel.selectTab(AppTab.HOME) },
                            onTypeSelected = { type -> viewModel.setSearchType(type) },
                            onGenreSelected = { genre -> viewModel.setSearchGenre(genre) },
                            onYearSelected = { year -> viewModel.setSearchYear(year) },
                            onCountrySelected = { country -> viewModel.setSearchCountry(country) },
                            onClearFilters = { viewModel.clearSearchFilters() },
                            onClearSearchHistory = { viewModel.clearSearchHistory() },
                            onMovieClick = { movie ->
                                viewModel.openDetail(movie.safeSlug, movie.subjectId, movie.safeName, movie.type)
                            }
                        )
                    }

                    AppTab.LIBRARY -> {
                        LibraryScreen(
                            watchlist = watchlist,
                            watchHistory = watchHistory,
                            downloadedItems = downloadedItems,
                            onBackClick = { viewModel.selectTab(AppTab.HOME) },
                            onMovieClick = { movie ->
                                viewModel.openDetail(movie.safeSlug, movie.subjectId, movie.safeName, movie.type)
                            },
                            onResumeHistoryClick = { history ->
                                viewModel.openDetail(history.slug, history.subjectId, history.title, history.type)
                                viewModel.playItem(history.slug, history.subjectId, history.season, history.episode, history.positionMs)
                            },
                            onDeleteHistoryItem = { subId ->
                                viewModel.deleteHistory(subId)
                            },
                            onClearHistory = { viewModel.clearAllHistory() },
                            onExploreClick = { viewModel.selectTab(AppTab.HOME) },
                            onPlayDownloadedItem = { item -> viewModel.playDownloadedItem(item) },
                            onDeleteDownloadedFile = { item -> viewModel.deleteDownloadedItem(item) },
                            onRefreshDownloads = { viewModel.refreshDownloads() }
                        )
                    }

                    AppTab.SETTINGS -> {
                        SettingsScreen(
                            onBackClick = { viewModel.selectTab(AppTab.HOME) },
                            onClearHistory = { viewModel.clearAllHistory() },
                            onClearWatchlist = { viewModel.clearAllWatchlist() },
                            onOpenDownloads = { viewModel.selectTab(AppTab.LIBRARY) },
                            defaultQuality = defaultQuality,
                            autoplayNext = autoplayNext,
                            onDefaultQualityChanged = { quality -> viewModel.setDefaultQuality(quality) },
                            onAutoplayNextChanged = { enabled -> viewModel.setAutoplayNext(enabled) },
                            onShowGetStarted = {
                                viewModel.showGetStarted()
                                viewModel.selectTab(AppTab.HOME)
                            }
                        )
                    }
                    }
                }
            }
        }
    }
}

@Composable
private fun CinePulseSplash() {
    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(JetBlack),
        contentAlignment = Alignment.Center
    ) {
        Image(
            painter = painterResource(R.drawable.cinepulse_splash),
            contentDescription = "CinePulse",
            modifier = Modifier
                .fillMaxWidth()
                .widthIn(max = 360.dp)
                .aspectRatio(4f),
            contentScale = ContentScale.Crop
        )
    }
}

@Composable
private fun TabletNavigationRail(
    currentTab: AppTab,
    onTabSelected: (AppTab) -> Unit
) {
    Box(
        modifier = Modifier
            .fillMaxHeight()
            .width(96.dp)
            .windowInsetsPadding(WindowInsets.systemBars.only(WindowInsetsSides.Vertical))
            .padding(start = 16.dp, top = 20.dp, bottom = 20.dp),
        contentAlignment = Alignment.CenterStart
    ) {
        Surface(
            shape = RoundedCornerShape(28.dp),
            color = LiquidGlassNavBg,
            border = BorderStroke(1.dp, LiquidGlassBorderBrush),
            shadowElevation = 24.dp,
            modifier = Modifier
                .width(72.dp)
                .wrapContentHeight()
                .testTag("tablet_nav_rail")
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(vertical = 10.dp, horizontal = 6.dp),
                verticalArrangement = Arrangement.spacedBy(6.dp),
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                LiquidGlassNavItem(
                    selected = currentTab == AppTab.HOME,
                    onClick = { onTabSelected(AppTab.HOME) },
                    icon = if (currentTab == AppTab.HOME) Icons.Filled.Home else Icons.Outlined.Home,
                    label = "Home",
                    testTag = "nav_home_tablet",
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                )
                LiquidGlassNavItem(
                    selected = currentTab == AppTab.CATALOG,
                    onClick = { onTabSelected(AppTab.CATALOG) },
                    icon = if (currentTab == AppTab.CATALOG) Icons.Filled.Explore else Icons.Outlined.Explore,
                    label = "Browse",
                    testTag = "nav_browse_tablet",
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                )
                LiquidGlassNavItem(
                    selected = currentTab == AppTab.SEARCH,
                    onClick = { onTabSelected(AppTab.SEARCH) },
                    icon = Icons.Filled.Search,
                    label = "Search",
                    testTag = "nav_search_tablet",
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                )
                LiquidGlassNavItem(
                    selected = currentTab == AppTab.LIBRARY,
                    onClick = { onTabSelected(AppTab.LIBRARY) },
                    icon = if (currentTab == AppTab.LIBRARY) Icons.Filled.Bookmark else Icons.Outlined.BookmarkBorder,
                    label = "Library",
                    testTag = "nav_library_tablet",
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                )
                LiquidGlassNavItem(
                    selected = currentTab == AppTab.SETTINGS,
                    onClick = { onTabSelected(AppTab.SETTINGS) },
                    icon = if (currentTab == AppTab.SETTINGS) Icons.Filled.Settings else Icons.Outlined.Settings,
                    label = "Settings",
                    testTag = "nav_settings_tablet",
                    modifier = Modifier.fillMaxWidth().height(56.dp)
                )
            }
        }
    }
}

private fun Context.findActivity(): Activity? {
    var currentContext = this
    while (currentContext is ContextWrapper) {
        if (currentContext is Activity) return currentContext
        currentContext = currentContext.baseContext
    }
    return null
}

@Composable
private fun LiquidGlassNavItem(
    selected: Boolean,
    onClick: () -> Unit,
    icon: androidx.compose.ui.graphics.vector.ImageVector,
    label: String,
    testTag: String,
    modifier: Modifier = Modifier
) {
    val haptic = androidx.compose.ui.platform.LocalHapticFeedback.current

    Box(
        modifier = modifier
            .clip(RoundedCornerShape(18.dp))
            .clickable {
                haptic.performHapticFeedback(androidx.compose.ui.hapticfeedback.HapticFeedbackType.TextHandleMove)
                onClick()
            }
            .padding(vertical = 4.dp)
            .testTag(testTag),
        contentAlignment = Alignment.Center
    ) {
        Box(
            modifier = Modifier
                .size(42.dp)
                .clip(RoundedCornerShape(15.dp))
                .then(
                    if (selected) {
                        Modifier
                            .background(Brush.horizontalGradient(BrandButtonGradient))
                            .border(1.dp, BrandCoral.copy(alpha = 0.7f), RoundedCornerShape(15.dp))
                    } else {
                        Modifier
                    }
                ),
            contentAlignment = Alignment.Center
        ) {
            Icon(
                imageVector = icon,
                contentDescription = label,
                tint = if (selected) PureWhite else PureWhite.copy(alpha = 0.55f),
                modifier = Modifier.size(21.dp)
            )
        }

    }
}
