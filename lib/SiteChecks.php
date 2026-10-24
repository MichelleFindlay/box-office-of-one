<?php

require_once __DIR__ . '/Http.php';

/**
 * The footer's DNSSEC and security-headers ticks: whether this site's
 * domain is DNSSEC-signed and validates, and whether its pages carry the
 * full set of security headers.
 *
 * - DNSSEC: asks Google's DNS-over-HTTPS resolver (Cloudflare's if that
 *   fails) for the site's address; "AD" (authenticated data) in the
 *   answer means the signatures checked out all the way down.
 * - Security headers: fetches the site's own page from outside, as a
 *   visitor would (so through any CDN in front of it), and checks for
 *   what securityheaders.com needs for an A+: all six headers, and a
 *   Content-Security-Policy without 'unsafe-inline' or 'unsafe-eval'.
 *   securityheaders.com itself doesn't answer automated requests, so the
 *   footer links there to see the real grade.
 *
 * Checked at most once a day (an hour after a failed attempt), by
 * cron.php or, without cron, a page load. The page records its own
 * public address for that, since cron.php can't know it. Not shown for
 * localhost or an IP address, where neither check means anything.
 */
class SiteChecks
{
    private const TTL = 86400;   // a day
    private const RETRY = 3600;  // after a check couldn't be made
    public const SELF_CHECK_HEADER = 'X-Box-Office-Self-Check'; // marks the self-fetch, so it doesn't check again

    private const REQUIRED = [
        'content-security-policy', 'strict-transport-security', 'x-frame-options',
        'x-content-type-options', 'referrer-policy', 'permissions-policy',
    ];

    private string $file;

    public function __construct()
    {
        $this->file = __DIR__ . '/../cache/site_checks.json';
    }

    /**
     * This page's public address, from the request — or null for a
     * localhost / IP address, or outside a web request.
     */
    public static function currentUrl(): ?string
    {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        if ($host === '' || $host === 'localhost' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }

        // Behind a proxy or CDN (Cloudflare), PHP may see plain HTTP.
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || strpos((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false;
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');

        return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
    }

    /**
     * Notes the address the site is being visited at, for later checks.
     */
    public function remember(string $url): void
    {
        $state = $this->load();
        if (($state['url'] ?? null) !== $url) {
            $this->save(['url' => $url]); // a new address: anything checked before no longer applies
        }
    }

    /**
     * @return array{url: string, host: string, dnssec: ?bool, headers: ?bool, problems: string[]}|null null if no address is known yet
     */
    public function results(): ?array
    {
        $state = $this->load();
        if (empty($state['url'])) {
            return null;
        }

        return [
            'url'      => $state['url'],
            'host'     => (string) parse_url($state['url'], PHP_URL_HOST),
            'dnssec'   => $state['dnssec'] ?? null,
            'headers'  => $state['headers'] ?? null,
            'problems' => (array) ($state['problems'] ?? []),
        ];
    }

    /**
     * Re-runs both checks if they're due.
     */
    public function refreshIfDue(): void
    {
        $state = $this->load();
        if (empty($state['url'])) {
            return;
        }
        $complete = isset($state['dnssec'], $state['headers']);
        $age = time() - (int) ($state['checked_at'] ?? 0);
        if ($age < ($complete ? self::TTL : self::RETRY)) {
            return;
        }

        $host = (string) parse_url($state['url'], PHP_URL_HOST);
        $state['dnssec'] = self::dnssec($host) ?? ($state['dnssec'] ?? null);
        [$headers, $problems] = self::headers($state['url']);
        if ($headers !== null) {
            $state['headers'] = $headers;
            $state['problems'] = $problems;
        }
        $state['checked_at'] = time();
        $this->save($state);
    }

    /**
     * True if the host's address validates with DNSSEC, false if it
     * doesn't, null if no resolver could be asked.
     */
    private static function dnssec(string $host): ?bool
    {
        foreach ([
            ['https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A', []],
            ['https://cloudflare-dns.com/dns-query?name=' . rawurlencode($host) . '&type=A', ['Accept: application/dns-json']],
        ] as [$url, $headers]) {
            $data = json_decode((string) Http::get($url, $headers, 6), true);
            if (is_array($data) && isset($data['AD'])) {
                return (bool) $data['AD'] && (int) ($data['Status'] ?? 1) === 0;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?bool, 1: string[]} pass/fail (null if the page couldn't be fetched), and what's wrong
     */
    private static function headers(string $url): array
    {
        $response = Http::request('GET', $url, [self::SELF_CHECK_HEADER . ': 1', 'User-Agent: box-office-of-one site check'], null, 20);
        if ($response === null || $response['status'] >= 500) {
            return [null, []];
        }

        $problems = [];
        foreach (self::REQUIRED as $name) {
            if (trim((string) ($response['headers'][$name] ?? '')) === '') {
                $problems[] = 'missing ' . $name;
            }
        }
        $csp = strtolower((string) ($response['headers']['content-security-policy'] ?? ''));
        foreach (["'unsafe-inline'", "'unsafe-eval'"] as $unsafe) {
            if (strpos($csp, $unsafe) !== false) {
                $problems[] = 'Content-Security-Policy allows ' . $unsafe;
            }
        }

        return [$problems === [], $problems];
    }

    private function load(): array
    {
        $data = json_decode((string) @file_get_contents($this->file), true);

        return is_array($data) ? $data : [];
    }

    private function save(array $state): void
    {
        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, json_encode($state)) !== false) {
            @rename($tmp, $this->file);
        }
    }
}
