<?php

require_once __DIR__ . '/Trakt.php';

/**
 * Maintains a local, gzip-compressed snapshot of the user's full Trakt watch
 * history under cache/, built up in bounded batches by cron.php rather than
 * fetched live on every request. Each play is stored compactly (history id,
 * timestamp, movie/episode, which title, season/episode number, runtime),
 * alongside a per-title map of metadata (name, year, genres, runtime, ids,
 * poster) that every play of that title shares.
 *
 * Once a UI period's start falls inside what's been backfilled, every panel
 * and widget can be computed straight from this file — exact calendar
 * boundaries, zero live API calls. Query methods return null when the
 * snapshot doesn't reach back far enough yet; callers report that honestly
 * rather than showing a misleadingly partial result.
 *
 * Backfill walks pages of one history query anchored at the moment the
 * snapshot was created (Trakt's end_at), so plays arriving between cron
 * runs can't shift page boundaries. That's a fixed anchor plus a page
 * counter rather than lastfm-dash's "oldest timestamp seen so far" anchor:
 * on Trakt, bulk "mark season as watched" commonly gives hundreds of plays
 * the exact same watched_at, which a timestamp anchor can never step past.
 * Newer plays are picked up separately by syncRecent() (start_at).
 *
 * Trakt also lets you add plays with a past date, or remove them, neither of
 * which a forward-only sync can notice. So once the backfill completes, a
 * fresh shadow copy is rebuilt every library_rebuild_days in the
 * background (see maintainRebuild()) and swapped in when it's done — the
 * live copy keeps serving the whole time.
 */
class Library
{
    private Trakt $trakt;
    private string $file;
    private string $rebuildFile;
    private ?array $stateCache = null;

    public function __construct(Trakt $trakt, string $user, string $suffix = '')
    {
        $this->trakt = $trakt;
        $base = __DIR__ . '/../cache/library_' . md5($user);
        $this->file = $base . $suffix . '.json.gz';
        $this->rebuildFile = $base . '.rebuild.json.gz';
    }

    private function load(): array
    {
        if ($this->stateCache !== null) {
            return $this->stateCache;
        }

        $data = null;
        if (is_file($this->file)) {
            $raw = @file_get_contents($this->file);
            $json = $raw !== false ? @gzdecode($raw) : false;
            $data = $json !== false ? json_decode($json, true) : null;
        }

        $this->stateCache = is_array($data) ? ($data + self::emptyState()) : self::emptyState();

        return $this->stateCache;
    }

    private function save(array $state): void
    {
        $this->stateCache = $state;

        Trakt::ensureCacheDir(dirname($this->file));
        // Write-then-rename so a page request never reads a half-written file.
        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, gzencode(json_encode($state), 6)) !== false) {
            @rename($tmp, $this->file);
        }
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    private static function emptyState(): array
    {
        $now = time();

        return [
            'created_at'        => $now,
            'backfill_complete' => false,
            'backfill_anchor'   => $now, // end_at for the whole backfill walk
            'backfill_page'     => 0,    // last page of that walk fetched
            'oldest_seen'       => $now, // local history is complete from here forward
            'synced_through'    => $now, // start_at for syncRecent()
            'plays'             => [],   // each: [history id, unix ts, 'm'|'e', title key, season, number, runtime mins, episode title, manual 0|1]
            'titles'            => [],   // title key ('m123' / 's456') => metadata, see titleFromMedia()
        ];
    }

    private static function iso(int $ts): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $ts);
    }

    /**
     * Folds one /history item into $state. Returns false if it was already
     * known (same history id) or unusable.
     */
    private static function addHistoryItem(array &$state, array $item, array &$known): bool
    {
        $hid = $item['id'] ?? null;
        $ts = isset($item['watched_at']) ? strtotime($item['watched_at']) : false;
        if ($hid === null || $ts === false || isset($known[$hid])) {
            return false;
        }

        // "watch" = added by hand ("mark as watched") rather than scrobbled
        // or checked in live — see isManual().
        $manual = ($item['action'] ?? '') === 'watch' ? 1 : 0;

        if (($item['type'] ?? '') === 'movie' && isset($item['movie']['ids']['trakt'])) {
            $movie = $item['movie'];
            $key = 'm' . $movie['ids']['trakt'];
            $state['titles'][$key] = self::titleFromMedia($movie, $state['titles'][$key] ?? null);
            $state['plays'][] = [$hid, $ts, 'm', $key, 0, 0, (int) ($movie['runtime'] ?? 0), '', $manual];
        } elseif (($item['type'] ?? '') === 'episode' && isset($item['show']['ids']['trakt'])) {
            $show = $item['show'];
            $ep = $item['episode'] ?? [];
            $key = 's' . $show['ids']['trakt'];
            $state['titles'][$key] = self::titleFromMedia($show, $state['titles'][$key] ?? null);
            $runtime = (int) ($ep['runtime'] ?? 0) ?: (int) ($show['runtime'] ?? 0);
            $state['plays'][] = [$hid, $ts, 'e', $key, (int) ($ep['season'] ?? 0), (int) ($ep['number'] ?? 0), $runtime, (string) ($ep['title'] ?? ''), $manual];
        } else {
            return false;
        }

        $known[$hid] = true;

        return true;
    }

    private static function titleFromMedia(array $media, ?array $existing): array
    {
        return [
            't'    => (string) ($media['title'] ?? ''),
            'y'    => isset($media['year']) ? (int) $media['year'] : null,
            'g'    => array_values(array_filter((array) ($media['genres'] ?? []), 'is_string')),
            'r'    => (int) ($media['runtime'] ?? 0),
            'slug' => $media['ids']['slug'] ?? null,
            'tmdb' => isset($media['ids']['tmdb']) ? (int) $media['ids']['tmdb'] : null,
            'imdb' => $media['ids']['imdb'] ?? null,
            'p'    => Trakt::imageUrl($media, 'poster') ?? ($existing['p'] ?? null),
            'f'    => Trakt::imageUrl($media, 'fanart') ?? ($existing['f'] ?? null),
        ];
    }

    private static function knownIds(array $state): array
    {
        $known = [];
        foreach ($state['plays'] as $p) {
            $known[$p[0]] = true;
        }

        return $known;
    }

    /**
     * Fetches up to $maxPages more pages (100 plays each) of older history.
     * Meant to be called from cron.php every run, paced across many runs for
     * a large history.
     *
     * @return array{pages: int, plays: int, complete: bool}
     */
    public function backfillBatch(int $maxPages): array
    {
        $state = $this->load();

        if ($state['backfill_complete']) {
            return ['pages' => 0, 'plays' => 0, 'complete' => true];
        }

        $known = self::knownIds($state);
        $pages = 0;
        $added = 0;

        for ($i = 0; $i < $maxPages; $i++) {
            $page = $state['backfill_page'] + 1;
            $result = $this->trakt->callWithMeta('/users/' . $this->trakt->userSlug() . '/history', [
                'page'     => $page,
                'limit'    => 100,
                'end_at'   => self::iso($state['backfill_anchor']),
                'extended' => 'full,images',
            ], 0);

            if ($result === null) {
                break; // transient failure or rate limit — resume from this page next run
            }

            $items = is_array($result['data']) ? $result['data'] : [];
            foreach ($items as $item) {
                if (self::addHistoryItem($state, $item, $known)) {
                    $added++;
                }
                $ts = isset($item['watched_at']) ? strtotime($item['watched_at']) : false;
                if ($ts !== false) {
                    $state['oldest_seen'] = min($state['oldest_seen'], $ts);
                }
            }

            $state['backfill_page'] = $page;
            $pages++;

            if (empty($items) || $page >= $result['page_count']) {
                $state['backfill_complete'] = true;
                break;
            }
        }

        if ($pages > 0) {
            $this->save($state);
        }

        return ['pages' => $pages, 'plays' => $added, 'complete' => $state['backfill_complete']];
    }

    /**
     * Pulls any plays at or after the last sync point, deduplicated by
     * history id. Cheap and safe to call every cron run regardless of
     * backfill progress. The sync point only advances once every page of
     * new plays has been read, so a large burst (say, an import) that hits
     * the 50-page safety cap is simply re-read and finished next run rather
     * than partly skipped.
     *
     * @return array{plays: int}
     */
    public function syncRecent(): array
    {
        $state = $this->load();
        $known = self::knownIds($state);
        $added = 0;
        $newest = $state['synced_through'];
        $complete = false;

        for ($page = 1; $page <= 50; $page++) {
            $result = $this->trakt->callWithMeta('/users/' . $this->trakt->userSlug() . '/history', [
                'page'     => $page,
                'limit'    => 100,
                'start_at' => self::iso($state['synced_through']),
                'extended' => 'full,images',
            ], 0);

            if ($result === null) {
                break;
            }

            $items = is_array($result['data']) ? $result['data'] : [];
            foreach ($items as $item) {
                if (self::addHistoryItem($state, $item, $known)) {
                    $added++;
                }
                $ts = isset($item['watched_at']) ? strtotime($item['watched_at']) : false;
                if ($ts !== false) {
                    $newest = max($newest, $ts);
                }
            }

            if (empty($items) || $page >= $result['page_count']) {
                $complete = true;
                break;
            }
        }

        if ($complete) {
            $state['synced_through'] = $newest;
        }

        if ($added > 0 || $complete) {
            $this->save($state);
        }

        return ['plays' => $added];
    }

    /**
     * Keeps a periodic full re-download going in the background (see the
     * class comment for why). Called from cron.php each run after the
     * normal sync. Does nothing until the live copy's first backfill is
     * done, or when $rebuildDays is 0.
     *
     * @return array{status: string, pages?: int}
     */
    public function maintainRebuild(int $rebuildDays, int $pagesPerRun): array
    {
        $state = $this->load();

        if ($rebuildDays <= 0 || !$state['backfill_complete']) {
            return ['status' => 'idle'];
        }

        $shadow = new Library($this->trakt, '', '');
        $shadow->file = $this->rebuildFile;

        if (!$shadow->exists()) {
            if (time() - (int) $state['created_at'] < $rebuildDays * 86400) {
                return ['status' => 'idle'];
            }
            $shadow->save(self::emptyState());
        }

        $result = $shadow->backfillBatch($pagesPerRun);

        if ($result['complete']) {
            // The shadow's own sync point is its creation time, so catch the
            // swapped-in copy up from there straight away — otherwise plays
            // that arrived mid-rebuild would vanish until the next run.
            @rename($this->rebuildFile, $this->file);
            $this->stateCache = null;
            $this->syncRecent();

            return ['status' => 'swapped', 'pages' => $result['pages']];
        }

        return ['status' => 'rebuilding', 'pages' => $result['pages']];
    }

    // --- Queries ---------------------------------------------------------

    /**
     * Exact calendar start timestamp for a UI period key, in $tz.
     */
    public static function periodStart(string $uiPeriod, DateTimeZone $tz): int
    {
        $today = new DateTime('today', $tz); // 00:00:00 today

        switch ($uiPeriod) {
            case 'today':
                return $today->getTimestamp();
            case 'this_week':
                $isoDow = (int) $today->format('N'); // 1 (Mon) .. 7 (Sun)
                return (clone $today)->modify('-' . ($isoDow - 1) . ' days')->getTimestamp();
            case 'this_month':
                return (clone $today)->modify('first day of this month')->getTimestamp();
            case 'this_year':
                return (new DateTime($today->format('Y') . '-01-01', $tz))->getTimestamp();
            default:
                return 0; // all_time
        }
    }

    public function covers(int $sinceUnix): bool
    {
        $state = $this->load();
        if ($state['backfill_complete']) {
            return true;
        }

        return $sinceUnix > 0 && $state['backfill_page'] > 0 && $sinceUnix >= $state['oldest_seen'];
    }

    /**
     * Plays in [$since, $until], in stored (unordered) form.
     *
     * @return array<int, array>|null
     */
    public function plays(int $sinceUnix = 0, ?int $untilUnix = null, ?string $type = null): ?array
    {
        if (!$this->covers($sinceUnix)) {
            return null;
        }

        $state = $this->load();
        $out = [];
        foreach ($state['plays'] as $p) {
            if ($p[1] < $sinceUnix || ($untilUnix !== null && $p[1] > $untilUnix)) {
                continue;
            }
            if ($type !== null && $p[2] !== $type) {
                continue;
            }
            $out[] = $p;
        }

        return $out;
    }

    /**
     * Every stored play regardless of backfill progress, for widgets that
     * are still meaningful on a partial history (labelled as such via
     * coverage()) rather than unavailable until the backfill finishes.
     */
    public function storedPlays(): array
    {
        return $this->load()['plays'];
    }

    /**
     * Whether a play was added by hand on Trakt ("mark as watched") rather
     * than scrobbled or checked in as it happened. Its timestamp is then
     * whatever was picked when marking it — "now" for a whole season at
     * once, or the release date — not when it was really watched, so the
     * time-of-day / day-of-week / binge / streak widgets leave these out.
     * They still count towards totals, genres, and top lists.
     */
    public static function isManual(array $play): bool
    {
        return !empty($play[8]);
    }

    public function title(string $key): ?array
    {
        return $this->load()['titles'][$key] ?? null;
    }

    /**
     * Runtime to credit a play with: its own (episode) runtime, else the
     * title's, else a typical default — Trakt occasionally has none.
     */
    public function playMinutes(array $play): int
    {
        if ($play[6] > 0) {
            return $play[6];
        }
        $title = $this->title($play[3]);
        if (!empty($title['r'])) {
            return (int) $title['r'];
        }

        return $play[2] === 'm' ? 110 : 40;
    }

    /**
     * Most-watched titles of one type for a period: shows ranked by episodes
     * watched, movies by number of plays (then most recent).
     *
     * @return array<int, array{key:string, title:string, year:?int, plays:int, minutes:int, last:int, poster:?string, slug:?string, tmdb:?int}>|null
     */
    public function topTitles(string $type, int $sinceUnix, int $limit): ?array
    {
        $plays = $this->plays($sinceUnix, null, $type);
        if ($plays === null) {
            return null;
        }

        $agg = [];
        foreach ($plays as $p) {
            $key = $p[3];
            if (!isset($agg[$key])) {
                $agg[$key] = ['key' => $key, 'plays' => 0, 'minutes' => 0, 'last' => 0];
            }
            $agg[$key]['plays']++;
            $agg[$key]['minutes'] += $this->playMinutes($p);
            $agg[$key]['last'] = max($agg[$key]['last'], $p[1]);
        }

        usort($agg, fn($a, $b) => [$b['plays'], $b['last']] <=> [$a['plays'], $a['last']]);

        $out = [];
        foreach (array_slice($agg, 0, $limit) as $row) {
            $t = $this->title($row['key']) ?? [];
            $out[] = $row + [
                'title'  => $t['t'] ?? '?',
                'year'   => $t['y'] ?? null,
                'poster' => $t['p'] ?? null,
                'slug'   => $t['slug'] ?? null,
                'tmdb'   => $t['tmdb'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Genre breakdown for a period, weighted by minutes watched (so one
     * film doesn't count the same as a 60-episode binge). A title with
     * several genres contributes its full time to each of them.
     *
     * @return array<int, array{name:string, pct:float, minutes:int}>|null
     */
    public function genres(int $sinceUnix): ?array
    {
        $plays = $this->plays($sinceUnix);
        if ($plays === null) {
            return null;
        }

        $scores = [];
        foreach ($plays as $p) {
            $minutes = $this->playMinutes($p);
            foreach ($this->title($p[3])['g'] ?? [] as $slug) {
                $scores[$slug] = ($scores[$slug] ?? 0) + $minutes;
            }
        }

        $total = array_sum($scores);
        if ($total <= 0) {
            return [];
        }

        arsort($scores);
        $out = [];
        foreach ($scores as $slug => $minutes) {
            $out[] = [
                'name'    => Trakt::prettyGenre((string) $slug),
                'pct'     => round($minutes / $total * 100, 1),
                'minutes' => (int) $minutes,
            ];
        }

        return $out;
    }

    /**
     * Totals for a period: plays, movies, episodes, distinct titles, minutes.
     */
    public function summary(int $sinceUnix, ?int $untilUnix = null): ?array
    {
        $plays = $this->plays($sinceUnix, $untilUnix);
        if ($plays === null) {
            return null;
        }

        $movies = 0;
        $episodes = 0;
        $minutes = 0;
        $shows = [];
        foreach ($plays as $p) {
            $minutes += $this->playMinutes($p);
            if ($p[2] === 'm') {
                $movies++;
            } else {
                $episodes++;
                $shows[$p[3]] = true;
            }
        }

        return [
            'plays'    => count($plays),
            'movies'   => $movies,
            'episodes' => $episodes,
            'shows'    => count($shows),
            'minutes'  => $minutes,
        ];
    }

    /**
     * Individual plays as readable records, newest first — for callers (the
     * MCP server) that want date/time-level detail rather than aggregates.
     *
     * @return array<int, array>|null
     */
    public function history(int $sinceUnix, ?int $untilUnix, ?string $type, int $limit, DateTimeZone $tz): ?array
    {
        $plays = $this->plays($sinceUnix, $untilUnix, $type);
        if ($plays === null) {
            return null;
        }

        usort($plays, fn($a, $b) => $b[1] <=> $a[1]);

        $out = [];
        foreach (array_slice($plays, 0, max(1, $limit)) as $p) {
            $t = $this->title($p[3]) ?? [];
            $row = [
                'watched_at' => (new DateTime('@' . $p[1]))->setTimezone($tz)->format(DATE_ATOM),
                'type'       => $p[2] === 'm' ? 'movie' : 'episode',
                'title'      => $t['t'] ?? '?',
                'year'       => $t['y'] ?? null,
                'runtime'    => $this->playMinutes($p),
            ];
            if ($p[2] === 'e') {
                $row['episode'] = Trakt::episodeCode($p[4], $p[5]);
                $row['episode_title'] = $p[7];
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Snapshot status — how far back local history reaches and whether it's
     * fully caught up — so callers can honestly say why something isn't
     * available yet.
     */
    public function coverage(): array
    {
        $state = $this->load();

        return [
            'backfill_complete' => $state['backfill_complete'],
            'covered_since'     => $state['backfill_page'] > 0 ? $state['oldest_seen'] : null,
            'synced_through'    => $state['synced_through'],
            'play_count'        => count($state['plays']),
            'rebuilding'        => is_file($this->rebuildFile),
        ];
    }

    /**
     * Fills in missing posters from TMDB (when configured) for the titles
     * that matter most — the heaviest-watched first — a bounded number per
     * cron run, so page loads find them already cached.
     */
    public function backfillPosters(Posters $posters, int $max): int
    {
        if (!$posters->enabled()) {
            return 0;
        }

        $state = $this->load();
        $counts = [];
        foreach ($state['plays'] as $p) {
            $counts[$p[3]] = ($counts[$p[3]] ?? 0) + 1;
        }
        arsort($counts);

        $looked = 0;
        foreach (array_keys($counts) as $key) {
            if ($looked >= $max) {
                break;
            }
            $t = $state['titles'][$key] ?? null;
            if (!$t || !empty($t['p']) || empty($t['tmdb'])) {
                continue;
            }
            $type = $key[0] === 'm' ? 'movie' : 'show';
            if ($posters->isCached($type, $t['tmdb'])) {
                continue; // already looked up (even if TMDB had nothing)
            }
            $posters->lookup($type, $t['tmdb']);
            $looked++;
        }

        return $looked;
    }
}
