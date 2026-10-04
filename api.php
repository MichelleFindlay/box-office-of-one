<?php

/**
 * Lightweight JSON endpoint polled by the dashboard to refresh the
 * "now watching" card (and lifetime stats) without reloading the page.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/App.php';

$config = App::loadConfig();
if (App::needsSetup($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing or incomplete config.php']);
    exit;
}

$app = App::boot($config);

// Cache slightly shorter than the browser's poll interval so every poll gets
// fresh data, while still de-duplicating any near-simultaneous requests.
$pollSeconds = max(1, (int) ($config['poll_interval_ms'] / 1000));
$now = $app->nowWatching(max(5, $pollSeconds - 2));

echo json_encode([
    'ok'       => $now['current'] !== null,
    'current'  => $now['current'],
    'previous' => $now['previous'],
    'stats'    => $app->lifetimeStats(),
]);
