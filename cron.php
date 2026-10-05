<?php

/**
 * Keeps the local history snapshot in sync and pre-warms the widget and
 * stats caches on a schedule, so visitors always get an already-cached
 * response instead of triggering a cold computation on their own page load.
 *
 * Each run, in order:
 *   1. Picks up any plays since the last run (cheap — usually one call).
 *   2. Continues the historical backfill by a bounded number of pages, so a
 *      long history fills in over several runs rather than one huge one.
 *   3. Advances the periodic background rebuild, if one is due (see
 *      library_rebuild_days in config.php).
 *   4. Fills in a bounded batch of missing posters from TMDB (only with
 *      tmdb_api_key set), and of IMDb / Popcornmeter ratings from MDBList
 *      (only with mdblist_api_key set).
 *   5. Recomputes every widget and every period of every panel.
 *
 * Run this every 15 minutes, matching the widget cache TTL. Two ways to
 * schedule it (crontab syntax: minute 0,15,30,45 of every hour — written
 * out rather than as "star-slash-15" so it doesn't end this comment):
 *
 *   Real system cron (preferred, if you have shell access):
 *     0,15,30,45 * * * * php /full/path/to/box-office-of-one/cron.php >/dev/null 2>&1
 *
 *   URL-based "cron" (common on shared hosting control panels):
 *     0,15,30,45 * * * * curl -s "https://yourdomain.com/path/cron.php?token=YOUR_CRON_SECRET" >/dev/null
 *
 * Once scheduled, set 'cron_enabled' => true in config.php (shown as a
 * small footer note). If 'cron_secret' is set, HTTP requests must include a
 * matching ?token= to run this; CLI runs are always allowed.
 */

if (function_exists('set_time_limit')) {
    @set_time_limit(300);
}

require __DIR__ . '/lib/App.php';

$isCli = PHP_SAPI === 'cli';

function respond(string $message, int $httpStatus = 200): void
{
    global $isCli;

    if ($isCli) {
        fwrite(STDOUT, $message . "\n");
        return;
    }

    http_response_code($httpStatus);
    header('Content-Type: text/plain');
    echo $message . "\n";
}

$config = App::loadConfig();
if (App::needsSetup($config)) {
    respond('cron.php: missing or incomplete config.php', 500);
    exit(1);
}

if (!$isCli) {
    $secret = $config['cron_secret'];
    if ($secret !== '' && !hash_equals($secret, (string) ($_GET['token'] ?? ''))) {
        respond('cron.php: invalid or missing token', 403);
        exit(1);
    }
}

$app = App::boot($config);
$handlers = $app->handlers();

$refreshed = [];
$failed = [];

$step = function (string $name, callable $fn) use (&$refreshed, &$failed) {
    try {
        $result = $fn();
        $refreshed[] = $name;
        return $result;
    } catch (Throwable $e) {
        $failed[] = $name . ' (' . $e->getMessage() . ')';
        return null;
    }
};

$recent = $step('library_sync', fn() => $app->library->syncRecent());
$backfill = $step('library_backfill', fn() => $app->library->backfillBatch(max(1, (int) $config['library_backfill_pages_per_run'])));
$rebuild = $step('library_rebuild', fn() => $app->library->maintainRebuild(
    (int) $config['library_rebuild_days'],
    max(1, (int) $config['library_backfill_pages_per_run'])
));
$posterCount = $step('posters', fn() => $app->library->backfillPosters($app->posters, max(0, (int) $config['poster_backfill_per_run'])));
$ratingCount = $step('ratings', fn() => $app->library->backfillRatings(
    $app->ratings,
    max(0, (int) $config['ratings_backfill_per_run']),
    $app->onPageTitleKeys()
));

foreach (WidgetRegistry::SIMPLE_IDS as $id) {
    $step($id, fn() => WidgetCache::remember($id, ['id' => $id], 900, $handlers[$id], true));
}

// Period-picker panels — every period the picker offers. The handlers read
// $_GET (matching widgets.php's on-demand requests), so it's faked here for
// each in turn; the cache key matches what widgets.php will look up.
foreach (Trakt::UI_PERIODS as $period) {
    foreach ([['id' => 'genre'], ['id' => 'titles', 'panel' => 'shows'], ['id' => 'titles', 'panel' => 'movies']] as $base) {
        $params = $base + ['period' => $period];
        $_GET = $params;
        $step(implode('_', $params), fn() => WidgetCache::remember($params['id'], $params, 900, $handlers[$params['id']], true));
    }
}

$step('lifetime_stats', fn() => $app->lifetimeStats());

$summary = sprintf(
    '[%s] box-office-of-one cron: refreshed %d/%d (%s)',
    date('c'),
    count($refreshed),
    count($refreshed) + count($failed),
    $failed ? 'failed: ' . implode(', ', $failed) : 'all ok'
);

if ($recent) {
    $summary .= sprintf(' — +%d new plays', $recent['plays']);
}
if ($backfill) {
    $summary .= $backfill['complete']
        ? ' — history backfill complete'
        : sprintf(' — history backfill: +%d pages, +%d plays this run', $backfill['pages'], $backfill['plays']);
}
if ($rebuild && $rebuild['status'] !== 'idle') {
    $summary .= ' — rebuild: ' . $rebuild['status'];
}
if ($posterCount) {
    $summary .= sprintf(' — posters: +%d', $posterCount);
}
if ($ratingCount) {
    $summary .= sprintf(' — ratings: +%d', $ratingCount);
}

$coverage = $app->library->coverage();
$summary .= sprintf(' — %s plays stored locally', number_format($coverage['play_count']));

respond($summary, $failed ? 500 : 200);
