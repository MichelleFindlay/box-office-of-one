<?php

require_once __DIR__ . '/Http.php';

/**
 * Minimal Trakt API client with simple file-based response caching, plus the
 * OAuth bits needed to read a private profile (see auth.php).
 *
 * A public Trakt profile is fully readable with just a Client ID — no
 * sign-in involved. A private one needs an OAuth access token, obtained once
 * via the device-code flow in auth.php and refreshed automatically here
 * before it expires.
 */
class Trakt
{
    private const BASE_URL = 'https://api.trakt.tv';

    /**
     * Small files in cache/ that hold secrets or in-progress auth state are
     * written as "<?php exit; ?>" followed by JSON: readable here with
     * file_get_contents(), but if someone requests the file directly over
     * the web, PHP just executes it and exits — nothing is ever printed.
     */
    private const GUARD = "<?php exit; ?>\n";

    private string $clientId;
    private string $clientSecret;
    private string $user;
    private int $cacheTtl;
    private string $cacheDir;
    private ?array $token = null;
    private bool $tokenLoaded = false;

    public function __construct(array $config, int $cacheTtl = 60)
    {
        $this->clientId = (string) ($config['client_id'] ?? '');
        $this->clientSecret = (string) ($config['client_secret'] ?? '');
        $this->user = (string) ($config['username'] ?? '');
        $this->cacheTtl = $cacheTtl;
        $this->cacheDir = __DIR__ . '/../cache';

        self::ensureCacheDir($this->cacheDir);
    }

    /**
     * Creates cache/ on first use, along with an .htaccess that denies web
     * access to it on Apache — the local history snapshot in there is your
     * full watch history, which shouldn't be downloadable by anyone who
     * guesses the filename. (Other web servers need the equivalent rule
     * added by hand; see README.md.)
     */
    public static function ensureCacheDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
        }
    }

    /**
     * Path segment for /users/{id}/... calls. Falls back to "me" (the
     * token's own account) when no username is configured but OAuth is.
     */
    public function userSlug(): string
    {
        if ($this->user !== '') {
            return rawurlencode($this->user);
        }

        return $this->accessToken() !== null ? 'me' : '';
    }

    public function isAuthenticated(): bool
    {
        return $this->accessToken() !== null;
    }

    /**
     * Call a Trakt GET endpoint, transparently caching the decoded JSON.
     * Pass $ttlOverride for calls that should be cached far longer (or
     * shorter) than the general TTL. Pass $cacheOnly to read a cached
     * response without ever making a live request.
     *
     * @return array{data: mixed, page_count: int, item_count: int}|null null on any failure
     */
    public function callWithMeta(string $path, array $params = [], ?int $ttlOverride = null, bool $cacheOnly = false): ?array
    {
        $ttl = $ttlOverride ?? $this->cacheTtl;
        $authed = $this->accessToken() !== null;

        $cacheFile = $this->cacheDir . '/trakt_' . md5($path . serialize($params) . ($authed ? ':auth' : '')) . '.json';

        if ($ttl > 0 && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached) && array_key_exists('data', $cached)) {
                return $cached;
            }
        }

        if ($cacheOnly || $this->rateLimited()) {
            return null;
        }

        $url = self::BASE_URL . $path . ($params ? '?' . http_build_query($params) : '');
        $response = Http::request('GET', $url, $this->headers());

        if ($response !== null && $response['status'] === 401 && $authed) {
            // Token revoked or expired early — try one refresh, then retry once.
            if ($this->refreshToken(true)) {
                $response = Http::request('GET', $url, $this->headers());
            }
        }

        if ($response === null) {
            return null;
        }

        if ($response['status'] === 429) {
            $this->noteRateLimit((int) ($response['headers']['retry-after'] ?? 60));
            return null;
        }

        if ($response['status'] === 204) {
            $result = ['data' => null, 'page_count' => 0, 'item_count' => 0];
        } elseif ($response['status'] >= 200 && $response['status'] < 300) {
            $decoded = json_decode($response['body'], true);
            if ($decoded === null && trim($response['body']) !== 'null') {
                return null;
            }
            $result = [
                'data'       => $decoded,
                'page_count' => (int) ($response['headers']['x-pagination-page-count'] ?? 1),
                'item_count' => (int) ($response['headers']['x-pagination-item-count'] ?? (is_array($decoded) ? count($decoded) : 0)),
            ];
        } else {
            return null;
        }

        if ($ttl > 0) {
            @file_put_contents($cacheFile, json_encode($result));
        }

        return $result;
    }

    /**
     * Same as callWithMeta() but just the decoded body.
     *
     * @return mixed|null
     */
    public function call(string $path, array $params = [], ?int $ttlOverride = null, bool $cacheOnly = false)
    {
        $result = $this->callWithMeta($path, $params, $ttlOverride, $cacheOnly);

        return $result['data'] ?? null;
    }

    private function headers(): array
    {
        $headers = [
            'Content-Type: application/json',
            'trakt-api-version: 2',
            'trakt-api-key: ' . $this->clientId,
            'User-Agent: box-office-of-one/' . self::appVersion(),
        ];

        $token = $this->accessToken();
        if ($token !== null) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    public static function appVersion(): string
    {
        $versionFile = __DIR__ . '/../VERSION';

        return is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '0.0.0';
    }

    // --- Rate limiting ---------------------------------------------------

    /**
     * Once Trakt answers 429, every live call is skipped (served from cache
     * or reported as unavailable) until its Retry-After has passed, rather
     * than piling more requests onto an already-throttled key.
     */
    private function rateLimited(): bool
    {
        $file = $this->cacheDir . '/trakt_ratelimit.txt';

        return is_file($file) && time() < (int) file_get_contents($file);
    }

    private function noteRateLimit(int $retryAfter): void
    {
        @file_put_contents($this->cacheDir . '/trakt_ratelimit.txt', (string) (time() + max(1, $retryAfter)));
    }

    // --- OAuth -----------------------------------------------------------

    private function tokenFile(): string
    {
        return $this->cacheDir . '/trakt_token.php';
    }

    public static function readGuarded(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }
        $raw = (string) @file_get_contents($file);
        if (strpos($raw, self::GUARD) === 0) {
            $raw = substr($raw, strlen(self::GUARD));
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    public static function writeGuarded(string $file, array $data): void
    {
        @file_put_contents($file, self::GUARD . json_encode($data), LOCK_EX);
        @chmod($file, 0600);
    }

    private function accessToken(): ?string
    {
        if (!$this->tokenLoaded) {
            $this->tokenLoaded = true;
            $this->token = self::readGuarded($this->tokenFile());
            if ($this->token !== null) {
                $this->refreshToken(false);
            }
        }

        return $this->token['access_token'] ?? null;
    }

    /**
     * Refreshes the access token when it's within a day of expiring (or
     * unconditionally when $force is set, after a 401). Trakt refresh
     * tokens are single-use, so this holds an exclusive lock and re-reads
     * the token file once it has it — two overlapping requests (say, a page
     * load and a cron run) would otherwise both spend the same refresh
     * token, and the loser would discard a now-valid token for a dead one.
     */
    private function refreshToken(bool $force): bool
    {
        if ($this->token === null || empty($this->token['refresh_token'])) {
            return false;
        }

        $expiresAt = (int) ($this->token['created_at'] ?? 0) + (int) ($this->token['expires_in'] ?? 0);
        if (!$force && $expiresAt - time() > 86400) {
            return false;
        }

        if ($this->clientSecret === '') {
            return false;
        }

        $lock = @fopen($this->cacheDir . '/trakt_token.lock', 'c');
        if ($lock) {
            flock($lock, LOCK_EX);
        }

        try {
            $current = self::readGuarded($this->tokenFile());
            if ($current !== null && ($current['access_token'] ?? '') !== ($this->token['access_token'] ?? '')) {
                // Someone else refreshed while we waited for the lock.
                $this->token = $current;
                return true;
            }

            $response = Http::request('POST', self::BASE_URL . '/oauth/token', [
                'Content-Type: application/json',
                'User-Agent: box-office-of-one/' . self::appVersion(),
            ], json_encode([
                'refresh_token' => $this->token['refresh_token'],
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'redirect_uri'  => 'urn:ietf:wg:oauth:2.0:oob',
                'grant_type'    => 'refresh_token',
            ]));

            $data = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;
            if (!is_array($data) || empty($data['access_token'])) {
                return false;
            }

            $data['created_at'] = $data['created_at'] ?? time();
            self::writeGuarded($this->tokenFile(), $data);
            $this->token = $data;

            return true;
        } finally {
            if ($lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * Step 1 of the device-code flow: ask Trakt for a short user code to
     * enter at trakt.tv/activate.
     *
     * @return array{device_code:string, user_code:string, verification_url:string, expires_in:int, interval:int}|null
     */
    public function requestDeviceCode(): ?array
    {
        $response = Http::request('POST', self::BASE_URL . '/oauth/device/code', [
            'Content-Type: application/json',
            'User-Agent: box-office-of-one/' . self::appVersion(),
        ], json_encode(['client_id' => $this->clientId]));

        $data = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;

        return is_array($data) && isset($data['device_code']) ? $data : null;
    }

    /**
     * Step 2: poll for the token once the user has approved the code.
     *
     * @return string one of: approved | pending | slow_down | expired | denied | invalid | error
     */
    public function pollDeviceToken(string $deviceCode): string
    {
        $response = Http::request('POST', self::BASE_URL . '/oauth/device/token', [
            'Content-Type: application/json',
            'User-Agent: box-office-of-one/' . self::appVersion(),
        ], json_encode([
            'code'          => $deviceCode,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]));

        if ($response === null) {
            return 'error';
        }

        switch ($response['status']) {
            case 200:
                $data = json_decode($response['body'], true);
                if (!is_array($data) || empty($data['access_token'])) {
                    return 'error';
                }
                $data['created_at'] = $data['created_at'] ?? time();
                self::writeGuarded($this->tokenFile(), $data);
                $this->token = $data;
                $this->tokenLoaded = true;
                return 'approved';
            case 400:
                return 'pending';
            case 429:
                return 'slow_down';
            case 404:
                return 'invalid';
            case 409:
            case 410:
                return 'expired';
            case 418:
                return 'denied';
            default:
                return 'error';
        }
    }

    public function forgetToken(): void
    {
        @unlink($this->tokenFile());
        $this->token = null;
        $this->tokenLoaded = true;
    }

    // --- Convenience endpoints ------------------------------------------

    public function getProfile(): ?array
    {
        $data = $this->call('/users/' . $this->userSlug(), ['extended' => 'full'], 21600);

        return is_array($data) ? $data : null;
    }

    public function getStats(): ?array
    {
        $data = $this->call('/users/' . $this->userSlug() . '/stats', [], 900);

        return is_array($data) ? $data : null;
    }

    /**
     * What's playing right now (a scrobble or check-in in progress), or
     * null if nothing is. Cached only briefly — this is what api.php polls.
     */
    public function getWatching(int $ttl): ?array
    {
        $data = $this->call('/users/' . $this->userSlug() . '/watching', ['extended' => 'full,images'], $ttl);

        return is_array($data) && isset($data['type']) ? $data : null;
    }

    public function getRecentHistory(int $limit, int $ttl): array
    {
        $data = $this->call('/users/' . $this->userSlug() . '/history', [
            'limit'    => $limit,
            'page'     => 1,
            'extended' => 'full,images',
        ], $ttl);

        return is_array($data) ? $data : [];
    }

    /**
     * Every rating you've given (movies, shows, seasons, episodes), with the
     * rated item's full info so community ratings can be compared against.
     */
    public function getRatings(): array
    {
        return $this->allPages('/users/' . $this->userSlug() . '/ratings', ['extended' => 'full'], 3600);
    }

    public function getWatchlist(): array
    {
        return $this->allPages('/users/' . $this->userSlug() . '/watchlist', ['extended' => 'full,images'], 3600);
    }

    /**
     * Walks every page of a paginated list endpoint. Capped at 50 pages
     * (5,000 items) as a safety limit.
     */
    private function allPages(string $path, array $params, int $ttl): array
    {
        $items = [];
        for ($page = 1; $page <= 50; $page++) {
            $result = $this->callWithMeta($path, $params + ['page' => $page, 'limit' => 100], $ttl);
            if ($result === null || !is_array($result['data'])) {
                break;
            }
            $items = array_merge($items, $result['data']);
            if ($page >= $result['page_count'] || count($result['data']) === 0) {
                break;
            }
        }

        return $items;
    }

    // --- Static helpers --------------------------------------------------

    /**
     * Best image URL of a given kind (poster, fanart, thumb...) from a
     * Trakt media object fetched with extended=images. Trakt returns these
     * scheme-less ("media.trakt.tv/images/..."), so https:// is added.
     */
    public static function imageUrl(?array $media, string $kind): ?string
    {
        $candidates = $media['images'][$kind] ?? null;
        $url = is_array($candidates) ? ($candidates[0] ?? null) : $candidates;
        if (!is_string($url) || $url === '') {
            return null;
        }

        return preg_match('#^https?://#', $url) ? $url : 'https://' . ltrim($url, '/');
    }

    /**
     * "S02E05" style episode code.
     */
    public static function episodeCode(int $season, int $number): string
    {
        return sprintf('S%02dE%02d', $season, $number);
    }

    /**
     * Trakt genre slugs ("science-fiction") as display names
     * ("Science Fiction").
     */
    public static function prettyGenre(string $slug): string
    {
        $special = ['sci-fi' => 'Sci-Fi', 'tv-movie' => 'TV Movie', 'game-show' => 'Game Show', 'talk-show' => 'Talk Show'];
        if (isset($special[$slug])) {
            return $special[$slug];
        }

        return ucwords(str_replace('-', ' ', $slug));
    }

    public static function resolveTimezone(string $tz): DateTimeZone
    {
        if ($tz !== '') {
            try {
                return new DateTimeZone($tz);
            } catch (Exception $e) {
                // fall through to the server default
            }
        }

        return new DateTimeZone(date_default_timezone_get());
    }

    public const UI_PERIODS = ['all_time', 'this_year', 'this_month', 'this_week', 'today'];

    public static function validUiPeriod(string $period, string $fallback): string
    {
        return in_array($period, self::UI_PERIODS, true) ? $period : $fallback;
    }

    /**
     * Minutes as a compact "3d 4h" / "5h 20m" / "45m" string.
     */
    public static function formatMinutes(int $minutes): string
    {
        $days = intdiv($minutes, 1440);
        $hours = intdiv($minutes % 1440, 60);
        $mins = $minutes % 60;

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        }
        if ($hours > 0) {
            return $hours . 'h ' . $mins . 'm';
        }

        return $mins . 'm';
    }

    /**
     * Lifetime totals from /users/{id}/stats (plus the profile's join date)
     * as display-ready strings, keyed for the stats row and api.php.
     */
    public static function formatLifetimeStats(?array $stats, ?array $profile): array
    {
        if (!$stats) {
            return [];
        }

        $movieMinutes = (int) ($stats['movies']['minutes'] ?? 0);
        $episodeMinutes = (int) ($stats['episodes']['minutes'] ?? 0);
        $joined = $profile['joined_at'] ?? null;

        return [
            'movies'       => number_format((int) ($stats['movies']['watched'] ?? 0)),
            'movie_time'   => self::formatMinutes($movieMinutes),
            'shows'        => number_format((int) ($stats['shows']['watched'] ?? 0)),
            'episodes'     => number_format((int) ($stats['episodes']['watched'] ?? 0)),
            'tv_time'      => self::formatMinutes($episodeMinutes),
            'total_time'   => number_format(($movieMinutes + $episodeMinutes) / 1440, 1) . ' days',
            'ratings'      => number_format((int) ($stats['ratings']['total'] ?? 0)),
            'member_since' => $joined ? date('M Y', strtotime($joined)) : '—',
        ];
    }
}
