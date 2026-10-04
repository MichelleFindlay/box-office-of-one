<?php

/**
 * JSON endpoint for the insight widget popups and the period pickers. Each
 * widget is computed lazily on first click rather than on every page load,
 * then cached for 15 minutes (see WidgetCache::remember()) — or pre-warmed
 * ahead of time by cron.php, if you've set that up (see cron_enabled in
 * config.php).
 */

header('Content-Type: application/json');

if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

require __DIR__ . '/lib/App.php';

$config = App::loadConfig();
if (App::needsSetup($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing or incomplete config.php']);
    exit;
}

$app = App::boot($config);
$handlers = $app->handlers();
$id = $_GET['id'] ?? '';

if (!isset($handlers[$id])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown widget']);
    exit;
}

// Only the params a handler actually reads go into the cache key, so
// arbitrary extra query strings can't be used to fill cache/ with junk.
$params = array_intersect_key($_GET, array_flip(['id', 'period', 'panel']));
$data = WidgetCache::remember($id, $params, 900, $handlers[$id]);

echo json_encode(['ok' => true, 'id' => $id, 'data' => $data]);
