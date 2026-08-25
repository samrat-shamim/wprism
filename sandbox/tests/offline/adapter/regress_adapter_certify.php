<?php
/**
 * Offline contract for `duo adapter keygen | certify | pin` (round-3 T6 §3.5).
 *
 * Three things this suite is for, in the order they matter.
 *
 * **1. The whole verb, end to end, against the real signer.** `certify`
 * registers the operator's key in the site trust root, calls
 * `AdapterCertification::sign_site()`, writes the certificate at its derived
 * path, and verifies it back through `verifyFile()` — the same call the live
 * policy path makes. The bundle itself is the AGENT's (T6 §3.5 as landed);
 * this suite owns the three mutations around it and proves the composition
 * produces a `certified` claim under a `site` trust root.
 *
 * A manifest exercising every branch of the agent's derived ratification is
 * used deliberately — a declared plugin, an entity section, three field
 * sections, a default-authored keyspace and an intent-only table — because
 * `ManifestDispositions::validate_entry()` checks the derived entry against
 * the manifest in five directions, and a fixture that dodged them would let
 * a real adapter fail where this passed.
 *
 * **2. The three mutations refuse before they damage anything.** A private
 * key inside a repository that gets committed is a published key; an
 * overwritten key orphans every certificate it signed; a rewritten
 * `site.duo.json` that flattened `"policy": {}` into `[]` would produce a
 * file the engine refuses to load. Each is asserted rather than reasoned
 * about — the third was a real defect caught by the first smoke run of
 * `duo adapter pin`, and the ordering rule below by the first run of
 * `duo adapter certify`.
 *
 * **3. A failed certify leaves the repository as it found it.** The
 * pre-flight grammar check runs BEFORE the key is registered, so an adapter
 * the engine will not load never causes a write.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$duoRoot = dirname(__DIR__, 4);
require_once $duoRoot . '/cli/src/Adapter/AdapterCertify.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\Orchestrator\AdapterCertify;

// -------------------------------------------------------------------- harness

/** Boot the engine into this WordPress-free process, exactly as the verb does. */
// No setAccessible(): it has been a no-op since PHP 8.1 and is DEPRECATED in
// 8.5, and composer.json floors this repo at >=8.3. Calling it emitted a
// deprecation on every invocation, which is noise in a suite whose whole job
// is to make a real failure legible.
(new ReflectionMethod(AdapterCertify::class, 'boot'))->invoke(null);

/** @return mixed */
function cert_private(string $method, array $args) {
    return (new ReflectionMethod(AdapterCertify::class, $method))->invokeArgs(null, $args);
}

function cert_rmtree(string $path): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach ((array) scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        cert_rmtree($path . '/' . $entry);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/duo_adapter_certify_' . bin2hex(random_bytes(6));
mkdir($root, 0755, true);
register_shutdown_function(static fn() => cert_rmtree($root));

/**
 * Run `duo adapter <args>` in-process and capture stdout.
 *
 * In-process rather than through a subprocess because most cases below assert
 * the exit code, and a subprocess would add a PHP boot per case to a suite
 * that already runs dozens. The cases that assert a refusal's WORDING use
 * cert_run_cli() instead.
 *
 * @param list<string> $args
 * @return array{exit:int,out:string,err:string}
 */
function cert_run(array $args): array {
    // Only stdout is captured here: STDERR is a constant this process cannot
    // rebind, so a refusal's SENTENCE needs the subprocess helper below. The
    // exit code is the contract every failing path shares (2 for usage/IO),
    // and it is what most cases here assert.
    ob_start();
    $exit = AdapterCertify::run($args);

    return ['exit' => $exit, 'out' => (string) ob_get_clean(), 'err' => ''];
}

/**
 * The same run with STDERR captured, for the cases whose sentence matters.
 *
 * A subprocess is the only way to read what a command wrote to the STDERR
 * constant, so the handful of refusal-wording cases pay for one.
 *
 * @param list<string> $args
 * @return array{exit:int,out:string,err:string}
 */
function cert_run_cli(array $args): array {
    global $duoRoot;
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $duoRoot . '/cli/duo', 'adapter'], $args),
        $descriptors,
        $pipes
    );
    if (!is_resource($process)) {
        return ['exit' => -1, 'out' => '', 'err' => 'cannot start duo'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
}

/** A site repository with one adapter installed. @param array<string,mixed> $manifest */
function cert_site(string $root, string $label, array $manifest, array $pins = ['core']): string {
    $repo = $root . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    $name = (string) $manifest['name'];
    Canon::write_file($repo . '/adapters/' . $name . '.json', Canon::encode($manifest));
    Canon::write_file($repo . '/site.duo.json', Canon::encode([
        'manifests' => $pins,
        // An EMPTY JSON object, deliberately: PHP erases {} vs [] on an
        // associative round trip, and a writer that did so would rewrite this
        // into a list the engine refuses. See the pin cases below.
        'policy' => new stdClass(),
        'spec_version' => DUO_SPEC_VERSION,
    ]));

    return $repo;
}

/**
 * A scratch agent manifest library holding the operator's public key.
 *
 * @return array{dir:string,key_id:string,secret:string}
 */
function cert_agent_library(string $root, string $label, array $adapterNames, array $tiers): array {
    global $duoRoot;
    $dir = $root . '/' . $label;
    mkdir($dir . '/capabilities', 0755, true);
    copy($duoRoot . '/manifests/core.json', $dir . '/core.json');
    copy($duoRoot . '/manifests/dispositions.json', $dir . '/dispositions.json');

    // The shipped platform boundary verbatim: the exercised compatibility cells
    // inside every signed statement are read out of these exact bytes
    // (spec/repo-format.md § v3.6), so a fixture that re-authored them would
    // sign against a platform no agent runs.
    copy(
        $duoRoot . '/manifests/capabilities/platform.json',
        $dir . '/capabilities/platform.json'
    );

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);
    $keyId = 'site-' . substr(hash('sha256', $public), 0, 12);
    $keys = new stdClass();
    $keys->{$keyId} = [
        'adapter_names' => $adapterNames,
        'algorithm' => 'ed25519',
        'public_key' => base64_encode($public),
        'scope' => 'site_adapter_certification',
        'status' => 'trusted',
        'trust_tiers' => $tiers,
    ];
    Canon::write_file($dir . '/capabilities/adapter-authorities.json', Canon::encode([
        'format' => AdapterCertification::AUTHORITIES_FORMAT,
        'keys' => $keys,
    ]));

    return ['dir' => $dir, 'key_id' => $keyId, 'secret' => $secret];
}

// ------------------------------------------------------------------- keygen

$keyDir = $root . '/keys';
mkdir($keyDir, 0755, true);

$keygen = cert_run(['keygen', '--out=' . $keyDir . '/org.key']);
duo_check_same(0, $keygen['exit'], 'keygen exits 0');
duo_check(
    is_file($keyDir . '/org.key'),
    'keygen writes the secret to the path the operator named'
);
duo_check_same(
    '0600',
    substr(sprintf('%o', fileperms($keyDir . '/org.key')), -4),
    'the secret key file is mode 0600 — a group/world-readable private key is not one'
);
$keygenKeyId = null;
if (preg_match('/^key-id:\s+(\S+)$/m', $keygen['out'], $m) === 1) {
    $keygenKeyId = $m[1];
}
duo_check(
    is_string($keygenKeyId) && str_starts_with($keygenKeyId, 'site-'),
    'keygen prints the key id it derived (' . var_export($keygenKeyId, true) . ')'
);
duo_check(
    preg_match('/^public-key:\s+\S{40,}=*$/m', $keygen['out']) === 1,
    'keygen prints the public key, which is what goes in a trust root'
);
duo_check(
    !str_contains($keygen['out'], (string) file_get_contents($keyDir . '/org.key')),
    'keygen never prints the SECRET key to stdout'
);
duo_check(
    str_contains($keygen['out'], 'not a Duo one'),
    'keygen states out loud that this is a customer-organization trust root'
);

$rerun = cert_run_cli(['keygen', '--out=' . $keyDir . '/org.key']);
duo_check_same(2, $rerun['exit'], 'keygen refuses to overwrite an existing private key');
duo_check(
    str_contains($rerun['err'], 'never overwrites a private key'),
    'and says why — every certificate that key signed would be orphaned'
);

// T6 §3.1: "Private keys never live in the repository." `duo init`'s own next
// steps tell the operator to `git add .`, so a key anywhere under a site repo
// is a key they are about to publish.
$keyRepo = cert_site($root, 'keyrepo', [
    'name' => 'keeper',
    'option_autoload' => 'preserve',
    'options' => ['keeper_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
]);
mkdir($keyRepo . '/secrets/deep', 0755, true);
foreach (['org.key', 'secrets/deep/org.key'] as $inside) {
    $refusal = cert_run_cli(['keygen', '--out=' . $keyRepo . '/' . $inside]);
    duo_check_same(2, $refusal['exit'], "keygen refuses a secret path inside a site repository ($inside)");
    duo_check(
        str_contains($refusal['err'], '[secret_key_inside_repository]')
            && str_contains($refusal['err'], 'inside the duo site repository'),
        "and names the repository it found ($inside) under its typed reason code — the walk is up the ancestors, not one level"
    );
    duo_check(!is_file($keyRepo . '/' . $inside), "and writes nothing ($inside)");
}

duo_check_same(2, cert_run(['keygen'])['exit'], 'keygen without --out is a usage error');
duo_check_same(
    2,
    cert_run(['keygen', '--out=' . $keyDir . '/a.key', '--out=' . $keyDir . '/b.key'])['exit'],
    'a repeated flag is refused rather than last-wins'
);
duo_check_same(
    2,
    cert_run(['keygen', '--out=' . $keyDir . '/c.key', '--nope=1'])['exit'],
    'an unsupported flag is refused'
);

// --------------------------------------------------------- the signing round trip

/**
 * A manifest that exercises every branch of the derived disposition:
 * a declared plugin (so supported_versions must mirror its contract), an
 * entity section, three field sections, a default-authored keyspace (which
 * must appear one-for-one in default_authored_keyspaces), and an intent-only
 * table (which MUST be marked unsupported or validate_entry() refuses).
 */
$rich = [
    'name' => 'acme-catalog',
    'option_autoload' => 'preserve',
    'options' => ['acme_catalog_layout' => ['class' => 'authored']],
    'option_namespaces' => [['match' => '^acme_catalog_']],
    'plugin' => 'acme-catalog/acme-catalog.php',
    'post_meta' => ['_acme_catalog_ref' => ['class' => 'authored']],
    'post_types' => ['acme_item' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
    'tables' => [
        'acme_catalog_index' => [
            'class' => 'authored_snapshot',
            'columns' => ['label' => ['class' => 'authored']],
            'default_class' => 'authored',
            'identity' => ['mode' => 'mapped'],
            'pk' => 'id',
        ],
        'acme_catalog_intent' => [
            'class' => 'authored_typed_snapshot_post_v1',
            'columns' => ['label' => ['class' => 'authored']],
            'identity' => ['mode' => 'mapped'],
            'pk' => 'id',
        ],
    ],
    'version_range' => ['max' => '3.0.0', 'min' => '1.0.0'],
];

$signRepo = cert_site($root, 'signsite', $rich);

// The key lives in the SITE trust root, which is what makes the result
// `site_signed` — `sign_site()` refuses an agent-owned key by name, because
// an agent-owned key certifies a reviewed exercise or nothing.
$keypair = sodium_crypto_sign_keypair();
$signSecret = sodium_crypto_sign_secretkey($keypair);
$signPublic = sodium_crypto_sign_publickey($keypair);
$signKeyId = 'site-' . substr(hash('sha256', $signPublic), 0, 12);
$secretPath = $keyDir . '/sign.key';
file_put_contents($secretPath, base64_encode($signSecret) . "\n");
chmod($secretPath, 0600);

$reason = 'Acme Ltd reviewed this adapter against its own catalog schema.';
cert_private('registerAuthority', [
    $signRepo, $signKeyId, $signPublic, 'acme-catalog', AdapterSources::TIER_DECLARATIVE,
]);

// The agent owns the bundle (T6 §3.5): sign_site() derives the ratification,
// runs the real loader for the grammar verdict, builds and verifies the
// unexercised bundle in memory, and signs. This suite asserts the composition
// around it, and that the composition produces a claim the LIVE verifier
// accepts — the same call the policy path makes on every load.
$certificate = AdapterCertification::sign_site(
    Policy::manifests_dir(),
    $signRepo,
    'acme-catalog',
    $signKeyId,
    base64_encode($signSecret),
    $reason
);
$certificatePath = AdapterCertify::writeCertificate($signRepo, 'acme-catalog', $certificate);
duo_check_same(
    // realpath: writeCertificate() resolves the repository root, and macOS
    // resolves /var to /private/var. The assertion is about the DERIVED
    // relative path, not about the platform's symlinking of tmp.
    realpath($signRepo) . '/adapters/certifications/acme-catalog.json',
    $certificatePath,
    'the certificate lands at its DERIVED path, never one a caller chose'
);
duo_check_same(
    '0644',
    substr(sprintf('%o', fileperms($certificatePath)), -4),
    'the certificate is world-readable — it is a public statement, not a secret'
);

$verified = AdapterCertification::verifyFile(
    Policy::manifests_dir(),
    $signRepo,
    'acme-catalog',
    $rich,
    $certificatePath
);
$summary = AdapterCertification::certificateSummary($verified);
duo_check_same('certified', $summary['status'] ?? null, 'the live verifier reports a CERTIFIED claim');
duo_check_same('acme-catalog', $summary['name'] ?? null, 'for this adapter');
duo_check_same(
    AdapterSources::TIER_DECLARATIVE,
    $summary['trust_tier'] ?? null,
    'at the declarative trust tier the manifest earned'
);
duo_check_same(
    $signKeyId,
    $summary['authority']['key_id'] ?? null,
    'under the operator\'s own key'
);

// T6 §3.2's two projected facts, read off the claim the projection consumes.
// `exercised: false` is the whole point of the site profile — a certificate
// that could be read as "somebody ran it" is the ambiguity the relaxation
// exists to remove — and `trust_root: site` is what keeps this
// `Site-certified` rather than `Platform-certified`.
$claim = is_array($verified['claim'] ?? null) ? $verified['claim'] : [];
duo_check_same(
    false,
    $claim['evidence']['exercised'] ?? null,
    'the CLAIM carries exercised: false, so nothing downstream can read it as a reviewed exercise'
);
duo_check_same(
    'site',
    $claim['certification']['trust_root'] ?? null,
    'and a site trust root, which is what makes the projection say Site-certified'
);
duo_check_same(
    'site',
    $claim['certification']['source'] ?? null,
    'with source "site" — the fact ProjectionVocabulary::projectProvenance() keys on'
);
duo_check_same(
    $signKeyId,
    $claim['certification']['principal'] ?? null,
    'naming the principal the human view prints'
);
duo_check_same(
    // The reason rides on the DISPOSITION, not the claim's evidence block —
    // it is what the entry is certified ON, and it is inside the signed
    // statement either way. Asserted where it lives rather than where it
    // would have been convenient.
    $reason,
    $verified['disposition']['reason'] ?? null,
    'and the operator\'s stated basis, signed rather than merely typed'
);
duo_check_same(
    [],
    $claim['evidence']['tests'] ?? null,
    'with NO named tests — `evidence.tests: ["something"]` is indistinguishable downstream from a '
    . 'reviewed conformance run, which is the collapse `exercised: false` exists to prevent'
);

// A verified certificate produced under a different PHP environment is not
// reusable. Build that exact signed state rather than mocking verification:
// the live verifier accepts it, then sign_site() must compare the full current
// candidate and rotate it back to this process's PHP evidence.
$environmentRepo = cert_site($root, 'environment-site', $rich);
cert_private('registerAuthority', [
    $environmentRepo, $signKeyId, $signPublic, 'acme-catalog', AdapterSources::TIER_DECLARATIVE,
]);
$environmentCertificate = AdapterCertification::sign_site(
    Policy::manifests_dir(),
    $environmentRepo,
    'acme-catalog',
    $signKeyId,
    base64_encode($signSecret),
    $reason
);
$environmentCertificatePath = AdapterCertify::writeCertificate(
    $environmentRepo,
    'acme-catalog',
    $environmentCertificate
);
$foreignEnvironment = json_decode($environmentCertificate, true, 512, JSON_THROW_ON_ERROR);
$foreignSummary = $foreignEnvironment['statement']['bundle']['environment_summary'];
$foreignSummary['php'] = '8.3.0-fixture';
$foreignEnvironmentRaw = Canon::encode($foreignSummary);
$foreignEnvironment['statement']['bundle']['environment_summary'] = $foreignSummary;
$foreignEnvironment['statement']['bundle']['environment'] = [
    'path' => 'environment.json',
    'sha256' => hash('sha256', $foreignEnvironmentRaw),
    'size' => strlen($foreignEnvironmentRaw),
];
$bundleDigestMethod = new ReflectionMethod(AdapterCertification::class, 'bundleDigest');
$foreignEnvironment['statement']['bundle']['bundle_digest'] = $bundleDigestMethod->invoke(
    null,
    $foreignEnvironment['statement']['bundle']
);
$signatureBytesMethod = new ReflectionMethod(AdapterCertification::class, 'signatureBytes');
$foreignEnvironment['signature'] = base64_encode(sodium_crypto_sign_detached(
    $signatureBytesMethod->invoke(null, $foreignEnvironment['statement']),
    $signSecret
));
$foreignEnvironmentBytes = Canon::encode($foreignEnvironment);
Canon::write_file($environmentCertificatePath, $foreignEnvironmentBytes);
$foreignVerified = AdapterCertification::verifyFile(
    Policy::manifests_dir(),
    $environmentRepo,
    'acme-catalog',
    $rich,
    $environmentCertificatePath
);
duo_check_same(
    'certified',
    $foreignVerified['claim']['status'] ?? null,
    'fixture premise: the different-PHP certificate is a valid current signed certificate'
);
$currentEnvironmentCertificate = AdapterCertification::sign_site(
    Policy::manifests_dir(),
    $environmentRepo,
    'acme-catalog',
    $signKeyId,
    base64_encode($signSecret),
    $reason
);
duo_check(
    !hash_equals($foreignEnvironmentBytes, $currentEnvironmentCertificate)
        && (json_decode($currentEnvironmentCertificate, true)['statement']['bundle']['environment_summary']['php'] ?? null)
            === PHP_VERSION,
    'a changed PHP/environment input rotates the certificate instead of reusing a verified old timestamp'
);

// An AGENT-owned key routed through this entry point is refused by name. The
// relaxation follows the trust ROOT, not the caller, so this is the boundary
// that keeps `site_signed` and `third_party_signed` separable at all.
$agentLibrary = cert_agent_library($root, 'agentlib', ['acme-catalog'], [AdapterSources::TIER_DECLARATIVE]);
$agentRepo = cert_site($root, 'agentsite', $rich);
duo_check_throws(
    static fn() => AdapterCertification::sign_site(
        $agentLibrary['dir'],
        $agentRepo,
        'acme-catalog',
        $agentLibrary['key_id'],
        base64_encode($agentLibrary['secret']),
        'attempting the unexercised profile under an agent-owned key'
    ),
    RuntimeException::class,
    'an agent-owned key cannot mint an unexercised certificate — it certifies a reviewed exercise or nothing'
);

// Byte-binding, restated as a live check rather than a claim: one edited byte
// and the certificate stops verifying. This is why the guides say to re-run
// certify after every adapter edit.
$tampered = $rich;
$tampered['options']['acme_catalog_layout']['class'] = 'runtime';
Canon::write_file($signRepo . '/adapters/acme-catalog.json', Canon::encode($tampered));
duo_check_throws(
    static fn() => AdapterCertification::verifyFile(
        Policy::manifests_dir(),
        $signRepo,
        'acme-catalog',
        $tampered,
        $certificatePath
    ),
    RuntimeException::class,
    'editing the adapter after certification breaks verification'
);
Canon::write_file($signRepo . '/adapters/acme-catalog.json', Canon::encode($rich));

// ------------------------------------------------------- the site trust root

$authRepo = cert_site($root, 'authsite', [
    'name' => 'keeper',
    'option_autoload' => 'preserve',
    'options' => ['keeper_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
]);
$public = sodium_crypto_sign_publickey_from_secretkey($signSecret);
$wrote = cert_private('registerAuthority', [
    $authRepo, 'site-acme', $public, 'keeper', AdapterSources::TIER_DECLARATIVE,
]);
duo_check_same(true, $wrote, 'registering a new key reports that it wrote');
$authorities = json_decode(
    (string) file_get_contents($authRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE),
    true
);
duo_check_same(
    AdapterCertification::AUTHORITIES_FORMAT,
    $authorities['format'] ?? null,
    'the SITE trust root uses exactly the shipped duo-adapter-authorities/v1 grammar — '
    . 'a second dialect would be a second verifier'
);
duo_check_same(
    ['adapter_names' => ['keeper'], 'algorithm' => 'ed25519', 'public_key' => base64_encode($public),
        'scope' => 'site_adapter_certification', 'status' => 'trusted',
        'trust_tiers' => [AdapterSources::TIER_DECLARATIVE]],
    $authorities['keys']['site-acme'] ?? null,
    'and the record carries exactly the six fields validateAuthorityRecord() enforces'
);
duo_check(
    str_contains(
        (string) file_get_contents($authRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE),
        '"keys": {'
    ),
    '`keys` stays a JSON OBJECT even with one member — Canon encodes an empty PHP array as [], '
    . 'which authority() refuses'
);

// Widening: a second adapter under the same key is an edit, not a new record.
cert_private('registerAuthority', [
    $authRepo, 'site-acme', $public, 'other', AdapterSources::TIER_NATIVE_ACTION,
]);
$authorities = json_decode(
    (string) file_get_contents($authRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE),
    true
);
duo_check_same(
    ['keeper', 'other'],
    $authorities['keys']['site-acme']['adapter_names'] ?? null,
    'a second adapter widens the existing record rather than adding a second key'
);
duo_check_same(
    [AdapterSources::TIER_DECLARATIVE, AdapterSources::TIER_NATIVE_ACTION],
    $authorities['keys']['site-acme']['trust_tiers'] ?? null,
    'and so does a second trust tier'
);
duo_check_same(
    false,
    cert_private('registerAuthority', [
        $authRepo, 'site-acme', $public, 'keeper', AdapterSources::TIER_DECLARATIVE,
    ]),
    're-registering the same key/name/tier changes no bytes and says so'
);

// A key id already bound to a DIFFERENT public key is a silent-rotation
// hazard: every certificate that id signed would start verifying against
// another organization.
$other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
duo_check_throws(
    static fn() => cert_private('registerAuthority', [
        $authRepo, 'site-acme', $other, 'keeper', AdapterSources::TIER_DECLARATIVE,
    ]),
    RuntimeException::class,
    'the same key id with a different public key refuses rather than rotating silently'
);

// A revoked key cannot certify. The engine refuses it at sign time too; this
// is the earlier, clearer refusal.
$revoked = json_decode(
    (string) file_get_contents($authRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE),
    true
);
$revoked['keys']['site-acme']['status'] = 'revoked';
Canon::write_file(
    $authRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE,
    Canon::encode(['format' => $revoked['format'], 'keys' => (object) $revoked['keys']])
);
duo_check_throws(
    static fn() => cert_private('registerAuthority', [
        $authRepo, 'site-acme', $public, 'keeper', AdapterSources::TIER_DECLARATIVE,
    ]),
    RuntimeException::class,
    'a revoked key in the site trust root cannot certify'
);

// -------------------------------------------------------------- secret handling

$loose = $keyDir . '/loose.key';
file_put_contents($loose, base64_encode($signSecret) . "\n");
chmod($loose, 0644);
duo_check_throws(
    static fn() => AdapterCertify::readSecretKey($loose),
    RuntimeException::class,
    'a group/world-readable secret key file is refused: signing with one mints an authority '
    . 'anybody on the box could forge'
);
chmod($loose, 0600);
duo_check_same(
    $signSecret,
    AdapterCertify::readSecretKey($loose),
    'a 0600 base64 secret key reads back byte-for-byte'
);
$hexKey = $keyDir . '/hex.key';
file_put_contents($hexKey, bin2hex($signSecret) . "\n");
chmod($hexKey, 0600);
duo_check_same(
    $signSecret,
    AdapterCertify::readSecretKey($hexKey),
    'and so does the hexadecimal spelling, which the engine\'s own signer accepts'
);
$junk = $keyDir . '/junk.key';
file_put_contents($junk, "not a key\n");
chmod($junk, 0600);
duo_check_throws(
    static fn() => AdapterCertify::readSecretKey($junk),
    RuntimeException::class,
    'a file that is not an Ed25519 secret key is refused before any signing begins'
);

// ------------------------------------------------------------------------ pin

$pinRepo = cert_site($root, 'pinsite', [
    'name' => 'keeper',
    'option_autoload' => 'preserve',
    'options' => ['keeper_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
]);
$before = (string) file_get_contents($pinRepo . '/site.duo.json');

// Written in CANONICAL key order, which is the order the file will hold:
// Canon sorts, and this suite compares the decoded file rather than a
// re-sorted copy of it, so the expectation states the real byte order.
$pin = ['digest' => str_repeat('a', 64), 'name' => 'keeper', 'source' => 'site'];
duo_check_same(true, cert_private('writePin', [$pinRepo, $pin]), 'writing a new pin reports that it changed');
$after = json_decode((string) file_get_contents($pinRepo . '/site.duo.json'), true);
duo_check_same(['core', $pin], $after['manifests'] ?? null, 'the pin is appended, leaving existing pins alone');
duo_check(
    str_contains((string) file_get_contents($pinRepo . '/site.duo.json'), '"policy": {}'),
    'an EMPTY JSON object in site.duo.json survives the rewrite — an associative round trip turns '
    . '{} into [], which the engine refuses, and that was a real defect on the first smoke run'
);
duo_check_same(
    false,
    cert_private('writePin', [$pinRepo, $pin]),
    'rewriting the same pin changes no bytes and says so — a no-op must not churn a committed file'
);

$moved = ['digest' => str_repeat('b', 64), 'name' => 'keeper', 'source' => 'site'];
cert_private('writePin', [$pinRepo, $moved]);
$after = json_decode((string) file_get_contents($pinRepo . '/site.duo.json'), true);
duo_check_same(
    ['core', $moved],
    $after['manifests'] ?? null,
    'a moved digest REPLACES the pin in place rather than adding a second one for the same name'
);

// A repository already carrying two pins for one name is a defect PinResolver
// refuses later; collapsing it silently here would hide it until the next load.
$dupRepo = cert_site($root, 'dupsite', [
    'name' => 'keeper',
    'option_autoload' => 'preserve',
    'options' => ['keeper_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
], ['core', 'keeper', ['name' => 'keeper', 'source' => 'site', 'digest' => str_repeat('c', 64)]]);
duo_check_throws(
    static fn() => cert_private('writePin', [$dupRepo, $pin]),
    RuntimeException::class,
    'a repository that already pins one name twice refuses rather than silently collapsing it'
);

// A site.duo.json that is valid but not canonical (the operator hand-pasted
// the previous pin, exactly as the guide tells them to) is admitted: the pin
// write is canonical whatever the input was, only `manifests` changes, and
// the engine then reads canonical bytes. Refusing here sent the author to
// reformat a file this command was about to rewrite (T6 walk S2).
$roughRepo = $root . '/roughsite';
mkdir($roughRepo . '/adapters', 0755, true);
file_put_contents(
    $roughRepo . '/site.duo.json',
    "{\"spec_version\": 2, \"manifests\": [\"core\"], \"policy\": {}}\n"
);
duo_check_same(true, cert_private('writePin', [$roughRepo, $pin]), 'a valid non-canonical site.duo.json takes the pin');
$roughAfter = (string) file_get_contents($roughRepo . '/site.duo.json');
duo_check(
    hash_equals(Canon::encode(json_decode($roughAfter)), $roughAfter),
    'and is canonical afterwards, with its other keys re-encoded unchanged'
);
duo_check_same(
    ['core', $pin],
    json_decode($roughAfter, true)['manifests'],
    'the pin joins the existing name-only core pin'
);

// ---------------------------------------------------------- argument grammar

duo_check_same(2, cert_run(['certify'])['exit'], 'certify without a site repo is a usage error');
duo_check_same(2, cert_run(['pin'])['exit'], 'pin without a site repo is a usage error');
duo_check_same(2, cert_run(['nonsense'])['exit'], 'an unknown adapter sub-verb is a usage error');
duo_check_same(
    2,
    cert_run(['pin', $pinRepo, '--name=keeper', '--source=shipped'])['exit'],
    '--source=shipped is refused: a name-only pin already resolves there, and it is the one source '
    . 'an operator can neither install nor move'
);
$noSite = $root . '/notarepo';
mkdir($noSite, 0755, true);
duo_check_same(
    2,
    cert_run(['pin', $noSite, '--name=keeper'])['exit'],
    'a directory with no site.duo.json is not a site repo'
);
duo_check_same(
    2,
    cert_run(['certify', $pinRepo, '--name=keeper'])['exit'],
    'certify without --secret-key-file is a usage error: certification IS a signature'
);
$missingAdapter = cert_run_cli(['certify', $pinRepo, '--name=absent', '--secret-key-file=' . $secretPath]);
duo_check_same(2, $missingAdapter['exit'], 'certifying an adapter that is not installed is a usage error');
duo_check(
    str_contains($missingAdapter['err'], 'certification signs an installed site adapter'),
    'and the refusal names the thing to do first, including the draft command that produces one'
);

// -------------------------------------------- hand-edited files (T6 walk S2)
// An operator finishes a draft by hand and hand-pastes into site.duo.json, so
// both are valid JSON and almost never canonical. certify rewrites the
// adapter canonically before it signs (same declarations) and says so;
// --pin admits a non-canonical site.duo.json because its write is canonical
// whatever the input was. Invalid JSON is still refused with the parser's words.
$handRepo = cert_site($root, 'handsite', $rich);
$handPretty = json_encode($rich, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
file_put_contents($handRepo . '/adapters/acme-catalog.json', "  " . $handPretty . "\n\n");
$handSite = json_decode((string) file_get_contents($handRepo . '/site.duo.json'), true);
file_put_contents($handRepo . '/site.duo.json', json_encode($handSite, JSON_PRETTY_PRINT) . "\n");
duo_check(
    !hash_equals(Canon::encode($rich), (string) file_get_contents($handRepo . '/adapters/acme-catalog.json')),
    'the fixture adapter is genuinely non-canonical before certify'
);
cert_private('registerAuthority', [
    $handRepo, $signKeyId, $signPublic, 'acme-catalog', AdapterSources::TIER_DECLARATIVE,
]);
$handRun = cert_run(['certify', $handRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=' . $reason, '--pin']);
duo_check_same(0, $handRun['exit'], 'certify --pin succeeds on a hand-edited adapter and a hand-edited site.duo.json');
duo_check(
    str_contains($handRun['out'], 'rewrote site adapter acme-catalog.json canonically'),
    'certify says it rewrote the adapter canonically'
);
duo_check(
    hash_equals(Canon::encode($rich), (string) file_get_contents($handRepo . '/adapters/acme-catalog.json')),
    'the adapter on disk is now the canonical bytes of the same declarations'
);
$handPins = json_decode((string) file_get_contents($handRepo . '/site.duo.json'), true)['manifests'] ?? [];
duo_check(
    count(array_filter($handPins, static fn ($m) => is_array($m) && ($m['name'] ?? '') === 'acme-catalog' && ($m['source'] ?? '') === 'site')) === 1,
    'the pin landed in the previously non-canonical site.duo.json'
);
file_put_contents($handRepo . '/adapters/acme-catalog.json', "{ not json");
$handBad = cert_run_cli(['certify', $handRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath, '--key-id=' . $signKeyId]);
duo_check_same(2, $handBad['exit'], 'invalid JSON is still refused');
duo_check(str_contains($handBad['err'], 'is not valid JSON'), 'and named as such, never rewritten');

// -------------------------------- overriding a shipped adapter (T6 walk S4)
// The operator copies manifests/woocommerce.json to adapters/woocommerce.json,
// edits it, and says `duo adapter pin --source=site`. Three things the walk
// stopped on, each pinned here: (1) the pin verb bootstraps the override in
// one command — the site copy loads only once site.duo.json names source
// "site", and the digest that completes the pin can only be read by loading;
// (2) certify admits the override's inherited `compatibility_shim` tier under
// a site key; (3) certifying a SECOND adapter under the same key keeps the
// first certificate valid — the site trust root is a living registry, and a
// certificate binds the key's identity, not the record's growing scope lists.
$shippedWoo = json_decode((string) file_get_contents(Policy::manifests_dir() . '/woocommerce.json'), true);
$overrideCopy = $shippedWoo;
$overrideCopy['options']['woocommerce_walk_banner'] = ['class' => 'authored'];
$overRepo = cert_site($root, 'oversite', $overrideCopy, ['core', 'woocommerce']);
duo_check_throws(
    static fn() => Policy::load($overRepo),
    RuntimeException::class,
    'before the override pin the site copy of a shipped name refuses to load (it shadows)'
);
$overPin = cert_run(['pin', $overRepo, '--name=woocommerce', '--source=site']);
duo_check_same(0, $overPin['exit'], 'pin --source=site on a shipped name succeeds from a name-only pin');
duo_check(
    str_contains($overPin['out'], "override: site.duo.json now names the site copy of shipped adapter 'woocommerce'")
        && str_contains($overPin['out'], 'wrote the pin'),
    'and says it made the override before writing the digest pin'
);
$overPins = json_decode((string) file_get_contents($overRepo . '/site.duo.json'), true)['manifests'] ?? [];
$overEntries = array_values(array_filter(
    $overPins,
    static fn ($m) => (is_string($m) ? $m : ($m['name'] ?? null)) === 'woocommerce'
));
duo_check(
    count($overEntries) === 1
        && is_array($overEntries[0])
        && ($overEntries[0]['source'] ?? null) === 'site'
        && preg_match('/^[0-9a-f]{64}$/', (string) ($overEntries[0]['digest'] ?? '')) === 1
        && in_array('core', $overPins, true),
    'the name-only pin is REPLACED by one {name, source:"site", digest} pin; core is untouched'
);
$overPolicy = Policy::load($overRepo);
duo_check_same(
    AdapterSources::SITE,
    $overPolicy->adapter_sources()->source('woocommerce'),
    'the override now loads from the site source'
);
$overBytes = (string) file_get_contents($overRepo . '/site.duo.json');
$overAgain = cert_run(['pin', $overRepo, '--name=woocommerce', '--source=site']);
duo_check(
    $overAgain['exit'] === 0
        && !str_contains($overAgain['out'], 'override:')
        && str_contains($overAgain['out'], 'confirmed the pin')
        && hash_equals($overBytes, (string) file_get_contents($overRepo . '/site.duo.json')),
    'a second pin --source=site is a confirmation: no override line, no byte change'
);

// (2) certify the override under the site key: its tier is the shipped
// compatibility_shim, inherited with the provider grant.
$overCert = cert_run(['certify', $overRepo, '--name=woocommerce', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=Acme Ltd reviewed its banner option against the shipped adapter.', '--pin']);
duo_check_same(0, $overCert['exit'], 'certify --pin succeeds on an override that inherits shipped grants');
duo_check(
    str_contains($overCert['out'], 'trust tier: ' . AdapterSources::TIER_COMPATIBILITY_SHIM),
    'and reports the inherited compatibility_shim tier rather than laundering it'
);
$overAuthorities = json_decode((string) file_get_contents($overRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE), true);
duo_check_same(
    [AdapterSources::TIER_COMPATIBILITY_SHIM],
    $overAuthorities['keys'][$signKeyId]['trust_tiers'] ?? null,
    'the site trust root records the shim tier for the key — a site key may certify an override\'s declarations'
);
$overPolicy = Policy::load($overRepo);
duo_check_same(
    'site_signed',
    $overPolicy->adapter_sources()->diagnostics($overPolicy->manifests)['woocommerce']['certification'] ?? null,
    'the certified override reads site_signed'
);

// (3) a second adapter under the SAME key: the record grows (adapter_names,
// trust_tiers) and the first certificate must stay valid.
Canon::write_file($overRepo . '/adapters/keeper.json', Canon::encode([
    'name' => 'keeper',
    'option_autoload' => 'preserve',
    'options' => ['keeper_layout' => ['class' => 'authored']],
    'spec_version' => DUO_SPEC_VERSION,
]));
duo_check_same(0, cert_run(['pin', $overRepo, '--name=keeper', '--source=site'])['exit'], 'a second site adapter pins');
$keeperCert = cert_run(['certify', $overRepo, '--name=keeper', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=Acme Ltd reviewed keeper.', '--pin']);
duo_check_same(0, $keeperCert['exit'], 'the second adapter certifies under the same key');
$overAuthorities = json_decode((string) file_get_contents($overRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE), true);
duo_check_same(
    ['keeper', 'woocommerce'],
    $overAuthorities['keys'][$signKeyId]['adapter_names'] ?? null,
    'the key record now names both adapters (it grew)'
);
$overPolicy = Policy::load($overRepo);
$overWords = $overPolicy->adapter_sources()->diagnostics($overPolicy->manifests);
duo_check_same(
    ['keeper' => 'site_signed', 'woocommerce' => 'site_signed'],
    ['keeper' => $overWords['keeper']['certification'] ?? null, 'woocommerce' => $overWords['woocommerce']['certification'] ?? null],
    'BOTH certificates verify after the record grew — a growing site trust root does not invalidate earlier certificates'
);
$overWooCert = $overRepo . '/adapters/certifications/woocommerce.json';
$verifiedOver = AdapterCertification::verifyFile(Policy::manifests_dir(), $overRepo, 'woocommerce', $overrideCopy, $overWooCert);
duo_check_same(
    'site',
    $verifiedOver['provenance']['proof']['authority']['trust_root'] ?? null,
    'the live verifier agrees, under the site trust root'
);
$overResolvedBefore = null;
foreach (RepositoryCompiler::resolved_adapters(Policy::load($overRepo)) as $resolvedRow) {
    if ($resolvedRow['name'] === 'woocommerce') {
        $overResolvedBefore = $resolvedRow['digest'];
    }
}
$overPinsNow = json_decode((string) file_get_contents($overRepo . '/site.duo.json'), true)['manifests'];
$overPinDigest = null;
foreach ($overPinsNow as $m) {
    if (is_array($m) && ($m['name'] ?? null) === 'woocommerce') {
        $overPinDigest = $m['digest'];
    }
}
duo_check_same(
    $overPinDigest,
    $overResolvedBefore,
    'the woocommerce pin written BEFORE keeper was certified still equals the digest the engine resolves after: '
    . 'a growing trust root moves no earlier adapter digest'
);

// The identity half IS still bound: a rotated public key under the same id
// invalidates every certificate, and revocation refuses at scope.
$rotated = $overAuthorities;
$rotated['keys'][$signKeyId]['public_key'] = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));
Canon::write_file(
    $overRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE,
    Canon::encode(['format' => $rotated['format'], 'keys' => (object) $rotated['keys']])
);
duo_check_throws(
    static fn() => AdapterCertification::verifyFile(Policy::manifests_dir(), $overRepo, 'woocommerce', $overrideCopy, $overWooCert),
    RuntimeException::class,
    'a rotated public key under the same key id invalidates the certificate — identity is bound'
);
$revokedRoot = $overAuthorities;
$revokedRoot['keys'][$signKeyId]['status'] = 'revoked';
Canon::write_file(
    $overRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE,
    Canon::encode(['format' => $revokedRoot['format'], 'keys' => (object) $revokedRoot['keys']])
);
duo_check_throws(
    static fn() => AdapterCertification::verifyFile(Policy::manifests_dir(), $overRepo, 'woocommerce', $overrideCopy, $overWooCert),
    RuntimeException::class,
    'and a revoked key refuses on the next verification — revocation is live, not frozen into the certificate'
);
Canon::write_file(
    $overRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE,
    Canon::encode(['format' => $overAuthorities['format'], 'keys' => (object) $overAuthorities['keys']])
);

// An override that WIDENS the shipped grant never certifies, and never leaves
// a trust root behind: the pre-flight load refuses before any write.
$widenRepo = cert_site($root, 'widensite', (static function (array $copy): array {
    $copy['providers'][] = [
        'capabilities' => ['flush'], 'id' => 'walk-rogue', 'plugin' => 'woocommerce/woocommerce.php',
        'source' => 'manifest', 'version' => '1.0.0',
    ];

    return $copy;
})($overrideCopy), ['core', ['name' => 'woocommerce', 'source' => 'site']]);
$widenCert = cert_run_cli(['certify', $widenRepo, '--name=woocommerce', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=widened']);
duo_check_same(2, $widenCert['exit'], 'certifying an override that adds a manifest-sourced provider is refused');
duo_check(
    str_contains($widenCert['err'], "an override of shipped adapter 'woocommerce' inherits the shipped interpreter"),
    'with the override remediation (repeat the shipped declaration verbatim or drop the change)'
);
duo_check(
    !is_file($widenRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE),
    'and no trust root was written for the failed attempt'
);

// ------------------------------- the pin is the site's scope opt-in (DUO-3495)
// The reported walkthrough: a site initialized with --allow-unmanaged-plugins
// records the unmanaged plugin's CPT as policy.scope.post_type.<cpt> =
// {"class":"runtime"}; the developer later authors, certifies and pins an
// adapter that declares that CPT. Before this, the pin extended no scope, so
// `duo capture` silently skipped the type, and three hand-edits of
// site.duo.json were the only way forward. What follows is that exact
// sequence, plus the invariant the fix must not spend to get there: a
// recorded site decision is never rewritten by a command that was not told
// to rewrite it.

/** A site repository whose site.duo.json is init-shaped, with an optional recorded scope. */
function cert_init_site(string $root, string $label, array $manifest, array $scope): string {
    $repo = $root . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    Canon::write_file($repo . '/adapters/' . $manifest['name'] . '.json', Canon::encode($manifest));
    $policy = [
        // Empty JSON OBJECTS, as `duo init` writes them. They are the reason
        // the scope writer is typed: an associative round trip rewrites each
        // one as `[]` and the engine then refuses the file.
        'options' => new stdClass(),
        'post_meta' => new stdClass(),
        'post_types' => ['attachment', 'page', 'post'],
        'taxonomies' => ['category', 'post_tag'],
        'term_meta' => new stdClass(),
    ];
    if ($scope !== []) {
        $policy['scope'] = $scope;
    }
    Canon::write_file($repo . '/site.duo.json', Canon::encode([
        'manifests' => ['core'],
        'policy' => $policy,
        'spec_version' => DUO_SPEC_VERSION,
    ]));

    return $repo;
}

/**
 * The two questions capture asks about a whole type, plus the one `duo
 * assess` asks — deliberately reported side by side, because DUO-3504 was
 * this pin answering the first two right and the third wrong.
 *
 * `source` is the declaration that WON the classification, and after the pin
 * it is `site.duo.json` by design: the scope rule this command writes is the
 * site's own whole-type decision and site policy always wins. `declared_by`
 * is a second, additive fact — which pinned adapter DECLARES the type
 * (`Policy::declaring_manifest()`) — and it is what
 * `AssessInventory::declarant()` reports as the surface group's `declared_by`
 * and the host renders as "Site-certified". Reading the first as the second
 * made `duo assess` print "Platform-certified" for the CPT of the adapter the
 * operator had just certified.
 */
function cert_type_verdict(string $repo, string $postType): array {
    $policy = Policy::load($repo);
    $details = $policy->post_type_rule_details($postType);

    return [
        'in_scope' => in_array($postType, $policy->post_types(), true),
        'class' => $details['rule']['class'] ?? null,
        'source' => $details['source'],
        'declared_by' => $policy->declaring_manifest('post_types', $postType),
    ];
}

// (1) Nothing recorded: the pin opts the site in, and says exactly what it wrote.
$freshRepo = cert_init_site($root, 'scope-fresh', $rich, []);
duo_check_same(
    ['in_scope' => false, 'class' => null, 'source' => null, 'declared_by' => null],
    cert_type_verdict($freshRepo, 'acme_item'),
    'fixture premise: before the pin the engine knows nothing about the declared type — including who declares '
    . 'it, because an unpinned adapter is not yet part of this site\'s policy'
);
$freshCert = cert_run(['certify', $freshRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=' . $reason, '--pin']);
duo_check_same(0, $freshCert['exit'], 'certify --pin succeeds on a repository that had decided nothing about the type');
duo_check(
    str_contains($freshCert['out'], 'scope: wrote 1 authored scope rule(s)')
        && str_contains($freshCert['out'], '+ policy.scope.post_type.acme_item = {"class": "authored"}'),
    'and prints the exact rule it wrote — a scope widen is never silent'
);
duo_check_same(
    ['in_scope' => true, 'class' => 'authored', 'source' => 'site.duo.json', 'declared_by' => 'acme-catalog'],
    cert_type_verdict($freshRepo, 'acme_item'),
    'the engine now answers all three questions for the declared type: in scope, authored, and — DUO-3504 — '
    . 'declared by the adapter that was just certified, even though the rule that classified it is the site\'s own'
);
$freshBytes = (string) file_get_contents($freshRepo . '/site.duo.json');
$freshCertificatePath = $freshRepo . '/adapters/certifications/acme-catalog.json';
$freshCertificateBytes = (string) file_get_contents($freshCertificatePath);
duo_check(
    str_contains($freshBytes, '"options": {}') && str_contains($freshBytes, '"term_meta": {}'),
    'the write is typed: init\'s empty policy sections are still JSON objects, not lists the engine refuses'
);

// (2) Rerunning decides nothing twice: no rule, no byte, and it says so.
// Cross a real UTC-second boundary: siteBundle.created_at used to mint a new
// signature here, which moved the adapter digest and rewrote site.duo.json.
// A fixed sleep just over one second is bounded and makes the prior defect
// deterministic without exposing a production clock injection seam.
usleep(1100000);
$freshAgain = cert_run(['certify', $freshRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=' . $reason, '--pin']);
duo_check(
    $freshAgain['exit'] === 0
        && str_contains($freshAgain['out'], "scope: every surface this adapter declares is already in site.duo.json's authored scope")
        && hash_equals($freshBytes, (string) file_get_contents($freshRepo . '/site.duo.json')),
    'a second certify --pin writes no scope rule and no byte — adoption is idempotent'
);
duo_check(
    hash_equals($freshCertificateBytes, (string) file_get_contents($freshCertificatePath)),
    'a second semantically identical certify reuses the verified certificate across a UTC-second boundary'
);

// Reuse is exact candidate equality, not timestamp pinning. Every signed input
// still rotates the certificate and therefore the repository pin when it
// changes: reason, authority/key and raw+canonical manifest bytes are three
// independent witnesses for the full deterministic comparison.
$reasonRotated = cert_run(['certify', $freshRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=Acme Ltd performed a second catalog review.', '--pin']);
duo_check_same(0, $reasonRotated['exit'], 'certify succeeds when the signed reason changes');
$reasonCertificateBytes = (string) file_get_contents($freshCertificatePath);
$reasonPinBytes = (string) file_get_contents($freshRepo . '/site.duo.json');
duo_check(
    !hash_equals($freshCertificateBytes, $reasonCertificateBytes)
        && !hash_equals($freshBytes, $reasonPinBytes),
    'a changed reason rotates both certificate and digest pin instead of reusing the old timestamp'
);

$nextKeypair = sodium_crypto_sign_keypair();
$nextSecret = sodium_crypto_sign_secretkey($nextKeypair);
$nextPublic = sodium_crypto_sign_publickey($nextKeypair);
$nextKeyId = 'site-' . substr(hash('sha256', $nextPublic), 0, 12);
$nextSecretPath = $keyDir . '/sign-next.key';
file_put_contents($nextSecretPath, base64_encode($nextSecret) . "\n");
chmod($nextSecretPath, 0600);
$keyRotated = cert_run(['certify', $freshRepo, '--name=acme-catalog', '--secret-key-file=' . $nextSecretPath,
    '--key-id=' . $nextKeyId, '--reason=Acme Ltd performed a second catalog review.', '--pin']);
duo_check_same(0, $keyRotated['exit'], 'certify succeeds under a newly registered scoped authority');
$keyCertificateBytes = (string) file_get_contents($freshCertificatePath);
$keyPinBytes = (string) file_get_contents($freshRepo . '/site.duo.json');
duo_check(
    !hash_equals($reasonCertificateBytes, $keyCertificateBytes)
        && !hash_equals($reasonPinBytes, $keyPinBytes),
    'a changed authority/key rotates both certificate and digest pin'
);

$changedRich = $rich;
$changedRich['version_range']['max'] = '3.1.0';
Canon::write_file($freshRepo . '/adapters/acme-catalog.json', Canon::encode($changedRich));
$manifestRotated = cert_run(['certify', $freshRepo, '--name=acme-catalog', '--secret-key-file=' . $nextSecretPath,
    '--key-id=' . $nextKeyId, '--reason=Acme Ltd performed a second catalog review.', '--pin']);
duo_check_same(0, $manifestRotated['exit'], 'certify succeeds after the site adapter manifest changes');
duo_check(
    !hash_equals($keyCertificateBytes, (string) file_get_contents($freshCertificatePath))
        && !hash_equals($keyPinBytes, (string) file_get_contents($freshRepo . '/site.duo.json')),
    'changed raw/canonical manifest inputs rotate both certificate and digest pin'
);

// (3) The walkthrough proper: init already recorded the type as runtime.
$walkRepo = cert_init_site($root, 'scope-walkthrough', $rich, [
    'post_type' => ['acme_item' => ['class' => 'runtime']],
]);
$walkCert = cert_run(['certify', $walkRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=' . $reason, '--pin']);
duo_check_same(0, $walkCert['exit'], 'certify --pin still succeeds over a recorded runtime scope class');
duo_check_same(
    ['class' => 'runtime'],
    Canon::decode(Canon::read_file($walkRepo . '/site.duo.json'))['policy']['scope']['post_type']['acme_item'] ?? null,
    'THE INVARIANT: a recorded site scope class is not rewritten. `duo classify` and `duo init '
    . '--allow-unmanaged-plugins` write byte-identical rules and the grammar carries no provenance key '
    . '(Policy.php:2794), so flipping it would be a guess about which one wrote it'
);
duo_check(
    str_contains($walkCert['out'], 'scope: 1 surface(s) this adapter declares stay LOCAL')
        && str_contains($walkCert['out'], '! policy.scope.post_type.acme_item = {"class": "runtime"} — capture will skip post_type acme_item'),
    'and the dead end is named instead of being left to be discovered by an empty capture'
);
duo_check(
    str_contains($walkCert['out'], 'duo adapter pin ' . realpath($walkRepo) . ' --name=acme-catalog --adopt-scope')
        && str_contains($walkCert['out'], "wp duo classify --repo=<repo> --set='scope:post_type:acme_item=authored'"),
    'with both remedies copy-pasteable: the host command, and the agent-side classify spec'
);

// (4) --adopt-scope is the operator supplying the fact the file cannot carry.
$adoptRun = cert_run(['pin', $walkRepo, '--name=acme-catalog', '--source=site', '--adopt-scope']);
duo_check_same(0, $adoptRun['exit'], 'the printed --adopt-scope command runs');
duo_check(
    str_contains($adoptRun['out'], 'scope: --adopt-scope overrode 1 decision(s) site.duo.json had already recorded')
        && str_contains($adoptRun['out'], '~ policy.scope.post_type.acme_item = {"class": "runtime"} -> {"class": "authored"}'),
    'and reports the override as an override, naming the class it replaced'
);
duo_check_same(
    ['in_scope' => true, 'class' => 'authored', 'source' => 'site.duo.json', 'declared_by' => 'acme-catalog'],
    cert_type_verdict($walkRepo, 'acme_item'),
    'after which the walkthrough\'s capture has nothing left to refuse — one printed command, zero hand-edits — '
    . 'and the adapter is still the declarant an --adopt-scope override did not transfer to the site'
);
duo_check_same(
    2,
    cert_run(['certify', $walkRepo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
        '--key-id=' . $signKeyId, '--adopt-scope'])['exit'],
    '--adopt-scope without --pin is refused rather than silently inert: scope follows the pin'
);

// (5) Insertion never guesses beyond the declaration. A structural taxonomy
// (no class at all) is authored and is adopted; a post type the ADAPTER
// itself classifies runtime is its author's decision and is left alone.
$declManifest = [
    'name' => 'acme-cases',
    'option_autoload' => 'preserve',
    'options' => ['acme_cases_layout' => ['class' => 'authored']],
    'post_types' => ['acme_case' => ['class' => 'authored'], 'acme_log' => ['class' => 'runtime']],
    'spec_version' => DUO_SPEC_VERSION,
    'taxonomies' => ['acme_case_kind' => new stdClass()],
];
$declRepo = cert_init_site($root, 'scope-declared', $declManifest, []);
$declRun = cert_run(['pin', $declRepo, '--name=acme-cases', '--source=site']);
duo_check_same(0, $declRun['exit'], 'a site adapter declaring three surfaces pins');
duo_check(
    str_contains($declRun['out'], '+ policy.scope.post_type.acme_case = {"class": "authored"}')
        && str_contains($declRun['out'], '+ policy.scope.taxonomy.acme_case_kind = {"class": "authored"}'),
    'a structural declaration (no class) is authored and adopted, exactly as init reads one'
);
$declScope = Canon::decode(Canon::read_file($declRepo . '/site.duo.json'))['policy']['scope'];
duo_check(
    !isset($declScope['post_type']['acme_log']),
    'a type the adapter itself classifies runtime is never opted in — the pin adopts declarations, it does not invent them'
);

// ------------- certify --pin, THEN init: the pin's scope rules are seed (DUO-3515)
// docs/guides/quickstart.md's seed paragraph and T6 §3.4 both put certify
// before init ("certifying first and initializing second is the intended
// order"). Since DUO-3495 `--pin` is the site's scope opt-in as well as its
// pin, so the file init is handed is the seed + the pin + the rules the pin
// wrote — and InitPlanner::existing_config() set aside only the pin, so the
// documented order refused `existing_configuration` on a repository whose
// entire non-seed content was one certified adapter (grind_adapter_walk.sh
// S2). Every file below is one this suite's own verb just wrote, on top of
// the seed's own bytes.
require_once $duoRoot . '/agent/src/Init/InitPlanner.php';

/**
 * A repository whose site.duo.json is the ADOPTION SEED — `Adopt::SEED`'s own
 * body (cli/src/Onboarding/Adopt.php:20-30), which is what `duo adopt` writes
 * and what `duo init` is then handed.
 *
 * Deliberately NOT cert_init_site()'s file: init publishes the flat lists
 * SORTED and the seed does not (`post, page, attachment` here against
 * `attachment, page, post` there), and existing_config() compares the file to
 * the seed's bytes exactly — so an init-shaped fixture would answer `owned`
 * before any pin and prove nothing about this fix.
 */
function cert_seed_site(string $root, string $label, array $manifest): string {
    $repo = $root . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    Canon::write_file($repo . '/adapters/' . $manifest['name'] . '.json', Canon::encode($manifest));
    Canon::write_file($repo . '/site.duo.json', Canon::encode([
        'manifests' => ['core'],
        'policy' => [
            'options' => [], 'post_meta' => [], 'term_meta' => [],
            'post_types' => ['post', 'page', 'attachment'],
            'taxonomies' => ['category', 'post_tag'],
        ],
        'spec_version' => DUO_SPEC_VERSION,
    ]));

    return $repo;
}

/** Edit site.duo.json through $edit, run $then, put the original bytes back. */
function cert_with_edited_site(string $repo, callable $edit, callable $then): void {
    $file = $repo . '/site.duo.json';
    $before = (string) file_get_contents($file);
    $site = json_decode($before, false, 512, JSON_THROW_ON_ERROR);
    $edit($site);
    // Typed, exactly as AdapterCertify::writeScopeRules() is and for its
    // reason: an associative round trip rewrites the seed's empty sections and
    // this would stop testing the scope rule.
    Canon::write_file($file, Canon::encode($site));
    try {
        $then();
    } finally {
        Canon::write_file($file, $before);
    }
}

/** The same, for one hand-added `policy.scope.<kind>.<name>` rule. */
function cert_with_scope_rule(string $repo, string $kind, string $name, array $rule, callable $then): void {
    cert_with_edited_site($repo, static function (stdClass $site) use ($kind, $name, $rule): void {
        $site->policy->scope->{$kind}->{$name} = $rule;
    }, $then);
}

// (6) The reported sequence itself: seed, certify --pin, then ask what init
// asks. One declared post type — grind_adapter_walk.sh S2's own shape.
$s2Repo = cert_seed_site($root, 'seed-certify', $rich);
duo_check(
    \Duo\InitPlanner::is_adoption_seed($s2Repo),
    'premise: before the pin the adoption seed reads as the adoption seed'
);
$s2Cert = cert_run(['certify', $s2Repo, '--name=acme-catalog', '--secret-key-file=' . $secretPath,
    '--key-id=' . $signKeyId, '--reason=' . $reason, '--pin']);
duo_check_same(0, $s2Cert['exit'], 'certify --pin runs on the seed, as T6 §3.4 orders it');
duo_check(
    str_contains($s2Cert['out'], '+ policy.scope.post_type.acme_item = {"class": "authored"}'),
    'and writes its one authored scope rule there too — the seed had decided nothing'
);
duo_check(
    \Duo\InitPlanner::is_adoption_seed($s2Repo),
    'THE FIX: seed + one {name, source:"site", digest} pin + the one authored rule that pin wrote is STILL the '
    . 'adoption seed, so init proposes instead of refusing existing_configuration'
);
cert_with_edited_site($s2Repo, static function (stdClass $site): void {
    unset($site->policy->scope);
}, static function () use ($s2Repo): void {
    duo_check(
        \Duo\InitPlanner::is_adoption_seed($s2Repo),
        'PREMISE, both ways: with policy.scope removed the same file still reads as the seed — DUO-3494 already '
        . 'set the pin itself aside, so the verdict above turns on the scope rule and on nothing else certify wrote'
    );
});
cert_with_edited_site($s2Repo, static function (stdClass $site): void {
    unset($site->policy->scope->post_type->acme_item);
}, static function () use ($s2Repo): void {
    duo_check(
        !\Duo\InitPlanner::is_adoption_seed($s2Repo),
        'and an EMPTY policy.scope.post_type left behind still reads owned: neither verb writes an empty node '
        . '(writeScopeRules() runs only for a non-empty row set), so the set-aside removes only what it emptied'
    );
});

// (7) The two-surface case, through `duo adapter pin`: a declared post type
// AND a structural taxonomy, both adopted by the one command.
$pinRepo = cert_seed_site($root, 'seed-pin', $declManifest);
$seedPin = cert_run(['pin', $pinRepo, '--name=acme-cases', '--source=site']);
duo_check_same(0, $seedPin['exit'], 'the same adoption runs through `duo adapter pin` on a seed');
duo_check(
    str_contains($seedPin['out'], '+ policy.scope.post_type.acme_case = {"class": "authored"}')
        && str_contains($seedPin['out'], '+ policy.scope.taxonomy.acme_case_kind = {"class": "authored"}'),
    'writing one rule per declared surface, post type and taxonomy alike'
);
duo_check(
    \Duo\InitPlanner::is_adoption_seed($pinRepo),
    'and both rules are seed-compatible: they are one fact about the repository with the pin that wrote them, '
    . 'not a policy the operator hand-authored'
);
duo_check_same(
    ['post_types' => ['acme_case'], 'taxonomies' => ['acme_case_kind']],
    \Duo\InitPlanner::adapter_scope(
        ['acme-cases'],
        ['acme-cases' => Canon::decode(Canon::read_file($pinRepo . '/adapters/acme-cases.json'))]
    ),
    'THE REPUBLISH RULE: init folds exactly those surfaces into the flat policy.post_types/taxonomies it '
    . 'publishes for a selected adapter, and the scope rule does not survive beside them. One representation '
    . 'cannot disagree with itself; a site scope rule outranks every manifest, so a stale authored rule would '
    . 'keep classifying a surface its adapter had since reclassified'
);

// (8) Everything else under policy.scope is still an owned decision.
cert_with_scope_rule($pinRepo, 'post_type', 'acme_other', ['class' => 'runtime'], static function () use ($pinRepo): void {
    duo_check(
        !\Duo\InitPlanner::is_adoption_seed($pinRepo),
        'a hand-written runtime rule beside them is a decision only the site can have made — `--pin` writes '
        . 'authored and never a class it was not asked for — and the repository reads owned again'
    );
});
cert_with_scope_rule($pinRepo, 'post_type', 'acme_log', ['class' => 'authored'], static function () use ($pinRepo): void {
    duo_check(
        !\Duo\InitPlanner::is_adoption_seed($pinRepo),
        'and so does an authored rule for a type the pinned adapter classifies RUNTIME itself: the pin adopts '
        . 'declarations, so a rule it would never have written is not seed content'
    );
});
cert_with_scope_rule($pinRepo, 'taxonomy', 'acme_unrelated', ['class' => 'authored'], static function () use ($pinRepo): void {
    duo_check(
        !\Duo\InitPlanner::is_adoption_seed($pinRepo),
        'nor is an authored rule for a surface no pinned adapter declares at all'
    );
});
cert_with_scope_rule($pinRepo, 'post_type', 'acme_case', ['class' => 'runtime'], static function () use ($pinRepo): void {
    duo_check(
        !\Duo\InitPlanner::is_adoption_seed($pinRepo),
        'and flipping the pin\'s own rule to runtime reads owned: what is set aside is the exact rule the verb '
        . 'writes ({"class":"authored"}), not the surface it names'
    );
});

// ------------------------- bulk scope adoption over a repository SET (WP-3.4)
// The single-repo opt-in rides on the pin, which is right for the site that
// AUTHORED the adapter and wrong for the fleet that consumes it: adding one
// adapter to N sites cost N hand edits of site.duo.json, so operator cost
// scaled with sites × adapters. `duo adapter adopt-scope <repo>… --name=<n>`
// is that same opt-in over a set. What follows pins the four properties a
// batch write must have — idempotent, never overwriting a recorded node,
// byte-identical to the single-repo writer, and per-repo atomic under a
// mid-batch fault — because one defect in a batch write is multiplied by the
// size of the set.

/**
 * An init-shaped site repository that ALREADY PINS the adapter by
 * {name, source} — the state every consumer site is in before its scope is
 * widened, and the state `adopt-scope` requires (scope follows the pin).
 *
 * A source pin without a digest is exactly what `pin --source=site` writes as
 * its own override bootstrap (AdapterCertify::pin()), so this is the engine's
 * own admitted shape and not a fixture dialect.
 */
function cert_bulk_site(string $root, string $label, array $manifest, array $scope = []): string {
    $repo = $root . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    Canon::write_file($repo . '/adapters/' . $manifest['name'] . '.json', Canon::encode($manifest));
    $policy = [
        'options' => new stdClass(),
        'post_meta' => new stdClass(),
        'post_types' => ['attachment', 'page', 'post'],
        'taxonomies' => ['category', 'post_tag'],
        'term_meta' => new stdClass(),
    ];
    if ($scope !== []) {
        $policy['scope'] = $scope;
    }
    Canon::write_file($repo . '/site.duo.json', Canon::encode([
        'manifests' => ['core', ['name' => $manifest['name'], 'source' => AdapterSources::SITE]],
        'policy' => $policy,
        'spec_version' => DUO_SPEC_VERSION,
    ]));

    return $repo;
}

/** @return array<string,string> repo => its current site.duo.json bytes */
function cert_bulk_bytes(array $repos): array {
    $out = [];
    foreach ($repos as $repo) {
        $out[$repo] = (string) file_get_contents($repo . '/site.duo.json');
    }

    return $out;
}

$bulkOne = cert_bulk_site($root, 'bulk-one', $declManifest);
$bulkTwo = cert_bulk_site($root, 'bulk-two', $declManifest);
// The fleet member whose site already recorded a decision about the type — the
// DUO-3495 walkthrough's own shape, now one repository inside a set.
$bulkThree = cert_bulk_site($root, 'bulk-three', $declManifest, [
    'post_type' => ['acme_case' => ['class' => 'runtime']],
]);
$bulkSet = [$bulkOne, $bulkTwo, $bulkThree];

duo_check_same(
    // The manifest classifies the type `authored` and the pin makes that
    // reading resolve — but the site never opted the type into its flat list,
    // so capture still skips it. That gap is exactly what DUO-3495 reported
    // and what one adoption per site used to be the only cure for.
    ['in_scope' => false, 'class' => 'authored', 'source' => 'acme-cases', 'declared_by' => 'acme-cases'],
    cert_type_verdict($bulkOne, 'acme_case'),
    'fixture premise: every repository in the set pins the adapter, so the declaration resolves — and none of them '
    . 'is in scope, because the CLASS comes from the manifest while being in scope is the site\'s own act'
);
duo_check(
    !isset(Canon::decode(Canon::read_file($bulkOne . '/site.duo.json'))['policy']['scope']),
    'and no repository in the set has recorded a scope decision of its own: there is nothing here to overwrite'
);

$bulkBefore = cert_bulk_bytes($bulkSet);
$bulkDry = cert_run(array_merge(['adopt-scope'], $bulkSet, ['--name=acme-cases', '--dry-run']));
duo_check_same(0, $bulkDry['exit'], '--dry-run over the whole set exits 0');
duo_check(
    str_contains($bulkDry['out'], '--dry-run: nothing is written')
        && str_contains($bulkDry['out'], 'would adopt: 3 repo(s), 5 authored scope rule(s)')
        && cert_bulk_bytes($bulkSet) === $bulkBefore,
    'and reports the plan for the whole set without writing one byte — a fleet write is reviewable before it happens'
);

$bulkRun = cert_run(array_merge(['adopt-scope'], $bulkSet, ['--name=acme-cases']));
duo_check_same(0, $bulkRun['exit'], 'one invocation adopts the adapter\'s scope across three site repositories');
duo_check(
    str_contains($bulkRun['out'], 'adopted:    3 repo(s), 5 authored scope rule(s)')
        && str_contains($bulkRun['out'], "$bulkOne\n  + policy.scope.post_type.acme_case = {\"class\": \"authored\"}")
        && str_contains($bulkRun['out'], '  + policy.scope.taxonomy.acme_case_kind = {"class": "authored"}'),
    'printing every rule it wrote, per repository — a fleet-wide scope widen is never silent'
);
foreach ([$bulkOne, $bulkTwo] as $adoptedRepo) {
    duo_check_same(
        ['in_scope' => true, 'class' => 'authored', 'source' => 'site.duo.json', 'declared_by' => 'acme-cases'],
        cert_type_verdict($adoptedRepo, 'acme_case'),
        "the engine answers the three whole-type questions in $adoptedRepo exactly as the single-repo pin leaves them"
    );
}

// THE INVARIANT, carried into the batch: a recorded class is never overwritten,
// and the surface beside it is still adopted — the write is per NODE, not per
// repository, so one recorded decision does not cost the site the rest.
duo_check_same(
    ['class' => 'runtime'],
    Canon::decode(Canon::read_file($bulkThree . '/site.duo.json'))['policy']['scope']['post_type']['acme_case'] ?? null,
    'a recorded site scope class inside the set is left exactly as the site wrote it'
);
duo_check_same(
    ['class' => 'authored'],
    Canon::decode(Canon::read_file($bulkThree . '/site.duo.json'))['policy']['scope']['taxonomy']['acme_case_kind'] ?? null,
    'while the surface that repository had NOT decided is adopted in the same write'
);
duo_check(
    str_contains($bulkRun['out'], '! policy.scope.post_type.acme_case = {"class": "runtime"} — capture will skip post_type acme_case')
        && str_contains($bulkRun['out'], 'shadowed:   1 repo(s) record a decision this command never overwrites')
        && str_contains($bulkRun['out'], 'to override one, per site: duo adapter pin <site-repo> --name=acme-cases --adopt-scope'),
    'and the shadowed node is named with the per-site remedy — the override stays a reviewed act on ONE repository'
);

$bulkAfter = cert_bulk_bytes($bulkSet);
$bulkRepeat = cert_run(array_merge(['adopt-scope'], $bulkSet, ['--name=acme-cases']));
duo_check(
    $bulkRepeat['exit'] === 0
        && str_contains($bulkRepeat['out'], 'adopted:    0 repo(s), 0 authored scope rule(s)')
        && str_contains($bulkRepeat['out'], 'settled:    2 repo(s) had already decided every surface')
        && cert_bulk_bytes($bulkSet) === $bulkAfter,
    'a second invocation over the same set writes no rule and no byte: write-only-where-absent has no second effect'
);

// RULE 8, stated as bytes: the batch writes what the SHIPPED single-repo
// adoption writes. Both repositories start from identical bytes; one is
// adopted through AdapterCertify::adoptScope() — the exact call `certify --pin`
// and `pin` make — and the other through the batch verb.
$bulkSingle = cert_bulk_site($root, 'bulk-single', $declManifest);
$bulkBatch = cert_bulk_site($root, 'bulk-batch', $declManifest);
duo_check(
    hash_equals(
        (string) file_get_contents($bulkSingle . '/site.duo.json'),
        (string) file_get_contents($bulkBatch . '/site.duo.json')
    ),
    'premise: the single-repo and batch fixtures start byte-identical'
);
ob_start();
// The single-repo call `pin()` makes, argument for argument
// (AdapterCertify.php:467): the manifest is the one the ENGINE resolves, not
// the PHP literal above — `taxonomies => new stdClass()` survives a JSON round
// trip as an empty array, and only that array reads as a structural authored
// declaration (ScopeAdoption::declared_authored():78 requires is_array).
cert_private(
    'adoptScope',
    [$bulkSingle, 'acme-cases', cert_private('resolvedManifest', [$bulkSingle, 'acme-cases']), false]
);
$bulkSingleOut = (string) ob_get_clean();
duo_check_same(
    0,
    cert_run(['adopt-scope', $bulkBatch, '--name=acme-cases'])['exit'],
    'the batch verb runs over a one-repository set'
);
duo_check(
    hash_equals(
        (string) file_get_contents($bulkSingle . '/site.duo.json'),
        (string) file_get_contents($bulkBatch . '/site.duo.json')
    ),
    'and leaves site.duo.json BYTE-IDENTICAL to what the shipped single-repo writer leaves — the batch adds no '
    . 'scope semantics, it reuses writeScopeRules() and therefore the same typed, canonical, atomic write'
);
duo_check(
    str_contains($bulkSingleOut, 'scope: wrote 2 authored scope rule(s)')
        && str_contains($bulkSingleOut, '+ policy.scope.post_type.acme_case = {"class": "authored"}'),
    'and the single-repo verb\'s own output is unchanged by the batch mode existing (rule 8)'
);

// A repository that does not resolve the adapter refuses the WHOLE set before
// anything is written: scope follows the pin, and writing scope for an adapter
// a site never pinned would opt it into types nothing can classify.
$bulkUnpinned = cert_init_site($root, 'bulk-unpinned', $declManifest, []);
$bulkFresh = cert_bulk_site($root, 'bulk-fresh', $declManifest);
$bulkFreshBefore = (string) file_get_contents($bulkFresh . '/site.duo.json');
$bulkRefusal = cert_run_cli(['adopt-scope', $bulkFresh, $bulkUnpinned, '--name=acme-cases']);
duo_check_same(2, $bulkRefusal['exit'], 'a set holding one repository that does not pin the adapter is refused');
duo_check(
    str_contains($bulkRefusal['err'], 'wrote nothing')
        && str_contains($bulkRefusal['err'], "the engine resolves no adapter 'acme-cases' here")
        && str_contains($bulkRefusal['err'], "duo adapter pin $bulkUnpinned --name=acme-cases"),
    'naming the repository and the command that fixes it, rather than adopting the rest and reporting a partial'
);
duo_check(
    hash_equals($bulkFreshBefore, (string) file_get_contents($bulkFresh . '/site.duo.json')),
    'and the healthy repository in that set is untouched — the plan phase writes nothing at all'
);

// Two SPELLINGS of one repository, not two copies of one string: identity is
// realpath()'s, so a set that would have been planned and counted twice is
// refused instead. The bytes are checked because a second plan of an
// already-planned repository is precisely how a batch write double-counts.
$bulkDupBefore = (string) file_get_contents($bulkFresh . '/site.duo.json');
$bulkDup = cert_run_cli(['adopt-scope', $bulkFresh, $bulkFresh . '/./', '--name=acme-cases']);
duo_check_same(
    2,
    $bulkDup['exit'],
    'one repository named twice under two spellings is refused rather than planned twice against a count nobody '
    . 'can reconcile with the set they typed'
);
duo_check(
    str_contains($bulkDup['err'], "names the same site repository as '$bulkFresh'")
        && hash_equals($bulkDupBefore, (string) file_get_contents($bulkFresh . '/site.duo.json')),
    'naming the argument that already claimed it, and writing nothing'
);
duo_check_same(
    2,
    cert_run(['adopt-scope', $noSite, '--name=acme-cases'])['exit'],
    'a directory with no site.duo.json is not a site repo here either'
);
duo_check_same(
    2,
    cert_run(['adopt-scope', '--name=acme-cases'])['exit'],
    'adopt-scope with no repository is a usage error'
);
duo_check_same(2, cert_run(['adopt-scope', $bulkFresh])['exit'], 'and so is adopt-scope without --name');
$bulkOverride = cert_run_cli(['adopt-scope', $bulkThree, '--name=acme-cases', '--adopt-scope']);
duo_check_same(2, $bulkOverride['exit'], '--adopt-scope is refused in bulk mode');
duo_check(
    str_contains($bulkOverride['err'], 'overriding a decision a site RECORDED is a per-site')
        && str_contains($bulkOverride['err'], 'duo adapter pin <site-repo> --name=acme-cases --adopt-scope'),
    'because one flag flipping a recorded class across a fleet is exactly the multiplied consequence this verb '
    . 'exists to avoid — the per-site command is printed instead'
);

// FAULT INJECTION: a repository in the middle of the set cannot be written.
// The property under test is not the diagnostic but the STATE: every
// repository ends fully adopted or untouched, never half-written, and the
// operator is told which is which.
$faultOne = cert_bulk_site($root, 'fault-one', $declManifest);
$faultTwo = cert_bulk_site($root, 'fault-two', $declManifest);
$faultThree = cert_bulk_site($root, 'fault-three', $declManifest);
$faultBefore = cert_bulk_bytes([$faultOne, $faultTwo, $faultThree]);
chmod($faultTwo, 0555);
$faultRun = cert_run_cli(['adopt-scope', $faultOne, $faultTwo, $faultThree, '--name=acme-cases']);
chmod($faultTwo, 0755);
duo_check_same(2, $faultRun['exit'], 'a repository that cannot be written mid-batch fails the invocation');
duo_check(
    !hash_equals($faultBefore[$faultOne], (string) file_get_contents($faultOne . '/site.duo.json'))
        && hash_equals($faultBefore[$faultTwo], (string) file_get_contents($faultTwo . '/site.duo.json'))
        && hash_equals($faultBefore[$faultThree], (string) file_get_contents($faultThree . '/site.duo.json')),
    'THE ATOMICITY PROPERTY: the repository written before the fault is fully adopted, the failing one is '
    . 'byte-for-byte untouched, and the one after it was never attempted'
);
duo_check_same(
    ['class' => 'authored'],
    Canon::decode(Canon::read_file($faultOne . '/site.duo.json'))['policy']['scope']['post_type']['acme_case'] ?? null,
    'the adopted repository holds a COMPLETE adoption, not a partial one'
);
duo_check(
    str_contains($faultRun['err'], "adopted before this failure: $faultOne")
        && str_contains($faultRun['err'], "untouched: $faultTwo, $faultThree")
        && str_contains($faultRun['err'], 're-run the same command — adoption is idempotent'),
    'and the refusal is a LEDGER: which repositories moved, which did not, and why re-running is safe'
);
duo_check(
    trim($faultRun['out']) !== '' && !str_contains($faultRun['out'], 'PHP Warning')
        && !str_contains($faultRun['err'], 'PHP Warning') && !str_contains($faultRun['err'], 'Notice:'),
    'with no PHP diagnostic anywhere: tempnam() silently falls back to the system temp directory on an '
    . 'unwritable directory, so the unguarded write would have named /tmp in a warning instead of the repository'
);
$faultResume = cert_run(['adopt-scope', $faultOne, $faultTwo, $faultThree, '--name=acme-cases']);
duo_check(
    $faultResume['exit'] === 0
        && str_contains($faultResume['out'], 'adopted:    2 repo(s), 4 authored scope rule(s)')
        && str_contains($faultResume['out'], 'settled:    1 repo(s) had already decided every surface'),
    're-running after the fault finishes the set and re-decides nothing in the repository that had already moved'
);

// The atomicity above must not rest on the writability GUARD, which is a
// diagnostic and therefore a TOCTOU check. Drive the shipped writer straight
// at an unwritable repository: the tempnam()+rename() discipline is what keeps
// the file whole, and it is asserted here on its own.
$faultRaw = cert_bulk_site($root, 'fault-raw', $declManifest);
$faultRawBefore = (string) file_get_contents($faultRaw . '/site.duo.json');
chmod($faultRaw, 0555);
// Scoped to this one call: the expected rename() warning is the thing under
// test, and a suite whose job is to make a real failure legible must not print
// a diagnostic it provoked on purpose.
set_error_handler(static fn (): bool => true);
try {
    duo_check_throws(
        static fn() => cert_private('writeScopeRules', [
            $faultRaw,
            [['kind' => 'post_type', 'name' => 'acme_case']],
        ]),
        RuntimeException::class,
        'the shipped scope writer refuses when the atomic rename cannot land'
    );
} finally {
    restore_error_handler();
    chmod($faultRaw, 0755);
}
duo_check(
    hash_equals($faultRawBefore, (string) file_get_contents($faultRaw . '/site.duo.json')),
    'and leaves site.duo.json byte-for-byte intact WITHOUT the guard — the derived-path tempnam()+rename() '
    . 'discipline is the atomicity, the writability check only names the repository the operator must fix'
);

// ------------------------------------------------------------------ closure

duo_check(
    !str_contains((string) file_get_contents($duoRoot . '/cli/src/Adapter/AdapterCertify.php'), 'sandbox/'),
    'the verb never reaches into the sandbox tree'
);
duo_check(
    str_contains(
        (string) file_get_contents($duoRoot . '/cli/duo'),
        'AdapterCertify::VERBS'
    ),
    'every verb is dispatched from cli/duo by the class\'s own closed list'
);
foreach (AdapterCertify::VERBS as $verb) {
    duo_check(
        str_contains((string) file_get_contents($duoRoot . '/cli/duo'), 'duo adapter ' . $verb . ' '),
        "`duo adapter $verb` appears in the public usage text"
    );
}

duo_check_summary('regress_adapter_certify');
