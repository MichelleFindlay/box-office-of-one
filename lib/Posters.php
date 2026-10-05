<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional TMDB fallback for poster/backdrop art. Trakt's own
 * extended=images data is used first wherever a response includes it (see
 * Trakt::imageUrl()); this only fills in titles that came back without
 * any, and only when a free TMDB API key is configured — without one,
 * art-less titles just get the letter placeholder.
 *
 * Get a key at https://www.themoviedb.org/settings/api (the "API Key",
 * v3 auth — not the longer read access token).
 */
class Posters
{
    private const IMAGE_BASE = 'https://image.tmdb.org/t/p/';

    private string $apiKey;
    private string $cacheDir;

    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['tmdb_api_key'] ?? '');
        $this->cacheDir = __DIR__ . '/../cache';
    }

    public function enabled(): bool
    {
        return $this->apiKey !== '';
    }

    private function cacheFile(string $type, int $tmdbId): string
    {
        return $this->cacheDir . '/tmdb_' . ($type === 'movie' ? 'movie' : 'tv') . '_' . $tmdbId . '.json';
    }

    public function isCached(string $type, int $tmdbId): bool
    {
        $file = $this->cacheFile($type, $tmdbId);

        return is_file($file) && (time() - filemtime($file)) < 2592000;
    }

    /**
     * @param string $type "movie" or "show" (episodes use their show's art)
     * @return array{poster: ?string, fanart: ?string}
     */
    public function lookup(string $type, ?int $tmdbId, bool $cacheOnly = false): array
    {
        $empty = ['poster' => null, 'fanart' => null];
        if (!$this->enabled() || !$tmdbId) {
            return $empty;
        }

        $kind = $type === 'movie' ? 'movie' : 'tv';
        $cacheFile = $this->cacheFile($type, $tmdbId);
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;

        // Refreshed after a month (posters change rarely, and a missing one
        // isn't worth re-asking about often either), but a stored result is
        // never thrown away: it's served whenever a refresh isn't possible.
        if (is_array($cached) && ($cacheOnly || $this->isCached($type, $tmdbId))) {
            return $cached + $empty;
        }

        if ($cacheOnly) {
            return $empty;
        }

        $body = Http::get('https://api.themoviedb.org/3/' . $kind . '/' . $tmdbId . '?api_key=' . rawurlencode($this->apiKey));
        $data = $body !== null ? json_decode($body, true) : null;
        if (!is_array($data)) {
            return is_array($cached) ? $cached + $empty : $empty; // retried next time
        }

        $result = [
            'poster' => !empty($data['poster_path']) ? self::IMAGE_BASE . 'w342' . $data['poster_path'] : null,
            'fanart' => !empty($data['backdrop_path']) ? self::IMAGE_BASE . 'w1280' . $data['backdrop_path'] : null,
        ];
        @file_put_contents($cacheFile, json_encode($result));

        return $result;
    }
}
