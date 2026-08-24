<?php
/**
 * The closed top-level manifest key set (spec/repo-format.md § v3.3; WP-4.3).
 *
 * WHY THIS SUITE EXISTS
 * ---------------------
 * The set was already maintained and already refused — in ONE place, the
 * signer's classify-or-throw loop in `AdapterCertification::siteRatification()`.
 * `ManifestValidator` did not consult it, so a manifest carrying
 * `totally_made_up_section` and a transposed `optoins` validated `[ok]` and was
 * then unsignable: measured, both halves, in `regress_spec_v3_dry_run.php` under
 * rule V3-KEYS, and reproduced here through `duo manifest-validate` itself. The
 * cost of that silence is the one `ManifestGrammar.php:50-56` already states one
 * level down for a table `class` value — an unrecognised declaration that means
 * nothing is indistinguishable from a deliberate one, which is how a whole
 * plugin's authored rows go missing from canonical state because of one
 * transposed letter.
 *
 * WHY IT PROBES TWO ENGINES, AND TWO TREES
 * ----------------------------------------
 * `DUO_SPEC_VERSION` is 2 and stays 2 — the flip is WP-4.12's alone — and the
 * rule is gated at `spec_version: 3`, so on the shipped engine it is unreachable
 * through the product path by construction: § v3.1 refuses a `spec_version` 3
 * manifest wholesale one step before the key check. That is the correct shipped
 * state and PART 1 asserts it. The rule's own behaviour therefore has to be read
 * at a v3 engine, and this file builds one two ways, each answering a question
 * the other cannot:
 *
 *   - a CHILD PROCESS of this file that defines `DUO_SPEC_VERSION` as N+1 before
 *     loading the same shipped grammar (PART 2). A spec version is a `define()`
 *     and a PHP process holds one of those — the argument
 *     `regress_spec_window.php` states for the identical technique. Cheap, so
 *     the whole verdict matrix runs here.
 *   - a COPY of the shipped trees whose two `define()` lines and
 *     `platform.json` restatement are moved together, AGENTS.md rule 8's atomic
 *     pair, driven by the real `duo manifest-validate` (PART 3). Slower, so it
 *     runs the one case that has to be seen end to end: the empirical baseline
 *     of this rider, `[ok]` at v2 and `[error]` at v3 on the SAME fixture bytes.
 *
 * WHAT THE PARTS PROVE
 * --------------------
 *   PART 1 — the shipped engine. Every one of the 16 shipped manifests still
 *   loads, and a v2 manifest declaring `totally_made_up_section` and `optoins`
 *   still loads: the open v2 era is unchanged, which is what makes this rider
 *   digest-neutral. The partition and the validator's admitted set are the same
 *   set, both directions.
 *
 *   PART 2 — a synthetic N+1 engine, verdict by verdict. Every one of the 33
 *   partition keys is admitted individually; an unclaimed unknown key refuses
 *   NAMING THE KEY; a key claimed by a declared feature the engine IMPLEMENTS is
 *   admitted; a key claimed by a declared feature it does NOT implement refuses
 *   naming the FEATURE, never the key. Those three verdicts are § v3.3's whole
 *   growth rule and the suite exists to keep them distinct. `_draft` gets its
 *   own verdict and its own remedy, and `theme_version_range` — resolution 1 —
 *   is admitted at load AND classifiable by the signer, which is the pair that
 *   was broken.
 *
 *   PART 3 — the product path, both eras, one fixture directory.
 *
 *   PART 4 — the release gate. `php tools/wire-surface.php --check` asserts the
 *   two sets are one set (row R-21). A gate that never bites is theatre, so it
 *   is also run against a COPY whose partition has had one key removed, and must
 *   refuse it naming that key.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 4);

/**
 * The verdict matrix, run identically against every engine version.
 *
 * One probe set, two eras: PART 1 runs it in this process against the shipped
 * define and PART 2 in a child that defines N+1, so a verdict that differs
 * between them differs because the ENGINE differs and for no other reason.
 *
 * The subject manifests are minimal on purpose. Every other check in
 * `validate_adapter_contract()` is keyed on a declaration they do not carry, so
 * the only verdicts measured are the version one and the key-set one — except
 * `theme`, which is carried WITH its mandatory companion precisely because the
 * pair is what resolution 1 fixed.
 *
 * @return array<string,mixed>
 */
function closed_keys_report(int $supported): array {
    $partition = \Duo\AdapterCertification::topLevelKeyPartition();
    $arms = array_merge(
        $partition['entity_sections'],
        $partition['field_sections'],
        $partition['non_surface_keys']
    );
    sort($arms, SORT_STRING);

    $implemented = \Duo\AdapterContractGrammar::implemented_features();
    $feature = $implemented[0] ?? 'none/v0';
    $featureKeys = \Duo\AdapterContractGrammar::admitted_feature_keys(['engine_features' => [$feature]]);

    $verdict = static function (array $manifest): ?string {
        try {
            \Duo\AdapterContractGrammar::validate_adapter_contract($manifest);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    };
    // `name` and `spec_version` are themselves partition members, so the base
    // manifest declares nothing the rule has an opinion about.
    $named = static fn(array $extra = []): array
        => ['name' => 'keys-probe', 'spec_version' => $supported] + $extra;

    // One probe per partition key: a set that is closed must admit every member
    // of itself, one at a time, or the gate in PART 4 is comparing two lists
    // that agree while the refusal consults a third.
    $perKey = [];
    foreach ($arms as $key) {
        $value = match ($key) {
            // The two subject keys carry their mandatory companion, since
            // declaring either alone refuses for a reason that is not this rule.
            'plugin' => 'acme/acme.php',
            'theme' => 'acme',
            'version_range', 'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
            'interpreter' => 'acme',
            'name', 'note' => 'keys-probe',
            'spec_version' => $supported,
            default => [],
        };
        $subject = $named([$key => $value]);
        if ($key === 'plugin') {
            $subject['version_range'] = ['min' => '1.0.0', 'max' => '2.0.0'];
        }
        if ($key === 'theme') {
            $subject['theme_version_range'] = ['min' => '1.0.0', 'max' => '2.0.0'];
        }
        $perKey[$key] = $verdict($subject);
    }

    $probes = [
        'bare' => $named(),
        // WP-4.3's named case, verbatim from regress_spec_v3_dry_run.php's
        // `fixture:typo-and-invented-section`: an invented section and one
        // transposed letter in `options`.
        'typo_and_invented' => $named([
            'options' => ['acme_a_setting' => ['class' => 'authored', 'autoload' => 'yes']],
            'optoins' => ['acme_b_setting' => ['class' => 'authored', 'autoload' => 'yes']],
            'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
        ]),
        'one_unknown' => $named(['totally_made_up_section' => ['acme_thing' => ['class' => 'authored']]]),
        // What `duo adapter-draft` actually hands an author (AdapterDraft.php:379).
        'draft_sidecar' => $named(['_draft' => ['proposals' => [], 'evidence' => []]]),
        // The growth rule, both halves. `engine_features` is in NO arm of the
        // partition, so the first of these is admitted ONLY by the feature that
        // claims it — which is what makes it the worked example § v3.3 names.
        'feature_claimed' => $named(['engine_features' => [$feature]]),
        'feature_unimplemented' => $named(['engine_features' => ['acme-thing/v1']]),
        // Resolution 1's subject: a theme adapter, which validated and was then
        // unsignable while its mandatory companion sat in no arm.
        'theme_adapter' => [
            'name' => 'acme-theme',
            'spec_version' => $supported,
            'theme' => 'acme',
            'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
            'options' => ['acme_theme_setting' => ['class' => 'authored', 'autoload' => 'yes']],
        ],
    ];
    $verdicts = [];
    foreach ($probes as $label => $manifest) {
        $verdicts[$label] = $verdict($manifest);
    }

    return [
        'engine_supported' => $supported,
        'partition' => $arms,
        'admitted_base' => \Duo\AdapterContractGrammar::admitted_top_level_keys([]),
        'admitted_with_feature' => \Duo\AdapterContractGrammar::admitted_top_level_keys(
            ['engine_features' => [$feature]]
        ),
        'feature' => $feature,
        'feature_keys' => $featureKeys,
        'per_key' => $perKey,
        'verdicts' => $verdicts,
    ];
}

// ---------------------------------------------------------------------------
// CHILD MODE. `php <this file> --probe <N>` loads the SHIPPED grammar under a
// synthetic DUO_SPEC_VERSION and prints the verdict matrix as JSON. It runs
// before check.php is required and exits before any assertion, so the child
// contributes no counted checks and no output the corpus diagnostics guard
// reads.
// ---------------------------------------------------------------------------
$closedKeysArgv = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];
if (($closedKeysArgv[1] ?? '') === '--probe') {
    define('DUO_SPEC_VERSION', (int) ($closedKeysArgv[2] ?? 0));
    if (!defined('DUO_AGENT_VERSION')) {
        define('DUO_AGENT_VERSION', '0.0.0');
    }
    require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
    require_once $repo . '/agent/src/Adapter/AdapterCertification.php';
    echo json_encode(closed_keys_report(DUO_SPEC_VERSION), JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
}

require_once __DIR__ . '/../../lib/check.php';

// The engine's own defines, read out of agent/duo.php the way every other
// offline suite that needs them does — never a literal, so this file says
// nothing about which integer N happens to be.
$duoSource = (string) file_get_contents($repo . '/agent/duo.php');
if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $duoSource, $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('DUO_SPEC_VERSION', (int) $m[1]);
preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $duoSource, $agentMatch);
define('DUO_AGENT_VERSION', (string) ($agentMatch[1] ?? '0.0.0'));

require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Kernel/OptionState.php';
require_once $repo . '/agent/src/Kernel/Db.php';
require_once $repo . '/agent/src/Adapter/AdapterSources.php';
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterCertification.php';

use Duo\AdapterCertification;
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

$N = DUO_SPEC_VERSION;

// ===========================================================================
echo "\nPART 1 — the SHIPPED engine: v2 is open, and stays open byte for byte\n";
// ===========================================================================

$shipped = closed_keys_report($N);
$report('engine DUO_SPEC_VERSION: ' . $N . '; partition: ' . count($shipped['partition']) . ' keys');
$report('implemented engine features: ' . implode(', ', AdapterContractGrammar::implemented_features()));

// THE EMPIRICAL BASELINE THIS RIDER TURNS ON. A v2 manifest declaring an
// invented section and a transposed one loads, exactly as it did before this
// rider, and that is not an oversight being tolerated — it is the flag-day
// invariant. Any other answer here would change how a shipped manifest is read.
duo_check_same(
    null,
    $shipped['verdicts']['typo_and_invented'],
    'v2 IS OPEN: a spec_version ' . $N . ' manifest declaring `totally_made_up_section` and a transposed '
        . '`optoins` still loads — the behaviour WP-4.3 leaves untouched, which is what makes it digest-neutral'
);
duo_check_same(
    null,
    $shipped['verdicts']['draft_sidecar'],
    '...and so does `duo adapter-draft` output carrying `_draft`, at v2'
);

// Digest neutrality, measured over the library rather than argued.
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
duo_check_same(
    [],
    $refused,
    'every one of the ' . count($library) . ' shipped manifests still passes the contract grammar — the closed '
        . 'key set refuses none of the library'
);
duo_check_same(
    [$N => true],
    $declaredVersions,
    'and every one still declares spec_version ' . $N . ', so the rule is gated OUT of the whole shipped library'
);

// The union in use against the partition, in both directions. The measurement
// WP-1.6 owed and this rider consumes: a shipped key the partition does not
// know would be a v3 refusal on the flag day, and the answer must be none.
$union = [];
foreach ($library as $manifest) {
    foreach (array_keys($manifest) as $key) {
        $union[(string) $key] = true;
    }
}
$unionKeys = array_keys($union);
sort($unionKeys, SORT_STRING);
duo_check_same(
    [],
    array_values(array_diff($unionKeys, $shipped['partition'])),
    'no shipped manifest declares a top-level key the closed set does not know, so the flag day refuses zero '
        . 'shipped adapters for this rule'
);
$report('in-use union: ' . count($unionKeys) . ' keys; closed set: ' . count($shipped['partition'])
    . '; admitted but undeclared: ' . implode(', ', array_diff($shipped['partition'], $unionKeys)));

// ONE SET, NOT TWO — asserted in this process as well as at the release gate,
// because the gate proves it for the shipped tree and this proves the accessor
// a reader would reach for answers the same.
duo_check_same(
    $shipped['partition'],
    $shipped['admitted_base'],
    'ONE DEFINITION: the validator\'s admitted set with no features declared IS the signer\'s partition, key '
        . 'for key — not a copy that agrees today'
);
duo_check_same(
    count($shipped['partition']),
    count(array_unique($shipped['partition'])),
    'and the three arms are disjoint, so a key\'s arm — which decides what a derived ratification says about '
        . 'it — is never a function of iteration order'
);

// Resolution 1: the pair that was broken. `theme` was admitted and its mandatory
// companion was not, so a theme adapter validated and was then unsignable.
duo_check(
    in_array('theme', $shipped['partition'], true) && in_array('theme_version_range', $shipped['partition'], true),
    'RESOLUTION 1: the partition now knows `theme_version_range` beside `theme` — the companion the shipped '
        . 'grammar already made mandatory'
);
$ratify = (new ReflectionClass(AdapterCertification::class))->getMethod('siteRatification');
$signerVerdict = static function (string $name, array $manifest) use ($ratify): ?string {
    try {
        $ratify->invoke(null, $name, $manifest, 'closed key set suite');
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
$themeAdapter = [
    'name' => 'acme-theme',
    'spec_version' => $N,
    'theme' => 'acme',
    'theme_version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'options' => ['acme_theme_setting' => ['class' => 'authored', 'autoload' => 'yes']],
];
duo_check_same(
    null,
    $signerVerdict('acme-theme', $themeAdapter),
    '...so a theme adapter is now SIGNABLE: the signer classifies every key it declares, which it could not '
        . 'do while the companion sat in no arm'
);
// The classify-or-throw loop is still live for a key that really is in no arm —
// admitting one key must not have widened the signer into admitting anything.
$unclassifiable = $themeAdapter;
$unclassifiable['totally_made_up_section'] = ['acme_thing' => ['class' => 'authored']];
duo_check(
    str_contains(
        (string) $signerVerdict('acme-theme', $unclassifiable),
        'which this signer cannot classify'
    ),
    '...while the signer still refuses a key that IS in no arm, by name'
);

// ===========================================================================
echo "\nPART 2 — a synthetic N+1 engine: the three verdicts, kept distinct\n";
// ===========================================================================

$child = $run([PHP_BINARY, __FILE__, '--probe', (string) ($N + 1)]);
duo_check_same(0, $child['exit'], 'the child probe process exits 0 (stderr: ' . trim($child['stderr']) . ')');
$v3 = json_decode($child['stdout'], true);
duo_check(is_array($v3), 'and prints one decodable verdict matrix');
$v3 = is_array($v3) ? $v3 : ['verdicts' => [], 'per_key' => [], 'partition' => [], 'admitted_base' => []];

$report('synthetic engine DUO_SPEC_VERSION: ' . ($N + 1) . '; closed set: '
    . count((array) $v3['partition']) . ' keys');

duo_check_same(
    $shipped['partition'],
    $v3['partition'],
    'the set does not move with the engine version: the same partition, read at both eras'
);
duo_check_same(null, $v3['verdicts']['bare'], 'a spec_version ' . ($N + 1) . ' manifest declaring only `name` and `spec_version` loads');

// VERDICT 1 — a key nothing claims refuses AS A TYPO, NAMING THE KEY.
$invented = (string) $v3['verdicts']['typo_and_invented'];
duo_check(
    str_contains($invented, "'optoins'") && str_contains($invented, "'totally_made_up_section'")
        && str_contains($invented, 'does not recognise')
        && str_contains($invented, 'spec/repo-format.md § v3.3'),
    'VERDICT 1 (unclaimed unknown key): the SAME manifest that loads at v' . $N . ' refuses at v' . ($N + 1)
        . ', naming BOTH offending keys and the rule'
);
duo_check(
    str_contains($invented, 'correct the spelling'),
    '...and the remedy names the likely cause — a misspelling — rather than only stating the rule'
);
duo_check(!str_contains($invented, "\n"), '...and is one line, so it survives a WP-CLI error and a harness that pins it');
duo_check_detail('unknown-key refusal: ' . $invented);
duo_check(
    str_contains((string) $v3['verdicts']['one_unknown'], "the top-level key 'totally_made_up_section'"),
    '...and a single offending key is reported in the singular, so the sentence reads as the author\'s case'
);

// VERDICT 2 — a key claimed by a declared, IMPLEMENTED feature is ADMITTED.
duo_check_same(
    ['engine_features'],
    $v3['feature_keys'],
    'the one implemented feature (`' . $v3['feature'] . '`) claims exactly the `engine_features` key'
);
duo_check(
    !in_array('engine_features', (array) $v3['partition'], true),
    'and `engine_features` is in NO arm of the partition, which is what makes it the growth rule\'s worked example'
);
duo_check_same(
    null,
    $v3['verdicts']['feature_claimed'],
    'VERDICT 2 (declared + implemented): a manifest declaring `engine_features: ["' . $v3['feature']
        . '"]` loads, and the key that feature claims is admitted BESIDE the partition rather than inside it'
);
$expectedWithFeature = array_values(array_unique(array_merge((array) $v3['partition'], ['engine_features'])));
sort($expectedWithFeature, SORT_STRING);
duo_check_same(
    $expectedWithFeature,
    $v3['admitted_with_feature'],
    '...and the admitted set for THAT manifest is the partition plus exactly that key — the growth rule adds '
        . 'nothing else'
);

// VERDICT 3 — a key claimed by a declared UNIMPLEMENTED feature refuses BY
// FEATURE NAME. The ordering inside validate_adapter_contract() is what keeps
// this from collapsing into verdict 1, and collapsing them would tell an author
// to fix a spelling that is correct.
$unimplemented = (string) $v3['verdicts']['feature_unimplemented'];
duo_check(
    str_contains($unimplemented, "declares engine feature 'acme-thing/v1'")
        && str_contains($unimplemented, 'this engine does not implement it'),
    'VERDICT 3 (declared, unimplemented): refuses naming the FEATURE'
);
duo_check(
    !str_contains($unimplemented, 'does not recognise') && !str_contains($unimplemented, '§ v3.3'),
    '...and NOT as an unrecognised key: the feature channel answers first, so an author is never told to fix a '
        . 'spelling that is correct'
);
duo_check_detail('unimplemented-feature refusal: ' . $unimplemented);

// `_draft`, resolution 2: refused, with the one remedy that is not "declare a
// feature" — an author may not mint a feature name (§ v3.2), so pointing them at
// the channel would be pointing them nowhere.
$draft = (string) $v3['verdicts']['draft_sidecar'];
duo_check(
    str_contains($draft, "the top-level key '_draft'") && str_contains($draft, 'strip the `_draft` key before install'),
    'RESOLUTION 2: `_draft` refuses at v' . ($N + 1) . ' and its remedy is to STRIP it, not to declare anything'
);
duo_check(
    str_contains($draft, 'unreviewed proposals inside the identity row every certificate covers'),
    '...and says why admitting it would be wrong on the merits, not merely that it is unknown'
);
duo_check_detail('draft-sidecar refusal: ' . $draft);

// Resolution 1 at the era that has the rule: the theme adapter loads.
duo_check_same(
    null,
    $v3['verdicts']['theme_adapter'],
    'RESOLUTION 1 at v' . ($N + 1) . ': a theme adapter declaring `theme` + `theme_version_range` loads, so the '
        . 'key that joined the partition is admitted by the rule that reads it'
);

// EVERY member of the closed set, one at a time. A set that is closed must admit
// itself; anything less means the refusal consults a list the gate does not see.
$rejectedMembers = [];
foreach ((array) $v3['per_key'] as $key => $verdict) {
    if (is_string($verdict) && str_contains($verdict, 'does not recognise')) {
        $rejectedMembers[(string) $key] = $verdict;
    }
}
duo_check_same(
    [],
    $rejectedMembers,
    'all ' . count((array) $v3['per_key']) . ' members of the closed set are admitted individually at v'
        . ($N + 1) . ' — the set admits itself, key by key'
);

// ===========================================================================
echo "\nPART 3 — the product path, both eras, one fixture directory\n";
// ===========================================================================

$scratch = sys_get_temp_dir() . '/duo_regress_closed_keys_' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
$mutantRoot = sys_get_temp_dir() . '/duo_regress_closed_keys_v3_' . bin2hex(random_bytes(4));
$gateRoot = sys_get_temp_dir() . '/duo_regress_closed_keys_gate_' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($scratch, $mutantRoot, $gateRoot, $removeTree): void {
    $removeTree($scratch);
    $removeTree($mutantRoot);
    $removeTree($gateRoot);
});

// The declared name must equal the file name (AdapterSources::assert_name()),
// so the two fixtures differ ONLY in their `spec_version` and their name.
$fixtureBody = static fn(string $name, int $spec): array => [
    'name' => $name,
    'optoins' => ['acme_b_setting' => ['class' => 'authored', 'autoload' => 'yes']],
    'options' => ['acme_a_setting' => ['class' => 'authored', 'autoload' => 'yes']],
    'plugin' => 'acme/acme.php',
    'spec_version' => $spec,
    'totally_made_up_section' => ['acme_thing' => ['class' => 'authored']],
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
];
Canon::write_file($scratch . '/acme-at-v2.json', Canon::encode($fixtureBody('acme-at-v2', $N)));
Canon::write_file($scratch . '/acme-at-v3.json', Canon::encode($fixtureBody('acme-at-v3', $N + 1)));

$shippedRun = $run([PHP_BINARY, $repo . '/cli/duo', 'manifest-validate', $scratch]);
duo_check(
    str_contains($shippedRun['stdout'], '[ok] acme-at-v2'),
    'THE BASELINE, THROUGH THE PRODUCT PATH: on the shipped engine `duo manifest-validate` still reports [ok] '
        . 'for a v' . $N . ' manifest carrying `totally_made_up_section`'
);
duo_check(
    str_contains($shippedRun['stdout'], '[error] acme-at-v3')
        && str_contains($shippedRun['stdout'], 'accepts spec_version {' . ($N - 1) . ', ' . $N . '}'),
    '...and the v' . ($N + 1) . ' sibling refuses on the WINDOW (§ v3.1), never on the key set — which is why '
        . 'the rule is unreachable on the shipped engine'
);

// The same fixture bytes at a v3 engine. Both defines move together with
// platform.json's restatement, AGENTS.md rule 8's atomic pair, because
// ManifestDispositions::platform_boundary() refuses the moment they disagree.
foreach (['agent', 'cli', 'recovery', 'manifests'] as $tree) {
    $copyTree($repo . '/' . $tree, $mutantRoot . '/' . $tree);
}
$mutantDuo = (string) file_get_contents($mutantRoot . '/agent/duo.php');
file_put_contents($mutantRoot . '/agent/duo.php', (string) preg_replace(
    "/define\('DUO_SPEC_VERSION', $N\)/",
    "define('DUO_SPEC_VERSION', " . ($N + 1) . ')',
    $mutantDuo
));
$mutantPlatform = (string) file_get_contents($mutantRoot . '/manifests/capabilities/platform.json');
file_put_contents($mutantRoot . '/manifests/capabilities/platform.json', (string) preg_replace(
    '/"spec_version": ' . $N . '/',
    '"spec_version": ' . ($N + 1),
    $mutantPlatform
));
$v3Run = $run([PHP_BINARY, $mutantRoot . '/cli/duo', 'manifest-validate', $scratch]);
duo_check(
    str_contains($v3Run['stdout'], 'spec_version:  ' . ($N + 1)),
    'the copied tree really is a v' . ($N + 1) . ' engine (both defines moved with platform.json)'
);
duo_check(
    str_contains($v3Run['stdout'], '[ok] acme-at-v2'),
    'AND THE INVARIANT HOLDS ACROSS THE FLIP: the v' . $N . ' manifest is STILL [ok] on the v' . ($N + 1)
        . ' engine — the window accepts it and the closed set is gated out of it'
);
duo_check(
    str_contains($v3Run['stdout'], '[error] acme-at-v3')
        && str_contains($v3Run['stdout'], "'totally_made_up_section'"),
    'THE FLIP ITSELF: the identical declaration at spec_version ' . ($N + 1) . ' is [error], naming the key'
);
duo_check($v3Run['exit'] !== 0, '...and the run fails, because a pin set holding an unloadable adapter is not a passing check');
duo_check_detail('v' . ($N + 1) . ' manifest-validate exit ' . $v3Run['exit']);

// ===========================================================================
echo "\nPART 4 — the release gate: one definition, proven to bite\n";
// ===========================================================================

$gate = $run([PHP_BINARY, $repo . '/tools/wire-surface.php', '--check']);
duo_check_same(0, $gate['exit'], '`php tools/wire-surface.php --check` — a make release-gate step — passes on the shipped tree');
duo_check(
    str_contains(
        (string) file_get_contents($repo . '/docs/wire-surface.md'),
        '### R-21 — The top-level manifest key set is closed at `spec_version: 3`, from one definition'
    ),
    'and the register carries R-21, the row that records the set as a one-way door with one definition'
);

// A gate that never bites is theatre. Remove one key from the SIGNER's arm in a
// copy and require the same command to refuse — this is the exact drift the
// gate exists for: the validator would still admit the key (it reads the
// accessor, so in fact both move together), so the copy also plants the second
// list the rule forbids, by pinning the removed key into the validator.
foreach (['agent', 'cli', 'recovery'] as $tree) {
    $copyTree($repo . '/' . $tree, $gateRoot . '/' . $tree);
}
@mkdir($gateRoot . '/docs', 0777, true);
@mkdir($gateRoot . '/tools', 0777, true);
copy($repo . '/docs/wire-surface.md', $gateRoot . '/docs/wire-surface.md');
copy($repo . '/tools/wire-surface.php', $gateRoot . '/tools/wire-surface.php');
// Gate 6 reads the SHIPPED authorities document under --root, so the copy must
// carry it or every --check below refuses on that gate before this one is
// reached.
@mkdir($gateRoot . '/manifests/capabilities', 0777, true);
copy(
    $repo . '/manifests/capabilities/adapter-authorities.json',
    $gateRoot . '/manifests/capabilities/adapter-authorities.json'
);

$baseline = $run([PHP_BINARY, $gateRoot . '/tools/wire-surface.php', '--check', '--root=' . $gateRoot]);
duo_check_same(0, $baseline['exit'], 'the unmutated copy passes, so a refusal below is the mutation and not the copy');

$certPath = $gateRoot . '/agent/src/Adapter/AdapterCertification.php';
$certSource = (string) file_get_contents($certPath);
file_put_contents($certPath, str_replace(
    "'spec_version', 'theme', 'theme_version_range', 'version_range',",
    "'spec_version', 'theme', 'version_range',",
    $certSource
));
$grammarPath = $gateRoot . '/agent/src/Adapter/AdapterContractGrammar.php';
$grammarSource = (string) file_get_contents($grammarPath);
file_put_contents($grammarPath, str_replace(
    '            self::admitted_feature_keys($manifest)',
    "            ['theme_version_range'],\n            self::admitted_feature_keys(\$manifest)",
    $grammarSource
));
$bitten = $run([PHP_BINARY, $gateRoot . '/tools/wire-surface.php', '--check', '--root=' . $gateRoot]);
duo_check($bitten['exit'] !== 0, 'THE GATE BITES: a validator holding a key the signer partition does not is refused');
duo_check(
    str_contains($bitten['stderr'] . $bitten['stdout'], 'are not the same set')
        && str_contains($bitten['stderr'] . $bitten['stdout'], 'theme_version_range'),
    '...naming the key and the rule — one definition, not two (row R-21)'
);
duo_check_detail('gate refusal: ' . trim($bitten['stderr'] . $bitten['stdout']));

duo_check_summary('closed top-level key set');
