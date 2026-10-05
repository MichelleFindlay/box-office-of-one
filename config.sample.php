<?php
/**
 * Copy this file to config.php and fill in your own values.
 * config.php is gitignored so your API credentials never get committed.
 *
 * Create a Trakt API app at: https://trakt.tv/oauth/applications/new
 *   - Name: anything (e.g. "box-office-of-one")
 *   - Redirect URI: urn:ietf:wg:oauth:2.0:oob
 *   - Leave the permission checkboxes (/checkin, /scrobble) unticked —
 *     this dashboard only ever reads.
 * Then copy its Client ID (and Client Secret, if your profile is private)
 * below.
 */
return [
    // Required
    'client_id' => 'YOUR_TRAKT_CLIENT_ID',
    'username'  => 'YOUR_TRAKT_USERNAME',

    // Only needed if your Trakt profile is private (Settings → Privacy on
    // trakt.tv). A public profile is fully readable with just client_id.
    // With a secret set, run `php auth.php` once to sign in — see README.md.
    'client_secret' => '',

    // Dashboard branding
    'app_name' => 'Box Office of One',

    // How often the browser polls for "now watching" updates, in milliseconds
    'poll_interval_ms' => 15000,

    // How long general Trakt API responses are cached, in seconds
    'cache_ttl' => 60,

    // Rows shown in the Top Shows / Top Movies panels
    'top_limit' => 8,

    // IANA timezone, e.g. 'Europe/London', used for period boundaries
    // (when "today" / "this week" start) and every time-of-day widget.
    // Leave blank to use the server's default.
    'timezone' => '',

    // Which timeframe each period-picker panel shows on page load. Visitors
    // can still switch it themselves — this only sets the initial view.
    // Valid values: all_time | this_year | this_month | this_week | today
    // (Exact calendar periods — real Jan 1, real 1st-of-month, real Monday —
    // computed from the local history snapshot.)
    'shows_default_period'  => 'this_year',
    'movies_default_period' => 'this_year',
    'genre_default_period'  => 'all_time',

    // Optional: free TMDB API key (v3 "API Key") used as a fallback for
    // poster art on any title Trakt's own image data doesn't cover.
    // Get one at https://www.themoviedb.org/settings/api
    'tmdb_api_key' => '',

    // Optional: read "Now Watching" straight from your Plex server instead
    // of relying on a live Trakt scrobbler — real playback position, and
    // stays up while paused. Falls back to Trakt whenever nothing's playing
    // on Plex (or Plex can't be reached).
    //   plex_token:  your X-Plex-Token — the only required setting. See
    //                https://support.plex.tv/articles/204059436-finding-an-authentication-token-x-plex-token/
    //                Stays server-side; posters are proxied via plex_art.php.
    //   plex_server: which server to use, by name, if your account can see
    //                more than one. Blank = the first server you own.
    //   plex_user:   whose playback to show, by Plex username. Blank = the
    //                server owner only, so other people sharing your server
    //                don't show up on your dashboard.
    //   plex_url:    optional fixed address, e.g. 'http://192.168.1.10:32400'.
    //                Blank = found automatically through your Plex account
    //                (whichever of the server's addresses answers first).
    'plex_token'  => '',
    'plex_server' => '',
    'plex_user'   => '',
    'plex_url'    => '',

    // Optional: IMDb rating and Rotten Tomatoes Popcornmeter (audience score)
    // next to each title, from MDBList — neither comes from Trakt itself.
    // Trakt's own viewer rating is shown with or without this.
    // Get a free key at https://mdblist.com/preferences/ (1,000 requests/day).
    // cron.php looks up ratings_backfill_per_run titles per run (heaviest-
    // watched first), each cached a week, never exceeding
    // mdblist_daily_limit requests in any 24 hours.
    'mdblist_api_key'          => '',
    'mdblist_daily_limit'      => 999,
    'ratings_backfill_per_run' => 50,

    // Local history snapshot (see lib/Library.php). cron.php pulls this many
    // pages of history (100 plays/page) per run while backfilling, so a
    // long history fills in over several runs rather than one huge one.
    'library_backfill_pages_per_run' => 20,

    // Trakt lets you add plays with a past date or remove plays, which a
    // forward-only sync can't see. Every this-many days, cron.php quietly
    // re-downloads the full history in the background and swaps it in once
    // done (the current copy keeps serving meanwhile). 0 disables.
    'library_rebuild_days' => 7,

    // TMDB poster lookups cron.php makes per run (only with tmdb_api_key
    // set) — heaviest-watched titles first.
    'poster_backfill_per_run' => 40,

    // Widget data is cached for 15 minutes. cron.php also pre-warms that
    // cache and keeps the local history in sync — schedule it every 15
    // minutes (see README.md) and set cron_enabled to true once you have
    // (shown as a small footer note). cron_secret, if set, is required as a
    // ?token= query param for HTTP-triggered runs of cron.php (not for CLI
    // runs), and is also what unlocks signing in via auth.php in a browser.
    //
    // You can generate a random secret here - https://nexty.dev/tools/cron-secret-generator
    'cron_enabled' => false,
    'cron_secret'  => '',

    // MCP (Model Context Protocol) server at mcp.php — lets an AI client
    // (Claude, ChatGPT/OpenAI, or anything else speaking MCP) query your
    // watch history, top shows/movies, genres, ratings, watchlist, and
    // widgets as tools. Read-only, but it's still your real viewing data
    // behind a single static key, so keep it secret the same way you would
    // an API key. Leave blank (the default) to disable the endpoint
    // entirely — it 404s until set. See README.md.
    'mcp_api_key' => '',

    // Footer "update available" check against the latest GitHub release of
    // this repo. Set github_repo to '' to disable the check (and hide the
    // GitHub link) entirely.
    'github_repo'      => 'MichelleFindlay/box-office-of-one',
    'update_check_ttl' => 3600,
];
