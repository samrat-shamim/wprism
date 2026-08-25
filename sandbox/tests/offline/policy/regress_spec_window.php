<?php
/**
 * The `spec_version` acceptance window and the `engine_features` channel
 * (spec/repo-format.md § v3.1 and § v3.2; WP-4.2).
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * Before this rider the wire version was one exact-equality test —
 * `if (!is_int($spec) || $spec !== $supported)` — so an absent declaration and
 * a declaration one version behind produced the identical refusal, and every
 * format change was a flag day for every adapter anyone had authored. The
 * window replaces that with three DIFFERENT verdicts, and the whole value of
 * the rule is that they stay different: a version outside {N-1, N} refuses
 * wholesale naming the window; a manifest inside it that declares a section
 * this engine implements only at a higher version refuses NAMING THE SECTION;
 * and an absent or non-integer `spec_version` keeps the older refusal byte for
 * byte, because it is not a version and so is not outside anything. Collapse
 * any two of those together and the window is back to being a tolerance.
 *
 * WHY IT PROBES TWO ENGINES
 * -------------------------
 * WP-4.12's flip changed which era the shipped engine is IN, and this header
 * is the record of it. While `DUO_SPEC_VERSION` was `2` the window was {1, 2}
 * and the one implemented section, `engine_features` (since 3), was NOT
 * declarable by any manifest the engine accepted — the channel's admitting
 * half could not be exercised in this process at all. The flip moved the
 * ceiling onto the section's own version, so the admitting half is now a live
 * product path here and the shipped library, still at N-1, is exactly the
 * population the refusing half is for.
 *
 * THREE ENGINES, ONE PROBE SET. The child processes are this file in
 * `--probe` mode — a spec version is a `define()` and a PHP process holds one
 * of those, the same reason `spec_migration_estate.php` drives its estate
 * through child processes — so no era can drift from another:
 *
 *   N   this process, the shipped engine;
 *   N+1 what the next bump would do (PART 2);
 *   N-1 the ROLLBACK engine, which is where the "the window does not reach
 *       that version" remedy went when the flip retired it here, and which is
 *       what a v2 agent tells an operator about a v3-stamped manifest.
 *
 * WHAT THE PARTS PROVE
 * --------------------
 *   PART 1 — the shipped engine (N = DUO_SPEC_VERSION). The window is exactly
 *   {N-1, N}: N-2 and N+1 both refuse, naming the window. The two older
 *   refusals are byte-identical to the strings recorded here from before the
 *   window shipped. Every one of the shipped manifests still loads, which is
 *   the digest-neutrality half — no manifest byte moves, so no adapter digest
 *   moves — the library declares N-1, and that is the no-restamp rule
 *   (§ v3.12) measured rather than argued. `engine_features` is ADMITTED at
 *   the ceiling and refuses by SECTION NAME at the floor, with the actionable
 *   remedy the window now makes true.
 *
 *   PART 2 — a synthetic N+1 engine, and the N-1 rollback engine. The channel
 *   answers three ways: a declared feature this engine IMPLEMENTS admits the
 *   adapter and admits the key that feature claims; a declared name nothing
 *   implements refuses THAT ADAPTER naming the feature; a malformed list
 *   refuses on shape before any vocabulary lookup. The N-1 probe carries the
 *   other remedy arm and the one-way fact G3 turns on: an N-1 engine accepts
 *   {N-2, N-1}, so a manifest re-stamped to N is outside its window entirely.
 *
 *   PART 3 — the surfaces that publish the window. `duo manifest-validate`
 *   loads each manifest on its own, so a pin set holding one offending adapter
 *   reports that adapter `[error]` and its neighbours `[ok]` in one run — the
 *   blast radius § v3.1 states, measured rather than asserted. The emitted
 *   `duo-manifest-grammar/v2` document's `spec_window` block is MEASURED by
 *   probing (WP-4.1), so it must have followed the window with no edit to the
 *   emitter; that it did is checked here rather than assumed.
 *
 *   PART 4 — the release gate. `php tools/wire-surface.php --check` asserts the
 *   floor is exactly `DUO_SPEC_VERSION - 1` (register row R-18). A gate that
 *   never bites is theatre, so it is also run against a COPY of the shipped
 *   trees whose window has been widened to N-2, and must refuse that copy.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 4);

/**
 * The probe set, run identically against every engine version.
 *
 * One list, two eras: PART 1 runs it in this process against the shipped
 * define and PART 2 runs it in a child that defines N+1, so a verdict that
 * differs between them differs because the ENGINE differs and for no other
 * reason. Each probe is a minimal manifest — every other check in
 * `validate_adapter_contract()` is keyed on a declaration these do not carry
 * (`interpreter`, `plugin`/`version_range`, `theme`/`theme_version_range`), so
 * the only verdicts measured are the version, section and feature ones.
 *
 * @return array<string,mixed>
 */
function spec_window_report(int $supported): array {
    $sections = \Duo\AdapterContractGrammar::section_min_spec();
    $featureSection = 'engine_features';
    $since = $sections[$featureSection] ?? ($supported + 1);
    $implemented = \Duo\AdapterContractGrammar::implemented_features();
    // The feature that claims THIS suite's section, derived rather than taken
    // as `$implemented[0]`. That index was the same thing while the engine
    // implemented one feature; WP-6.1 added two more, and the first one
    // alphabetically now claims a different key entirely — so every probe below
    // would have declared `engine_features: ["attr-id-codecs/v1"]`, a manifest
    // whose declared feature does not admit the very key it is written in, and
    // the suite would have measured the closed key set instead of the channel.
    $first = 'none/v0';
    foreach ($implemented as $feature) {
        $claims = \Duo\AdapterContractGrammar::admitted_feature_keys([$featureSection => [$feature]]);
        if (in_array($featureSection, $claims, true)) {
            $first = $feature;
            break;
        }
    }

    $verdict = static function (array $manifest): ?string {
        try {
            \Duo\AdapterContractGrammar::validate_adapter_contract($manifest);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    };
    $named = static fn(string $name, int $spec, array $extra = []): array
        => ['name' => $name, 'spec_version' => $spec] + $extra;

    $accepted = [];
    for ($candidate = $supported - 3; $candidate <= $supported + 2; $candidate++) {
        if ($verdict($named('window-probe', $candidate)) === null) {
            $accepted[] = $candidate;
        }
    }

    $probes = [
        // The two verdicts that must NOT become the window's verdict.
        'absent' => ['name' => 'window-probe'],
        'non_integer' => ['name' => 'window-probe', 'spec_version' => (string) $supported],
        // The window's own edges.
        'floor' => $named('window-probe', $supported - 1),
        'ceiling' => $named('window-probe', $supported),
        'below_floor' => $named('window-probe', $supported - 2),
        'above_ceiling' => $named('window-probe', $supported + 1),
        // A v3-only section declared from inside the window.
        'floor_with_section' => $named('sectionful', $supported - 1, [$featureSection => [$first]]),
        'ceiling_with_section' => $named('sectionful', $supported, [$featureSection => [$first]]),
        // The channel, at the version that implements it.
        'since_implemented' => $named('featureful', $since, [$featureSection => [$first]]),
        'since_unimplemented' => $named('featureful', $since, [$featureSection => ['acme-thing/v1']]),
        'since_mixed' => $named('featureful', $since, [$featureSection => ['acme-thing/v1', $first]]),
        // Shape, checked before vocabulary.
        'since_empty' => $named('featureful', $since, [$featureSection => []]),
        'since_unsorted' => $named('featureful', $since, [$featureSection => [$first, 'acme-thing/v1']]),
        'since_duplicated' => $named('featureful', $since, [$featureSection => [$first, $first]]),
        'since_not_a_list' => $named('featureful', $since, [$featureSection => [$first => true]]),
        'since_non_string' => $named('featureful', $since, [$featureSection => [7]]),
        'since_scalar' => $named('featureful', $since, [$featureSection => $first]),
    ];
    $verdicts = [];
    foreach ($probes as $label => $manifest) {
        $verdicts[$label] = $verdict($manifest);
    }

    return [
        'engine_supported' => $supported,
        'accepted' => $accepted,
        'verdicts' => $verdicts,
        'implemented_features' => $implemented,
        'section_min_spec' => $sections,
        'admitted_feature_keys' => \Duo\AdapterContractGrammar::admitted_feature_keys(
            [$featureSection => [$first]]
        ),
        'admitted_for_unknown_feature' => \Duo\AdapterContractGrammar::admitted_feature_keys(
            [$featureSection => ['acme-thing/v1']]
        ),
    ];
}

// ---------------------------------------------------------------------------
// CHILD MODE. `php <this file> --probe <N>` loads the SHIPPED grammar under a
// synthetic DUO_SPEC_VERSION and prints the probe report as JSON. It runs
// before check.php is required and exits before any assertion, so the child
// contributes no counted checks and no output the corpus diagnostics guard
// reads.
// ---------------------------------------------------------------------------
$specWindowArgv = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];
if (($specWindowArgv[1] ?? '') === '--probe') {
    define('DUO_SPEC_VERSION', (int) ($specWindowArgv[2] ?? 0));
    require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
    echo json_encode(spec_window_report(DUO_SPEC_VERSION), JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

require_once __DIR__ . '/../../lib/check.php';

// The engine's own define, read out of agent/duo.php the way every other
// offline suite that needs it does — never a literal, so this file says
// nothing about which integer N happens to be.
if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);
if (!defined('DUO_AGENT_VERSION')) {
    preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", (string) file_get_contents($repo . '/agent/duo.php'), $agentMatch);
    define('DUO_AGENT_VERSION', (string) ($agentMatch[1] ?? '0.0.0'));
}

require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Kernel/OptionState.php';
require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';

use Duo\AdapterContractGrammar;
use Duo\Canon;

/** One indented report row, indented so the diagnostics guard cannot read it as a PHP notice. */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

/**
 * Run a command and return its exact exit code and streams.
 *
 * @param list<string> $args
 * @return array{exit:int, stdout:string, stderr:string}
 */
$run = static function (array $args): array {
    $cmd = implode(' ', array_map('escapeshellarg', $args));
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        throw new \RuntimeException("could not run: $cmd");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
};

$N = DUO_SPEC_VERSION;

echo "\nPART 1 — the SHIPPED engine: the window is exactly {N-1, N}\n";

$shipped = spec_window_report($N);
$report('engine DUO_SPEC_VERSION: ' . $N . '; accepted over N-3 … N+2: {' . implode(', ', $shipped['accepted']) . '}');
$report('implemented engine features: ' . implode(', ', $shipped['implemented_features']));
$report('v3-only sections: ' . json_encode($shipped['section_min_spec'], JSON_UNESCAPED_SLASHES));

duo_check_same(
    [$N - 1, $N],
    $shipped['accepted'],
    'the shipped window is exactly {' . ($N - 1) . ', ' . $N . '} — floor N-1, ceiling N, and nothing deeper'
);

// THE REFUSAL THAT MUST NOT MOVE. Recorded literally rather than rebuilt from
// the engine: the whole claim is that this string did not change when the
// window shipped, and a string derived from the same code cannot make it.
$absentRefusal = "duo: manifest 'window-probe' declares no spec_version but this engine requires spec_version "
    . $N . ' — pin a compatible manifest or update it';
duo_check_same(
    $absentRefusal,
    $shipped['verdicts']['absent'],
    'an ABSENT spec_version keeps DUO-3247\'s refusal byte for byte — it is not a version, so it is not outside a window'
);
duo_check_same(
    "duo: manifest 'window-probe' declares spec_version '" . $N . "' but this engine requires spec_version "
        . $N . ' — pin a compatible manifest or update it',
    $shipped['verdicts']['non_integer'],
    'and a NON-INTEGER spec_version keeps it too, including the var_export rendering of the declared value'
);

duo_check_same(null, $shipped['verdicts']['ceiling'], 'a manifest declaring N loads, exactly as it always did');
duo_check_same(null, $shipped['verdicts']['floor'], 'a manifest declaring N-1 now loads — the window, and the one behaviour WP-4.2 adds');

foreach (['below_floor' => $N - 2, 'above_ceiling' => $N + 1] as $label => $candidate) {
    $refusal = (string) $shipped['verdicts'][$label];
    duo_check(
        str_contains($refusal, 'declares spec_version ' . $candidate)
            && str_contains($refusal, 'accepts spec_version {' . ($N - 1) . ', ' . $N . '}')
            && str_contains($refusal, 'spec/repo-format.md § v3.1'),
        "spec_version $candidate refuses WHOLESALE and names the window it is outside ($label)"
    );
}
duo_check_detail('out-of-window refusal: ' . (string) $shipped['verdicts']['above_ceiling']);

// THE PER-SECTION REFUSAL, AND WHAT WP-4.12 DID TO IT. Before the flip the one
// implemented section, `engine_features`, sat at spec_version 3 — one PAST this
// engine's ceiling — so both in-window versions refused it and the remedy had
// to say "this engine's window does not reach 3", because sending an author to
// declare 3 would have sent them to a manifest the same validator refused
// wholesale one line up. The flip moved the ceiling onto the section's own
// version. Both halves of the channel are now reachable in this process, which
// is § v3.2's whole point arriving: the declaration channel opens with the
// bump and needs no second one.
$sectionSince = $shipped['section_min_spec']['engine_features'] ?? null;
duo_check_same(
    $N,
    $sectionSince,
    '`engine_features` is implemented at spec_version ' . $N . ', which IS this engine\'s ceiling — the flip '
        . '(WP-4.12) brought the channel inside the window'
);
$floorRefusal = (string) $shipped['verdicts']['floor_with_section'];
duo_check(
    str_contains($floorRefusal, "manifest 'sectionful' declares spec_version " . ($N - 1))
        && str_contains($floorRefusal, "the section 'engine_features'")
        && str_contains($floorRefusal, 'implements only at spec_version ' . $sectionSince)
        && str_contains($floorRefusal, 'may not declare a section from a HIGHER version'),
    'a spec_version ' . ($N - 1) . ' manifest declaring `engine_features` still refuses BY SECTION NAME and by '
        . 'adapter name — the N-1 arm of the window is exactly where the shipped library sits'
);
duo_check(
    str_contains($floorRefusal, 'declare spec_version ' . $sectionSince . ' to use it, or remove the section'),
    '...and its remedy is now the ACTIONABLE one, because the window reaches that version: the same code path '
        . 'that used to say "this engine\'s window does not reach it" reads the window rather than assuming an era'
);
duo_check_same(
    null,
    $shipped['verdicts']['ceiling_with_section'],
    'while a spec_version ' . $N . ' manifest declaring it is ADMITTED on the shipped engine — the channel is a '
        . 'live product path here, not a synthetic-engine measurement'
);
duo_check_detail('per-section refusal at the floor: ' . $floorRefusal);

// DIGEST NEUTRALITY. The rider's standing precondition: no shipped manifest
// byte moves, so no adapter digest moves. Re-measured from the library rather
// than argued, since the window is the one change that could have refused one.
$library = [];
foreach (glob($repo . '/manifests/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'dispositions') {
        continue;
    }
    $library[$name] = Canon::decode(Canon::read_file($file));
}
ksort($library, SORT_STRING);
$refused = [];
$declaredVersions = [];
foreach ($library as $name => $manifest) {
    $declaredVersions[$manifest['spec_version'] ?? 'absent'] = true;
    try {
        AdapterContractGrammar::validate_adapter_contract($manifest);
    } catch (\Throwable $e) {
        $refused[$name] = $e->getMessage();
    }
}
$report('shipped library: ' . count($library) . ' manifests, declared spec_versions {'
    . implode(', ', array_map('strval', array_keys($declaredVersions))) . '}');
duo_check_same([], $refused, 'every one of the ' . count($library) . ' shipped manifests still passes the contract grammar — the window refuses none of the library');
// WP-4.12: the library sits at N-1, not at N. That is the whole no-restamp
// rule (§ v3.12) expressed as a measurement — the flip moved the ENGINE and
// left every manifest byte alone, which is why not one adapter digest moved
// and why the window had to exist before the bump rather than with it.
duo_check_same(
    [($N - 1) => true],
    $declaredVersions,
    'and every one still declares spec_version ' . ($N - 1) . ' — the flip moved the engine to ' . $N
        . ' and re-stamped nothing, so no manifest byte and no adapter digest moved'
);
$declarers = array_keys(array_filter($library, static fn(array $m): bool => array_key_exists('engine_features', $m)));
duo_check_same([], $declarers, 'no shipped manifest declares `engine_features` — the channel ships with a live implementation and no declarer, which is § v3.12\'s no-restamp rule and not an empty channel');

echo "\nPART 2 — a synthetic N+1 engine: the channel's admitting half\n";

$child = $run([PHP_BINARY, __FILE__, '--probe', (string) ($N + 1)]);
duo_check_same(0, $child['exit'], 'the child probe process exits 0 (stderr: ' . trim($child['stderr']) . ')');
$future = json_decode($child['stdout'], true);
duo_check(is_array($future), 'and prints one decodable probe report');
$future = is_array($future) ? $future : ['accepted' => [], 'verdicts' => [], 'section_min_spec' => []];

$report('synthetic engine DUO_SPEC_VERSION: ' . ($N + 1) . '; accepted: {' . implode(', ', (array) $future['accepted']) . '}');

duo_check_same(
    [$N, $N + 1],
    $future['accepted'],
    'the window moved with the engine: an N+1 engine accepts {' . $N . ', ' . ($N + 1) . '} — which is why no manifest is re-stamped on the flip'
);
duo_check(
    str_contains((string) $future['verdicts']['below_floor'], 'accepts spec_version {' . $N . ', ' . ($N + 1) . '}'),
    'and spec_version ' . ($N - 1) . ' — inside the OLD window — refuses on the new engine, naming the new window'
);

// The requirement this whole rider turns on: a declared feature the engine
// IMPLEMENTS admits the adapter, and admits the key that feature claims.
duo_check_same(
    null,
    $future['verdicts']['since_implemented'],
    'DECLARATION + IMPLEMENTATION ADMITS: a spec_version ' . ($N + 1)
        . ' manifest declaring the feature that claims `engine_features` loads'
);
duo_check_same(
    ['engine_features'],
    $future['admitted_feature_keys'],
    '...and that feature admits the top-level key it claims, which is the seam § v3.3\'s closed key set attaches to'
);
duo_check_same(
    [],
    $future['admitted_for_unknown_feature'],
    '...while a feature the engine does not implement admits nothing — it never reaches the key question, because the contract grammar refused first'
);
// The SAME declaration, refused by section name on one engine and admitted on
// the other. One mechanism read at two engine versions, which is what the
// two-era probe set exists to show.
//
// WP-4.12 moved WHICH probe carries it. Both engines now admit
// `ceiling_with_section`, because the flip put the section's own version
// inside the shipped window — so the cross-era pair is the FLOOR probe: the
// shipped engine's floor is spec_version N-1, below the section, and the N+1
// engine's floor is N, which is the section's version exactly.
duo_check(
    is_string($shipped['verdicts']['floor_with_section'])
        && $future['verdicts']['floor_with_section'] === null,
    'the floor declaration the shipped engine refuses by section name is the one the N+1 engine admits — the '
        . 'section rule and the feature rule are one mechanism read at two versions'
);

$unimplemented = (string) $future['verdicts']['since_unimplemented'];
duo_check(
    str_contains($unimplemented, "manifest 'featureful' declares engine feature 'acme-thing/v1'")
        && str_contains($unimplemented, 'this engine does not implement it')
        && str_contains($unimplemented, 'This engine implements: ' . implode(', ', $shipped['implemented_features'])),
    'an UNIMPLEMENTED feature refuses THAT ADAPTER, naming the feature, the adapter, and what this engine does implement'
);
duo_check(
    str_contains($unimplemented, 'an adapter declares one, never mints one'),
    '...and says why the name could not simply be minted: feature names are engine-owned (§ v3.2)'
);
duo_check_detail('unimplemented-feature refusal: ' . $unimplemented);
duo_check(
    str_contains((string) $future['verdicts']['since_mixed'], "'acme-thing/v1'"),
    'a list mixing an implemented and an unimplemented name refuses on the unimplemented one — one bad name is enough'
);

duo_check_same(
    null,
    $future['verdicts']['ceiling'],
    'on the N+1 engine a spec_version ' . ($N + 1) . ' manifest loads'
);
duo_check_same(
    null,
    $future['verdicts']['floor_with_section'],
    '...and its FLOOR — spec_version ' . $N . ', the section\'s own version — admits the section outright, which '
        . 'is the same admitting verdict the shipped engine now gives at its ceiling'
);

// THE OTHER REMEDY ARM, AND WHERE IT LIVES AFTER THE FLIP. `assert_section_
// versions()` has two remedies: "declare spec_version <since>" when the window
// reaches that version, and "this engine's window does not reach it" when it
// does not. The flip moved the shipped engine from the second arm into the
// first (PART 1 measures that), so the second arm is now only reachable BELOW
// this engine — which is exactly the rollback target. Probing N-1 is therefore
// not a synthetic curiosity: it is what a v2 agent tells an operator about a
// v3-declaring manifest, and it is the reason § v3.12 files the first
// v3-stamped manifest with the acts G3 forbids.
$priorChild = $run([PHP_BINARY, __FILE__, '--probe', (string) ($N - 1)]);
duo_check_same(0, $priorChild['exit'], 'the ROLLBACK-engine probe (N-1) exits 0 (stderr: ' . trim($priorChild['stderr']) . ')');
$prior = json_decode($priorChild['stdout'], true);
duo_check(is_array($prior), 'and prints one decodable report');
$prior = is_array($prior) ? $prior : ['verdicts' => [], 'accepted' => []];
$report('rollback engine DUO_SPEC_VERSION: ' . ($N - 1) . '; accepted: {' . implode(', ', (array) $prior['accepted']) . '}');
duo_check_same(
    [$N - 2, $N - 1],
    (array) $prior['accepted'],
    'the N-1 engine accepts {' . ($N - 2) . ', ' . ($N - 1) . '} — so a manifest re-stamped to ' . $N
        . ' is outside its window entirely, which is the one-way half of the flag day'
);
$unreached = (string) $prior['verdicts']['floor_with_section'];
duo_check(
    str_contains($unreached, "declares spec_version " . ($N - 2) . " and the section 'engine_features'")
        && str_contains($unreached, "this engine's window does not reach spec_version " . $sectionSince),
    'and it gives the OTHER remedy — the window does not reach the section at all — which is the arm the flip '
        . 'retired on the shipped engine and did not delete'
);
duo_check_detail('rollback-engine remedy: ' . $unreached);

foreach ([
    'since_empty' => 'an empty list',
    'since_unsorted' => 'an unsorted list',
    'since_duplicated' => 'a list with a duplicate',
    'since_not_a_list' => 'a JSON object rather than a list',
    'since_non_string' => 'a non-string member',
    'since_scalar' => 'a bare string rather than a list',
] as $label => $what) {
    $refusal = (string) $future['verdicts'][$label];
    duo_check(
        str_contains($refusal, "declares 'engine_features'")
            && str_contains($refusal, 'non-empty, sorted, duplicate-free list of engine feature name strings'),
        "SHAPE BEFORE VOCABULARY: $what refuses on shape, naming the key ($label)"
    );
    duo_check(
        !str_contains($refusal, "\n"),
        "...and the refusal is one line, so it survives a WP-CLI error and a harness that pins it ($label)"
    );
}

echo "\nPART 3 — the surfaces that publish the window\n";

// Per-manifest isolation, measured through the product path rather than
// claimed: `duo manifest-validate` loads each manifest on its own, so the
// neighbours of an offending adapter are judged and reported in the same run.
$scratch = sys_get_temp_dir() . '/duo_regress_spec_window_' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
register_shutdown_function(static function () use ($scratch): void {
    foreach (glob($scratch . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($scratch);
});
Canon::write_file($scratch . '/acme-clean.json', Canon::encode([
    'name' => 'acme-clean',
    'options' => ['acme_clean_setting' => ['class' => 'authored', 'autoload' => 'yes']],
    'spec_version' => $N,
]));
// Stamped at N-1, not N: after the flip a v-N manifest declaring the section is
// ADMITTED (PART 1), so the offending neighbour has to be the one that is still
// refused — a manifest sitting where the whole shipped library sits, reaching
// for a section its own declared version does not have.
Canon::write_file($scratch . '/acme-staged.json', Canon::encode([
    'engine_features' => [$shipped['implemented_features'][0]],
    'name' => 'acme-staged',
    'options' => ['acme_staged_setting' => ['class' => 'authored', 'autoload' => 'yes']],
    'spec_version' => $N - 1,
]));
$validated = $run([PHP_BINARY, $repo . '/cli/duo', 'manifest-validate', $scratch]);
duo_check(
    str_contains($validated['stdout'], '[ok] acme-clean')
        && str_contains($validated['stdout'], '[error] acme-staged'),
    'BLAST RADIUS: one offending adapter in a two-adapter set is reported [error] and its neighbour [ok] in the same run'
);
duo_check(
    str_contains($validated['stdout'], "the section 'engine_features'"),
    '...and the error row carries the section name, so the operator knows which declaration to remove'
);
duo_check(
    $validated['exit'] !== 0,
    '...and the RUN still fails, because a pin set holding an unloadable adapter is not a passing check'
);
duo_check_detail('manifest-validate exit ' . $validated['exit']
    . ' (stderr: ' . trim($validated['stderr']) . ')');

// The emitted grammar. WP-4.1 made this block MEASURED by probing the shipped
// refusal precisely so that it would follow the window with no edit to the
// emitter. That it did is checked here, not assumed.
$emitted = $run([PHP_BINARY, $repo . '/cli/duo', 'manifest-validate', '--emit-schema']);
duo_check_same(0, $emitted['exit'], '`duo manifest-validate --emit-schema` exits 0');
$schema = json_decode($emitted['stdout'], true);
$window = is_array($schema) ? (array) ($schema['spec_window'] ?? []) : [];
duo_check_same(
    [$N - 1, $N],
    $window['accepted'] ?? null,
    'duo-manifest-grammar/v2\'s `spec_window` reports {' . ($N - 1) . ', ' . $N . '} accepted — derived, so it moved with the engine and not with an edit'
);
duo_check_same(true, $window['n_minus_1_accepted'] ?? null, 'and `n_minus_1_accepted` turned from false to true, which is the fact WP-4.1 published so this rider could not land silently');
duo_check(
    is_string($window['status'] ?? null) && str_contains((string) $window['status'], 'is ENFORCED here'),
    'and its status line no longer says the window is specified-but-not-enforced'
);

echo "\nPART 4 — the release gate: the floor cannot accumulate\n";

$gate = $run([PHP_BINARY, $repo . '/tools/wire-surface.php', '--check']);
duo_check_same(0, $gate['exit'], '`php tools/wire-surface.php --check` — a make release-gate step — passes on the shipped tree');
duo_check(
    str_contains((string) file_get_contents($repo . '/docs/wire-surface.md'), '### R-18 — The `spec_version` acceptance window is exactly {N-1, N}'),
    'and the register carries R-18, the row that records the floor as exactly DUO_SPEC_VERSION - 1'
);

// A gate that never bites is theatre. Widen the window to N-2 in a COPY of the
// shipped trees and require the same command to refuse it.
$mutantRoot = sys_get_temp_dir() . '/duo_regress_spec_window_gate_' . bin2hex(random_bytes(4));
$copyTree = static function (string $src, string $dst) use (&$copyTree): void {
    @mkdir($dst, 0777, true);
    foreach (scandir($src) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        is_dir("$src/$entry") ? $copyTree("$src/$entry", "$dst/$entry") : copy("$src/$entry", "$dst/$entry");
    }
};
$removeTree = static function (string $dir) use (&$removeTree): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        is_dir("$dir/$entry") ? $removeTree("$dir/$entry") : @unlink("$dir/$entry");
    }
    @rmdir($dir);
};
register_shutdown_function(static fn() => $removeTree($mutantRoot));
foreach (['agent', 'cli', 'recovery'] as $tree) {
    $copyTree($repo . '/' . $tree, $mutantRoot . '/' . $tree);
}
@mkdir($mutantRoot . '/docs', 0777, true);
@mkdir($mutantRoot . '/tools', 0777, true);
copy($repo . '/docs/wire-surface.md', $mutantRoot . '/docs/wire-surface.md');
copy($repo . '/tools/wire-surface.php', $mutantRoot . '/tools/wire-surface.php');
// WP-4.8's gate 6 reads the SHIPPED authorities document under --root, so the
// copy must carry it or every --check below refuses on that gate before the
// window gate's verdict is even reachable for the baseline/restore cases. The
// file, not the whole manifests tree: this suite's subject is the window, and
// the one document gate 6 names is the one document the copy needs.
@mkdir($mutantRoot . '/manifests/capabilities', 0777, true);
copy(
    $repo . '/manifests/capabilities/adapter-authorities.json',
    $mutantRoot . '/manifests/capabilities/adapter-authorities.json'
);
// WP-4.10's gate 7 reads the flat adapter set under --root for the same
// reason, and for the same cost: without it the copy looks like a library with
// zero adapters and every --check refuses on the grandfather list's membership
// before the window gate is reached. The flat `manifests/*.json` glob is
// exactly what that gate enumerates — no interpreters, providers or
// regenerators, none of which it reads.
foreach (glob($repo . '/manifests/*.json') ?: [] as $shippedManifest) {
    copy($shippedManifest, $mutantRoot . '/manifests/' . basename($shippedManifest));
}

$baseline = $run([PHP_BINARY, $mutantRoot . '/tools/wire-surface.php', '--check', '--root=' . $mutantRoot]);
duo_check_same(0, $baseline['exit'], 'the untouched copy passes the same check, so any refusal below is the mutation and nothing else');

// WP-4.12 moved the window's one definition down a layer. It used to live in
// AdapterContractGrammar; `site.duo.json` carries the same integer and
// `RepositoryCompiler` (layer 3) cannot reference the grammar (layer 5), so
// the definition is now `SpecVersionWindow` in the kernel and the grammar
// delegates to it. The mutation therefore has to hit the kernel file — and
// that is the point of mutating rather than reading: one definition means one
// file to corrupt, and the gate has to notice from the far side of the
// delegation.
$grammarFile = $mutantRoot . '/agent/src/Kernel/SpecVersionWindow.php';
$grammarSource = (string) file_get_contents($grammarFile);
$anchor = 'return [$supported - 1, $supported];';
duo_check(str_contains($grammarSource, $anchor), 'the window\'s one definition is present in the copied engine\'s kernel');
file_put_contents($grammarFile, str_replace($anchor, 'return [$supported - 2, $supported - 1, $supported];', $grammarSource));
$widened = $run([PHP_BINARY, $mutantRoot . '/tools/wire-surface.php', '--check', '--root=' . $mutantRoot]);
duo_check(
    $widened['exit'] !== 0 && str_contains($widened['stderr'], 'row R-18 records the floor as exactly DUO_SPEC_VERSION - 1'),
    'GATE BITES: a copy that also accepts N-2 is REFUSED by the release-gate check, naming the row — N-2 cannot accumulate by inattention'
);
duo_check_detail('gate refusal: ' . trim($widened['stderr']));

file_put_contents($grammarFile, str_replace($anchor, 'return [$supported];', $grammarSource));
$narrowed = $run([PHP_BINARY, $mutantRoot . '/tools/wire-surface.php', '--check', '--root=' . $mutantRoot]);
duo_check(
    $narrowed['exit'] !== 0 && str_contains($narrowed['stderr'], 'not {' . ($N - 1) . ', ' . $N . '}'),
    'and a copy that NARROWED back to exact equality is refused too — the gate is an equality, so it catches a silent removal of the window as well as a silent widening'
);

file_put_contents($grammarFile, $grammarSource);
duo_check_same(
    0,
    $run([PHP_BINARY, $mutantRoot . '/tools/wire-surface.php', '--check', '--root=' . $mutantRoot])['exit'],
    'restoring the copy restores the gate — every mutation stayed inside the scratch tree'
);
duo_check_same(
    0,
    $run([PHP_BINARY, $repo . '/tools/wire-surface.php', '--check'])['exit'],
    'and the real tree still passes, which is the assertion that proves the mutations never touched it'
);

duo_check_summary('spec window and engine features');
