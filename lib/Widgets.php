<?php

require_once __DIR__ . '/Trakt.php';
require_once __DIR__ . '/Library.php';
require_once __DIR__ . '/Posters.php';
require_once __DIR__ . '/Ratings.php';
require_once __DIR__ . '/Awards.php';
require_once __DIR__ . '/Streaming.php';
require_once __DIR__ . '/TmdbDetails.php';
require_once __DIR__ . '/Soundtracks.php';

/**
 * Computes the insight widgets (the clickable cards below the panels).
 * Almost everything here comes from the local history snapshot — see
 * lib/Library.php — so these cost no live API calls at all once it's
 * synced. The exceptions are Hot Takes (your ratings), which reads its own
 * Trakt endpoint, cached an hour, and Streaming Changes (see Streaming).
 *
 * Widgets built from the whole history still work while the backfill is
 * in progress: they use whatever's synced so far and say how far back
 * that reaches (see coverageNote()), rather than refusing to show anything.
 */
class Widgets
{
    private Trakt $trakt;
    private Library $library;
    private Posters $posters;
    private Ratings $ratings;
    private Awards $awards;
    private Streaming $streaming;
    private TmdbDetails $tmdbDetails;
    private Soundtracks $soundtracks;
    private array $config;
    private DateTimeZone $tz;
    private ?array $myRatings = null;

    public function __construct(Trakt $trakt, Library $library, Posters $posters, Ratings $ratings, Awards $awards,
        Streaming $streaming, TmdbDetails $tmdbDetails, Soundtracks $soundtracks, array $config)
    {
        $this->trakt = $trakt;
        $this->library = $library;
        $this->posters = $posters;
        $this->ratings = $ratings;
        $this->awards = $awards;
        $this->streaming = $streaming;
        $this->tmdbDetails = $tmdbDetails;
        $this->soundtracks = $soundtracks;
        $this->config = $config;
        $this->tz = Trakt::resolveTimezone($config['timezone'] ?? '');
    }

    private function localTime(int $ts): DateTime
    {
        return (new DateTime('@' . $ts))->setTimezone($this->tz);
    }

    /**
     * Honest "this is based on X" footnote for whole-history widgets.
     */
    private function coverageNote(int $playCount, int $bulkExcluded = 0): string
    {
        $coverage = $this->library->coverage();
        $note = 'Based on ' . number_format($playCount) . ' plays';

        if (!$coverage['backfill_complete']) {
            $note .= $coverage['covered_since']
                ? ' back to ' . $this->localTime($coverage['covered_since'])->format('j M Y') . ' — older history is still syncing'
                : ' — history is still syncing';
        }
        $note .= '.';

        if ($bulkExcluded > 0) {
            $note .= ' Leaves out ' . number_format($bulkExcluded) . ' plays logged in bulk at the exact same second (e.g. "mark season as watched"), since their times aren\'t real watch times.';
        }

        return $note;
    }

    /**
     * See Library::timedPlays().
     */
    private function timedPlays(): array
    {
        return $this->library->timedPlays();
    }

    /**
     * Poster for a title key, from the snapshot's Trakt image data or, if
     * that had none, an already-cached TMDB lookup (cron.php fills those in
     * ahead of time — see Library::backfillPosters()).
     */
    public function posterFor(string $key): ?string
    {
        $t = $this->library->title($key);
        if (!$t) {
            return null;
        }
        if (!empty($t['p'])) {
            return $t['p'];
        }

        return $this->posters->lookup($key[0] === 'm' ? 'movie' : 'show', $t['tmdb'] ?? null, true)['poster'];
    }

    /**
     * Score chips shown next to a title: IMDb rating and Rotten Tomatoes
     * Popcornmeter (from MDBList, when configured and already cached) and
     * Trakt's viewer rating (from Trakt itself, falling back to MDBList's
     * copy). Missing scores are simply left out.
     *
     * @param ?int $traktPercent a fresher Trakt score to prefer, e.g. from a live API response
     * @return array<int, array{kind: string, label: string, value: string, title: string}>
     */
    /**
     * Your own Trakt rating (1–10) for a movie or show, or null if unrated.
     */
    public function myRating(string $key): ?int
    {
        if ($this->myRatings === null) {
            $this->myRatings = [];
            foreach ($this->trakt->getRatings() as $r) {
                $type = $r['type'] ?? '';
                $id = $r[$type]['ids']['trakt'] ?? null;
                if (($type === 'movie' || $type === 'show') && $id !== null) {
                    $this->myRatings[($type === 'movie' ? 'm' : 's') . $id] = (int) $r['rating'];
                }
            }
        }

        return $this->myRatings[$key] ?? null;
    }

    /**
     * Combined community score (0–100): the average of whichever of IMDb
     * (scaled from 0–10), Trakt and the Rotten Tomatoes Popcornmeter the
     * title has. Null if it has none.
     */
    public function communityScore(string $key): ?int
    {
        $mdb = $this->ratings->lookup($key) ?? [];
        $scores = array_filter([
            !empty($mdb['imdb']) ? $mdb['imdb'] * 10 : null,
            $this->library->title($key)['tr'] ?? $mdb['trakt'] ?? null,
            $mdb['popcorn'] ?? null,
        ], fn($v) => $v !== null);

        return $scores ? (int) round(array_sum($scores) / count($scores)) : null;
    }

    /**
     * Top Movies for a period, best first: by your own rating, then the
     * combined community score, with plays only breaking ties. For a film
     * you haven't rated, the community score stands in for your rating —
     * otherwise a film you gave 2/10 would outrank an unrated 90% one.
     *
     * @return array<int, array>|null topTitles() rows plus 'mine' and 'community', or null while syncing
     */
    public function topMovies(int $sinceUnix, int $limit): ?array
    {
        $movies = $this->library->topTitles('m', $sinceUnix, PHP_INT_MAX);
        if ($movies === null) {
            return null;
        }

        foreach ($movies as &$m) {
            $m['mine'] = $this->myRating($m['key']);
            $m['community'] = $this->communityScore($m['key']);
        }
        unset($m);

        $sortKey = fn($m) => [
            $m['mine'] !== null ? $m['mine'] * 10 : ($m['community'] ?? -1),
            $m['community'] ?? -1,
            $m['plays'],
            $m['last'],
        ];
        usort($movies, fn($a, $b) => $sortKey($b) <=> $sortKey($a));

        return array_slice($movies, 0, $limit);
    }

    /**
     * Chips for a title: show progress first (shows only), then scores,
     * then awards.
     */
    public function titleChips(?string $key, ?int $traktPercent = null, bool $allowLookup = false): array
    {
        // Only titles already in the library: anything else (just started,
        // not synced yet) would be stored as award-less for a month.
        $imdb = $key !== null ? ($this->library->title($key)['imdb'] ?? null) : null;
        if ($allowLookup && is_string($imdb)) {
            $this->awards->lookupNow($key, $imdb);
        }
        $awards = Awards::chip($this->awards->lookup($key));

        return array_merge($this->progressChips($key), $this->ratingChips($key, $traktPercent, $allowLookup), $awards ? [$awards] : []);
    }

    /**
     * A title's award wins and nominations — see Awards::lookup().
     */
    public function awards(?string $key): array
    {
        return $this->awards->lookup($key);
    }

    /**
     * "Watched 75%" chip for a show — see Library::showProgress().
     */
    public function progressChips(?string $key): array
    {
        $progress = $key !== null ? $this->library->showProgress($key) : null;
        if ($progress === null) {
            return [];
        }

        return [[
            'kind'  => 'progress' . ($progress['pct'] >= 100 ? ' rating-complete' : ''),
            'label' => 'Watched',
            'value' => $progress['pct'] . '%',
            'title' => number_format($progress['watched']) . ' of ' . number_format($progress['aired']) . ' aired episodes watched (not counting specials)',
        ]];
    }

    public function ratingChips(?string $key, ?int $traktPercent = null, bool $allowLookup = false): array
    {
        if ($key === null || !preg_match('/^[ms]\d+$/', $key)) {
            return [];
        }

        $mdb = $this->ratings->lookup($key, $allowLookup) ?? [];
        $trakt = $traktPercent ?? ($this->library->title($key)['tr'] ?? null) ?? ($mdb['trakt'] ?? null);

        $chips = [];
        if (!empty($mdb['imdb'])) {
            $votes = !empty($mdb['imdb_votes']) ? ' · ' . number_format($mdb['imdb_votes']) . ' votes' : '';
            $chips[] = ['kind' => 'imdb', 'label' => 'IMDb', 'value' => number_format($mdb['imdb'], 1), 'title' => 'IMDb rating' . $votes];
        }
        if ($trakt !== null) {
            $chips[] = ['kind' => 'trakt', 'label' => 'Trakt', 'value' => $trakt . '%', 'title' => 'Trakt viewer rating'];
        }
        if (!empty($mdb['popcorn'])) {
            $chips[] = ['kind' => 'popcorn', 'label' => '🍿', 'value' => $mdb['popcorn'] . '%', 'title' => 'Rotten Tomatoes Popcornmeter (audience score)'];
        }

        return $chips;
    }

    /**
     * Everything the hover card shows for a show or film: Trakt's summary
     * (network/status or tagline/director, synopsis...), where it's
     * streaming, score chips, and
     * your own history with it from the local snapshot — plus, for a show,
     * progress and the next episode due to air.
     *
     * Only titles in your history or on your watchlist are looked up, so
     * this can't be used to proxy arbitrary Trakt requests.
     *
     * @return array{title: string, year: ?int, tagline: string, overview: string, facts: string[], badge: ?array, watch: array, genres: string[], chips: array, rows: array<int, array{label: string, value: string}>, url: ?string}|null
     */
    public function titleInfo(string $key): ?array
    {
        if (!preg_match('/^[ms]\d+$/', $key)) {
            return null;
        }

        $isShow = $key[0] === 's';
        $id = substr($key, 1);
        $local = $this->library->title($key);
        if ($local === null && !$this->onWatchlist($key)) {
            return null;
        }

        $media = $this->trakt->call(($isShow ? '/shows/' : '/movies/') . $id, ['extended' => 'full'], 86400);
        $media = is_array($media) ? $media : [];
        if ($local === null && $media === []) {
            return null;
        }

        $tmdbId = isset($media['ids']['tmdb']) ? (int) $media['ids']['tmdb'] : (isset($local['tmdb']) ? (int) $local['tmdb'] : null);
        $title = (string) ($media['title'] ?? $local['t'] ?? '');
        // Your country's certificate (BBFC in the UK) where TMDB has it.
        $certificate = $this->tmdbDetails->certification($isShow ? 'show' : 'movie', $tmdbId);
        // UK ratings get the BBFC's own symbol (assets/bbfc/, public domain)
        // instead of plain text.
        $badge = $certificate !== null && $this->streaming->regionCode() === 'GB' && preg_match('/^(U|PG|12A|12|15|18|R18)$/', $certificate)
            ? ['src' => 'assets/bbfc/' . $certificate . '.svg', 'alt' => 'BBFC ' . $certificate]
            : null;
        if ($certificate === null && !empty($media['certification'])) {
            // Trakt's, which is the US rating — say so anywhere else.
            $certificate = ($this->streaming->regionCode() === 'US' ? '' : 'US ') . $media['certification'];
        }

        $rows = [];
        $mine = $this->myRating($key);
        if ($mine !== null) {
            $rows[] = ['label' => 'Your rating', 'value' => '★ ' . $mine . '/10'];
        }

        if ($isShow) {
            $statuses = ['returning series' => 'Returning series', 'continuing' => 'Returning series', 'ended' => 'Ended',
                'canceled' => 'Cancelled', 'in production' => 'In production', 'planned' => 'Planned', 'pilot' => 'Pilot', 'upcoming' => 'Upcoming'];
            $facts = [
                $media['network'] ?? null,
                $statuses[strtolower((string) ($media['status'] ?? ''))] ?? null,
                $badge === null ? $certificate : null,
                !empty($media['runtime']) ? $media['runtime'] . ' min' : null,
                isset($media['country']) ? strtoupper($media['country']) : null,
            ];

            $progress = $this->library->showProgress($key);
            if ($progress !== null) {
                $rows[] = ['label' => 'Progress', 'value' => number_format($progress['watched']) . ' of ' . number_format($progress['aired']) . ' episodes (' . $progress['pct'] . '%)'];
            }
            $last = $this->library->lastPlay($key);
            if ($last !== null) {
                $rows[] = ['label' => 'Last watched', 'value' => Trakt::episodeCode($last[4], $last[5])
                    . ($last[7] !== '' ? ' · ' . $last[7] : '') . ' — ' . $this->localTime($last[1])->format('j M Y')];
            }
            // 204 (nothing scheduled) caches as null like any other response.
            $next = $this->trakt->call('/shows/' . $id . '/next_episode', ['extended' => 'full'], 21600);
            if (is_array($next) && isset($next['season'])) {
                $rows[] = ['label' => 'Next episode', 'value' => Trakt::episodeCode((int) $next['season'], (int) ($next['number'] ?? 0))
                    . (!empty($next['title']) ? ' · ' . $next['title'] : '')
                    . (!empty($next['first_aired']) ? ' — ' . $this->localTime((int) strtotime($next['first_aired']))->format('D j M Y') : '')];
            }
            $soundtrack = $this->soundtracks->lookup($key, $title, isset($media['year']) ? (int) $media['year'] : ($local['y'] ?? null));
            if ($soundtrack !== null) {
                $rows[] = ['label' => 'Soundtrack', 'value' => $soundtrack['title'] . ($soundtrack['artist'] !== '' ? ' — ' . $soundtrack['artist'] : '')];
            }
        } else {
            $released = !empty($media['released']) ? date('j M Y', (int) strtotime($media['released'])) : null;
            $facts = [
                $badge === null ? $certificate : null,
                !empty($media['runtime']) ? Trakt::formatMinutes((int) $media['runtime']) : null,
                $released ? 'Released ' . $released : null,
                isset($media['country']) ? strtoupper($media['country']) : null,
            ];

            $directors = [];
            $people = $this->trakt->call('/movies/' . $id . '/people', [], 604800);
            foreach ((array) ($people['crew']['directing'] ?? []) as $c) {
                if (in_array('Director', (array) ($c['jobs'] ?? [$c['job'] ?? '']), true) && !empty($c['person']['name'])) {
                    $directors[] = $c['person']['name'];
                }
            }
            if ($directors) {
                $rows[] = ['label' => count($directors) > 1 ? 'Directors' : 'Director', 'value' => implode(', ', array_slice(array_unique($directors), 0, 3))];
            }

            // Cinema and digital release dates in your country (else the US).
            $releases = $this->tmdbDetails->releaseDates($tmdbId);
            foreach (['cinema' => 'Cinema', 'digital' => 'Digital'] as $kind => $label) {
                if (!empty($releases[$kind])) {
                    $r = $releases[$kind];
                    $rows[] = ['label' => $label, 'value' => $this->releaseDate($r['date'])
                        . ($r['note'] !== '' ? ' (' . $r['note'] . ')' : '')
                        . ($releases['country'] !== $this->streaming->regionCode() ? ' — ' . self::countryName($releases['country']) : '')];
                }
            }

            $plays = array_filter($this->library->storedPlays(), fn($p) => $p[3] === $key);
            if ($plays) {
                $last = $this->library->lastPlay($key);
                $times = count($plays) === 1 ? 'Once' : count($plays) . ' times';
                $rows[] = ['label' => 'You watched', 'value' => $times . ($last !== null ? ' — last ' . $this->localTime($last[1])->format('j M Y') : '')];
            } else {
                $rows[] = ['label' => 'You watched', 'value' => 'Not yet — it\'s on your watchlist'];
            }

            $soundtrack = $this->soundtracks->lookup($key, $title, isset($media['year']) ? (int) $media['year'] : ($local['y'] ?? null));
            if ($soundtrack !== null) {
                $rows[] = ['label' => 'Soundtrack', 'value' => $soundtrack['title'] . ($soundtrack['artist'] !== '' ? ' — ' . $soundtrack['artist'] : '')];
            }

            // "$814.6m worldwide · $175m budget (4.7×)"
            $money = $this->tmdbDetails->boxOffice($tmdbId);
            if ($money['revenue'] !== null || $money['budget'] !== null) {
                $rows[] = ['label' => 'Box office', 'value' => implode(' · ', array_filter([
                    $money['revenue'] !== null ? self::dollars($money['revenue']) . ' worldwide' : null,
                    $money['budget'] !== null ? self::dollars($money['budget']) . ' budget'
                        . ($money['revenue'] !== null ? ' (' . round($money['revenue'] / $money['budget'], 1) . '×)' : '') : null,
                ]))];
            }

            // "Toy Story Collection · part 2 of 5 · 3 seen" — released films only.
            $collection = $this->tmdbDetails->collection($tmdbId);
            if ($collection !== null && count($collection['parts']) > 1) {
                $watched = $this->library->watchedMovieTmdbIds();
                $seen = count(array_filter($collection['parts'], fn($p) => isset($watched[$p['tmdb']])));
                $position = array_search($tmdbId, array_column($collection['parts'], 'tmdb'), true);
                $rows[] = ['label' => 'Collection', 'value' => $collection['name']
                    . ($position !== false ? ' · part ' . ($position + 1) . ' of ' . count($collection['parts']) : '')
                    . ' · ' . $seen . ' of ' . count($collection['parts']) . ' seen'];
            }
        }

        // Awards: one row per ceremony, the biggest few in full.
        $awards = $this->awards->lookup($key);
        foreach (array_slice($awards, 0, 5) as $a) {
            $rows[] = ['label' => '🏆 ' . $a['name'], 'value' => Awards::detail($a)];
        }
        if (count($awards) > 5) {
            $rows[] = ['label' => '🏆 Also', 'value' => implode(', ', array_map(fn($a) => $a['name'] . ($a['wins'] > 0 ? ' (' . $a['wins'] . ')' : ''), array_slice($awards, 5)))];
        }

        $slug = $local['slug'] ?? ($media['ids']['slug'] ?? null);

        return [
            'title'    => $title,
            'year'     => $media['year'] ?? $local['y'] ?? null,
            'tagline'  => (string) ($media['tagline'] ?? ''),
            'overview' => (string) ($media['overview'] ?? ''),
            'facts'    => array_values(array_filter($facts)),
            'badge'    => $badge,
            'watch'    => array_map(fn($l) => ['name' => $l['name'], 'logo' => $l['logo']], $this->streaming->lookup($isShow ? 'show' : 'movie', $tmdbId, $title, $key)),
            'genres'   => array_map([Trakt::class, 'prettyGenre'], array_slice((array) ($media['genres'] ?? $local['g'] ?? []), 0, 4)),
            'chips'    => $this->titleChips($key, Library::traktPercent($media)),
            'rows'     => $rows,
            'url'      => $slug ? 'https://trakt.tv/' . ($isShow ? 'shows/' : 'movies/') . $slug : null,
        ];
    }

    /**
     * "22 Nov 2017", or for one still to come "3 Nov 2026 (in 25 days)".
     */
    private function releaseDate(string $ymd): string
    {
        $date = new DateTime($ymd, $this->tz);
        $today = new DateTime('today', $this->tz);
        $text = $date->format('j M Y');
        if ($date > $today) {
            $days = (int) $today->diff($date)->days;
            $text .= ' (' . ($days === 1 ? 'tomorrow' : 'in ' . $days . ' days') . ')';
        }

        return $text;
    }

    private static function countryName(string $code): string
    {
        return ['US' => 'US', 'GB' => 'UK'][$code] ?? $code;
    }

    /**
     * $1.2bn, $814.6m, $11.4m, $850k
     */
    private static function dollars(int $amount): string
    {
        if ($amount >= 1e9) {
            return '$' . rtrim(rtrim(number_format($amount / 1e9, 2), '0'), '.') . 'bn';
        }
        if ($amount >= 1e6) {
            return '$' . rtrim(rtrim(number_format($amount / 1e6, 1), '0'), '.') . 'm';
        }

        return '$' . number_format(round($amount / 1e3)) . 'k';
    }

    private function onWatchlist(string $key): bool
    {
        foreach ($this->trakt->getWatchlist() as $item) {
            $type = $item['type'] ?? '';
            if (($type === 'movie' || $type === 'show') && ($type === 'movie' ? 'm' : 's') . ($item[$type]['ids']['trakt'] ?? '') === $key) {
                return true;
            }
        }

        return false;
    }

    // --- Widgets ---------------------------------------------------------

    public function watchClock(): array
    {
        [$plays, $bulk] = $this->timedPlays();
        if (!$plays) {
            return ['available' => false];
        }

        $hours = array_fill(0, 24, 0);
        foreach ($plays as $p) {
            $hours[(int) $this->localTime($p[1])->format('G')]++;
        }

        $peak = array_search(max($hours), $hours, true);

        return [
            'hours'       => $hours,
            'label'       => sprintf('Prime time: %02d:00–%02d:00', $peak, ($peak + 1) % 24),
            'sample_note' => 'Times are when each play finished, which is what Trakt records. ' . $this->coverageNote(count($plays), $bulk),
        ];
    }

    public function weekRhythm(): array
    {
        [$plays, $bulk] = $this->timedPlays();
        if (!$plays) {
            return ['available' => false];
        }

        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $minutes = array_fill(0, 7, 0);
        foreach ($plays as $p) {
            $minutes[(int) $this->localTime($p[1])->format('N') - 1] += $this->library->playMinutes($p);
        }

        $peakIdx = array_search(max($minutes), $minutes, true);

        return [
            'days'        => array_map(fn($m) => round($m / 60, 1), $minutes), // hours
            'labels'      => $labels,
            'peak_day'    => $labels[$peakIdx],
            'sample_note' => $this->coverageNote(count($plays), $bulk),
        ];
    }

    /**
     * Total time watched, converted into things. Prefers Trakt's own
     * lifetime totals (exact, and available before the backfill finishes),
     * falling back to the local snapshot's sum.
     */
    public function timeWatched(): array
    {
        $stats = $this->trakt->getStats();
        $minutes = $stats
            ? (int) ($stats['movies']['minutes'] ?? 0) + (int) ($stats['episodes']['minutes'] ?? 0)
            : array_sum(array_map([$this->library, 'playMinutes'], $this->library->storedPlays()));

        if ($minutes <= 0) {
            return ['available' => false];
        }

        // Approximate, and deliberately a bit silly.
        $units = [
            ['label' => 'screenings of Titanic', 'minutes' => 194],
            ['label' => 'London → New York flights', 'minutes' => 480],
            ['label' => 'Lord of the Rings extended trilogy marathons', 'minutes' => 686],
            ['label' => 'full runs of Breaking Bad (62 episodes)', 'minutes' => 2940],
            ['label' => 'trips to the Moon at Apollo speed (~3 days)', 'minutes' => 4320],
            ['label' => 'years of a full-time job (1,820 hrs)', 'minutes' => 109200],
        ];

        $comparisons = [];
        foreach ($units as $u) {
            $exact = $minutes / $u['minutes'];
            if ($exact < 0.05) {
                continue;
            }
            $comparisons[] = [
                'label' => $u['label'],
                'count' => round($exact, $exact < 10 ? 1 : 0),
                'pct'   => (int) round(fmod($exact, 1) * 100),
            ];
        }

        return [
            'total_hours' => (int) round($minutes / 60),
            'total_days'  => round($minutes / 1440, 1),
            'comparisons' => $comparisons,
            'source_note' => $stats ? 'Lifetime totals from Trakt.' : $this->coverageNote(count($this->library->storedPlays())),
        ];
    }

    /**
     * Binge sessions: uninterrupted runs of episodes from the same show,
     * each finishing within 3 hours of the last (Trakt timestamps a play
     * when it ends, so the gap covers the next episode's own runtime too).
     */
    public function binge(): array
    {
        [$plays, $bulk] = $this->timedPlays();
        usort($plays, fn($a, $b) => $a[1] <=> $b[1]);

        $sessions = [];
        $current = null;
        foreach ($plays as $p) {
            $continues = $current !== null
                && $p[2] === 'e'
                && $p[3] === $current['key']
                && $p[1] - $current['end'] <= 3 * 3600;

            if ($continues) {
                $current['episodes']++;
                $current['end'] = $p[1];
                $current['minutes'] += $this->library->playMinutes($p);
                $current['last_ep'] = Trakt::episodeCode($p[4], $p[5]);
                continue;
            }

            if ($current !== null && $current['episodes'] >= 3) {
                $sessions[] = $current;
            }

            $current = $p[2] === 'e' ? [
                'key'      => $p[3],
                'episodes' => 1,
                'start'    => $p[1],
                'end'      => $p[1],
                'minutes'  => $this->library->playMinutes($p),
                'first_ep' => Trakt::episodeCode($p[4], $p[5]),
                'last_ep'  => Trakt::episodeCode($p[4], $p[5]),
            ] : null;
        }
        if ($current !== null && $current['episodes'] >= 3) {
            $sessions[] = $current;
        }

        if (!$sessions) {
            return ['available' => false];
        }

        usort($sessions, fn($a, $b) => [$b['episodes'], $b['minutes']] <=> [$a['episodes'], $a['minutes']]);

        $top = [];
        foreach (array_slice($sessions, 0, 5) as $s) {
            $top[] = [
                'key'      => $s['key'],
                'show'     => $this->library->title($s['key'])['t'] ?? '?',
                'poster'   => $this->posterFor($s['key']),
                'ratings'  => $this->titleChips($s['key']),
                'episodes' => $s['episodes'],
                'hours'    => round($s['minutes'] / 60, 1),
                'date'     => $this->localTime($s['start'])->format('j M Y'),
                'range'    => $s['first_ep'] . '–' . $s['last_ep'],
            ];
        }

        return [
            'sessions'      => $top,
            'session_count' => count($sessions),
            'sample_note'   => 'A binge is 3+ episodes of one show back to back, each within 3 hours of the last. ' . $this->coverageNote(count($plays), $bulk),
        ];
    }

    /**
     * Distinct movies watched, by decade of release.
     */
    public function decades(): array
    {
        $years = [];
        foreach ($this->library->storedPlays() as $p) {
            if ($p[2] !== 'm') {
                continue;
            }
            $year = $this->library->title($p[3])['y'] ?? null;
            if ($year) {
                $years[$p[3]] = (int) $year;
            }
        }

        if (!$years) {
            return ['available' => false];
        }

        $counts = [];
        foreach ($years as $y) {
            $decade = intdiv($y, 10) * 10;
            $counts[$decade] = ($counts[$decade] ?? 0) + 1;
        }
        ksort($counts);

        $oldestKey = array_search(min($years), $years, true);
        $peak = array_search(max($counts), $counts, true);

        return [
            'decades'     => array_map(fn($d, $c) => ['label' => $d . 's', 'count' => $c], array_keys($counts), $counts),
            'peak_decade' => $peak . 's',
            'median_year' => self::median(array_values($years)),
            'oldest'      => ($this->library->title($oldestKey)['t'] ?? '?') . ' (' . $years[$oldestKey] . ')',
            'movie_count' => count($years),
        ];
    }

    /**
     * Longest and current run of consecutive days with something watched,
     * plus a year-long daily calendar (GitHub-contributions style).
     */
    public function streaks(): array
    {
        [$plays, $bulk] = $this->timedPlays();
        if (!$plays) {
            return ['available' => false];
        }

        $daily = [];
        foreach ($plays as $p) {
            $d = $this->localTime($p[1])->format('Y-m-d');
            $daily[$d] = ($daily[$d] ?? 0) + $this->library->playMinutes($p);
        }

        $dates = array_keys($daily);
        sort($dates);

        // Plain calendar dates — compared in UTC so a DST change can't make
        // two consecutive days look 23 hours (i.e. zero days) apart.
        $utc = new DateTimeZone('UTC');
        $longest = ['length' => 0, 'start' => null, 'end' => null];
        $runStart = null;
        $prev = null;
        foreach ($dates as $d) {
            if ($prev === null || (new DateTime($prev, $utc))->modify('+1 day')->format('Y-m-d') !== $d) {
                $runStart = $d;
            }
            $length = (int) (new DateTime($runStart, $utc))->diff(new DateTime($d, $utc))->days + 1;
            if ($length > $longest['length']) {
                $longest = ['length' => $length, 'start' => $runStart, 'end' => $d];
            }
            $prev = $d;
        }

        // Current streak: counts back from today, or from yesterday if
        // nothing's been watched yet today (the day isn't over).
        $today = new DateTime('today', $this->tz);
        $cursor = isset($daily[$today->format('Y-m-d')]) ? clone $today : (clone $today)->modify('-1 day');
        $current = 0;
        while (isset($daily[$cursor->format('Y-m-d')])) {
            $current++;
            $cursor->modify('-1 day');
        }

        // Calendar: 53 full weeks ending this week, starting on a Monday.
        $start = (clone $today)->modify('-' . ((int) $today->format('N') - 1) . ' days')->modify('-52 weeks');
        $calendar = [];
        $activeDays = 0;
        for ($d = clone $start; $d <= $today; $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $m = $daily[$key] ?? 0;
            $calendar[] = ['date' => $key, 'minutes' => $m];
            if ($m > 0) {
                $activeDays++;
            }
        }

        $fmt = fn(?string $d) => $d ? (new DateTime($d, $utc))->format('j M Y') : null;

        return [
            'longest'       => $longest['length'],
            'longest_range' => $longest['length'] > 0 ? $fmt($longest['start']) . ' – ' . $fmt($longest['end']) : '',
            'current'       => $current,
            'calendar'      => $calendar,
            'active_days'   => $activeDays,
            'sample_note'   => $this->coverageNote(count($plays), $bulk),
        ];
    }

    /**
     * Your ratings distribution, and the titles where your score is
     * furthest from the Trakt community's.
     */
    public function hotTakes(): array
    {
        $ratings = $this->trakt->getRatings();
        if (!$ratings) {
            return ['available' => false];
        }

        $distribution = array_fill(1, 10, 0);
        $sum = 0;
        $takes = [];
        foreach ($ratings as $r) {
            $mine = (int) ($r['rating'] ?? 0);
            if ($mine < 1 || $mine > 10) {
                continue;
            }
            $distribution[$mine]++;
            $sum += $mine;

            $type = $r['type'] ?? '';
            if ($type !== 'movie' && $type !== 'show') {
                continue; // season/episode ratings: too noisy to compare
            }
            $media = $r[$type] ?? [];
            $community = (float) ($media['rating'] ?? 0);
            if ($community <= 0 || (int) ($media['votes'] ?? 0) < 100) {
                continue;
            }
            $takes[] = [
                'title'     => ($media['title'] ?? '?') . (isset($media['year']) ? ' (' . $media['year'] . ')' : ''),
                'type'      => $type,
                'mine'      => $mine,
                'community' => round($community, 1),
                'diff'      => round($mine - $community, 1),
            ];
        }

        $count = array_sum($distribution);
        if ($count === 0) {
            return ['available' => false];
        }

        usort($takes, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));

        return [
            'distribution' => array_values($distribution), // index 0 = rating 1
            'average'      => round($sum / $count, 1),
            'count'        => $count,
            'takes'        => array_slice($takes, 0, 6),
        ];
    }

    /**
     * Streaming Changes: titles from your watchlist and history that
     * arrived on or left a streaming service in the last 30 days, newest
     * first, watchlist titles flagged. See Streaming::refreshDue().
     */
    public function streamingChanges(): array
    {
        $since = time() - 30 * 86400;
        $events = array_reverse(array_filter($this->streaming->changes(), fn($e) => $e['at'] >= $since));
        if (!$this->streaming->enabled() || !$events) {
            return ['available' => false];
        }

        $watchlist = [];
        foreach ($this->trakt->getWatchlist() as $item) {
            $type = $item['type'] ?? '';
            if (($type === 'movie' || $type === 'show') && isset($item[$type]['ids']['trakt'])) {
                $watchlist[($type === 'movie' ? 'm' : 's') . $item[$type]['ids']['trakt']] = true;
            }
        }

        $rows = ['arrived' => [], 'left' => []];
        foreach ($events as $e) {
            $key = $e['key'] ?? null;
            if (!isset($rows[$e['kind']]) || count($rows[$e['kind']]) >= 20) {
                continue; // 20 of each is plenty
            }
            $rows[$e['kind']][] = [
                'key'          => $key,
                'title'        => $e['title'],
                'type'         => $e['type'],
                'service'      => $e['name'],
                'logo'         => $e['logo'],
                'date'         => $this->localTime($e['at'])->format('j M'),
                'on_watchlist' => $key !== null && isset($watchlist[$key]),
                'poster'       => ($key !== null ? $this->posterFor($key) : null) ?? $this->posters->lookup($e['type'], $e['tmdb'])['poster'],
            ];
        }

        return [
            'available' => true,
            'region'    => $this->streaming->regionCode(),
            'arrived'   => $rows['arrived'],
            'left'      => $rows['left'],
        ];
    }

    private static function median(array $values): int
    {
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
