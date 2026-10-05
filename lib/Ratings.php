<?php

require_once __DIR__ . '/Http.php';

/**
 * Optional IMDb rating and Rotten Tomatoes Popcornmeter (audience score)
 * for each title, via MDBList (https://mdblist.com) — neither is available
 * from Trakt, and Rotten Tomatoes has no public API of its own. MDBList
 * also returns Trakt's viewer rating, which is used to fill in any title
 * whose history entry didn't carry one.
 *
 * Get a free API key at https://mdblist.com/preferences/ (free tier:
 * 1,000 requests a day). Scores are stored per title and kept for good:
 * cron.php fills in missing ones first, then refreshes any over a week old,
 * a batch at a time and capped at mdblist_daily_limit requests per rolling
 * 24 hours. If that cap is hit or MDBList is unavailable, the last stored
 * scores keep being shown. Page loads only ever read what's stored.
 */
class Ratings
{
    private const BASE_URL = 'https://api.mdblist.com';
    private const TTL = 604800; // a week

    private string $apiKey;
    private int $dailyLimit;
    private string $cacheDir;

    public function __construct(array $config)
    {
        $this->apiKey = (string) ($config['mdblist_api_key'] ?? '');
        $this->dailyLimit = max(0, (int) ($config['mdblist_daily_limit'] ?? 900));
        $this->cacheDir = __DIR__ . '/../cache';
    }

    public function enabled(): bool
    {
        return $this->apiKey !== '';
    }

    private function cacheFile(string $key): string
    {
        return $this->cacheDir . '/mdblist_' . preg_replace('/[^a-z0-9]/', '', $key) . '.json';
    }

    /**
     * Whether scores have ever been fetched for this title (however long ago).
     */
    public function has(string $key): bool
    {
        return is_file($this->cacheFile($key));
    }

    /**
     * Whether the stored scores are recent enough not to need refreshing.
     */
    public function isFresh(string $key): bool
    {
        $file = $this->cacheFile($key);

        return is_file($file) && (time() - filemtime($file)) < self::TTL;
    }

    /**
     * Scores for a title. Stored scores are always served, however old —
     * they're only ever replaced by a successful refresh, never discarded —
     * so running out of MDBList requests (or MDBList being down) leaves the
     * last known scores in place rather than blanking them.
     *
     * With $allowRefresh, scores missing or older than a week are refreshed
     * first if the daily cap allows; page loads leave that to cron.php.
     *
     * @param string $key title key from the local library: 'm<trakt id>' or 's<trakt id>'
     * @return array{imdb: ?float, imdb_votes: ?int, popcorn: ?int, trakt: ?int}|null null if never fetched
     */
    public function lookup(string $key, bool $allowRefresh = false): ?array
    {
        if (!$this->enabled() || !preg_match('/^[ms]\d+$/', $key)) {
            return null;
        }

        if ($allowRefresh && !$this->isFresh($key)) {
            $this->refresh($key);
        }

        $cached = json_decode((string) @file_get_contents($this->cacheFile($key)), true);

        return is_array($cached) ? $cached : null;
    }

    /**
     * Fetches fresh scores from MDBList and stores them. Anything short of
     * a definite answer leaves the stored copy untouched.
     *
     * @return string "ok" (stored), "limit" (daily cap reached or MDBList
     *                said slow down — stop for now), or "error" (transient)
     */
    public function refresh(string $key): string
    {
        if (!$this->enabled() || !preg_match('/^[ms]\d+$/', $key)) {
            return 'error';
        }
        if (!$this->takeQuota()) {
            return 'limit';
        }

        $type = $key[0] === 'm' ? 'movie' : 'show';
        $url = self::BASE_URL . '/trakt/' . $type . '/' . substr($key, 1) . '?apikey=' . rawurlencode($this->apiKey);
        $response = Http::request('GET', $url, ['Accept: application/json', 'User-Agent: box-office-of-one'], null, 10);

        if ($response !== null && $response['status'] === 429) {
            $this->exhaustQuota(); // MDBList says stop — honour it until the window rolls over
            return 'limit';
        }

        if ($response === null || ($response['status'] !== 200 && $response['status'] !== 404)) {
            return 'error';
        }

        // 404 = MDBList doesn't know this title. Stored as "no scores" so it
        // isn't asked about again until the next weekly refresh — but never
        // over the top of scores we already have.
        if ($response['status'] === 404 && $this->has($key)) {
            @touch($this->cacheFile($key));
            return 'ok';
        }

        $data = $response['status'] === 200 ? json_decode($response['body'], true) : [];
        $file = $this->cacheFile($key);
        $tmp = $file . '.tmp';
        if (@file_put_contents($tmp, json_encode(self::parse(is_array($data) ? $data : []))) !== false) {
            @rename($tmp, $file); // atomic, so a reader never sees a half-written file
        }

        return 'ok';
    }

    /**
     * Picks the three scores out of MDBList's ratings list. Each entry has
     * a source, its native value (IMDb: 0–10; Trakt / Popcorn: 0–100) and
     * a normalized 0–100 score; null means that source has no rating yet.
     */
    private static function parse(array $data): array
    {
        $result = ['imdb' => null, 'imdb_votes' => null, 'popcorn' => null, 'trakt' => null];

        foreach ((array) ($data['ratings'] ?? []) as $r) {
            $source = $r['source'] ?? '';
            $value = $r['value'] ?? null;
            if (!is_numeric($value) || (float) $value <= 0) {
                continue;
            }
            if ($source === 'imdb') {
                $result['imdb'] = round((float) $value, 1);
                $result['imdb_votes'] = isset($r['votes']) ? (int) $r['votes'] : null;
            } elseif ($source === 'popcorn') {
                $result['popcorn'] = (int) round((float) $value);
            } elseif ($source === 'trakt') {
                $result['trakt'] = (int) round((float) $value);
            }
        }

        return $result;
    }

    // --- Daily request cap ------------------------------------------------

    private function quotaFile(): string
    {
        return $this->cacheDir . '/mdblist_quota.json';
    }

    /**
     * Records one request against the rolling 24-hour cap; false (and no
     * request should be made) once it's used up.
     */
    private function takeQuota(): bool
    {
        $now = time();
        $stamps = json_decode((string) @file_get_contents($this->quotaFile()), true);
        $stamps = array_values(array_filter(is_array($stamps) ? $stamps : [], fn($t) => $t > $now - 86400));

        if (count($stamps) >= $this->dailyLimit) {
            return false;
        }

        $stamps[] = $now;
        @file_put_contents($this->quotaFile(), json_encode($stamps));

        return true;
    }

    private function exhaustQuota(): void
    {
        @file_put_contents($this->quotaFile(), json_encode(array_fill(0, $this->dailyLimit, time())));
    }
}
