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
    'arrow-right-left' => '<path d="m16 3 4 4-4 4"/><path d="M20 7H4"/><path d="m8 21-4-4 4-4"/><path d="M4 17h16"/>',
    'music'          => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>',
];

/**
 * Logos for the footer's "Powered by" row: brand icons from Simple Icons
 * (CC0, simpleicons.org), plus JustWatch's play-triangle mark redrawn, as
 * it isn't in that set. 24×24 paths, inlined like ICONS above.
 */
const BRAND_LOGOS = [
    'trakt' => ['#9F42C6', 'm15.082 15.107-.73-.73 9.578-9.583a4.499 4.499 0 0 0-.115-.575L13.662 14.382l1.08 1.08-.73.73-1.81-1.81L23.422 3.144c-.075-.15-.155-.3-.25-.44L11.508 14.377l2.154 2.155-.73.73-7.193-7.199.73-.73 4.309 4.31L22.546 1.86A5.618 5.618 0 0 0 18.362 0H5.635A5.637 5.637 0 0 0 0 5.634V18.37A5.632 5.632 0 0 0 5.635 24h12.732C21.477 24 24 21.48 24 18.37V6.19l-8.913 8.918zm-4.314-2.155L6.814 8.988l.73-.73 3.954 3.96zm1.075-1.084-3.954-3.96.73-.73 3.959 3.96zm9.853 5.688a4.141 4.141 0 0 1-4.14 4.14H6.438a4.144 4.144 0 0 1-4.139-4.14V6.438A4.141 4.141 0 0 1 6.44 2.3h10.387v1.04H6.438c-1.71 0-3.099 1.39-3.099 3.1V17.55c0 1.71 1.39 3.105 3.1 3.105h11.117c1.71 0 3.1-1.395 3.1-3.105v-1.754h1.04v1.754z'],
    'plex' => ['#EBAF00', 'M3.987 8.409c-.96 0-1.587.28-2.12.933v-.72H0v8.88s.038.018.127.037c.138.03.821.187 1.331-.249.441-.377.542-.814.542-1.318v-1.283c.533.573 1.147.813 2 .813 1.84 0 3.253-1.493 3.253-3.48 0-2.12-1.36-3.613-3.266-3.613Zm16.748 5.595.406.591c.391.614.894.906 1.492.908.621-.012 1.064-.562 1.226-.755 0 0-.307-.27-.686-.72-.517-.614-1.214-1.755-1.24-1.803l-1.198 1.779Zm-3.205-1.955c0-2.08-1.52-3.64-3.52-3.64s-3.467 1.587-3.467 3.573a3.48 3.48 0 0 0 3.507 3.52c1.413 0 2.626-.84 3.253-2.293h-2.04l-.093.093c-.427.4-.72.533-1.227.533-.787 0-1.373-.506-1.453-1.266h4.986c.04-.214.054-.307.054-.52Zm-7.671-.219c0 .769.11 1.701.868 2.722l.056.069c-.306.526-.742.88-1.248.88-.399 0-.814-.211-1.138-.579a2.177 2.177 0 0 1-.538-1.441V6.409H9.86l-.001 5.421Zm9.283 3.46h-2.39l2.247-3.332-2.247-3.335h2.39l2.248 3.335-2.248 3.332Zm1.593-1.286Zm-17.162-.342c-.933 0-1.68-.773-1.68-1.72s.76-1.666 1.68-1.666c.92 0 1.68.733 1.68 1.68 0 .946-.733 1.706-1.68 1.706Zm18.361-1.974L24 8.622h-2.391l-.87 1.293 1.195 1.773Zm-9.404-.466c.16-.706.72-1.133 1.493-1.133.773 0 1.373.467 1.507 1.133h-3Z'],
    'tmdb' => ['#01B4E4', 'M6.62 12a2.291 2.291 0 0 1 2.292-2.295h-.013A2.291 2.291 0 0 1 11.189 12a2.291 2.291 0 0 1-2.29 2.291h.013A2.291 2.291 0 0 1 6.62 12zm10.72-4.062h4.266a2.291 2.291 0 0 0 2.29-2.291 2.291 2.291 0 0 0-2.29-2.296H17.34a2.291 2.291 0 0 0-2.291 2.296 2.291 2.291 0 0 0 2.29 2.29zM2.688 20.645h8.285a2.291 2.291 0 0 0 2.291-2.292 2.291 2.291 0 0 0-2.29-2.295H2.687a2.291 2.291 0 0 0-2.291 2.295 2.291 2.291 0 0 0 2.29 2.292zm10.881-6.354h.81l1.894-4.586H15.19l-1.154 3.008h-.013l-1.135-3.008h-1.154zm4.208 0h1.011V9.705h-1.011zm2.878 0h3.235v-.93h-2.223v-.933h1.99v-.934h-1.99v-.855h2.107v-.934h-3.112zM1.31 7.941h1.01V4.247h1.31v-.895H0v.895h1.31zm3.747 0h1.011V5.959h1.958v1.984h1.011v-4.59h-1.01v1.711H6.061V3.351H5.057zm5.348 0h3.242v-.933H11.41v-.934h1.99v-.933h-1.99v-.856h2.107v-.934h-3.112zM.162 14.296h1.005v-3.52h.013l1.167 3.52h.765l1.206-3.52h.013v3.52h1.011v-4.59H3.82L2.755 12.7h-.013L1.686 9.705H.156zm14.534 6.353h1.641a3.188 3.188 0 0 0 .98-.149 2.531 2.531 0 0 0 .824-.437 2.123 2.123 0 0 0 .567-.713 2.193 2.193 0 0 0 .223-.983 2.399 2.399 0 0 0-.218-1.07 1.958 1.958 0 0 0-.586-.716 2.405 2.405 0 0 0-.873-.392 4.349 4.349 0 0 0-1.046-.13h-1.519zm1.013-3.656h.596a2.26 2.26 0 0 1 .606.08 1.514 1.514 0 0 1 .503.244 1.167 1.167 0 0 1 .34.412 1.28 1.28 0 0 1 .13.587 1.546 1.546 0 0 1-.13.658 1.127 1.127 0 0 1-.347.433 1.41 1.41 0 0 1-.518.238 2.797 2.797 0 0 1-.649.07h-.538zm4.686 3.656h1.88a2.997 2.997 0 0 0 .613-.064 1.735 1.735 0 0 0 .554-.214 1.221 1.221 0 0 0 .402-.39 1.105 1.105 0 0 0 .155-.606 1.188 1.188 0 0 0-.071-.415 1.01 1.01 0 0 0-.204-.34 1.087 1.087 0 0 0-.317-.24 1.297 1.297 0 0 0-.413-.13v-.012a1.203 1.203 0 0 0 .575-.366.962.962 0 0 0 .216-.648 1.081 1.081 0 0 0-.149-.603 1.022 1.022 0 0 0-.389-.354 1.673 1.673 0 0 0-.54-.169 4.463 4.463 0 0 0-.6-.041h-1.712zm1.011-3.734h.687a1.4 1.4 0 0 1 .24.022.748.748 0 0 1 .22.075.432.432 0 0 1 .16.147.418.418 0 0 1 .061.236.47.47 0 0 1-.055.233.433.433 0 0 1-.146.156.62.62 0 0 1-.204.084 1.058 1.058 0 0 1-.23.026h-.745zm0 1.835h.765a1.96 1.96 0 0 1 .266.02 1.015 1.015 0 0 1 .26.07.519.519 0 0 1 .204.152.406.406 0 0 1 .08.26.481.481 0 0 1-.06.253.519.519 0 0 1-.16.168.62.62 0 0 1-.217.09 1.155 1.155 0 0 1-.237.027H21.4z'],
    'justwatch' => ['#FBC500', 'M3.00 3.10v4.00l3.90-2.00zM3.00 7.70v4.00l3.90-2.00zM3.00 12.30v4.00l3.90-2.00zM3.00 16.90v4.00l3.90-2.00zM7.40 5.40v4.00l3.90-2.00zM7.40 10.00v4.00l3.90-2.00zM7.40 14.60v4.00l3.90-2.00zM11.80 7.70v4.00l3.90-2.00zM11.80 12.30v4.00l3.90-2.00zM16.20 10.00v4.00l3.90-2.00z'],
    'mdblist' => ['#4284CA', 'M1.928.029A2.47 2.47 0 0 0 .093 1.673c-.085.248-.09.629-.09 10.33s.005 10.08.09 10.33a2.51 2.51 0 0 0 1.512 1.558l.276.108h20.237l.277-.108a2.51 2.51 0 0 0 1.512-1.559c.085-.25.09-.63.09-10.33s-.005-10.08-.09-10.33A2.51 2.51 0 0 0 22.395.115l-.277-.109L12.117 0C6.615-.004 2.032.011 1.929.029m7.48 8.067 2.123 2.004v1.54c0 .897-.02 1.536-.043 1.527s-.92-.845-1.995-1.86c-1.071-1.01-1.962-1.84-1.977-1.84s-.024 1.91-.024 4.248v4.25H4.911V6.085h1.188l1.183.006zm9.729 3.93v5.94h-2.63l-.01-4.25-.013-4.25-1.907 1.795a367 367 0 0 1-1.98 1.864c-.076.056-.08-.047-.08-1.489v-1.555l2.127-1.995 2.122-1.995 1.187-.005h1.184z'],
    'musicbrainz' => ['#BA478F', 'M11.582 0L1.418 5.832v12.336L11.582 24V10.01L7.1 12.668v3.664c.01.111.01.225 0 .336-.103.435-.54.804-1 1.111-.802.537-1.752.509-2.166-.111-.413-.62-.141-1.631.666-2.168.384-.28.863-.399 1.334-.332V6.619c0-.154.134-.252.226-.308L11.582 3zm.836 0v6.162c.574.03 1.14.16 1.668.387a2.225 2.225 0 0 0 1.656-.717 1.02 1.02 0 1 1 1.832-.803l.004.006a1.022 1.022 0 0 1-1.295 1.197c-.34.403-.792.698-1.297.85.34.263.641.576.891.928a1.04 1.04 0 0 1 .777.125c.768.486.568 1.657-.318 1.857-.886.2-1.574-.77-1.09-1.539.02-.03.042-.06.065-.09a3.598 3.598 0 0 0-1.436-1.166 4.142 4.142 0 0 0-1.457-.369v4.01c.855.06 1.256.493 1.555.834.227.256.356.39.578.402.323.018.568.008.806 0a5.44 5.44 0 0 1 .895.022c.94-.017 1.272-.226 1.605-.446a2.533 2.533 0 0 1 1.131-.463 1.027 1.027 0 0 1 .12-.263 1.04 1.04 0 0 1 .105-.137c.023-.025.047-.044.07-.066a4.775 4.775 0 0 1 0-2.405l-.012-.01a1.02 1.02 0 1 1 .692.272h-.057a4.288 4.288 0 0 0 0 1.877h.063a1.02 1.02 0 1 1-.545 1.883l-.047-.033a1 1 0 0 1-.352-.442 1.885 1.885 0 0 0-.814.354 3.03 3.03 0 0 1-.703.365c.757.555 1.772 1.6 2.199 2.299a1.03 1.03 0 0 1 .256-.033 1.02 1.02 0 1 1-.545 1.88l-.047-.03a1.017 1.017 0 0 1-.27-1.376.72.72 0 0 1 .051-.072c-.445-.775-2.026-2.28-2.46-2.387a4.037 4.037 0 0 0-1.31-.117c-.24.008-.513.018-.866 0-.515-.027-.783-.333-1.043-.629-.26-.296-.51-.56-1.055-.611V18.5a1.877 1.877 0 0 0 .426-.135.333.333 0 0 1 .058-.027c.56-.267 1.421-.91 2.096-2.447a1.02 1.02 0 0 1-.27-1.344 1.02 1.02 0 1 1 .915 1.54 6.273 6.273 0 0 1-1.432 2.136 1.785 1.785 0 0 1 .691.306.667.667 0 0 0 .37.168 3.31 3.31 0 0 0 .888-.222 1.02 1.02 0 0 1 1.787-.79v-.005a1.02 1.02 0 0 1-.773 1.683 1.022 1.022 0 0 1-.719-.287 3.935 3.935 0 0 1-1.168.287h-.05a1.313 1.313 0 0 1-.71-.275c-.262-.177-.51-.345-1.402-.12a2.098 2.098 0 0 1-.707.2V24l10.164-5.832V5.832zm4.154 4.904a.352.352 0 0 0-.197.639l.018.01c.163.1.378.053.484-.108v-.002a.352.352 0 0 0-.303-.539zm-4.99 1.928L7.082 9.5v2l4.5-2.668zm8.385.38a.352.352 0 0 0-.295.165v.002a.35.35 0 0 0 .096.473l.013.01a.357.357 0 0 0 .487-.108.352.352 0 0 0-.301-.541zM16.09 8.647a.352.352 0 0 0-.277.163.355.355 0 0 0 .296.54c.482 0 .463-.73-.02-.703zm3.877 2.477a.352.352 0 0 0-.295.164.35.35 0 0 0 .094.475l.015.01a.357.357 0 0 0 .485-.11.352.352 0 0 0-.3-.539zm-4.375 3.594a.352.352 0 0 0-.291.172.35.35 0 0 0-.04.265.352.352 0 1 0 .33-.437zm4.375.789a.352.352 0 0 0-.295.164v.002a.352.352 0 0 0 .094.473l.015.01a.357.357 0 0 0 .485-.108.352.352 0 0 0-.3-.54zm-2.803 2.488v.002a.347.347 0 0 0-.223.084.352.352 0 0 0 .23.62.347.347 0 0 0 .23-.085.348.348 0 0 0 .12-.24.353.353 0 0 0-.35-.38.347.347 0 0 0-.007 0Z'],
    'wikidata' => ['#339966', 'M0 4.583v14.833h.865V4.583zm1.788 0v14.833h2.653V4.583zm3.518 0v14.832H7.96V4.583zm3.547 0v14.834h.866V4.583zm1.789 0v14.833h.865V4.583zm1.759 0v14.834h2.653V4.583zm3.518 0v14.834h.923V4.583zm1.788 0v14.833h2.653V4.583zm3.64 0v14.834h.865V4.583zm1.788 0v14.834H24V4.583Z'],
];

function renderBrandLogo(string $name): string
{
    [$color, $path] = BRAND_LOGOS[$name];

    return '<svg class="powered-by-logo" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">'
        . '<path fill="' . $color . '" d="' . $path . '"/></svg>';
}

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
            . '<span class="rating-label">' . e($c['label']) . '</span><span class="rating-value">' . e($c['value']) . '</span></span>';
    }

    return $html . '</span>';
}

/**
 * Streaming service links beside "View on Trakt" — same markup as
 * watchLinks() in assets/app.js.
 */
function renderWatchLinks(array $links): string
{
    $html = '';
    foreach ($links as $l) {
        $html .= '<a class="listen-link watch-link" href="' . e($l['url']) . '" target="_blank" rel="noopener" title="Watch on ' . e($l['name']) . '">'
            . ($l['logo'] ? '<img src="' . e($l['logo']) . '" alt="" loading="lazy">' : '')
            . '<span>' . e($l['name']) . '</span></a>';
    }

    return $html;
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
        $infoKey = preg_match('/^[ms]\d+$/', $r['key'] ?? '') ? ' data-info-key="' . e($r['key']) . '"' : '';
        echo '<li class="track-row">'
            . '<span class="rank">' . (int) $r['rank'] . '</span>'
            . '<span class="thumb thumb-poster"' . $infoKey . '>' . $thumb . '</span>'
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
            $app->library->backfillAwards($app->awards, Awards::batchSize(), $app->onPageTitleKeys());
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
    ['id' => 'streaming_changes', 'icon' => 'arrow-right-left', 'title' => 'Streaming Changes', 'teaser' => 'What from your watchlist and history just landed on, or left, a streaming service'],
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
        <div class="art-tile art-tile-poster" data-art-tile<?= !empty($current['info_key']) ? ' data-info-key="' . e($current['info_key']) . '"' : '' ?>>
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
            <p class="track-name" data-track-name><?= e($current['title'] ?? 'Nothing watched yet') ?></p>
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
                <a class="listen-link" data-soundtrack-link href="<?= e($current['soundtrack']['url'] ?? '') ?>" target="_blank" rel="noopener"
                   title="<?= e(isset($current['soundtrack']) ? $current['soundtrack']['title'] . ' — ' . $current['soundtrack']['artist'] : '') ?>"
                   style="<?= empty($current['soundtrack']) ? 'display:none' : '' ?>">
                    <?= renderIcon('music', 'listen-link-icon') ?>
                    <span>Soundtrack</span>
                </a>
                <span class="watch-links" data-watch-links><?= renderWatchLinks($current['watch'] ?? []) ?></span>
            </div>
        </div>
        <?php $prevInitial = strtoupper(mb_substr($previous['title'] ?? '?', 0, 1)); ?>
        <div class="prev-track" data-prev-track style="<?= $previous ? '' : 'display:none' ?>">
            <div class="prev-track-thumb prev-track-thumb-poster" data-prev-thumb<?= !empty($previous['info_key']) ? ' data-info-key="' . e($previous['info_key']) . '"' : '' ?>>
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
        <?php
        // Only the services this install actually uses. TMDB and JustWatch
        // both ask for credit wherever their data is shown.
        $poweredBy = array_filter([
            ['trakt', 'Trakt', 'https://trakt.tv', 'Watch history, ratings and show details'],
            $config['plex_token'] !== '' ? ['plex', 'Plex', 'https://www.plex.tv', 'Now Watching'] : null,
            $config['tmdb_api_key'] !== '' ? ['tmdb', 'TMDB', 'https://www.themoviedb.org', 'Posters, where to stream, certificates and collections. This product uses the TMDB API but is not endorsed or certified by TMDB.'] : null,
            $config['tmdb_api_key'] !== '' ? ['justwatch', 'JustWatch', 'https://www.justwatch.com', 'Streaming availability, via TMDB'] : null,
            $config['mdblist_api_key'] !== '' ? ['mdblist', 'MDBList', 'https://mdblist.com', 'IMDb and Rotten Tomatoes scores'] : null,
            (int) $config['awards_backfill_per_run'] > 0 ? ['wikidata', 'Wikidata', 'https://www.wikidata.org', 'Awards'] : null,
            ['musicbrainz', 'MusicBrainz', 'https://musicbrainz.org', 'Soundtracks'],
        ]);
        ?>
        <?php if ($poweredBy): ?>
            <div class="powered-by-line">
                Powered by
                <?php foreach ($poweredBy as [$logo, $name, $url, $what]): ?>
                    <a href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e($name . ' — ' . $what) ?>" aria-label="<?= e($name) ?>"><?= renderBrandLogo($logo) ?></a>
                <?php endforeach; ?>
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
