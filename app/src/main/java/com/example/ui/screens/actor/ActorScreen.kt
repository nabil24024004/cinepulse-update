package com.example.ui.screens.actor

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.rememberLazyGridState
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import coil.compose.AsyncImage
import coil.request.ImageRequest
import androidx.compose.ui.platform.LocalContext
import com.example.data.model.MovieItem
import com.example.ui.ActorFilmographyUiState
import com.example.ui.components.GlassIconButton
import com.example.ui.components.MoviePosterCard
import com.example.ui.theme.*
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.filter
import kotlinx.coroutines.flow.map

@Composable
fun ActorScreen(
    uiState: ActorFilmographyUiState,
    onBackClick: () -> Unit,
    onMovieClick: (MovieItem) -> Unit,
    onLoadMore: () -> Unit,
    modifier: Modifier = Modifier
) {
    val context = LocalContext.current
    val gridState = rememberLazyGridState()

    LaunchedEffect(gridState, uiState.items.size, uiState.hasMore, uiState.isLoading) {
        snapshotFlowLastVisibleIndex(gridState)
            .distinctUntilChanged()
            .filter { uiState.items.isNotEmpty() && it >= uiState.items.size - 4 }
            .collectLatest { onLoadMore() }
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .background(CinemaBlack)
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .statusBarsPadding()
                .padding(start = 12.dp, end = 16.dp, top = 8.dp, bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            GlassIconButton(
                icon = Icons.AutoMirrored.Filled.ArrowBack,
                contentDescription = "Back to title",
                onClick = onBackClick
            )
            Spacer(Modifier.width(10.dp))
            Box(
                modifier = Modifier
                    .size(48.dp)
                    .clip(CircleShape)
                    .background(CinemaSurfaceVariant)
                    .border(1.dp, LiquidGlassBorderBrush, CircleShape),
                contentAlignment = Alignment.Center
            ) {
                if (uiState.avatarUrl.isNullOrBlank()) {
                    Icon(Icons.Default.Person, contentDescription = null, tint = TextSecondary)
                } else {
                    AsyncImage(
                        model = ImageRequest.Builder(context)
                            .data(uiState.avatarUrl)
                            .crossfade(true)
                            .build(),
                        contentDescription = uiState.name,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize()
                    )
                }
            }
            Spacer(Modifier.width(12.dp))
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = uiState.name ?: "Actor",
                    color = TextPrimary,
                    fontSize = 19.sp,
                    fontWeight = FontWeight.Bold,
                    maxLines = 1
                )
                Text(
                    text = "Movies & series available on CinePulse",
                    color = TextSecondary,
                    fontSize = 12.sp,
                    maxLines = 1
                )
            }
        }

        when {
            uiState.isLoading && uiState.items.isEmpty() -> {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    CircularProgressIndicator(color = BrandCoral)
                }
            }

            uiState.errorMessage != null && uiState.items.isEmpty() -> {
                Box(
                    modifier = Modifier
                        .fillMaxSize()
                        .padding(28.dp),
                    contentAlignment = Alignment.Center
                ) {
                    Column(horizontalAlignment = Alignment.CenterHorizontally) {
                        Text("Couldn’t load titles", color = TextPrimary, fontWeight = FontWeight.Bold)
                        Spacer(Modifier.height(8.dp))
                        Text(
                            uiState.errorMessage,
                            color = TextSecondary,
                            textAlign = TextAlign.Center,
                            fontSize = 13.sp
                        )
                    }
                }
            }

            uiState.items.isEmpty() -> {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                    Text(
                        "No titles found for this actor.",
                        color = TextSecondary,
                        textAlign = TextAlign.Center
                    )
                }
            }

            else -> {
                LazyVerticalGrid(
                    columns = GridCells.Adaptive(minSize = 110.dp),
                    state = gridState,
                    contentPadding = PaddingValues(start = 16.dp, end = 16.dp, bottom = 96.dp),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                    verticalArrangement = Arrangement.spacedBy(14.dp),
                    modifier = Modifier
                        .fillMaxSize()
                        .testTag("actor_filmography_grid")
                ) {
                    items(
                        items = uiState.items,
                        key = { movie -> "${movie.safeSlug}_${movie.subjectId}" }
                    ) { movie ->
                        MoviePosterCard(
                            movie = movie,
                            onClick = { onMovieClick(movie) },
                            cardWidth = 110.dp,
                            cardHeight = 165.dp,
                            modifier = Modifier.fillMaxWidth()
                        )
                    }
                    if (uiState.isLoading) {
                        item {
                            Box(
                                modifier = Modifier
                                    .fillMaxWidth()
                                    .height(60.dp),
                                contentAlignment = Alignment.Center
                            ) {
                                CircularProgressIndicator(
                                    color = BrandCoral,
                                    strokeWidth = 2.dp,
                                    modifier = Modifier.size(24.dp)
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun snapshotFlowLastVisibleIndex(
    gridState: androidx.compose.foundation.lazy.grid.LazyGridState
) = androidx.compose.runtime.snapshotFlow {
    gridState.layoutInfo.visibleItemsInfo.lastOrNull()?.index ?: 0
}.map { it }
