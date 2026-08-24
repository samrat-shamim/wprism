<?php
/**
 * Offline regression for `duo census` — the fleet census
 * (`duo-fleet-census/v1`) over N `duo-assess-inventory/v1` submissions.
 *
 * WHAT FAILS WITHOUT THE CHANGE
 * -----------------------------
 * Every question below was unanswerable, and the reason was structural rather
 * than accidental: `Coverage` already names and counts what one site cannot
 * version (agent/src/Review/Coverage.php:336-364 groups the genuinely
 * invisible option rows by prefix with a probable owner; :296-317 counts every
 * undeclared custom table beside its own owner guess), `duo assess` already
 * carries that block through verbatim, and nothing anywhere folded two of
 * those documents together. So "which adapter is worth building next" had
 * exactly one possible basis — somebody's recollection.
 *
 * Against the pre-change tree every case here fails at the first call:
 * `duo: unknown verb 'census'`.
 *
 * WHY IT DRIVES THE EXECUTABLE
 * ----------------------------
 * Same reason `sandbox/tests/offline/refresh/regress_merge_check.php` does:
 * the deliverable includes a dispatch entry, a usage line and an exit-code
 * contract, and an in-process call to `FleetCensus::project()` would assert
 * the document while missing all three. Every case runs the real `cli/duo`
 * process against real files under `sandbox/tmp/`.
 *
 * WHAT IS ASSERTED
 * ----------------
 *  1. DETERMINISM. The same submissions in the reverse order produce a
 *     BYTE-IDENTICAL document. The rank is a function of the submitted set,
 *     never of the order a shell globbed it in.
 *  2. REDACTION. `Coverage`'s names-and-counts-only property
 *     (agent/src/Review/Coverage.php:24-36) survives the fold. Each submission
 *     carries a sentinel site URL, a sentinel plugin display name and a
 *     sentinel review-queue `ref_hint`; none of them reaches the census, and
 *     the only site identifier that does is the caller's own opaque label —
 *     which the verb refuses outright when it could be a hostname.
 *  3. COVERAGE CREDIT IS DECLARED AND REVIEWED. A residual surface is credited
 *     to an adapter only when it appears in
 *     `ManifestDispositions::claim_from_disposition()`'s derived surfaces
 *     (agent/src/Policy/ManifestDispositions.php:196-219). Three adapters make
 *     the three cases separable: one reviewed for the sections its manifest
 *     declares (credit), one certified whose disposition names NO section
 *     (zero), and one whose disposition NAMES a section whose manifest map
 *     does not hold the residual key (zero — naming a section earns nothing).
 *     The same fixture is then re-run with the crediting adapter's sections
 *     emptied, and the residual must move from `claimed_surfaces` to
 *     `uncovered_surfaces` with the demand score rising to match.
 *  4. THE DENOMINATOR IS DISCLOSED. The platform claims `single-site`
 *     (manifests/capabilities/platform.json), so a multisite submission is
 *     excluded — and the excluded population is NAMED with its size beside the
 *     eligible count, never silently dropped. The multisite fixture carries a
 *     residual large enough to reorder the rank, so an implementation that
 *     folded it in fails case 4 twice.
 *  5. THE FUNNEL SEPARATES DEMAND FROM ADOPTION. `no_adapter` (nobody built
 *     one) and `adapter_unpinned` (one exists, covers you, and your site does
 *     not pin it) are different rows with different remedies, and
 *     `adapter_pinned` is a third.
 *
 * Every name in this fixture is INVENTED, for the reason
 * `sandbox/tests/fixtures/assess/make-fixture.php:20-24` states: the
 * engine-adapter boundary gate forbids a shipped plugin slug in
 * `cli/src/Assess`, and a fixture that used a real ecosystem's names would
 * tempt the next author to match it in production code.
 */
declare(strict_types=1);

// From offline/cli/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$repoRoot = dirname(__DIR__, 4);
$duo = $repoRoot . '/cli/duo';

// Fixtures live under sandbox/tmp/ — gitignored scratch, per AGENTS.md rule 3
// — with a per-process suffix, because `make -j8` runs the corpus concurrently
// and a fixed path would let two runs of this suite see each other's library.
$scratch = $repoRoot . '/sandbox/tmp/census_' . getmypid() . '_' . bin2hex(random_bytes(4));

function fc_rmtree(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                fc_rmtree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}
register_shutdown_function(static function () use ($scratch): void {
    fc_rmtree($scratch);
});

function fc_write(string $path, array $document): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create fixture directory: $dir");
    }
    file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}

/**
 * Run the real `duo` executable.
 *
 * @param list<string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function fc_run(string $duo, string $cwd, array $args): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $duo], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start the duo executable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** @return array<string,mixed>|null */
function fc_json(string $raw): ?array {
    $decoded = json_decode(trim($raw), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * `duo census …` — the verb is prepended here rather than at each call site so
 * no case can accidentally assert against a different one.
 *
 * @param list<string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function fc_census(string $duo, string $cwd, array $args): array {
    return fc_run($duo, $cwd, array_merge(['census'], $args));
}

/** The one demand row for a slug, or null. @return array<string,mixed>|null */
function fc_demand(array $document, string $slug): ?array {
    foreach ((array) ($document['demand'] ?? []) as $row) {
        if (is_array($row) && ($row['slug'] ?? null) === $slug) {
            return $row;
        }
    }
    return null;
}

// ------------------------------------------------------------ the library
//
// A synthetic manifest library, because the three cases case 3 needs must be
// constructible: the shipped library is (correctly) reviewed for everything it
// declares, so it can only ever demonstrate one of them. `platform.json` is
// COPIED from the shipped library rather than invented —
// `ManifestDispositions::platform_boundary()` refuses a boundary whose
// agent/spec version disagrees with the loaded agent, and a hand-written one
// would have to be edited on every version bump.

/** @return array<string,mixed> */
function fc_disposition(string $plugin, array $range, array $entitySections, array $fieldSections): array {
    return [
        'capabilities' => [
            'deletion_semantics' => ['supported' => [], 'unsupported' => ['fixture entity deletes']],
            'entity_sections' => $entitySections,
            'field_sections' => $fieldSections,
            'lifecycle_phases' => ['activate', 'verify'],
            'operations' => ['capture', 'plan', 'apply'],
        ],
        'default_authored_keyspaces' => [],
        'evidence' => [
            'bundle_schema' => 'duo-subject-certification-bundle/v1',
            'tests' => ['conformance-fixture'],
        ],
        'reason' => 'Fixture disposition for the fleet census suite; no product claim.',
        'status' => 'certified',
        'supported_versions' => ['plugin' => $plugin, 'range' => $range],
        'unsupported' => [[
            'operation' => 'all',
            'reason' => 'Duo v1 refuses multisite, so this fixture claims none of it.',
            'surface' => 'multisite',
        ]],
    ];
}

/**
 * Publish a library. `$formsSections` is the one knob case 3 turns: emptying
 * the crediting adapter's reviewed sections must move its residual out of
 * `claimed_surfaces` without touching a single manifest byte.
 */
function fc_library(string $repoRoot, string $dir, array $formsSections): void {
    $range = ['max' => '2.0.0', 'min' => '1.0.0'];

    // Reviewed for exactly the sections it declares: both residual surfaces
    // below are inside claim_from_disposition()'s expansion.
    fc_write($dir . '/fixture-forms.json', [
        'name' => 'fixture-forms',
        'plugin' => 'fixture-forms/fixture-forms.php',
        'version_range' => $range,
        'options' => [
            'fixture_forms_settings' => ['class' => 'authored'],
            'fixture_forms_state' => ['class' => 'runtime'],
        ],
        'tables' => ['fixture_forms_log' => ['class' => 'runtime']],
    ]);

    // Certified, and its disposition names NO section at all. The manifest
    // declares the very surfaces the fleet is missing — "declared" alone earns
    // nothing, because the expansion never runs.
    fc_write($dir . '/fixture-gallery.json', [
        'name' => 'fixture-gallery',
        'plugin' => 'fixture-gallery/fixture-gallery.php',
        'version_range' => $range,
        'options' => ['fixture_gallery_settings' => ['class' => 'authored']],
        'tables' => ['fixture_gallery_cache' => ['class' => 'runtime']],
    ]);

    // Names the `tables` SECTION, whose map does not hold the residual key.
    // claim_from_disposition() mints the bare `tables` string and no
    // `tables.<key>`, so the residual earns zero.
    fc_write($dir . '/fixture-ledger.json', [
        'name' => 'fixture-ledger',
        'plugin' => 'fixture-ledger/fixture-ledger.php',
        'version_range' => $range,
        'tables' => ['fixture_ledger_index' => ['class' => 'runtime']],
    ]);

    fc_write($dir . '/dispositions.json', [
        'format' => 'duo-manifest-dispositions/v1',
        'manifests' => [
            'fixture-forms' => fc_disposition(
                'fixture-forms/fixture-forms.php',
                $range,
                $formsSections['entity'],
                $formsSections['field']
            ),
            'fixture-gallery' => fc_disposition('fixture-gallery/fixture-gallery.php', $range, [], []),
            'fixture-ledger' => fc_disposition('fixture-ledger/fixture-ledger.php', $range, ['tables'], []),
        ],
        'profiles' => new stdClass(),
    ]);

    if (!is_dir($dir . '/capabilities') && !mkdir($dir . '/capabilities', 0777, true)) {
        throw new RuntimeException('could not create the fixture platform directory');
    }
    copy($repoRoot . '/manifests/capabilities/platform.json', $dir . '/capabilities/platform.json');
}

// --------------------------------------------------------- the submissions

const FC_SENTINEL_HOST = 'https://sentinel-host-must-not-travel.invalid';
const FC_SENTINEL_HINT = 'SENTINEL-REF-HINT-MUST-NOT-TRAVEL';
const FC_SENTINEL_NAME = 'Sentinel Display Name Must Not Travel';

/**
 * One inventory in the shape `AssessInventory::from_facts()` emits, including
 * the three fields a census must never read: `target.home`, `target.siteurl`
 * and the `pending` block, whose `ref_hint` is the ONE content-shaped
 * exception the inventory deliberately carries
 * (agent/src/Assess/AssessInventory.php:37-42).
 *
 * @param array<string,bool> $plugins slug => active
 * @param list<string> $pins
 * @return array<string,mixed>
 */
function fc_inventory(
    string $siteMode,
    array $plugins,
    array $pins,
    array $optionGroups,
    array $tables,
    array $optionTotals
): array {
    $pluginRows = [];
    foreach ($plugins as $slug => $active) {
        $pluginRows[] = [
            'basename' => "$slug/$slug.php",
            'name' => FC_SENTINEL_NAME,
            'version' => '1.4.0',
            'active' => $active,
        ];
    }
    $pinRows = [];
    foreach ($pins as $pin) {
        $pinRows[] = ['name' => $pin, 'source' => 'shipped', 'adapter_digest' => str_repeat('a', 64), 'status' => 'certified'];
    }
    $undeclared = [];
    $undeclaredRows = 0;
    foreach ($tables as $row) {
        $undeclaredRows++;
        $undeclared[] = [
            'table' => 'wp_' . $row['logical_name'],
            'logical_name' => $row['logical_name'],
            'row_count' => $row['row_count'],
            'probable_owner' => $row['probable_owner'],
        ];
    }

    return [
        'format' => 'duo-assess-inventory/v1',
        'spec_version' => 2,
        'agent_version' => '0.5.0',
        'target' => [
            'wordpress' => '7.0.3',
            'php' => '8.3.33',
            'database' => ['engine' => 'MariaDB', 'version' => '11.8.8'],
            'site_mode' => $siteMode,
            'home' => FC_SENTINEL_HOST,
            'siteurl' => FC_SENTINEL_HOST,
        ],
        'plugins' => $pluginRows,
        'plugins_without_adapter' => [],
        'themes' => [['stylesheet' => 'fixture-theme', 'name' => FC_SENTINEL_NAME, 'version' => '2.0', 'active' => true]],
        'media' => ['count' => 2, 'bytes' => null],
        'policy' => ['manifests' => $pinRows, 'surface_groups' => []],
        'coverage' => [
            'format' => 'duo-coverage-report/v1',
            'options' => $optionTotals + [
                'declared_excluded_by_class' => [],
                'invisible_groups' => $optionGroups,
            ],
            'tables' => [
                'live_total' => 12 + $undeclaredRows,
                'core_total' => 12,
                'declared_total' => 0,
                'undeclared_total' => $undeclaredRows,
                'undeclared' => $undeclared,
            ],
        ],
        'pending' => [
            'count' => 1,
            'rows' => [[
                'section' => 'options',
                'key' => 'fixture_queued_row',
                'proposal' => null,
                'evidence' => new stdClass(),
                'ref_hint' => FC_SENTINEL_HINT,
            ]],
            'truncated' => false,
        ],
        'adapter_survey' => ['adapters' => [], 'not_installed' => [], 'refusals' => [], 'sources' => []],
    ];
}

/** The four-bucket options block Coverage guarantees reconciles. @return array<string,int> */
function fc_options(int $total, int $captured, int $excluded, int $pending, int $transient, int $other): array {
    return [
        'total' => $total,
        'captured' => $captured,
        'declared_excluded' => $excluded,
        'pending' => $pending,
        'invisible_total' => $transient + $other,
        'invisible_transient' => $transient,
        'invisible_other' => $other,
    ];
}

$inventories = $scratch . '/inventories';

// alpha: forms installed and NOT pinned, so its residual is visible; a widget
// no adapter anywhere declares; and one option group Coverage::attribute()
// could not attribute at all.
fc_write($inventories . '/alpha.json', fc_inventory(
    'single-site',
    ['fixture-forms' => true, 'fixture-widget' => true],
    [],
    [
        ['prefix' => 'fixture_forms', 'count' => 40, 'probable_owner' => 'fixture-forms'],
        ['prefix' => 'fixture_widget', 'count' => 9, 'probable_owner' => 'fixture-widget'],
        ['prefix' => 'orphan_ns', 'count' => 4, 'probable_owner' => null],
    ],
    [['logical_name' => 'fixture_forms_log', 'row_count' => 12, 'probable_owner' => 'fixture-forms']],
    fc_options(200, 120, 25, 2, 0, 53)
));

// bravo: forms PINNED — the adapter in force, so nothing of its own is
// residual here. This is the site that makes fixture-forms `adapter_pinned`.
fc_write($inventories . '/bravo.json', fc_inventory(
    'single-site',
    ['fixture-forms' => true, 'fixture-gallery' => true],
    ['fixture-forms'],
    [['prefix' => 'fixture_gallery', 'count' => 30, 'probable_owner' => 'fixture-gallery']],
    [['logical_name' => 'fixture_gallery_cache', 'row_count' => 3, 'probable_owner' => 'fixture-gallery']],
    fc_options(150, 100, 18, 2, 0, 30)
));

// charlie: the ledger's residual table, whose logical name its reviewed
// `tables` section does not hold.
fc_write($inventories . '/charlie.json', fc_inventory(
    'single-site',
    ['fixture-forms' => true, 'fixture-ledger' => true],
    [],
    [['prefix' => 'fixture_forms', 'count' => 40, 'probable_owner' => 'fixture-forms']],
    [['logical_name' => 'fixture_ledger_events', 'row_count' => 77, 'probable_owner' => 'fixture-ledger']],
    fc_options(180, 110, 28, 2, 0, 40)
));

// delta: the widget alone — the second site that makes it a two-site demand.
fc_write($inventories . '/delta.json', fc_inventory(
    'single-site',
    ['fixture-widget' => true],
    [],
    [['prefix' => 'fixture_widget', 'count' => 11, 'probable_owner' => 'fixture-widget']],
    [],
    fc_options(90, 60, 19, 0, 0, 11)
));

// The excluded population, and deliberately the loudest document in the set:
// eight distinct residual surfaces on a plugin nothing else in the fleet even
// installs. Folded in, it would take the top of the rank; excluded, it must
// not appear in one demand row.
$multisiteGroups = [];
for ($i = 0; $i < 8; $i++) {
    $multisiteGroups[] = ['prefix' => sprintf('fixture_network_%d', $i), 'count' => 500, 'probable_owner' => 'fixture-network'];
}
fc_write($inventories . '/echo.json', fc_inventory(
    'multisite',
    ['fixture-network' => true],
    [],
    $multisiteGroups,
    [],
    fc_options(5000, 900, 100, 0, 0, 4000)
));

$labels = ['alpha', 'bravo', 'charlie', 'delta', 'echo'];
$siteFlags = [];
foreach ($labels as $label) {
    $siteFlags[$label] = '--site=' . $label . '=' . $inventories . '/' . $label . '.json';
}

$library = $scratch . '/library';
fc_library($repoRoot, $library, ['entity' => ['tables'], 'field' => ['options']]);

// ------------------------------------------------------- 1. determinism

$forward = fc_census($duo, $repoRoot, array_merge(
    array_values($siteFlags),
    ['--manifests=' . $library, '--format=json']
));
duo_check_same(0, $forward['exit'], 'a census over five submissions exits 0');
duo_check_same('', $forward['stderr'], 'the JSON path writes nothing to stderr');
$document = fc_json($forward['stdout']);
duo_check($document !== null, 'the JSON path emits one decodable document');
// Against a tree with no `census` verb every assertion below has nothing to
// read. Coercing here keeps that run a wall of named FAILs — which is the
// evidence — instead of one fatal that hides the other sixty.
$document ??= [];
duo_check_same('duo-fleet-census/v1', $document['format'] ?? null, 'the document names its own versioned format');

$reverse = fc_census($duo, $repoRoot, array_merge(
    array_reverse(array_values($siteFlags)),
    ['--manifests=' . $library, '--format=json']
));
duo_check_same(0, $reverse['exit'], 'the reversed submission order also exits 0');
duo_check_same(
    $forward['stdout'],
    $reverse['stdout'],
    'the rank is a function of the submitted SET: reversing the input order is byte-identical'
);

// The rank itself, so "deterministic" cannot be satisfied by a document that
// is stably wrong. Ties break on sites_installed, then on slug.
duo_check_same(
    ['fixture-widget', 'fixture-gallery', 'fixture-ledger', 'fixture-forms'],
    array_map(static fn(array $row): string => (string) $row['slug'], (array) ($document['demand'] ?? [])),
    'demand ranks by sites x uncovered surface, ties broken by site count then slug'
);

// -------------------------------------------------------- 2. redaction

$encoded = (string) $forward['stdout'];
duo_check(
    !str_contains($encoded, FC_SENTINEL_HOST),
    'no site URL survives the fold: target.home/siteurl are never read'
);
duo_check(
    !str_contains($encoded, FC_SENTINEL_HINT),
    'no review-queue ref_hint survives the fold: the pending block is never read'
);
duo_check(
    !str_contains($encoded, FC_SENTINEL_NAME),
    'no plugin or theme display name survives the fold: only the slug identity travels'
);
foreach (['home', 'siteurl', 'pending_rows', 'ref_hint'] as $forbidden) {
    duo_check(
        !str_contains($encoded, '"' . $forbidden . '"'),
        "the census document carries no '$forbidden' key anywhere"
    );
}
// The only site identifiers in the document are the caller's own labels.
duo_check_same(
    ['alpha', 'bravo', 'charlie', 'delta'],
    array_map(static fn(array $row): string => (string) $row['label'], (array) ($document['sites'] ?? [])),
    'the per-site rows are keyed by the opaque caller label and nothing else'
);

// And the label cannot BE a site identifier: the grammar admits no dot.
$hostLabel = fc_census($duo, $repoRoot, [
    '--site=alpha.example.com=' . $inventories . '/alpha.json',
    '--manifests=' . $library,
    '--format=json',
]);
duo_check_same(1, $hostLabel['exit'], 'a label that could be a hostname refuses');
$refusal = fc_json($hostLabel['stdout']);
duo_check_same(
    'census_label_not_opaque',
    $refusal['reason_code'] ?? null,
    'the hostname-shaped label refuses with its own reason code in the machine envelope'
);
duo_check(
    !str_contains((string) $hostLabel['stdout'], 'alpha.example.com'),
    'the refusal does not echo the offending label — that is the identity the grammar excludes'
);

// ------------------------------------------- 3. coverage credit is reviewed

$forms = fc_demand($document, 'fixture-forms');
duo_check($forms !== null, 'the crediting adapter has a demand row');
$forms ??= [];
duo_check_same(
    [],
    (array) ($forms['uncovered_surfaces'] ?? null),
    'a residual inside claim_from_disposition() derived surfaces is NOT counted as demand'
);
duo_check_same(
    ['options:fixture_forms', 'tables:fixture_forms_log'],
    (array) ($forms['claimed_surfaces'] ?? null),
    'that residual is reported as already claimed — the remedy is a pin, not an adapter'
);
duo_check_same(0, (int) ($forms['demand_score'] ?? -1), 'a fully claimed residual scores zero demand');

$gallery = fc_demand($document, 'fixture-gallery');
duo_check($gallery !== null, 'the declared-but-unreviewed adapter has a demand row');
$gallery ??= [];
duo_check_same(
    ['options:fixture_gallery', 'tables:fixture_gallery_cache'],
    (array) ($gallery['uncovered_surfaces'] ?? null),
    'DECLARING the sections earns zero credit: the disposition names none, so the expansion never runs'
);
duo_check_same(
    [],
    (array) ($gallery['claimed_surfaces'] ?? null),
    'and nothing about it is claimed'
);

$ledger = fc_demand($document, 'fixture-ledger');
duo_check($ledger !== null, 'the section-naming adapter has a demand row');
$ledger ??= [];
duo_check_same(
    ['tables:fixture_ledger_events'],
    (array) ($ledger['uncovered_surfaces'] ?? null),
    'NAMING a section earns zero: only the bare section string is minted, never tables.<key>'
);

// Turn the one knob: the same manifests, the same submissions, the crediting
// adapter's reviewed sections emptied. Credit must vanish.
$narrowed = $scratch . '/library-narrowed';
fc_library($repoRoot, $narrowed, ['entity' => [], 'field' => []]);
$narrowedRun = fc_census($duo, $repoRoot, array_merge(
    array_values($siteFlags),
    ['--manifests=' . $narrowed, '--format=json']
));
duo_check_same(0, $narrowedRun['exit'], 'the narrowed-library census exits 0');
$narrowedDocument = fc_json($narrowedRun['stdout']) ?? [];
$narrowedForms = fc_demand($narrowedDocument, 'fixture-forms') ?? [];
duo_check_same(
    ['options:fixture_forms', 'tables:fixture_forms_log'],
    (array) ($narrowedForms['uncovered_surfaces'] ?? null),
    'emptying the reviewed sections moves the same residual back into demand — credit is the REVIEW, not the manifest'
);
duo_check_same(
    6,
    (int) ($narrowedForms['demand_score'] ?? -1),
    'and the demand score rises with it (3 sites x 2 uncovered surfaces)'
);
duo_check(
    isset($document['library']['surfaces_sha256'], $narrowedDocument['library']['surfaces_sha256'])
        && $document['library']['surfaces_sha256'] !== $narrowedDocument['library']['surfaces_sha256'],
    'the published surfaces_sha256 is the content address of the coverage oracle, so the two runs are distinguishable'
);

// ----------------------------------------- 4. the denominator is disclosed

$population = (array) ($document['population'] ?? []);
duo_check_same(5, (int) ($population['submissions'] ?? -1), 'the population names every submission received');
duo_check_same(4, (int) ($population['eligible'] ?? -1), 'and the eligible count every ratio divides by');
duo_check_same(1, (int) ($population['excluded_total'] ?? -1), 'the excluded population is counted, not dropped');
// duo_check_json_equal, not duo_check_same: `Canon::encode()` ksorts object
// keys, so an ordered literal here would be asserting the encoder rather than
// the fact.
duo_check_json_equal(
    [['site_mode' => 'multisite', 'sites' => 1,
      'reason' => 'outside the platform boundary, which claims site_mode single-site']],
    (array) ($population['excluded'] ?? []),
    'the excluded population is NAMED with its topology and the boundary that excluded it'
);
duo_check_same('eligible', (string) ($population['denominator'] ?? ''), 'the denominator names itself');
duo_check(
    str_contains((string) ($population['disclosure'] ?? ''), '4 eligible')
        && str_contains((string) ($population['disclosure'] ?? ''), '1 excluded'),
    'the disclosure sentence carries both halves of the denominator'
);
duo_check_same(
    null,
    fc_demand($document, 'fixture-network'),
    'an excluded submission contributes to no demand row, however loud its residual'
);
duo_check_same(
    'single-site',
    (string) ($document['library']['site_mode'] ?? ''),
    'the eligibility rule is read from the platform boundary, not from a literal'
);

// The residual that nobody owns is still residual, and still named.
duo_check_json_equal(
    ['option_groups' => [['prefix' => 'orphan_ns', 'count' => 4]], 'tables' => [],
     'surfaces' => 1, 'option_rows' => 4, 'table_rows' => 0],
    (array) ($document['unattributed'] ?? []),
    'an unattributable residual is counted and named in its own bucket rather than dropped'
);

// ------------------------------------------------------------ 5. the funnel

duo_check_same('no_adapter', (string) ((fc_demand($document, 'fixture-widget') ?? [])['funnel_stage'] ?? ''),
    'a plugin no adapter declares reads no_adapter — this is demand');
duo_check_same('adapter_unpinned', (string) ($ledger['funnel_stage'] ?? ''),
    'an adapter that exists and is reviewed but that no site pins reads adapter_unpinned — this is adoption');
duo_check_same('adapter_unreviewed', (string) ($gallery['funnel_stage'] ?? ''),
    'an adapter whose review names nothing reads adapter_unreviewed');
duo_check_same('adapter_pinned', (string) ($forms['funnel_stage'] ?? ''),
    'an adapter at least one site pins reads adapter_pinned');
duo_check_same(1, (int) ($forms['sites_pinning'] ?? -1), 'and the row counts the sites that pin it');
duo_check_same(3, (int) ($forms['sites_installed'] ?? -1), 'against the sites that installed the plugin');
duo_check_same(2, (int) ($forms['sites_unpinned'] ?? -1), 'leaving the adoption gap as its own number');

$funnel = (array) ($document['funnel'] ?? []);
$stages = array_keys($funnel);
$expectedStages = ['no_adapter', 'adapter_unreviewed', 'adapter_unpinned', 'adapter_pinned'];
sort($stages, SORT_STRING);
sort($expectedStages, SORT_STRING);
duo_check_same(
    $expectedStages,
    $stages,
    'every funnel stage is present including the zeroes — the count is the signal'
);
duo_check_same(1, (int) ($funnel['no_adapter']['plugins'] ?? -1), 'the funnel counts the no-adapter plugins');
duo_check_same(1, (int) ($funnel['adapter_unpinned']['plugins'] ?? -1), 'separately from the adapter-exists-unpinned ones');

// ---------------------------------------------- the ratio and its residual

$alpha = null;
foreach ((array) ($document['sites'] ?? []) as $row) {
    if (($row['label'] ?? null) === 'alpha') {
        $alpha = (array) $row;
    }
}
duo_check($alpha !== null, 'alpha has a per-site row');
$alpha ??= ['surfaces' => [], 'residual' => ['option_groups' => []]];
// 200 option rows + 1 non-core table = 201 surfaces; 120 captured + 25
// declared-excluded + 0 covered tables = 145 covered.
duo_check_json_equal(
    ['total' => 201, 'covered' => 145, 'pending' => 2, 'uncovered' => 54,
     'covered_ppm' => intdiv(145 * 1000000, 201)],
    (array) ($alpha['surfaces'] ?? []),
    'the per-site ratio reconciles: covered + pending + uncovered === total'
);
duo_check_same(
    3,
    count((array) ($alpha['residual']['option_groups'] ?? [])),
    'the residual is NAMED per site, not merely counted'
);
$fleet = (array) ($document['fleet'] ?? []);
duo_check_same(
    (int) ($fleet['total'] ?? -1),
    array_sum(array_map(static fn(array $row): int => (int) $row['surfaces']['total'], (array) ($document['sites'] ?? []))),
    'the fleet denominator is the sum of the eligible site denominators and nothing else'
);

// ------------------------------------------------------ the basis is labelled

duo_check_same('narrow', (string) ($document['basis']['sample_class'] ?? ''),
    'a four-site census is labelled narrow rather than published as a fleet verdict');
duo_check(
    str_contains((string) ($document['basis']['caveat'] ?? ''), '4 eligible'),
    'and the caveat names the number the rank rests on'
);
$one = fc_census($duo, $repoRoot, [$siteFlags['alpha'], '--manifests=' . $library, '--format=json']);
duo_check_same('one-site', (string) ((fc_json($one['stdout']) ?? [])['basis']['sample_class'] ?? ''),
    'a census of one estate says so — a labelled sample of one is still better than no basis at all');

// ------------------------------------------------------- the verb contract

$usage = fc_run($duo, $repoRoot, ['census', '--not-a-flag=1']);
duo_check_same(2, $usage['exit'], 'an unrecognized flag is a usage error (2), matching the env-free verb family');
$none = fc_census($duo, $repoRoot, ['--manifests=' . $library]);
duo_check_same(1, $none['exit'], 'a census with no submissions refuses (1) rather than publishing an empty fleet');

$missing = fc_census($duo, $repoRoot, [
    '--site=alpha=' . $scratch . '/does-not-exist.json',
    '--manifests=' . $library,
    '--format=json',
]);
duo_check_same(1, $missing['exit'], 'an unreadable submission refuses');
duo_check_same(
    'census_inventory_unreadable',
    (fc_json($missing['stdout']) ?? [])['reason_code'] ?? null,
    'and names why in the machine envelope rather than on stderr'
);

// --dir labels by filename stem through the same grammar, so a submission
// saved under a site's domain is refused rather than quietly pooled.
$hostile = $scratch . '/hostile';
fc_write($hostile . '/site.example.com.json', fc_inventory(
    'single-site',
    ['fixture-widget' => true],
    [],
    [],
    [],
    fc_options(10, 10, 0, 0, 0, 0)
));
$hostileRun = fc_census($duo, $repoRoot, ['--dir=' . $hostile, '--manifests=' . $library, '--format=json']);
duo_check_same(1, $hostileRun['exit'], '--dir refuses a submission whose filename is a site identifier');
duo_check_same(
    'census_label_not_opaque',
    (fc_json($hostileRun['stdout']) ?? [])['reason_code'] ?? null,
    'with the same reason code the explicit label path uses'
);

// The human view exists and is bounded; it prints counts, never listings.
$human = fc_census($duo, $repoRoot, array_merge(
    array_values($siteFlags),
    ['--manifests=' . $library, '--limit=2']
));
duo_check_same(0, $human['exit'], 'the human view exits 0');
duo_check(
    str_contains($human['stdout'], 'demand: 2 further row(s) in --format=json'),
    '--limit bounds the human rank and points at the unbounded document'
);
duo_check(
    !str_contains($human['stdout'], FC_SENTINEL_HOST) && !str_contains($human['stdout'], FC_SENTINEL_HINT),
    'and the human view carries no sentinel either'
);

// The verb must be reachable and documented through cli/duo itself — the two
// halves an in-process test of FleetCensus could not see.
$shell = (string) file_get_contents($duo);
duo_check(
    str_contains($shell, "if (\$verb === 'census') {"),
    'census is registered in cli/duo dispatch, before the environment preflight'
);
duo_check(
    str_contains($shell, 'duo census --site=<label>=<inventory.json>'),
    'census appears in the public usage text'
);
require_once $repoRoot . '/cli/src/Command/EnvironmentCommandPreflight.php';
require_once $repoRoot . '/cli/src/Transport/EnvironmentDriver.php';
duo_check(
    !in_array('census', \Duo\Orchestrator\EnvironmentCommandPreflight::environmentVerbs(), true),
    'census is NOT an environment verb: it has no target, and binding it to one would be a category error'
);

duo_check_summary('regress_fleet_census');
