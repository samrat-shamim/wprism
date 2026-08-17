<?php
/**
 * Offline contract for `duo adapter keygen | certify | pin` (round-3 T6 §3.5).
 *
 * Three things this suite is for, in the order they matter.
 *
 * **1. The bundle this command builds is accepted by the SHIPPED verifier.**
 * `AdapterCertify` deliberately produces the existing
 * `duo-site-adapter-certification-bundle/v1` grammar rather than a relaxed
 * one, so the adversarial matrix in `regress_site_adapter_certification.php`
 * keeps applying to an operator-authored certificate unchanged. That claim is
 * only worth anything if it is exercised end to end, so the core case here
 * signs and then VERIFIES through `AdapterCertification::verifyFile()` — the
 * same call the live policy path makes.
 *
 * The signing round trip runs against a scratch agent manifest directory whose
 * `capabilities/adapter-authorities.json` holds the operator's key. That is not
 * a test backdoor: `DUO_MANIFESTS_DIR` is the documented way every offline
 * adapter command retargets the library (`ManifestValidate`, `AdapterDraft`
 * and `regress_site_adapter_certification.php` all use it). It also keeps this
 * suite independent of the SITE trust root, which is the agent half of T6 §3.1
 * — so a red line here is about the CLI's bundle, never about that.
 *
 * **2. The two mutations refuse before they damage anything.** A private key
 * inside a repository that gets committed is a published key; an overwritten
 * key orphans every certificate it signed; a rewritten `site.duo.json` that
 * flattened `"policy": {}` into `[]` would produce a file the engine refuses
 * to load. Each is asserted rather than reasoned about — the third one was a
 * real defect caught by the first smoke run of `duo adapter pin`.
 *
 * **3. The derived ratification satisfies the disposition validator.**
 * `certify` has to synthesize a `duo-manifest-dispositions/v1` entry from a
 * manifest, and `ManifestDispositions::validate_entry()` checks that entry
 * against the manifest in five different directions (named sections must
 * exist, default-authored keyspaces must be one-for-one, intent-only tables
 * must be marked unsupported, a certified entry's plugin versions must match
 * its contract). A manifest exercising all of them is signed here.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/check.php';

$duoRoot = dirname(__DIR__, 2);
require_once $duoRoot . '/cli/src/Adapter/AdapterCertify.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
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

    $registry = json_decode((string) file_get_contents(
        $duoRoot . '/manifests/capabilities/registry.json'
    ), true);
    Canon::write_file($dir . '/capabilities/registry.json', Canon::encode([
        'format' => $registry['format'],
        'platform' => $registry['platform'],
    ]));

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
        str_contains($refusal['err'], 'inside the duo site repository'),
        "and names the repository it found ($inside) — the walk is up the ancestors, not one level"
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
$library = cert_agent_library($root, 'signlib', ['acme-catalog'], [AdapterSources::TIER_DECLARATIVE]);
$secretPath = $keyDir . '/sign.key';
file_put_contents($secretPath, base64_encode($library['secret']) . "\n");
chmod($secretPath, 0600);

$adapterRaw = (string) file_get_contents($signRepo . '/adapters/acme-catalog.json');
$reason = 'Acme Ltd reviewed this adapter against its own catalog schema.';
$bundleDir = cert_private('buildBundle', [
    $signRepo, 'acme-catalog', $rich, $adapterRaw, ['status' => 'ok', 'message' => null], $reason,
]);

$bundleRaw = (string) file_get_contents($bundleDir . '/bundle.json');
$bundle = json_decode($bundleRaw, true);
duo_check_same(
    AdapterCertification::BUNDLE_FORMAT,
    $bundle['schema_version'] ?? null,
    'the bundle declares the SHIPPED schema — one grammar, one verifier'
);
duo_check_same([], $bundle['artifacts'] ?? null, 'artifacts[] is a STATED empty: nothing was exercised');
duo_check_same(
    AdapterCertify::NO_EVIDENCE_REVISION,
    $bundle['git_revision'] ?? null,
    'git_revision is all zeros: this profile binds no evidence repository, and a borrowed '
    . 'site-repo HEAD would read as provenance it is not'
);
duo_check_same([], $bundle['force_hatches'] ?? null, 'no force hatches — a certificate is not a vehicle for one');
duo_check_same(
    ['adapters/acme-catalog.json'],
    array_column((array) ($bundle['bound_inputs'] ?? []), 'path'),
    'the bundle binds exactly the adapter file'
);
duo_check_same(
    hash('sha256', $adapterRaw),
    $bundle['bound_inputs'][0]['sha256'] ?? null,
    'and binds its EXACT bytes, which is what makes the certificate specific to them'
);

$result = json_decode(
    (string) file_get_contents($bundleDir . '/results/' . AdapterCertify::GRAMMAR_TEST . '.json'),
    true
);
duo_check_same(
    ['exercised' => false, 'exit_code' => 0, 'grammar' => 'ok', 'reason' => $reason,
        'schema_version' => 1, 'test' => AdapterCertify::GRAMMAR_TEST, 'verdict' => 'pass'],
    $result,
    'T6 §3.5 evidence triple {grammar, exercised: false, reason} is the named test\'s recorded result'
);

$ratification = json_decode((string) file_get_contents($bundleDir . '/ratification.json'), true);
$disposition = $ratification['manifests']['acme-catalog'] ?? [];
duo_check_same('certified', $disposition['status'] ?? null, 'the derived disposition is a certified entry');
duo_check_same($reason, $disposition['reason'] ?? null, 'the operator\'s stated reason is what it is certified ON');
duo_check_same(
    ['post_types', 'tables'],
    $disposition['capabilities']['entity_sections'] ?? null,
    'entity_sections names only sections the manifest actually declares'
);
duo_check_same(
    ['option_namespaces', 'options', 'post_meta'],
    $disposition['capabilities']['field_sections'] ?? null,
    'and so does field_sections — a disposition naming an absent section is refused'
);
duo_check_same(
    ['acme_catalog_index'],
    array_column((array) ($disposition['default_authored_keyspaces'] ?? []), 'table'),
    'default_authored_keyspaces is exactly the default-authored tables, one for one'
);
duo_check(
    in_array('tables.acme_catalog_intent', array_column(
        (array) ($disposition['unsupported'] ?? []),
        'surface'
    ), true),
    'the intent-only table is marked unsupported, which validate_entry() requires'
);
duo_check_same(
    ['plugin' => 'acme-catalog/acme-catalog.php', 'range' => ['max' => '3.0.0', 'min' => '1.0.0']],
    $disposition['supported_versions'] ?? null,
    'supported_versions mirrors the manifest\'s own plugin contract, which a certified entry must'
);
duo_check_same(
    ['supported' => [], 'unsupported' => ['all']],
    $disposition['capabilities']['deletion_semantics'] ?? null,
    'deletion is declared UNSUPPORTED: a validator run cannot review deletion semantics, and a '
    . 'certificate claiming them would claim a review nobody did'
);
duo_check(
    !in_array('delete', (array) ($disposition['capabilities']['operations'] ?? []), true),
    'and `delete` is absent from the claimed operations for the same reason'
);
duo_check(
    in_array('apply', (array) ($disposition['capabilities']['operations'] ?? []), true)
    && in_array('deploy', (array) ($disposition['capabilities']['operations'] ?? []), true),
    'while apply+deploy ARE claimed — CapabilityRegistry mints `promote` from them, which is what '
    . 'lets duo release admit a site-certified adapter through its existing gate'
);

// The claim this whole suite exists for: the SHIPPED signer and the SHIPPED
// live verifier both accept what AdapterCertify built.
$certificate = AdapterCertification::sign(
    $library['dir'],
    $signRepo,
    'acme-catalog',
    $bundleDir,
    $signRepo,
    $library['key_id'],
    base64_encode($library['secret'])
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
    $library['dir'],
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
    $library['key_id'],
    $summary['authority']['key_id'] ?? null,
    'under the operator\'s own key'
);

// Byte-binding, restated as a live check rather than a claim: one edited byte
// and the certificate stops verifying. This is why the guides say to re-run
// certify after every adapter edit.
$tampered = $rich;
$tampered['options']['acme_catalog_layout']['class'] = 'runtime';
Canon::write_file($signRepo . '/adapters/acme-catalog.json', Canon::encode($tampered));
duo_check_throws(
    static fn() => AdapterCertification::verifyFile(
        $library['dir'],
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
$public = sodium_crypto_sign_publickey_from_secretkey($library['secret']);
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
file_put_contents($loose, base64_encode($library['secret']) . "\n");
chmod($loose, 0644);
duo_check_throws(
    static fn() => AdapterCertify::readSecretKey($loose),
    RuntimeException::class,
    'a group/world-readable secret key file is refused: signing with one mints an authority '
    . 'anybody on the box could forge'
);
chmod($loose, 0600);
duo_check_same(
    $library['secret'],
    AdapterCertify::readSecretKey($loose),
    'a 0600 base64 secret key reads back byte-for-byte'
);
$hexKey = $keyDir . '/hex.key';
file_put_contents($hexKey, bin2hex($library['secret']) . "\n");
chmod($hexKey, 0600);
duo_check_same(
    $library['secret'],
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

// A site.duo.json that is not canonical is refused rather than rewritten: the
// engine reads those bytes exactly, and quietly canonicalising an operator's
// file is an edit they did not ask for.
$roughRepo = $root . '/roughsite';
mkdir($roughRepo . '/adapters', 0755, true);
file_put_contents(
    $roughRepo . '/site.duo.json',
    "{\"spec_version\": 2, \"manifests\": [\"core\"], \"policy\": {}}\n"
);
duo_check_throws(
    static fn() => cert_private('writePin', [$roughRepo, $pin]),
    RuntimeException::class,
    'a non-canonical site.duo.json is refused, not silently rewritten'
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
    'the three verbs are dispatched from cli/duo by the class\'s own closed list'
);
foreach (AdapterCertify::VERBS as $verb) {
    duo_check(
        str_contains((string) file_get_contents($duoRoot . '/cli/duo'), 'duo adapter ' . $verb . ' '),
        "`duo adapter $verb` appears in the public usage text"
    );
}

duo_check_summary('regress_adapter_certify');
