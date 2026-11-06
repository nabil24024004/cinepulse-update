package com.example.util

import android.app.DownloadManager
import android.content.Context
import android.net.Uri
import android.os.Environment
import android.widget.Toast

object DownloadHelper {
    private const val HTTP_USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"
    private const val REFERER_HEADER = "https://moviebox.ph/"
    private const val ORIGIN_HEADER = "https://moviebox.ph"

    fun downloadVideo(
        context: Context,
        url: String,
        title: String,
        resolution: String,
        season: Int? = null,
        episode: Int? = null,
        onEnqueued: (downloadId: Long) -> Unit = {}
    ): Long? {
        if (url.isBlank()) {
            Toast.makeText(context, "No download stream link available.", Toast.LENGTH_SHORT).show()
            return null
        }
        if (url.contains(".m3u8", ignoreCase = true)) {
            Toast.makeText(context, "Offline downloads currently support MP4 files only.", Toast.LENGTH_LONG).show()
            return null
        }
        if (Uri.parse(url).scheme?.lowercase() != "https") {
            Toast.makeText(context, "Download link must use HTTPS.", Toast.LENGTH_SHORT).show()
            return null
        }

        return try {
            val downloadManager = context.getSystemService(Context.DOWNLOAD_SERVICE) as? DownloadManager
                ?: throw IllegalStateException("DownloadManager is not available on this device.")

            val safeTitle = title.replace(Regex("[^a-zA-Z0-9.-]"), "_").trim('_').ifBlank { "CinePulse" }
            val episodeSuffix = if (season != null && episode != null && season > 0 && episode > 0) {
                "_S${season}E${episode}"
            } else ""
            val fileName = "CinePulse_${safeTitle}${episodeSuffix}_${resolution}_${System.currentTimeMillis()}.mp4"

            val request = DownloadManager.Request(Uri.parse(url))
                .setTitle(title)
                .setDescription("Downloading $title ($resolution) via CinePulse")
                .setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED)
                .setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "CinePulse/$fileName")
                .addRequestHeader("User-Agent", HTTP_USER_AGENT)
                .addRequestHeader("Referer", REFERER_HEADER)
                .addRequestHeader("Origin", ORIGIN_HEADER)
                .setAllowedOverMetered(true)
                .setAllowedOverRoaming(false)

            val downloadId = downloadManager.enqueue(request)
            onEnqueued(downloadId)
            Toast.makeText(context, "Downloading $fileName to Downloads/CinePulse", Toast.LENGTH_LONG).show()
            downloadId
        } catch (e: Exception) {
            Toast.makeText(context, "Download failed: ${e.localizedMessage ?: "unknown error"}", Toast.LENGTH_LONG).show()
            null
        }
    }
}
