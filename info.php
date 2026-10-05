<?php

/**
 * JSON endpoint for the TV show hover card: ?key=s<trakt id>. Fetched
 * lazily by assets/app.js the first time a show is hovered. Trakt's
 * responses are cached (show summary a day, next episode 6 hours), so
 * repeat hovers are served locally.
 */

header('Content-Type: application/json');

require __DIR__ . '/lib/App.php';

$config = App::loadConfig();
if (App::needsSetup($config)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'missing or incomplete config.php']);
    exit;
}

$key = (string) ($_GET['key'] ?? '');
$info = preg_match('/^s\d+$/', $key) ? App::boot($config)->widgets->showInfo($key) : null;

if ($info === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown show']);
    exit;
}

echo json_encode(['ok' => true, 'info' => $info]);
