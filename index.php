<?php

if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require __DIR__ . '/lib/App.php';
require __DIR__ . '/lib/VersionCheck.php';

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Inner markup (no wrapping <svg>) for a small set of Lucide icons
 * (ISC-licensed, ~ lucide.dev), used to give each widget card and lifetime
 * stat a quick visual identifier. Kept as plain strings rather than fetched
 * at request time so the page has no runtime dependency on an icon CDN.
 */
const ICONS = [
    'clock'          => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
    'activity'       => '<path d="M22 12h-2.48a2 2 0 0 0-1.93 1.46l-2.35 8.36a.25.25 0 0 1-.48 0L9.24 2.18a.25.25 0 0 0-.48 0l-2.35 8.36A2 2 0 0 1 4.49 12H2"/>',
    'calendar-days'  => '<path d="M8 2v3"/><path d="M16 2v3"/><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M8 13h.01"/><path d="M12 13h.01"/><path d="M16 13h.01"/><path d="M8 17h.01"/><path d="M12 17h.01"/><path d="M16 17h.01"/>',
    'film'           => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M7 3v18"/><path d="M3 7.5h4"/><path d="M3 12h18"/><path d="M3 16.5h4"/><path d="M17 3v18"/><path d="M17 7.5h4"/><path d="M17 16.5h4"/>',
    'clapperboard'   => '<path d="M20.2 6 3 11l-.9-2.4c-.3-1.1.3-2.2 1.3-2.5l13.5-4c1.1-.3 2.2.3 2.5 1.3Z"/><path d="m6.2 5.3 3.1 3.9"/><path d="m12.4 3.4 3.1 4"/><path d="M3 11h18v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
    'tv'             => '<rect width="20" height="15" x="2" y="7" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/>',
    'layers'         => '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 17.65-9.17 4.16a2 2 0 0 1-1.66 0L2 17.65"/><path d="m22 12.65-9.17 4.16a2 2 0 0 1-1.66 0L2 12.65"/>',
    'hourglass'      => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
    'star'           => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    'flame'          => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
    'history'        => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
    'calendar-check' => '<rect width="18" height="18" x="3" y="4" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
    'bookmark'       => '<path d="m19 21-7-4-7 4V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16z"/>',
];

function renderIcon(string $name, string $class): string
{
    if (!isset(ICONS[$name])) {
        return '';
    }

    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" '
        . 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ICONS[$name] . '</svg>';
}

function renderPeriodPicker(string $group, string $active, array $labels): void
{
    echo '<div class="period-picker" data-period-group-wrap="' . e($group) . '">';
    foreach ($labels as $code => $label) {
        $activeClass = $code === $active ? ' active' : '';
        echo '<button type="button" class="period-btn' . $activeClass . '" data-period-group="' . e($group) . '" data-period="' . e($code) . '">' . e($label) . '</button>';
    }
    echo '</div>';
}

const SYNCING_MESSAGE = 'Still syncing your watch history this far back — check again shortly.';

/**
 * IMDb / Trakt / Popcornmeter score chips — same markup as ratingChips() in
 * assets/app.js builds client-side.
 */
function renderRatingChips(array $chips, string $extraClass = ''): string
{
    if (!$chips) {
        return '';
    }

    $html = '<span class="rating-chips' . ($extraClass !== '' ? ' ' . e($extraClass) : '') . '">';
    foreach ($chips as $c) {
        $html .= '<span class="rating-chip rating-' . e($c['kind']) . '" title="' . e($c['title']) . '">'
            . '<span class="rating-label">' . e($c['label']) . '</span> ' . e($c['value']) . '</span>';
    }

    return $html . '</span>';
}

/**
 * Same markup as renderTitleListContent() in assets/app.js builds after a
 * period switch — keep the two in step.
 */
function renderTitleListMarkup(?array $rows, string $emptyMessage): void
{
    if ($rows === null) {
        echo '<p class="empty-state">' . e(SYNCING_MESSAGE) . '</p>';
        return;
    }
    if (empty($rows)) {
        echo '<p class="empty-state">' . e($emptyMessage) . '</p>';
        return;
    }

    echo '<ol class="track-list">';
    foreach ($rows as $r) {
        $initial = strtoupper(mb_substr($r['name'] ?? '?', 0, 1));
        $thumb = $r['art']
            ? '<img src="' . e($r['art']) . '" alt="" loading="lazy" onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'\';">'
                . '<span class="thumb-fallback" style="display:none">' . e($initial) . '</span>'
            : e($initial);
        $name = $r['url']
            ? '<a href="' . e($r['url']) . '" target="_blank" rel="noopener">' . e($r['name']) . '</a>'
            : e($r['name']);
        $infoKey = preg_match('/^s\d+$/', $r['key'] ?? '') ? ' data-info-key="' . e($r['key']) . '"' : '';
        echo '<li class="track-row"' . $infoKey . '>'
            . '<span class="rank">' . (int) $r['rank'] . '</span>'
            . '<span class="thumb thumb-poster">' . $thumb . '</span>'
            . '<span class="meta"><div class="name">' . $name . '</div><div class="artist">' . e($r['sub']) . '</div>'
            . renderRatingChips($r['ratings'] ?? []) . '</span>'
            . '<span class="count">' . e($r['count'])
            . ($r['pct'] !== null ? '<div class="bar"><div class="bar-fill" style="width: ' . (int) $r['pct'] . '%"></div></div>' : '')
            . '</span>'
            . '</li>';
    }
    echo '</ol>';
}

$config = App::loadConfig();
$needsSetup = App::needsSetup($config);
$config = $config ?? App::DEFAULTS;

$current = null;
$previous = null;
$shows = [];
$movies = [];
$genres = [];
$lifetimeStats = [];
$coverage = null;
$apiError = false;

$uiPeriodLabels = [
    'all_time'   => 'All Time',
    'this_year'  => 'This Year',
    'this_month' => 'This Month',
    'this_week'  => 'This Week',
    'today'      => 'Today',
];

if (!$needsSetup) {
    $app = App::boot($config);
    $tz = $app->tz;

    // Without cron.php scheduled, nothing else would ever sync the local
    // history — so do a small, bounded slice of that work on each page load
    // instead. Slower first visits, but the dashboard still fills in.
    if (empty($config['cron_enabled'])) {
        try {
            $app->library->syncRecent();
            $app->library->backfillBatch(3);
            $app->library->backfillRatings($app->ratings, 5, $app->onPageTitleKeys());
        } catch (Throwable $e) {
            // Non-fatal: the page still renders from whatever's stored.
        }
    }

    $now = $app->nowWatching(max(5, (int) ($config['poll_interval_ms'] / 1000) - 2));
    $current = $now['current'];
    $previous = $now['previous'];
    $apiError = $current === null && $app->trakt->getProfile() === null;

    $activeShowsPeriod  = Trakt::validUiPeriod($config['shows_default_period'], 'this_year');
    $activeMoviesPeriod = Trakt::validUiPeriod($config['movies_default_period'], 'this_year');
    $activeGenrePeriod  = Trakt::validUiPeriod($config['genre_default_period'], 'all_time');

    $limit = (int) $config['top_limit'];
    $shows = WidgetRegistry::titleRows($app->library, $app->widgets, 'shows', $activeShowsPeriod, $limit, $tz);
    $movies = WidgetRegistry::titleRows($app->library, $app->widgets, 'movies', $activeMoviesPeriod, $limit, $tz);
    $genres = $app->library->genres(Library::periodStart($activeGenrePeriod, $tz));

    $statsMap = $app->lifetimeStats();
    if ($statsMap) {
        $lifetimeStats = [
            ['key' => 'movies', 'icon' => 'film', 'label' => 'Movies', 'value' => $statsMap['movies']],
            ['key' => 'movie_time', 'icon' => 'clapperboard', 'label' => 'Movie Time', 'value' => $statsMap['movie_time']],
            ['key' => 'shows', 'icon' => 'tv', 'label' => 'Shows', 'value' => $statsMap['shows']],
            ['key' => 'episodes', 'icon' => 'layers', 'label' => 'Episodes', 'value' => $statsMap['episodes']],
            ['key' => 'tv_time', 'icon' => 'clock', 'label' => 'TV Time', 'value' => $statsMap['tv_time']],
            ['key' => 'total_time', 'icon' => 'hourglass', 'label' => 'Total', 'value' => $statsMap['total_time']],
            ['key' => 'ratings', 'icon' => 'star', 'label' => 'Ratings', 'value' => $statsMap['ratings']],
            ['key' => 'member_since', 'icon' => 'calendar-days', 'label' => 'Tracking Since', 'value' => $statsMap['member_since']],
        ];
    }

    $coverage = $app->library->coverage();
}

$widgetDefs = [
    ['id' => 'watch_clock', 'icon' => 'clock', 'title' => 'Watch Clock', 'teaser' => 'When you actually press play, mapped across 24 hours'],
    ['id' => 'week_rhythm', 'icon' => 'activity', 'title' => 'Weekly Rhythm', 'teaser' => 'Which days of the week you watch the most'],
    ['id' => 'time_watched', 'icon' => 'hourglass', 'title' => 'Time Watched', 'teaser' => 'Your lifetime screen time, converted into something absurd'],
    ['id' => 'binge', 'icon' => 'flame', 'title' => 'Binge Report', 'teaser' => 'Your longest back-to-back episode runs'],
    ['id' => 'decades', 'icon' => 'history', 'title' => 'Movie Decades', 'teaser' => 'Which eras your film taste lives in'],
    ['id' => 'streaks', 'icon' => 'calendar-check', 'title' => 'Streaks', 'teaser' => 'Days in a row, and a year of viewing at a glance'],
    ['id' => 'hot_takes', 'icon' => 'star', 'title' => 'Hot Takes', 'teaser' => "Where your ratings and everyone else's disagree most"],
    ['id' => 'watchlist', 'icon' => 'bookmark', 'title' => 'Watchlist Debt', 'teaser' => "How long it'd take to clear — plus a pick for tonight"],
];

$versionInfo = ['installed' => Trakt::appVersion(), 'latest' => null, 'up_to_date' => null, 'release_url' => null, 'error' => null];
if (!empty($config['github_repo'])) {
    $versionCheck = new VersionCheck($config['github_repo'], Trakt::appVersion(), __DIR__, (int) $config['update_check_ttl']);
    $versionInfo = $versionCheck->check();
}

$profileUrl = $config['username'] !== '' && !$needsSetup ? 'https://trakt.tv/users/' . rawurlencode($config['username']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($config['app_name']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= (int) @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>

<div class="bg">
    <div class="bg-layer" data-bg-a></div>
    <div class="bg-layer" data-bg-b></div>
</div>
<div class="bg-scrim"></div>

<div class="wrap">
    <header class="site-header">
        <h1><?= e($config['app_name']) ?></h1>
        <span class="updated" data-updated></span>
    </header>

    <?php if ($needsSetup): ?>
        <div class="error-banner">
            <strong>Setup needed:</strong> copy <code>config.sample.php</code> to <code>config.php</code>
            and fill in your Trakt <code>client_id</code> and <code>username</code>.
        </div>
    <?php elseif ($apiError): ?>
        <div class="error-banner">
            Couldn't read Trakt data for <strong><?= e($config['username']) ?></strong>. Check
            <code>client_id</code> and <code>username</code> in <code>config.php</code> — and if the profile
            is private, set <code>client_secret</code> and run <code>php auth.php</code> to sign in.
        </div>
    <?php elseif ($coverage && !$coverage['backfill_complete']): ?>
        <div class="sync-banner">
            Syncing your watch history: <?= number_format($coverage['play_count']) ?> plays stored so far<?php
            if ($coverage['covered_since']): ?>, back to <?= e((new DateTime('@' . $coverage['covered_since']))->setTimezone($tz)->format('j M Y')) ?><?php endif; ?>.
            <?= empty($config['cron_enabled'])
                ? 'Each page load fetches a little more — scheduling cron.php (see README) makes this much faster.'
                : 'cron.php fetches more every run.' ?>
        </div>
    <?php endif; ?>

    <?php $heroInitial = strtoupper(mb_substr($current['title'] ?? '?', 0, 1)); ?>
    <section class="now-playing">
        <div class="art-tile art-tile-poster">
            <span class="art-tile-fallback" data-art-fallback
                  style="<?= empty($current['image']) ? '' : 'display:none' ?>"><?= e($heroInitial) ?></span>
            <img data-art-img src="<?= e($current['image'] ?? '') ?>" alt="Poster"
                 style="<?= empty($current['image']) ? 'display:none' : '' ?>">
        </div>
        <div class="info">
            <div class="status-badge<?= !empty($current['live']) ? ' live' : '' ?>" data-status-badge>
                <?= !empty($current['live'])
                    ? '<span class="eq"><span></span><span></span><span></span></span> Now watching'
                    : 'Last watched' ?>
            </div>
            <p class="track-name" data-track-name<?= !empty($current['info_key']) ? ' data-info-key="' . e($current['info_key']) . '"' : '' ?>><?= e($current['title'] ?? 'Nothing watched yet') ?></p>
            <p class="track-artist" data-track-artist><?= e($current['subtitle'] ?? '') ?></p>
            <p class="track-album" data-track-album><?= e($current['meta'] ?? '') ?></p>
            <div class="hero-ratings" data-hero-ratings><?= renderRatingChips($current['ratings'] ?? []) ?></div>
            <div class="watch-progress" data-watch-progress style="<?= !empty($current['live']) ? '' : 'display:none' ?>">
                <div class="watch-progress-bar"><div class="watch-progress-fill" data-watch-progress-fill></div></div>
                <span class="watch-progress-label" data-watch-progress-label></span>
            </div>
            <div class="listen-links">
                <a class="listen-link" data-trakt-link href="<?= e($current['url'] ?? '') ?>" target="_blank" rel="noopener"
                   style="<?= empty($current['url']) ? 'display:none' : '' ?>">
                    <?= renderIcon('tv', 'listen-link-icon') ?>
                    <span>View on Trakt</span>
                </a>
            </div>
        </div>
        <?php $prevInitial = strtoupper(mb_substr($previous['title'] ?? '?', 0, 1)); ?>
        <div class="prev-track" data-prev-track style="<?= $previous ? '' : 'display:none' ?>"<?= !empty($previous['info_key']) ? ' data-info-key="' . e($previous['info_key']) . '"' : '' ?>>
            <div class="prev-track-thumb prev-track-thumb-poster">
                <span class="prev-track-thumb-fallback" data-prev-art-fallback
                      style="<?= empty($previous['image']) ? '' : 'display:none' ?>"><?= e($prevInitial) ?></span>
                <img data-prev-art-img src="<?= e($previous['image'] ?? '') ?>" alt=""
                     style="<?= empty($previous['image']) ? 'display:none' : '' ?>">
            </div>
            <div class="prev-track-info">
                <div class="prev-track-label">Previously watched</div>
                <div class="prev-track-name" data-prev-track-name><?= e($previous['title'] ?? '') ?></div>
                <div class="prev-track-artist" data-prev-track-artist><?= e($previous['subtitle'] ?? '') ?></div>
                <div data-prev-ratings><?= renderRatingChips($previous['ratings'] ?? []) ?></div>
            </div>
        </div>
    </section>

    <div class="panels">
        <section class="panel">
            <div class="panel-header-row">
                <h2>Top Shows</h2>
                <?php if (!$needsSetup): ?>
                    <?php renderPeriodPicker('shows', $activeShowsPeriod, $uiPeriodLabels); ?>
                <?php endif; ?>
            </div>
            <div data-period-content="shows">
                <?php renderTitleListMarkup($shows, 'No episodes watched in this period.'); ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-header-row">
                <h2>Top Movies</h2>
                <?php if (!$needsSetup): ?>
                    <?php renderPeriodPicker('movies', $activeMoviesPeriod, $uiPeriodLabels); ?>
                <?php endif; ?>
            </div>
            <div data-period-content="movies">
                <?php renderTitleListMarkup($movies, 'No movies watched in this period.'); ?>
            </div>
        </section>
    </div>

    <section class="panel panel-wide">
        <div class="panel-header-row">
            <h2>Genre Breakdown</h2>
            <?php if (!$needsSetup): ?>
                <div class="genre-controls">
                    <?php renderPeriodPicker('genre', $activeGenrePeriod, $uiPeriodLabels); ?>
                    <label class="genre-threshold-label">
                        Show
                        <select data-genre-threshold>
                            <option value="0">all genres</option>
                            <option value="1" selected>above 1%</option>
                            <option value="2">above 2%</option>
                            <option value="5">above 5%</option>
                        </select>
                    </label>
                </div>
            <?php endif; ?>
        </div>
        <div data-period-content="genre">
            <?php if ($genres === null): ?>
                <p class="empty-state"><?= e(SYNCING_MESSAGE) ?></p>
            <?php elseif (empty($genres)): ?>
                <p class="empty-state">Nothing watched in this period.</p>
            <?php else: ?>
                <div class="genre-bar">
                    <?php foreach ($genres as $i => $g): ?>
                        <div class="genre-segment" style="width: <?= $g['pct'] ?>%; background: hsl(<?= fmod($i * 137.508, 360) ?>, 65%, 55%)"
                             title="<?= e($g['name'] . ' — ' . $g['pct'] . '%') ?>"></div>
                    <?php endforeach; ?>
                </div>
                <ul class="genre-legend">
                    <?php foreach ($genres as $i => $g): ?>
                        <li class="genre-legend-item" data-pct="<?= $g['pct'] ?>">
                            <span class="genre-swatch" style="background: hsl(<?= fmod($i * 137.508, 360) ?>, 65%, 55%)"></span>
                            <span class="genre-name"><?= e($g['name']) ?></span>
                            <span class="genre-pct"><?= $g['pct'] ?>%</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$needsSetup): ?>
        <div class="widget-grid">
            <?php foreach ($widgetDefs as $w): ?>
                <button type="button" class="widget-card" data-widget-id="<?= e($w['id']) ?>">
                    <?= renderIcon($w['icon'], 'widget-card-icon') ?>
                    <span class="widget-card-title"><?= e($w['title']) ?></span>
                    <span class="widget-card-teaser"><?= e($w['teaser']) ?></span>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="modal-overlay" data-modal-overlay hidden>
            <div class="modal" role="dialog" aria-modal="true">
                <button type="button" class="modal-close" data-modal-close aria-label="Close">&times;</button>
                <div class="modal-body" data-modal-body></div>
            </div>
        </div>
    <?php endif; ?>

    <section class="panel panel-wide">
        <h2>Lifetime Stats</h2>
        <?php if (empty($lifetimeStats)): ?>
            <p class="empty-state">Stats unavailable.</p>
        <?php else: ?>
            <div class="stats-row">
                <?php foreach ($lifetimeStats as $stat): ?>
                    <div class="stat-item">
                        <?= renderIcon($stat['icon'], 'stat-icon') ?>
                        <div class="stat-value" data-stat="<?= e($stat['key']) ?>"><?= e($stat['value']) ?></div>
                        <div class="stat-label"><?= e($stat['label']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <footer class="site-footer">
        <?php if ($profileUrl): ?>
            <div class="lastfm-profile-line">
                <a href="<?= e($profileUrl) ?>" target="_blank" rel="noopener">
                    <?= renderIcon('tv', 'lastfm-icon') ?>
                    <span><?= e($config['username']) ?> on Trakt</span>
                </a>
            </div>
        <?php endif; ?>
        <div class="version-line">
            <?php if (!empty($config['github_repo'])): ?>
                <a class="version-gh-link" href="https://github.com/<?= e($config['github_repo']) ?>" target="_blank" rel="noopener">
                    <svg class="github-icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
                        <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z"></path>
                    </svg>
                    box-office-of-one
                </a>
            <?php else: ?>
                box-office-of-one
            <?php endif; ?>
            v<?= e(Trakt::appVersion()) ?>
            <?php if (empty($config['github_repo'])): ?>
                &middot; <span class="version-muted">update check disabled</span>
            <?php elseif ($versionInfo['error']): ?>
                &middot; <span class="version-muted"><?= e($versionInfo['error']) ?></span>
            <?php elseif ($versionInfo['up_to_date']): ?>
                &middot; <span class="version-ok">up to date</span>
            <?php else: ?>
                &middot; <a class="version-update" href="<?= e($versionInfo['release_url']) ?>" target="_blank" rel="noopener">Update available: v<?= e($versionInfo['latest']) ?></a>
                — you're on v<?= e($versionInfo['installed']) ?>
            <?php endif; ?>
        </div>
        <?php if (!empty($config['cron_enabled'])): ?>
            <div class="cron-line" title="cron.php is scheduled to sync history and refresh widget caches every 15 minutes">
                &#8635; Background refresh active
            </div>
        <?php endif; ?>
    </footer>
</div>

<script>
window.APP_CONFIG = { pollIntervalMs: <?= (int) $config['poll_interval_ms'] ?>, syncingMessage: <?= json_encode(SYNCING_MESSAGE) ?> };
window.INITIAL_ITEM = <?= json_encode($current) ?>;
</script>
<script src="assets/app.js?v=<?= (int) @filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
