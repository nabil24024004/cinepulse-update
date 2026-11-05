# CinePulse Client ↔ API Reference

> **What this document is.** An integration reference reconstructed *only* from the open-source CinePulse Android client (`cinepulse-main.zip`): `MovieApiService.kt`, `MovieModels.kt`, `MovieRepository.kt`, `StreamTokenManager.kt`, `MainViewModel.kt`, the player and the screens.
> **What it is not.** It is not an official backend spec. The CinePulse README states that backend protocols and internal API docs are reserved by the author. Nothing here was observed from live traffic — response shapes are inferred from the client's Kotlin data classes. Anything the client code does not reveal is listed under [§12 Unknowns](#12-unknowns--things-to-verify).

---

## 0. Read this first (licence, legal scope & usage)

- Licence is "MIT / CinePulse Client Modding License". It grants rights to the **client source code only**. Clause 2: you agree not to run malicious automated scraping, DoS, or otherwise disrupt the cloud service at `movieapi.zarhosting.dpdns.org`.
- **Non-Hosting & Legal Architecture:** CinePulse does not host, store, or stream any copyrighted audio or video media on its servers. The API comes from the developer's server purely to aggregate publicly available media metadata and links. All playback resolution occurs client-side from external third-party CDNs. CinePulse is an educational open-source indexer and does not operate any illegal media streaming services.
- **Free Cloud Hosting & Regional DNS Notice:** The API is currently deployed on free-tier cloud hosting. Some regional ISPs or government firewalls block or throttle free-tier hosting domains. If the API fails to connect in your region, switch your device to a secure DNS (Cloudflare `1.1.1.1`, Google `8.8.8.8`) or use a VPN.
- **Support & Donations:** To help upgrade the server from free-tier hosting to high-speed dedicated instances, reach out to developer Ahmed Ishtiaque Zarir via email (`ahmedishtiaquezarir@gmail.com`) or Telegram (`t.me/ahmedishtiaquezarir`).
- You may fork and modify the Android client freely. Building a *separate* web app or app against the author's hosted API is **not explicitly granted** — the README says the backend is proprietary. Ask the author (GitHub: `ImAhmedZarir/cinepulse`) before shipping anything public, and keep request volume polite (cache, debounce).
- The streams are signed CDN links that originate from third-party hosts. Whether you may redistribute that content is a rights question for you to settle; publishing an app that streams it on Google Play / App Store may be rejected or taken down.

---

## 1. Connection basics

| Item | Value (from source) |
|---|---|
| Base URL | `https://movieapi.zarhosting.dpdns.org/api/public/v1/` (trailing slash matters for Retrofit) |
| Protocol | HTTPS, `GET` only. The client never sends a body, POST, PUT or DELETE. |
| Auth | **None.** No API key, bearer token or cookie anywhere in the client. (`.env.example` literally says `HAS NO API AUTHENTICATION!`) |
| Request headers (API) | `User-Agent: CinePulse-Android/1.0`, `Accept: application/json` |
| Timeouts (client) | connect 12 s, read 18 s, write 18 s |
| Response format | JSON (Moshi). Unknown keys ignored; most fields nullable. |
| Client HTTP cache | 30 MB disk cache; client **forces** `Cache-Control: public, max-age=900` (15 min) on every successful GET response, so repeat calls are served from cache for 15 minutes. |

**Endpoint summary**

| # | Method | Path | Purpose |
|---|---|---|---|
| 1 | GET | `health` | Liveness check (defined, not called by UI) |
| 2 | GET | `home` | Home feed: banner + sections |
| 3 | GET | `movies` | Movie catalog (paged, filtered) |
| 4 | GET | `tv-series` | TV catalog |
| 5 | GET | `animation` | Animation catalog |
| 6 | GET | `search` | Full search |
| 7 | GET | `search/suggest` | Autocomplete titles |
| 8 | GET | `detail/{slug}` | Title metadata, cast, season/episode map |
| 9 | GET | `stream/{subject_id}` | Playable (signed) video URLs |
| 10 | GET | `stream/{subject_id}/captions` | Subtitle (SRT) URLs |

---

## 2. Loosely-typed fields — parse defensively

The client declares many fields as `Any?` because the backend is inconsistent. Your web/Android code must tolerate both forms:

| Field | May arrive as | Normalise to |
|---|---|---|
| `subject_id` / `subjectId` | number or string | string |
| `rating` / `imdbRatingValue` | number or string | string (empty → null) |
| `year` | number (e.g. `2024.0`) or string | `"2024"` |
| `type` (list items) | `1`, `2`, or a string such as `"movie"`, `"tv"`, `"tv-series"` | `1→"movie"`, `2→"tv"` |
| `subjectType` (detail) | number or numeric string | int. `1` = movie, `2` = series |
| `duration` | number or numeric string | seconds (long) |
| `size` (stream source) | number or string | bytes as string |

A title is a TV series if `type` is `"tv"` or `"tv-series"` (list), or `subjectType == 2` (detail).

---

## 3. `GET health`

No params.

```json
{ "status": "ok", "time": "2026-11-01T14:00:00Z" }
```
Both fields are optional strings (`status`, `time`). Format of `time` unknown.

---

## 4. `GET home`

No params. Returns the landing feed.

```json
{
  "status": "ok",
  "sections": [
    { "section": "Banner",  "count": 8,  "items": [ MovieItem, ... ] },
    { "section": "<name>",  "count": 20, "items": [ MovieItem, ... ] }
  ]
}
```

- `sections[].section` — display title. The client treats the section named **`Banner`** (case-insensitive) as the hero carousel; every other section with ≥1 item becomes a horizontal row, rendered in server order with `section` as the heading. Section names are therefore **dynamic** — don't hard-code them (other than `Banner`).
- `count` — optional int; do not rely on it.
- Client caches the last non-empty response in memory + `cached_home_feed.json` and falls back to it if the call fails.

### MovieItem (used by home, catalog, search)

| JSON key | Type | Notes |
|---|---|---|
| `name` | string? | Title. Client shows `"Untitled"` when null. |
| `poster_url` | string? | Absolute image URL. |
| `slug` | string? | **Key for `detail/{slug}`** and `detail_path` on stream calls. |
| `subject_id` | number\|string? | **Key for `stream/{subject_id}`.** |
| `badge` | string? | Optional label shown on card; falls back to uppercase `type`. |
| `rating` | number\|string? | IMDb-style rating. |
| `year` | number\|string? | |
| `genre` | string? | Comma-separated, e.g. `"Action, Drama"`. Client shows the first. |
| `type` | number\|string? | See §2. |

Unique key for de-duplication in the client: `slug + "_" + subject_id`.

---

## 5. Catalog: `GET movies`, `GET tv-series`, `GET animation`

| Query param | Type | Applies to | Notes |
|---|---|---|---|
| `page` | int, default `1` | all | 1-based |
| `sort` | string | all | UI uses `foryou`, `hottest`, `latest`, `rating` (see below) |
| `genre` | string | all | Omit for "All". UI values listed below. |
| `country` | string | movies, tv-series only | Defined in the API interface; the UI never sends it. Valid values unknown. |
| `year` | string | movies, tv-series only | Defined, never sent by UI. Format unknown. |

**`sort` values used by the app:** `foryou` (UI "For You", the default), `hottest` ("Trending"), `latest` ("Latest"), `rating` ("Top Rated").

**`genre` values offered by the app UI:** `Action, Drama, Comedy, Sci-Fi, Horror, Thriller, Adventure, Romance, Fantasy, Crime, Mystery, Animation` (plus `All` = param omitted). The backend may accept more; this is just what the UI sends.

**Response**

```json
{
  "page": 1,
  "per_page": 24,
  "has_more": true,
  "total": 1234,
  "items": [ MovieItem, ... ]
}
```
- Pagination: request `page+1` while `has_more` is true. If `has_more` is missing, the client assumes "more" when `items` is non-empty. `per_page` defaults to 24 in the model (so 24 is likely, not guaranteed). The client drops duplicates across pages.
- Client in-memory catalog cache key: `"{movies|tv|anim}_{page}_{sort}_{genre}"`.

Example:
```
GET /api/public/v1/movies?page=2&sort=hottest&genre=Action
```

---

## 6. Search

### `GET search`
| Param | Type | Notes |
|---|---|---|
| `q` | string, required | Search text (client trims) |
| `page` | int, default `1` | The client only ever requests page 1 |

```json
{ "query": "batman", "page": 1, "total": 42, "items": [ MovieItem, ... ] }
```

### `GET search/suggest`
| Param | Type |
|---|---|
| `q` | string, required |

```json
{ "suggestions": [ { "title": "Batman Begins", "slug": "batman-begins-xxxx", "subject_id": 123456 } ] }
```
`slug` and `subject_id` are optional; the client uses only `title` (distinct, non-blank).

**Client behaviour:** debounce **350 ms** after the last keystroke → call `search/suggest` → then call `search` with the same text. Blank input clears results.

---

## 7. `GET detail/{slug}`

Path: `slug` — from `MovieItem.slug` (the client also reuses this value as `detail_path` for streaming).

Envelope:

```json
{
  "code": 0,
  "message": "ok",
  "data": {
    "subject": {
      "subjectId": "…",
      "subjectType": 1,
      "title": "…",
      "description": "…",
      "releaseDate": "2024-05-10",
      "duration": 7200,
      "genre": "Action, Drama",
      "countryName": "United States",
      "imdbRatingValue": "7.8",
      "subtitles": "English,Hindi,…",
      "hasResource": true,
      "cover":   { "url": "…", "avgHueLight": "…", "avgHueDark": "…" },
      "trailer": { "videoAddress": { "url": "…", "duration": 120 }, "cover": { "url": "…" } }
    },
    "stars": [
      { "name": "…", "character": "…", "avatarUrl": "…", "staffType": 1 }
    ],
    "resource": {
      "seasons": [
        { "se": 1, "maxEp": 10, "allEp": "1,2,3…",
          "resolutions": [ { "resolution": 720, "epNum": 10 } ] }
      ]
    }
  }
}
```

Field notes (all optional unless you decide otherwise):
- `data.subject.releaseDate` — client uses `take(4)` as the year.
- `duration` — seconds (client shows `duration / 60` minutes).
- `genre` — comma-separated string.
- `subtitles` — string; the model exposes it but the UI builds its language list from the captions endpoint instead.
- `cover.url` — hero/poster image. `trailer` is parsed by the model but **not used anywhere in the UI** — treat as available but unverified.
- `stars[]` — cast row (name, role `character`, `avatarUrl`). `staffType` meaning is not documented in the client.
- `resource.seasons[]` — `se` = season number, `maxEp` = number of episodes. Movies carry `se == 0` (or no valid seasons).

**Movie vs series decision (copy this logic)**

```
subjectType = data.subject.subjectType
rawSeasons  = data.resource.seasons ?? []
guessedSeries = list item's type is "tv" | "tv-series"

isMovie  = subjectType == 1 || (!guessedSeries && subjectType != 2 && rawSeasons.every(s => s.se == 0))
isSeries = !isMovie && (subjectType == 2 || guessedSeries || rawSeasons.some(s => s.se > 0 && s.maxEp > 0))
validSeasons = isSeries ? rawSeasons.filter(s => s.se > 0 && s.maxEp > 0) : []
```
For series, default to the last-watched season/episode if it exists in `validSeasons`, else the first valid season, episode 1.

---

## 8. `GET stream/{subject_id}`

| Param | Where | Type | Notes |
|---|---|---|---|
| `subject_id` | path | string | `MovieItem.subject_id` |
| `detail_path` | query | string, **required** | The title's `slug` |
| `se` | query | int, default 1 | Season |
| `ep` | query | int, default 1 | Episode |

**Critical rule (from code comments):** for **movies the backend expects `se=0&ep=0`**. For series use real values (`se ≥ 1`, `ep ≥ 1`).

```json
{
  "subject_id": "…",
  "se": 1,
  "ep": 1,
  "has_resource": true,
  "sources": [
    { "resolution": "720p", "format": "MP4", "url": "https://…signed…",
      "size": 734003200, "duration": 7200, "codec": "h264" }
  ],
  "hls":  [ { "resolution": "…", "url": "…" } ],
  "dash": [ { "resolution": "…", "url": "…" } ],
  "free_episodes": 5,
  "limited": false,
  "note": "optional human-readable message"
}
```

- `sources[]` — **the only list the Android player actually uses** (progressive MP4 URLs). `resolution` is a free string containing a number (`"480p"`, `"720"`, `"1080p"`, defaults to `"Auto"`); match with `contains`. `format` defaults to `"MP4"`. `size` is bytes (client prints as MB/GB).
- `hls[]` / `dash[]` — parsed but unused by the client; contents unverified.
- `has_resource=false` or `sources=[]` ⇒ nothing playable. Show `note` if present (client falls back to *"No stream sources available for this title."*).
- `free_episodes`, `limited` — hint that some series content is restricted; not acted on by the client.

**Fallback sequence used by the client when a call returns nothing playable** (replicate this — the backend is fussy about `se/ep`):

| Title | First try | Then, in order (skipping what was already tried) |
|---|---|---|
| Movie | `0,0` | `1,1` → `0,1` → `1,0` |
| Series | `se,ep` (each ≥ 1) | `0,0` → `1,1` → `0,1` → `1,0` |

A network/HTTP error on the first call is treated the same as "no resource", and the fallbacks are attempted.

### Signed URL lifetime (important for your player)
`StreamTokenManager` documents stream URLs as **signed CDN links valid roughly one minute**. Client policy:
- treat a fetched source list as fresh for **40 s**;
- while playing, refresh in the background every **~35 s**;
- on `ERROR_CODE_IO_*` / HTTP 403 / container errors, re-call `stream/{id}` (same `detail_path/se/ep`), swap in the new URL for the same resolution index, seek back to the last position, up to 4 auto-retries;
- pick the new URL by resolution preference: *current → 480 → 720 → 1080 → first*.

Practically: **never store stream URLs**; always call `stream/…` right before playback and re-call on failure.

### Required headers when fetching the media itself
The Android player and the downloader send these on the **CDN** request (not the JSON API):
```
User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36
Referer: https://moviebox.ph/
Origin: https://moviebox.ph
Accept: */*
Accept-Encoding: identity      ← prevents the CDN gzip-ing MP4, which corrupts parsing
```
Also: allow cross-protocol (HTTP↔HTTPS) redirects; connect timeout 25 s, read timeout 35 s; the manifest enables cleartext traffic. **A browser cannot set `Referer`/`Origin`/`User-Agent` itself**, and whether the CDN or the API sends CORS headers is not something the client reveals — test this before choosing a web architecture (see §12).

---

## 9. `GET stream/{subject_id}/captions`

Same params as §8 (`detail_path`, `se`, `ep`; movies → `0,0`).

```json
{
  "subject_id": "…", "se": 1, "ep": 1, "count": 2,
  "captions": [
    { "id": "…", "lan": "en", "lanName": "English", "url": "https://….srt", "size": "12345", "delay": 0 }
  ]
}
```
- `lan` = language code (`en`, `hi`, …), `lanName` = display name (may be native script, e.g. `हिन्दी`).
- `url` = **SRT** file (UTF-8). Client downloads it with `User-Agent: CinePulse-Android/1.0`, 12 s timeouts, follows redirects.
- `delay` — present in the model, **not applied** by the client; unknown units.
- Fallback sequence when empty: movie `0,0 → 1,1 → 0,1`; series `se,ep → 0,0 → 1,1 → 0,1`.
- Default track: first caption with `lan == "en"` or `lanName` containing "English", else index 0. Hindi is detected via `lan == "hi"` or name containing "Hindi"/"हिन्दी".

**SRT parsing rules the client uses** (port to JS/Kotlin as-is): split into blocks; skip numeric index lines; parse `HH:MM:SS,mmm --> HH:MM:SS,mmm` (comma → dot; `MM:SS` also accepted); strip HTML tags (`<[^>]*>`); look up the cue every 250 ms with `start ≤ position ≤ end`.

---

## 10. Recommended call flows

**Open app:** `GET home` (show cached copy instantly) → `GET movies?page=1&sort=foryou` in parallel.
**Open a title:** `GET detail/{slug}` → decide movie/series (§7) → `GET stream/{id}/captions` (to show languages).
**Play:** `GET stream/{id}?detail_path={slug}&se=&ep=` (+ captions in parallel) → choose source (default preference **720 → 1080 → 480 → first**) → play; refresh URL every ~35 s.
**Next episode:** same with `ep+1` while `ep < maxEp`.
**Download:** call `stream/…` fresh, let the user choose a `source`, download `source.url` with the §8 headers. Android file name pattern: `{safeTitle}_S{se}E{ep}_{resolution}.{mp4|m3u8}` into `Downloads/CinePulse/`.
**Episode UI:** the client chunks episodes into pages of 30 (`chunkSize = 30`) and offers a "jump to episode" input.

---

## 11. Local (on-device) data — not part of the API

The server has **no accounts, history or watchlist endpoints**. Both live in a local Room/SQLite DB `cinepulse.db` (v1). For a web app mirror this in IndexedDB/localStorage; for sync across devices you would need your own backend.

`watch_history` (PK `subjectId`): `subjectId, slug, title, posterUrl?, season, episode, positionMs, durationMs, lastWatchedTimestamp, type?` — saved only when `positionMs > 0 && durationMs > 0`; sorted by `lastWatchedTimestamp DESC`; upsert-by-title (one row per title).

`watchlist` (PK `subjectId`): `subjectId, slug, title, posterUrl?, rating?, year?, genre?, type?, addedTimestamp` — sorted `addedTimestamp DESC`.

Other prefs: caption text opacity (0.2–1.0, default 1.0) and background opacity (0.0–1.0, default 0.75).

---

## 12. Unknowns — things to verify

The client source cannot tell you these; check with the API owner or by testing responsibly:
1. Error response shape / HTTP status codes (the client only catches exceptions).
2. Rate limits and acceptable request volume.
3. CORS headers on the API and on the media CDN (decides whether a pure browser app can work, or whether you need your own server).
4. Valid values for `country` and `year`, and whether more `genre` / `sort` values exist.
5. Contents/format of `hls[]`, `dash[]`, `trailer`, and the meaning of `staffType`, `delay`, `free_episodes`, `limited`.
6. Whether `search` supports `page > 1` and what `total` counts.
7. Exact signed-URL lifetime (code says "~1 minute"; the client refreshes at 35–40 s).
8. Long-term stability: the host is a free `dpdns.org` domain and the API is unversioned beyond `/v1`.

---

## 13. Minimal reference code

**curl**
```bash
B=https://movieapi.zarhosting.dpdns.org/api/public/v1
curl -s "$B/home"
curl -s "$B/movies?page=1&sort=hottest&genre=Action"
curl -s "$B/search/suggest?q=bat"
curl -s "$B/detail/<slug>"
curl -s "$B/stream/<subject_id>?detail_path=<slug>&se=0&ep=0"      # movie
curl -s "$B/stream/<subject_id>/captions?detail_path=<slug>&se=1&ep=1"  # series
```

**TypeScript (types + client with the same fallbacks as the Android app)**
```ts
const BASE = "https://movieapi.zarhosting.dpdns.org/api/public/v1";

export interface MovieItem {
  name?: string | null; poster_url?: string | null; slug?: string | null;
  subject_id?: string | number | null; badge?: string | null;
  rating?: string | number | null; year?: string | number | null;
  genre?: string | null; type?: string | number | null;
}
export interface Catalog { page?: number; per_page?: number; has_more?: boolean; total?: number; items: MovieItem[] }
export interface StreamSource { resolution: string; format?: string; url: string; size?: string | number; duration?: string | number; codec?: string }
export interface StreamResponse { subject_id?: string | number; se?: number; ep?: number; has_resource: boolean; sources: StreamSource[]; hls?: any[]; dash?: any[]; free_episodes?: number; limited?: boolean; note?: string }
export interface Caption { id?: string; lan?: string; lanName?: string; url: string; size?: string; delay?: number }

const get = async <T>(path: string, params: Record<string, string | number | undefined> = {}): Promise<T> => {
  const qs = new URLSearchParams(
    Object.entries(params).filter(([, v]) => v !== undefined && v !== "").map(([k, v]) => [k, String(v)])
  );
  const res = await fetch(`${BASE}/${path}${qs.toString() ? "?" + qs.toString() : ""}`, { headers: { Accept: "application/json" } });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
};

export const api = {
  home: () => get<{ sections?: { section: string; count?: number; items: MovieItem[] }[] }>("home"),
  catalog: (kind: "movies" | "tv-series" | "animation", page = 1, sort?: string, genre?: string) =>
    get<Catalog>(kind, { page, sort, genre: genre === "All" ? undefined : genre }),
  search: (q: string, page = 1) => get<{ query?: string; page?: number; total?: number; items: MovieItem[] }>("search", { q, page }),
  suggest: (q: string) => get<{ suggestions: { title?: string; slug?: string; subject_id?: string | number }[] }>("search/suggest", { q }),
  detail: (slug: string) => get<any>(`detail/${encodeURIComponent(slug)}`),
};

// movies need se=0/ep=0; fall back through the same pairs the Android app uses
export async function getStream(subjectId: string, slug: string, isSeries: boolean, se = 1, ep = 1): Promise<StreamResponse> {
  const first: [number, number] = isSeries ? [Math.max(se, 1), Math.max(ep, 1)] : [0, 0];
  const rest: [number, number][] = isSeries ? [[se, ep], [0, 0], [1, 1], [0, 1], [1, 0]] : [[0, 0], [1, 1], [0, 1], [1, 0]];
  const pairs = [first, ...rest].filter((p, i, a) => a.findIndex(q => q[0] === p[0] && q[1] === p[1]) === i);
  let last: StreamResponse = { has_resource: false, sources: [] };
  for (const [s, e] of pairs) {
    try {
      const r = await get<StreamResponse>(`stream/${subjectId}`, { detail_path: slug, se: s, ep: e });
      if (r.has_resource && r.sources?.length) return r;
      last = r;
    } catch { /* try next pair */ }
  }
  return last;
}

export const pickSource = (sources: StreamSource[], pref = "720") =>
  sources.find(s => s.resolution.includes(pref)) ?? sources.find(s => s.resolution.includes("1080")) ??
  sources.find(s => s.resolution.includes("480")) ?? sources[0];
```

**Android (Retrofit)** — you can reuse the client's own `MovieApiService.kt`, `MovieModels.kt` and `MovieRepository.kt` verbatim (they are MIT-licensed); only the UI layer needs replacing.

---

## 14. Where each piece lives in the source

| Topic | File |
|---|---|
| Endpoints + HTTP client | `data/remote/MovieApiService.kt` |
| JSON models | `data/model/MovieModels.kt` |
| Fallback & caching logic | `data/repository/MovieRepository.kt` |
| Signed-URL refresh | `util/StreamTokenManager.kt` |
| State machines (home/catalog/search/detail/player) | `ui/MainViewModel.kt` |
| Player, quality/subtitle pickers, error recovery | `ui/player/VideoPlayer.kt` |
| SRT parser | `ui/player/Subtitles.kt` |
| Download headers & naming | `util/DownloadHelper.kt` |
| Local DB | `data/local/WatchEntities.kt` |
