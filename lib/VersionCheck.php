<?php

require_once __DIR__ . '/Http.php';

/**
 * Compares the app's configured version against the latest published
 * GitHub release for the repo, so the footer can advise updating when a
 * newer release exists. Deliberately doesn't touch .git at all (an earlier
 * commit-hash-diffing version did) — that's more fragile on hosts where
 * .git isn't even uploaded, and a published release is a more meaningful
 * "you should update" signal than an arbitrary commit diff anyway.
 */
class VersionCheck
{
    private string $repo;
    private string $currentVersion;
    private string $cacheFile;
    private int $cacheTtl;

    public function __construct(string $repo, string $currentVersion, string $rootDir, int $cacheTtl = 3600)
    {
        $this->repo = $repo;
        $this->currentVersion = ltrim($currentVersion, 'vV');
        $this->cacheTtl = $cacheTtl;
        $this->cacheFile = $rootDir . '/cache/version-check.json';
    }

    /**
     * @return array{installed: string, latest: ?string, up_to_date: ?bool, release_url: ?string, error: ?string}
     */
    public function check(): array
    {
        $result = [
            'installed'   => $this->currentVersion,
            'latest'      => null,
            'up_to_date'  => null,
            'release_url' => null,
            'error'       => null,
        ];

        $release = $this->cachedLatestRelease();

        if ($release === false) {
            $result['error'] = 'could not reach GitHub';
            return $result;
        }

        if ($release === null) {
            $result['error'] = 'no releases published yet';
            return $result;
        }

        $latestVersion = ltrim($release['tag_name'] ?? '', 'vV');
        if ($latestVersion === '') {
            $result['error'] = 'could not read release version';
            return $result;
        }

        $result['latest']      = $latestVersion;
        $result['release_url'] = $release['html_url'] ?? "https://github.com/{$this->repo}/releases";
        $result['up_to_date']  = version_compare($this->currentVersion, $latestVersion, '>=');

        return $result;
    }

    /**
     * @return array|null|false array = the release payload, null = repo has no releases, false = request failed
     */
    private function cachedLatestRelease()
    {
        if (is_file($this->cacheFile) && (time() - filemtime($this->cacheFile)) < $this->cacheTtl) {
            $cached = json_decode((string) file_get_contents($this->cacheFile), true);
            if ($cached !== null) {
                return $cached === [] ? null : $cached; // [] is the cached "no releases" sentinel
            }
        }

        $url = "https://api.github.com/repos/{$this->repo}/releases/latest";
        $response = Http::get($url, ['User-Agent: box-office-of-one', 'Accept: application/vnd.github+json']);

        if ($response === null) {
            return false;
        }

        $data = json_decode($response, true);
        $toCache = isset($data['tag_name']) ? $data : []; // GitHub 404s (as JSON) when there are no releases

        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->cacheFile, json_encode($toCache));

        return $toCache === [] ? null : $toCache;
    }
}
