<?php

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Trakt.php'; // readGuarded()/writeGuarded()

/**
 * Optional direct Plex connection for the "Now Watching" card. Trakt only
 * knows something is playing if a scrobbler reports it live (and loses it
 * the moment you pause); asking your Plex server directly gives the real
 * playback position and paused state — no scrobbler needed.
 *
 * Only plex_token is required. The server is found through Plex's own
 * account API (plex.tv/api/v2/resources), which lists every server your
 * account can reach and the addresses it's reachable at; the first address
 * that answers from wherever this dashboard runs is used, remembered for a
 * day, and re-discovered if it stops working (say, your home IP changes).
 * Set plex_url to skip discovery and use a fixed address instead.
 *
 * The token never leaves the server: posters are fetched through
 * plex_art.php rather than linked directly, since a direct Plex image URL
 * would have to carry the token.
 *
 * Finding your token: https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/
 */
class Plex
{
    private const PLEX_TV = 'https://plex.tv';

    private string $url;
    private string $token;
    private string $user;
    private string $serverName;
    private string $cacheDir;
    private ?array $server = null;

    public function __construct(array $config)
    {
        $this->url = rtrim((string) ($config['plex_url'] ?? ''), '/');
        $this->token = (string) ($config['plex_token'] ?? '');
        $this->user = (string) ($config['plex_user'] ?? '');
        $this->serverName = (string) ($config['plex_server'] ?? '');
        $this->cacheDir = __DIR__ . '/../cache';
    }

    public function enabled(): bool
    {
        return $this->token !== '';
    }

    private function headers(string $token, string $accept = 'application/json'): array
    {
        return [
            'Accept: ' . $accept,
            'X-Plex-Token: ' . $token, // header rather than query string, so it stays out of server logs
            'X-Plex-Product: box-office-of-one',
            'X-Plex-Client-Identifier: box-office-of-one',
        ];
    }

    private function serverCacheFile(): string
    {
        return $this->cacheDir . '/plex_server.php'; // guarded — holds the server's access token
    }

    private function backoffFile(): string
    {
        return $this->cacheDir . '/plex_backoff.txt';
    }

    /**
     * Where to reach the server and which token to use there.
     *
     * @return array{url: string, token: string}|null
     */
    private function server(): ?array
    {
        if ($this->server !== null) {
            return $this->server;
        }
        if ($this->url !== '') {
            return $this->server = ['url' => $this->url, 'token' => $this->token];
        }

        $cached = Trakt::readGuarded($this->serverCacheFile());
        if ($cached !== null && time() - (int) ($cached['at'] ?? 0) < 86400) {
            return $this->server = $cached;
        }

        // After a failed discovery (Plex offline, no reachable address),
        // wait a few minutes rather than re-probing on every 15s poll.
        if (is_file($this->backoffFile()) && time() < (int) file_get_contents($this->backoffFile())) {
            return null;
        }

        $found = $this->discover();
        if ($found === null) {
            @file_put_contents($this->backoffFile(), (string) (time() + 300));
            return null;
        }

        Trakt::writeGuarded($this->serverCacheFile(), $found);

        return $this->server = $found;
    }

    /**
     * Asks plex.tv for your servers, picks one (plex_server by name, else
     * the first you own), and tries its addresses in order — local network
     * first (fastest when the dashboard runs at home), then remote, then
     * Plex's relay as a last resort — keeping the first that responds.
     */
    private function discover(): ?array
    {
        $response = Http::request('GET', self::PLEX_TV . '/api/v2/resources?includeHttps=1&includeRelay=1', $this->headers($this->token), null, 8);
        $resources = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;
        if (!is_array($resources)) {
            return null;
        }

        $chosen = null;
        foreach ($resources as $r) {
            if (strpos((string) ($r['provides'] ?? ''), 'server') === false) {
                continue;
            }
            if ($this->serverName !== '') {
                if (strcasecmp((string) ($r['name'] ?? ''), $this->serverName) === 0) {
                    $chosen = $r;
                    break;
                }
            } elseif (!empty($r['owned'])) {
                $chosen = $r;
                break;
            }
        }
        if ($chosen === null) {
            return null;
        }

        $connections = (array) ($chosen['connections'] ?? []);
        usort($connections, function ($a, $b) {
            $rank = fn($c) => !empty($c['relay']) ? 2 : (!empty($c['local']) ? 0 : 1);
            return $rank($a) <=> $rank($b);
        });

        $token = (string) ($chosen['accessToken'] ?? '') ?: $this->token;
        foreach ($connections as $c) {
            $uri = rtrim((string) ($c['uri'] ?? ''), '/');
            if ($uri === '') {
                continue;
            }
            $probe = Http::request('GET', $uri . '/identity', $this->headers($token), null, 3);
            if ($probe !== null && $probe['status'] === 200) {
                return ['url' => $uri, 'token' => $token, 'name' => $chosen['name'] ?? '', 'at' => time()];
            }
        }

        return null;
    }

    /**
     * A request to the server failed — forget the discovered address so the
     * next attempt finds a working one (no-op with a fixed plex_url).
     */
    private function forgetServer(): void
    {
        $this->server = null;
        if ($this->url === '') {
            @unlink($this->serverCacheFile());
        }
    }

    /**
     * The session to show: the configured user's, or — with plex_user left
     * blank — the server owner's (Plex account id 1), so other people
     * sharing your server don't appear on your dashboard. Prefers something
     * actively playing over something paused.
     *
     * @return array|null raw Plex session metadata, or null if nothing's on (or Plex is unreachable)
     */
    public function currentSession(int $ttl): ?array
    {
        if (!$this->enabled()) {
            return null;
        }

        $data = $this->fetch('/status/sessions', 'plex_sessions.json', $ttl);
        if ($data === null) {
            return null;
        }

        $best = null;
        foreach ($data['MediaContainer']['Metadata'] ?? [] as $session) {
            if (!in_array($session['type'] ?? '', ['movie', 'episode'], true)) {
                continue; // music, photos, live TV etc.
            }

            $user = $session['User'] ?? [];
            $mine = $this->user !== ''
                ? strcasecmp((string) ($user['title'] ?? ''), $this->user) === 0
                : (string) ($user['id'] ?? '') === '1';
            if (!$mine) {
                continue;
            }

            if ($best === null || (($session['Player']['state'] ?? '') === 'playing' && ($best['Player']['state'] ?? '') !== 'playing')) {
                $best = $session;
            }
        }

        return $best;
    }

    /**
     * Your most recently finished movies and episodes, newest first, from
     * Plex's own play history — which records a play the moment it ends,
     * whereas Trakt only hears about it when a sync tool next runs (e.g.
     * PlexTraktSync's periodic `sync`). Same user filter as currentSession().
     *
     * @return array<int, array> raw Plex history metadata
     */
    public function recentHistory(int $limit, int $ttl): array
    {
        $account = $this->accountId();
        if (!$this->enabled() || $account === null) {
            return [];
        }

        $query = http_build_query([
            'sort'                   => 'viewedAt:desc',
            'accountID'              => $account,
            'X-Plex-Container-Start' => 0,
            'X-Plex-Container-Size'  => $limit * 3, // room for music etc. filtered out below
        ]);
        $data = $this->fetch('/status/sessions/history/all?' . $query, 'plex_history.json', $ttl);

        $items = [];
        foreach ($data['MediaContainer']['Metadata'] ?? [] as $item) {
            if (in_array($item['type'] ?? '', ['movie', 'episode'], true) && !empty($item['viewedAt'])
                && (string) ($item['accountID'] ?? $account) === $account) {
                $items[] = $item;
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * Plex account id whose plays to show: plex_user's (looked up by name,
     * cached a day), or the server owner's (always 1) when that's blank.
     */
    private function accountId(): ?string
    {
        if ($this->user === '') {
            return '1';
        }

        $data = $this->fetch('/accounts', 'plex_accounts.json', 86400);
        foreach ($data['MediaContainer']['Account'] ?? [] as $a) {
            if (strcasecmp((string) ($a['name'] ?? ''), $this->user) === 0) {
                return (string) $a['id'];
            }
        }

        return null;
    }

    /**
     * GET a server endpoint as JSON, cached for $ttl seconds in cache/$cacheName.
     * A failed request forgets the discovered server address so the next
     * attempt finds a working one.
     */
    private function fetch(string $path, string $cacheName, int $ttl): ?array
    {
        $cacheFile = $this->cacheDir . '/' . $cacheName;
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $data = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($data)) {
                return $data;
            }
        }

        $server = $this->server();
        if ($server === null) {
            return null;
        }
        $response = Http::request('GET', $server['url'] . $path, $this->headers($server['token']), null, 5);
        if ($response === null || $response['status'] !== 200) {
            $this->forgetServer();
            return null;
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data)) {
            return null;
        }
        @file_put_contents($cacheFile, json_encode($data));

        return $data;
    }

    /**
     * Only Plex's own library image paths are ever proxied — never an
     * arbitrary URL, which would turn plex_art.php into an open proxy
     * carrying your token.
     */
    public static function validArtPath(string $path): bool
    {
        return (bool) preg_match('#^/library/metadata/\d+/(thumb|art)/\d+$#', $path);
    }

    /**
     * Fetches (and caches for a day) a resized poster/backdrop.
     *
     * @return string|null JPEG bytes
     */
    public function artImage(string $path, int $width, int $height): ?string
    {
        if (!$this->enabled() || !self::validArtPath($path)) {
            return null;
        }

        $cacheFile = $this->cacheDir . '/plexart_' . md5($path . 'x' . $width . 'x' . $height) . '.jpg';
        if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            return (string) file_get_contents($cacheFile);
        }

        $server = $this->server();
        if ($server === null) {
            return null;
        }

        $query = http_build_query(['width' => $width, 'height' => $height, 'minSize' => 1, 'upscale' => 1, 'url' => $path]);
        $response = Http::request('GET', $server['url'] . '/photo/:/transcode?' . $query, $this->headers($server['token'], 'image/jpeg'), null, 10);
        if ($response === null || $response['status'] !== 200 || $response['body'] === '') {
            return null;
        }

        @file_put_contents($cacheFile, $response['body']);

        return $response['body'];
    }

    public static function artUrl(?string $path, string $kind): ?string
    {
        return $path !== null && self::validArtPath($path) ? 'plex_art.php?kind=' . $kind . '&path=' . rawurlencode($path) : null;
    }
}
