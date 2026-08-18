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

require_once __DIR__ . '/lib/check.php';

$duoRoot = dirname(__DIR__, 2);
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
