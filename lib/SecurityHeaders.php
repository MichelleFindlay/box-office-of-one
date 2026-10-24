<?php

/**
 * Security response headers, sent by every web entry point before any
 * output. Done in PHP rather than .htaccess so they apply whatever the
 * server (Apache, LiteSpeed, nginx + FPM...) and however PHP runs.
 *
 * The Content-Security-Policy is strict — no 'unsafe-inline' anywhere —
 * so the page itself keeps to that: no inline scripts, style attributes
 * or event handlers. index.php hands its data to app.js as a JSON block,
 * initial show/hide state is the hidden attribute, and widths and colours
 * are data- attributes that app.js applies. Anything new on the page has
 * to follow the same rules, or the browser will block it.
 */
class SecurityHeaders
{
    /**
     * The HTML pages (index.php, auth.php): what they load, and from where.
     */
    private const PAGE_CSP = [
        "default-src 'none'",
        "script-src 'self'",
        "style-src 'self'",
        // Posters and logos: Trakt's and TMDB's image hosts, plus Plex art
        // and the bundled BBFC symbols from this server.
        "img-src 'self' https://*.trakt.tv https://image.tmdb.org",
        "connect-src 'self'", // api.php, widgets.php, details.php
        "frame-src https://www.youtube-nocookie.com", // the trailer player
        "base-uri 'none'",
        "form-action 'self'", // auth.php's sign-in buttons
        "frame-ancestors 'none'",
    ];

    /**
     * Everything else (JSON, plain text, images): nothing to load at all.
     */
    private const DATA_CSP = [
        "default-src 'none'",
        "frame-ancestors 'none'",
    ];

    /**
     * Features the page never uses are switched off; the ones a YouTube
     * player needs are allowed for this page and the trailer frame only.
     */
    private const PERMISSIONS = [
        'accelerometer=()', 'camera=()', 'geolocation=()', 'gyroscope=()', 'magnetometer=()',
        'microphone=()', 'payment=()', 'usb=()', 'browsing-topics=()',
        'autoplay=(self "https://www.youtube-nocookie.com")',
        'encrypted-media=(self "https://www.youtube-nocookie.com")',
        'fullscreen=(self "https://www.youtube-nocookie.com")',
        'picture-in-picture=(self "https://www.youtube-nocookie.com")',
    ];

    /**
     * @param bool $page true for an HTML page, false for data (JSON, text, images)
     */
    public static function send(bool $page): void
    {
        if (PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }

        header_remove('X-Powered-By'); // don't advertise the PHP version
        header('Content-Security-Policy: ' . implode('; ', $page ? self::PAGE_CSP : self::DATA_CSP));
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        // The origin only, cross-site — YouTube's player needs at least
        // that much to play an embedded video.
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: ' . implode(', ', self::PERMISSIONS));
        header('Cross-Origin-Opener-Policy: same-origin');
    }
}
