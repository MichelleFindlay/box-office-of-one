<?php

/**
 * Serves Plex posters/backdrops for the "Now Watching" card without exposing
 * your Plex token to the browser (a direct Plex image URL would need it in
 * the query string). Only Plex library image paths are accepted — see
 * Plex::validArtPath(). Being same-origin also lets the page sample the
 * image for its accent colour, which a cross-origin image often can't.
 */

require __DIR__ . '/lib/App.php';

$config = App::loadConfig();
$path = (string) ($_GET['path'] ?? '');
$sizes = ['poster' => [342, 513], 'backdrop' => [1280, 720]];
$kind = isset($sizes[$_GET['kind'] ?? '']) ? $_GET['kind'] : 'poster';

$plex = $config !== null ? new Plex($config) : null;
$image = $plex !== null && Plex::validArtPath($path) ? $plex->artImage($path, ...$sizes[$kind]) : null;

if ($image === null) {
    http_response_code(404);
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=86400');
echo $image;
