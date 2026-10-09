<?php

require_once __DIR__ . '/App.php';

/**
 * Defines and executes the tools exposed over mcp.php — see that file for
 * the transport/auth layer. Read-only: every tool just reads existing
 * Trakt / local-library data, nothing here modifies anything.
 */
class Mcp
{
    private const WIDGET_TOOLS = [
        'watch_clock'  => 'A 24-hour breakdown of when you watch, hour by hour (by when each play finished).',
        'week_rhythm'  => 'Hours watched per day of the week, and the peak day.',
        'time_watched' => 'Total lifetime watch time, converted into flights, film marathons, trips to the Moon, etc.',
        'binge'        => 'Your longest binge sessions: 3+ episodes of one show back to back.',
        'decades'      => 'Distinct movies watched, grouped by decade of release, with median release year and oldest film.',
        'streaks'      => 'Longest and current streak of consecutive days with something watched, plus a year-long daily calendar of minutes watched.',
        'hot_takes'    => 'Your rating distribution and the titles where your rating differs most from the Trakt community average.',
        'watchlist'    => 'Watchlist size, total hours to clear it, days to clear at your recent pace, and a pick for tonight.',
    ];

    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function initialize(): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => ['tools' => new stdClass()],
            'serverInfo'      => ['name' => 'box-office-of-one', 'version' => Trakt::appVersion()],
        ];
    }

    public function listTools(): array
    {
        $none = ['type' => 'object', 'properties' => new stdClass()];
        $period = ['type' => 'string', 'enum' => Trakt::UI_PERIODS, 'description' => 'Defaults to all_time.'];

        $tools = [
            [
                'name'        => 'get_now_watching',
                'description' => 'What is playing on Trakt right now (or the most recently watched item if nothing is), plus the item watched before it.',
                'inputSchema' => $none,
            ],
            [
                'name'        => 'list_history',
                'description' => 'Individual plays (movie or episode, title, episode code/title, runtime, exact date/time watched) from the locally cached Trakt history — use for anything date/time-specific.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'since' => ['type' => 'string', 'description' => 'ISO 8601 date/datetime, e.g. "2024-01-01". Omit for the start of your history.'],
                        'until' => ['type' => 'string', 'description' => 'ISO 8601 date/datetime. Omit for now.'],
                        'type'  => ['type' => 'string', 'enum' => ['movie', 'episode'], 'description' => 'Only movies or only episodes. Omit for both.'],
                        'limit' => ['type' => 'integer', 'description' => 'Max entries, newest first (default 200, max 2000).'],
                    ],
                ],
            ],
            [
                'name'        => 'find_title',
                'description' => 'Every play of the movies/shows whose title contains the search text — e.g. "when did I last watch Alien?" or "how far into The Expanse am I?".',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['query' => ['type' => 'string', 'description' => 'Case-insensitive title text.']],
                    'required'   => ['query'],
                ],
            ],
            [
                'name'        => 'top_shows',
                'description' => 'Your most-watched shows for a period, ranked by episodes watched, with how far through each show you are (distinct episodes watched vs. aired, excluding specials).',
                'inputSchema' => ['type' => 'object', 'properties' => ['period' => $period, 'limit' => ['type' => 'integer', 'description' => 'Default 20, max 200.']]],
            ],
            [
                'name'        => 'top_movies',
                'description' => 'Your top movies watched in a period: ranked by your own Trakt rating (or, for films you haven\'t rated, the combined community score), then the combined community score (average of IMDb, Trakt and Rotten Tomatoes Popcornmeter, 0-100), with plays only as a tiebreaker.',
                'inputSchema' => ['type' => 'object', 'properties' => ['period' => $period, 'limit' => ['type' => 'integer', 'description' => 'Default 20, max 200.']]],
            ],
            [
                'name'        => 'genre_breakdown',
                'description' => 'Genre percentages for a period, weighted by minutes watched.',
                'inputSchema' => ['type' => 'object', 'properties' => ['period' => $period]],
            ],
            [
                'name'        => 'period_summary',
                'description' => 'Totals for a period: plays, movies, episodes, distinct shows, hours watched.',
                'inputSchema' => ['type' => 'object', 'properties' => ['period' => $period]],
            ],
            [
                'name'        => 'lifetime_stats',
                'description' => 'Lifetime Trakt totals: movies, shows, episodes, time watched, ratings, tracking since (earlier of join date and first play).',
                'inputSchema' => $none,
            ],
        ];

        foreach (self::WIDGET_TOOLS as $id => $description) {
            $tools[] = ['name' => 'widget_' . $id, 'description' => $description, 'inputSchema' => $none];
        }

        return $tools;
    }

    /**
     * @return array{content: array<int, array{type:string, text:string}>, isError: bool}
     */
    public function callTool(string $name, array $arguments): array
    {
        try {
            $data = $this->dispatch($name, $arguments);

            return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)]], 'isError' => false];
        } catch (Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
    }

    private function dispatch(string $name, array $args): array
    {
        $library = $this->app->library;
        $tz = $this->app->tz;

        if (strpos($name, 'widget_') === 0 && isset(self::WIDGET_TOOLS[substr($name, 7)])) {
            $id = substr($name, 7);
            $handlers = $this->app->handlers();

            return WidgetCache::remember($id, ['id' => $id], 900, $handlers[$id]);
        }

        $period = Trakt::validUiPeriod((string) ($args['period'] ?? 'all_time'), 'all_time');
        $since = Library::periodStart($period, $tz);
        $limit = max(1, min(200, (int) ($args['limit'] ?? 20)));

        switch ($name) {
            case 'get_now_watching':
                return $this->app->nowWatching(10);

            case 'lifetime_stats':
                return $this->app->lifetimeStats();

            case 'list_history':
                $from = isset($args['since']) ? $this->parseDate((string) $args['since']) : 0;
                $until = isset($args['until']) ? $this->parseDate((string) $args['until']) : null;
                $type = ($args['type'] ?? null) === 'movie' ? 'm' : (($args['type'] ?? null) === 'episode' ? 'e' : null);
                $rows = $library->history($from, $until, $type, max(1, min(2000, (int) ($args['limit'] ?? 200))), $tz);

                return $rows === null ? $this->notCovered() : ['available' => true, 'plays' => $rows];

            case 'find_title':
                return $this->findTitle(trim((string) ($args['query'] ?? '')));

            case 'top_shows':
            case 'top_movies':
                $rows = $name === 'top_movies'
                    ? $this->app->widgets->topMovies($since, $limit)
                    : $library->topTitles('e', $since, $limit);
                if ($rows === null) {
                    return $this->notCovered();
                }

                return ['available' => true, 'period' => $period, 'titles' => array_map(fn($r) => [
                    'title'        => $r['title'],
                    'year'         => $r['year'],
                    $name === 'top_movies' ? 'plays' : 'episodes' => $r['plays'],
                    'hours'        => round($r['minutes'] / 60, 1),
                    'ratings'      => array_column($this->app->widgets->ratingChips($r['key']), 'value', 'kind'),
                    'awards'       => array_map(fn($a) => ['ceremony' => $a['name'], 'wins' => $a['wins'], 'nominations' => $a['noms'], 'won' => $a['won']], $this->app->widgets->awards($r['key'])),
                    'your_rating'  => $r['mine'] ?? null,
                    'community_score' => $r['community'] ?? null,
                    'progress'     => $name === 'top_shows' ? $this->app->library->showProgress($r['key']) : null,
                    'last_watched' => $r['last'] > 0 ? (new DateTime('@' . $r['last']))->setTimezone($tz)->format(DATE_ATOM) : null,
                ], $rows)];

            case 'genre_breakdown':
                $genres = $library->genres($since);

                return $genres === null ? $this->notCovered() : ['available' => true, 'period' => $period, 'genres' => $genres];

            case 'period_summary':
                $summary = $library->summary($since);
                if ($summary === null) {
                    return $this->notCovered();
                }
                $summary['hours'] = round($summary['minutes'] / 60, 1);

                return ['available' => true, 'period' => $period] + $summary;
        }

        throw new RuntimeException('Unknown tool: ' . $name);
    }

    private function findTitle(string $query): array
    {
        if ($query === '') {
            throw new RuntimeException('query is required');
        }

        $library = $this->app->library;
        $matches = [];
        foreach ($library->storedPlays() as $p) {
            $title = $library->title($p[3]);
            if ($title === null || stripos($title['t'], $query) === false) {
                continue;
            }
            $key = $p[3];
            if (!isset($matches[$key])) {
                $matches[$key] = [
                    'title' => $title['t'],
                    'year'  => $title['y'],
                    'type'  => $key[0] === 'm' ? 'movie' : 'show',
                    'plays' => [],
                ];
            }
            $play = ['watched_at' => Library::playDate($p, $this->app->tz)];
            if (Library::dateUnknown($p)) {
                $play['date_unknown'] = true;
            }
            if ($p[2] === 'e') {
                $play['episode'] = Trakt::episodeCode($p[4], $p[5]);
                $play['episode_title'] = $p[7];
            }
            $matches[$key]['plays'][] = $play;
        }

        foreach ($matches as &$m) {
            usort($m['plays'], fn($a, $b) => strcmp((string) $b['watched_at'], (string) $a['watched_at'])); // unknown dates sort last
            $m['play_count'] = count($m['plays']);
            $m['plays'] = array_slice($m['plays'], 0, 500);
        }
        unset($m);

        return [
            'query'    => $query,
            'matches'  => array_values($matches),
            'coverage' => $this->coverageInfo(),
        ];
    }

    private function parseDate(string $value): int
    {
        try {
            return (new DateTime($value, $this->app->tz))->getTimestamp();
        } catch (Exception $e) {
            throw new RuntimeException('Could not parse date: ' . $value);
        }
    }

    private function coverageInfo(): array
    {
        $c = $this->app->library->coverage();
        $fmt = fn($ts) => $ts ? (new DateTime('@' . $ts))->setTimezone($this->app->tz)->format(DATE_ATOM) : null;

        return [
            'backfill_complete' => $c['backfill_complete'],
            'covered_since'     => $fmt($c['covered_since']),
            'synced_through'    => $fmt($c['synced_through']),
            'plays_stored'      => $c['play_count'],
        ];
    }

    private function notCovered(): array
    {
        return [
            'available' => false,
            'reason'    => 'The local history snapshot does not reach back that far yet — cron.php is still backfilling it.',
            'coverage'  => $this->coverageInfo(),
        ];
    }
}
