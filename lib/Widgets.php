<?php

require_once __DIR__ . '/Trakt.php';
require_once __DIR__ . '/Library.php';
require_once __DIR__ . '/Posters.php';

/**
 * Computes the insight widgets (the clickable cards below the panels).
 * Almost everything here comes from the local history snapshot — see
 * lib/Library.php — so these cost no live API calls at all once it's
 * synced. The exceptions are Hot Takes (your ratings) and Watchlist Debt
 * (your watchlist), which read their own Trakt endpoints, cached an hour.
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
    private array $config;
    private DateTimeZone $tz;

    public function __construct(Trakt $trakt, Library $library, Posters $posters, array $config)
    {
        $this->trakt = $trakt;
        $this->library = $library;
        $this->posters = $posters;
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
    private function coverageNote(int $playCount, int $manualExcluded = 0): string
    {
        $coverage = $this->library->coverage();
        $note = 'Based on ' . number_format($playCount) . ' plays';

        if (!$coverage['backfill_complete']) {
            $note .= $coverage['covered_since']
                ? ' back to ' . $this->localTime($coverage['covered_since'])->format('j M Y') . ' — older history is still syncing'
                : ' — history is still syncing';
        }
        $note .= '.';

        if ($manualExcluded > 0) {
            $note .= ' Leaves out ' . number_format($manualExcluded) . ' plays added by hand with "mark as watched", since their times aren\'t real watch times.';
        }

        return $note;
    }

    /**
     * Plays whose timestamp reflects when they were really watched (see
     * Library::isManual()), plus how many were left out.
     *
     * @return array{0: array, 1: int}
     */
    private function timedPlays(): array
    {
        $all = $this->library->storedPlays();
        $timed = array_values(array_filter($all, fn($p) => !Library::isManual($p)));

        return [$timed, count($all) - count($timed)];
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

    // --- Widgets ---------------------------------------------------------

    public function watchClock(): array
    {
        [$plays, $manual] = $this->timedPlays();
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
            'sample_note' => 'Times are when each play finished, which is what Trakt records. ' . $this->coverageNote(count($plays), $manual),
        ];
    }

    public function weekRhythm(): array
    {
        [$plays, $manual] = $this->timedPlays();
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
            'sample_note' => $this->coverageNote(count($plays), $manual),
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
        [$plays, $manual] = $this->timedPlays();
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
                'show'     => $this->library->title($s['key'])['t'] ?? '?',
                'poster'   => $this->posterFor($s['key']),
                'episodes' => $s['episodes'],
                'hours'    => round($s['minutes'] / 60, 1),
                'date'     => $this->localTime($s['start'])->format('j M Y'),
                'range'    => $s['first_ep'] . '–' . $s['last_ep'],
            ];
        }

        return [
            'sessions'      => $top,
            'session_count' => count($sessions),
            'sample_note'   => 'A binge is 3+ episodes of one show back to back, each within 3 hours of the last. ' . $this->coverageNote(count($plays), $manual),
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
        [$plays, $manual] = $this->timedPlays();
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
            'sample_note'   => $this->coverageNote(count($plays), $manual),
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
     * How long it'd take to watch everything on your watchlist at your
     * recent pace, plus a pick for tonight (stable for the day, so it
     * doesn't reshuffle on every open).
     */
    public function watchlistDebt(): array
    {
        $items = $this->trakt->getWatchlist();
        if (!$items) {
            return ['available' => false];
        }

        $minutes = 0;
        $movies = [];
        $showCount = 0;
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            if ($type === 'movie' && isset($item['movie'])) {
                $minutes += (int) ($item['movie']['runtime'] ?? 0) ?: 110;
                $movies[] = $item['movie'];
            } elseif ($type === 'show' && isset($item['show'])) {
                $show = $item['show'];
                $minutes += ((int) ($show['runtime'] ?? 0) ?: 40) * max(1, (int) ($show['aired_episodes'] ?? 1));
                $showCount++;
            }
        }

        // Pace: average minutes a day over the last 90 days of history —
        // unknown (null), not zero, while those 90 days are still syncing.
        $since = (new DateTime('today', $this->tz))->modify('-90 days')->getTimestamp();
        $recent = $this->library->summary($since);
        $perDay = $recent !== null ? $recent['minutes'] / 90 : null;

        $pick = null;
        if ($movies) {
            $seed = crc32((new DateTime('today', $this->tz))->format('Y-m-d'));
            $m = $movies[$seed % count($movies)];
            $poster = Trakt::imageUrl($m, 'poster')
                ?? $this->posters->lookup('movie', $m['ids']['tmdb'] ?? null)['poster'];
            $pick = [
                'title'    => $m['title'] ?? '?',
                'year'     => $m['year'] ?? null,
                'runtime'  => (int) ($m['runtime'] ?? 0),
                'overview' => $m['overview'] ?? '',
                'poster'   => $poster,
                'url'      => isset($m['ids']['slug']) ? 'https://trakt.tv/movies/' . $m['ids']['slug'] : null,
            ];
        }

        return [
            'total_items'   => count($items),
            'movies'        => count($movies),
            'shows'         => $showCount,
            'hours'         => (int) round($minutes / 60),
            'per_day_min'   => $perDay !== null ? (int) round($perDay) : null,
            'days_to_clear' => $perDay ? (int) ceil($minutes / $perDay) : null,
            'pick'          => $pick,
        ];
    }

    private static function median(array $values): int
    {
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : (int) round(($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }
}
