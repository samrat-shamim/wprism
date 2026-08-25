<?php
/**
 * WP-4.7 — a certificate binds the compatibility CELLS it was exercised
 * against, not the platform document (spec/repo-format.md § v3.6).
 *
 * THE DEFECT THIS SUITE PINS
 * --------------------------
 * Verification used to be `hash_equals(Canon::encode($platform),
 * Canon::encode($statementTyped->platform))` — the whole
 * `manifests/capabilities/platform.json` record, byte for byte. AGENTS.md rule
 * 8 makes that file restate both `define()`s, so EVERY agent release moved it,
 * and every release therefore withdrew every certificate in the field: a patch
 * release that re-measured one PHP patch and moved no axis anyone exercised
 * cost the fleet all of its certified claims, silently, on upgrade. PART 1
 * below drives exactly that release and asserts the certificate SURVIVES it,
 * and PART 1's last case evaluates the retired predicate on the same two
 * documents so the suite states what the old rule would have answered rather
 * than asserting the absence of a behaviour.
 *
 * WHAT IS BOUND, AND THE ONE RULE THAT DECIDES IT
 * -----------------------------------------------
 * A cell's bound value is everything the boundary uses to ACCEPT a runtime on
 * that cell, and nothing it uses only to WITNESS one. The shipped boundary
 * declares three axis shapes and this suite drives all three (PART 2):
 *
 *   - `verified` series maps (`php`, `wordpress`) bind the SERIES names, never
 *     the exact patch beside them, because acceptance is series membership;
 *   - the `engines` map (`database`) binds each engine's own min/max line,
 *     because there the range is a function of the engine — the value IS the
 *     acceptance term;
 *   - an axis with neither (`filesystem`, `process`) is one reviewed PROFILE,
 *     bound whole minus its prose `note`.
 *
 * WHY THE NARROWING ARM IS DRIVEN AT `platformStatement()` AND NOT END TO END
 * --------------------------------------------------------------------------
 * § v3.5 narrowing reads a `spec_version: 3` manifest, and this engine's
 * acceptance window is exactly {1, 2} (§ v3.1) — so no narrowing adapter can
 * LOAD, and `sign_site()` takes its grammar verdict from the real loader. A
 * narrowing adapter is therefore unsignable end to end until WP-4.12 flips the
 * defines, and PART 3 drives the binding at the function that owns it, the way
 * `regress_adapter_environment_narrowing.php` drives the claim projection for
 * the identical reason. The arm every SIGNABLE adapter takes today — declare
 * nothing, bind the whole boundary — is driven end to end in PART 1.
 *
 * PART 4 is the wire generation: a v1-generation statement and an unimplemented
 * `version` are each withdrawn per adapter and never as a whole-source refusal,
 * and the cheap forgeries that would otherwise reach that degrade path are
 * proved to be refused as malformed first — WP-1.1's review hardening, kept.
 *
 * PART 5 is the operator round trip: `duo adapter keygen | certify | pin`,
 * end to end, minting on the /v2 wire and verifying back through the same call
 * the live policy path makes.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// THE CHILD PROBE. A PHP process holds one DUO_AGENT_VERSION, so "the agent
// released a patch" can only be driven by a second process that defines the
// bumped version before the engine loads — the same shape
// regress_spec_window.php uses for DUO_SPEC_VERSION. It runs before check.php
// is required and exits before any assertion, so the child never counts as a
// suite of its own.
// ---------------------------------------------------------------------------
$axisArgv = $_SERVER['argv'] ?? [];
if (($axisArgv[1] ?? null) === '--probe') {
    define('DUO_AGENT_VERSION', (string) ($axisArgv[2] ?? ''));
    define('DUO_SPEC_VERSION', (int) ($axisArgv[3] ?? 0));
    $probeRepo = dirname(__DIR__, 4);
    require_once $probeRepo . '/agent/src/Adapter/AdapterCertification.php';
    [$lib, $repo, $name, $certificate] = array_slice($axisArgv, 4, 4);
    $manifest = \Duo\Canon::decode(\Duo\Canon::read_file($repo . '/adapters/' . $name . '.json'));
    try {
        $verified = \Duo\AdapterCertification::verifyFile($lib, $repo, $name, $manifest, $certificate);
        echo json_encode([
            'outcome' => 'valid',
            'certification' => $verified['disposition']['certification'] ?? null,
            'platform_axes_sha256' => $verified['disposition']['provenance']['proof']['platform_axes_sha256'] ?? null,
            'agent_version' => DUO_AGENT_VERSION,
        ], JSON_UNESCAPED_SLASHES), "\n";
    } catch (Throwable $t) {
        echo json_encode([
            'outcome' => 'refused',
            'class' => get_class($t),
            'message' => $t->getMessage(),
            'agent_version' => DUO_AGENT_VERSION,
        ], JSON_UNESCAPED_SLASHES), "\n";
    }
    exit(0);
}

require_once __DIR__ . '/../../lib/check.php';

$duoRoot = dirname(__DIR__, 4);

function axis_rmtree(string $path): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach ((array) scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        axis_rmtree($path . '/' . $entry);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/duo_axis_binding_' . bin2hex(random_bytes(6));
mkdir($root . '/manifests/capabilities', 0755, true);
register_shutdown_function(static fn() => axis_rmtree($root));

// The scratch library IS the shipped one for every file this suite reads, and
// the boundary is copied byte for byte rather than authored: a hand-written
// boundary would stop being evidence about the document a real certificate
// binds, and the three axis shapes below are exactly the three the shipped
// file declares.
$lib = $root . '/manifests';
copy($duoRoot . '/manifests/core.json', $lib . '/core.json');
// WP-4.4 split the monolith: the reviewed claim source is now the
// dispositions/ directory (core's own entry plus profiles.json — the two
// documents a core-only library resolves), so the copy follows the layout the
// loader reads rather than a file the library no longer ships.
mkdir($lib . '/dispositions', 0755, true);
copy($duoRoot . '/manifests/dispositions/core.json', $lib . '/dispositions/core.json');
copy($duoRoot . '/manifests/dispositions/profiles.json', $lib . '/dispositions/profiles.json');
copy($duoRoot . '/manifests/capabilities/platform.json', $lib . '/capabilities/platform.json');
copy(
    $duoRoot . '/manifests/capabilities/adapter-authorities.json',
    $lib . '/capabilities/adapter-authorities.json'
);
// Set before the engine boots, because sign_site() refuses to sign against any
// library other than the one THIS process loads (siteGrammarVerdict()).
putenv('DUO_MANIFESTS_DIR=' . $lib);

require_once $duoRoot . '/cli/src/Adapter/AdapterCertify.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Policy;
use Duo\Orchestrator\AdapterCertify;

(new ReflectionMethod(AdapterCertify::class, 'boot'))->invoke(null);

if (realpath(Policy::manifests_dir()) !== realpath($lib)) {
    fwrite(STDERR, "the scratch library did not take: Policy::manifests_dir() is " . Policy::manifests_dir() . "\n");
    exit(1);
}

/** @return mixed */
function axis_private(string $class, string $method, array $args) {
    return (new ReflectionMethod($class, $method))->invokeArgs(null, $args);
}

/** @param list<string> $args @return array{exit:int,out:string} */
function axis_cli(array $args): array {
    ob_start();
    $exit = AdapterCertify::run($args);

    return ['exit' => $exit, 'out' => (string) ob_get_clean()];
}

/** The current scratch boundary, decoded. @return array<string,mixed> */
function axis_boundary(): array {
    global $lib;

    return Canon::decode(Canon::read_file($lib . '/capabilities/platform.json'))['platform'];
}

/** @param array<string,mixed> $platform */
function axis_write_boundary(array $platform): void {
    global $lib;
    Canon::write_file($lib . '/capabilities/platform.json', Canon::encode([
        'format' => ManifestDispositions::PLATFORM_FORMAT,
        'platform' => $platform,
    ]));
}

$shippedBoundary = axis_boundary();

/** A site repository holding one declarative adapter. @param array<string,mixed> $manifest */
function axis_site(string $label, array $manifest): string {
    global $root;
    $repo = $root . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    Canon::write_file($repo . '/adapters/' . $manifest['name'] . '.json', Canon::encode($manifest));
    Canon::write_file($repo . '/site.duo.json', Canon::encode([
        'manifests' => ['core'],
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));

    return $repo;
}

$subject = [
    'name' => 'axis-demo',
    'option_autoload' => 'preserve',
    'options' => ['axis_demo_layout' => ['class' => 'authored']],
    'option_namespaces' => [['match' => '^axis_demo_']],
    'post_types' => ['axis_demo_item' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
];

$repo = axis_site('site', $subject);
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$keyId = 'site-' . substr(hash('sha256', $public), 0, 12);
axis_private(AdapterCertify::class, 'registerAuthority', [
    $repo, $keyId, $public, 'axis-demo', AdapterSources::TIER_DECLARATIVE,
]);

$certificateRaw = AdapterCertification::sign_site(
    $lib,
    $repo,
    'axis-demo',
    $keyId,
    base64_encode($secret),
    'The operator reviewed this adapter against its own catalog schema.'
);
$certPath = AdapterCertify::writeCertificate($repo, 'axis-demo', $certificateRaw);
$certificate = Canon::decode($certificateRaw);

/** Verify the subject certificate against whatever boundary is installed now. */
function axis_verify(): array {
    global $lib, $repo, $subject, $certPath;
    try {
        return ['outcome' => 'valid', 'verified' => AdapterCertification::verifyFile(
            $lib,
            $repo,
            'axis-demo',
            $subject,
            $certPath
        )];
    } catch (Throwable $t) {
        return ['outcome' => 'refused', 'class' => get_class($t), 'message' => $t->getMessage()];
    }
}

echo "\n== the minted statement: what a /v2 certificate actually says ==\n";

duo_check_same(
    "duo-site-adapter-certification-signature/v2\0",
    (string) (new ReflectionClass(AdapterCertification::class))->getConstant('SIGNATURE_DOMAIN'),
    'the signer minted under the /v2 domain — the binding semantics changed, so per R-01 this is a NEW statement '
    . 'type verified beside the old one, never an edit of it'
);
duo_check_same(
    2,
    $certificate['statement']['version'] ?? null,
    'and the statement states its own generation INSIDE the signature, which is the member the v1 statement could '
    . 'not grow (R-06/R-24)'
);
$signedPlatform = $certificate['statement']['platform'] ?? [];
duo_check_same(
    ['agent_version', 'axes', 'site_mode', 'spec_version'],
    (static function (array $p): array {
        $keys = array_keys($p);
        sort($keys, SORT_STRING);
        return $keys;
    })(is_array($signedPlatform) ? $signedPlatform : []),
    'statement.platform is the four members R-23 records — a boundary DIGEST surface, not a boundary copy'
);
duo_check_same(
    ['database', 'filesystem', 'php', 'process', 'wordpress'],
    (static function (array $axes): array {
        $names = array_keys($axes);
        sort($names, SORT_STRING);
        return $names;
    })((array) ($signedPlatform['axes'] ?? [])),
    'and it binds all five compatibility axes the shipped boundary declares, `process` (#560) included — an axis '
    . 'the signer skipped would be coverage nothing could ever be checked against'
);
duo_check_same(
    ['8.3', '8.4'],
    (array) ($signedPlatform['axes']['php']['cells'] ?? []),
    'the php axis binds the exercised SERIES names out of `verified`, not the patches beside them'
);
duo_check_same(
    ['MariaDB', 'MySQL'],
    (array) ($signedPlatform['axes']['database']['cells'] ?? []),
    'the database axis binds each claimed ENGINE, because there the accepted range is a function of the engine'
);
duo_check_same(
    ['local-posix-atomic-rename-flock-fsync/v1'],
    (array) ($signedPlatform['axes']['filesystem']['cells'] ?? []),
    'and an axis with neither series member binds one reviewed PROFILE, named by the profile string'
);
duo_check_same(
    $shippedBoundary['agent_version'],
    $signedPlatform['agent_version'] ?? null,
    'agent_version is RECORDED inside the signature — an operator still has to be told which agent state a '
    . 'certificate was minted beside, and a fact inside the signature cannot be forged'
);

$baseline = axis_verify();
duo_check_same('valid', $baseline['outcome'], 'baseline: the freshly minted certificate verifies (' . ($baseline['message'] ?? '') . ')');
$baselineProof = $baseline['verified']['disposition']['provenance']['proof'] ?? [];
duo_check(
    is_string($baselineProof['platform_axes_sha256'] ?? null)
        && !array_key_exists('platform_sha256', $baselineProof),
    'the proof records `platform_axes_sha256` and NOT `platform_sha256` — the value was renamed with its meaning, '
    . 'because ContractAttestation\'s whole-boundary `platform_sha256` still exists and means something else'
);

echo "\n== PART 1 — an agent PATCH release that moves no bound axis ==\n";

// The release, built the way a release actually builds it: the shipped
// document with the two defines restated (AGENTS.md rule 8 forces that) and one
// axis note re-worded, which is what a live-matrix re-run edits.
$patchVersion = (static function (string $version): string {
    $parts = explode('.', $version);
    $parts[2] = (string) (((int) ($parts[2] ?? '0')) + 1);
    return implode('.', $parts);
})((string) $shippedBoundary['agent_version']);

$patchBoundary = $shippedBoundary;
$patchBoundary['agent_version'] = $patchVersion;
$patchBoundary['compatibility']['php']['note'] = $shippedBoundary['compatibility']['php']['note']
    . ' Re-verified live on the patch release.';
// A re-measured PHP patch inside an ALREADY exercised series: new evidence for
// the same cell, which § v3.6 names as the case that must add coverage and
// invalidate nothing.
$patchBoundary['compatibility']['php']['verified']['8.3'] = '8.3.34';
axis_write_boundary($patchBoundary);

$probe = (static function (array $args): array {
    $pipes = [];
    $process = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'cannot start the probe'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $out, 'stderr' => $err];
})([
    PHP_BINARY, __FILE__, '--probe', $patchVersion, (string) DUO_SPEC_VERSION,
    $lib, $repo, 'axis-demo', $certPath,
]);
duo_check_same(0, $probe['exit'], 'the patch-release probe process runs (stderr: ' . trim($probe['stderr']) . ')');
$released = json_decode($probe['stdout'], true);
duo_check(is_array($released), 'and reports a verdict as JSON (' . trim($probe['stdout']) . ')');
duo_check_same(
    $patchVersion,
    $released['agent_version'] ?? null,
    'the probe really is a DIFFERENT agent: it defines the bumped DUO_AGENT_VERSION before the engine loads, '
    . 'which is the only way one process can be one agent'
);
duo_check_same(
    'valid',
    $released['outcome'] ?? null,
    'THE CASE THIS RIDER EXISTS FOR: an agent patch release that moves agent_version, an axis note and one '
    . 'already-exercised PHP patch leaves the certificate VALID (' . ($released['message'] ?? '') . ')'
);
duo_check_same(
    'certified',
    $released['certification'] ?? null,
    'and the adapter is still CERTIFIED under the released agent — no withdrawal, no re-signing, no operator '
    . 'action for a condition the site did not cause'
);
duo_check_same(
    $baselineProof['platform_axes_sha256'] ?? null,
    $released['platform_axes_sha256'] ?? null,
    'the axes digest folded into the adapter digest is UNMOVED, so the release is pin-neutral too: a certificate '
    . 'that merely re-verified would still have moved every site.duo.json content pin that binds it'
);
// The retired predicate, evaluated on the same two documents. Stated rather
// than asserted-by-absence: this is the exact expression verifyCertificate()
// ran until WP-4.7, and it is what made the release above a fleet-wide
// withdrawal.
duo_check(
    !hash_equals(Canon::encode($shippedBoundary), Canon::encode($patchBoundary)),
    'FAILING-BEFORE, in one line: the retired rule was hash_equals() over the WHOLE boundary record, and these '
    . 'two documents are NOT equal — so every certificate in the field was withdrawn by this release under the '
    . 'previous engine, including certificates for adapters nothing about the release touched'
);

echo "\n== PART 2 — what a boundary edit does, cell by cell ==\n";

// (a) An axis the boundary GAINS. Coverage this certificate never claimed.
$gained = $shippedBoundary;
$gained['compatibility']['php']['verified']['8.5'] = '8.5.1';
$gained['compatibility']['php']['max'] = '8.6.0';
axis_write_boundary($gained);
$gainedVerdict = axis_verify();
duo_check_same(
    'valid',
    $gainedVerdict['outcome'],
    '(a) recording a NEWLY exercised series adds coverage and invalidates nothing — the certificate never claimed '
    . 'it, and `max` moves in the same edit, which is why the range is outside every binding ('
    . ($gainedVerdict['message'] ?? '') . ')'
);

// (b) A bound cell the boundary drops — the live matrix's own documented
// remedy ("drop the 7.1 entry ... never widen around a failure").
$dropped = $shippedBoundary;
$dropped['compatibility']['wordpress']['verified'] = array_diff_key(
    $dropped['compatibility']['wordpress']['verified'],
    ['7.1' => true]
);
$dropped['compatibility']['wordpress']['last_verified'] = '7.0';
$dropped['compatibility']['wordpress']['max'] = '7.1.0';
axis_write_boundary($dropped);
$droppedVerdict = axis_verify();
duo_check_same(
    'Duo\\StalePlatformSiteAdapterCertificate',
    $droppedVerdict['class'] ?? $droppedVerdict['outcome'],
    '(b) a bound cell the boundary NO LONGER CARRIES withdraws the claim, through the typed signal — one adapter '
    . 'degrades, the site does not refuse'
);
duo_check(
    str_contains((string) ($droppedVerdict['message'] ?? ''), "'wordpress' cell '7.1'"),
    '(b) and the refusal names the axis AND the cell, so an operator is not sent to diff a whole document ('
    . ($droppedVerdict['message'] ?? '') . ')'
);

// (c) A bound cell whose ACCEPTANCE TERMS move. On the engines axis the value
// is the acceptance term, so widening MySQL is a different cell wearing the
// same name — not new evidence for the old one.
$widenedEngine = $shippedBoundary;
$widenedEngine['compatibility']['database']['engines']['MySQL']['max'] = '9.0.0';
axis_write_boundary($widenedEngine);
$engineVerdict = axis_verify();
duo_check_same(
    'Duo\\StalePlatformSiteAdapterCertificate',
    $engineVerdict['class'] ?? $engineVerdict['outcome'],
    '(c) widening an ENGINE\'s version line withdraws the claim: on that axis the range IS the acceptance term, '
    . 'so the cell now admits a runtime nobody ran'
);
duo_check(
    str_contains((string) ($engineVerdict['message'] ?? ''), "compatibility axis 'database'"),
    '(c) naming the axis whose exercised cells the boundary now states differently ('
    . ($engineVerdict['message'] ?? '') . ')'
);

// (d) A profile axis, moved where it counts and where it does not.
$noteOnly = $shippedBoundary;
$noteOnly['compatibility']['process']['note'] = 'Reworded after a live run.';
axis_write_boundary($noteOnly);
duo_check_same(
    'valid',
    axis_verify()['outcome'],
    '(d) an axis NOTE is prose that is edited on every live matrix run and moves no runtime, so it is outside the '
    . 'binding on a profile axis exactly as it is on a series one'
);
$profileMoved = $shippedBoundary;
$profileMoved['compatibility']['process']['required_functions'][] = 'pcntl_fork';
sort($profileMoved['compatibility']['process']['required_functions'], SORT_STRING);
axis_write_boundary($profileMoved);
duo_check_same(
    'Duo\\StalePlatformSiteAdapterCertificate',
    axis_verify()['class'] ?? '(valid)',
    '(d) but a function the profile now REQUIRES withdraws it — the gate demands something the certified run was '
    . 'never proved against'
);

// (e) The two top-level members that are prose about the agent's posture.
$posture = $shippedBoundary;
$posture['branchable_state'] = 'a differently worded statement of the same posture';
axis_write_boundary($posture);
duo_check_same(
    'valid',
    axis_verify()['outcome'],
    '(e) `branchable_state` is outside the binding: it is prose about the AGENT, not a runtime cell anything was '
    . 'exercised against, and a claim restates it from the boundary installed now'
);
// `site_mode` IS bound, and this case is driven at the function that owns the
// binding rather than through verifyFile(), because currentPlatform() pins the
// member to 'single-site' before the binding is ever consulted ("agent
// capability platform boundary disagrees with the loaded agent"). So the
// member is defence in depth against the day a multisite boundary ships — and
// defence in depth that nothing exercises is decoration, which is why it is
// exercised here rather than asserted in a comment.
$multisiteBoundary = $shippedBoundary;
$multisiteBoundary['site_mode'] = 'multisite';
$modeRefusal = null;
try {
    axis_private(AdapterCertification::class, 'assertPlatformBinding', [
        'axis-demo',
        $multisiteBoundary,
        $signedPlatform,
    ]);
} catch (Throwable $t) {
    $modeRefusal = $t;
}
duo_check(
    $modeRefusal instanceof \Duo\StalePlatformSiteAdapterCertificate
        && str_contains($modeRefusal->getMessage(), "binds site mode 'single-site'"),
    '(e) but `site_mode` IS bound — it is one of the four cells a claim states and one of the four § v3.5 lets an '
    . 'adapter narrow, so a certificate that did not bind it could name a mode nobody exercised ('
    . ($modeRefusal === null ? 'no refusal' : $modeRefusal->getMessage()) . ')'
);

axis_write_boundary($shippedBoundary);
duo_check_same(
    'valid',
    axis_verify()['outcome'],
    'and restoring the shipped boundary restores the claim — every refusal above was the edit and nothing else'
);

echo "\n== PART 3 — a § v3.5 narrowing adapter binds its NARROWED cells ==\n";

$narrowing = $subject;
$narrowing['spec_version'] = 3;
$narrowing['environment'] = ['php' => ['8.3']];
$narrowStatement = axis_private(AdapterCertification::class, 'platformStatement', [$shippedBoundary, $narrowing]);
duo_check_same(
    ['8.3'],
    (array) (((array) $narrowStatement['axes'])['php']['cells'] ?? []),
    'a narrowing adapter binds the cells it declared, not the whole axis — the signer reads '
    . 'ManifestDispositions::narrowed_environment(), so the BINDING and the CLAIM are one function'
);
duo_check_same(
    ['MariaDB', 'MySQL'],
    (array) (((array) $narrowStatement['axes'])['database']['cells'] ?? []),
    'and an axis it did NOT narrow still binds whole, so narrowing is per axis rather than per certificate'
);
duo_check_same(
    ['local-posix-process-group-exec/v1'],
    (array) (((array) $narrowStatement['axes'])['process']['cells'] ?? []),
    'including the two axes no claim states (`filesystem`, `process`): they are absent from the narrowing '
    . 'projection by design, so a certificate binds the boundary\'s own profile for them'
);
$wholeStatement = axis_private(AdapterCertification::class, 'platformStatement', [$shippedBoundary, $subject]);
duo_check_same(
    ['8.3', '8.4'],
    (array) (((array) $wholeStatement['axes'])['php']['cells'] ?? []),
    'an adapter declaring NOTHING binds the whole boundary, which is what every signable adapter does today — the '
    . 'acceptance window is {1, 2}, so a spec_version 3 manifest cannot load at all yet'
);
duo_check_same(
    Canon::encode($signedPlatform),
    Canon::encode($wholeStatement),
    'and that projection is byte-identical to the member the real signer put in the certificate above, so PART 3 '
    . 'is driving the same function the product path drives'
);

$widerDeclaration = $subject;
$widerDeclaration['spec_version'] = 3;
$widerDeclaration['environment'] = ['php' => ['8.3', '9.0']];
$widerRefusal = null;
try {
    axis_private(AdapterCertification::class, 'platformStatement', [$shippedBoundary, $widerDeclaration]);
} catch (Throwable $t) {
    $widerRefusal = $t;
}
duo_check(
    $widerRefusal !== null && str_contains($widerRefusal->getMessage(), 'never widen it')
        && str_contains($widerRefusal->getMessage(), '9.0'),
    'an adapter declaring a WIDER environment than the reviewed boundary refuses AT MINT, naming the axis and the '
    . 'cell — the refusal fires before a signature byte exists rather than when a site first loads the '
    . 'certificate (' . ($widerRefusal === null ? 'no refusal' : $widerRefusal->getMessage()) . ')'
);

echo "\n== PART 4 — the wire generation, and the ordering that guards it ==\n";

/**
 * Verify a hand-built certificate document against the shipped boundary.
 *
 * Written at the DERIVED path rather than a scratch one: the certificate path
 * is inside the signed statement (R-07), so the verifier refuses any other
 * location before it reads a single member — and a case that landed on that
 * refusal would be asserting nothing about the wire. The genuine certificate is
 * restored afterwards so the cases stay independent of each other's order.
 */
function axis_verify_document(array $document): array {
    global $lib, $repo, $subject, $certPath, $certificateRaw;
    Canon::write_file($certPath, Canon::encode($document));
    try {
        AdapterCertification::verifyFile($lib, $repo, 'axis-demo', $subject, $certPath);
        $outcome = ['outcome' => 'valid'];
    } catch (Throwable $t) {
        $outcome = ['outcome' => 'refused', 'class' => get_class($t), 'message' => $t->getMessage()];
    }
    Canon::write_file($certPath, $certificateRaw);

    return $outcome;
}

// A v1-GENERATION statement: exactly the five members R-06 closed. Built by
// removing `version` from a genuine, correctly-signed statement, which is also
// the cheapest downgrade an attacker with write access can author — so this one
// document proves both halves at once.
$v1Statement = $certificate;
unset($v1Statement['statement']['version']);
$v1Verdict = axis_verify_document($v1Statement);
duo_check_same(
    'Duo\\SupersededWireSiteAdapterCertificate',
    $v1Verdict['class'] ?? $v1Verdict['outcome'],
    'a v1-generation statement is refused BY NAME through the typed withdrawal, never as an unparseable statement '
    . '— which would have been a whole-source refusal taking the site\'s unrelated adapters with it'
);
duo_check(
    str_contains((string) ($v1Verdict['message'] ?? ''), 'wire generation 1')
        && str_contains((string) ($v1Verdict['message'] ?? ''), 'it verifies generation 2'),
    'and the refusal says which generation it read and which it verifies (' . ($v1Verdict['message'] ?? '') . ')'
);

$futureStatement = $certificate;
$futureStatement['statement']['version'] = 3;
$futureVerdict = axis_verify_document($futureStatement);
duo_check_same(
    'Duo\\SupersededWireSiteAdapterCertificate',
    $futureVerdict['class'] ?? $futureVerdict['outcome'],
    'an in-statement version this engine does not implement takes the same route — refused BY VERSION rather than '
    . 'read as corruption, which is the whole reason `version` had to arrive with the domain'
);
duo_check(
    str_contains((string) ($futureVerdict['message'] ?? ''), 'wire generation 3'),
    'naming the generation it was handed (' . ($futureVerdict['message'] ?? '') . ')'
);

// THE ORDERING, kept from WP-1.1's review hardening: every unconditional shape
// proof runs BEFORE any degrade signal, so the cheapest hand-authored files are
// refused as malformed and never reach the withdrawal.
$bareVersion = ['format' => AdapterCertification::FORMAT, 'signature' => $certificate['signature'],
    'statement' => ['version' => 2]];
$bareVerdict = axis_verify_document($bareVersion);
duo_check(
    ($bareVerdict['class'] ?? '') === 'RuntimeException'
        && str_contains((string) ($bareVerdict['message'] ?? ''), 'must contain exactly'),
    'a statement carrying `version` and nothing else is refused as MALFORMED, not degraded — the closed member '
    . 'set is proved before the generation is read (' . ($bareVerdict['message'] ?? '') . ')'
);
$brokenMember = $certificate;
$brokenMember['statement']['platform'] = 'not an object';
$brokenVerdict = axis_verify_document($brokenMember);
duo_check(
    ($brokenVerdict['class'] ?? '') === 'RuntimeException'
        && str_contains((string) ($brokenVerdict['message'] ?? ''), 'statement.platform must be an object'),
    'and so is a well-membered statement whose `platform` is a scalar — the object-ness of all five common '
    . 'members is proved before either generation test, so a v1-shaped forgery cannot be cheap ('
    . ($brokenVerdict['message'] ?? '') . ')'
);
$malformedAxis = $certificate;
$malformedAxis['statement']['platform']['axes']['php'] = ['cells' => ['8.3'], 'sha256' => 'not-a-digest'];
$malformedAxisVerdict = axis_verify_document($malformedAxis);
duo_check(
    ($malformedAxisVerdict['class'] ?? '') === 'RuntimeException'
        && str_contains((string) ($malformedAxisVerdict['message'] ?? ''), 'sha256 digest of its exercised cells'),
    'a malformed AXIS binding is a corrupt certificate (hard refusal), never an agent-owned document that moved '
    . '(the typed withdrawal) — folding the two would make a hand-edited statement look like an ordinary upgrade ('
    . ($malformedAxisVerdict['message'] ?? '') . ')'
);
$forgedDigest = $certificate;
$forgedDigest['statement']['platform']['axes']['php']['sha256'] = str_repeat('0', 64);
$forgedVerdict = axis_verify_document($forgedDigest);
duo_check(
    ($forgedVerdict['class'] ?? '') === 'RuntimeException'
        && str_contains((string) ($forgedVerdict['message'] ?? ''), 'invalid Ed25519 signature'),
    'and a well-formed axis digest that was EDITED fails on the signature, because the whole platform member is '
    . 'inside the signed bytes — the binding narrowed what invalidates, not what is signed ('
    . ($forgedVerdict['message'] ?? '') . ')'
);

echo "\n== PART 5 — keygen | certify | pin, end to end on the /v2 wire ==\n";

$keyDir = $root . '/keys';
mkdir($keyDir, 0755, true);
// Its own adapter, not a near-copy of the subject: option keys and namespaces
// must be namespaced to the adapter that owns them or the grammar refuses.
$roundTripManifest = [
    'name' => 'axis-roundtrip',
    'option_autoload' => 'preserve',
    'options' => ['axis_roundtrip_layout' => ['class' => 'authored']],
    'option_namespaces' => [['match' => '^axis_roundtrip_']],
    'post_types' => ['axis_roundtrip_item' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
];
$roundTripRepo = axis_site('roundtrip', $roundTripManifest);

$keygen = axis_cli(['keygen', '--out=' . $keyDir . '/org.key']);
duo_check_same(0, $keygen['exit'], 'keygen exits 0');
duo_check(
    preg_match('/^key-id:\s+(site-[0-9a-f]{12})$/m', $keygen['out'], $keyMatch) === 1,
    'and derives a fingerprint-bound key id (' . trim($keygen['out']) . ')'
);
$roundTripKeyId = $keyMatch[1] ?? '';

$certify = axis_cli([
    'certify', $roundTripRepo, '--name=axis-roundtrip',
    '--secret-key-file=' . $keyDir . '/org.key',
    '--reason=The operator reviewed this adapter against its own schema.',
    '--pin',
]);
duo_check_same(0, $certify['exit'], 'certify --pin exits 0 (' . trim($certify['out']) . ')');
$roundTripCertPath = $roundTripRepo . '/adapters/certifications/axis-roundtrip.json';
duo_check(is_file($roundTripCertPath), 'and writes the certificate at its derived path');
$roundTripCertificate = Canon::decode(Canon::read_file($roundTripCertPath));
duo_check_same(
    2,
    $roundTripCertificate['statement']['version'] ?? null,
    '`duo adapter certify` mints the /v2 generation from now on — the operator verb and the agent signer are one '
    . 'code path, so there is no second wire to keep in step'
);
duo_check_same(
    Canon::encode($signedPlatform['axes'] ?? []),
    Canon::encode($roundTripCertificate['statement']['platform']['axes'] ?? []),
    'and it binds the same exercised cells the agent signer bound, for the same boundary'
);

$sitePolicy = Canon::decode(Canon::read_file($roundTripRepo . '/site.duo.json'));
$pinned = null;
foreach ((array) ($sitePolicy['manifests'] ?? []) as $pin) {
    if (is_array($pin) && ($pin['name'] ?? null) === 'axis-roundtrip') {
        $pinned = $pin;
    }
}
duo_check(
    is_array($pinned) && ($pinned['source'] ?? null) === AdapterSources::SITE
        && preg_match('/^[0-9a-f]{64}$/D', (string) ($pinned['digest'] ?? '')) === 1,
    'the pin --pin wrote is the source-qualified content pin an operator holds ('
    . json_encode($pinned, JSON_UNESCAPED_SLASHES) . ')'
);

$roundTripVerified = AdapterCertification::verifyFile(
    $lib,
    $roundTripRepo,
    'axis-roundtrip',
    $roundTripManifest,
    $roundTripCertPath
);
$roundTripSummary = AdapterCertification::certificateSummary($roundTripVerified);
duo_check_same('certified', $roundTripSummary['status'] ?? null, 'the live verifier reports a CERTIFIED claim');
duo_check_same('site', $roundTripSummary['trust_root'] ?? null, 'under the operator\'s own trust root');
duo_check_same(
    $roundTripKeyId,
    $roundTripSummary['authority']['key_id'] ?? null,
    'signed by the key keygen minted'
);
duo_check_same(
    ($roundTripVerified['disposition']['provenance']['proof']['platform_axes_sha256'] ?? null),
    hash('sha256', Canon::encode((object) ($roundTripCertificate['statement']['platform']['axes'] ?? []))),
    'and the pinned identity folds the AXES digest — a release that moves no exercised cell moves no pin either, '
    . 'which is what makes this rider a stable identity rather than only a valid certificate'
);

duo_check_summary('certificate axis binding');
