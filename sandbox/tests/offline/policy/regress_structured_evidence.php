<?php
/**
 * `declaration_evidence` — the first POST-v3 grammar section, and the proof
 * that shipping one cost no version bump (spec/repo-format.md § v3.14, WP-6.4).
 *
 * WHAT THIS SUITE IS ACTUALLY FOR
 * ------------------------------
 * Not the section. The section is small and its grammar is closed; PART 3 walks
 * it verdict by verdict and that is the least interesting part of this file.
 * What is on trial is § v3.2's CLAIM — that a post-v3 primitive ships as a
 * feature name, a key that feature claims, and a refusal for the engine that
 * lacks it, with no integer moving anywhere. Before WP-6.4 that claim rested on
 * `spec-window/v1`, a feature whose only claimed key is the channel's own, which
 * is an argument about admissibility rather than a path something walks.
 * `declaration_evidence` is a genuinely new top-level section that did not exist
 * when v3 was cut, so PART 1's three verdicts are the claim being exercised for
 * real and PART 2 is the part that would have made all of it worthless:
 * `DUO_SPEC_VERSION` IS STILL 3, read out of `agent/duo.php` in this same run.
 * A channel that worked while the integer quietly moved would have demonstrated
 * the opposite of what it set out to.
 *
 * WHAT IT DELIBERATELY DOES NOT CLAIM
 * -----------------------------------
 * That the shipped library uses the section. It cannot, and PART 4 asserts the
 * absence rather than glossing it: a manifest byte is adapter identity
 * (AGENTS.md rule 2), so adopting the section across the 16 shipped adapters
 * would move all 16 digests and invalidate every pin and certificate naming one,
 * for what is a documentation change. So the declaration-to-rationale link is
 * gated two ways at once — `regress_shipped_option_declarations.php:237` keeps
 * its `str_contains($text, 'DUO-3509')` grep over `notes` prose for the shipped
 * library, and the schema check below covers fixtures and out-of-tree adapters.
 * PART 4 pins BOTH halves, including the grep it did not replace, because a
 * conversion that only half happened is worth less than a written statement of
 * which half is which.
 *
 * That an adapter carrying it can be certified. PART 5 pins the opposite, from
 * the shipped signer: a feature-claimed key has no arm in the partition, so
 * `siteRatification()` refuses it by name. § v3.3 states that posture in advance
 * and this file measures it rather than discovering it later from a missing
 * surface.
 *
 * WHY IT NEEDS NO SYNTHETIC ENGINE
 * --------------------------------
 * Its neighbours (`regress_spec_window.php`, `regress_closed_top_level_keys.php`)
 * run a child process at N+1 because the rule they measure sat above the shipped
 * engine's ceiling. This one does not: the whole point is that the section is
 * live on the SHIPPED engine, at the SHIPPED version, so every verdict here is
 * read through `duo manifest-validate` and the shipped grammar, in this process
 * and one child of the real CLI.
 */
declare(strict_types=1);

// From offline/policy/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$repo = dirname(__DIR__, 4);

// The engine's own defines, read out of agent/duo.php the way every other
// offline suite that needs them does — never a literal, so PART 2's assertion
// is a MEASUREMENT of the shipped tree and not a restatement of this file.
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
require_once $repo . '/agent/src/Kernel/ReferenceKindGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterSources.php';
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
require_once $repo . '/agent/src/Adapter/AdapterContractGrammar.php';
require_once $repo . '/agent/src/Adapter/AdapterCertification.php';
require_once $repo . '/agent/src/Adapter/StructuredEvidence.php';

use Duo\AdapterCertification;
use Duo\AdapterContractGrammar;
use Duo\Canon;
use Duo\ReferenceKindGrammar;
use Duo\StructuredEvidence;

$N = DUO_SPEC_VERSION;
$SECTION = StructuredEvidence::SECTION;
/** The feature that claims the section. Engine-owned, and permanent (R-19). */
const SE_FEATURE = 'structured-evidence/v1';
/** The feature that claims `engine_features` itself — needed to use the channel at all. */
const SE_CHANNEL_FEATURE = 'spec-window/v1';

/** One indented report row, indented so the diagnostics guard cannot read it as a PHP notice. */
$report = static function (string $line): void {
    echo '  ' . $line . "\n";
};

/** The shipped contract grammar's verdict on one manifest: null, or the refusal. */
$verdict = static function (array $manifest): ?string {
    try {
        AdapterContractGrammar::validate_adapter_contract($manifest);
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

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

// AGENTS.md rule 3: scratch never lands under `agent/` or `manifests/`, the two
// trees `sandbox/bin/pair.sh` bind-mounts and refuses dirty.
$scratch = $repo . '/sandbox/tmp/regress_structured_evidence_' . bin2hex(random_bytes(4));
mkdir($scratch, 0777, true);
register_shutdown_function(static function () use ($scratch, $removeTree): void {
    $removeTree($scratch);
});

/**
 * A minimal adapter that DECLARES an option, so a target has something to
 * address. The declared name must equal the file name
 * (`AdapterSources::assert_name()`), so callers pass both.
 *
 * @param list<string> $features
 * @param array<string,mixed> $extra
 * @return array<string,mixed>
 */
$adapter = static function (string $name, array $features, array $extra = []) use ($N): array {
    $base = [
        'name' => $name,
        'options' => ['acme_settings' => ['class' => 'authored', 'autoload' => 'yes']],
        'plugin' => 'acme/acme.php',
        'spec_version' => $N,
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ];
    if ($features !== []) {
        sort($features, SORT_STRING);
        $base['engine_features'] = $features;
    }

    return $base + $extra;
};

/** One well-formed record for `options.acme_settings`, carried by most fixtures below. */
$goodRecord = [
    'options.acme_settings' => [
        'evidence' => [[
            'source' => 'state/options/core.json',
            'locator' => 'acme_settings',
            'observation' => 'scalar in 12 captured files, no id positions',
        ]],
        'answered' => [[
            'question' => 'does any value carry a post id?',
            'answer' => 'no — the probe found 0 id positions across 12 rows',
        ]],
    ],
];

// ===========================================================================
echo "\nPART 1 — the three verdicts § v3.3's growth rule promises, on a section v3 did not have\n";
// ===========================================================================

$report('engine features implemented: ' . implode(', ', AdapterContractGrammar::implemented_features()));
$report('v3-only sections: ' . json_encode(AdapterContractGrammar::section_min_spec(), JSON_UNESCAPED_SLASHES));

duo_check(
    in_array(SE_FEATURE, AdapterContractGrammar::implemented_features(), true),
    'the engine implements `' . SE_FEATURE . '` — the vocabulary is engine-owned, so this is the only place '
        . 'the name can come from (R-19)'
);
duo_check_same(
    [$SECTION],
    AdapterContractGrammar::admitted_feature_keys(['engine_features' => [SE_FEATURE]]),
    '...and that feature claims exactly `' . $SECTION . '`, from the ONE constant that also decides its first '
        . 'spec_version — a feature implemented with its section unknown is the silent mis-read § v3.2 removes'
);

// VERDICT 1 — declared and implemented ADMITS.
duo_check_same(
    null,
    $verdict($adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [$SECTION => $goodRecord])),
    'VERDICT 1 (declared + implemented): the section is ADMITTED — a top-level key in NO arm of the signer\'s '
        . 'partition, loading only because a declared feature claims it'
);
duo_check(
    !in_array($SECTION, AdapterContractGrammar::admitted_top_level_keys([]), true),
    '...and it is admitted BESIDE the partition rather than inside it: with no feature declared the base '
        . 'admitted set does not contain it, which is what makes this the growth rule and not a widened partition'
);

// VERDICT 2 — declared but UNIMPLEMENTED refuses BY FEATURE NAME.
$unimplemented = (string) $verdict(
    $adapter('acme-evidence', [SE_CHANNEL_FEATURE, 'structured-evidence/v2'], [$SECTION => $goodRecord])
);
duo_check(
    str_contains($unimplemented, "declares engine feature 'structured-evidence/v2'")
        && str_contains($unimplemented, 'this engine does not implement it')
        && str_contains($unimplemented, 'spec/repo-format.md § v3.2'),
    'VERDICT 2 (declared, unimplemented): refuses naming the FEATURE — and `/vN` is the change channel, so a '
        . 'meaning that moves becomes a NEW name beside the old one rather than an edit of it'
);
duo_check(
    !str_contains($unimplemented, "'" . $SECTION . "'"),
    '...and does NOT name the section: the author\'s spelling is correct and their engine is old, which are '
        . 'two different remedies'
);
duo_check(
    str_contains($unimplemented, 'run an engine that has the feature'),
    '...so the remedy names the engine, not the manifest'
);
duo_check_detail('VERDICT 2: ' . $unimplemented);

// VERDICT 3 — UNDECLARED refuses as a typo, naming the key.
$typo = (string) $verdict($adapter('acme-evidence', [], [$SECTION => $goodRecord]));
duo_check(
    str_contains($typo, "the top-level key '" . $SECTION . "'")
        && str_contains($typo, 'does not recognise')
        && str_contains($typo, 'correct the spelling')
        && str_contains($typo, 'spec/repo-format.md § v3.3'),
    'VERDICT 3 (undeclared): refuses as a MISSPELLING, naming the key — the third verdict, distinct from both '
        . 'others, which is the property § v3.3 says has no fourth answer'
);
duo_check(
    str_contains($typo, 'this engine implements: ' . implode(', ', AdapterContractGrammar::implemented_features())),
    '...and enumerates the implemented feature names, so an author who meant to declare one can'
);
duo_check(!str_contains($typo, "\n"), '...and is one line, so it survives a WP-CLI error and a harness that pins it');
duo_check_detail('VERDICT 3: ' . $typo);

// The fourth arm of the same rule, at the OTHER end of the window: the section
// is v3-only, so the version that the whole shipped library still declares
// refuses it BY SECTION NAME (§ v3.1) before the feature question is asked.
$atFloor = $adapter('acme-evidence', [], [$SECTION => $goodRecord]);
$atFloor['spec_version'] = $N - 1;
$floorRefusal = (string) $verdict($atFloor);
duo_check(
    str_contains($floorRefusal, "the section '" . $SECTION . "'")
        && str_contains($floorRefusal, 'implements only at spec_version ' . $N)
        && str_contains($floorRefusal, 'declare spec_version ' . $N . ' to use it, or remove the section'),
    'AND AT THE WINDOW FLOOR: a spec_version ' . ($N - 1) . ' manifest refuses BY SECTION NAME with the '
        . 'actionable remedy — the population that has not migrated is told which version has the section'
);

// ===========================================================================
echo "\nPART 2 — THE NO-BUMP PROOF: DUO_SPEC_VERSION is still $N\n";
// ===========================================================================

// This is the assertion the whole work package exists for. Everything above
// would be equally true of a section that arrived with a version bump; what
// makes it a demonstration is that the integer did not move to admit it.
duo_check_same(
    3,
    $N,
    'DUO_SPEC_VERSION is 3, read out of agent/duo.php — the section shipped AFTER the flip and the wire '
        . 'version did not move to admit it (spec/repo-format.md § v3.14)'
);
$platform = Canon::decode(Canon::read_file($repo . '/manifests/capabilities/platform.json'));
duo_check_same(
    $N,
    $platform['platform']['spec_version'] ?? null,
    '...and manifests/capabilities/platform.json restates the same 3, so the boundary a certificate signs '
        . 'against did not move either (AGENTS.md rule 8)'
);
$since = AdapterContractGrammar::section_min_spec();
duo_check_same(
    $N,
    $since[$SECTION] ?? null,
    '...and the new section\'s own `since` is ' . $N . ' — the version this engine ALREADY ran at. A `since` '
        . 'of ' . ($N + 1) . ' would have refused it at every version the window accepts, which is how a '
        . 'channel meant to avoid a flag day would have quietly scheduled one'
);
duo_check_same(
    $since[$SECTION] ?? null,
    $since['engine_features'] ?? null,
    '...the same `since` the channel\'s own key has: the section joined the era that already existed rather '
        . 'than opening a new one'
);
// The window itself, measured by handing candidate integers to the shipped
// validator rather than by reading a constant. A bump would have moved BOTH
// ends of this — the ceiling to admit the section and the floor with it,
// stranding the entire shipped library, which still declares N-1.
$accepted = [];
foreach ([$N - 2, $N - 1, $N, $N + 1] as $candidate) {
    if ($verdict(['name' => 'window-probe', 'spec_version' => $candidate]) === null) {
        $accepted[] = $candidate;
    }
}
duo_check_same(
    [$N - 1, $N],
    $accepted,
    '...and the acceptance window is still {' . ($N - 1) . ', ' . $N . '} — a bump would have moved the FLOOR '
        . 'onto ' . $N . ' and stranded the whole shipped library, which declares ' . ($N - 1) . ' to this day '
        . '(§ v3.12\'s no-restamp rule)'
);

// ===========================================================================
echo "\nPART 3 — the section's own grammar, closed at every level\n";
// ===========================================================================

/** @param array<string,mixed> $section */
$sectionVerdict = static function (mixed $section) use ($adapter, $verdict): string {
    return (string) $verdict(
        $adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [StructuredEvidence::SECTION => $section])
    );
};

duo_check(
    str_contains($sectionVerdict([]), 'is [] — the section is a non-empty OBJECT'),
    'an empty section refuses: a document that declares it is founded and then declines to say on what reads '
        . 'as coverage, which is worse than declaring nothing'
);
duo_check(
    str_contains($sectionVerdict(['not an object']), 'the section is a non-empty OBJECT keyed by the declaration'),
    '...and a LIST refuses, naming the shape — the polymorphism `notes` has (a list in 14 manifests, an '
        . 'object in 2) is exactly what this section exists not to repeat'
);

$dangling = $sectionVerdict(['optoins.acme_settings' => [
    'evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => 'c']],
]]);
duo_check(
    str_contains($dangling, 'declares the target "optoins.acme_settings"')
        && str_contains($dangling, "this manifest declares no top-level 'optoins'"),
    'THE LOAD-BEARING RULE: a target ADDRESSES a declaration this manifest makes, so one transposed letter in '
        . 'the head refuses at load — the thing a `str_contains($notes, $name)` grep structurally cannot do'
);
duo_check_detail('dangling target: ' . $dangling);

duo_check(
    str_contains(
        $sectionVerdict([StructuredEvidence::SECTION . '.x' => [
            'evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => 'c']],
        ]]),
        'a record may not be evidence for this section itself'
    ),
    '...and a record about this section refuses: evidence addresses a DECLARATION, and a circular record is '
        . 'the unfalsifiable prose the section replaces'
);

duo_check(
    str_contains(
        $sectionVerdict(['options.acme_settings' => ['evidence' => []]]),
        '.evidence is [] — a non-empty LIST of {locator, observation, source} objects is required'
    ),
    'an empty `evidence[]` refuses, and the refusal names the path AND the required row shape'
);
duo_check(
    str_contains(
        $sectionVerdict(['options.acme_settings' => [
            'evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => 'c']],
            'note' => 'a sentence',
        ]]),
        "declares 'note', which this section does not define"
    ),
    'a record is CLOSED: an unrecognised member refuses by name, because a member no checker reads is `notes` '
        . 'again one nesting level deeper'
);
duo_check(
    str_contains(
        $sectionVerdict(['options.acme_settings' => [
            'evidence' => [['source' => 'a', 'locator' => 'b']],
        ]]),
        "evidence[0] is missing 'observation'"
    ),
    '...and closed in the OTHER direction too: a row missing a member refuses naming the member and the index'
);
duo_check(
    str_contains(
        $sectionVerdict(['options.acme_settings' => [
            'evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => '   ']],
        ]]),
        'evidence[0].observation is "   " — every member of a row is a non-empty string'
    ),
    '...and a whitespace-only member refuses: an evidence row whose observation says nothing is a row that '
        . 'passes a shape check while asserting nothing'
);
duo_check(
    str_contains(
        $sectionVerdict(['options.acme_settings' => [
            'evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => 'c']],
            'answered' => ['a bare question with no answer'],
        ]]),
        'answered[0] is "a bare question with no answer" — a row is an object with exactly {answer, question}'
    ),
    'AN OPEN QUESTION CANNOT SHIP: `answered[]` rows are {question, answer} pairs, so a draft\'s bare '
        . 'deferral string is refused — a deferral belongs in the `_draft` sidecar that gets stripped'
);
duo_check_same(
    null,
    $verdict($adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [$SECTION => [
        'options.acme_settings' => [
            'evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => 'c']],
        ],
    ]])),
    '...while `answered` is OPTIONAL: a declaration nobody had a question about carries evidence alone'
);

// REFUSAL ORDER is observable contract: two defects in one section must produce
// the same first refusal on every run, whatever order a re-serializing tool left
// the keys in.
$twoDefects = [
    'zzz.later' => ['evidence' => [['source' => 'a', 'locator' => 'b', 'observation' => 'c']]],
    'options.acme_settings' => ['evidence' => []],
];
duo_check(
    str_contains((string) $sectionVerdict($twoDefects), 'options.acme_settings')
        && str_contains((string) $sectionVerdict(array_reverse($twoDefects, true)), 'options.acme_settings'),
    'targets are walked SORTED, so a manifest re-serialized by a tool that does not sort gets the same first '
        . 'refusal for the same two defects'
);

// THE BLIND WALK. `ReferenceKindGrammar` collects `ref`/`refs`/`json_refs`/
// `key_refs` at ANY depth and skips only four top-level keys; this section is
// not one of them, by choice — every object in it has a closed key set, so
// nothing inside can be a trigger.
$withRefBait = $adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [$SECTION => [
    'options.acme_settings' => [
        'evidence' => [[
            'source' => 'state/options/core.json',
            'locator' => 'acme_settings.ref',
            'observation' => 'the value looked like a `ref` but is a slug',
        ]],
    ],
]]);
$refWalk = null;
try {
    ReferenceKindGrammar::validate_ref_kinds($withRefBait, []);
} catch (\Throwable $e) {
    $refWalk = $e->getMessage();
}
duo_check_same(
    null,
    $refWalk,
    'the section is INERT under the blind ref-kind walk without a fifth skip being added for it: its members '
        . 'are closed, so `ref`/`refs`/`json_refs`/`key_refs` cannot appear as keys inside it'
);

// ===========================================================================
echo "\nPART 4 — the product path, and the deferral this rider is honest about\n";
// ===========================================================================

Canon::write_file(
    $scratch . '/acme-evidence.json',
    Canon::encode($adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [$SECTION => $goodRecord]))
);
$broken = $adapter('acme-broken', [SE_CHANNEL_FEATURE, SE_FEATURE], [$SECTION => $goodRecord]);
// The declaration the record addresses, DELETED — the exact edit the prose grep
// cannot notice, because prose has no addresses.
unset($broken['options']);
Canon::write_file($scratch . '/acme-broken.json', Canon::encode($broken));

$validate = $run([PHP_BINARY, $repo . '/cli/duo', 'manifest-validate', $scratch]);
duo_check(
    str_contains($validate['stdout'], '[ok] acme-evidence'),
    'THROUGH THE PRODUCT PATH: `duo manifest-validate` reports [ok] for an out-of-tree adapter carrying the '
        . 'section — no synthetic engine, no mutant tree, the shipped CLI at the shipped version'
);
duo_check(
    str_contains($validate['stdout'], '[error] acme-broken')
        && str_contains($validate['stdout'], "declares no top-level 'options'"),
    '...and [error] for its sibling whose addressed declaration was deleted: the rationale outlives the '
        . 'declaration by exactly zero releases, which is the whole point of an address'
);
duo_check($validate['exit'] !== 0, '...and the run fails, because a pin set holding an unloadable adapter is not a passing check');

// THE DEFERRAL, RECORDED RATHER THAN GLOSSED. The shipped library cannot adopt
// the section: `ArtifactPolicyIdentity::manifest_rows()` folds each manifest's
// JSON into its `digest`, so 16 adopting edits are 16 moved digests and every
// pin and certificate naming one stops matching (AGENTS.md rule 2).
$shippedManifests = [];
foreach (glob($repo . '/manifests/*.json') ?: [] as $file) {
    $decoded = Canon::decode(Canon::read_file($file));
    $shippedManifests[basename($file, '.json')] = $decoded;
}
$adopters = array_values(array_filter(
    array_keys($shippedManifests),
    static fn(string $n): bool => array_key_exists($SECTION, $shippedManifests[$n])
));
duo_check_same(
    [],
    $adopters,
    'NO SHIPPED MANIFEST declares the section, so this rider moved zero adapter digests — the same '
        . 'digest-neutrality WP-4.12\'s flip was engineered for, for the same reason'
);
$report('shipped manifests: ' . count($shippedManifests) . '; adopting the section: ' . count($adopters));

$grepSuite = (string) file_get_contents(
    $repo . '/sandbox/tests/offline/policy/regress_shipped_option_declarations.php'
);
duo_check(
    str_contains($grepSuite, "str_contains(\$text, 'DUO-3509')"),
    'AND THE PROSE GREP STAYS: `regress_shipped_option_declarations.php` still greps an issue id out of '
        . '`notes` for the shipped library, because that library cannot carry the structured section without '
        . 'moving its digests. The gate converts per adapter, when one is next touched for a product reason'
);
duo_check(
    str_contains($grepSuite, 'regress_structured_evidence.php'),
    '...and that suite NAMES this one at the grep, so the deferral is a written cross-reference a reviewer '
        . 'meets at the site, not a note in a merge description nobody reads again'
);

// `notes` is a SIBLING, not a predecessor: an adapter may carry both, and
// nothing here reads or rewrites the prose.
duo_check_same(
    null,
    $verdict($adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [
        'notes' => ['acme_settings is an authored blob; see DUO-3509'],
        $SECTION => $goodRecord,
    ])),
    'a manifest carrying BOTH `notes` and the section loads: nothing is migrated, nothing is rewritten, and '
        . 'the two can be reconciled by whoever next edits that adapter for a product reason'
);

// ===========================================================================
echo "\nPART 5 — what a certificate says about an adapter that adopts it: nothing\n";
// ===========================================================================

$ratify = (new ReflectionClass(AdapterCertification::class))->getMethod('siteRatification');
$signer = static function (string $name, array $manifest) use ($ratify): ?string {
    try {
        $ratify->invoke(null, $name, $manifest, 'structured evidence suite');
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};
// The signer's classify-or-throw loop walks the manifest's own key order and
// refuses the FIRST key it cannot place, so a realistic adapter is refused on
// `engine_features` — one key earlier than the section. That is not a weaker
// result, it is a stronger one: declaring the CHANNEL already makes an adapter
// uncertifiable, so nothing about this section made anything worse, and both
// halves are pinned rather than one being inferred from the other.
$signerVerdict = (string) $signer(
    'acme-evidence',
    $adapter('acme-evidence', [SE_CHANNEL_FEATURE, SE_FEATURE], [$SECTION => $goodRecord])
);
duo_check(
    str_contains($signerVerdict, "declares 'engine_features'")
        && str_contains($signerVerdict, 'which this signer cannot classify'),
    'THE STATED POSTURE, MEASURED: an adapter that uses the channel at all is already NOT certifiable — the '
        . 'signer refuses `engine_features` itself, which is in no arm either, one key before it reaches the '
        . 'section'
);
// The section's OWN answer, isolated by handing the signer a manifest whose
// only unclassifiable key is the section. That manifest is not loadable (the
// key needs its feature declared, and the feature needs the channel key), which
// is exactly why the signer has to be asked directly to learn what it thinks of
// this one section.
$sectionOnly = (string) $signer('acme-evidence', [
    'name' => 'acme-evidence',
    'spec_version' => $N,
    'options' => ['acme_settings' => ['class' => 'authored', 'autoload' => 'yes']],
    $SECTION => $goodRecord,
]);
duo_check(
    str_contains($sectionOnly, "declares '" . $SECTION . "'")
        && str_contains($sectionOnly, 'which this signer cannot classify')
        && str_contains($sectionOnly, 'teach the signer this section'),
    '...and the section itself is refused by name for the same reason: a feature record carries {since, keys} '
        . 'and no ARM, so there is no reviewed answer to the only question the signer asks — § v3.3 states '
        . 'that posture in advance and this is the refusal it predicts'
);
duo_check_detail('signer refusal (channel): ' . $signerVerdict);
duo_check_detail('signer refusal (section): ' . $sectionOnly);
$partition = AdapterCertification::topLevelKeyPartition();
duo_check(
    !in_array($SECTION, array_merge(
        $partition['entity_sections'],
        $partition['field_sections'],
        $partition['non_surface_keys']
    ), true),
    '...and the key is in no arm of the partition, deliberately: an arm would also admit it with NO feature '
        . 'declared, which would delete the demonstration PART 1 and PART 2 are'
);

// ===========================================================================
echo "\nPART 6 — the register and the spec say the same thing the engine does\n";
// ===========================================================================

$register = (string) file_get_contents($repo . '/docs/wire-surface.md');
duo_check(
    str_contains($register, '### R-29 — Structured declaration evidence'),
    'the wire-surface register carries R-29 for the section — a new wire surface acquired with no bump, which '
        . 'is the first row in the register that was not paid for by one'
);
duo_check(
    str_contains($register, 'structured-evidence/v1') && str_contains($register, '`' . $SECTION . '`'),
    '...naming both the feature and the key it claims, projected from the engine constants by '
        . '`tools/wire-surface.php` and byte-compared by `make release-gate`'
);
$specBody = (string) file_get_contents($repo . '/spec/repo-format.md');
duo_check(
    str_contains($specBody, '### v3.14 `' . $SECTION . '` — the first POST-v3 section, shipped with no bump'),
    'and spec/repo-format.md § v3.14 is the section every refusal above cites — a rule whose cited section '
        . 'does not exist is a rule an author cannot check'
);

duo_check_summary('structured typed evidence');
