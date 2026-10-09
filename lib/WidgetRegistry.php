<?php

/**
 * Single source of truth for every widget id and how to compute it, shared
 * by widgets.php (on-demand), cron.php (pre-warming), and the MCP server
 * (lib/Mcp.php) so the three can never drift apart.
 */
class WidgetRegistry
{
    /**
     * Widgets with no request parameters — the ones cron.php pre-warms
     * unconditionally, and the MCP server exposes as widget_<id> tools.
     */
    public const SIMPLE_IDS = [
        'watch_clock', 'week_rhythm', 'time_watched', 'binge',
        'decades', 'streaks', 'hot_takes', 'streaming_changes',
    ];

    /**
     * @return array<string, callable(): array>
     */
    public static function handlers(Trakt $trakt, Library $library, Widgets $widgets, array $config): array
    {
        $tz = Trakt::resolveTimezone($config['timezone'] ?? '');

        return [
            'watch_clock'  => fn() => $widgets->watchClock(),
            'week_rhythm'  => fn() => $widgets->weekRhythm(),
            'time_watched' => fn() => $widgets->timeWatched(),
            'binge'        => fn() => $widgets->binge(),
            'decades'      => fn() => $widgets->decades(),
            'streaks'      => fn() => $widgets->streaks(),
            'hot_takes'    => fn() => $widgets->hotTakes(),
            'streaming_changes' => fn() => $widgets->streamingChanges(),

            // Period-picker panels. Read their params from $_GET, matching
            // how widgets.php's on-demand requests are shaped (cron.php
            // fakes $_GET to pre-warm them the same way).
            'genre' => function () use ($library, $tz) {
                $period = Trakt::validUiPeriod($_GET['period'] ?? 'all_time', 'all_time');
                $genres = $library->genres(Library::periodStart($period, $tz));

                return ['period' => $period, 'genres' => $genres, 'syncing' => $genres === null];
            },
            'titles' => function () use ($library, $widgets, $config, $tz) {
                $period = Trakt::validUiPeriod($_GET['period'] ?? 'all_time', 'all_time');
                $panel = ($_GET['panel'] ?? '') === 'movies' ? 'movies' : 'shows';
                $rows = self::titleRows($library, $widgets, $panel, $period, (int) ($config['top_limit'] ?? 8), $tz);

                return ['period' => $period, 'panel' => $panel, 'titles' => $rows, 'syncing' => $rows === null];
            },
        ];
    }

    /**
     * Display-ready rows for the Top Shows / Top Movies panels, shared by
     * index.php's first render and the AJAX period picker so both produce
     * identical markup data.
     *
     * @return array<int, array>|null null while the snapshot doesn't cover the period yet
     */
    public static function titleRows(Library $library, Widgets $widgets, string $panel, string $period, int $limit, DateTimeZone $tz): ?array
    {
        $type = $panel === 'movies' ? 'm' : 'e';
        $since = Library::periodStart($period, $tz);
        // Shows rank by episodes watched; movies by your rating, then
        // community score (see Widgets::topMovies()), with no play count shown.
        $top = $type === 'm' ? $widgets->topMovies($since, $limit) : $library->topTitles($type, $since, $limit);
        if ($top === null) {
            return null;
        }

        $max = max(1, ...array_map(fn($t) => $t['plays'], $top ?: [['plays' => 1]]));
        $rows = [];
        foreach ($top as $i => $t) {
            if ($type === 'm') {
                $count = $t['mine'] !== null ? '★ ' . $t['mine'] . '/10' : '';
                $pct = null; // no play-count bar for movies
            } else {
                $count = number_format($t['plays']) . ' ' . ($t['plays'] === 1 ? 'episode' : 'episodes');
                $pct = max(4, (int) round($t['plays'] / $max * 100));
            }
            $rows[] = [
                'key'     => $t['key'],
                'rank'    => $i + 1,
                'name'    => $t['title'],
                'sub'     => trim(($t['year'] ? $t['year'] . ' · ' : '') . Trakt::formatMinutes($t['minutes'])),
                'count'   => $count,
                'pct'     => $pct,
                'art'     => $widgets->posterFor($t['key']),
                'ratings' => $widgets->titleChips($t['key']),
                'url'     => $t['slug'] ? 'https://trakt.tv/' . ($type === 'm' ? 'movies' : 'shows') . '/' . $t['slug'] : null,
            ];
        }

        return $rows;
    }
}
