<?php

require_once __DIR__ . '/Http.php';

/**
 * A show's or film's soundtrack album, if it has one, from MusicBrainz
 * (https://musicbrainz.org) — free, no key. Searched by title among
 * release groups typed "Soundtrack" or titled like one ("... Soundtrack",
 * "Music from ...", "... Score"), and only accepted when the album's
 * title starts with the title's own ("Succession: Season 1", "Line of
 * Duty (Music from the Original Series)") and it came out no earlier than
 * the year before the show began, so a same-named 1992 album doesn't
 * count. Albums typed "Soundtrack" beat ones only titled like one, then
 * the earliest wins — usually season one's.
 *
 * Films are matched more strictly, since short titles ("Up", "Parasite")
 * prefix plenty of unrelated albums: the title has to be followed by
 * punctuation (": Original Motion Picture Soundtrack", " (Score)"), not
 * just more words ("Up in the Air"), and the album has to come out within
 * a year of the film. English "Original Motion Picture Soundtrack"-style
 * titles are preferred, so a dubbed edition (Coco's Danish one) doesn't
 * win just by being listed first.
 *
 * Linked as a Spotify search for that album (MusicBrainz only says it
 * exists, not where it streams). Cached a month per show, including "no
 * soundtrack"; after a failed request (MusicBrainz turns away more than
 * one a second) none are made for two minutes, since lookups happen on
 * page loads and hovers.
 */
class Soundtracks
{
    private const ENDPOINT = 'https://musicbrainz.org/ws/2/release-group';
    private const USER_AGENT = 'box-office-of-one/1.0 (https://github.com/MichelleFindlay/box-office-of-one)';
    private const TTL = 2592000; // 30 days

    private string $cacheDir;

    public function __construct()
    {
        $this->cacheDir = __DIR__ . '/../cache';
    }

    /**
     * @param string $key title key, 's<trakt id>' for a show or 'm<trakt id>' for a film
     * @param ?int $year the year the show began or the film came out, if known
     * @param bool $cacheOnly don't ask MusicBrainz, just return what's stored
     * @return array{title: string, artist: string, url: string}|null
     */
    public function lookup(string $key, string $title, ?int $year, bool $cacheOnly = false): ?array
    {
        if (!preg_match('/^[ms]\d+$/', $key) || trim($title) === '') {
            return null;
        }

        $file = $this->cacheDir . '/soundtrack_' . $key . '.json';
        $cached = json_decode((string) @file_get_contents($file), true);
        $fresh = is_array($cached) && (time() - (int) @filemtime($file)) < self::TTL;

        $backoff = $this->cacheDir . '/soundtrack_backoff';
        if (!$fresh && !$cacheOnly && (time() - (int) @filemtime($backoff)) >= 120) {
            $found = $this->search($title, $year, $key[0] === 'm');
            if ($found === false) {
                @touch($backoff);
            } else {
                $cached = ['album' => $found];
                @file_put_contents($file, json_encode($cached));
            }
        }

        return is_array($cached['album'] ?? null) ? $cached['album'] : null;
    }

    /**
     * @return array|null|false the album, null if there's none, false if MusicBrainz couldn't be asked
     */
    private function search(string $title, ?int $year, bool $film)
    {
        // Typed "Soundtrack", or (as plenty aren't) titled like one.
        $query = 'releasegroup:"' . str_replace('"', '', $title) . '" AND (secondarytype:soundtrack'
            . ' OR releasegroup:soundtrack OR releasegroup:score OR releasegroup:"music from")';
        $url = self::ENDPOINT . '?' . http_build_query(['query' => $query, 'fmt' => 'json', 'limit' => $film ? 50 : 25]);
        $headers = ['Accept: application/json', 'User-Agent: ' . self::USER_AGENT];
        $response = Http::request('GET', $url, $headers, null, 8);
        if ($response !== null && $response['status'] === 503) {
            usleep(1500000); // turned away for going over one a second — one more try
            $response = Http::request('GET', $url, $headers, null, 8);
        }
        $data = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;
        if (!is_array($data) || !isset($data['release-groups'])) {
            return false;
        }

        $name = self::normalize($title);
        $best = null;
        foreach ((array) $data['release-groups'] as $rg) {
            $album = (string) ($rg['title'] ?? '');
            $released = (string) ($rg['first-release-date'] ?? '');
            $normalized = self::normalize($album);
            $typed = in_array('Soundtrack', (array) ($rg['secondary-types'] ?? []), true);
            if (!$typed && !preg_match('/soundtrack|\bscore\b|music from|\bost\b/i', $album)) {
                continue;
            }

            // The title, then nothing, or a separator: for a show also a
            // space or number (": Season 1", ", Volume One", " 2"), for a
            // film only punctuation (": Original Score", " (Soundtrack)").
            $rest = substr($normalized, strlen($name));
            if (strpos($normalized, $name) !== 0 || !preg_match($film ? '/^($|\s*[:,(\-–—\[])/u' : '/^($|[\s:,(\-–—\[]|\s*\d)/u', $rest)) {
                continue;
            }
            $releasedYear = $released !== '' ? (int) substr($released, 0, 4) : null;
            if ($film ? ($year !== null && ($releasedYear === null || abs($releasedYear - $year) > 1))
                      : ($year !== null && $releasedYear !== null && $releasedYear < $year - 1)) {
                continue;
            }
            // Prefer albums MusicBrainz types as soundtracks (title-only
            // matches are more often tributes), for a film then an English
            // "Original Motion Picture Soundtrack"-style title, then the earliest.
            $english = $film && preg_match('/original motion picture|music from the motion picture|original (score|soundtrack)/i', $album);
            $rank = [$typed ? 0 : 1, $english ? 0 : 1, $released !== '' ? $released : '9999'];
            if ($best === null || $rank < $best['rank']) {
                $artist = implode('', array_map(fn($a) => ($a['name'] ?? '') . ($a['joinphrase'] ?? ''), (array) ($rg['artist-credit'] ?? [])));
                $best = ['title' => $album, 'artist' => $artist, 'rank' => $rank];
            }
        }

        if ($best === null) {
            return null;
        }

        $search = trim($best['title'] . ' ' . ($best['artist'] !== 'Various Artists' ? $best['artist'] : ''));

        return [
            'title'  => $best['title'],
            'artist' => $best['artist'],
            'url'    => 'https://open.spotify.com/search/' . rawurlencode($search),
        ];
    }

    private static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(['’', '‘', '&'], ["'", "'", 'and'], $s);

        return preg_replace('/^the\s+/u', '', $s) ?? $s;
    }
}
