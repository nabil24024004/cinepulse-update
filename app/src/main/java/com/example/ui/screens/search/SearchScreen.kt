package com.example.ui.screens.search

import androidx.compose.animation.AnimatedContent
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.animation.expandVertically
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.shrinkVertically
import androidx.compose.animation.togetherWith
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Clear
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.History
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Tune
import androidx.compose.material.icons.filled.TrendingUp
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalSoftwareKeyboardController
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.data.model.MovieItem
import com.example.ui.SearchUiState
import com.example.ui.components.GlassChip
import com.example.ui.components.GlassIconButton
import com.example.ui.components.MoviePosterCard
import com.example.ui.theme.*

private val POPULAR_SEARCHES = listOf(
    "Breaking Bad", "Avatar", "Oppenheimer", "Batman",
    "John Wick", "Dune", "Peaky Blinders", "Anime", "Action"
)

private val SEARCH_TYPES = listOf("All", "Movie", "Series", "Anime", "Animation")
private val SEARCH_GENRES = listOf(
    "All", "Action", "Drama", "Comedy", "Sci-Fi", "Horror",
    "Thriller", "Adventure", "Romance", "Fantasy", "Crime", "Mystery",
    "Documentary", "Family", "History", "Music"
)
private val SEARCH_YEARS = listOf("All", "2026", "2025", "2024", "2023", "2022", "2021", "2020")
private val SEARCH_COUNTRIES = listOf(
    "All", "United States", "United Kingdom", "South Korea", "Japan", "India", "China"
)

// ─── Shimmer Placeholder ─────────────────────────────────────────────────────

@Composable
private fun ShimmerCard(modifier: Modifier = Modifier) {
    val infiniteTransition = rememberInfiniteTransition(label = "shimmer")
    val shimmerX by infiniteTransition.animateFloat(
        initialValue = -600f,
        targetValue = 600f,
        animationSpec = infiniteRepeatable(
            animation = tween(1200, easing = LinearEasing),
            repeatMode = RepeatMode.Restart
        ),
        label = "shimmerX"
    )
    val shimmerBrush = Brush.linearGradient(
        colors = listOf(
            LiquidGlassSurface,
            LiquidGlassSurfaceElevated,
            LiquidGlassSurface
        ),
        start = Offset(shimmerX, 0f),
        end = Offset(shimmerX + 400f, 400f)
    )
    Box(
        modifier = modifier
            .clip(RoundedCornerShape(16.dp))
            .background(shimmerBrush)
            .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(16.dp))
    )
}

@Composable
private fun ShimmerGrid() {
    LazyVerticalGrid(
        columns = GridCells.Adaptive(minSize = 110.dp),
        contentPadding = PaddingValues(start = 16.dp, end = 16.dp, top = 8.dp, bottom = 96.dp),
        horizontalArrangement = Arrangement.spacedBy(10.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
        modifier = Modifier.fillMaxSize(),
        userScrollEnabled = false
    ) {
        items(count = 8) {
            ShimmerCard(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(193.dp)
            )
        }
    }
}

@Composable
private fun SearchFilterGroup(
    title: String,
    options: List<String>,
    selected: String,
    onSelected: (String) -> Unit
) {
    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        Text(
            text = title,
            color = TextPrimary,
            fontWeight = FontWeight.SemiBold,
            fontSize = 14.sp
        )
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState()),
            horizontalArrangement = Arrangement.spacedBy(8.dp)
        ) {
            options.forEach { option ->
                GlassChip(
                    text = option,
                    selected = selected == option,
                    onClick = { onSelected(option) }
                )
            }
        }
    }
}

// ─── Main Screen ─────────────────────────────────────────────────────────────

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun SearchScreen(
    uiState: SearchUiState,
    onQueryChanged: (String) -> Unit,
    onSearchSubmit: (String) -> Unit,
    onBackClick: () -> Unit,
    onTypeSelected: (String) -> Unit,
    onGenreSelected: (String) -> Unit,
    onYearSelected: (String) -> Unit,
    onCountrySelected: (String) -> Unit,
    onClearFilters: () -> Unit,
    onClearSearchHistory: () -> Unit,
    onMovieClick: (MovieItem) -> Unit,
    modifier: Modifier = Modifier
) {
    val keyboardController = LocalSoftwareKeyboardController.current
    var showFilterSheet by remember { mutableStateOf(false) }
    val activeFilterCount = listOf(
        uiState.selectedType,
        uiState.selectedGenre,
        uiState.selectedYear,
        uiState.selectedCountry
    ).count { it != "All" }

    if (showFilterSheet) {
        ModalBottomSheet(
            onDismissRequest = { showFilterSheet = false },
            containerColor = CinemaDark,
            contentColor = TextPrimary,
            dragHandle = { BottomSheetDefaults.DragHandle(color = TextSecondary) }
        ) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .verticalScroll(rememberScrollState())
                    .padding(start = 20.dp, end = 20.dp, bottom = 28.dp),
                verticalArrangement = Arrangement.spacedBy(20.dp)
            ) {
                Row(
                    modifier = Modifier.fillMaxWidth(),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        text = "Filter Results",
                        color = TextPrimary,
                        fontSize = 20.sp,
                        fontWeight = FontWeight.Bold,
                        modifier = Modifier.weight(1f)
                    )
                    IconButton(onClick = { showFilterSheet = false }) {
                        Icon(
                            imageVector = Icons.Default.Close,
                            contentDescription = "Close filters",
                            tint = TextSecondary
                        )
                    }
                }

                SearchFilterGroup("Type", SEARCH_TYPES, uiState.selectedType, onTypeSelected)
                SearchFilterGroup("Genre", SEARCH_GENRES, uiState.selectedGenre, onGenreSelected)
                SearchFilterGroup("Year", SEARCH_YEARS, uiState.selectedYear, onYearSelected)
                SearchFilterGroup("Country", SEARCH_COUNTRIES, uiState.selectedCountry, onCountrySelected)

                Row(
                    modifier = Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.spacedBy(12.dp),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    TextButton(
                        onClick = onClearFilters,
                        enabled = activeFilterCount > 0,
                        modifier = Modifier.weight(1f)
                    ) {
                        Text("Clear all", color = if (activeFilterCount > 0) BrandCoral else TextSecondary)
                    }
                    Button(
                        onClick = { showFilterSheet = false },
                        modifier = Modifier.weight(1f),
                        colors = ButtonDefaults.buttonColors(containerColor = BrandCoral)
                    ) {
                        Text("Done", color = PureWhite)
                    }
                }
            }
        }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(CinemaBlack)
    ) {

        // ── Liquid Glass Search Bar ───────────────────────────────────────────
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .statusBarsPadding()
                .padding(start = 8.dp, end = 16.dp, top = 8.dp, bottom = 10.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            GlassIconButton(
                icon = Icons.AutoMirrored.Filled.ArrowBack,
                contentDescription = "Back",
                onClick = onBackClick
            )
            Spacer(modifier = Modifier.width(8.dp))
            Box(
                modifier = Modifier
                    .weight(1f)
                    .clip(RoundedCornerShape(999.dp))
                    .background(LiquidGlassSurface)
                    .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(999.dp))
            ) {
            // Top specular sheen stripe
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(1.dp)
                    .background(
                        Brush.horizontalGradient(
                            listOf(
                                Color.Transparent,
                                LiquidGlassSpecularHighlight,
                                Color.Transparent
                            )
                        )
                    )
            )
            TextField(
                value = uiState.query,
                onValueChange = onQueryChanged,
                placeholder = {
                    Text(
                        text = "Search movies, series, anime…",
                        color = TextSecondary,
                        style = MaterialTheme.typography.bodyMedium
                    )
                },
                leadingIcon = {
                    Icon(
                        imageVector = Icons.Default.Search,
                        contentDescription = "Search",
                        tint = BrandCoral
                    )
                },
                trailingIcon = {
                    if (uiState.query.isNotBlank()) {
                        IconButton(onClick = { onQueryChanged("") }) {
                            Icon(
                                imageVector = Icons.Default.Clear,
                                contentDescription = "Clear",
                                tint = TextSecondary
                            )
                        }
                    }
                },
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = {
                    keyboardController?.hide()
                    onSearchSubmit(uiState.query)
                }),
                colors = TextFieldDefaults.colors(
                    focusedContainerColor = Color.Transparent,
                    unfocusedContainerColor = Color.Transparent,
                    disabledContainerColor = Color.Transparent,
                    focusedIndicatorColor = Color.Transparent,
                    unfocusedIndicatorColor = Color.Transparent,
                    focusedTextColor = TextPrimary,
                    unfocusedTextColor = TextPrimary,
                    cursorColor = BrandCoral
                ),
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag("search_text_field")
            )
            }
        }

        // ── Compact filter control ──────────────────────────────────────────
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(start = 16.dp, end = 16.dp, bottom = 8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Row(
                modifier = Modifier
                    .clip(RoundedCornerShape(999.dp))
                    .background(if (activeFilterCount > 0) BrandCoral else LiquidGlassSurface)
                    .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(999.dp))
                    .clickable { showFilterSheet = true }
                    .padding(horizontal = 14.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(7.dp)
            ) {
                Icon(
                    imageVector = Icons.Default.Tune,
                    contentDescription = "Open filters",
                    tint = PureWhite,
                    modifier = Modifier.size(16.dp)
                )
                Text(
                    text = if (activeFilterCount == 0) "Filters" else "Filters ($activeFilterCount)",
                    color = PureWhite,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold
                )
            }
            if (activeFilterCount > 0) {
                Spacer(modifier = Modifier.width(10.dp))
                Text(
                    text = "Refine results",
                    color = TextSecondary,
                    fontSize = 12.sp
                )
            }
        }

        // ── Animated Suggestions Dropdown ────────────────────────────────────
        AnimatedVisibility(
            visible = uiState.suggestions.isNotEmpty(),
            enter = expandVertically(tween(220)) + fadeIn(tween(220)),
            exit = shrinkVertically(tween(180)) + fadeOut(tween(180))
        ) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(horizontal = 16.dp)
                    .clip(RoundedCornerShape(16.dp))
                    .background(LiquidGlassSurfaceElevated)
                    .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(16.dp))
                    .padding(vertical = 6.dp)
            ) {
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .horizontalScroll(rememberScrollState())
                        .padding(horizontal = 12.dp, vertical = 4.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    uiState.suggestions.forEach { suggestion ->
                        Box(
                            modifier = Modifier
                                .clip(RoundedCornerShape(999.dp))
                                .background(LiquidGlassSurface)
                                .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(999.dp))
                                .clickable {
                                    keyboardController?.hide()
                                    onSearchSubmit(suggestion)
                                }
                                .padding(horizontal = 14.dp, vertical = 7.dp),
                            contentAlignment = Alignment.Center
                        ) {
                            Text(
                                text = suggestion,
                                style = MaterialTheme.typography.labelSmall.copy(
                                    fontWeight = FontWeight.Medium,
                                    color = TextPrimary,
                                    fontSize = 12.sp
                                )
                            )
                        }
                    }
                }
            }
        }

        if (uiState.suggestions.isNotEmpty()) {
            Spacer(modifier = Modifier.height(8.dp))
        }

        // ── Content Area ─────────────────────────────────────────────────────
        AnimatedContent(
            targetState = when {
                uiState.isSearching               -> "loading"
                uiState.filteredResults.isNotEmpty() -> "results"
                uiState.hasActiveFilters           -> "empty_filter"
                uiState.results.isNotEmpty()       -> "empty_filter"
                uiState.query.isNotBlank()         -> "empty_query"
                else                               -> "idle"
            },
            transitionSpec = {
                fadeIn(tween(260)) togetherWith fadeOut(tween(180))
            },
            label = "SearchContentArea",
            modifier = Modifier.fillMaxSize()
        ) { state ->
            when (state) {

                // Loading shimmer
                "loading" -> ShimmerGrid()

                // Results grid
                "results" -> {
                    val uniqueResults = remember(uiState.filteredResults) {
                        uiState.filteredResults.distinctBy { "${it.safeSlug}_${it.subjectId}" }
                    }
                    LazyVerticalGrid(
                        columns = GridCells.Adaptive(minSize = 110.dp),
                        contentPadding = PaddingValues(
                            start = 16.dp, end = 16.dp,
                            top = 8.dp, bottom = 96.dp
                        ),
                        horizontalArrangement = Arrangement.spacedBy(10.dp),
                        verticalArrangement = Arrangement.spacedBy(14.dp),
                        modifier = Modifier
                            .fillMaxSize()
                            .testTag("search_results_grid")
                    ) {
                        items(
                            count = uniqueResults.size,
                            key = { i -> "${uniqueResults[i].safeSlug}_${uniqueResults[i].subjectId}_$i" }
                        ) { i ->
                            val movie = uniqueResults[i]
                            MoviePosterCard(
                                movie = movie,
                                onClick = { onMovieClick(movie) },
                                cardWidth = 110.dp,
                                cardHeight = 165.dp,
                                modifier = Modifier.fillMaxWidth()
                            )
                        }
                    }
                }

                "empty_filter" -> {
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(32.dp),
                        contentAlignment = Alignment.Center
                    ) {
                        Text(
                            text = "No titles match these filters.",
                            color = TextSecondary,
                            style = MaterialTheme.typography.bodyMedium,
                            textAlign = TextAlign.Center
                        )
                    }
                }

                // No results for this query
                "empty_query" -> {
                    Box(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(32.dp),
                        contentAlignment = Alignment.Center
                    ) {
                        // Glass card empty state
                        Box(
                            modifier = Modifier
                                .clip(RoundedCornerShape(24.dp))
                                .background(LiquidGlassSurface)
                                .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(24.dp))
                                .padding(32.dp),
                            contentAlignment = Alignment.Center
                        ) {
                            Column(horizontalAlignment = Alignment.CenterHorizontally) {
                                Box(
                                    modifier = Modifier
                                        .size(64.dp)
                                        .clip(RoundedCornerShape(20.dp))
                                        .background(LiquidGlassSurfaceElevated)
                                        .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(20.dp)),
                                    contentAlignment = Alignment.Center
                                ) {
                                    Icon(
                                        imageVector = Icons.Default.Search,
                                        contentDescription = null,
                                        tint = TextSecondary,
                                        modifier = Modifier.size(30.dp)
                                    )
                                }
                                Spacer(modifier = Modifier.height(16.dp))
                                Text(
                                    text = "No results for\n\"${uiState.query}\"",
                                    style = MaterialTheme.typography.titleMedium.copy(
                                        fontWeight = FontWeight.Bold,
                                        color = TextPrimary,
                                        textAlign = TextAlign.Center
                                    )
                                )
                                Spacer(modifier = Modifier.height(8.dp))
                                Text(
                                    text = "Try different keywords or title names.",
                                    style = MaterialTheme.typography.bodySmall.copy(
                                        color = TextSecondary,
                                        textAlign = TextAlign.Center
                                    )
                                )
                            }
                        }
                    }
                }

                // Idle — trending searches
                else -> {
                    Column(
                        modifier = Modifier
                            .fillMaxSize()
                            .verticalScroll(rememberScrollState())
                            .padding(horizontal = 16.dp, vertical = 4.dp)
                    ) {
                        if (uiState.searchHistory.isNotEmpty()) {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                verticalAlignment = Alignment.CenterVertically
                            ) {
                                Icon(
                                    imageVector = Icons.Default.History,
                                    contentDescription = null,
                                    tint = TextPrimary,
                                    modifier = Modifier.size(18.dp)
                                )
                                Spacer(modifier = Modifier.width(8.dp))
                                Text(
                                    text = "Search History",
                                    style = MaterialTheme.typography.titleMedium.copy(
                                        fontWeight = FontWeight.Bold,
                                        color = TextPrimary,
                                        fontSize = 16.sp
                                    )
                                )
                                Spacer(modifier = Modifier.weight(1f))
                                TextButton(onClick = onClearSearchHistory) {
                                    Text("Clear", color = BrandCoral)
                                }
                            }
                            Spacer(modifier = Modifier.height(8.dp))
                            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                                uiState.searchHistory.forEach { query ->
                                    Box(
                                        modifier = Modifier
                                            .fillMaxWidth()
                                            .clip(RoundedCornerShape(14.dp))
                                            .background(LiquidGlassSurface)
                                            .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(14.dp))
                                            .clickable { onSearchSubmit(query) }
                                    ) {
                                        Row(
                                            modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
                                            verticalAlignment = Alignment.CenterVertically
                                        ) {
                                            Icon(
                                                imageVector = Icons.Default.History,
                                                contentDescription = null,
                                                tint = TextSecondary,
                                                modifier = Modifier.size(16.dp)
                                            )
                                            Spacer(modifier = Modifier.width(12.dp))
                                            Text(
                                                text = query,
                                                style = MaterialTheme.typography.bodyMedium.copy(
                                                    color = TextPrimary,
                                                    fontWeight = FontWeight.Medium
                                                ),
                                                modifier = Modifier.weight(1f)
                                            )
                                        }
                                    }
                                }
                            }
                            Spacer(modifier = Modifier.height(20.dp))
                        }

                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(8.dp)
                        ) {
                            Icon(
                                imageVector = Icons.Default.TrendingUp,
                                contentDescription = null,
                                tint = Color.White,
                                modifier = Modifier.size(18.dp)
                            )
                            Text(
                                text = "Trending Searches",
                                style = MaterialTheme.typography.titleMedium.copy(
                                    fontWeight = FontWeight.Bold,
                                    color = TextPrimary,
                                    fontSize = 16.sp
                                )
                            )
                        }

                        Spacer(modifier = Modifier.height(12.dp))

                        Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                            POPULAR_SEARCHES.forEach { tag ->
                                Box(
                                    modifier = Modifier
                                        .fillMaxWidth()
                                        .clip(RoundedCornerShape(14.dp))
                                        .background(LiquidGlassSurface)
                                        .border(1.dp, LiquidGlassBorderBrush, RoundedCornerShape(14.dp))
                                        .clickable {
                                            keyboardController?.hide()
                                            onSearchSubmit(tag)
                                        }
                                ) {
                                    Row(
                                        modifier = Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
                                        verticalAlignment = Alignment.CenterVertically
                                    ) {
                                        Icon(
                                            imageVector = Icons.Default.Search,
                                            contentDescription = null,
                                            tint = Color.White,
                                            modifier = Modifier.size(16.dp)
                                        )
                                        Spacer(modifier = Modifier.width(12.dp))
                                        Text(
                                            text = tag,
                                            style = MaterialTheme.typography.bodyMedium.copy(
                                                color = TextPrimary,
                                                fontWeight = FontWeight.Medium
                                            ),
                                            modifier = Modifier.weight(1f)
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}
