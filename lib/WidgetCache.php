<?php

/**
 * File-based cache for a widget's full computed output, shared between
 * widgets.php (serves a widget on demand) and cron.php (pre-warms them on a
 * schedule) so both read and write the exact same cache entries. On expiry
 * the stale file is deleted outright and rebuilt fresh, rather than left
 * around to be merely overwritten.
 *
 * cron.php passes $force: it has just synced new history, so an entry
 * computed a few minutes earlier (say, while the backfill was still in
 * progress) shouldn't be kept for the rest of its TTL.
 */
class WidgetCache
{
    public static function remember(string $id, array $params, int $ttl, callable $compute, bool $force = false): array
    {
        ksort($params);
        $cacheFile = __DIR__ . '/../cache/widget_' . $id . '_' . md5(serialize($params)) . '.json';

        if (!$force && is_file($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if ($cached !== null) {
                return $cached;
            }
        }

        if (is_file($cacheFile)) {
            @unlink($cacheFile);
        }

        $result = $compute();

        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($result));

        return $result;
    }
}
