<?php
/**
 * WP-2.7 — a recorded conformance vector replays offline, and says a WEAKER
 * word than the live sweep that recorded it.
 *
 * `sandbox/conformance/run.sh` proves a manifest's round trip against a
 * disposable pair: seed conf1 -> capture -> deploy conf2 -> apply -> recapture
 * -> `diff -r` clean. `docs/agents/live-pair-budget.md` allows exactly one pair
 * at a time program-wide under a 6 pair-hour per-wave ceiling, so that proof
 * cannot be an iteration loop. `CONF_RECORD_VECTOR=<file>` makes one such run
 * leave a `duo-conformance-vector/v1` document behind
 * (`sandbox/conformance/record-vector.sh`), and this suite is what consumes it
 * forever after: FakeWpdb seeded from the recorded rows, FrozenPolicy loading
 * the adapter over the fail-closed `duo-policy-snapshot/v6` wire, and the REAL
 * engine — `Snapshot::capture()`, then `Snapshot::ensure_row()` +
 * `Snapshot::finalize_row()` into an EMPTY second target, then capture again —
 * doing the round trip.
 *
 * THE VECTOR HERE IS SYNTHETIC, ON PURPOSE. Recording a real plugin's vector
 * costs the pair this program budgets at allocation order 4; the mechanism must
 * be provably correct before that pair is spent, so the fixture below is a
 * synthetic adapter (`duo-vector-fixture`) carrying the shape that matters —
 * a typed `authored_snapshot` table, an `authored_snapshot_meta` twin with one
 * authored key and one runtime key, a URL that must tokenize to `{{home}}`, and
 * hostile UTF-8/delimiter bytes. The expected canonical trees are PINNED
 * LITERALS below, not values this suite recomputes: an expectation derived from
 * the same engine it is checking would agree with any drift.
 *
 * WHAT EACH SECTION EXISTS TO CATCH
 *   1. the vector replays to a byte-identical recapture, on both legs;
 *   2. a manifest edit that changes what canonical holds IS CAUGHT by the
 *      replay — the recorded expectation stays fixed while the library moves,
 *      which is exactly the fleet situation AGENTS.md rule 2 describes;
 *   3. the replay verdict is `vector_replayed`, a distinct and weaker word
 *      than the `conformance_verified` the vector was recorded under, and the
 *      `status: deferred` rows are stamped on a PASSING envelope too — the
 *      discipline `duo manifest-validate` uses
 *      (`cli/src/Adapter/AdapterCatalog.php:193-195`) so silence cannot read
 *      as verified;
 *   4. a vector that could not honestly replay is refused BY NAME rather than
 *      degrading — above all one with no recorded `duo_map`, because capture
 *      MINTS a uuid for an unmapped row and such a vector would produce
 *      different paths and bytes on every run.
 */
declare(strict_types=1);

// From offline/capture/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';

// The manifest grammar's own gate: AdapterContractGrammar refuses a manifest
// whose spec_version disagrees with the engine, so the replay loads its adapter
// over the same version boundary a deployed site does.
define('DUO_SPEC_VERSION', 2);

require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Secrets.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Repository/IdentityNotes.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';

require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../../lib/ConformanceVector.php';

use Duo\Canon;
use DuoTest\ConformanceVector;

// --------------------------------------------------------------- the fixture

/**
 * The synthetic adapter the vector was "recorded" against. Every rule here is
 * load-bearing for some byte in the pinned trees below: `touched` is `runtime`
 * so it must NOT reach canonical, `weight` is `authored` so it must, and the
 * meta twin declares `colour` authored / `last_seen` runtime so exactly one of
 * the three recorded meta rows survives capture.
 */
function vector_manifest(): array
{
    return [
        'name' => 'duo-vector-fixture',
        'plugin' => 'duo-vector-fixture/duo-vector-fixture.php',
        'spec_version' => 2,
        'tables' => [
            'duovec_notemeta' => [
                'attached_to' => ['column' => 'note_id', 'table' => 'duovec_notes'],
                'class' => 'authored_snapshot_meta',
                // `default_class: runtime` keeps the keyspace bounded: an
                // unknown key is not silently absorbed as authored, which is
                // the gate Snapshot::keyspace_gaps() enforces at capture.
                'default_class' => 'runtime',
                'key_column' => 'meta_key',
                'keys' => [
                    'colour' => ['class' => 'authored'],
                    'last_seen' => ['class' => 'runtime'],
                ],
                'value_column' => 'meta_value',
            ],
            'duovec_notes' => [
                'class' => 'authored_snapshot',
                'columns' => [
                    'body' => ['class' => 'authored'],
                    'title' => ['class' => 'authored'],
                    'touched' => ['class' => 'runtime'],
                    'weight' => ['class' => 'authored', 'lint_ok' => true],
                ],
                'id_kind' => 'duovec_note',
                'pk' => 'id',
                'refs' => [],
                'slug_column' => 'title',
            ],
        ],
        'version_range' => ['max' => '2.1.0', 'min' => '2.0.0'],
    ];
}

/**
 * The `duo-adapter-probe/v1` half: live schema facts, self-hashed the way
 * `AdapterProbe::hash_document()` hashes them. This is what makes the replay's
 * FakeWpdb schema a RECORDING rather than the harness's own bookkeeping — the
 * objection `sandbox/tests/lib/README.md` raises against a synthetic
 * `information_schema`. The engine reads it for real: `assert_meta_schema()`
 * compares the twin's SHOW COLUMNS against `[id, meta_key, meta_value,
 * note_id]` and `TableSchema::live_column_types()` reads the note table's
 * types before every typed insert on the apply leg.
 */
function vector_probe(): array
{
    $document = [
        'authority' => false,
        'deferred' => [
            'a probe proposes no class, identity, deletion authority or capability: it converts a structural '
                . 'guess into a live fact so the human ratification in adapter-draft is better founded',
            'row values are never read; the only row-derived numbers are COUNT(*) and COUNT(DISTINCT <column>)',
        ],
        'format' => 'duo-adapter-probe/v1',
        'redaction' => 'values_omitted',
        'tables' => [
            'duovec_notemeta' => [
                'columns' => [
                    'id' => ['nullable' => false, 'type' => 'bigint(20) unsigned'],
                    'meta_key' => ['nullable' => true, 'type' => 'varchar(255)'],
                    'meta_value' => ['nullable' => true, 'type' => 'longtext'],
                    'note_id' => ['nullable' => false, 'type' => 'bigint(20) unsigned'],
                ],
                'eav_twin' => null,
                'foreign_keys' => [],
                'index_coverage' => [
                    'id' => ['index' => 'PRIMARY', 'prefix' => null],
                    'meta_key' => ['index' => 'meta_key', 'prefix' => 191],
                    'meta_value' => ['index' => null, 'prefix' => null],
                    'note_id' => ['index' => 'note_id', 'prefix' => null],
                ],
                'present' => true,
                'primary_key' => ['id'],
                'unique_keys' => [],
            ],
            'duovec_notes' => [
                'columns' => [
                    'body' => ['nullable' => false, 'type' => 'longtext'],
                    'id' => ['nullable' => false, 'type' => 'bigint(20) unsigned'],
                    'title' => ['nullable' => false, 'type' => 'varchar(191)'],
                    'touched' => ['nullable' => false, 'type' => 'datetime'],
                    'weight' => ['nullable' => false, 'type' => 'int(11)'],
                ],
                'eav_twin' => null,
                'foreign_keys' => [],
                'index_coverage' => [
                    'body' => ['index' => null, 'prefix' => null],
                    'id' => ['index' => 'PRIMARY', 'prefix' => null],
                    'title' => ['index' => 'title', 'prefix' => null],
                    'touched' => ['index' => null, 'prefix' => null],
                    'weight' => ['index' => null, 'prefix' => null],
                ],
                'present' => true,
                'primary_key' => ['id'],
                'unique_keys' => ['title' => ['title']],
            ],
        ],
        'target' => ['agent_version' => '0.0.0-vector-fixture', 'spec_version' => 2],
    ];
    $document['probe_hash'] = 'sha256:' . hash('sha256', Canon::encode($document));
    return $document;
}

/**
 * The PINNED canonical trees. Transcribed from a run, never recomputed here:
 * every property this suite claims — `touched` absent, `weight` present as the
 * string mysqli's text protocol returns, `last_seen` absent from `meta`, the
 * uploads URL rewritten to `{{home}}`, the uuid taken from the recorded ledger
 * rather than minted — is visible in these bytes.
 *
 * @return array<string,string>
 */
function vector_expected_tree(): array
{
    return [
        'tables/duovec_notes/01924f2a-0000-7000-8000-00000000004a--alpha-note.json' => "{\n    \"columns\": {\n        \"body\": \"portable 東京 🚀 | comma, quote\\\" apostrophe' backslash\\\\\",\n        \"title\": \"Alpha note\",\n        \"weight\": \"10\"\n    },\n    \"meta\": {\n        \"colour\": \"amber\"\n    },\n    \"table\": \"duovec_notes\",\n    \"uuid\": \"01924f2a-0000-7000-8000-00000000004a\"\n}\n",
        'tables/duovec_notes/01924f2a-0000-7000-8000-00000000007b--beta-note.json' => "{\n    \"columns\": {\n        \"body\": \"see {{home}}/wp-content/uploads/x.png\",\n        \"title\": \"Beta note\",\n        \"weight\": \"20\"\n    },\n    \"meta\": {\n        \"colour\": \"teal\"\n    },\n    \"table\": \"duovec_notes\",\n    \"uuid\": \"01924f2a-0000-7000-8000-00000000007b\"\n}\n",
    ];
}

/** @param array<string,mixed> $overrides */
function vector_document(array $overrides = []): array
{
    $parts = [
        'ledger' => [
            // conf1's duo_map. Without it capture mints, and "byte-identical"
            // becomes impossible rather than merely unproven.
            'duo_map' => [
                [
                    'entity_type' => 'duovec_notes',
                    'id_kind' => 'duovec_note',
                    'local_id' => 4,
                    'uuid' => '01924f2a-0000-7000-8000-00000000004a',
                ],
                [
                    'entity_type' => 'duovec_notes',
                    'id_kind' => 'duovec_note',
                    'local_id' => 7,
                    'uuid' => '01924f2a-0000-7000-8000-00000000007b',
                ],
            ],
        ],
        'manifest' => vector_manifest(),
        'probe' => vector_probe(),
        'recapture' => vector_expected_tree(),
        'recorded' => [
            'agent_version' => '0.0.0-vector-fixture',
            'manifest_name' => 'duo-vector-fixture',
            'plugin_version' => '2.0.4',
            'recorded_at' => '2026-08-24T00:00:00Z',
            'source_sha' => '0000000000000000000000000000000000000000',
            'spec_version' => 2,
            // Stamped by the recorder ONLY because run.sh's own acceptance
            // passed first; assert_document() refuses a vector without it.
            'verdict' => ConformanceVector::LIVE_VERDICT,
        ],
        'rows' => [
            'duovec_notemeta' => [
                ['id' => 1, 'meta_key' => 'colour', 'meta_value' => 'amber', 'note_id' => 4],
                ['id' => 2, 'meta_key' => 'last_seen', 'meta_value' => '2026-08-01 09:00:00', 'note_id' => 4],
                ['id' => 3, 'meta_key' => 'colour', 'meta_value' => 'teal', 'note_id' => 7],
            ],
            'duovec_notes' => [
                [
                    'body' => "portable 東京 🚀 | comma, quote\" apostrophe' backslash\\",
                    'id' => 4,
                    'title' => 'Alpha note',
                    'touched' => '2026-08-01 09:00:00',
                    'weight' => 10,
                ],
                [
                    'body' => 'see https://duo-vector.invalid/wp-content/uploads/x.png',
                    'id' => 7,
                    'title' => 'Beta note',
                    'touched' => '2026-08-02 09:00:00',
                    'weight' => 20,
                ],
            ],
        ],
        'state' => vector_expected_tree(),
    ];
    return ConformanceVector::document(array_replace($parts, $overrides));
}

// ------------------------------------------------ 1. the vector replays clean

echo "\n== 1. a recorded vector replays to a byte-identical recapture ==\n";

$vector = vector_document();
duo_check_same(
    ConformanceVector::FORMAT,
    $vector['format'],
    'the recorded vector names duo-conformance-vector/v1'
);
duo_check_same(
    $vector['vector_hash'],
    ConformanceVector::hash_document($vector),
    'the vector self-hash covers its own canonical bytes'
);

$pass = ConformanceVector::replay($vector);
duo_check_same([], $pass['mismatches'], 'the replay reports no mismatch on either leg');
duo_check(true === $pass['replayed'], 'the replay reproduced the recorded bytes');

// The two legs are separately load-bearing, so assert what each one produced
// rather than trusting the aggregate: leg 1 is capture over the recorded rows,
// leg 2 is ensure_row+finalize_row into an EMPTY target and capture again.
DuoTest\WpStore::reset()->seedOptions(['home' => 'https://duo-vector.invalid']);
ConformanceVector::seed($vector, true);
$captured = ConformanceVector::capture_tree(ConformanceVector::policy(vector_manifest()));
duo_check_same(
    vector_expected_tree(),
    $captured,
    'leg 1: the real capture engine reproduces the recorded state tree from the recorded rows'
);

$note = Canon::decode($captured['tables/duovec_notes/01924f2a-0000-7000-8000-00000000004a--alpha-note.json']);
duo_check(
    !array_key_exists('touched', (array) $note['columns']),
    'the runtime-classed column never reaches canonical (a real classification, not a transcription)'
);
duo_check_same(
    ['colour' => 'amber'],
    (array) $note['meta'],
    'exactly the authored meta key survives; the runtime key does not'
);

// The apply leg's target really was empty and really was written by the engine.
DuoTest\WpStore::reset()->seedOptions(['home' => 'https://duo-vector.invalid']);
$empty = ConformanceVector::seed($vector, false);
duo_check_same([], $empty->rows('wp_duovec_notes'), 'the apply leg starts from an empty target, as conf2 does');
duo_check_same([], $empty->rows('wp_duo_map'), 'the apply leg starts with no identity, as conf2 does');

// ------------------------------- 2. a manifest edit is CAUGHT by the replay

echo "\n== 2. a manifest edit that breaks the round trip is caught ==\n";

$reclassified = vector_manifest();
$reclassified['tables']['duovec_notes']['columns']['weight']['class'] = 'runtime';
$caughtColumn = ConformanceVector::replay($vector, $reclassified);
duo_check(false === $caughtColumn['replayed'], 'reclassifying an authored column to runtime fails the replay');
duo_check_same(
    ConformanceVector::REPLAY_REFUSED,
    $caughtColumn['verdict'],
    'a broken round trip earns the refusal verdict, never the pass verdict'
);
duo_check(
    in_array(
        "state: 'tables/duovec_notes/01924f2a-0000-7000-8000-00000000004a--alpha-note.json' bytes differ from the recording",
        $caughtColumn['mismatches'],
        true
    ),
    'the refusal names the exact recorded path whose bytes moved'
);

$demoted = vector_manifest();
$demoted['tables']['duovec_notemeta']['keys']['colour']['class'] = 'runtime';
$caughtMeta = ConformanceVector::replay($vector, $demoted);
duo_check(false === $caughtMeta['replayed'], 'demoting an authored meta key to runtime fails the replay');

// A manifest edit that moves the canonical PATH, not just the bytes: the
// recorded path is then never produced at all, which is a different failure
// mode and must be reported as one.
$reslugged = vector_manifest();
$reslugged['tables']['duovec_notes']['slug_column'] = 'body';
$caughtSlug = ConformanceVector::replay($vector, $reslugged);
duo_check(false === $caughtSlug['replayed'], 'changing the slug column fails the replay');
duo_check(
    (bool) array_filter(
        $caughtSlug['mismatches'],
        static fn(string $row): bool => str_contains($row, 'was not produced')
    ),
    'a moved canonical path is reported as a recorded path that was not produced'
);

// The control: the SAME vector against its OWN manifest still passes, so the
// three refusals above are the edits biting and not the harness drifting.
duo_check(
    true === ConformanceVector::replay($vector, vector_manifest())['replayed'],
    'the unedited manifest still replays clean, so the refusals above are the edits'
);

// ------------------------------- 3. the verdict word is distinct and weaker

echo "\n== 3. the replay verdict is a distinct, weaker word than the live one ==\n";

duo_check_same('conformance_verified', ConformanceVector::LIVE_VERDICT, 'the live sweep verdict word is pinned');
duo_check_same('vector_replayed', ConformanceVector::REPLAY_VERDICT, 'the replay verdict word is pinned');
duo_check(
    ConformanceVector::LIVE_VERDICT !== ConformanceVector::REPLAY_VERDICT
        && ConformanceVector::LIVE_VERDICT !== ConformanceVector::REPLAY_REFUSED,
    'no replay verdict word equals the live conformance verdict word'
);
duo_check_same(
    ConformanceVector::LIVE_VERDICT,
    $vector['recorded']['verdict'],
    'the vector carries the LIVE verdict it was recorded under'
);
duo_check(
    $pass['verdict'] !== $vector['recorded']['verdict'],
    'a passing replay of that same vector answers with a different word than the one it was recorded under'
);
duo_check_same(ConformanceVector::REPLAY_VERDICT, $pass['verdict'], 'a passing replay says vector_replayed');
duo_check_same(
    ConformanceVector::LIVE_VERDICT,
    $pass['verdict_is_not'],
    'the envelope names the verdict it is NOT, in machine-readable form'
);
duo_check(
    str_contains($pass['verdict_note'], 'boots no WordPress')
        && str_contains($pass['verdict_note'], 'renders no page'),
    'the envelope states what the weaker word means, not just that it is weaker'
);

// The manifest-validate discipline: the deferrals are stamped on the PASSING
// envelope too. A list that only appears on failures is a list nobody reads.
foreach ([['pass', $pass], ['refusal', $caughtColumn]] as [$label, $envelope]) {
    duo_check(
        count($envelope['deferred']) >= 5,
        "the $label envelope carries the deferred rows"
    );
    $statuses = array_values(array_unique(array_column($envelope['deferred'], 'status')));
    duo_check_same(['deferred'], $statuses, "every $label deferral is stamped status=deferred");
    foreach ($envelope['deferred'] as $row) {
        duo_check(
            ($row['surface'] ?? '') !== '' && ($row['check'] ?? '') !== '' && ($row['why'] ?? '') !== '',
            "the $label deferral '" . ($row['surface'] ?? '?') . "' names a surface, a check and a reason"
        );
    }
}
$surfaces = array_column(ConformanceVector::deferred(), 'surface');
foreach (['deploy: activation and theme reconciliation', 'render-level acceptance'] as $mustDefer) {
    duo_check(
        in_array($mustDefer, $surfaces, true),
        "the replay defers '$mustDefer' — a live-only surface it can never speak for"
    );
}

// ------------------------------- 4. a dishonest vector is refused by name

echo "\n== 4. a vector that could not honestly replay is refused ==\n";

$tampered = $vector;
$tampered['rows']['duovec_notes'][0]['title'] = 'Tampered note';
duo_check_throws(
    static fn() => ConformanceVector::replay($tampered),
    \RuntimeException::class,
    'a vector edited after recording is refused by its self-hash',
    'self-hash does not match'
);

duo_check_throws(
    static fn() => ConformanceVector::replay(vector_document(['ledger' => ['duo_map' => []]])),
    \RuntimeException::class,
    'a vector with no recorded duo_map is refused rather than replayed against minted uuids',
    'capture MINTS a uuid for an unmapped row'
);

$noProbe = vector_document(['probe' => ['format' => 'duo-adapter-observation/v1']]);
duo_check_throws(
    static fn() => ConformanceVector::replay($noProbe),
    \RuntimeException::class,
    'a vector with no duo-adapter-probe/v1 document is refused',
    'synthetic information_schema'
);

$claimsAuthority = vector_probe();
$claimsAuthority['authority'] = true;
duo_check_throws(
    static fn() => ConformanceVector::replay(vector_document(['probe' => $claimsAuthority])),
    \RuntimeException::class,
    'a probe that claims authority is refused; a vector never upgrades AdapterProbe\'s authority:false',
    'claims authority'
);

$unverified = vector_document();
$unverified['recorded']['verdict'] = 'vector_replayed';
$unverified['vector_hash'] = ConformanceVector::hash_document($unverified);
duo_check_throws(
    static fn() => ConformanceVector::replay($unverified),
    \RuntimeException::class,
    'a vector not recorded under the live verdict is refused — a replay may not father a vector',
    'only a sweep whose own acceptance passed'
);

$disagreeing = vector_document();
$disagreeing['recapture'] = array_slice($disagreeing['recapture'], 0, 1, true);
$disagreeing['vector_hash'] = ConformanceVector::hash_document($disagreeing);
duo_check_throws(
    static fn() => ConformanceVector::replay($disagreeing),
    \RuntimeException::class,
    'a vector whose recorded state and recapture already disagree did not come from a passing round trip',
    'did not come from a passing round trip'
);

duo_check_throws(
    static fn() => ConformanceVector::document(['manifest' => vector_manifest()]),
    \RuntimeException::class,
    'a recorder that stops emitting a field fails at assembly, on the pair that produced it',
    "is missing 'ledger'"
);
duo_check_throws(
    static fn() => vector_document(['surprise' => 1]),
    \RuntimeException::class,
    'an unknown field is refused rather than silently carried',
    "unknown field 'surprise'"
);

// ------------------- 5. the recorder emits exactly what the replay accepts

echo "\n== 5. conformance/record-vector.php assembles a vector this replay accepts ==\n";

// The recorder's LIVE inputs (the probe, the row dump) cannot be produced
// without a pair, but its ASSEMBLY is host-side and pure, and it is the half
// that can silently drift from the grammar. Drive it as the subprocess run.sh
// drives it, over the synthetic adapter and the pinned trees above — no pair,
// no shipped manifest bytes pinned into a fixture (AGENTS.md rule 2).
$work = sys_get_temp_dir() . '/duo_vector_recorder_' . bin2hex(random_bytes(6));
$sourceRoot = $work . '/source';
$packageRoot = $sourceRoot . '/adapter-packages/duo-vector-fixture/package';
$platformRoot = $sourceRoot . '/platform/adapter-library';
mkdir($packageRoot, 0700, true);
mkdir($platformRoot . '/capabilities', 0700, true);
mkdir($platformRoot . '/core', 0700, true);
mkdir($work . '/state/tables/duovec_notes', 0700, true);
mkdir($work . '/recapture/tables/duovec_notes', 0700, true);
$removeWork = static function () use ($work): void {
    if (!is_dir($work)) {
        return;
    }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($work);
};
register_shutdown_function($removeWork);

file_put_contents($packageRoot . '/manifest.json', Canon::encode(vector_manifest()));
file_put_contents($packageRoot . '/disposition.json', "{}\n");
$repoPlatform = dirname(__DIR__, 4) . '/platform/adapter-library';
foreach ([
    'capabilities/adapter-authorities.json',
    'capabilities/platform.json',
    'core/disposition.json',
    'core/manifest.json',
    'profiles.json',
] as $platformMember) {
    copy($repoPlatform . '/' . $platformMember, $platformRoot . '/' . $platformMember);
}
foreach (vector_expected_tree() as $path => $bytes) {
    file_put_contents($work . '/state/' . $path, $bytes);
    file_put_contents($work . '/recapture/' . $path, $bytes);
}
file_put_contents($work . '/probe.json', json_encode(vector_probe()));
file_put_contents($work . '/rows.json', json_encode([
    'agent_version' => '0.0.0-vector-fixture',
    'ledger' => $vector['ledger'],
    'rows' => $vector['rows'],
    'spec_version' => 2,
]));

$recorderShell = (string) file_get_contents(__DIR__ . '/../../../conformance/record-vector.sh');
duo_check(
    !str_contains($recorderShell, 'DUO_MANIFESTS_DIR')
        && !str_contains($recorderShell, '../manifests/'),
    'the live recorder has no process-global or flat-manifest library selection'
);
duo_check(
    str_contains($recorderShell, '$SOURCE_ROOT/adapter-packages/$MANIFEST/package/manifest.json')
        && str_contains($recorderShell, '"$WORK/rows.json" "$SOURCE_ROOT"'),
    'the live recorder reads and hands off the same explicit source adapter package'
);

$command = 'php ' . escapeshellarg(__DIR__ . '/../../../conformance/record-vector.php') . ' '
    . implode(' ', array_map('escapeshellarg', [
        $work . '/vector.json',
        'duo-vector-fixture',
        $work . '/state',
        $work . '/recapture',
        $work . '/probe.json',
        $work . '/rows.json',
        $sourceRoot,
    ])) . ' 2>&1';
$recorderOutput = [];
$recorderStatus = 1;
exec($command, $recorderOutput, $recorderStatus);
duo_check_same(0, $recorderStatus, 'the recorder exits 0: ' . implode("\n", $recorderOutput));

$recorded = json_decode((string) file_get_contents($work . '/vector.json'), true);
duo_check(is_array($recorded), 'the recorder wrote a JSON document');
duo_check_same(
    ConformanceVector::LIVE_VERDICT,
    $recorded['recorded']['verdict'] ?? null,
    'the recorder stamps the LIVE verdict — a claim about the sweep that invoked it'
);
duo_check_same(
    $recorded['vector_hash'],
    ConformanceVector::hash_document($recorded),
    'the recorded document self-hashes over its own bytes'
);
duo_check(
    true === ConformanceVector::replay($recorded)['replayed'],
    'a vector this recorder produced replays clean through the same driver'
);
duo_check_same(
    ConformanceVector::REPLAY_VERDICT,
    ConformanceVector::replay($recorded)['verdict'],
    'and still answers with the weaker word'
);

duo_check_summary('conformance vector replay (duo-conformance-vector/v1): record once live, replay forever offline');
