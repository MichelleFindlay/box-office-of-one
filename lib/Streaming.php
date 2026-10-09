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
 * TMDB can't be reached.
 */
class Streaming
{
    private const TTL = 86400;
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
    private static function region(string $configured, DateTimeZone $tz): string
    {
        if (preg_match('/^[A-Za-z]{2}$/', $configured)) {
            return strtoupper($configured);
        }
        $country = $tz->getLocation()['country_code'] ?? '';

        return preg_match('/^[A-Z]{2}$/', $country) ? $country : 'US';
    }

    /**
     * @param string $type "movie" or "show" (episodes use their show)
     * @return array<int, array{id: string, name: string, logo: ?string, url: string}> in SERVICES order
     */
    public function lookup(string $type, ?int $tmdbId, string $title): array
    {
        if (!$this->enabled() || !$tmdbId) {
            return [];
        }

        $kind = $type === 'movie' ? 'movie' : 'tv';
        $file = $this->cacheDir . '/watch_' . $kind . '_' . $tmdbId . '_' . $this->region . '.json';
        $cached = json_decode((string) @file_get_contents($file), true);

        if (!is_array($cached) || (time() - (int) @filemtime($file)) >= self::TTL) {
            $body = Http::get('https://api.themoviedb.org/3/' . $kind . '/' . $tmdbId . '/watch/providers?api_key=' . rawurlencode($this->apiKey), [], 6);
            $data = $body !== null ? json_decode($body, true) : null;
            if (is_array($data) && isset($data['results'])) {
                $cached = (array) ($data['results'][$this->region] ?? []);
                @file_put_contents($file, json_encode($cached));
            } elseif (!is_array($cached)) {
                return []; // retried next time
            }
        }

        $available = [];
        foreach (['flatrate', 'free', 'ads'] as $offer) {
            foreach ((array) ($cached[$offer] ?? []) as $p) {
                if (isset($p['provider_id'])) {
                    $available[(int) $p['provider_id']] ??= $p['logo_path'] ?? null;
                }
            }
        }

        $links = [];
        foreach (self::SERVICES as $id => [$name, $providerIds, $search]) {
            foreach ($providerIds as $providerId) {
                if (array_key_exists($providerId, $available)) {
                    $links[] = [
                        'id'   => $id,
                        'name' => $name,
                        'logo' => $available[$providerId] ? self::LOGO_BASE . $available[$providerId] : null,
                        'url'  => $search !== null
                            ? str_replace('{q}', rawurlencode($title), $search)
                            : (string) ($cached['link'] ?? 'https://www.themoviedb.org/' . $kind . '/' . $tmdbId . '/watch?locale=' . $this->region),
                    ];
                    break;
                }
            }
        }

        return $links;
    }
}
