<?php

require_once __DIR__ . '/Http.php';

/**
 * Major film and TV award wins and nominations for each title, from
 * Wikidata (https://www.wikidata.org) — free, no API key, matched on the
 * IMDb ID Trakt already gives us. Trakt itself has no awards data.
 *
 * Wikidata records awards as "award received" / "nominated for" statements,
 * either on the film or show itself (Best Picture, Outstanding Drama
 * Series) or on a person with a "for work" qualifier pointing at it (Best
 * Actress) — both are collected. Only the ceremonies in CEREMONIES are
 * counted; the long tail of critics' circles and minor festivals is left
 * out. Coverage is as good as Wikidata's: excellent for the Oscars, Globes,
 * BAFTAs and Emmys, patchier for the smaller ones.
 *
 * Stored for every title in one file, cache/awards.json. cron.php fills in
 * missing titles first, then refreshes any over a month old, a batch per
 * Wikidata query. Page loads only ever read what's stored.
 */
class Awards
{
    private const ENDPOINT = 'https://query.wikidata.org/sparql';
    private const USER_AGENT = 'box-office-of-one/1.0 (https://github.com/MichelleFindlay/box-office-of-one)';
    private const TTL = 2592000; // 30 days — awards change a few times a year
    private const BATCH = 20; // titles per Wikidata query

    /**
     * Ceremonies counted, in display order: id => [name, pattern]. A
     * category belongs to the first ceremony whose pattern matches its own
     * Wikidata label or one of its parents' ("instance of" / "part of" /
     * "subclass of") — so more specific patterns (BAFTA TV Craft, Creative
     * Arts Emmys) come before the broader ones that would also match.
     */
    public const CEREMONIES = [
        // Film – major
        'oscars'         => ['Oscars', '/^Academy Awards?\b/'],
        'globes'         => ['Golden Globes', '/^Golden Globe/'],
        'bafta_tv_craft' => ['BAFTA TV Craft', '/British Academy Television Craft|BAFTA TV Craft/'],
        'bafta_tv'       => ['BAFTA TV', '/British Academy Television|BAFTA TV/'],
        'bafta'          => ['BAFTA', '/^BAFTA|British Academy Film/'],
        'sag'            => ['SAG Awards', '/Screen Actors Guild|^Actor Awards?\b/'],
        'critics'        => ["Critics' Choice", "/Critics['’] Choice|Broadcast Film Critics|Broadcast Television Journalists/"],
        'spirit'         => ['Spirit Awards', '/Independent Spirit/'],
        // Film – festivals
        'cannes'         => ['Cannes', "/Cannes Film Festival|Palme d['’]Or/"],
        'venice'         => ['Venice', '/Venice (International )?Film Festival|Golden Lion|Silver Lion|Volpi Cup/'],
        'berlin'         => ['Berlinale', '/Berlin International Film Festival|Berlinale|Golden Bear|Silver Bear/'],
        'sundance'       => ['Sundance', '/Sundance/'],
        'tiff'           => ['TIFF', '/Toronto International Film Festival|^TIFF\b/'],
        // Film – international and guild
        'cesar'          => ['César', '/^C[ée]sar Award/'],
        'goya'           => ['Goya', '/^Goya Award/'],
        'efa'            => ['European Film Awards', '/^European Film Award/'],
        'dga'            => ['DGA', '/Directors Guild of America/'],
        'wga'            => ['WGA', '/Writers Guild of America/'],
        'pga'            => ['PGA', '/Producers Guild of America/'],
        'annie'          => ['Annies', '/^Annie Award/'],
        'saturn'         => ['Saturn Awards', '/^Saturn Award/'],
        // TV
        'emmy_intl'      => ['International Emmys', '/International Emmy/'],
        'emmy_creative'  => ['Creative Arts Emmys', '/Creative Arts Emmy/'],
        'emmy'           => ['Emmys', '/Primetime Emmy/'],
        'peabody'        => ['Peabody', '/Peabody/'],
        'nta'            => ['NTAs', '/National Television Award/'],
        'tv_choice'      => ['TV Choice', '/^TV Choice Award/'],
        'rts'            => ['RTS', '/Royal Television Society|^RTS (Programme|Craft|Television)/'],
    ];

    private bool $enabled;
    private string $file;
    private ?array $store = null;

    public function __construct(array $config)
    {
        $this->enabled = (int) ($config['awards_backfill_per_run'] ?? 0) > 0;
        $this->file = __DIR__ . '/../cache/awards.json';
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array<string, array{at: int, c: array}> title key => stored awards
     */
    private function load(): array
    {
        if ($this->store === null) {
            $data = json_decode((string) @file_get_contents($this->file), true);
            $this->store = is_array($data) ? $data : [];
        }

        return $this->store;
    }

    /**
     * Whether awards have ever been looked up for this title (however long ago).
     */
    public function has(string $key): bool
    {
        return isset($this->load()[$key]);
    }

    public function isFresh(string $key): bool
    {
        return isset($this->load()[$key]) && (time() - (int) $this->load()[$key]['at']) < self::TTL;
    }

    /**
     * A title's awards, ceremonies in CEREMONIES order; empty if it has
     * none (or hasn't been looked up yet).
     *
     * @return array<int, array{id: string, name: string, wins: int, noms: int, won: string[]}>
     */
    public function lookup(?string $key): array
    {
        if (!$this->enabled || $key === null) {
            return [];
        }

        $result = [];
        foreach ((array) ($this->load()[$key]['c'] ?? []) as $id => $c) {
            if (isset(self::CEREMONIES[$id])) {
                $result[] = ['id' => $id, 'name' => self::CEREMONIES[$id][0], 'wins' => (int) $c[0], 'noms' => (int) $c[1], 'won' => (array) ($c[2] ?? [])];
            }
        }

        return $result;
    }

    /**
     * Looks up a batch of titles in a single Wikidata query and stores the
     * results. Titles without an IMDb ID are stored as having no awards.
     *
     * @param array<string, ?string> $titles title key => IMDb ID (tt...)
     * @return string "ok" (stored), "limit" (Wikidata said slow down —
     *                stop for now), or "error" (transient)
     */
    public function refresh(array $titles, int $timeout = 60): string
    {
        $byImdb = [];
        foreach ($titles as $key => $imdb) {
            if (is_string($imdb) && preg_match('/^tt\d+$/', $imdb)) {
                $byImdb[$imdb][] = $key;
            }
        }

        $found = [];
        if ($byImdb) {
            $response = Http::request('POST', self::ENDPOINT, [
                'Accept: application/sparql-results+json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ' . self::USER_AGENT,
            ], http_build_query(['query' => self::query(array_keys($byImdb))]), $timeout);

            if ($response !== null && ($response['status'] === 429 || $response['status'] === 503)) {
                return 'limit';
            }
            $data = $response !== null && $response['status'] === 200 ? json_decode($response['body'], true) : null;
            if (!is_array($data) || !isset($data['results']['bindings'])) {
                return 'error';
            }
            $found = self::parse($data['results']['bindings']);
        }

        $store = $this->load();
        foreach ($titles as $key => $imdb) {
            $store[$key] = ['at' => time(), 'c' => $found[$imdb] ?? new stdClass()];
        }
        $this->store = $store;

        $tmp = $this->file . '.tmp';
        if (@file_put_contents($tmp, json_encode($store)) !== false) {
            @rename($tmp, $this->file); // atomic, so a reader never sees a half-written file
        }

        return 'ok';
    }

    /**
     * Looks up one title straight away if it never has been — for what's
     * on screen right now (Now Watching), which shouldn't wait for
     * cron.php to reach it. Short timeout, and after a failure no more
     * tries for ten minutes, since this runs on page loads and polls.
     */
    public function lookupNow(string $key, string $imdb): void
    {
        $backoff = dirname($this->file) . '/awards_backoff';
        if (!$this->enabled || $this->has($key) || (time() - (int) @filemtime($backoff)) < 600) {
            return;
        }
        if ($this->refresh([$key => $imdb], 10) !== 'ok') {
            @touch($backoff);
        }
    }

    public static function batchSize(): int
    {
        return self::BATCH;
    }

    /**
     * Every award statement (won or nominated) on the titles themselves,
     * plus those on people "for work" on them, with each category's parent
     * classes so it can be matched to a ceremony.
     *
     * @param string[] $imdbIds
     */
    private static function query(array $imdbIds): string
    {
        $values = implode(' ', array_map(fn($id) => '"' . $id . '"', $imdbIds));

        return <<<SPARQL
SELECT ?imdb ?st ?kind ?award ?awardLabel ?parentLabel ?year WHERE {
  VALUES ?imdb { $values }
  ?item wdt:P345 ?imdb .
  {
    { ?item p:P166 ?st . ?st ps:P166 ?award . BIND("win" AS ?kind) }
    UNION { ?item p:P1411 ?st . ?st ps:P1411 ?award . BIND("nom" AS ?kind) }
  } UNION {
    { ?who p:P166 ?st . ?st ps:P166 ?award . BIND("win" AS ?kind) }
    UNION { ?who p:P1411 ?st . ?st ps:P1411 ?award . BIND("nom" AS ?kind) }
    ?st pq:P1686 ?item .
  }
  OPTIONAL { ?st pq:P585 ?t . BIND(YEAR(?t) AS ?year) }
  OPTIONAL { ?award wdt:P31|wdt:P361|wdt:P279 ?parent . }
  SERVICE wikibase:label { bd:serviceParam wikibase:language "en". }
}
SPARQL;
    }

    /**
     * Turns the query's rows (one per statement per parent class) into
     * per-ceremony counts for each IMDb ID: [wins, nominations, the
     * categories won]. A win counts as a nomination too.
     *
     * @return array<string, array<string, array{0: int, 1: int, 2: string[]}>>
     */
    private static function parse(array $bindings): array
    {
        // Collapse the rows back into one entry per statement.
        $statements = [];
        foreach ($bindings as $b) {
            $st = $b['st']['value'] ?? null;
            if ($st === null || !isset($b['imdb']['value'], $b['award']['value'])) {
                continue;
            }
            $statements[$st] ??= [
                'imdb'   => $b['imdb']['value'],
                'win'    => ($b['kind']['value'] ?? '') === 'win',
                'award'  => $b['award']['value'],
                'label'  => $b['awardLabel']['value'] ?? '',
                'year'   => $b['year']['value'] ?? '',
                'labels' => [],
            ];
            $statements[$st]['labels'][] = $b['parentLabel']['value'] ?? '';
        }

        // One award is often recorded several times over: on the film and
        // again on each person it went to (three producers for one Best
        // Picture). Count each category once per year, and a copy with no
        // year only if no dated copy of that category exists.
        $dated = [];
        foreach ($statements as $s) {
            if ($s['year'] !== '') {
                $dated[$s['imdb'] . '|' . $s['award']] = true;
            }
        }

        $wins = [];
        $noms = [];
        $won = [];
        foreach ($statements as $s) {
            if ($s['year'] === '' && isset($dated[$s['imdb'] . '|' . $s['award']])) {
                continue;
            }
            $ceremony = self::classify(array_merge([$s['label']], $s['labels']));
            if ($ceremony === null) {
                continue;
            }
            $id = $s['award'] . '|' . $s['year'];
            $noms[$s['imdb']][$ceremony][$id] = true;
            if ($s['win']) {
                $wins[$s['imdb']][$ceremony][$id] = true;
                $category = self::shortCategory($s['label']);
                if ($category !== null) {
                    $won[$s['imdb']][$ceremony][$category] = true;
                }
            }
        }

        $result = [];
        foreach ($noms as $imdb => $ceremonies) {
            foreach (array_keys(self::CEREMONIES) as $ceremony) {
                if (isset($ceremonies[$ceremony])) {
                    $result[$imdb][$ceremony] = [
                        count($wins[$imdb][$ceremony] ?? []),
                        count($ceremonies[$ceremony]),
                        array_keys($won[$imdb][$ceremony] ?? []),
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * @param string[] $labels the category's own label, then its parents'
     */
    private static function classify(array $labels): ?string
    {
        foreach (self::CEREMONIES as $id => [, $pattern]) {
            foreach ($labels as $label) {
                if ($label !== '' && preg_match($pattern . 'u', $label)) {
                    return $id;
                }
            }
        }

        return null;
    }

    /**
     * "Academy Award for Best Picture" → "Best Picture"; names that are
     * the award itself ("Palme d'Or", "Golden Lion") are kept whole. Null
     * for just the ceremony's name ("Peabody Awards"), which says nothing.
     */
    private static function shortCategory(string $label): ?string
    {
        if ($label === '' || preg_match('/^\S+( \S+)? Awards?$/u', $label) || preg_match('/^Q\d+$/', $label)) {
            return null;
        }

        return preg_replace('/^.*?\b(Awards?|Emmys?|Prize)\s+for\s+/u', '', $label) ?? $label;
    }

    /**
     * Summary chip for a title: wins across every ceremony (or
     * nominations, if it hasn't won anything), with the full breakdown
     * as its tooltip. Null if it has neither.
     */
    public static function chip(array $awards): ?array
    {
        if (!$awards) {
            return null;
        }

        $wins = array_sum(array_column($awards, 'wins'));
        $noms = array_sum(array_column($awards, 'noms'));
        $value = $wins > 0
            ? $wins . ($wins === 1 ? ' win' : ' wins')
            : $noms . ($noms === 1 ? ' nom' : ' noms');

        return [
            'kind'  => 'awards' . ($wins > 0 ? '' : ' rating-noms'),
            'label' => '🏆',
            'value' => $value,
            'title' => implode("\n", array_map([self::class, 'describe'], $awards)),
        ];
    }

    /**
     * Hover card line: "Won 4 of 6 — Best Picture, Best Director, …" or
     * "2 nominations".
     */
    public static function detail(array $a): string
    {
        if ($a['wins'] === 0) {
            return $a['noms'] . ($a['noms'] === 1 ? ' nomination' : ' nominations');
        }

        $won = array_slice($a['won'], 0, 3);
        $more = count($a['won']) > 3 ? ', …' : '';

        return 'Won ' . $a['wins'] . ' of ' . max($a['wins'], $a['noms']) . ($won ? ' — ' . implode(', ', $won) . $more : '');
    }

    /**
     * "Oscars: 4 wins from 6 nominations"
     */
    public static function describe(array $a): string
    {
        $noms = $a['noms'] . ($a['noms'] === 1 ? ' nomination' : ' nominations');

        return $a['name'] . ': ' . ($a['wins'] > 0
            ? $a['wins'] . ($a['wins'] === 1 ? ' win' : ' wins') . ' from ' . $noms
            : $noms);
    }
}
