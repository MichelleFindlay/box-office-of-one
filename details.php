<?php

/**
 * JSON endpoint for the poster hover card: ?key=s<trakt id> for a show,
 * m<trakt id> for a film. Fetched lazily by assets/app.js the first time a
 * poster is hovered. Trakt's responses are cached (summary a day, next
 * episode 6 hours, cast and crew a week), so repeat hovers are local.
 *
 * Deliberately not called info.php: hosts commonly block or quarantine
 * files with that name (it's the classic phpinfo() page), which silently
 * broke the hover card on a real install.
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
$info = App::boot($config)->widgets->titleInfo($key);

if ($info === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'unknown title']);
    exit;
}

echo json_encode(['ok' => true, 'info' => $info]);
