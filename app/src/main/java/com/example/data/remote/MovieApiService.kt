package com.example.data.remote

import com.example.data.model.*
import com.squareup.moshi.Moshi
import com.squareup.moshi.kotlin.reflect.KotlinJsonAdapterFactory
import okhttp3.Dns
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import retrofit2.converter.moshi.MoshiConverterFactory
import retrofit2.http.GET
import retrofit2.http.Path
import retrofit2.http.Query
import java.net.Inet6Address
import java.net.InetAddress
import java.net.URL
import java.net.UnknownHostException
import java.util.concurrent.TimeUnit
import org.json.JSONObject

interface MovieApiService {

    @GET("health")
    suspend fun checkHealth(): HealthResponse

    @GET("home")
    suspend fun getHome(): HomeResponse

    @GET("movies")
    suspend fun getMovies(
        @Query("page") page: Int = 1,
        @Query("sort") sort: String? = null,
        @Query("genre") genre: String? = null,
        @Query("country") country: String? = null,
        @Query("year") year: String? = null
    ): CatalogResponse

    @GET("tv-series")
    suspend fun getTvSeries(
        @Query("page") page: Int = 1,
        @Query("sort") sort: String? = null,
        @Query("genre") genre: String? = null,
        @Query("country") country: String? = null,
        @Query("year") year: String? = null
    ): CatalogResponse

    @GET("animation")
    suspend fun getAnimation(
        @Query("page") page: Int = 1,
        @Query("sort") sort: String? = null,
        @Query("genre") genre: String? = null
    ): CatalogResponse

    @GET("search")
    suspend fun search(
        @Query("q") query: String,
        @Query("page") page: Int = 1
    ): SearchResponse

    @GET("search/suggest")
    suspend fun getSuggestions(
        @Query("q") query: String
    ): SuggestResponse

    @GET("detail/{slug}")
    suspend fun getDetail(
        @Path("slug") slug: String
    ): DetailResponse

    @GET("stream/{subject_id}")
    suspend fun getStream(
        @Path("subject_id") subjectId: String,
        @Query("detail_path") detailPath: String,
        @Query("se") season: Int = 1,
        @Query("ep") episode: Int = 1
    ): StreamResponse

    @GET("stream/{subject_id}/captions")
    suspend fun getCaptions(
        @Path("subject_id") subjectId: String,
        @Query("detail_path") detailPath: String,
        @Query("se") season: Int = 1,
        @Query("ep") episode: Int = 1
    ): CaptionsResponse
}

/**
 * Resilient DNS resolver for mobile networks.
 * 1. Prioritizes IPv4 addresses to prevent timeouts caused by broken mobile carrier IPv6 routing (IPv6 blackholing).
 * 2. Provides hardcoded Anycast CDN fallback and Google DoH when ISP DNS blocks/filters dynamic DNS (dpdns.org).
 */
class ResilientDns : Dns {
    override fun lookup(hostname: String): List<InetAddress> {
        try {
            val addresses = Dns.SYSTEM.lookup(hostname)
            // Sort IPv4 first to eliminate IPv6 packet drop and timeout on 4G/5G mobile carriers
            val sorted = addresses.sortedBy { if (it is Inet6Address) 1 else 0 }
            if (sorted.isNotEmpty()) return sorted
        } catch (_: UnknownHostException) {
            // System DNS failed (commonly blocked by regional ISP on dynamic DNS domains)
        } catch (_: Exception) {}

        // Fallback 1: Cloudflare Anycast IPs for the primary API host
        if (hostname.equals("movieapi.zarhosting.dpdns.org", ignoreCase = true)) {
            return listOf(
                InetAddress.getByName("104.21.70.103"),
                InetAddress.getByName("172.67.222.217")
            )
        }

        // Fallback 2: Known Anycast CDN for poster images
        if (hostname.equals("pbcdn.aoneroom.com", ignoreCase = true) ||
            hostname.equals("pbcdnw.aoneroom.com", ignoreCase = true)) {
            return listOf(
                InetAddress.getByName("154.85.74.169")
            )
        }

        // Fallback 3: Query Google DNS-over-HTTPS (DoH) dynamically
        try {
            val dohUrl = "https://dns.google/resolve?name=$hostname&type=A"
            val conn = URL(dohUrl).openConnection() as java.net.HttpURLConnection
            conn.connectTimeout = 3500
            conn.readTimeout = 3500
            conn.setRequestProperty("Accept", "application/json")
            if (conn.responseCode == 200) {
                val jsonStr = conn.inputStream.bufferedReader().use { it.readText() }
                val json = JSONObject(jsonStr)
                val answers = json.optJSONArray("Answer")
                if (answers != null && answers.length() > 0) {
                    val resolved = mutableListOf<InetAddress>()
                    for (i in 0 until answers.length()) {
                        val obj = answers.getJSONObject(i)
                        if (obj.optInt("type") == 1) { // Type 1 = A record (IPv4)
                            val ip = obj.optString("data")
                            if (ip.isNotBlank()) {
                                resolved.add(InetAddress.getByName(ip))
                            }
                        }
                    }
                    if (resolved.isNotEmpty()) return resolved
                }
            }
        } catch (_: Exception) {}

        throw UnknownHostException("ResilientDns unable to resolve host: $hostname")
    }
}

object NetworkClient {
    private const val BASE_URL = "https://movieapi.zarhosting.dpdns.org/api/public/v1/"
    private const val BROWSER_UA = "Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36"
    private var cacheDir: java.io.File? = null

    fun init(cacheDirectory: java.io.File) {
        cacheDir = cacheDirectory
    }

    val moshi: Moshi = Moshi.Builder()
        .add(KotlinJsonAdapterFactory())
        .build()

    val okHttpClient: OkHttpClient by lazy {
        val builder = OkHttpClient.Builder()
            .dns(ResilientDns())
            .retryOnConnectionFailure(true)
            .connectTimeout(15, TimeUnit.SECONDS)
            .readTimeout(20, TimeUnit.SECONDS)
            .writeTimeout(20, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val request = chain.request().newBuilder()
                    .header("User-Agent", BROWSER_UA)
                    .header("Accept", "application/json, text/plain, */*")
                    .header("Accept-Language", "en-US,en;q=0.9")
                    .header("Referer", "https://moviebox.ph/")
                    .header("Origin", "https://moviebox.ph")
                    .build()
                chain.proceed(request)
            }
            .addNetworkInterceptor { chain ->
                val response = chain.proceed(chain.request())
                // Cache public GET requests for 15 minutes (900 seconds) on device
                if (chain.request().method.equals("GET", ignoreCase = true) && response.isSuccessful) {
                    response.newBuilder()
                        .header("Cache-Control", "public, max-age=900")
                        .removeHeader("Pragma")
                        .build()
                } else {
                    response
                }
            }

        cacheDir?.let { dir ->
            try {
                val httpCacheDir = java.io.File(dir, "http_cache")
                builder.cache(okhttp3.Cache(httpCacheDir, 30L * 1024 * 1024))
            } catch (_: Exception) {}
        }

        builder.build()
    }

    val api: MovieApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(okHttpClient)
            .addConverterFactory(MoshiConverterFactory.create(moshi))
            .build()
            .create(MovieApiService::class.java)
    }
}
