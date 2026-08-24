<?php
/**
 * `duo adapter doctor --migration` — the preflight, cross-checked against the
 * flag-day rehearsal that measured what a bump actually moves.
 *
 * THE ACCEPTANCE, AND WHY IT IS THE ONLY ONE WORTH HAVING
 * ------------------------------------------------------
 * A preflight that under-reports turns a controlled bump into an incident: the
 * rollout proceeds on a false green and the movements nobody predicted surface
 * on customer sites. Asserting the verb's own output against hand-written
 * expectations would test this suite's opinion of the mechanism, not the
 * mechanism. So the acceptance is a CROSS-CHECK against an independent
 * measurement: WP-1.4's `spec_migration_estate.php` builds a nine-site fleet at
 * state A and OBSERVES it at A and again at B, driving the whole engine through
 * a child process per state. This suite drives the preflight against that same
 * estate at state B and asserts, per site, that
 *
 *     the set of invalidations the preflight PREDICTED
 *   = the set of movements the rehearsal OBSERVED
 *
 * — the same set and the same count, id for id. Two independent paths to one
 * answer: the rehearsal compiles at A and compiles again at B and diffs; the
 * preflight compiles once at B and compares against what the site HOLDS.
 *
 * THE TWO MEASURED FINDINGS THE PREDICTION MUST COVER
 * ---------------------------------------------------
 * WP-1.4 pinned both as assertions, and both are movements no operator would
 * guess from the digest-neutrality argument alone:
 *
 *   (a) `artifact_hash` moves for EVERY site, including sites pinning nothing
 *       but shipped adapters under full digest neutrality, because every
 *       resolved adapter row inside the compiled document carries
 *       `capability.platform.agent_version`. So scope-contract re-projections
 *       are part of the predicted set for every site in the estate.
 *   (b) A site holding a CERTIFIED SITE ADAPTER additionally moves its own
 *       `manifest_hash` and `revision_hash`, because the withdrawal changes the
 *       certificate-derived digest folded into `manifest_rows()`.
 *
 * Both are load-bearing here rather than decorative: the cross-check FAILS if
 * the predictor drops either class. Dropping (a) costs every one of the seven
 * cohort sites one predicted row; dropping (b) costs each of the three
 * certificate-holding sites two more. Measured by removing each class in turn
 * while writing this suite, which is what makes the cross-check a test rather
 * than a coincidence.
 *
 * WHAT THE CROSS-CHECK COMPARES, AND WHAT IT DELIBERATELY DOES NOT
 * ---------------------------------------------------------------
 * The rehearsal OBSERVES seven kinds of movement (four identity values, an
 * adapter digest, a withdrawn certificate, a scope contract that stops
 * associating). The preflight predicts those and three more the rehearsal has
 * no observation for — a content pin, a contract attestation, a contract
 * registry. CROSS_CHECK_VOCABULARY below is the shared half and the comparison
 * runs over it; CROSS_CHECK_UNOBSERVED is the other half, each id with the
 * reason the rehearsal cannot answer it, and the suite refuses a predicted
 * class that is in neither list. So a movement class added later cannot quietly
 * fall outside the cross-check.
 *
 * THE FIXTURE HALF
 * ----------------
 * Beside the cross-check, six small site repositories built here exercise the
 * shapes the estate does not carry: a certified adapter that HOLDS, an
 * uncertified one, a host contract whose registry pin still matches and one
 * whose pin has moved, a certificate shape the verb cannot classify, and a pin
 * shape it cannot classify. The last two are the point of the refuse-to-classify
 * posture: both produce an `unclassified` row, no green verdict, and the
 * engine's own sentence — and both are answered even though `Policy::load()`
 * refuses the repository outright, which is the property this whole command
 * family exists for.
 */
declare(strict_types=1);

// From offline/cli/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$estateDriver = $root . '/sandbox/tests/offline/guards/spec_migration_estate.php';
$stateDriver = __DIR__ . '/migration_preflight_state.php';

// The fixture half writes two documents the engine reads through its canonical
// readers — a host contract and a certificate — so it authors them with the
// engine's own encoder and the contract's own digest function rather than with
// json_encode() plus a guess about key order. `ApplicationContract.php` pulls
// Canon and CommandRefusal by path (its own require_once lines), and the two
// defines are what `Policy::load()` would compare a manifest's spec_version
// against; resolving them from `agent/duo.php` is the same regex
// `AdapterCatalog::boot()` uses, for the same reason (a literal drifts).
$duoSource = (string) file_get_contents($root . '/agent/duo.php');
if (preg_match("/define\('DUO_AGENT_VERSION', '([^']+)'\)/", $duoSource, $agentMatch) !== 1
    || preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", $duoSource, $specMatch) !== 1) {
    fwrite(STDERR, "FAIL: cannot resolve the agent defines from agent/duo.php\n");
    exit(1);
}
define('DUO_AGENT_VERSION', $agentMatch[1]);
define('DUO_SPEC_VERSION', (int) $specMatch[1]);
require_once $root . '/cli/src/Contract/ApplicationContract.php';

/**
 * The movement ids the rehearsal OBSERVES, so the cross-check can compare like
 * with like. `adapter_digest:<name>` and `certificate:<name>` are families; the
 * prefix is what is listed.
 */
const CROSS_CHECK_VOCABULARY = [
    'artifact_hash',
    'manifest_hash',
    'revision_hash',
    'site_hash',
    'adapter_digest:',
    'certificate:',
    'scope_contract',
];

/**
 * The movement ids the preflight predicts that the rehearsal has no observation
 * for, each with the reason. A predicted id in neither list fails the suite:
 * the cross-check must never silently narrow.
 */
const CROSS_CHECK_UNOBSERVED = [
    'content_pin:' =>
        'the rehearsal observes a moved pin only through the adapter digest underneath it — its per-site rows '
        . 'carry adapters[].digest, never the site.duo.json pin list — so a content_pin row has no observed '
        . 'counterpart to equal. It is not unobserved in substance: the same movement is compared as '
        . 'adapter_digest:<name>.',
    'contract_attestation' =>
        'no site in the estate carries .duo/contract/contract.json; the estate\'s one attested contract lives '
        . 'beside the sites (holdings/_contract/) and the rehearsal drives it through its own probe.',
    'contract_registry' =>
        'same: no estate SITE carries a host contract, so there is no per-site observation to compare against.',
    'frozen_certificate:' =>
        'the rehearsal observes the frozen path as snapshot_adapters[].certified on one site; this cross-check '
        . 'compares the mutable path, and passing --snapshot would add a prediction with no per-site observed '
        . 'counterpart in the same vocabulary.',
];

/** Run a child process and return [exit, stdout, stderr]. @return array{0:int,1:string,2:string} */
function preflight_run(array $command): array {
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "FAIL: cannot start " . implode(' ', $command) . "\n");
        exit(1);
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

/** Run the estate driver and decode its document. */
function preflight_estate(string $driver, string $estate, string $state, string $mode): array {
    [$exit, $stdout, $stderr] = preflight_run([PHP_BINARY, $driver, $estate, $state, $mode]);
    $decoded = json_decode($stdout, true);
    if ($exit !== 0 || !is_array($decoded)) {
        // A driver that cannot build or read the estate is a broken instrument,
        // not a failing assertion: report its own words and stop.
        fwrite(STDERR, "FAIL: estate driver $mode/$state exited $exit\n" . $stderr . "\n");
        exit(1);
    }
    return $decoded;
}

/**
 * Run the preflight at one agent state and decode its `duo-migration-preflight/v1`.
 *
 * @param list<string> $args
 * @return array{0:int,1:array<string,mixed>}
 */
function preflight_at(string $driver, string $agentVersion, int $specVersion, string $lib, array $args): array {
    [$exit, $stdout, $stderr] = preflight_run(array_merge(
        [PHP_BINARY, $driver, $agentVersion, (string) $specVersion, $lib],
        $args
    ));
    $decoded = json_decode($stdout, true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "FAIL: preflight produced no document (exit $exit)\n$stdout\n$stderr\n");
        exit(1);
    }
    return [$exit, $decoded];
}

function preflight_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        preflight_remove_tree($item->getPathname());
    }
    rmdir($path);
}

/** The movement ids of a preflight document. @return list<string> */
function preflight_ids(array $document): array {
    $ids = [];
    foreach ((array) ($document['movements'] ?? []) as $row) {
        $ids[] = (string) $row['id'];
    }
    sort($ids, SORT_STRING);
    return $ids;
}

/** Whether an id belongs to a listed family (`prefix:` matches by prefix). */
function preflight_in(array $vocabulary, string $id): bool {
    foreach ($vocabulary as $entry) {
        if (str_ends_with($entry, ':') ? str_starts_with($id, $entry) : $id === $entry) {
            return true;
        }
    }
    return false;
}

/**
 * What the rehearsal OBSERVED move for one site between its two passes.
 *
 * Read off the estate's own per-site observation rows — nothing is recomputed
 * here, which is what makes this an independent measurement rather than a
 * second copy of the preflight's arithmetic.
 *
 * @return list<string>
 */
function preflight_observed(array $before, array $after): array {
    $moved = [];
    foreach (['artifact_hash', 'manifest_hash', 'revision_hash', 'site_hash'] as $key) {
        if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
            $moved[] = $key;
        }
    }
    $afterAdapters = [];
    foreach ((array) ($after['adapters'] ?? []) as $row) {
        $afterAdapters[(string) $row['name']] = $row;
    }
    foreach ((array) ($before['adapters'] ?? []) as $row) {
        $name = (string) $row['name'];
        $now = $afterAdapters[$name] ?? null;
        if ($now === null || ($now['digest'] ?? null) !== ($row['digest'] ?? null)) {
            $moved[] = "adapter_digest:$name";
        }
        if (($row['certified'] ?? false) === true && ($now['certified'] ?? null) === false) {
            $moved[] = "certificate:$name";
        }
    }
    if (array_key_exists('scope_contract', $before)
        && ($before['scope_contract'] ?? null) !== ($after['scope_contract'] ?? null)) {
        $moved[] = 'scope_contract';
    }
    sort($moved, SORT_STRING);
    return $moved;
}

if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "FAIL: the PHP sodium extension is required for the migration preflight cross-check\n");
    exit(1);
}

// Rule 3: never under agent/ or manifests/ — `sandbox/bin/pair.sh:355` refuses
// on an untracked file there. A unique root per run, because the corpus runs
// concurrently.
$scratch = sys_get_temp_dir() . '/duo-migration-preflight-' . bin2hex(random_bytes(6));
register_shutdown_function(static fn() => preflight_remove_tree($scratch));
$estate = $scratch . '/estate';

echo "\n== the estate, built and observed by WP-1.4's own driver ==\n";
preflight_estate($estateDriver, $estate, 'A', 'materialize');
$observedA = preflight_estate($estateDriver, $estate, 'A', 'observe');
$observedB = preflight_estate($estateDriver, $estate, 'B', 'observe');

// State B is read out of the estate's own observation rather than recomputed:
// `rehearsal_state_versions()` (spec_migration_estate.php:124-143) is the one
// definition of what the fleet is moving to, and a second one here could drift
// into preflighting a different agent than the rehearsal measured.
$targetAgent = (string) $observedB['agent_version'];
$targetSpec = (int) $observedB['spec_version'];
duo_check(
    $targetAgent !== (string) $observedA['agent_version']
    && (string) $observedB['platform_sha256'] !== (string) $observedA['platform_sha256'],
    'the estate really does move the agent under the fleet (' . (string) $observedA['agent_version']
    . ' -> ' . $targetAgent . '), so a preflight run at B is a preflight of a real bump'
);

/**
 * The cohort, and what each site is HOLDING. The estate materializes holdings
 * at state A, so a held artifact here IS the pre-bump document a deployed site
 * would be carrying. The two controls are excluded by name with the reason.
 */
$cohort = [
    'core-only' => ['artifact'],
    'editorial' => ['artifact'],
    'pinned-shop' => ['artifact', 'scope-contract'],
    'multilingual' => ['artifact'],
    'certified-alpha' => ['artifact', 'scope-contract'],
    'certified-beta' => ['artifact'],
    // Its artifact was deliberately removed at materialization
    // (`snapshot_only`): this is the promoted site that verifies only from its
    // frozen snapshot. Its predictions therefore come from the CERTIFICATE gate
    // alone — including finding (a), on the `certificate-platform` basis — which
    // is exactly the case worth having in the cross-check.
    'promoted-frozen' => [],
];
$controls = [
    'drifted-pin' => 'CONTROL: its pin was drifted on purpose, so it refuses at BOTH states — there is no A-to-B '
        . 'movement to predict, and the preflight reports the load refusal instead',
    'artifact-drift' => 'CONTROL: it holds an artifact compiled against a library whose reviewed disposition was '
        . 'edited, so its held document is not a state-A document. Held-versus-target and A-versus-B are then '
        . 'answering different questions, which is the control working rather than a disagreement',
];
$estateSites = array_keys((array) $observedA['sites']);
$accountedFor = array_values(array_unique(array_merge(array_keys($cohort), array_keys($controls))));
sort($estateSites, SORT_STRING);
sort($accountedFor, SORT_STRING);
duo_check_same(
    $estateSites,
    $accountedFor,
    'every site the estate builds is accounted for here — seven cross-checked and two controls named with their '
    . 'reason, so a site added to the estate cannot silently fall out of this suite'
);

echo "\n== THE CROSS-CHECK: predicted == observed, per site ==\n";
printf("%-17s %-9s %-9s %s\n", 'site', 'predicted', 'observed', 'ids');
$straySources = [];
foreach ($cohort as $id => $holds) {
    $repo = $estate . '/sites/' . $id;
    $args = ['doctor', '--migration', '--repo=' . $repo, '--format=json'];
    foreach ($holds as $kind) {
        $args[] = "--$kind=" . $estate . '/holdings/' . $id . '/'
            . ($kind === 'artifact' ? 'artifact.json' : 'scope-contract.json');
    }
    [$exit, $document] = preflight_at($stateDriver, $targetAgent, $targetSpec, $estate . '/libs/B', $args);

    duo_check_same(
        'duo-migration-preflight/v1',
        (string) ($document['format'] ?? ''),
        "$id: the preflight emits duo-migration-preflight/v1"
    );
    duo_check_same(1, $exit, "$id: a site with predicted movement exits 1 (a surfaced finding), never 0");
    duo_check_same([], (array) $document['unclassified'], "$id: every certificate and pin shape was classified");

    $predicted = preflight_ids($document);
    foreach ($predicted as $predictedId) {
        if (!preflight_in(CROSS_CHECK_VOCABULARY, $predictedId)
            && !preflight_in(array_keys(CROSS_CHECK_UNOBSERVED), $predictedId)) {
            $straySources[] = "$id: $predictedId";
        }
    }
    $comparable = array_values(array_filter(
        $predicted,
        static fn(string $movementId): bool => preflight_in(CROSS_CHECK_VOCABULARY, $movementId)
    ));
    $observed = preflight_observed($observedA['sites'][$id], $observedB['sites'][$id]);

    printf("%-17s %-9d %-9d %s\n", $id, count($comparable), count($observed), implode(' ', $observed));
    duo_check_same(
        $observed,
        $comparable,
        "$id: THE ACCEPTANCE — the preflight's predicted invalidation set EQUALS the rehearsal's observed set, "
        . 'id for id and count for count, on the same fixture'
    );
}
duo_check_same(
    [],
    $straySources,
    'and every predicted movement class is either in the compared vocabulary or in the reviewed unobserved list '
    . 'with its reason — a new class cannot fall outside the cross-check unnoticed'
);

echo "\n== finding (a): artifact_hash moves for EVERY site, on a named basis ==\n";
foreach ($cohort as $id => $holds) {
    $repo = $estate . '/sites/' . $id;
    $args = ['doctor', '--migration', '--repo=' . $repo, '--format=json'];
    foreach ($holds as $kind) {
        $args[] = "--$kind=" . $estate . '/holdings/' . $id . '/'
            . ($kind === 'artifact' ? 'artifact.json' : 'scope-contract.json');
    }
    [, $document] = preflight_at($stateDriver, $targetAgent, $targetSpec, $estate . '/libs/B', $args);
    $row = null;
    foreach ((array) $document['movements'] as $movement) {
        if ((string) $movement['id'] === 'artifact_hash') {
            $row = $movement;
        }
    }
    duo_check(
        is_array($row) && (string) $row['class'] === 'artifact_reprojection',
        "(a) $id: artifact_hash is predicted to move — a site pinning nothing but shipped adapters included — "
        . 'because the compiled document carries every adapter\'s capability.platform.agent_version'
    );
    duo_check(
        is_array($row) && (array) $row['basis'] !== [],
        "(a) $id: and the prediction names the basis it came from (" . implode(', ', (array) ($row['basis'] ?? []))
        . '), never an unsourced assertion'
    );
    duo_check(
        str_contains((string) ($row['remedy'] ?? ''), 'scope contract'),
        "(a) $id: with the re-projection remedy stated: scope contracts, scoped mutation authorities and scoped "
        . 'rollback claims are what pin artifact_hash'
    );
}

// The mechanism itself, not just the verdict: the held document and the target
// disagree about the very field the finding names.
[, $coreOnly] = preflight_at($stateDriver, $targetAgent, $targetSpec, $estate . '/libs/B', [
    'doctor', '--migration', '--repo=' . $estate . '/sites/core-only', '--format=json',
    '--artifact=' . $estate . '/holdings/core-only/artifact.json',
]);
duo_check_same(
    (string) $observedA['agent_version'],
    (string) ($coreOnly['held_artifact']['capability_platform_agent_versions']['core'] ?? ''),
    '(a) the mechanism is in the payload, not in this suite\'s prose: the HELD compiled artifact\'s '
    . 'resolved_adapters[core].capability.platform.agent_version is the pre-bump agent'
);
duo_check_same(
    $targetAgent,
    (string) ($coreOnly['adapters'][0]['capability_platform_agent_version'] ?? ''),
    '(a) and the target resolves the identical adapter with the post-bump agent in the same field — which is the '
    . 'whole of why artifact_hash moves under digest neutrality'
);
duo_check_same(
    'holds',
    (string) ($coreOnly['identity']['manifest_hash']['verdict'] ?? ''),
    '(a) while manifest_hash HOLDS on that same site: digest neutrality is real, and artifact_hash moving anyway '
    . 'is the correction WP-1.4 measured'
);
duo_check_same(
    'verifies',
    (string) ($coreOnly['held_artifact']['reader'] ?? ''),
    '(a) and the held artifact still VERIFIES — read_artifact() compares site_hash, manifest_hash, effects and '
    . 'code, never artifact_hash — so the movement is real and the refusal is not'
);

echo "\n== finding (b): a certified site adapter moves its own manifest and revision identity ==\n";
foreach (['certified-alpha' => 'estate-forms', 'certified-beta' => 'estate-shop', 'promoted-frozen' => 'estate-catalog'] as $id => $adapter) {
    $args = ['doctor', '--migration', '--repo=' . $estate . '/sites/' . $id, '--format=json'];
    if ($id !== 'promoted-frozen') {
        $args[] = '--artifact=' . $estate . '/holdings/' . $id . '/artifact.json';
    }
    [, $document] = preflight_at($stateDriver, $targetAgent, $targetSpec, $estate . '/libs/B', $args);
    $classes = [];
    foreach ((array) $document['movements'] as $movement) {
        $classes[(string) $movement['id']] = $movement;
    }
    duo_check(
        isset($classes['manifest_hash']) && isset($classes['revision_hash']),
        "(b) $id: holding a certified site adapter moves this site's OWN manifest_hash and revision_hash"
    );
    duo_check(
        in_array('certificate-gate', (array) ($classes['manifest_hash']['basis'] ?? []), true),
        "(b) $id: and the prediction is sourced from the CERTIFICATE gate, so it holds without a held artifact — "
        . 'which is how promoted-frozen, whose artifact was never kept, is predicted at all'
    );
    duo_check(
        isset($classes["certificate:$adapter"]) && isset($classes["adapter_digest:$adapter"]),
        "(b) $id: with the withdrawal of '$adapter' and the certificate-derived digest that moves with it"
    );
    $certificate = null;
    foreach ((array) $document['certificates'] as $row) {
        if ((string) $row['name'] === $adapter) {
            $certificate = $row;
        }
    }
    duo_check_same(
        false,
        $certificate['matches_target_platform'] ?? null,
        "(b) $id: the certificate's platform does NOT match the target's — the answer comes from "
        . 'AdapterCertification::verifyFile() raising StalePlatformSiteAdapterCertificate, never from a digest '
        . 'this command hashed itself'
    );
    duo_check_same(
        (string) $observedA['agent_version'],
        (string) ($certificate['certificate_agent_version'] ?? ''),
        "(b) $id: and the certificate names the agent it WAS signed against, so an operator can see both ends"
    );
    duo_check_same(
        true,
        $certificate['key_reachable'] ?? null,
        "(b) $id: the signing key id is reachable by id in the site trust root — the reachability question is "
        . 'answered over identifiers, with no public_key byte read'
    );
}

echo "\n== the controls: the preflight does not invent a movement it cannot source ==\n";
[$driftExit, $drifted] = preflight_at($stateDriver, $targetAgent, $targetSpec, $estate . '/libs/B', [
    'doctor', '--migration', '--repo=' . $estate . '/sites/drifted-pin', '--format=json',
]);
duo_check_same('refused', (string) $drifted['status'], 'CONTROL drifted-pin: ' . $controls['drifted-pin']);
duo_check_same(1, $driftExit, 'CONTROL drifted-pin: a refusing site is a surfaced finding, exit 1');
duo_check(
    str_contains((string) ($drifted['site']['refusal'] ?? ''), 'digest mismatch'),
    'CONTROL drifted-pin: carrying the engine\'s own refusal, byte for byte, rather than a paraphrase'
);
duo_check(
    (array) $drifted['pins'] !== [],
    'CONTROL drifted-pin: and the PIN LIST is still answered on a repository whose load refused — pins are read '
    . 'through PinResolver::normalize_manifest_pins() before Policy::load() runs, which is the property this '
    . 'command family exists for'
);

echo "\n== fixtures: shapes the estate does not carry ==\n";

/** Write a canonical JSON document. */
function preflight_write(string $path, string $bytes): void {
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        fwrite(STDERR, "FAIL: cannot create " . dirname($path) . "\n");
        exit(1);
    }
    file_put_contents($path, $bytes);
}

// The fixtures run at the TREE's own agent state, through the shipped `cli/duo`
// executable: that is the product path an operator walks, and it is the one
// state the checkout can be. Nothing here needs a bump — these cases are about
// classification, not about movement.
$fixtureLib = $root . '/manifests';
$sites = $scratch . '/fixtures';

// F1: a certified site adapter, minted by the operator's own command.
$certified = $sites . '/certified';
preflight_write($certified . '/site.duo.json', \Duo\Canon::encode([
    'manifests' => ['core', ['name' => 'preflight-forms', 'source' => 'site']],
    'policy' => ['options' => new stdClass(), 'post_types' => ['post'], 'taxonomies' => ['category']],
    'spec_version' => DUO_SPEC_VERSION,
]));
// Canonical bytes, not json_encode(): `AdapterCertification` reads a site
// adapter through readCanonicalObjectFile(), so a fixture that happened to
// match canonical key order today would break on the next field anybody adds.
preflight_write($certified . '/adapters/preflight-forms.json', \Duo\Canon::encode([
    'name' => 'preflight-forms',
    'option_autoload' => 'preserve',
    'post_types' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
    'tables' => [],
]));
$keypair = sodium_crypto_sign_seed_keypair(str_repeat('P', SODIUM_CRYPTO_SIGN_SEEDBYTES));
preflight_write($scratch . '/keys/preflight.key', base64_encode(sodium_crypto_sign_secretkey($keypair)) . "\n");
chmod($scratch . '/keys/preflight.key', 0600);
[$certifyExit, , $certifyErr] = preflight_run([
    PHP_BINARY, $root . '/cli/duo', 'adapter', 'certify', $certified,
    '--name=preflight-forms',
    '--key-id=preflight-key',
    '--secret-key-file=' . $scratch . '/keys/preflight.key',
    '--reason=Reviewed for the migration-preflight fixture set.',
    '--pin',
]);
duo_check_same(0, $certifyExit, 'fixture: `duo adapter certify --pin` minted the certified fixture (' . trim($certifyErr) . ')');

// The product path, end to end: the shipped executable, no test driver.
[$dueExit, $duoOut, $duoErr] = preflight_run([
    PHP_BINARY, $root . '/cli/duo', 'adapter', 'doctor', '--migration', '--repo=' . $certified, '--format=json',
]);
$greenDocument = json_decode($duoOut, true);
duo_check_same(0, $dueExit, 'F1 certified adapter: the SHIPPED `duo adapter doctor --migration` exits 0 on a site '
    . 'whose certificate matches the agent it is run from (' . trim($duoErr) . ')');
duo_check_same('ok', (string) ($greenDocument['status'] ?? ''), 'F1: status ok — nothing moves and nothing is unclassified');
duo_check_same(
    'holds',
    (string) ($greenDocument['certificates'][0]['outcome'] ?? ''),
    'F1: the certificate HOLDS, from AdapterCertification::verifyFile() returning rather than throwing'
);
duo_check_same(
    true,
    $greenDocument['certificates'][0]['key_reachable'] ?? null,
    'F1: its signing key id is reachable in the site trust root the certify run registered it in'
);
duo_check_same(
    true,
    $greenDocument['certificates'][0]['matches_target_platform'] ?? null,
    'F1: and its platform matches the target — the green half of the same gate'
);
duo_check_same([], (array) ($greenDocument['movements'] ?? [null]), 'F1: no predicted movement at all');
duo_check(
    (array) ($greenDocument['deferred'] ?? []) !== [],
    'F1: and the run still ends with its DEFERRED list — the identity questions it was given no held documents '
    . 'for are named, never folded into the pass'
);
$pinRow = null;
foreach ((array) $greenDocument['pins'] as $row) {
    if ((string) $row['name'] === 'preflight-forms') {
        $pinRow = $row;
    }
}
duo_check(
    is_array($pinRow) && (string) $pinRow['verdict'] === 'holds' && (string) $pinRow['source'] === 'site',
    'F1: and the {name,source,digest} pin `certify --pin` wrote is classified as holding against the digest '
    . 'ArtifactPolicyIdentity::resolved_adapters() resolves'
);

// F2: the same adapter, UNCERTIFIED — the certificate removed, the pin left as
// a source-only override, which is the shape a site carries before it signs.
$uncertified = $sites . '/uncertified';
preflight_write($uncertified . '/site.duo.json', \Duo\Canon::encode([
    'manifests' => ['core', ['name' => 'preflight-forms', 'source' => 'site']],
    'policy' => ['options' => new stdClass(), 'post_types' => ['post'], 'taxonomies' => ['category']],
    'spec_version' => DUO_SPEC_VERSION,
]));
preflight_write(
    $uncertified . '/adapters/preflight-forms.json',
    (string) file_get_contents($certified . '/adapters/preflight-forms.json')
);
[$uncertifiedExit, $uncertifiedDocument] = preflight_at(
    $stateDriver,
    (string) $observedA['agent_version'],
    (int) $observedA['spec_version'],
    $fixtureLib,
    ['doctor', '--migration', '--repo=' . $uncertified, '--format=json']
);
duo_check_same(0, $uncertifiedExit, 'F2 uncertified adapter: a site with no certificate at all is green — there is '
    . 'no claim for the bump to withdraw');
duo_check_same([], (array) $uncertifiedDocument['certificates'], 'F2: and it reports no certificates rather than an empty verdict about one');
duo_check_same(
    'no_content_pin',
    (string) ($uncertifiedDocument['pins'][1]['verdict'] ?? ''),
    'F2: its source-only override pin declares no content, so there is no digest to move — reported as the '
    . 'absence it is, not dropped'
);

// F3/F4: a host contract whose reviewed-registry pin matches the target, and
// one whose pin has moved. `evidence_pins.registry_sha256` is the number
// `ContractProjection`'s invalidation rule compares (:186-215), and the answer
// here is that rule's, reached through generate().
$contractFixture = json_decode(
    (string) file_get_contents($root . '/sandbox/tests/fixtures/contract/contract-unbound.json'),
    true
);
$targetRegistry = (string) ($greenDocument['target']['registry_sha256'] ?? '');
duo_check(
    preg_match('/^[0-9a-f]{64}$/', $targetRegistry) === 1,
    'fixture: the preflight publishes the target registry_sha256 it observed (ManifestDispositions::sha256(), '
    . 'the one definition a host contract pins)'
);

foreach ([
    'current' => [$targetRegistry, 'none', false],
    'moved' => [str_repeat('a', 64), 'whole-contract', true],
] as $label => [$registry, $expectedMode, $expectedMovement]) {
    $site = $sites . '/contract-' . $label;
    preflight_write($site . '/site.duo.json', \Duo\Canon::encode([
        'manifests' => ['core'],
        'policy' => ['options' => new stdClass(), 'post_types' => ['post'], 'taxonomies' => ['category']],
        'spec_version' => DUO_SPEC_VERSION,
    ]));
    $document = $contractFixture;
    $document['evidence_pins']['registry_sha256'] = $registry;
    if ($label === 'moved') {
        // The "without observable pins" half: a contract that declares no
        // manifest pins at all. The preflight's answer must not depend on
        // them — it deliberately supplies no OBSERVED pins to the projection
        // (a host process has observed no per-surface attribution), so the
        // documented no-observed-pins branch is what runs either way.
        $document['declarations']['manifest_pins'] = [];
    }
    foreach (($document['declarations']['external_effects'] ?? []) as $index => $effect) {
        if (($effect['decided_by'] ?? null) === 'unresolved') {
            $document['declarations']['external_effects'][$index]['decided_by'] = 'operator';
        }
    }
    // Re-digested by the contract's OWN digest function: `contract_digest`
    // addresses the document's content, so an edited fixture that kept the
    // shipped digest would be refused as a tamper before any evidence pin was
    // read, and the case would be about the wrong thing entirely.
    $document = \Duo\Orchestrator\ApplicationContract::withDigest($document);
    preflight_write($site . '/.duo/contract/contract.json', \Duo\Canon::encode($document) . "\n");

    [$contractExit, $contractReport] = preflight_at(
        $stateDriver,
        (string) $observedA['agent_version'],
        (int) $observedA['spec_version'],
        $fixtureLib,
        ['doctor', '--migration', '--repo=' . $site, '--format=json']
    );
    $row = $contractReport['contracts'][0] ?? [];
    duo_check_same(
        $expectedMode,
        (string) ($row['registry']['invalidation'] ?? ''),
        "F3/F4 contract-$label: the invalidation verdict is ContractProjection::generate()'s own, reached by "
        . 'calling it with the registry hash this run observed'
    );
    duo_check_same(
        $expectedMovement,
        in_array('contract_registry', preflight_ids($contractReport), true),
        "F3/F4 contract-$label: and a moved reviewed registry is predicted as an invalidation, an unmoved one is not"
    );
    duo_check_same(
        $expectedMovement ? 1 : 0,
        $contractExit,
        "F3/F4 contract-$label: exit code agrees with the verdict"
    );
    duo_check_same(
        'unsigned',
        (string) ($row['attestation']['verdict'] ?? ''),
        "F3/F4 contract-$label: an unsigned contract is reported unsigned rather than silently verified"
    );
}

// F5: a certificate shape the verb cannot classify. The signature is corrupted,
// so `verifyFile()` refuses with a bare RuntimeException — not one of the three
// reviewed typed signals — and this preflight has no reviewed migration outcome
// for it.
$unclassifiable = $sites . '/unclassifiable-certificate';
foreach (['site.duo.json', 'adapters/preflight-forms.json', 'adapters/authorities.json'] as $relative) {
    preflight_write($unclassifiable . '/' . $relative, (string) file_get_contents($certified . '/' . $relative));
}
// Mutated as BYTES, one base64 character of the signature, rather than decoded
// and re-encoded: the certificate is read through `readCanonicalObjectFile()`,
// so a re-encoded document would be refused for its FORMATTING and the case
// would never reach the signature check it is about. One character in, one
// character out keeps the length, the canonical base64 shape and every other
// byte of the file exactly as `duo adapter certify` wrote them.
$certificateBytes = (string) file_get_contents($certified . '/adapters/certifications/preflight-forms.json');
$mutated = preg_replace_callback(
    '/("signature": ")(.)/',
    static fn(array $m): string => $m[1] . ($m[2] === 'A' ? 'B' : 'A'),
    $certificateBytes,
    1,
    $mutations
);
duo_check_same(1, $mutations, 'F5 fixture: exactly one signature byte was mutated in the canonical certificate');
preflight_write($unclassifiable . '/adapters/certifications/preflight-forms.json', (string) $mutated);
[$unclassifiedExit, $unclassifiedDocument] = preflight_at(
    $stateDriver,
    (string) $observedA['agent_version'],
    (int) $observedA['spec_version'],
    $fixtureLib,
    ['doctor', '--migration', '--repo=' . $unclassifiable, '--format=json']
);
duo_check_same(1, $unclassifiedExit, 'F5 unclassifiable certificate: no green verdict — exit 1');
duo_check(
    (string) $unclassifiedDocument['status'] !== 'ok',
    'F5: and status is never `ok` while a shape is unclassified (' . (string) $unclassifiedDocument['status'] . ')'
);
$unclassifiedRow = null;
foreach ((array) $unclassifiedDocument['unclassified'] as $row) {
    if ((string) $row['what'] === 'certificate outcome') {
        $unclassifiedRow = $row;
    }
}
duo_check(
    is_array($unclassifiedRow),
    'F5: the refusal is an UNCLASSIFIED row naming the certificate, not silence — following '
    . 'CompiledArtifactReader::artifact_guidance()\'s posture that an unreviewed gate is a hard stop'
);
duo_check(
    str_contains((string) ($unclassifiedRow['detail'] ?? ''), 'invalid Ed25519 signature'),
    'F5: carrying the engine\'s own sentence byte for byte (' . (string) ($unclassifiedRow['detail'] ?? '') . ')'
);
duo_check_same(
    'unclassified',
    (string) ($unclassifiedDocument['certificates'][0]['outcome'] ?? ''),
    'F5: and the certificate row says `unclassified` rather than borrowing one of the three reviewed outcomes'
);
duo_check_same(
    'preflight-key',
    (string) ($unclassifiedDocument['certificates'][0]['key_id'] ?? ''),
    'F5: while STILL reporting which key id signed it — read facts survive a refused verification, because those '
    . 'two values are what tell an operator what to do next'
);
duo_check_same(
    'refused',
    (string) ($unclassifiedDocument['site']['load'] ?? ''),
    'F5: and this whole verdict was produced on a repository whose Policy::load() refuses outright — the state in '
    . 'which every other command is unavailable, which is the state this one is for'
);

// F6: a pin shape the verb cannot classify — an unknown pin key, which
// `PinResolver::normalize_manifest_pins()` refuses by name.
$badPins = $sites . '/unclassifiable-pin';
preflight_write($badPins . '/site.duo.json', \Duo\Canon::encode([
    'manifests' => [['name' => 'core', 'digest' => str_repeat('b', 64), 'flavour' => 'strawberry']],
    'policy' => ['options' => new stdClass(), 'post_types' => ['post'], 'taxonomies' => ['category']],
    'spec_version' => DUO_SPEC_VERSION,
]));
[$badPinExit, $badPinDocument] = preflight_at(
    $stateDriver,
    (string) $observedA['agent_version'],
    (int) $observedA['spec_version'],
    $fixtureLib,
    ['doctor', '--migration', '--repo=' . $badPins, '--format=json']
);
duo_check_same(1, $badPinExit, 'F6 unclassifiable pin shape: no green verdict — exit 1');
$pinShapeRow = null;
foreach ((array) $badPinDocument['unclassified'] as $row) {
    if ((string) $row['what'] === 'pin shape') {
        $pinShapeRow = $row;
    }
}
duo_check(
    is_array($pinShapeRow) && str_contains((string) $pinShapeRow['detail'], 'unknown pin key'),
    'F6: the normalizer\'s own refusal is the row, so a pin key nobody reviewed can never read as a pin that holds ('
    . (string) ($pinShapeRow['detail'] ?? '') . ')'
);

echo "\n== usage: the flag refuses what it cannot mean ==\n";
foreach ([
    [['adapter', 'doctor', '--migration'], '--migration needs the site it is about'],
    [['adapter', 'list', '--migration', '--repo=' . $certified], '--migration is a mode of `doctor`'],
    [['adapter', 'doctor', '--migration', '--repo=' . $certified, '--repo=' . $certified], "duplicate flag '--repo'"],
    [['adapter', 'doctor', '--migration', '--repo=' . $certified, '--artifact=/nonexistent/x.json'], "--artifact '/nonexistent/x.json' is not a file"],
] as [$argv, $expected]) {
    [$usageExit, , $usageErr] = preflight_run(array_merge([PHP_BINARY, $root . '/cli/duo'], $argv));
    duo_check_same(2, $usageExit, 'usage: `' . implode(' ', array_slice($argv, 0, 3)) . ' ...` exits 2, not 1');
    duo_check(
        str_contains($usageErr, $expected),
        'usage: and says why — ' . trim($usageErr)
    );
}

duo_check_summary('duo adapter doctor --migration preflight');
