# box-office-of-one

A single-page [Trakt](https://trakt.tv) dashboard: what you're watching right
now, your top shows and movies, a genre breakdown, lifetime stats, and a
handful of data-driven insight widgets. The whole page is themed from the
current poster. Your full watch history is cached locally, so nearly
everything is computed without live API calls.

A sibling of [lastfm-dash](https://github.com/MichelleFindlay/lastfm-dash),
built the same way: plain PHP, no database, flat-file caching.

## Features

- **Now Watching**: whatever's playing right now (a live scrobble from
  Plex/Kodi/Infuse/etc., a check-in, or read straight from your Plex
  server, see below), with a progress bar. Otherwise it shows the last thing you watched, and the
  item before that sits alongside as "previously watched". Polled every few
  seconds.
- **Dynamic theming**: the page background and accent colour come from the
  current backdrop or poster and ease in smoothly. The accent is clamped to a
  safe contrast range so text stays readable whatever the image.
- **Top Shows**, **Top Movies**, and **Genre Breakdown**: each has an All
  Time / This Year / This Month / This Week / Today picker, switched via AJAX.
  These are exact calendar periods (real Jan 1, real 1st-of-month, real
  Monday) computed from the local history snapshot. Shows rank by episodes
  watched. Movies rank by your own Trakt rating, then a combined community
  score (the average of IMDb, Trakt and Popcornmeter), with plays only as a
  tiebreaker. For films you haven't rated, the community score stands in
  for your rating. Genres are weighted by minutes watched, so one film
  doesn't count the same as a 60-episode binge.
- **Poster hover cards**: rest the mouse on any poster for an info card. For
  a show it has the network, status, synopsis, your progress, the last
  episode you watched, and when the next one airs. For a film it has the
  tagline, certificate, runtime, release date, director, synopsis, how often
  and when you watched it, when it reached cinemas and digital in your
  country, its box office (worldwide gross and budget, from TMDB), and where it sits in its collection ("Toy Story Collection · part 2
  of 5 · 3 of 5 seen"). Both list the soundtrack album, if there is one.
  Certificates are your country's (BBFC in the UK, shown as the BBFC's own
  symbols) where TMDB has them. Both include where it's streaming, your
  rating, the score chips and any major awards. Details load on first hover
  and are cached.
- **Where to stream**: beside "View on Trakt", a link for each streaming
  service the title is on right now, plus a **Trailer** button that plays
  the trailer on the page (from TMDB, or Trakt) and a **Soundtrack** link
  for a show or film that has one. See below.
- **Streaming Changes** widget: titles from your watchlist and history
  that arrived on or left a streaming service in the last 30 days.
- **Awards**: a 🏆 chip on each title with its wins (or nominations) at
  the major film and TV awards and festivals, from Wikidata. See below.
- **Lifetime Stats**: movies, shows, episodes, time spent on each, total
  days, ratings given, and how far back your history goes. These come from
  Trakt's own totals when it shares them. Otherwise they're worked out from
  your local history, since Trakt returns nothing for stats to an app that
  isn't signed in.
- **Insight widgets**: click-through popups:
  - **Watch Clock**: a 24-hour radial chart of when you actually press play
  - **Weekly Rhythm**: hours watched per day of the week
  - **Time Watched**: your lifetime screen time converted into Titanic
    screenings, transatlantic flights, Lord of the Rings marathons and
    trips to the Moon, each with how far into the next one you are
  - **Binge Report**: your longest runs of 3+ episodes of one show back to
    back
  - **Movie Decades**: which eras your film taste lives in
  - **Streaks**: your longest and current run of days in a row, plus a
    GitHub-style calendar of the past year
  - **Hot Takes**: your rating distribution, and the titles where your
    rating is furthest from the Trakt community's
  - **Streaming Changes**: titles from your watchlist and history that
    arrived on or left a streaming service in the last 30 days

  Plays logged in bulk ("mark season as watched", imports) share one
  timestamp, which is when they were logged, not when you watched them.
  Watch Clock, Weekly Rhythm, Binge Report, and Streaks leave out any play
  that shares its exact second with another, and say how many they skipped.
  Those plays still count everywhere else.
- **Self-update check**: the footer compares the installed version against
  the latest GitHub release and links to it when an update is available.
- **MCP server** (optional): lets an AI client (Claude, ChatGPT/OpenAI, or
  anything else speaking MCP) query your watch history directly. See
  "MCP server" below.

## Requirements

- PHP 7.4+ (8.x recommended), with the `zlib` extension (bundled in almost
  every PHP build). `curl` is used when available, with an automatic
  `allow_url_fopen` fallback if it isn't.
- A Trakt API app (free). See Setup.
- No database required. All caching is flat-file, under `cache/`.

## Setup

1. **Create a Trakt API app** at
   [trakt.tv/oauth/applications/new](https://trakt.tv/oauth/applications/new):
   - **Name:** anything, e.g. `box-office-of-one`
   - **Redirect URI:** `urn:ietf:wg:oauth:2.0:oob`
   - Leave the `/checkin` and `/scrobble` permissions unticked, since this
     dashboard only ever reads.

2. **Copy the sample config** and fill in your details:
   ```sh
   cp config.sample.php config.php
   ```
   Set at least `client_id` (from the app you just made) and `username`.
   Every other setting has a sensible default; the comments in
   `config.sample.php` explain each one. `config.php` is gitignored.

3. **If your Trakt profile is private**, also set `client_secret` and sign
   in once (see "Private profiles" below). A public profile is fully
   readable with just the Client ID.

4. **Point a PHP-capable web server** at the project root, or run PHP's
   built-in server for local testing:
   ```sh
   php -S localhost:8000
   ```
   Then open `http://localhost:8000`.

5. **Schedule `cron.php`** (see below). Strictly optional, but it makes
   everything much faster.

On shared/Apache hosting, `.htaccess` and `.user.ini` are included to toggle
PHP error display between debug and production. See the comments in each
file.

## Plex "Now Watching" (optional)

Out of the box, "Now Watching" comes from Trakt, so it only shows something
while a scrobbler reports it live: Trakt's own Plex integration (needs Plex
Pass), or `plextraktsync watch` running in the background. Note that
`plextraktsync sync` only copies finished plays after the fact. Trakt also
drops an item as soon as it's paused.

You can also have the dashboard ask your Plex server directly, through
Plex's own API. Set `plex_token` in `config.php` and that's it. It then
shows your real playback position and stays up while paused. It falls back
to Trakt whenever nothing is playing on Plex or Plex can't be reached.

"Previously watched" (and "Last watched", when nothing's on) also draws on
Plex's own play history, which records an episode the moment you finish
it. Trakt's history can trail behind when a sync tool only pushes plays
across every so often. Plays from both are merged, newest first, and the
same play reported by both only appears once, so things you watch outside
Plex still show up.

The server is found automatically through your Plex account
(`plex.tv/api/v2/resources`, which lists your servers and the addresses
each one can be reached at). The dashboard tries those addresses in order
(local network, then remote, then Plex's relay), uses the first that
answers, remembers it for a day, and finds a new one if it stops working.
If your account can see more than one server, set `plex_server` to the one
you want by name. If you'd rather use a fixed address, set `plex_url`.

- Only the server owner's playback is shown, unless you set `plex_user` to
  someone else's Plex username. That way other people sharing your server
  don't appear on your dashboard.
- The token never reaches the browser. Posters and backdrops go through
  `plex_art.php`, which only accepts Plex library image paths and caches
  each image for a day.
- If the dashboard is hosted outside your home network, your Plex server
  needs Remote Access turned on, or failing that the relay is used, which
  works but is slower.

## Private profiles

Trakt's device-code sign-in gives you a short code to enter at
[trakt.tv/activate](https://trakt.tv/activate). The resulting token is
stored in `cache/trakt_token.php` and refreshed automatically before it
expires.

**From a shell** (preferred):

```sh
php auth.php          # sign in: shows a code, waits while you approve it
php auth.php status   # is a token stored?
php auth.php logout   # forget it
```

**From a browser** (for hosting without shell access): set `cron_secret` in
`config.php`, then visit `auth.php?token=YOUR_CRON_SECRET`. Browser sign-in
is refused until `cron_secret` is set. Otherwise anyone who found the page
could link their own Trakt account to your dashboard.

## Local history sync

Your full Trakt watch history is mirrored into a gzip-compressed file under
`cache/`. Each play stores its title, episode, runtime and exact timestamp,
plus per-title genres, year, and poster. Every panel and nearly every widget
is computed from that file, so it costs zero live API calls once synced.

- **Backfill:** each `cron.php` run pulls a bounded batch of older history
  (`library_backfill_pages_per_run`, 100 plays per page, default 20 pages),
  so even a very long history fills in over a few runs rather than one huge
  request. Until a period is covered, its panel says so rather than showing
  a misleadingly partial result. Whole-history widgets work on what's
  synced so far and say how far back that reaches.
- **New plays** since the last run are picked up cheaply on every run.
- **Rebuild:** Trakt lets you add plays with a past date or remove them,
  which a forward-only sync can't notice. So every `library_rebuild_days`
  (default 7), a fresh copy is quietly re-downloaded in the background and
  swapped in once complete. The current copy keeps serving meanwhile. Set it
  to `0` to disable.
- **Without cron:** each page load does a small slice of the same work
  instead, so the dashboard still fills in, just more slowly.

`cache/` holds your full watch history and (for private profiles) your Trakt
token, so it shouldn't be web-accessible. An `.htaccess` denying access is
created inside it automatically, which covers Apache. The token file is also
written so that requesting it over the web returns nothing even without that
rule. **On nginx or another web server, add an equivalent rule yourself**,
e.g.:

```nginx
location ^~ /cache/ { deny all; }
```

## Background sync & cache warming (recommended)

`cron.php` keeps the local history in sync (see above), fills in missing
posters from TMDB (if configured), and recomputes every widget and every
period of every panel, so visitors always land on an already-cached page.
Schedule it every 15 minutes:

```sh
# Real system cron (preferred if you have shell access):
0,15,30,45 * * * * php /full/path/to/box-office-of-one/cron.php >/dev/null 2>&1
```

On shared hosting without shell access, most control panels offer a
URL-based "cron job" feature instead:

```sh
0,15,30,45 * * * * curl -s "https://yourdomain.com/path/cron.php?token=YOUR_CRON_SECRET" >/dev/null
```

Once it's scheduled, set `'cron_enabled' => true` in `config.php`. That
shows a small footer note, and stops page loads doing their own sync work.
If you set `cron_secret`, it's required as a `?token=` query param for
HTTP-triggered runs (CLI runs are always allowed).

## Ratings

Each title in the hero card and the Top Shows / Top Movies lists shows its
scores as small chips:

- **Trakt** viewer rating: always shown, from Trakt's own data.
- **IMDb** rating and the **🍿 Rotten Tomatoes Popcornmeter** (audience
  score): shown once you add a free [MDBList](https://mdblist.com) API key
  (`mdblist_api_key`, from [your preferences](https://mdblist.com/preferences/)).
  Trakt carries neither score, and Rotten Tomatoes has no public API.

`cron.php` looks up ratings a batch at a time (`ratings_backfill_per_run`):
titles on the page first, then the rest, heaviest-watched first. It never
makes more than `mdblist_daily_limit` requests in 24 hours (default 900,
under MDBList's free 1,000/day). Titles not looked up yet just show fewer
chips until a later run fills them in.

Scores are stored on the server and never thrown away. Each is refreshed
once it's over a week old, but if that can't happen (the daily limit is
used up, MDBList is down, or it asks the app to slow down), the last known
scores keep being shown. A cron run gives up after 3 failed requests rather
than waiting on a timeout for every title. Trakt data that changes slowly
(your ratings, watchlist, profile, show details) and TMDB posters fall back
to their last saved copy the same way.

## Awards

Titles that have won (or been nominated for) a major award get a **🏆**
chip next to their scores, e.g. "🏆 6 wins". Hover over it for the full
list, or over the poster for a breakdown by ceremony with the categories
won. The data comes from [Wikidata](https://www.wikidata.org), matched on
each title's IMDb ID. It's free and needs no key.

Counted:

- **Film**: Academy Awards, Golden Globes, BAFTA Film Awards, SAG (now
  the Actor Awards), Critics' Choice, Independent Spirit Awards
- **Festivals**: Cannes, Venice, Berlin, Sundance, Toronto (TIFF)
- **International and guild**: César, Goya, European Film Awards, DGA,
  WGA, PGA, Annie Awards, Saturn Awards
- **TV**: Primetime and Creative Arts Emmys, BAFTA TV and BAFTA TV Craft,
  International Emmys, Peabody Awards, National Television Awards,
  TV Choice Awards, Royal Television Society Awards (plus the Globes,
  Critics' Choice and SAG TV categories above)

Awards given to a person for a title (Best Actress, say) count towards
that title. Wikidata's coverage is excellent for the Oscars, Globes, BAFTAs
and Emmys but patchier for festival sidebars and the UK TV awards, so a
few smaller wins may be missing. Wikidata only sometimes marks an Emmy
category as Creative Arts, so many of those are counted under Emmys.

`cron.php` looks up `awards_backfill_per_run` titles per run (default 60,
20 per request): titles on the page first, then the rest, heaviest-watched
first. Each is refreshed monthly. Set it to `0` to turn awards off.

## Where to stream

With `tmdb_api_key` set, the Now Watching card links to every streaming
service the title is available on in your country (and poster hover cards
list them under the release date), by subscription or
free with ads (not rent or buy). It uses TMDB's watch-provider data, which
comes from JustWatch. The country is `watch_region` (e.g. `'GB'`), or
your timezone's country if that's blank.

Services linked: Netflix, Prime Video, Disney+, HBO Max, Paramount+
(SkyShowtime in parts of Europe), Apple TV, Peacock, YouTube, Tubi, Pluto
TV, The Roku Channel, BBC iPlayer, ITVX, Channel 4, My5, NOW, Sky Go and
BritBox. Anything else TMDB lists is left out.

TMDB doesn't give a link straight to the title on each service, so a link
opens that service's search for the title instead (Netflix, Prime Video,
HBO Max, Apple TV, YouTube, Tubi, iPlayer, My5, NOW). For services whose
search can't be linked to, it opens TMDB's "where to watch" page for the
title, which links into each one. Availability is cached for a day.

### Streaming Changes

`cron.php` re-checks where your watchlist and history are streaming
(`streaming_backfill_per_run` titles per run, default 150, each at most
once a day, watchlist first) and logs every service a title gains or
loses. The Streaming Changes widget shows the last 30 days, flagging
anything on your watchlist. No free source says what's *about* to leave
a service, so departures show the day after they happen. A title only
starts being tracked from its first check, so give it a day or two after
setup.

## Soundtracks

A show's or film's soundtrack album comes from
[MusicBrainz](https://musicbrainz.org) (free, no key): an album typed as a
soundtrack, or titled like one, whose title starts with the show's or
film's ("Succession: Season 1", "Oppenheimer: Original Motion Picture
Soundtrack") and that came out no earlier than the year before the show
began. Films are matched more strictly, since short titles like "Up" or
"Parasite" start plenty of unrelated album names: the album has to come
out within a year of the film, and its title has to carry on with
punctuation (": Original Score"), not more words ("Up in the Air"). That
misses some (an album called "Barbie The Album", say) but avoids wrong
matches. The Now Watching card links to it as a
Spotify search, and the poster hover card names it. Looked up when first
needed and cached for a month.

## Posters

Posters and backdrops come from Trakt's own image data wherever it's
included. For any title without one, add a free
[TMDB API key](https://www.themoviedb.org/settings/api) (the v3 "API Key")
as `tmdb_api_key`. `cron.php` then fills in missing posters a batch at a
time (`poster_backfill_per_run`), heaviest-watched titles first. Without
either, art-less titles just get a letter placeholder.

## MCP server (optional)

`mcp.php` exposes your Trakt data as tools an MCP-compatible AI client can
call. It's read-only: nothing here can modify your Trakt account.

**Setup:** set `mcp_api_key` in `config.php` to a long random secret. It's
blank by default, which disables the endpoint entirely (every request
404s). Treat it like a password: anyone with it can read your full watch
history through this endpoint.

**Tools available:**

| Tool | What it returns |
|---|---|
| `get_now_watching` | What's playing right now (or last watched), plus the item before it |
| `list_history` | Individual plays with exact date/time, filterable by `since`/`until`/`type`/`limit` |
| `find_title` | Every play of titles matching some text, for "when did I last watch…?" or "how far into … am I?" |
| `top_shows` / `top_movies` | For a period (`all_time`/`this_year`/`this_month`/`this_week`/`today`): shows by episodes watched, movies by your rating then community score |
| `genre_breakdown` | Genre percentages for a period, weighted by time watched |
| `period_summary` | Plays, movies, episodes, shows and hours for a period |
| `lifetime_stats` | Trakt's lifetime totals |
| `widget_*` | One tool per insight widget (`widget_binge`, `widget_hot_takes`, `widget_watchlist`, etc.) |

History-based tools report `"available": false` along with the current sync
coverage when the local snapshot doesn't reach back far enough yet. They
never return a silently partial answer.

Implements the request/response subset of MCP's Streamable HTTP transport
(`initialize`, `tools/list`, `tools/call` over a single POST, replying with
plain JSON).

**Connecting a client:** every client needs this app's `mcp.php` URL, and
your `mcp_api_key` sent as `Authorization: Bearer <your mcp_api_key>`.

- **Claude app** (claude.ai web/Desktop): **Settings → Connectors → Add
  custom connector**, URL = your `mcp.php` address, Authentication = **No
  sign-in**, then under **Request headers** add `Authorization` with the
  value `Bearer YOUR_MCP_API_KEY`.
- **Claude Code:**
  ```sh
  claude mcp add --transport http box-office-of-one https://yourdomain.com/mcp.php \
    --header "Authorization: Bearer YOUR_MCP_API_KEY"
  ```
- **OpenAI Responses API:**
  ```json
  {
    "type": "mcp",
    "server_label": "trakt",
    "server_url": "https://yourdomain.com/mcp.php",
    "headers": { "Authorization": "Bearer YOUR_MCP_API_KEY" },
    "require_approval": "never"
  }
  ```

See [lastfm-dash's README](https://github.com/MichelleFindlay/lastfm-dash#mcp-server-optional)
for more detail on each client. The setup is identical.

## License

GPL-3.0. See [LICENSE](LICENSE).
