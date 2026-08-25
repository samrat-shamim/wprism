<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * The refusal type for `duo census`, shaped exactly like MergeCheckRefusal
 * (cli/src/Refresh/MergeCheck.php:16-26) so the two env-free verbs publish one
 * refusal vocabulary rather than two.
 */
final class FleetCensusRefusal extends \RuntimeException {
    public function __construct(
        public string $reasonCode,
        string $message,
        public string $remediation,
        public ?string $diagnostic = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * `duo census` — the fleet census: demand rank, coverage ratio, adoption funnel.
 *
 * ## What was missing
 *
 * The basis for choosing the next adapter to build was nothing. A single
 * assessment already names what one site cannot version — `Coverage` groups
 * the genuinely-invisible option rows by prefix with a probable owner and
 * counts every undeclared custom table beside its own owner guess
 * (agent/src/Review/Coverage.php:336-364, :296-317) — and `duo assess` carries
 * that block through verbatim inside `duo-assess-inventory/v1`. What did not
 * exist was the fold: N of those documents put side by side so the answer to
 * "which plugin costs the most sites the most surface" is a number rather than
 * an opinion, and so the three-step adoption story — an adapter EXISTS, it
 * COVERS your plugin, your site PINS it — is countable at each step.
 *
 * ## Host-side, and structurally so
 *
 * No WordPress, no database, no environment, no transport. The only inputs are
 * documents an operator already produces locally and a manifest library on
 * this machine, which is why this sits beside `manifest-validate`,
 * `adapter-draft` and `merge-check` in cli/duo's env-free block rather than in
 * the environment-bound vocabulary.
 *
 * ## Redaction is a projection, not a promise
 *
 * `Coverage`'s discipline is names and counts only, stated in as many words at
 * agent/src/Review/Coverage.php:24-36, and `AssessInventory` inherits it
 * (agent/src/Assess/AssessInventory.php:32-42) with one deliberate exception:
 * `pending.rows`, which carries the review queue's content-shaped `ref_hint`.
 * A census that folded whole inventories would carry that exception into an
 * aggregate nobody scoped it for, and would carry `target.home` /
 * `target.siteurl` — the site's identity in plain text — into a document whose
 * whole purpose is to be pooled across operators.
 *
 * So `submission()` below is a WHITELIST projection: it reads exactly
 * `target.site_mode`, the `plugins[]` basenames and their active flag, the
 * pinned manifest NAMES, and the two `coverage` blocks. Every other key of the
 * inventory — including `target.home`, `target.siteurl` and the entire
 * `pending` block — is never read at all, so no later edit to the document
 * builder can leak one by accident. The only site identifier that survives is
 * the caller's own label, and `assert_label()` refuses anything that could be
 * a hostname: the grammar admits no dot, no slash, no colon and no `@`.
 *
 * ## Coverage credit is declared AND reviewed
 *
 * A residual surface is credited to an adapter only when it appears in that
 * adapter's DERIVED surfaces —
 * `ManifestDispositions::claim_from_disposition()`'s `surfaces` list
 * (agent/src/Policy/ManifestDispositions.php:196-219), which is the section
 * expansion of the reviewed disposition over the manifest's own keys. Two
 * failure modes fall out of that and both are intended:
 *
 *   - a manifest that DECLARES `options` while no reviewed disposition names
 *     the section earns zero, because the expansion never runs;
 *   - a disposition that NAMES a section whose manifest map does not hold the
 *     residual key earns zero for that key, because only the bare section
 *     string is minted (`:199-206` expands keys only for a non-list map).
 *
 * The same rule makes this census structurally conservative about pattern
 * declarations: `option_patterns` is a LIST, so `claim_from_disposition()`
 * mints no per-key surface for it and a pattern-covered option group is
 * counted as demand. Under-crediting an adapter overstates the work left;
 * over-crediting it would hide a gap, and only one of those two errors is safe
 * in a document whose job is to pick what to build next.
 *
 * ## The denominator is disclosed, never narrowed silently
 *
 * `manifests/capabilities/platform.json` claims `site_mode: single-site`, and
 * `ManifestDispositions::platform_boundary()` is where that value comes from
 * here rather than a literal. A multisite submission is therefore outside
 * every claim this library makes and cannot be scored against it — but a
 * denominator that quietly dropped it would let participation look better than
 * it is. `population.excluded[]` names the excluded population, its size and
 * its reason beside the eligible count every ratio divides by.
 *
 * ## And the sample is labelled
 *
 * Participation is opt-in, so a census over two documents is a census over two
 * documents. `basis.sample_class` says which of `one-site`, `narrow` or
 * `fleet` this run is, from the eligible count alone, and `basis.caveat`
 * carries the sentence a reader needs before quoting a rank derived from it.
 *
 * ## Fleet health rides here because this is where the operator already looks
 *
 * `duo adapter proposals` derives, per adapter, the newest release that probed
 * green (`last_verified`, the shape `manifests/capabilities/platform.json`
 * already uses per axis) and how many recorded releases are newer than it. That
 * fact deliberately lives OUTSIDE `manifests/`: stored beside a manifest it
 * would be a rule-2 identity input, and every re-verification would move an
 * `adapter_digest` and every `site.duo.json` content pin in the fleet.
 *
 * So it arrives here as a document, and `fleet_health` joins it to what this
 * census already knows — how many sites install and pin each adapter — because
 * "this adapter is four releases behind" and "eleven of your sites pin it" are
 * one decision, and the operator reads the census, not a second report. The
 * join is on the ADAPTER NAME, the same key `demand[].covering_adapter`
 * carries.
 *
 * `health_row()` is a WHITELIST projection for exactly the reason
 * `submission()` is: the health document is produced by a different verb whose
 * future fields nobody scoped for a pooled document, so this census reads names
 * and counts out of it and nothing else. A `--health` document carrying a site
 * URL contributes no key at all.
 */
final class FleetCensus {
    public const FORMAT = 'duo-fleet-census/v1';

    /** The one document this verb consumes (agent/src/Assess/AssessInventory.php:68). */
    public const INVENTORY_FORMAT = 'duo-assess-inventory/v1';

    /**
     * The optional second input: the derived adapter-freshness document
     * `duo adapter proposals` emits (cli/src/Adapter/AdapterProposals.php).
     * Optional because the census answers its own questions without it, and a
     * verb that refused without a document produced by a different verb would
     * make the demand rank hostage to a ledger nobody has recorded yet.
     */
    public const HEALTH_FORMAT = 'duo-adapter-boundary-proposals/v1';

    /**
     * The freshness vocabulary, restated so the census's own reader is closed
     * over it: a class this list does not name is dropped rather than ranked,
     * because a health document from a newer build must not smuggle an
     * uninterpreted verdict into a pooled document.
     */
    public const FRESHNESS_CLASSES = ['unrecorded', 'unverified', 'behind', 'current'];

    /**
     * The exit-code contract, identical to the env-free verb family's
     * (MergeCheckCommand.php:19-24): 0 an answer, 1 a refusal, 2 a usage
     * error. There is deliberately no "answer with a caveat" code — a narrow
     * sample is `basis.sample_class`, a fact inside the document, not a
     * different outcome for a CI job to branch on.
     */
    public const EXIT_OK = 0;
    public const EXIT_REFUSED = 1;
    public const EXIT_USAGE = 2;

    /**
     * The caller's site label. Opaque BY GRAMMAR: no dot, no slash, no colon,
     * no `@`, so a label cannot be a hostname, a URL or a path even by
     * accident — which is what makes "no site identifier beyond an opaque
     * caller label" a property of this file rather than of the caller's care.
     */
    public const LABEL_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,31}$/D';

    /**
     * The closed adoption vocabulary. The two rows an operator most needs to
     * tell apart are `no_adapter` (nobody has built one; this is demand) and
     * `adapter_unpinned` (one exists and covers you; this is adoption) — the
     * whole reason the funnel is three steps and not one ratio.
     */
    public const FUNNEL_STAGES = [
        'no_adapter',
        'adapter_unreviewed',
        'adapter_unpinned',
        'adapter_pinned',
    ];

    /** Above this many eligible submissions the sample stops being `narrow`. */
    public const SAMPLE_NARROW_MAX = 5;

    /**
     * Every ratio is published as parts per million, an INTEGER. A float in a
     * canonical document is a formatting decision (`Canon::encode()` hands the
     * value straight to `json_encode`), and two hosts printing `0.6666667` and
     * `0.66666667` would give one census two content addresses.
     */
    private const PPM = 1000000;

    /**
     * One census over already-read documents. Split from `run()` so the whole
     * fold is provable with no filesystem at all: the aggregation, the rank,
     * the redaction and the denominator are the subject, and a suite that had
     * to write files to reach them would be testing `file_get_contents`.
     *
     * @param list<array{label:string,inventory:array<string,mixed>}> $submissions
     * @param array{adapters:array<string,array<string,mixed>>,reviewed:int,sha256:string,site_mode:string} $library
     * @param array<string,mixed> $health a `duo-adapter-boundary-proposals/v1` document, or `[]`
     * @return array<string,mixed> a `duo-fleet-census/v1` document
     */
    public static function project(array $submissions, array $library, array $health = []): array {
        $eligible = [];
        $excluded = [];
        $seenLabels = [];
        foreach ($submissions as $submission) {
            $label = (string) ($submission['label'] ?? '');
            self::assert_label($label);
            if (isset($seenLabels[$label])) {
                throw new FleetCensusRefusal(
                    'census_duplicate_label',
                    "two submissions carry the site label '$label'",
                    'give every submission its own opaque label and rerun census'
                );
            }
            $seenLabels[$label] = true;
            $row = self::submission($label, (array) ($submission['inventory'] ?? []));
            if ($row['site_mode'] !== $library['site_mode']) {
                $excluded[$row['site_mode']] = ($excluded[$row['site_mode']] ?? 0) + 1;
                continue;
            }
            $eligible[] = $row;
        }
        // Input order must not reach the output: the rank is a function of the
        // submitted SET, and an operator who globbed their inventories in a
        // different order must read the same document.
        usort($eligible, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
        ksort($excluded, SORT_STRING);

        $sites = [];
        foreach ($eligible as $row) {
            $sites[] = self::site_row($row);
        }
        [$demand, $unattributed] = self::demand_rows($eligible, $library);

        return [
            'format' => self::FORMAT,
            'spec_version' => defined('DUO_SPEC_VERSION') ? (int) DUO_SPEC_VERSION : 0,
            'agent_version' => defined('DUO_AGENT_VERSION') ? (string) DUO_AGENT_VERSION : 'unknown',
            'library' => [
                'adapters' => count($library['adapters']),
                'reviewed' => (int) $library['reviewed'],
                // The content address of the coverage ORACLE, not of the
                // library on disk: two hosts crediting the same surfaces must
                // agree here, and a manifest edit that moves no derived
                // surface moves no census verdict either.
                'surfaces_sha256' => (string) $library['sha256'],
                'site_mode' => (string) $library['site_mode'],
            ],
            'population' => self::population(count($submissions), count($eligible), $excluded, $library),
            'basis' => self::basis(count($eligible)),
            'sites' => $sites,
            'fleet' => self::fleet($sites),
            'demand' => $demand,
            'unattributed' => $unattributed,
            'funnel' => self::funnel($demand),
            'fleet_health' => self::fleet_health($health, $demand),
        ];
    }

    /**
     * The fleet-health rows: derived adapter freshness, ranked by the exposure
     * this fleet actually has to it.
     *
     * The rank is `sites_pinning x releases_behind`, and both halves are
     * load-bearing. An adapter nobody pins is a backlog item; one eleven sites
     * pin whose proof is four releases old is the next upstream release away
     * from eleven blocked deploys, which is the whole reason
     * `AdapterContractGrammar.php:73-79` forbids an unbounded range. An adapter
     * with a recorded ledger and no green probe at all ranks by
     * `sites_pinning` alone, because `releases_behind` is 0 for it and zero
     * would sort a wholly unproven adapter to the bottom.
     *
     * @param array<string,mixed> $health
     * @param list<array<string,mixed>> $demand
     * @return array<string,mixed>
     */
    private static function fleet_health(array $health, array $demand): array {
        $exposure = [];
        foreach ($demand as $row) {
            $adapter = $row['covering_adapter'] ?? null;
            if (!is_string($adapter) || $adapter === '') {
                continue;
            }
            $exposure[$adapter] ??= ['sites_installed' => 0, 'sites_pinning' => 0];
            $exposure[$adapter]['sites_installed'] += (int) $row['sites_installed'];
            $exposure[$adapter]['sites_pinning'] += (int) $row['sites_pinning'];
        }

        $block = [
            'source' => 'not-supplied',
            'adapters' => 0,
            'stale' => 0,
            'open_proposals' => 0,
            'rows' => [],
            // The count is the signal (GapActions.php:152-156): a census with
            // no health document says so, rather than leaving a reader to read
            // an absent key as "nothing is stale".
            'disclosure' => 'no --health document was supplied, so no adapter freshness is known here; '
                . 'derive one with `duo adapter proposals --format=json`',
        ];
        if ($health === []) {
            return $block;
        }
        if (($health['format'] ?? null) !== self::HEALTH_FORMAT) {
            throw new FleetCensusRefusal(
                'census_health_unsupported',
                'the --health document is not a ' . self::HEALTH_FORMAT . ' document',
                'derive one with `duo adapter proposals --format=json`, then pass it to --health'
            );
        }

        $open = [];
        foreach ((array) ($health['proposals'] ?? []) as $proposal) {
            if (!is_array($proposal) || !is_string($proposal['adapter'] ?? null)) {
                continue;
            }
            $range = is_array($proposal['proposed_range'] ?? null) ? $proposal['proposed_range'] : [];
            $open[$proposal['adapter']] = [
                'min' => is_string($range['min'] ?? null) ? (string) $range['min'] : null,
                'max' => is_string($range['max'] ?? null) ? (string) $range['max'] : null,
            ];
        }

        $rows = [];
        $stale = 0;
        foreach ((array) ($health['freshness'] ?? []) as $row) {
            $projected = self::health_row(is_array($row) ? $row : [], $exposure, $open);
            if ($projected === null) {
                continue;
            }
            if ($projected['stale'] === true) {
                $stale++;
            }
            $rows[] = $projected;
        }
        usort($rows, static function (array $a, array $b): int {
            return $b['exposure_score'] <=> $a['exposure_score']
                ?: $b['releases_behind'] <=> $a['releases_behind']
                ?: $b['sites_pinning'] <=> $a['sites_pinning']
                ?: strcmp($a['adapter'], $b['adapter']);
        });

        $block['source'] = self::HEALTH_FORMAT;
        $block['adapters'] = count($rows);
        $block['stale'] = $stale;
        $block['open_proposals'] = count($open);
        $block['rows'] = $rows;
        $block['disclosure'] = 'freshness is DERIVED from recorded probe outcomes and names the newest release '
            . 'that probed green; an upstream release nobody recorded is invisible to it';
        return $block;
    }

    /**
     * One health row, whitelisted. Every field this census can ever read out of
     * the health document is named here — the same posture `submission()`
     * takes, and for the same reason: the producing verb's future fields were
     * never scoped for a document meant to be pooled across operators.
     *
     * @param array<string,mixed> $row
     * @param array<string,array{sites_installed:int,sites_pinning:int}> $exposure
     * @param array<string,array{min:?string,max:?string}> $open
     * @return array<string,mixed>|null
     */
    private static function health_row(array $row, array $exposure, array $open): ?array {
        $adapter = $row['adapter'] ?? null;
        $class = $row['freshness_class'] ?? null;
        if (!is_string($adapter) || $adapter === ''
            || !is_string($class) || !in_array($class, self::FRESHNESS_CLASSES, true)) {
            return null;
        }
        $sites = $exposure[$adapter] ?? ['sites_installed' => 0, 'sites_pinning' => 0];
        $behind = is_int($row['releases_behind'] ?? null) ? (int) $row['releases_behind'] : 0;
        return [
            'adapter' => $adapter,
            'freshness_class' => $class,
            'last_verified' => is_string($row['last_verified'] ?? null) ? (string) $row['last_verified'] : null,
            'newest_recorded_release' => is_string($row['newest_recorded_release'] ?? null)
                ? (string) $row['newest_recorded_release']
                : null,
            'releases_behind' => $behind,
            'unprobed_newer' => is_int($row['unprobed_newer'] ?? null) ? (int) $row['unprobed_newer'] : 0,
            'probes_recorded' => is_int($row['probes_recorded'] ?? null) ? (int) $row['probes_recorded'] : 0,
            'stale' => ($row['stale'] ?? null) === true,
            'sites_installed' => $sites['sites_installed'],
            'sites_pinning' => $sites['sites_pinning'],
            'open_proposal' => isset($open[$adapter]) ? $open[$adapter] : null,
            'exposure_score' => $sites['sites_pinning'] * $behind,
        ];
    }

    /**
     * Read one census over inventory files named on the command line.
     *
     * @param array{sites:array<string,string>,manifests:string,health:?string} $options
     * @return array<string,mixed>
     */
    public static function run(array $options): array {
        self::boot();
        $library = self::library((string) $options['manifests']);
        $submissions = [];
        foreach ($options['sites'] as $label => $path) {
            // Before the read, not after: a `--dir` collection labelled by
            // filename can carry a hostname in its stem, and that refusal must
            // arrive without this process having opened the document behind it.
            self::assert_label((string) $label);
            $submissions[] = ['label' => (string) $label, 'inventory' => self::read_inventory((string) $path)];
        }
        if ($submissions === []) {
            throw new FleetCensusRefusal(
                'census_no_submissions',
                'census was given no inventory documents to fold',
                'pass at least one --site=<label>=<inventory.json> or a --dir=<directory> holding them'
            );
        }
        $health = ($options['health'] ?? null) === null ? [] : self::read_health((string) $options['health']);
        return self::project($submissions, $library, $health);
    }

    /**
     * The cohort re-baseline: read the baseline census, obtain the current
     * one, and hand both to `CohortRebaseline::project()`.
     *
     * The current side comes from ONE of two places and never from both. A
     * `--current=<census.json>` compares two documents that were each measured
     * against the manifest library of their own day — the only way to do that,
     * because a checkout holds one library and a past one cannot be re-derived
     * from it. Without it the current side is measured HERE, from the same
     * submissions and library an ordinary `duo census` would fold, so the
     * re-measurement half of WP-6.3's exit criterion is one command rather
     * than two and a diff.
     *
     * Neither form is the one that "works across a flag day": a kept baseline
     * document is readable whichever engine wrote it — `comparability.engine`
     * records the move rather than refusing it — and
     * sandbox/tests/offline/cli/regress_cohort_rebaseline.php case 5 spans the
     * spec 2 -> 3 flip with the `--dir` form. What `--current` buys is the
     * LIBRARY on the current side, not the engine.
     *
     * @param array{sites:array<string,string>,manifests:string,health:?string,baseline:string,current:?string} $options
     * @return array<string,mixed> a `duo-cohort-rebaseline/v1` document
     */
    public static function rebaseline(array $options): array {
        self::boot();
        require_once __DIR__ . '/CohortRebaseline.php';
        $baseline = self::read_census((string) $options['baseline'], 'baseline');
        $current = ($options['current'] ?? null) === null
            ? self::run($options)
            : self::read_census((string) $options['current'], 'current');
        return CohortRebaseline::project($baseline, $current);
    }

    /**
     * One census document named on the command line. Read exactly as an
     * inventory is — same three failure modes — but with its own reason code,
     * because "your baseline is missing" and "one of forty submissions is
     * missing" are different problems for the operator holding them.
     *
     * @return array<string,mixed>
     */
    private static function read_census(string $path, string $side): array {
        if (!is_file($path)) {
            throw new FleetCensusRefusal(
                'rebaseline_document_unreadable',
                "the --$side census document does not exist",
                'produce it with `duo census --format=json > <path>`, then name that path',
                $path
            );
        }
        try {
            $decoded = \Duo\Canon::decode((string) file_get_contents($path));
        } catch (\Throwable $t) {
            throw new FleetCensusRefusal(
                'rebaseline_document_unreadable',
                "the --$side census document is not valid JSON",
                'reproduce it with `duo census --format=json` and pass the output unmodified',
                $path . ': ' . $t->getMessage(),
                $t
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FleetCensusRefusal(
                'rebaseline_document_unreadable',
                "the --$side census document does not decode to an object",
                'reproduce it with `duo census --format=json` and pass the output unmodified',
                $path
            );
        }
        return $decoded;
    }

    /**
     * The derived freshness document, read exactly as an inventory is: it is
     * another document produced elsewhere on this machine, and the failure
     * modes are the same three.
     *
     * @return array<string,mixed>
     */
    private static function read_health(string $path): array {
        if (!is_file($path)) {
            throw new FleetCensusRefusal(
                'census_health_unreadable',
                'the --health document does not exist',
                'derive one with `duo adapter proposals --format=json > <path>`, then pass it to --health',
                $path
            );
        }
        try {
            $decoded = \Duo\Canon::decode((string) file_get_contents($path));
        } catch (\Throwable $t) {
            throw new FleetCensusRefusal(
                'census_health_unreadable',
                'the --health document is not valid JSON',
                'rederive it with `duo adapter proposals --format=json` and pass the output unmodified',
                $path . ': ' . $t->getMessage(),
                $t
            );
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new FleetCensusRefusal(
                'census_health_unreadable',
                'the --health document does not decode to an object',
                'rederive it with `duo adapter proposals --format=json` and pass the output unmodified',
                $path
            );
        }
        return $decoded;
    }

    /**
     * The adapter oracle: for every manifest in the library, the plugin slug it
     * claims and the surfaces its REVIEWED disposition derives.
     *
     * `ManifestDispositions::load()` runs first and is allowed to refuse. That
     * is deliberate and it is the posture: the coverage half of this census is
     * only as good as the review document behind it, and a library whose
     * dispositions do not cover its manifests one-for-one cannot answer
     * "reviewed" for anything. Degrading to "no claim known" would publish a
     * demand rank inflated by the library's own defect.
     *
     * @return array{adapters:array<string,array<string,mixed>>,reviewed:int,sha256:string,site_mode:string}
     */
    public static function library(string $dir): array {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw new FleetCensusRefusal(
                'census_library_unreadable',
                'the manifest library directory does not exist',
                'pass --manifests=<dir> naming a directory holding the manifests and their dispositions document'
            );
        }
        try {
            $platform = \Duo\ManifestDispositions::platform_boundary($dir);
            $dispositions = \Duo\ManifestDispositions::load($dir);
        } catch (\Throwable $t) {
            throw new FleetCensusRefusal(
                'census_library_unreadable',
                'the manifest library could not be read as a reviewed adapter library',
                'repair the library so `duo manifest-validate` is green, then rerun census',
                $t->getMessage(),
                $t
            );
        }
        if ($dispositions === null) {
            throw new FleetCensusRefusal(
                'census_library_unreviewed',
                'this manifest library ships no dispositions document, so no adapter in it carries a reviewed claim',
                'point --manifests at a library carrying a dispositions/ directory, or review the adapters in this one'
            );
        }
        $siteMode = (string) ($platform['site_mode'] ?? '');
        if ($siteMode === '') {
            throw new FleetCensusRefusal(
                'census_library_unreadable',
                'the platform boundary names no site_mode, so no submission can be judged eligible',
                'repair capabilities/platform.json so it declares site_mode, then rerun census'
            );
        }

        $adapters = [];
        $reviewed = 0;
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $name = basename($file, '.json');
            if ($name === 'dispositions') {
                continue;
            }
            $manifest = \Duo\Canon::decode(\Duo\Canon::read_file($file));
            if (!is_array($manifest)) {
                continue;
            }
            $entry = $dispositions->entry($name);
            $surfaces = [];
            if (is_array($entry)) {
                $surfaces = (array) (\Duo\ManifestDispositions::claim_from_disposition(
                    $manifest,
                    $entry,
                    [],
                    $platform
                )['surfaces'] ?? []);
                $reviewed++;
            }
            $plugin = is_string($manifest['plugin'] ?? null) ? (string) $manifest['plugin'] : '';
            $adapters[$name] = [
                'plugin' => $plugin,
                'slug' => $plugin === '' ? '' : self::slug_of($plugin),
                'surfaces' => array_values(array_map('strval', $surfaces)),
                'reviewed' => is_array($entry),
            ];
        }
        ksort($adapters, SORT_STRING);
        $oracle = [];
        foreach ($adapters as $name => $adapter) {
            $oracle[$name] = ['slug' => $adapter['slug'], 'surfaces' => $adapter['surfaces']];
        }

        return [
            'adapters' => $adapters,
            'reviewed' => $reviewed,
            'sha256' => hash('sha256', \Duo\Canon::encode($oracle)),
            'site_mode' => $siteMode,
        ];
    }

    /**
     * The WHITELIST projection of one inventory. Every field this census can
     * ever see is named here; nothing else in the document is read.
     *
     * @param array<string,mixed> $inventory
     * @return array<string,mixed>
     */
    private static function submission(string $label, array $inventory): array {
        if (($inventory['format'] ?? null) !== self::INVENTORY_FORMAT) {
            throw new FleetCensusRefusal(
                'census_inventory_unsupported',
                "submission '$label' is not a " . self::INVENTORY_FORMAT . ' document',
                'assess the site with a build that emits ' . self::INVENTORY_FORMAT . ', then resubmit it'
            );
        }
        $coverage = is_array($inventory['coverage'] ?? null) ? $inventory['coverage'] : [];
        $options = is_array($coverage['options'] ?? null) ? $coverage['options'] : [];
        $tables = is_array($coverage['tables'] ?? null) ? $coverage['tables'] : [];
        // The four buckets `Coverage::options_report()` guarantees reconcile
        // (agent/src/Review/Coverage.php:63-66: total === captured +
        // declared_excluded + pending + invisible_total) plus the two table
        // totals every ratio here divides by. A submission missing one of them
        // has no coverage ratio at all, and inventing a zero for it would
        // publish a denominator nobody measured.
        foreach (['total', 'captured', 'declared_excluded', 'pending', 'invisible_total', 'invisible_transient', 'invisible_other'] as $key) {
            if (!is_int($options[$key] ?? null)) {
                throw new FleetCensusRefusal(
                    'census_inventory_incomplete',
                    "submission '$label' carries no coverage.options.$key, so it has no coverage ratio",
                    'reassess the site with a build that publishes the full coverage block, then resubmit it'
                );
            }
        }
        foreach (['live_total', 'core_total', 'undeclared_total'] as $key) {
            if (!is_int($tables[$key] ?? null)) {
                throw new FleetCensusRefusal(
                    'census_inventory_incomplete',
                    "submission '$label' carries no coverage.tables.$key, so it has no coverage ratio",
                    'reassess the site with a build that publishes the full coverage block, then resubmit it'
                );
            }
        }

        $plugins = [];
        foreach ((array) ($inventory['plugins'] ?? []) as $plugin) {
            if (!is_array($plugin) || !is_string($plugin['basename'] ?? null) || $plugin['basename'] === '') {
                continue;
            }
            $slug = self::slug_of((string) $plugin['basename']);
            // An installed plugin is one row per slug: a site cannot install
            // the same directory twice, and a duplicated basename would
            // otherwise inflate sites_installed above the site count.
            $plugins[$slug] = ($plugins[$slug] ?? false) || ($plugin['active'] ?? false) === true;
        }
        ksort($plugins, SORT_STRING);

        $pins = [];
        foreach ((array) (is_array($inventory['policy'] ?? null) ? ($inventory['policy']['manifests'] ?? []) : []) as $pin) {
            $name = is_array($pin) ? (string) ($pin['name'] ?? '') : '';
            if ($name !== '') {
                $pins[$name] = true;
            }
        }
        ksort($pins, SORT_STRING);

        $target = is_array($inventory['target'] ?? null) ? $inventory['target'] : [];
        $siteMode = is_string($target['site_mode'] ?? null) && $target['site_mode'] !== ''
            ? (string) $target['site_mode']
            : 'unknown';

        return [
            'label' => $label,
            'site_mode' => $siteMode,
            'plugins' => $plugins,
            'pins' => $pins,
            'options' => [
                'total' => (int) $options['total'],
                'captured' => (int) $options['captured'],
                'declared_excluded' => (int) $options['declared_excluded'],
                'pending' => (int) $options['pending'],
                'invisible_total' => (int) $options['invisible_total'],
                'invisible_transient' => (int) $options['invisible_transient'],
                'invisible_other' => (int) $options['invisible_other'],
            ],
            'invisible_groups' => self::invisible_groups($options['invisible_groups'] ?? null),
            'tables' => [
                'live_total' => (int) $tables['live_total'],
                'core_total' => (int) $tables['core_total'],
                'undeclared_total' => (int) $tables['undeclared_total'],
            ],
            'undeclared_tables' => self::undeclared_tables($tables['undeclared'] ?? null),
        ];
    }

    /**
     * `Coverage::group_and_attribute()`'s rows, narrowed to the three fields
     * it publishes (agent/src/Review/Coverage.php:336-364). A prefix is a
     * NAME; the option keys behind it never travel and never did.
     *
     * @return list<array{prefix:string,count:int,probable_owner:?string}>
     */
    private static function invisible_groups(mixed $rows): array {
        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !is_string($row['prefix'] ?? null) || $row['prefix'] === '') {
                continue;
            }
            $out[] = [
                'prefix' => (string) $row['prefix'],
                'count' => (int) ($row['count'] ?? 0),
                'probable_owner' => is_string($row['probable_owner'] ?? null) && $row['probable_owner'] !== ''
                    ? (string) $row['probable_owner']
                    : null,
            ];
        }
        return $out;
    }

    /**
     * `Coverage::tables_report()`'s undeclared rows, keyed on `logical_name`
     * — the prefix-free identity that file publishes rather than recomputes
     * (agent/src/Review/Coverage.php:302-311), because `wp_` is one install's
     * prefix and a census pools installs.
     *
     * @return list<array{logical_name:string,row_count:int,probable_owner:?string}>
     */
    private static function undeclared_tables(mixed $rows): array {
        $out = [];
        foreach ((array) $rows as $row) {
            if (!is_array($row) || !is_string($row['logical_name'] ?? null) || $row['logical_name'] === '') {
                continue;
            }
            $out[] = [
                'logical_name' => (string) $row['logical_name'],
                'row_count' => (int) ($row['row_count'] ?? 0),
                'probable_owner' => is_string($row['probable_owner'] ?? null) && $row['probable_owner'] !== ''
                    ? (string) $row['probable_owner']
                    : null,
            ];
        }
        return $out;
    }

    /**
     * One site's coverage ratio with its residual NAMED beside it.
     *
     * The unit is a SURFACE: one option row, or one non-core table. Mixing
     * option rows with table ROWS would let a single log table with 400 000
     * rows swamp every other fact on the site, which is the opposite of what
     * a coverage number is for.
     *
     * `tables.covered` counts Duo's own ledger tables as covered because
     * `Coverage::tables_report()` deliberately excludes them from `undeclared`
     * ("Duo's own ledger is not site state and no adapter will ever declare
     * it", agent/src/Review/Coverage.php:~288) — a handful of rows per site,
     * and naming them here would teach an operator to classify the tool
     * assessing them.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function site_row(array $row): array {
        $options = $row['options'];
        $tables = $row['tables'];
        $nonCore = max(0, $tables['live_total'] - $tables['core_total']);
        $coveredTables = max(0, $nonCore - $tables['undeclared_total']);
        $covered = $options['captured'] + $options['declared_excluded'] + $coveredTables;
        $pending = $options['pending'];
        $uncovered = $options['invisible_total'] + $tables['undeclared_total'];
        $total = $options['total'] + $nonCore;

        return [
            'label' => $row['label'],
            'surfaces' => [
                'total' => $total,
                'covered' => $covered,
                'pending' => $pending,
                'uncovered' => $uncovered,
                'covered_ppm' => self::ppm($covered, $total),
            ],
            'options' => [
                'total' => $options['total'],
                'covered' => $options['captured'] + $options['declared_excluded'],
                'pending' => $options['pending'],
                'invisible_transient' => $options['invisible_transient'],
                'invisible_other' => $options['invisible_other'],
            ],
            'tables' => [
                'non_core' => $nonCore,
                'covered' => $coveredTables,
                'undeclared' => $tables['undeclared_total'],
            ],
            // The residual, named. `option_transients` is a COUNT rather than
            // a listing because a transient row has no stable name to publish
            // and `Coverage::partition_transients()` already separates it from
            // the residual an adapter could ever claim.
            'residual' => [
                'option_groups' => $row['invisible_groups'],
                'option_transients' => $options['invisible_transient'],
                'tables' => $row['undeclared_tables'],
            ],
        ];
    }

    /**
     * The fleet totals, summed over the ELIGIBLE rows only — the same
     * denominator `population` discloses.
     *
     * @param list<array<string,mixed>> $sites
     * @return array<string,mixed>
     */
    private static function fleet(array $sites): array {
        $totals = ['total' => 0, 'covered' => 0, 'pending' => 0, 'uncovered' => 0];
        foreach ($sites as $site) {
            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $site['surfaces'][$key];
            }
        }
        $totals['covered_ppm'] = self::ppm($totals['covered'], $totals['total']);
        return $totals;
    }

    /**
     * The demand rank and the adoption funnel, in one pass because they are
     * two projections of one fold.
     *
     * @param list<array<string,mixed>> $eligible
     * @param array{adapters:array<string,array<string,mixed>>} $library
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private static function demand_rows(array $eligible, array $library): array {
        $bySlug = [];
        $unattributedOptions = [];
        $unattributedTables = [];

        foreach ($eligible as $site) {
            foreach ($site['plugins'] as $slug => $active) {
                $bySlug[$slug] ??= self::empty_slug_row((string) $slug);
                $bySlug[$slug]['sites_installed']++;
                if ($active) {
                    $bySlug[$slug]['sites_active']++;
                }
            }
            foreach ($site['invisible_groups'] as $group) {
                $owner = $group['probable_owner'];
                if ($owner === null || !isset($site['plugins'][$owner])) {
                    // `Coverage::attribute()` is advisory by ruling and returns
                    // null rather than guessing (agent/src/Review/Coverage.php:384-392).
                    // An unattributed residual is still residual: dropping it
                    // would make the fleet ratio disagree with its own site
                    // rows, so it is counted and named in its own bucket.
                    $unattributedOptions[$group['prefix']] = ($unattributedOptions[$group['prefix']] ?? 0) + $group['count'];
                    continue;
                }
                $bySlug[$owner]['option_groups'][$group['prefix']] =
                    ($bySlug[$owner]['option_groups'][$group['prefix']] ?? 0) + $group['count'];
            }
            foreach ($site['undeclared_tables'] as $table) {
                $owner = $table['probable_owner'];
                if ($owner === null || !isset($site['plugins'][$owner])) {
                    $unattributedTables[$table['logical_name']] =
                        ($unattributedTables[$table['logical_name']] ?? 0) + $table['row_count'];
                    continue;
                }
                $bySlug[$owner]['tables'][$table['logical_name']] =
                    ($bySlug[$owner]['tables'][$table['logical_name']] ?? 0) + $table['row_count'];
            }
        }

        $adapterBySlug = [];
        foreach ($library['adapters'] as $name => $adapter) {
            $slug = (string) $adapter['slug'];
            if ($slug === '') {
                continue;
            }
            // A slug two adapters claim is a library defect, not a census
            // verdict: the FIRST name in the ksorted library wins so the
            // document stays a function of the library's content, and the
            // collision is left to `duo adapter doctor`, which is where a
            // duplicate claim is the subject.
            $adapterBySlug[$slug] ??= (string) $name;
        }

        $rows = [];
        foreach ($bySlug as $slug => $row) {
            $adapterName = $adapterBySlug[$slug] ?? null;
            $surfaces = $adapterName === null
                ? []
                : array_fill_keys((array) $library['adapters'][$adapterName]['surfaces'], true);
            $reviewed = $adapterName !== null && $surfaces !== [];

            $uncovered = [];
            $claimed = [];
            $optionRows = 0;
            $tableRows = 0;
            ksort($row['option_groups'], SORT_STRING);
            ksort($row['tables'], SORT_STRING);
            foreach ($row['option_groups'] as $prefix => $count) {
                $optionRows += (int) $count;
                $id = 'options:' . $prefix;
                if (self::credits_option_prefix($surfaces, (string) $prefix)) {
                    $claimed[] = $id;
                    continue;
                }
                $uncovered[] = $id;
            }
            foreach ($row['tables'] as $logical => $count) {
                $tableRows += (int) $count;
                $id = 'tables:' . $logical;
                if (isset($surfaces['tables.' . $logical])) {
                    $claimed[] = $id;
                    continue;
                }
                $uncovered[] = $id;
            }

            $sitesPinning = 0;
            if ($adapterName !== null) {
                foreach ($eligible as $site) {
                    if (isset($site['plugins'][$slug]) && isset($site['pins'][$adapterName])) {
                        $sitesPinning++;
                    }
                }
            }

            $rows[] = [
                'slug' => (string) $slug,
                'sites_installed' => $row['sites_installed'],
                'sites_active' => $row['sites_active'],
                'sites_pinning' => $sitesPinning,
                'sites_unpinned' => $row['sites_installed'] - $sitesPinning,
                'covering_adapter' => $adapterName,
                'uncovered_option_groups' => count(array_filter(
                    $uncovered,
                    static fn(string $id): bool => str_starts_with($id, 'options:')
                )),
                'uncovered_tables' => count(array_filter(
                    $uncovered,
                    static fn(string $id): bool => str_starts_with($id, 'tables:')
                )),
                'residual_option_rows' => $optionRows,
                'residual_table_rows' => $tableRows,
                'uncovered_surfaces' => $uncovered,
                // Residual a reviewed claim already covers: the remedy is a
                // pin, not an adapter, and mixing the two into one number is
                // exactly the confusion this row exists to remove.
                'claimed_surfaces' => $claimed,
                'demand_score' => $row['sites_installed'] * count($uncovered),
                'funnel_stage' => self::stage($adapterName, $reviewed, $sitesPinning),
            ];
        }

        // Total order, and every key of it is a function of the submitted set:
        // reordering the inputs cannot move a row. `slug` is unique across
        // rows, so the comparison never falls through to input order.
        usort($rows, static function (array $a, array $b): int {
            return $b['demand_score'] <=> $a['demand_score']
                ?: $b['sites_installed'] <=> $a['sites_installed']
                ?: strcmp($a['slug'], $b['slug']);
        });

        ksort($unattributedOptions, SORT_STRING);
        ksort($unattributedTables, SORT_STRING);
        $unattributed = [
            'option_groups' => [],
            'tables' => [],
            'surfaces' => count($unattributedOptions) + count($unattributedTables),
            'option_rows' => array_sum($unattributedOptions),
            'table_rows' => array_sum($unattributedTables),
        ];
        foreach ($unattributedOptions as $prefix => $count) {
            $unattributed['option_groups'][] = ['prefix' => (string) $prefix, 'count' => (int) $count];
        }
        foreach ($unattributedTables as $logical => $count) {
            $unattributed['tables'][] = ['logical_name' => (string) $logical, 'row_count' => (int) $count];
        }

        return [$rows, $unattributed];
    }

    /** @return array<string,mixed> */
    private static function empty_slug_row(string $slug): array {
        return [
            'slug' => $slug,
            'sites_installed' => 0,
            'sites_active' => 0,
            'option_groups' => [],
            'tables' => [],
        ];
    }

    /**
     * Does this adapter's reviewed claim cover the option namespace the
     * residual is reported in?
     *
     * The residual arrives as `Coverage`'s own prefix grouping, so the claim's
     * exact option keys are folded through the SAME grammar
     * (`Coverage::guess_prefix()`, made reachable for exactly this) rather
     * than through a second regex here. Two prefix grammars that disagreed by
     * one underscore would credit or deny an adapter for a reason no operator
     * could reconstruct.
     *
     * @param array<string,true> $surfaces
     */
    private static function credits_option_prefix(array $surfaces, string $prefix): bool {
        foreach (array_keys($surfaces) as $surface) {
            if (!str_starts_with($surface, 'options.')) {
                continue;
            }
            if (\Duo\Coverage::guess_prefix(substr($surface, strlen('options.'))) === $prefix) {
                return true;
            }
        }
        return false;
    }

    /**
     * The three-step funnel collapsed to one word per plugin. `no_adapter` and
     * `adapter_unpinned` are different answers to "what do I do next" — build
     * one, or pin the one that exists — and a census that reported both as
     * "uncovered" would be the silence this verb exists to remove.
     *
     * `adapter_unreviewed` covers both ways an adapter can exist while
     * claiming nothing: no reviewed disposition entry at all, and an entry
     * naming no section. `claim_from_disposition()` derives zero surfaces
     * either way, and to the operator deciding what to do next the two are one
     * fact — the code shipped, the review did not.
     */
    private static function stage(?string $adapter, bool $reviewed, int $sitesPinning): string {
        if ($adapter === null) {
            return 'no_adapter';
        }
        if (!$reviewed) {
            return 'adapter_unreviewed';
        }
        return $sitesPinning > 0 ? 'adapter_pinned' : 'adapter_unpinned';
    }

    /**
     * Per-stage counts, every stage present including the zeroes —
     * `GapActions`' own "the count is the signal" doctrine
     * (cli/src/Assess/GapActions.php:152-156), so a reader sees a 0 rather
     * than inferring one from an absent key.
     *
     * @param list<array<string,mixed>> $demand
     * @return array<string,mixed>
     */
    private static function funnel(array $demand): array {
        $out = [];
        foreach (self::FUNNEL_STAGES as $stage) {
            $out[$stage] = ['plugins' => 0, 'sites_installed' => 0, 'sites_pinning' => 0, 'uncovered_surfaces' => 0];
        }
        foreach ($demand as $row) {
            $stage = (string) $row['funnel_stage'];
            $out[$stage]['plugins']++;
            $out[$stage]['sites_installed'] += (int) $row['sites_installed'];
            $out[$stage]['sites_pinning'] += (int) $row['sites_pinning'];
            $out[$stage]['uncovered_surfaces'] += count((array) $row['uncovered_surfaces']);
        }
        return $out;
    }

    /**
     * The denominator, said out loud.
     *
     * @param array<string,int> $excluded
     * @param array{site_mode:string} $library
     * @return array<string,mixed>
     */
    private static function population(int $submissions, int $eligible, array $excluded, array $library): array {
        $rows = [];
        $total = 0;
        foreach ($excluded as $mode => $count) {
            $total += $count;
            $rows[] = [
                'site_mode' => (string) $mode,
                'sites' => (int) $count,
                'reason' => 'outside the platform boundary, which claims site_mode ' . $library['site_mode'],
            ];
        }
        return [
            'submissions' => $submissions,
            'eligible' => $eligible,
            'excluded_total' => $total,
            'excluded' => $rows,
            'denominator' => 'eligible',
            'disclosure' => 'every ratio below divides by the ' . $eligible
                . ' eligible submission(s); the ' . $total
                . ' excluded submission(s) named above are in NO denominator and contribute to no rank',
        ];
    }

    /**
     * How much this rank is worth. Opt-in participation means the honest
     * answer can be "this is one estate", and saying so is the mitigation:
     * a labelled sample of one is still a better basis for choosing the next
     * adapter than the nothing it replaces.
     *
     * @return array<string,mixed>
     */
    private static function basis(int $eligible): array {
        if ($eligible <= 1) {
            return [
                'sites' => $eligible,
                'sample_class' => 'one-site',
                'caveat' => 'this census folds ' . $eligible
                    . ' eligible submission(s): the rank below describes that estate and nothing wider',
            ];
        }
        if ($eligible <= self::SAMPLE_NARROW_MAX) {
            return [
                'sites' => $eligible,
                'sample_class' => 'narrow',
                'caveat' => 'this census folds ' . $eligible . ' eligible submissions, at or below the '
                    . self::SAMPLE_NARROW_MAX . '-site narrow bound: read the rank as one estate\'s demand, not a fleet\'s',
            ];
        }
        return [
            'sites' => $eligible,
            'sample_class' => 'fleet',
            'caveat' => 'this census folds ' . $eligible
                . ' eligible submissions, above the narrow bound of ' . self::SAMPLE_NARROW_MAX,
        ];
    }

    /** Integer parts per million; null where nothing was measured. */
    private static function ppm(int $part, int $whole): ?int {
        return $whole <= 0 ? null : intdiv($part * self::PPM, $whole);
    }

    /**
     * WordPress's own `<dir>/<file>.php` plugin identity reduced to the
     * directory, exactly as `AssessInventory::plugins_without_adapter()`
     * derives it (agent/src/Assess/AssessInventory.php:477-485) — including
     * the single-file case, where the slug is the file name without `.php`.
     * The census joins a manifest's `plugin` to an inventory's `plugins[]` on
     * this value, so the two derivations must be the same one.
     */
    private static function slug_of(string $basename): string {
        $directory = strpos($basename, '/') === false ? '' : dirname($basename);
        $file = basename($basename);
        return $directory !== '' && $directory !== '.'
            ? $directory
            : (string) preg_replace('/\.php$/D', '', $file);
    }

    private static function assert_label(string $label): void {
        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw new FleetCensusRefusal(
                'census_label_not_opaque',
                'a site label must be an opaque token of up to 32 lowercase letters, digits, hyphens or underscores',
                'relabel the submission with a token that is not a hostname, URL or path, then rerun census',
                // The label itself is NOT echoed: it is the one thing a
                // careless caller may have made a hostname out of, and a
                // refusal that quoted it would print the identity the grammar
                // exists to keep out of this document.
                'the offending label was rejected by ' . self::LABEL_PATTERN
            );
        }
    }

    /** @return array<string,mixed> */
    private static function read_inventory(string $path): array {
        if (!is_file($path)) {
            throw new FleetCensusRefusal(
                'census_inventory_unreadable',
                'an inventory document named on the command line does not exist',
                'check the path of every --site/--dir submission and rerun census',
                $path
            );
        }
        try {
            $decoded = \Duo\Canon::decode((string) file_get_contents($path));
        } catch (\Throwable $t) {
            throw new FleetCensusRefusal(
                'census_inventory_unreadable',
                'an inventory document named on the command line is not valid JSON',
                'reassess the site and resubmit the document exactly as the agent emitted it',
                $path . ': ' . $t->getMessage(),
                $t
            );
        }
        if (!is_array($decoded)) {
            throw new FleetCensusRefusal(
                'census_inventory_unreadable',
                'an inventory document named on the command line does not decode to an object',
                'reassess the site and resubmit the document exactly as the agent emitted it',
                $path
            );
        }
        return $decoded;
    }

    /**
     * The env-free verb family's own bootstrap (ManifestValidate.php:773-812,
     * AdapterDraft.php:389-421), narrowed to what this verb touches: canonical
     * decoding, the reviewed-claim projector, and `Coverage` for the one
     * option-prefix grammar the residual is grouped in.
     */
    private static function boot(): void {
        $repo = dirname(__DIR__, 3);
        $agent = $repo . '/agent/duo.php';
        if (!is_file($agent)) {
            throw new FleetCensusRefusal(
                'census_agent_source_missing',
                'the agent source this checkout ships could not be found',
                'run census from a duo checkout that carries agent/duo.php',
                $agent
            );
        }
        $source = (string) file_get_contents($agent);
        if (!defined('DUO_AGENT_VERSION')) {
            if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $source, $m) !== 1) {
                throw new FleetCensusRefusal(
                    'census_agent_source_missing',
                    'the agent version could not be resolved from this checkout',
                    'run census from a duo checkout whose agent/duo.php declares DUO_AGENT_VERSION'
                );
            }
            define('DUO_AGENT_VERSION', $m[1]);
        }
        if (!defined('DUO_SPEC_VERSION')) {
            if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $source, $m) !== 1) {
                throw new FleetCensusRefusal(
                    'census_agent_source_missing',
                    'the spec version could not be resolved from this checkout',
                    'run census from a duo checkout whose agent/duo.php declares DUO_SPEC_VERSION'
                );
            }
            define('DUO_SPEC_VERSION', (int) $m[1]);
        }
        $classmap = require $repo . '/agent/duo-classmap.php';
        if (!is_array($classmap)) {
            throw new FleetCensusRefusal(
                'census_agent_source_missing',
                'the agent classmap did not return a map',
                'regenerate it with `php tools/classmap-generate.php` and rerun census'
            );
        }
        $files = [];
        foreach ($classmap as $path) {
            $files[basename((string) $path, '.php')] = (string) $path;
        }
        foreach (['Canon', 'ManifestDispositions', 'Coverage'] as $class) {
            $file = $files[$class] ?? null;
            if (!is_string($file)) {
                throw new FleetCensusRefusal(
                    'census_agent_source_missing',
                    'an agent source this verb requires is absent from the classmap',
                    'regenerate it with `php tools/classmap-generate.php` and rerun census',
                    $class . '.php'
                );
            }
            require_once $repo . '/agent/' . $file;
        }
    }
}
