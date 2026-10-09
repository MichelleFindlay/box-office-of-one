<?php

require_once __DIR__ . '/Http.php';

/**
 * Where a title can be streamed right now, for the links beside "View on
 * Trakt": TMDB's watch-provider data (sourced from JustWatch), using the
 * same tmdb_api_key as the poster fallback. Only subscription, free and
 * ad-supported availability counts — not rent or buy — and only the
 * services in SERVICES; anything else TMDB lists is ignored.
 *
 * TMDB doesn't give a link into each service, so each one opens that
 * service's own search for the title where its search URL takes a query,
 * or otherwise TMDB's "where to watch" page for the title, which has
 * one-click links into every service.
 *
 * Cached a day per title and region; a stored result is served whenever
 * TMDB can't be reached. cron.php re-checks your watchlist and library
 * daily (refreshDue()), and each re-check that finds a service added or
 * gone is logged — that's the Streaming Changes widget. There's no source
 * for what's *about* to leave, so departures show once they've happened.
 */
class Streaming
{
    private const TTL = 86400;
    private const CHANGES_KEPT_DAYS = 90;
    private const LOGO_BASE = 'https://image.tmdb.org/t/p/w92';

    /**
     * service => [name, TMDB provider IDs (every plan / ad tier), search
     * URL with {q} for the title, or null to use TMDB's watch page].
     * Search URLs were only kept where the service actually honours them.
     */
    public const SERVICES = [
        // Global subscription
        'netflix'     => ['Netflix', [8, 175, 1796], 'https://www.netflix.com/search?q={q}'],
        'prime'       => ['Prime Video', [9, 119, 613, 2100], 'https://www.primevideo.com/search/?phrase={q}'],
        'disney'      => ['Disney+', [337], null],
        'hbo'         => ['HBO Max', [1899, 384], 'https://play.hbomax.com/search/result?q={q}'],
        'paramount'   => ['Paramount+', [531, 2303, 2304, 2616], null],
        'skyshowtime' => ['SkyShowtime', [1773], null],
        'apple'       => ['Apple TV', [350], 'https://tv.apple.com/search?term={q}'],
        'peacock'     => ['Peacock', [386, 387], null],
        // Free and ad-supported
        'youtube'     => ['YouTube', [192, 235, 188], 'https://www.youtube.com/results?search_query={q}'],
        'tubi'        => ['Tubi', [73], 'https://tubitv.com/search/{q}'],
        'pluto'       => ['Pluto TV', [300], null],
        'roku'        => ['The Roku Channel', [207], null],
        // UK
        'iplayer'     => ['BBC iPlayer', [38], 'https://www.bbc.co.uk/iplayer/search?q={q}'],
        'itvx'        => ['ITVX', [41, 2300], null],
        'channel4'    => ['Channel 4', [103, 2311], null],
        'my5'         => ['My5', [333], 'https://www.channel5.com/search?q={q}'],
        'now'         => ['NOW', [39, 591], 'https://www.nowtv.com/watch/search?q={q}'],
        'sky'         => ['Sky Go', [29], null],
        'britbox'     => ['BritBox', [151], null],
    ];

    private string $apiKey;
    private string $region;
    private string $cacheDir;

    public function __construct(array $config, DateTimeZone $tz)
    {
        $this->apiKey = (string) ($config['tmdb_api_key'] ?? '');
        $this->region = self::region((string) ($config['watch_region'] ?? ''), $tz);
        $this->cacheDir = __DIR__ . '/../cache';
    }

    public function enabled(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * The configured two-letter country, else the timezone's country
     * (Europe/London → GB), else US.
     */
    public function regionCode(): string
    {
        return $this->region;
    }

    public static function region(string $configured, DateTimeZone $tz): string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $configured)) {
            return strtoupper($configured);
        }
        $country = $tz->getLocation()['country_code'] ?? '';

        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'US';
    }

    private function cacheFile(string $type, int $tmdbId): string
    {
        return $this->cacheDir . '/watch_' . ($type === 'movie' ? 'movie' : 'tv') . '_' . $tmdbId . '_' . $this->region . '.json';
    }

    public function isFresh(string $type, int $tmdbId): bool
    {
        $file = $this->cacheFile($type, $tmdbId);

        return is_file($file) && (time() - filemtime($file)) < self::TTL;
    }

    /**
     * @param string $type "movie" or "show" (episodes use their show)
     * @param ?string $key title key ('m…' / 's…'), recorded with any change spotted
     * @return array<int, array{id: string, name: string, logo: ?string, url: string}> in SERVICES order
     */
    public function lookup(string $type, ?int $tmdbId, string $title, ?string $key = null): array
    {
        if (!$this->enabled() || !$tmdbId) {
            return [];
        }

        $kind = $type === 'movie' ? 'movie' : 'tv';
        $file = $this->cacheFile($type, $tmdbId);
        $cached = json_decode((string) @file_get_contents($file), true);

        if (!is_array($cached) || (time() - (int) @filemtime($file)) >= self::TTL) {
            $body = Http::get('https://api.themoviedb.org/3/' . $kind . '/' . $tmdbId . '/watch/providers?api_key=' . rawurlencode($this->apiKey), [], 6);
            $data = $body !== null ? json_decode($body, true) : null;
            if (is_array($data) && isset($data['results'])) {
                $fresh = (array) ($data['results'][$this->region] ?? []);
                if (is_array($cached)) {
                    $this->logChanges($type, $tmdbId, $title, $key, $cached, $fresh);
                }
                $cached = $fresh;
                @file_put_contents($file, json_encode($cached));
            } elseif (!is_array($cached)) {
                return []; // retried next time
            }
        }

        $links = [];
        foreach (self::services($cached) as $id => [$name, $logo]) {
            $search = self::SERVICES[$id][2];
            $links[] = [
                'id'   => $id,
                'name' => $name,
                'logo' => $logo,
                'url'  => $search !== null
                    ? str_replace('{q}', rawurlencode($title), $search)
                    : (string) ($cached['link'] ?? 'https://www.themoviedb.org/' . $kind . '/' . $tmdbId . '/watch?locale=' . $this->region),
            ];
        }

        return $links;
    }

    /**
     * Services (from SERVICES) a TMDB providers entry lists by
     * subscription or free: service id => [name, logo URL].
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function services(array $providers): array
    {
        $available = [];
        foreach (['flatrate', 'free', 'ads'] as $offer) {
            foreach ((array) ($providers[$offer] ?? []) as $p) {
                if (isset($p['provider_id'])) {
                    $available[(int) $p['provider_id']] ??= $p['logo_path'] ?? null;
                }
            }
        }

        $services = [];
        foreach (self::SERVICES as $id => [$name, $providerIds]) {
            foreach ($providerIds as $providerId) {
                if (array_key_exists($providerId, $available)) {
                    $services[$id] = [$name, $available[$providerId] ? self::LOGO_BASE . $available[$providerId] : null];
                    break;
                }
            }
        }

        return $services;
    }

    // --- Arrivals and departures ----------------------------------------

    private function changesFile(): string
    {
        return $this->cacheDir . '/streaming_changes_' . $this->region . '.json';
    }

    private function logChanges(string $type, int $tmdbId, string $title, ?string $key, array $before, array $after): void
    {
        $was = self::services($before);
        $now = self::services($after);
        $events = [];
        foreach (array_diff_key($now, $was) as $id => [$name, $logo]) {
            $events[] = ['kind' => 'arrived', 'service' => $id, 'name' => $name, 'logo' => $logo];
        }
        foreach (array_diff_key($was, $now) as $id => [$name, $logo]) {
            $events[] = ['kind' => 'left', 'service' => $id, 'name' => $name, 'logo' => $logo];
        }
        if (!$events) {
            return;
        }

        $log = $this->changes();
        foreach ($events as $e) {
            $log[] = $e + ['at' => time(), 'type' => $type === 'movie' ? 'movie' : 'show', 'tmdb' => $tmdbId, 'title' => $title, 'key' => $key];
        }
        $cutoff = time() - self::CHANGES_KEPT_DAYS * 86400;
        $log = array_values(array_filter($log, fn($e) => $e['at'] >= $cutoff));

        $tmp = $this->changesFile() . '.tmp';
        if (@file_put_contents($tmp, json_encode($log)) !== false) {
            @rename($tmp, $this->changesFile());
        }
    }

    /**
     * Every logged arrival and departure, oldest first.
     *
     * @return array<int, array{kind: string, service: string, name: string, logo: ?string, at: int, type: string, tmdb: int, title: string, key: ?string}>
     */
    public function changes(): array
    {
        $log = json_decode((string) @file_get_contents($this->changesFile()), true);

        return is_array($log) ? $log : [];
    }

    /**
     * Re-checks up to $max titles whose availability is over a day old,
     * in the order given (cron.php passes watchlist first, then the
     * library, heaviest-watched first). Stops early if TMDB stops
     * answering.
     *
     * @param array<int, array{type: string, tmdb: ?int, title: string, key: ?string}> $titles
     * @return int titles re-checked
     */
    public function refreshDue(array $titles, int $max): int
    {
        if (!$this->enabled()) {
            return 0;
        }

        $checked = 0;
        $failed = 0;
        $seen = [];
        foreach ($titles as $t) {
            if ($checked >= $max) {
                break;
            }
            $id = $t['type'] . ($t['tmdb'] ?? '');
            if (empty($t['tmdb']) || isset($seen[$id]) || $this->isFresh($t['type'], (int) $t['tmdb'])) {
                continue;
            }
            $seen[$id] = true;
            $this->lookup($t['type'], (int) $t['tmdb'], $t['title'], $t['key']);
            $checked++;
            if (!$this->isFresh($t['type'], (int) $t['tmdb']) && ++$failed >= 3) {
                break; // TMDB looks unreachable — don't sit through a timeout per title
            }
        }

        return $checked;
    }
}
