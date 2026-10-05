<?php

require_once __DIR__ . '/Trakt.php';
require_once __DIR__ . '/Library.php';
require_once __DIR__ . '/Posters.php';
require_once __DIR__ . '/Plex.php';
require_once __DIR__ . '/Ratings.php';
require_once __DIR__ . '/Widgets.php';
require_once __DIR__ . '/WidgetCache.php';
require_once __DIR__ . '/WidgetRegistry.php';

/**
 * Shared setup for every entry point (index.php, api.php, widgets.php,
 * cron.php, auth.php, mcp.php): loads config.php with defaults filled in
 * and wires up the Trakt client, local library, and widgets.
 */
class App
{
    public const DEFAULTS = [
        'client_id'        => '',
        'client_secret'    => '',
        'username'         => '',
        'app_name'         => 'Box Office of One',
        'poll_interval_ms' => 15000,
        'cache_ttl'        => 60,
        'top_limit'        => 8,
        'timezone'         => '',
        'shows_default_period'  => 'this_year',
        'movies_default_period' => 'this_year',
        'genre_default_period'  => 'all_time',
        'tmdb_api_key'     => '',
        'plex_url'         => '',
        'plex_token'       => '',
        'plex_user'        => '',
        'plex_server'      => '',
        'mdblist_api_key'  => '',
        'mdblist_daily_limit'     => 900,
        'ratings_backfill_per_run' => 50,
        'library_backfill_pages_per_run' => 20,
        'library_rebuild_days'           => 7,
        'poster_backfill_per_run'        => 40,
        'cron_enabled'     => false,
        'cron_secret'      => '',
        'mcp_api_key'      => '',
        'github_repo'      => '',
        'update_check_ttl' => 3600,
    ];

    public array $config;
    public Trakt $trakt;
    public Library $library;
    public Posters $posters;
    public Plex $plex;
    public Ratings $ratings;
    public Widgets $widgets;
    public DateTimeZone $tz;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->tz = Trakt::resolveTimezone($config['timezone']);
        $this->trakt = new Trakt($config, (int) $config['cache_ttl']);
        $this->library = new Library($this->trakt, $config['username'] !== '' ? $config['username'] : 'me');
        $this->posters = new Posters($config);
        $this->plex = new Plex($config);
        $this->ratings = new Ratings($config);
        $this->widgets = new Widgets($this->trakt, $this->library, $this->posters, $this->ratings, $config);
    }

    /**
     * @return array|null config with defaults applied, or null if config.php is missing
     */
    public static function loadConfig(): ?array
    {
        $configFile = __DIR__ . '/../config.php';
        if (!is_file($configFile)) {
            return null;
        }

        $config = require $configFile;

        return is_array($config) ? $config + self::DEFAULTS : null;
    }

    public static function needsSetup(?array $config): bool
    {
        return $config === null
            || $config['client_id'] === '' || $config['client_id'] === 'YOUR_TRAKT_CLIENT_ID'
            || $config['username'] === 'YOUR_TRAKT_USERNAME';
    }

    public static function boot(array $config): self
    {
        return new self($config);
    }

    public function handlers(): array
    {
        return WidgetRegistry::handlers($this->trakt, $this->library, $this->widgets, $this->config);
    }

    /**
     * The hero card: what's playing right now (or the last thing watched),
     * plus the thing before it. A direct Plex session wins when Plex is
     * configured (real position, survives pausing), then a live Trakt
     * scrobble/check-in, then the most recent history entry.
     *
     * "Recent history" merges Trakt's with Plex's own (when configured):
     * Plex records a play the moment it finishes, while Trakt may only hear
     * about it when a sync tool next runs — so on its own, Trakt's history
     * can trail what you've just watched. The same play from both sources
     * is only listed once.
     *
     * @return array{current: ?array, previous: ?array}
     */
    public function nowWatching(int $ttl): array
    {
        $recent = $this->recentPlays($ttl);

        $plexSession = $this->plex->currentSession($ttl);
        if ($plexSession !== null) {
            $current = $this->describePlex($plexSession, true);
            $recent = array_values(array_filter($recent, fn($r) => $r['id'] !== self::playIdentity('plex', $plexSession)));
        } elseif ($watching = $this->trakt->getWatching($ttl)) {
            $current = $this->describe($watching, true);
        } else {
            $current = isset($recent[0]) ? ($recent[0]['make'])() : null;
            array_shift($recent);
        }

        return ['current' => $current, 'previous' => isset($recent[0]) ? ($recent[0]['make'])() : null];
    }

    /**
     * The last few finished plays from Trakt (and Plex), newest first,
     * de-duplicated. Each entry builds its full card data only when asked
     * (via 'make'), since that involves poster and rating lookups and at
     * most two are ever shown.
     *
     * @return array<int, array{at: int, id: string, make: callable}>
     */
    private function recentPlays(int $ttl): array
    {
        $plays = [];
        foreach ($this->trakt->getRecentHistory(3, $ttl) as $item) {
            $plays[] = [
                'at'   => isset($item['watched_at']) ? (int) strtotime($item['watched_at']) : 0,
                'id'   => self::playIdentity('trakt', $item),
                'make' => fn() => $this->describe($item, false),
            ];
        }
        foreach ($this->plex->recentHistory(3, $ttl) as $item) {
            $plays[] = [
                'at'   => (int) $item['viewedAt'],
                'id'   => self::playIdentity('plex', $item),
                'make' => fn() => $this->describePlex($item, false),
            ];
        }

        // Newest first. When both sources have the same play (same time),
        // Trakt's copy comes first and is the one kept below — it carries
        // Trakt's link and episode title. (usort is stable from PHP 8.)
        usort($plays, fn($a, $b) => $b['at'] <=> $a['at']);

        $seen = [];
        $unique = [];
        foreach ($plays as $p) {
            if ($p['id'] !== '' && isset($seen[$p['id']])) {
                continue; // the same play, as reported by the other source
            }
            $seen[$p['id']] = true;
            $unique[] = $p;
        }

        return $unique;
    }

    /**
     * What a play is, independent of which service reported it: type, title
     * and episode code (or year, for a movie). Two sources' copies of one
     * play match even when their episode titles are worded differently.
     */
    private static function playIdentity(string $source, array $item): string
    {
        if ($source === 'plex') {
            $isEpisode = ($item['type'] ?? '') === 'episode';
            $title = $isEpisode ? ($item['grandparentTitle'] ?? '') : ($item['title'] ?? '');
            $detail = $isEpisode ? Trakt::episodeCode((int) ($item['parentIndex'] ?? 0), (int) ($item['index'] ?? 0)) : (string) ($item['year'] ?? '');
        } elseif (($item['type'] ?? '') === 'episode') {
            $title = $item['show']['title'] ?? '';
            $detail = Trakt::episodeCode((int) ($item['episode']['season'] ?? 0), (int) ($item['episode']['number'] ?? 0));
        } else {
            $title = $item['movie']['title'] ?? '';
            $detail = (string) ($item['movie']['year'] ?? '');
        }

        return $title === '' ? '' : ($item['type'] ?? '') . '|' . mb_strtolower(trim($title)) . '|' . $detail;
    }

    /**
     * Normalizes a /watching or /history item into what the hero card shows.
     */
    private function describe(array $item, bool $live): ?array
    {
        $type = $item['type'] ?? '';
        if ($type === 'movie' && isset($item['movie'])) {
            $media = $item['movie'];
            $key = 'm' . ($media['ids']['trakt'] ?? '');
            $title = $media['title'] ?? '';
            $subtitle = isset($media['year']) ? (string) $media['year'] : '';
            $runtime = (int) ($media['runtime'] ?? 0);
            $url = isset($media['ids']['slug']) ? 'https://trakt.tv/movies/' . $media['ids']['slug'] : null;
            $posterType = 'movie';
        } elseif ($type === 'episode' && isset($item['show'], $item['episode'])) {
            $media = $item['show'];
            $ep = $item['episode'];
            $key = 's' . ($media['ids']['trakt'] ?? '');
            $title = $media['title'] ?? '';
            $code = Trakt::episodeCode((int) ($ep['season'] ?? 0), (int) ($ep['number'] ?? 0));
            $subtitle = $code . (!empty($ep['title']) ? ' · ' . $ep['title'] : '');
            $runtime = (int) ($ep['runtime'] ?? 0) ?: (int) ($media['runtime'] ?? 0);
            $url = isset($media['ids']['slug'])
                ? 'https://trakt.tv/shows/' . $media['ids']['slug'] . '/seasons/' . (int) ($ep['season'] ?? 0) . '/episodes/' . (int) ($ep['number'] ?? 0)
                : null;
            $posterType = 'show';
        } else {
            return null;
        }

        $poster = Trakt::imageUrl($media, 'poster') ?? ($this->library->title($key)['p'] ?? null);
        $fanart = Trakt::imageUrl($media, 'fanart') ?? ($this->library->title($key)['f'] ?? null);
        if ($poster === null || $fanart === null) {
            $tmdb = $this->posters->lookup($posterType, isset($media['ids']['tmdb']) ? (int) $media['ids']['tmdb'] : null);
            $poster = $poster ?? $tmdb['poster'];
            $fanart = $fanart ?? $tmdb['fanart'];
        }

        $genres = array_map([Trakt::class, 'prettyGenre'], array_slice((array) ($media['genres'] ?? []), 0, 3));

        return [
            'live'       => $live,
            'type'       => $type,
            'info_key'   => $key !== null && preg_match('/^[ms]\d+$/', $key) ? $key : null, // poster hover card
            'title'      => $title,
            'subtitle'   => $subtitle,
            'meta'       => implode(' · ', array_filter([$runtime ? $runtime . ' min' : '', implode(', ', $genres)])),
            'ratings'    => $this->widgets->titleChips($key, Library::traktPercent($media), true),
            'image'      => $poster,
            'backdrop'   => $fanart ?? $poster,
            'url'        => $url,
            'action'     => $item['action'] ?? null, // scrobble | checkin | watch
            'started_at' => isset($item['started_at']) ? strtotime($item['started_at']) : null,
            'expires_at' => isset($item['expires_at']) ? strtotime($item['expires_at']) : null,
            'watched_at' => isset($item['watched_at']) ? strtotime($item['watched_at']) : null,
        ];
    }

    /**
     * Normalizes a Plex /status/sessions entry (or, with $live false, a
     * play-history entry) into the same shape as describe(). For a live
     * session, progress comes from Plex's real playback position; the
     * start/end times are derived from it so the browser can keep the bar
     * moving between polls (and freeze it when paused).
     */
    private function describePlex(array $s, bool $live): array
    {
        $isEpisode = ($s['type'] ?? '') === 'episode';
        $title = $isEpisode ? (string) ($s['grandparentTitle'] ?? '') : (string) ($s['title'] ?? '');
        $year = $isEpisode ? null : ($s['year'] ?? null);
        $durationMs = (int) ($s['duration'] ?? 0);
        $offsetMs = min($durationMs, (int) ($s['viewOffset'] ?? 0));
        $paused = ($s['Player']['state'] ?? '') === 'paused';
        $startedAt = time() - intdiv($offsetMs, 1000);

        // Match against the local Trakt history for a link (and a poster,
        // if Trakt had one) — by exact title, and year for movies.
        $key = $this->library->findTitleKey($isEpisode ? 's' : 'm', $title, $isEpisode ? null : ($year !== null ? (int) $year : null));
        $known = $key !== null ? $this->library->title($key) : null;
        $url = !empty($known['slug']) ? 'https://trakt.tv/' . ($isEpisode ? 'shows/' : 'movies/') . $known['slug'] : null;

        $subtitle = $isEpisode
            ? Trakt::episodeCode((int) ($s['parentIndex'] ?? 0), (int) ($s['index'] ?? 0)) . (!empty($s['title']) ? ' · ' . $s['title'] : '')
            : (string) ($year ?? '');

        $genres = array_map(fn($g) => $g['tag'] ?? '', array_slice((array) ($s['Genre'] ?? []), 0, 3));
        $runtime = (int) round($durationMs / 60000);

        $poster = Plex::artUrl($isEpisode ? ($s['grandparentThumb'] ?? $s['thumb'] ?? null) : ($s['thumb'] ?? null), 'poster') ?? ($known['p'] ?? null);
        $backdrop = Plex::artUrl($isEpisode ? ($s['grandparentArt'] ?? $s['art'] ?? null) : ($s['art'] ?? null), 'backdrop') ?? ($known['f'] ?? $poster);

        return [
            'live'       => $live,
            'source'     => 'plex',
            'type'       => $isEpisode ? 'episode' : 'movie',
            'info_key'   => $key !== null && preg_match('/^[ms]\d+$/', $key) ? $key : null, // poster hover card
            'title'      => $title,
            'subtitle'   => $subtitle,
            'meta'       => implode(' · ', array_filter([$runtime ? $runtime . ' min' : '', implode(', ', array_filter($genres))])),
            'ratings'    => $this->widgets->titleChips($key, null, true),
            'image'      => $poster,
            'backdrop'   => $backdrop,
            'url'        => $url,
            'action'     => 'plex',
            'paused'     => $live && $paused,
            'progress'   => $live && $durationMs > 0 ? $offsetMs / $durationMs : null,
            'started_at' => $live && $durationMs > 0 ? $startedAt : null,
            'expires_at' => $live && $durationMs > 0 ? $startedAt + intdiv($durationMs, 1000) : null,
            'watched_at' => $live ? null : (int) ($s['viewedAt'] ?? 0),
        ];
    }

    /**
     * Title keys the page shows (or could show) — every Top Shows row for
     * every period, every film (since Top Movies is ranked by score), and
     * the most recently watched — so ratings are fetched for those before
     * the rest of the library.
     *
     * @return string[]
     */
    public function onPageTitleKeys(): array
    {
        $keys = [];
        foreach (Trakt::UI_PERIODS as $period) {
            foreach ($this->library->topTitles('e', Library::periodStart($period, $this->tz), (int) $this->config['top_limit']) ?? [] as $t) {
                $keys[] = $t['key'];
            }
        }

        // Top Movies is ranked by rating, so which films appear depends on
        // their scores — every film watched this year is a candidate,
        // ahead of older ones.
        foreach ($this->library->topTitles('m', Library::periodStart('this_year', $this->tz), PHP_INT_MAX) ?? [] as $t) {
            $keys[] = $t['key'];
        }

        $plays = $this->library->storedPlays();
        usort($plays, fn($a, $b) => $b[1] <=> $a[1]);
        foreach (array_slice($plays, 0, 10) as $p) {
            $keys[] = $p[3];
        }

        foreach ($this->library->topTitles('m', 0, PHP_INT_MAX) ?? [] as $t) {
            $keys[] = $t['key'];
        }

        return array_values(array_unique($keys));
    }

    /**
     * The page-level stats row: Trakt's own lifetime totals when it'll
     * share them, otherwise the same figures worked out from the local
     * history snapshot. Trakt's /stats endpoint returns an empty 204 to an
     * app that isn't signed in, even for a public profile.
     */
    public function lifetimeStats(): array
    {
        $stats = $this->trakt->getStats();

        if ($stats === null && $this->library->exists()) {
            $stats = $this->library->lifetimeTotals()
                + ['ratings' => ['total' => (int) $this->trakt->getRatingsCount()]];
        }

        return Trakt::formatLifetimeStats($stats, $this->trakt->getProfile(), $this->library->firstPlayAt());
    }
}
