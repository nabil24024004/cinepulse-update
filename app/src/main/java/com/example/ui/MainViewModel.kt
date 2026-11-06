package com.example.ui

import android.app.Application
import android.content.Context
import androidx.annotation.OptIn
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import androidx.media3.common.util.UnstableApi
import androidx.media3.datasource.DefaultDataSource
import androidx.media3.datasource.DefaultHttpDataSource
import androidx.media3.exoplayer.DefaultLoadControl
import androidx.media3.exoplayer.ExoPlayer
import androidx.media3.exoplayer.source.DefaultMediaSourceFactory
import androidx.media3.extractor.DefaultExtractorsFactory
import androidx.media3.extractor.mp4.FragmentedMp4Extractor
import androidx.media3.extractor.mp4.Mp4Extractor
import androidx.media3.exoplayer.upstream.DefaultLoadErrorHandlingPolicy
import com.example.data.local.AppDatabase
import com.example.data.local.WatchHistoryEntity
import com.example.data.local.WatchlistEntity
import com.example.data.model.*
import com.example.data.repository.MovieRepository
import com.example.util.StreamTokenManager
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.*
import kotlinx.coroutines.launch
import com.example.data.local.DownloadRecordEntity
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.app.DownloadManager
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope

private const val HTTP_USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
private const val REFERER_HEADER = "https://moviebox.ph/"

enum class AppTab {
    HOME,
    CATALOG,
    SEARCH,
    LIBRARY,
    SETTINGS
}

data class DownloadedMediaItem(
    val downloadId: Long,
    val title: String,
    val resolution: String,
    val sizeBytes: Long,
    val lastModified: Long,
    val uriString: String,
    val status: Int = DownloadManager.STATUS_SUCCESSFUL
)

enum class CatalogType(val label: String) {
    MOVIES("Movies"),
    TV_SERIES("TV Series"),
    ANIMATION("Animation")
}

data class HomeUiState(
    val isLoading: Boolean = true,
    val bannerItems: List<MovieItem> = emptyList(),
    val sections: List<HomeSection> = emptyList(),
    val errorMessage: String? = null
)

data class CatalogUiState(
    val type: CatalogType = CatalogType.MOVIES,
    val selectedGenre: String = "All",
    val selectedSort: String = "foryou",
    val items: List<MovieItem> = emptyList(),
    val isLoading: Boolean = false,
    val page: Int = 1,
    val hasMore: Boolean = true,
    val errorMessage: String? = null
)

data class SearchUiState(
    val query: String = "",
    val suggestions: List<String> = emptyList(),
    val results: List<MovieItem> = emptyList(),
    val selectedType: String = "All",
    val selectedGenre: String = "All",
    val selectedYear: String = "All",
    val selectedCountry: String = "All",
    val searchHistory: List<String> = emptyList(),
    val isSearching: Boolean = false,
    val errorMessage: String? = null
) {
    val filteredResults: List<MovieItem>
        get() = results.filter { movie ->
            val typeMatches = when (selectedType) {
                "Movie" -> movie.type.equals("movie", ignoreCase = true)
                "Series" -> movie.isTvSeries
                "Anime" -> movie.type.equals("anime", ignoreCase = true) ||
                    movie.genre.orEmpty().contains("anime", ignoreCase = true)
                "Animation" -> movie.type.equals("animation", ignoreCase = true) ||
                    movie.genre.orEmpty().contains("animation", ignoreCase = true)
                else -> true
            }
            val genreMatches = selectedGenre == "All" || movie.genre.orEmpty()
                .split(',', '/', '|')
                .any { it.trim().equals(selectedGenre, ignoreCase = true) } ||
                movie.genre.orEmpty().contains(selectedGenre, ignoreCase = true)
            val yearMatches = selectedYear == "All" || movie.year == selectedYear
            val countryMatches = when (selectedCountry) {
                "All" -> true
                "United States" -> movie.country.orEmpty().contains("united states", ignoreCase = true) ||
                    movie.country.orEmpty().contains("usa", ignoreCase = true) ||
                    movie.country.orEmpty().equals("us", ignoreCase = true)
                else -> movie.country.orEmpty().contains(selectedCountry, ignoreCase = true)
            }
            typeMatches && genreMatches && yearMatches && countryMatches
        }

    val hasActiveFilters: Boolean
        get() = selectedType != "All" || selectedGenre != "All" ||
            selectedYear != "All" || selectedCountry != "All"
}

data class ActorFilmographyUiState(
    val name: String? = null,
    val avatarUrl: String? = null,
    val items: List<MovieItem> = emptyList(),
    val page: Int = 0,
    val hasMore: Boolean = false,
    val isLoading: Boolean = false,
    val errorMessage: String? = null
)

data class DetailPlayerUiState(
    val slug: String? = null,
    val subjectId: String? = null,
    val title: String = "",
    val detail: DetailResponse? = null,
    val isSeries: Boolean = false,
    val isLoadingDetail: Boolean = false,
    val selectedSeason: Int = 0,
    val selectedEpisode: Int = 0,
    val availableSeasons: List<SeasonItem> = emptyList(),
    val isPlaying: Boolean = false,
    val streamResponse: StreamResponse? = null,
    val captions: List<CaptionItem> = emptyList(),
    val isLoadingStream: Boolean = false,
    val streamError: String? = null,
    val initialSeekPositionMs: Long = 0L,
    val isWatchlisted: Boolean = false
)

class MainViewModel(application: Application) : AndroidViewModel(application) {
    private val database = AppDatabase.getInstance(application)
    private val repository = MovieRepository(database = database, context = application)

    // Navigation state
    private val _currentTab = MutableStateFlow(AppTab.HOME)
    val currentTab: StateFlow<AppTab> = _currentTab.asStateFlow()

    private val prefs = application.getSharedPreferences("cinepulse_prefs", Context.MODE_PRIVATE)

    private val _isGetStartedDismissed = MutableStateFlow(prefs.getBoolean("get_started_dismissed", false))
    val isGetStartedDismissed: StateFlow<Boolean> = _isGetStartedDismissed.asStateFlow()

    private val _defaultQuality = MutableStateFlow(prefs.getString("default_quality", "Auto") ?: "Auto")
    val defaultQuality: StateFlow<String> = _defaultQuality.asStateFlow()

    private val _autoplayNext = MutableStateFlow(prefs.getBoolean("autoplay_next", true))
    val autoplayNext: StateFlow<Boolean> = _autoplayNext.asStateFlow()

    private val _isNetworkAvailable = MutableStateFlow(true)
    val isNetworkAvailable: StateFlow<Boolean> = _isNetworkAvailable.asStateFlow()

    private val _downloadedItems = MutableStateFlow<List<DownloadedMediaItem>>(emptyList())
    val downloadedItems: StateFlow<List<DownloadedMediaItem>> = _downloadedItems.asStateFlow()

    init {
        val cm = application.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
        if (cm != null) {
            val isConnected = cm.activeNetwork?.let { nw ->
                cm.getNetworkCapabilities(nw)?.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            } ?: false
            _isNetworkAvailable.value = isConnected

            val request = NetworkRequest.Builder()
                .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                .build()
            try {
                cm.registerNetworkCallback(request, object : ConnectivityManager.NetworkCallback() {
                    override fun onAvailable(network: Network) {
                        _isNetworkAvailable.value = true
                    }
                    override fun onLost(network: Network) {
                        _isNetworkAvailable.value = false
                    }
                })
            } catch (_: Exception) {}
        }
        refreshDownloads()
    }

    // Home state primed with disk/memory cache for instant 0ms load
    private val _homeState = MutableStateFlow(
        repository.getCachedHome()?.let { cached ->
            val sections = cached.sections.orEmpty()
            val bannerSection = sections.firstOrNull { it.section.equals("Banner", ignoreCase = true) }
            val bannerItems = bannerSection?.items.orEmpty()
            val otherSections = sections.filter { !it.section.equals("Banner", ignoreCase = true) && it.items.isNotEmpty() }
            HomeUiState(
                isLoading = false,
                bannerItems = bannerItems,
                sections = otherSections,
                errorMessage = null
            )
        } ?: HomeUiState()
    )
    val homeState: StateFlow<HomeUiState> = _homeState.asStateFlow()

    // Catalog state
    private val _catalogState = MutableStateFlow(CatalogUiState())
    val catalogState: StateFlow<CatalogUiState> = _catalogState.asStateFlow()

    // Search state
    private val _searchState = MutableStateFlow(
        SearchUiState(searchHistory = loadSearchHistory())
    )
    val searchState: StateFlow<SearchUiState> = _searchState.asStateFlow()
    private var searchJob: Job? = null
    private var searchGeneration = 0L

    private val _actorState = MutableStateFlow(ActorFilmographyUiState())
    val actorState: StateFlow<ActorFilmographyUiState> = _actorState.asStateFlow()
    private var actorJob: Job? = null
    private var actorGeneration = 0L

    // Detail & Player state
    private val _detailPlayerState = MutableStateFlow(DetailPlayerUiState())
    val detailPlayerState: StateFlow<DetailPlayerUiState> = _detailPlayerState.asStateFlow()

    // Room flows
    val watchHistory: StateFlow<List<WatchHistoryEntity>> = repository.watchHistory
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    val watchlist: StateFlow<List<WatchlistEntity>> = repository.watchlist
        .stateIn(viewModelScope, SharingStarted.WhileSubscribed(5000), emptyList())

    // Single persistent ExoPlayer instance surviving screen rotation
    private var playerInstance: ExoPlayer? = null
    private var detailJob: Job? = null
    private var streamJob: Job? = null
    private var watchlistJob: Job? = null
    private var detailGeneration = 0L

    init {
        StreamTokenManager.initialize(repository)
        loadHomeFeed()
        loadCatalog(reset = true)
    }

    @OptIn(UnstableApi::class)
    fun getPlayer(context: Context): ExoPlayer {
        if (playerInstance == null) {
            val httpDataSourceFactory = DefaultHttpDataSource.Factory()
                .setUserAgent(HTTP_USER_AGENT)
                .setDefaultRequestProperties(
                    mapOf(
                        "Referer" to REFERER_HEADER,
                        "Origin" to "https://moviebox.ph",
                        "Accept" to "*/*",
                        "Accept-Encoding" to "identity" // CRITICAL: Prevents CDN proxy from gzip-compressing MP4 containers which causes parsing container malformed
                    )
                )
                .setAllowCrossProtocolRedirects(false)
                .setConnectTimeoutMs(25000)
                .setReadTimeoutMs(35000)

            val dataSourceFactory = DefaultDataSource.Factory(context.applicationContext, httpDataSourceFactory)

            // Robust MP4 and Fragmented MP4 extractors with tolerant sample table parsing and edit list workarounds
            val extractorsFactory = DefaultExtractorsFactory().apply {
                setConstantBitrateSeekingEnabled(true)
                setMp4ExtractorFlags(Mp4Extractor.FLAG_WORKAROUND_IGNORE_EDIT_LISTS)
                setFragmentedMp4ExtractorFlags(
                    FragmentedMp4Extractor.FLAG_WORKAROUND_IGNORE_EDIT_LISTS or
                    FragmentedMp4Extractor.FLAG_WORKAROUND_EVERY_VIDEO_FRAME_IS_SYNC_FRAME
                )
            }

            val loadErrorHandlingPolicy = DefaultLoadErrorHandlingPolicy(6)

            val mediaSourceFactory = DefaultMediaSourceFactory(dataSourceFactory, extractorsFactory)
                .setLoadErrorHandlingPolicy(loadErrorHandlingPolicy)

            // Resilient buffer control preventing buffer underruns and mid-stream chunk drops
            val loadControl = DefaultLoadControl.Builder()
                .setBufferDurationsMs(
                    /* minBufferMs = */ 30_000,
                    /* maxBufferMs = */ 60_000,
                    /* bufferForPlaybackMs = */ 2_500,
                    /* bufferForPlaybackAfterRebufferMs = */ 5_000
                )
                .setPrioritizeTimeOverSizeThresholds(true)
                .build()

            playerInstance = ExoPlayer.Builder(context.applicationContext)
                .setMediaSourceFactory(mediaSourceFactory)
                .setLoadControl(loadControl)
                .setSeekForwardIncrementMs(10000)
                .setSeekBackIncrementMs(10000)
                .build().apply {
                    playWhenReady = true
                }
        }
        return playerInstance!!
    }

    fun selectTab(tab: AppTab) {
        _currentTab.value = tab
    }

    fun loadHomeFeed() {
        viewModelScope.launch {
            // Only show full loading indicator if we don't already have cached content
            if (_homeState.value.sections.isEmpty()) {
                _homeState.update { it.copy(isLoading = true, errorMessage = null) }
            }
            repository.getHome()
                .onSuccess { response ->
                    val sections = response.sections.orEmpty()
                    val bannerSection = sections.firstOrNull { it.section.equals("Banner", ignoreCase = true) }
                    val bannerItems = bannerSection?.items.orEmpty()
                    val otherSections = sections.filter { !it.section.equals("Banner", ignoreCase = true) && it.items.isNotEmpty() }
                    _homeState.update {
                        it.copy(
                            isLoading = false,
                            bannerItems = bannerItems,
                            sections = otherSections,
                            errorMessage = null
                        )
                    }
                }
                .onFailure { error ->
                    _homeState.update {
                        it.copy(
                            isLoading = false,
                            errorMessage = if (it.sections.isEmpty()) {
                                error.localizedMessage ?: "Failed to load home feed. Please check your connection."
                            } else null
                        )
                    }
                }
        }
    }

    fun setCatalogType(type: CatalogType) {
        if (_catalogState.value.type != type) {
            _catalogState.update { it.copy(type = type, page = 1) }
            loadCatalog(reset = true)
        }
    }

    fun setCatalogGenre(genre: String) {
        if (_catalogState.value.selectedGenre != genre) {
            _catalogState.update { it.copy(selectedGenre = genre, page = 1) }
            loadCatalog(reset = true)
        }
    }

    fun setCatalogSort(sort: String) {
        if (_catalogState.value.selectedSort != sort) {
            _catalogState.update { it.copy(selectedSort = sort, page = 1) }
            loadCatalog(reset = true)
        }
    }

    fun loadCatalog(reset: Boolean = false) {
        viewModelScope.launch {
            val currentState = _catalogState.value
            val targetPage = if (reset) 1 else currentState.page + 1

            if (!reset && !currentState.hasMore) return@launch

            val genreParam = if (currentState.selectedGenre == "All") null else currentState.selectedGenre
            val sortParam = currentState.selectedSort
            val cacheKey = when (currentState.type) {
                CatalogType.MOVIES -> "movies_${targetPage}_${sortParam}_${genreParam}"
                CatalogType.TV_SERIES -> "tv_${targetPage}_${sortParam}_${genreParam}"
                CatalogType.ANIMATION -> "anim_${targetPage}_${sortParam}_${genreParam}"
            }

            // Immediately display cached catalog items if available on reset
            if (reset) {
                val cached = repository.getCachedCatalog(cacheKey)
                if (cached != null && cached.items.isNotEmpty()) {
                    _catalogState.update {
                        it.copy(
                            items = cached.items,
                            isLoading = false,
                            page = 1,
                            hasMore = cached.hasMore ?: true
                        )
                    }
                } else if (currentState.items.isEmpty()) {
                    _catalogState.update { it.copy(isLoading = true, errorMessage = null) }
                }
            } else {
                _catalogState.update { it.copy(isLoading = true, errorMessage = null) }
            }

            val result = when (currentState.type) {
                CatalogType.MOVIES -> repository.getMovies(page = targetPage, sort = sortParam, genre = genreParam)
                CatalogType.TV_SERIES -> repository.getTvSeries(page = targetPage, sort = sortParam, genre = genreParam)
                CatalogType.ANIMATION -> repository.getAnimation(page = targetPage, sort = sortParam, genre = genreParam)
            }

            result.onSuccess { response ->
                val newItems = if (reset) response.items else currentState.items + response.items
                _catalogState.update {
                    it.copy(
                        isLoading = false,
                        items = newItems,
                        page = targetPage,
                        hasMore = response.hasMore ?: (response.items.isNotEmpty()),
                        errorMessage = null
                    )
                }
            }.onFailure { error ->
                _catalogState.update {
                    it.copy(
                        isLoading = false,
                        errorMessage = error.localizedMessage ?: "Failed to load catalog."
                    )
                }
            }
        }
    }

    private fun loadSearchHistory(): List<String> {
        return prefs.getString("search_history", "")
            .orEmpty()
            .split('\u001F')
            .map(String::trim)
            .filter(String::isNotBlank)
            .distinct()
            .take(12)
    }

    private fun saveSearchHistory(query: String) {
        val updated = listOf(query) + _searchState.value.searchHistory.filterNot { it.equals(query, ignoreCase = true) }
        val limited = updated.take(12)
        prefs.edit().putString("search_history", limited.joinToString("\u001F")).apply()
        _searchState.update { it.copy(searchHistory = limited) }
    }

    fun clearSearchHistory() {
        prefs.edit().remove("search_history").apply()
        _searchState.update { it.copy(searchHistory = emptyList()) }
    }

    fun setSearchType(type: String) {
        _searchState.update { it.copy(selectedType = type) }
        if (_searchState.value.query.isBlank()) refreshFilterOnlySearch()
    }

    fun setSearchGenre(genre: String) {
        _searchState.update { it.copy(selectedGenre = genre) }
        if (_searchState.value.query.isBlank()) refreshFilterOnlySearch()
    }

    fun setSearchYear(year: String) {
        _searchState.update { it.copy(selectedYear = year) }
        if (_searchState.value.query.isBlank()) refreshFilterOnlySearch()
    }

    fun setSearchCountry(country: String) {
        _searchState.update { it.copy(selectedCountry = country) }
        if (_searchState.value.query.isBlank()) refreshFilterOnlySearch()
    }

    fun clearSearchFilters() {
        searchJob?.cancel()
        searchGeneration++
        _searchState.update {
            it.copy(
                selectedType = "All",
                selectedGenre = "All",
                selectedYear = "All",
                selectedCountry = "All",
                results = if (it.query.isBlank()) emptyList() else it.results,
                isSearching = false,
                errorMessage = null
            )
        }
    }

    private fun refreshFilterOnlySearch() {
        val state = _searchState.value
        if (state.query.isNotBlank()) return

        searchGeneration++
        val generation = searchGeneration
        searchJob?.cancel()
        if (!state.hasActiveFilters) {
            _searchState.update { it.copy(results = emptyList(), isSearching = false, errorMessage = null) }
            return
        }

        val genre = state.selectedGenre.takeUnless { it == "All" }
        val country = state.selectedCountry.takeUnless { it == "All" }
        val year = state.selectedYear.takeUnless { it == "All" }
        _searchState.update { it.copy(results = emptyList(), isSearching = true, errorMessage = null) }

        searchJob = viewModelScope.launch {
            val responses = coroutineScope {
                when (state.selectedType) {
                    "Movie" -> listOf(async { repository.getMovies(1, "latest", genre, country, year) })
                    "Series" -> listOf(async { repository.getTvSeries(1, "latest", genre, country, year) })
                    "Anime", "Animation" -> listOf(async { repository.getAnimation(1, "latest", genre) })
                    else -> listOf(
                        async { repository.getMovies(1, "latest", genre, country, year) },
                        async { repository.getTvSeries(1, "latest", genre, country, year) },
                        async { repository.getAnimation(1, "latest", genre) }
                    )
                }.awaitAll()
            }
            if (generation != searchGeneration) return@launch
            val items = responses.flatMap { it.getOrNull()?.items.orEmpty() }
                .distinctBy { "${it.safeSlug}_${it.subjectId}" }
            _searchState.update {
                it.copy(
                    results = items,
                    isSearching = false,
                    errorMessage = if (items.isEmpty()) null else it.errorMessage
                )
            }
        }
    }

    fun openActor(name: String, avatarUrl: String? = null) {
        val actorName = name.trim()
        if (actorName.isBlank()) return
        actorGeneration++
        val generation = actorGeneration
        actorJob?.cancel()
        _actorState.value = ActorFilmographyUiState(
            name = actorName,
            avatarUrl = avatarUrl,
            isLoading = true
        )
        actorJob = viewModelScope.launch {
            repository.search(actorName, page = 1)
                .onSuccess { response ->
                    if (generation != actorGeneration) return@onSuccess
                    _actorState.value = ActorFilmographyUiState(
                        name = actorName,
                        avatarUrl = avatarUrl,
                        items = response.items,
                        page = response.page ?: 1,
                        hasMore = response.hasMore ?: response.items.isNotEmpty(),
                        isLoading = false
                    )
                }
                .onFailure { error ->
                    if (generation == actorGeneration) {
                        _actorState.value = ActorFilmographyUiState(
                            name = actorName,
                            avatarUrl = avatarUrl,
                            isLoading = false,
                            errorMessage = error.localizedMessage ?: "Unable to load this actor's titles."
                        )
                    }
                }
        }
    }

    fun loadMoreActorTitles() {
        val current = _actorState.value
        val actorName = current.name ?: return
        if (current.isLoading || !current.hasMore) return
        val generation = actorGeneration
        val nextPage = current.page + 1
        actorJob?.cancel()
        _actorState.update { it.copy(isLoading = true, errorMessage = null) }
        actorJob = viewModelScope.launch {
            repository.search(actorName, page = nextPage)
                .onSuccess { response ->
                    if (generation != actorGeneration) return@onSuccess
                    _actorState.update {
                        it.copy(
                            items = (it.items + response.items).distinctBy { movie -> "${movie.safeSlug}_${movie.subjectId}" },
                            page = response.page ?: nextPage,
                            hasMore = response.hasMore ?: response.items.isNotEmpty(),
                            isLoading = false
                        )
                    }
                }
                .onFailure { error ->
                    if (generation == actorGeneration) {
                        _actorState.update {
                            it.copy(isLoading = false, errorMessage = error.localizedMessage ?: "Unable to load more titles.")
                        }
                    }
                }
        }
    }

    fun closeActor() {
        actorGeneration++
        actorJob?.cancel()
        _actorState.value = ActorFilmographyUiState()
    }

    fun onSearchQueryChanged(newQuery: String) {
        _searchState.update { it.copy(query = newQuery) }
        val generation = ++searchGeneration
        searchJob?.cancel()

        if (newQuery.isBlank()) {
            _searchState.update { it.copy(suggestions = emptyList(), results = emptyList(), isSearching = false) }
            return
        }

        searchJob = viewModelScope.launch {
            delay(350)
            repository.getSuggestions(newQuery).onSuccess { res ->
                if (generation == searchGeneration) {
                    val titles = res.suggestions.mapNotNull { it.title }.filter { it.isNotBlank() }.distinct()
                    _searchState.update { it.copy(suggestions = titles) }
                }
            }
            if (generation != searchGeneration) return@launch
            _searchState.update { it.copy(isSearching = true, errorMessage = null) }
            repository.search(query = newQuery.trim(), page = 1)
                .onSuccess { res ->
                    if (generation == searchGeneration) {
                        _searchState.update {
                            it.copy(isSearching = false, results = res.items, errorMessage = null)
                        }
                    }
                }
                .onFailure { error ->
                    if (generation == searchGeneration) {
                        _searchState.update {
                            it.copy(isSearching = false, errorMessage = error.localizedMessage ?: "Search failed.")
                        }
                    }
                }
        }
    }

    fun performSearch(queryToSearch: String) {
        val q = queryToSearch.trim()
        if (q.isBlank()) return
        saveSearchHistory(q)
        val generation = ++searchGeneration
        searchJob?.cancel()
        searchJob = viewModelScope.launch {
            _searchState.update { it.copy(query = q, isSearching = true, errorMessage = null) }
            repository.search(query = q, page = 1)
                .onSuccess { res ->
                    if (generation == searchGeneration) {
                        _searchState.update {
                            it.copy(isSearching = false, results = res.items, errorMessage = null)
                        }
                    }
                }
                .onFailure { error ->
                    if (generation == searchGeneration) {
                        _searchState.update {
                            it.copy(isSearching = false, errorMessage = error.localizedMessage ?: "Search failed.")
                        }
                    }
                }
        }
    }

    fun openDetail(slug: String, subjectId: String, title: String = "", initialType: String? = null) {
        detailGeneration++
        val generation = detailGeneration
        detailJob?.cancel()
        streamJob?.cancel()
        watchlistJob?.cancel()
        val guessedSeries = initialType.equals("tv", ignoreCase = true) || initialType.equals("tv-series", ignoreCase = true)
        _detailPlayerState.value = DetailPlayerUiState(
            slug = slug,
            subjectId = subjectId,
            title = title,
            isSeries = guessedSeries,
            selectedSeason = if (guessedSeries) 1 else 0,
            selectedEpisode = if (guessedSeries) 1 else 0,
            isLoadingDetail = true
        )

        // Observe watchlist
        watchlistJob = viewModelScope.launch {
            repository.isWatchlisted(subjectId).collectLatest { isWatchlisted ->
                if (generation == detailGeneration && _detailPlayerState.value.subjectId == subjectId) {
                    _detailPlayerState.update { it.copy(isWatchlisted = isWatchlisted) }
                }
            }
        }

        // Fetch detail
        detailJob = viewModelScope.launch {
            val savedHistory = repository.getHistoryItem(subjectId)
            val prevPos = savedHistory?.positionMs ?: 0L
            val prevSe = savedHistory?.season ?: 1
            val prevEp = savedHistory?.episode ?: 1

            repository.getDetail(slug)
                .onSuccess { detailRes ->
                    if (generation != detailGeneration) return@onSuccess
                    val subjectType = detailRes.data?.subject?.subjectType
                    val rawSeasons = detailRes.data?.resource?.seasons.orEmpty()
                    // CRITICAL: A title is a series ONLY if subjectType == 2 OR (subjectType != 1 && rawSeasons has se > 0 and maxEp > 0)
                    val isMovie = subjectType == 1 || (!guessedSeries && subjectType != 2 && rawSeasons.all { it.se == 0 })
                    val isSeries = !isMovie && (subjectType == 2 || guessedSeries || rawSeasons.any { it.se > 0 && it.maxEp > 0 })

                    val validSeasons = if (isSeries) rawSeasons.filter { it.se > 0 && it.maxEp > 0 } else emptyList()
                    val actualTitle = detailRes.data?.subject?.title ?: title

                    val actualSe = if (isSeries) {
                        if (validSeasons.any { it.se == prevSe }) prevSe else (validSeasons.firstOrNull()?.se ?: 1)
                    } else 0
                    val actualEp = if (isSeries) prevEp.coerceAtLeast(1) else 0

                    _detailPlayerState.update {
                        it.copy(
                            detail = detailRes,
                            title = actualTitle,
                            isSeries = isSeries,
                            availableSeasons = validSeasons,
                            selectedSeason = actualSe,
                            selectedEpisode = actualEp,
                            isLoadingDetail = false,
                            initialSeekPositionMs = prevPos
                        )
                    }

                    // Pre-fetch captions so available subtitle languages (e.g. Hindi, English) are known immediately
                    viewModelScope.launch {
                        repository.getCaptions(subjectId, slug, season = actualSe, episode = actualEp, isSeries = isSeries)
                            .onSuccess { capRes ->
                                if (generation == detailGeneration &&
                                    _detailPlayerState.value.subjectId == subjectId &&
                                    _detailPlayerState.value.selectedSeason == actualSe &&
                                    _detailPlayerState.value.selectedEpisode == actualEp
                                ) {
                                    val caps = capRes.captions.orEmpty()
                                    _detailPlayerState.update { it.copy(captions = caps) }
                                }
                            }
                    }
                }
                .onFailure { error ->
                    if (generation == detailGeneration) {
                        _detailPlayerState.update {
                            it.copy(isLoadingDetail = false, streamError = error.localizedMessage)
                        }
                    }
                }
        }
    }

    fun playItem(
        slug: String,
        subjectId: String,
        season: Int = 0,
        episode: Int = 0,
        resumePositionMs: Long? = null
    ) {
        streamJob?.cancel()
        val generation = detailGeneration
        streamJob = viewModelScope.launch {
            val current = _detailPlayerState.value
            val isSeries = current.isSeries

            // For movies, Zar MovieBox API explicitly requires se=0, ep=0!
            val actualSe = if (isSeries) season.coerceAtLeast(1) else 0
            val actualEp = if (isSeries) episode.coerceAtLeast(1) else 0

            StreamTokenManager.setActiveSession(
                subjectId = subjectId,
                slug = slug,
                season = actualSe,
                episode = actualEp,
                isSeries = isSeries,
                resolution = defaultQuality.value
            )

            val initialPos = resumePositionMs ?: repository.getHistoryItem(subjectId)?.positionMs ?: 0L

            _detailPlayerState.update {
                it.copy(
                    slug = slug,
                    subjectId = subjectId,
                    selectedSeason = actualSe,
                    selectedEpisode = actualEp,
                    isPlaying = true,
                    isLoadingStream = true,
                    streamError = null,
                    initialSeekPositionMs = initialPos
                )
            }

            val (streamResult, captionsResult) = coroutineScope {
                val stream = async { repository.getStream(subjectId, slug, actualSe, actualEp, isSeries = isSeries) }
                val captions = async { repository.getCaptions(subjectId, slug, actualSe, actualEp, isSeries = isSeries) }
                stream.await() to captions.await()
            }

            if (generation != detailGeneration || _detailPlayerState.value.subjectId != subjectId) return@launch

            streamResult.onSuccess { streamRes ->
                val caps = captionsResult.getOrNull()?.captions.orEmpty()
                if (streamRes.sources.isNotEmpty()) {
                    StreamTokenManager.updateActiveSources(streamRes.sources)
                    _detailPlayerState.update {
                        it.copy(
                            streamResponse = streamRes,
                            captions = caps,
                            isLoadingStream = false,
                            streamError = null
                        )
                    }
                } else {
                    _detailPlayerState.update {
                        it.copy(
                            isPlaying = false,
                            streamResponse = streamRes,
                            captions = caps,
                            isLoadingStream = false,
                            streamError = streamRes.note ?: "No stream sources available for this title."
                        )
                    }
                }
            }.onFailure { err ->
                _detailPlayerState.update {
                    it.copy(
                        isPlaying = false,
                        streamResponse = null,
                        isLoadingStream = false,
                        streamError = err.localizedMessage ?: "Failed to fetch stream link."
                    )
                }
            }
        }
    }

    suspend fun getStreamForDownload(subjectId: String, slug: String, season: Int, episode: Int): List<StreamSource> {
        val isSeries = _detailPlayerState.value.isSeries
        val s = if (isSeries) season.coerceAtLeast(1) else 0
        val e = if (isSeries) episode.coerceAtLeast(1) else 0
        return repository.getStream(subjectId, slug, s, e, isSeries = isSeries).getOrNull()?.sources.orEmpty()
    }

    fun selectEpisode(season: Int, episode: Int) {
        val s = _detailPlayerState.value
        val slug = s.slug ?: return
        val subjectId = s.subjectId ?: return
        playItem(slug, subjectId, season, episode, resumePositionMs = 0L)
    }

    fun refreshCurrentStream(resumePositionMs: Long = 0L) {
        val state = _detailPlayerState.value
        val slug = state.slug ?: return
        val subjectId = state.subjectId ?: return
        playItem(slug, subjectId, state.selectedSeason, state.selectedEpisode, resumePositionMs)
    }

    fun saveProgress(posMs: Long, durMs: Long) {
        val s = _detailPlayerState.value
        val subId = s.subjectId ?: return
        val slug = s.slug ?: return
        val title = s.title.ifBlank { s.detail?.data?.subject?.title ?: "Movie" }
        val poster = s.detail?.data?.subject?.cover?.url
        val type = if (s.isSeries) "tv" else "movie"

        viewModelScope.launch {
            repository.saveWatchProgress(
                subjectId = subId,
                slug = slug,
                title = title,
                posterUrl = poster,
                season = s.selectedSeason,
                episode = s.selectedEpisode,
                positionMs = posMs,
                durationMs = durMs,
                type = type
            )
        }
    }

    fun toggleWatchlist() {
        val s = _detailPlayerState.value
        val subId = s.subjectId ?: return
        val slug = s.slug ?: return
        val subject = s.detail?.data?.subject
        val title = s.title.ifBlank { subject?.title ?: "Movie" }
        val poster = subject?.cover?.url
        val rating = subject?.imdbRatingValue
        val year = subject?.releaseDate?.take(4)
        val genre = subject?.genre
        val type = if (s.isSeries) "tv" else "movie"

        viewModelScope.launch {
            repository.toggleWatchlist(
                subjectId = subId,
                slug = slug,
                title = title,
                posterUrl = poster,
                rating = rating,
                year = year,
                genre = genre,
                type = type,
                currentlyWatchlisted = s.isWatchlisted
            )
        }
    }

    fun deleteHistory(subjectId: String) {
        viewModelScope.launch {
            repository.deleteHistory(subjectId)
        }
    }

    fun clearAllHistory() {
        viewModelScope.launch {
            repository.clearHistory()
        }
    }

    fun clearAllWatchlist() {
        viewModelScope.launch {
            repository.clearWatchlist()
        }
    }

    fun dismissGetStarted() {
        prefs.edit().putBoolean("get_started_dismissed", true).apply()
        _isGetStartedDismissed.value = true
    }

    fun setDefaultQuality(quality: String) {
        prefs.edit().putString("default_quality", quality).apply()
        _defaultQuality.value = quality
    }

    fun setAutoplayNext(enabled: Boolean) {
        prefs.edit().putBoolean("autoplay_next", enabled).apply()
        _autoplayNext.value = enabled
    }

    fun showGetStarted() {
        prefs.edit().putBoolean("get_started_dismissed", false).apply()
        _isGetStartedDismissed.value = false
    }

    fun refreshDownloads() {
        viewModelScope.launch(Dispatchers.IO) {
            val downloadManager = getApplication<Application>().getSystemService(Context.DOWNLOAD_SERVICE) as? DownloadManager
            val records = repository.downloadRecords.first()
            val list = mutableListOf<DownloadedMediaItem>()
            if (downloadManager != null) {
                for (record in records) {
                    val query = DownloadManager.Query().setFilterById(record.downloadId)
                    downloadManager.query(query)?.use { cursor ->
                        if (!cursor.moveToFirst()) return@use
                        val status = cursor.getInt(cursor.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS))
                        val uri = downloadManager.getUriForDownloadedFile(record.downloadId)
                        repository.saveDownloadRecord(
                            record.copy(
                                uriString = uri?.toString() ?: record.uriString,
                                status = status
                            )
                        )
                        if (status != DownloadManager.STATUS_SUCCESSFUL || uri == null) return@use
                        val sizeIndex = cursor.getColumnIndex(DownloadManager.COLUMN_TOTAL_SIZE_BYTES)
                        val timeIndex = cursor.getColumnIndex(DownloadManager.COLUMN_LAST_MODIFIED_TIMESTAMP)
                        val size = if (sizeIndex >= 0) cursor.getLong(sizeIndex).coerceAtLeast(0L) else 0L
                        val modified = if (timeIndex >= 0) cursor.getLong(timeIndex) else record.createdTimestamp
                        list.add(
                            DownloadedMediaItem(
                                downloadId = record.downloadId,
                                title = record.title,
                                resolution = record.resolution,
                                sizeBytes = size,
                                lastModified = modified,
                                uriString = uri.toString(),
                                status = status
                            )
                        )
                    }
                }
            }
            list.sortByDescending { it.lastModified }
            _downloadedItems.value = list
        }
    }

    fun registerDownload(downloadId: Long, title: String, resolution: String, season: Int = 0, episode: Int = 0) {
        viewModelScope.launch(Dispatchers.IO) {
            repository.saveDownloadRecord(
                DownloadRecordEntity(
                    downloadId = downloadId,
                    title = title,
                    resolution = resolution,
                    season = season,
                    episode = episode,
                    status = DownloadManager.STATUS_PENDING
                )
            )
            refreshDownloads()
        }
    }

    fun deleteDownloadedItem(item: DownloadedMediaItem) {
        viewModelScope.launch(Dispatchers.IO) {
            try {
                val manager = getApplication<Application>().getSystemService(Context.DOWNLOAD_SERVICE) as? DownloadManager
                manager?.remove(item.downloadId)
            } catch (_: Exception) {}
            repository.deleteDownloadRecord(item.downloadId)
            refreshDownloads()
        }
    }

    fun playDownloadedItem(item: DownloadedMediaItem) {
        val streamSource = StreamSource(
            format = "mp4",
            resolution = item.resolution,
            rawSize = "${item.sizeBytes / (1024 * 1024)} MB",
            url = item.uriString
        )
        _detailPlayerState.value = DetailPlayerUiState(
            slug = "local_${item.downloadId}",
            subjectId = "local_${item.downloadId}",
            title = item.title,
            isPlaying = true,
            streamResponse = StreamResponse(sources = listOf(streamSource)),
            detail = null
        )
    }


    fun closePlayer() {
        persistCurrentPlaybackProgress()
        playerInstance?.pause()
        playerInstance?.stop()
        _detailPlayerState.update { it.copy(isPlaying = false) }
    }

    fun closeDetail() {
        detailGeneration++
        detailJob?.cancel()
        streamJob?.cancel()
        watchlistJob?.cancel()
        persistCurrentPlaybackProgress()
        playerInstance?.pause()
        playerInstance?.stop()
        _detailPlayerState.value = DetailPlayerUiState()
    }

    override fun onCleared() {
        persistCurrentPlaybackProgress()
        super.onCleared()
        playerInstance?.release()
        playerInstance = null
    }

    private fun persistCurrentPlaybackProgress() {
        val player = playerInstance ?: return
        val positionMs = player.currentPosition
        val durationMs = player.duration
        if (positionMs > 0L && durationMs > 0L) {
            saveProgress(positionMs, durationMs)
        }
    }
}
