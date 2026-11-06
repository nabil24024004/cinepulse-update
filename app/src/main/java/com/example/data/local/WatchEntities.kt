package com.example.data.local

import androidx.room.*
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase
import kotlinx.coroutines.flow.Flow

@Entity(tableName = "watch_history")
data class WatchHistoryEntity(
    @PrimaryKey
    val subjectId: String,
    val slug: String,
    val title: String,
    val posterUrl: String?,
    val season: Int,
    val episode: Int,
    val positionMs: Long,
    val durationMs: Long,
    val lastWatchedTimestamp: Long = System.currentTimeMillis(),
    val type: String? = null
)

@Entity(tableName = "watchlist")
data class WatchlistEntity(
    @PrimaryKey
    val subjectId: String,
    val slug: String,
    val title: String,
    val posterUrl: String?,
    val rating: String?,
    val year: String?,
    val genre: String?,
    val type: String?,
    val addedTimestamp: Long = System.currentTimeMillis()
)

@Entity(tableName = "download_records")
data class DownloadRecordEntity(
    @PrimaryKey val downloadId: Long,
    val title: String,
    val resolution: String,
    val season: Int = 0,
    val episode: Int = 0,
    val uriString: String? = null,
    val status: Int = 0,
    val createdTimestamp: Long = System.currentTimeMillis()
)

@Dao
interface WatchDao {
    @Query("SELECT * FROM watch_history ORDER BY lastWatchedTimestamp DESC")
    fun getAllHistory(): Flow<List<WatchHistoryEntity>>

    @Query("SELECT * FROM watch_history WHERE subjectId = :subjectId LIMIT 1")
    suspend fun getHistory(subjectId: String): WatchHistoryEntity?

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertHistory(entity: WatchHistoryEntity)

    @Query("DELETE FROM watch_history WHERE subjectId = :subjectId")
    suspend fun deleteHistory(subjectId: String)

    @Query("DELETE FROM watch_history")
    suspend fun clearHistory()

    @Query("SELECT * FROM watchlist ORDER BY addedTimestamp DESC")
    fun getAllWatchlist(): Flow<List<WatchlistEntity>>

    @Query("SELECT EXISTS(SELECT 1 FROM watchlist WHERE subjectId = :subjectId)")
    fun isWatchlisted(subjectId: String): Flow<Boolean>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun addToWatchlist(entity: WatchlistEntity)

    @Query("DELETE FROM watchlist WHERE subjectId = :subjectId")
    suspend fun removeFromWatchlist(subjectId: String)

    @Query("DELETE FROM watchlist")
    suspend fun clearWatchlist()

    @Query("SELECT * FROM download_records ORDER BY createdTimestamp DESC")
    fun getAllDownloadRecords(): Flow<List<DownloadRecordEntity>>

    @Insert(onConflict = OnConflictStrategy.REPLACE)
    suspend fun upsertDownloadRecord(entity: DownloadRecordEntity)

    @Query("DELETE FROM download_records WHERE downloadId = :downloadId")
    suspend fun deleteDownloadRecord(downloadId: Long)

    @Query("DELETE FROM download_records")
    suspend fun clearDownloadRecords()
}

@Database(
    entities = [WatchHistoryEntity::class, WatchlistEntity::class, DownloadRecordEntity::class],
    version = 2,
    exportSchema = false
)
abstract class AppDatabase : RoomDatabase() {
    abstract fun watchDao(): WatchDao

    companion object {
        @Volatile
        private var INSTANCE: AppDatabase? = null

        fun getInstance(context: android.content.Context): AppDatabase {
            return INSTANCE ?: synchronized(this) {
                val instance = Room.databaseBuilder(
                    context.applicationContext,
                    AppDatabase::class.java,
                    "cinepulse.db"
                )
                    .addMigrations(MIGRATION_1_2)
                    .fallbackToDestructiveMigration(dropAllTables = true)
                    .build()
                INSTANCE = instance
                instance
            }
        }

        private val MIGRATION_1_2 = object : Migration(1, 2) {
            override fun migrate(db: SupportSQLiteDatabase) {
                db.execSQL(
                    """CREATE TABLE IF NOT EXISTS download_records (
                        downloadId INTEGER NOT NULL,
                        title TEXT NOT NULL,
                        resolution TEXT NOT NULL,
                        season INTEGER NOT NULL,
                        episode INTEGER NOT NULL,
                        uriString TEXT,
                        status INTEGER NOT NULL DEFAULT 0,
                        createdTimestamp INTEGER NOT NULL,
                        PRIMARY KEY(downloadId)
                    )""".trimIndent()
                )
            }
        }
    }
}
