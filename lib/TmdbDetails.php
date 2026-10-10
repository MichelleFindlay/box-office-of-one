<?php

require_once __DIR__ . '/Http.php';

/**
 * The extra TMDB details the poster hover card shows (needs tmdb_api_key):
 * your country's age certificate — BBFC in the UK, where Trakt only has
 * the US one — and, for a film, the collection it's part of ("Toy Story
 * Collection") with every film in it, so the card can say how many you've
 * seen, its box office (budget and worldwide gross, in US dollars), and
 * when it reached cinemas and digital in your country. Also each title's
 * YouTube trailer, for the Now Watching card's Trailer button.
 *
 * Titles cached a week, collections a week; a stored copy is served
 * whenever TMDB can't be reached.
 */
class TmdbDetails
{
    private const BASE_URL = 'https://api.themoviedb.org/3';
    private const TTL = 604800; // a week

    private string $apiKey;
    private string $region;
    private string $cacheDir;

    public function __construct(array $config, string $region)
    {
        $this->apiKey = (string) ($config['tmdb_api_key'] ?? '');
        $this->region = $region;
        $this->cacheDir = __DIR__ . '/../cache';
    }

    public function enabled(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Your country's certificate for a film or show, e.g. "12A" or "15";
     * null if TMDB doesn't have one for it.
     */
    public function certification(string $type, ?int $tmdbId): ?string
    {
        $details = $this->details($type, $tmdbId);

        return $details['cert'][$this->region] ?? null;
    }

    /**
     * The YouTube video ID of a film's or show's trailer: official
     * trailers first, then teasers, newest first — for a show, that's
     * usually the latest season's. Null if TMDB has none on YouTube.
     */
    public function trailer(string $type, ?int $tmdbId): ?string
    {
        return $this->details($type, $tmdbId)['trailer'] ?? null;
    }

    /**
     * A film's budget and worldwide box office gross in US dollars, as
     * TMDB has them — either can be null (TMDB stores "unknown" as 0).
     *
     * @return array{budget: ?int, revenue: ?int}
     */
    public function boxOffice(?int $tmdbId): array
    {
        $details = $this->details('movie', $tmdbId);

        return ['budget' => ($details['budget'] ?? 0) ?: null, 'revenue' => ($details['revenue'] ?? 0) ?: null];
    }

    /**
     * When a film reached cinemas and digital (streaming / download to
     * buy or rent) in your country — or, if TMDB has neither for it, in
     * the US, flagged by 'country'. Each is the earliest such release,
     * with TMDB's note on it if any ("Disney+ Premier Access"); null if
     * there wasn't one.
     *
     * @return array{country: string, cinema: ?array{date: string, note: string}, digital: ?array{date: string, note: string}}|null
     */
    public function releaseDates(?int $tmdbId): ?array
    {
        $releases = $this->details('movie', $tmdbId)['releases'] ?? [];
        foreach (array_unique([$this->region, 'US']) as $country) {
            if (!empty($releases[$country])) {
                return ['country' => $country, 'cinema' => $releases[$country]['cinema'] ?? null, 'digital' => $releases[$country]['digital'] ?? null];
            }
        }

        return null;
    }

    /**
     * The collection a film belongs to, its films in release order, only
     * those already released.
     *
     * @return array{name: string, parts: array<int, array{tmdb: int, title: string, date: string}>}|null
     */
    public function collection(?int $tmdbId): ?array
    {
        $id = $this->details('movie', $tmdbId)['collection'] ?? null;
        if (!$id) {
            return null;
        }

        $data = $this->cached('collection_' . $id, '/collection/' . $id, [], function (array $data) {
            $parts = [];
            foreach ((array) ($data['parts'] ?? []) as $p) {
                if (isset($p['id'])) {
                    $parts[] = ['tmdb' => (int) $p['id'], 'title' => (string) ($p['title'] ?? ''), 'date' => (string) ($p['release_date'] ?? '')];
                }
            }

            return ['name' => (string) ($data['name'] ?? ''), 'parts' => $parts];
        });
        if (!is_array($data)) {
            return null;
        }

        // Released films only (an announced sequel isn't one you could have seen yet).
        $today = date('Y-m-d');
        $parts = array_values(array_filter($data['parts'], fn($p) => $p['date'] !== '' && $p['date'] <= $today));
        usort($parts, fn($a, $b) => $a['date'] <=> $b['date']);

        return $parts ? ['name' => $data['name'], 'parts' => $parts] : null;
    }

    /**
     * @return array{cert: array<string, string>, releases: array, trailer: ?string, collection: ?int, budget: int, revenue: int}|null
     */
    private function details(string $type, ?int $tmdbId): ?array
    {
        if (!$tmdbId) {
            return null;
        }

        $isMovie = $type === 'movie';

        return $this->cached(
            ($isMovie ? 'movie4_' : 'tv2_') . $tmdbId, // bumped whenever a field is added (most recently, trailers)
            ($isMovie ? '/movie/' : '/tv/') . $tmdbId,
            ['append_to_response' => ($isMovie ? 'release_dates' : 'content_ratings') . ',videos'],
            fn(array $data) => self::parseDetails($data, $isMovie)
        );
    }

    private static function parseDetails(array $data, bool $isMovie): array
    {
        // Films: a certificate per release (premiere, cinema, digital...) —
        // prefer the cinema release's, else the first one that has any.
        // Shows: one rating per country.
        $certs = [];
        $dates = [];
        if ($isMovie) {
            foreach ((array) ($data['release_dates']['results'] ?? []) as $country) {
                $releases = (array) ($country['release_dates'] ?? []);

                // Earliest cinema release (TMDB type 3, else 2 — limited)
                // and digital release (type 4) per country.
                foreach (['cinema' => [3, 2], 'digital' => [4]] as $kind => $types) {
                    foreach ($types as $type) {
                        $matching = array_filter($releases, fn($r) => ($r['type'] ?? 0) === $type && !empty($r['release_date']));
                        if ($matching) {
                            usort($matching, fn($a, $b) => $a['release_date'] <=> $b['release_date']);
                            $dates[$country['iso_3166_1']][$kind] = ['date' => substr($matching[0]['release_date'], 0, 10), 'note' => trim((string) ($matching[0]['note'] ?? ''))];
                            break;
                        }
                    }
                }

                usort($releases, fn($a, $b) => (($a['type'] ?? 0) === 3 ? 0 : 1) <=> (($b['type'] ?? 0) === 3 ? 0 : 1));
                foreach ($releases as $r) {
                    if (trim((string) ($r['certification'] ?? '')) !== '') {
                        $certs[$country['iso_3166_1']] = trim($r['certification']);
                        break;
                    }
                }
            }
        } else {
            foreach ((array) ($data['content_ratings']['results'] ?? []) as $r) {
                if (trim((string) ($r['rating'] ?? '')) !== '') {
                    $certs[$r['iso_3166_1']] = trim($r['rating']);
                }
            }
        }

        return [
            'cert'       => $certs,
            'releases'   => $dates,
            'trailer'    => self::pickTrailer((array) ($data['videos']['results'] ?? [])),
            'collection' => isset($data['belongs_to_collection']['id']) ? (int) $data['belongs_to_collection']['id'] : null,
            'budget'     => (int) ($data['budget'] ?? 0),
            'revenue'    => (int) ($data['revenue'] ?? 0),
        ];
    }

    private static function pickTrailer(array $videos): ?string
    {
        $videos = array_filter($videos, fn($v) => ($v['site'] ?? '') === 'YouTube' && !empty($v['key'])
            && in_array($v['type'] ?? '', ['Trailer', 'Teaser'], true));
        usort($videos, fn($a, $b) => [($a['type'] === 'Trailer' ? 0 : 1), empty($a['official']) ? 1 : 0, $b['published_at'] ?? '']
            <=> [($b['type'] === 'Trailer' ? 0 : 1), empty($b['official']) ? 1 : 0, $a['published_at'] ?? '']);

        return $videos ? (string) $videos[0]['key'] : null;
    }

    /**
     * A TMDB response run through $parse, which picks out just what's
     * needed — that's what gets cached (a week), not the whole response.
     */
    private function cached(string $name, string $path, array $params, callable $parse): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $file = $this->cacheDir . '/tmdbx_' . $name . '.json';
        $cached = json_decode((string) @file_get_contents($file), true);
        if (is_array($cached) && (time() - (int) @filemtime($file)) < self::TTL) {
            return $cached;
        }

        $body = Http::get(self::BASE_URL . $path . '?' . http_build_query($params + ['api_key' => $this->apiKey]), [], 6);
        $data = $body !== null ? json_decode($body, true) : null;
        if (is_array($data) && !isset($data['success'])) { // TMDB errors carry "success": false
            $parsed = $parse($data);
            @file_put_contents($file, json_encode($parsed));
            return $parsed;
        }

        return is_array($cached) ? $cached : null;
    }
}
