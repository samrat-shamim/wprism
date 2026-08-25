<?php
/**
 * POPULATING THE PLATFORM TRUST ROOT — the enrollment ceremony, end to end
 * (spec/repo-format.md § v3.7 and § v3.8; WP-5.1).
 *
 * WHAT THIS SUITE IS, AND THE ONE THING IT IS NOT
 * ----------------------------------------------
 * `manifests/capabilities/adapter-authorities.json` is the one file in this
 * repository whose contents decide what a stranger's key may certify on every
 * managed site. It ships as the EMPTY v1 registry and stays that way: gate G4
 * is what admits a real external author, and every one of its conditions must
 * hold before a single key is issued (docs/guides/trust-enrollment.md carries
 * the checklist with its current truth values). So this suite proves the
 * MECHANISM and the CEREMONY over the program's own fixture keys, in scratch
 * libraries, and it re-asserts on every run that the SHIPPED file is still
 * `{"format":"duo-adapter-authorities/v1","keys":{}}` byte for byte.
 *
 * NO REAL VENDOR WAS VETTED HERE, and nothing below should be read as saying
 * one was. `acme-*` is a fixture namespace and its key is a deterministic
 * `sodium_crypto_sign_seed_keypair()` seed checked into this file. What is
 * proven is that the enrollment path runs end to end through the SHIPPED
 * producers and the SHIPPED reader — never a second implementation living in a
 * test — and that the refusals an enrolled root must have are the refusals it
 * does have.
 *
 * THE SEVEN THINGS PROVED, IN ORDER
 * ---------------------------------
 *  1. THE CEREMONY. Mint a platform root key; write a v2 registry; watch the
 *     shipped reader REFUSE it unsigned; sign it through the reviewer verb
 *     `authorities-sign`; watch the same reader accept it. The failing-before
 *     step is inside the ceremony rather than beside it, because "a populated
 *     root must carry the signed envelope R-18 requires" is the single property
 *     that distinguishes an enrolled registry from an edited file.
 *  2. THE DELEGATION. The enrolled platform key delegates a namespace pattern,
 *     a tier set and a validity window to a vendor key, through
 *     `delegation-sign`; the site installs it; the vendor certifies an adapter
 *     in that one repository through `sign-site`.
 *  3. THE WORD, PROJECTED HONESTLY. A DELEGATED key resolves under trust root
 *     `site` (§ v3.8 spends none of R-13's third value), so the operator-facing
 *     word for its adapter is `site_signed`. `third_party_signed` is the word
 *     an adapter certified DIRECTLY under the enrolled platform key projects,
 *     and that path takes a reviewed-exercise bundle — which is exactly why G4
 *     condition (7) ("a third party has produced an exercised bundle") is a
 *     separate condition from this one. Both words are driven here, from the
 *     same enrolled root, so the difference is measured rather than argued.
 *  4. THE SECOND-ENROLLMENT INVARIANT (WP-4.8, § v3.7 change (5); G4 condition
 *     2). A real enrollment event moves four things at once — a new key appears,
 *     the enrolling key's namespace widens, a tier is added, and its window is
 *     renewed — and the envelope signature over `{format, keys}` moves with all
 *     of them. Every certificate already issued under the first key must still
 *     verify, with its pinned `record_sha256` unmoved. This is the property that
 *     makes enrollment cadence independent of release cadence; without it the
 *     first vendor's sites break the day the second is admitted. DEMONSTRATED
 *     against the prior defect: restoring `authorityIdentity()` to the
 *     pre-WP-4.8 whole-record binding (a one-line `unset()` removal) makes step
 *     7 refuse with "certification authority/key/fingerprint/trust root does not
 *     match the current platform authority record".
 *  5. THE REFUSAL MATRIX the plan's acceptance names: the same certificate
 *     refuses after the delegation expires, after the delegator is revoked, and
 *     for a name outside the delegated namespace.
 *  6. THE REVOCATION DRILL, on WP-1.4's rehearsal fleet, with propagation
 *     latency measured and printed. The estate is built by its own driver,
 *     unchanged — `spec_migration_estate.php` still installs no revocation
 *     document, which is what keeps `regress_spec_migration_rehearsal.php`'s
 *     named gaps true — and the drill installs one AFTER materialization,
 *     which is precisely what an incident responder does to a fleet that
 *     already exists. DEMONSTRATED against the prior defect: making
 *     `AdapterCertification::revocations()` return `[]` (the pre-WP-4.9 world,
 *     where revocation was a `status` word on the agent-release cadence) makes
 *     the live-path assertion in step 8 fail and the drill unable to complete.
 *  7. THAT THE CEREMONY IS WRITTEN DOWN. G4's condition (5) is a document, and
 *     its checklist is the thing most likely to rot quietly, so the structure of
 *     `docs/guides/trust-enrollment.md` — every condition still enumerated, the
 *     unmet ones still named as unmet — is asserted here.
 *
 * @see docs/guides/trust-enrollment.md — the vetting posture, the rotation and
 *      compromise ceremonies, and the G4 checklist this suite is evidence for.
 * @see sandbox/tests/offline/adapter/regress_authority_record_v2.php — the v2
 *      record grammar itself (ids, window, namespace, envelope).
 * @see sandbox/tests/offline/adapter/regress_authority_delegation.php — the
 *      delegation refusal matrix in full.
 * @see sandbox/tests/offline/adapter/regress_revocation_reachability.php — the
 *      typed channel's own grammar and its three installed states.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterSources.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterCertification.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';

use Duo\AdapterCertification;
use Duo\AdapterSources;
use Duo\Canon;
use Duo\Policy;
use Duo\RepositoryCompiler;

// The two WordPress seams a policy load can touch. They REFUSE rather than
// answer, exactly as `spec_migration_estate.php:2060-2072` does: a suite that
// reached a target would stop being offline, and a silent stub would hide the
// reach.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        throw new RuntimeException("trust-enrollment fixture: target contact get_option($name)");
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(...$args): array {
        throw new RuntimeException('trust-enrollment fixture: target contact wp_upload_dir');
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value = null) {
        return $value;
    }
}
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

if (!function_exists('sodium_crypto_sign_seed_keypair')) {
    fwrite(STDERR, "FAIL: the PHP sodium extension is required for the enrollment ceremony\n");
    exit(1);
}

$repo = dirname(__DIR__, 4);

function pop_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        pop_remove_tree($item->getPathname());
    }
    rmdir($path);
}

function pop_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        throw new RuntimeException("trust-enrollment fixture: cannot create $to");
    }
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getBasename();
        if ($item->isDir() && !$item->isLink()) {
            pop_copy_tree($item->getPathname(), $target);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('trust-enrollment fixture: cannot copy ' . $item->getPathname());
        }
    }
}

function pop_write(string $path, string $bytes): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("trust-enrollment fixture: cannot create $dir");
    }
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException("trust-enrollment fixture: cannot write $path");
    }
}

/** @param mixed $value */
function pop_write_canon(string $path, $value): void {
    pop_write($path, Canon::encode($value));
}

/**
 * A minimal reviewed-EXERCISE certification bundle, on disk.
 *
 * Suite-local rather than shared, and the reason is the one
 * `AdapterCertification::sign_site()` states about its own internal builder:
 * the bundle grammar belongs to the engine (`verifyBundleManifest()` refuses
 * any deviation by exact key set) and the reviewed-exercise producer is
 * DELIBERATELY external — it is the output of a real conformance run this
 * repository has no business performing. So there is no shared producer to
 * reuse; what a fixture can do is emit the shape the verifier demands and let
 * the verifier be the judge, which is what happens two calls later when the
 * minted certificate is read back through `verifyFile()`.
 *
 * The `evidence.exercised: true` arm is the one that matters here: a
 * PLATFORM-rooted certificate may only be signed from an exercised bundle
 * (`bundleEvidence()`), which is precisely why the third-party word is gated on
 * a conformance run rather than on holding a key.
 */
function pop_write_bundle(string $dir, string $site, string $name, array $manifest): string {
    $pretty = static fn($value): string => json_encode(
        \Duo\Canon::normalize($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
    $descriptor = static function (string $path, string $relative): array {
        $contents = (string) file_get_contents($path);
        return ['path' => $relative, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
    };

    // Inside the shipped boundary's own exercised series, because the fixture
    // library IS the shipped one: 8.3 -> 8.3.33 and 7.0 -> 7.0.3 are the exact
    // patches manifests/capabilities/platform.json records a live proof ran on.
    $environment = ['multisite' => false, 'php' => '8.3.33', 'wordpress' => '7.0.3'];
    pop_write_canon($dir . '/environment.json', $environment);
    $ratification = [
        'format' => AdapterCertification::RATIFICATION_FORMAT,
        'manifests' => [
            $name => [
                'capabilities' => [
                    'deletion_semantics' => ['supported' => [], 'unsupported' => ['all']],
                    'entity_sections' => ['post_types'],
                    'field_sections' => ['options'],
                    'lifecycle_phases' => [],
                    'operations' => ['apply', 'capture', 'deploy'],
                ],
                'default_authored_keyspaces' => [],
                'evidence' => [
                    'bundle_schema' => AdapterCertification::BUNDLE_FORMAT,
                    'tests' => ['acme-conformance'],
                ],
                'reason' => 'The enrolled reviewer exercised this exact declarative adapter against a live target.',
                'status' => 'certified',
                // `ManifestDispositions::validate_entry()` refuses a certified
                // entry whose versions disagree with the manifest contract
                // (:1044-1052), so this is the manifest's own `plugin` and
                // `version_range` verbatim — a ratification that narrowed
                // either would be a certificate covering a version line the
                // adapter never declared.
                'supported_versions' => [
                    'plugin' => (string) $manifest['plugin'],
                    'range' => $manifest['version_range'],
                ],
                'unsupported' => [[
                    'operation' => 'delete',
                    'reason' => 'Deletion was not part of this focused certification.',
                    'surface' => 'all',
                ]],
            ],
        ],
        'profiles' => [],
    ];
    pop_write_canon($dir . '/ratification.json', $ratification);
    pop_write($dir . '/results/acme-conformance.json', $pretty([
        'exit_code' => 0,
        'schema_version' => 1,
        'test' => 'acme-conformance',
        'verdict' => 'pass',
    ]));
    pop_write_canon($dir . '/diffs/acme-conformance.json', ['changed' => [], 'status' => 'clean']);
    pop_write($dir . '/logs/acme-conformance.txt', "acme-shop conformance passed\n");

    $bundle = [
        'artifacts' => [[
            'name' => 'acme-shop',
            'role' => 'certified-boundary',
            'sha256' => str_repeat('c', 64),
            'url' => 'https://example.invalid/acme-shop-2.4.1.zip',
            'version' => '2.4.1',
        ]],
        'bound_inputs' => [$descriptor($site . '/adapters/' . $name . '.json', 'adapters/' . $name . '.json')],
        'created_at' => '2026-08-09T00:00:00Z',
        'environment' => $descriptor($dir . '/environment.json', 'environment.json'),
        'environment_summary' => $environment,
        'evidence' => [
            'exercised' => true,
            'grammar' => 'ok',
            'reason' => 'the enrolled reviewer exercised this adapter against a live conformance target',
        ],
        'force_hatches' => [],
        'git_revision' => str_repeat('a', 40),
        'harness' => ['name' => 'platform-authority-population-regression', 'version' => 1],
        'ratification' => $descriptor($dir . '/ratification.json', 'ratification.json'),
        'ratification_summary' => [
            'certified_claims' => ['manifests.' . $name],
            'manifest_count' => 1,
            'profile_count' => 0,
        ],
        'schema_version' => AdapterCertification::BUNDLE_FORMAT,
        'subject' => ['kind' => 'site_adapter', 'name' => $name],
        'tests' => [[
            'diff' => $descriptor($dir . '/diffs/acme-conformance.json', 'diffs/acme-conformance.json'),
            'id' => 'acme-conformance',
            'log' => $descriptor($dir . '/logs/acme-conformance.txt', 'logs/acme-conformance.txt'),
            'result' => $descriptor($dir . '/results/acme-conformance.json', 'results/acme-conformance.json'),
            'verdict' => 'pass',
        ]],
        'verdict' => 'pass',
    ];
    $digestInput = $bundle;
    unset($digestInput['bundle_digest']);
    $bundle['bundle_digest'] = hash('sha256', json_encode(
        \Duo\Canon::normalize($digestInput),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
    pop_write($dir . '/bundle.json', $pretty($bundle));

    return $dir;
}

/** @return array{exit:int,stdout:string,stderr:string} */
function pop_run(array $command): array {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return ['exit' => 1, 'stdout' => '', 'stderr' => 'cannot start ' . implode(' ', $command)];
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

// Rule 3: scratch never lives under agent/ or manifests/ —
// `sandbox/bin/pair.sh:355` refuses on an untracked file there. A unique root
// per run, because `make -j8` runs this corpus concurrently.
$root = sys_get_temp_dir() . '/duo-authority-population-' . bin2hex(random_bytes(6));
register_shutdown_function(static fn() => pop_remove_tree($root));

$library = $root . '/library';
$site = $root . '/site';
pop_copy_tree($repo . '/manifests', $library);
if (!mkdir($site . '/adapters', 0777, true)) {
    fwrite(STDERR, "FAIL: cannot create the scratch site repository at $site\n");
    exit(1);
}

$refusal = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

/**
 * Deterministic fixture keypairs, with the v2 fingerprint id computed the way
 * the grammar computes it — a delegated or enrolled id that does not derive
 * from its own key material is one of the refusals § v3.7 change (1) names.
 */
$pair = static function (string $seed): array {
    $keypair = sodium_crypto_sign_seed_keypair(str_repeat($seed, SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $public = sodium_crypto_sign_publickey($keypair);

    return [
        'secret' => sodium_crypto_sign_secretkey($keypair),
        'public' => $public,
        'encoded' => base64_encode($public),
        'fingerprint' => hash('sha256', $public),
        'short' => substr(hash('sha256', $public), 0, 12),
    ];
};
$platformKey = $pair('R');
$vendorKey = $pair('V');
$secondVendorKey = $pair('S');
$platformId = 'platform-' . $platformKey['short'];
$vendorId = 'acme-' . $vendorKey['short'];
$secondVendorId = 'zeta-' . $secondVendorKey['short'];

$secretFile = static function (string $path, string $secret): string {
    pop_write($path, base64_encode($secret) . "\n");
    chmod($path, 0600);
    return $path;
};
$platformSecretPath = $secretFile($root . '/platform-root.key', $platformKey['secret']);
$vendorSecretPath = $secretFile($root . '/vendor.key', $vendorKey['secret']);

/**
 * A v2 authority record: the six v1 members, plus the version and the window.
 *
 * The default `not_after` is deliberately far out. The window an ENROLLED root
 * carries is not this suite's subject — the expiry case below is about the
 * DELEGATION's window, judged in-process against the injected clock — while the
 * `sign-site` and `sign` steps run in CHILD processes at the real wall clock,
 * which no test seam reaches. A near-dated literal here would therefore stop the
 * ceremony on its own expiry date, and the failure would read as a broken
 * enrollment path rather than as a fixture that aged out.
 */
$v2Record = static fn(
    string $encodedPublic,
    array $names,
    array $tiers,
    string $notBefore = '2026-01-01T00:00:00Z',
    string $notAfter = '2099-01-01T00:00:00Z',
    string $status = 'trusted'
): array => [
    'adapter_names' => $names,
    'algorithm' => 'ed25519',
    'not_after' => $notAfter,
    'not_before' => $notBefore,
    'public_key' => $encodedPublic,
    'record_version' => 2,
    'scope' => 'site_adapter_certification',
    'status' => $status,
    'trust_tiers' => $tiers,
];

$authoritiesPath = $library . '/capabilities/adapter-authorities.json';
$revocationsPath = $library . '/capabilities/adapter-revocations.json';

/** The one selector every consumer reaches; driving it is driving the product. */
$authority = new ReflectionMethod(AdapterCertification::class, 'authority');
$resolve = static fn(string $id): array => (array) $authority->invoke(null, $library, $id, $site);
$scope = new ReflectionMethod(AdapterCertification::class, 'assertAuthorityScope');
$clock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
$setClock = static function (?string $instant) use ($clock): void {
    $clock->setValue(null, $instant === null ? null : static fn(): int => (int) strtotime($instant));
};
$setClock('2026-06-01T00:00:00Z');

// ---------------------------------------------------------------------------
echo "\n== the shipped precondition: G4 gates real population, and it has not opened ==\n";
// ---------------------------------------------------------------------------

$shippedAuthorities = (string) file_get_contents($repo . '/manifests/capabilities/adapter-authorities.json');
duo_check_same(
    Canon::encode((object) ['format' => AdapterCertification::AUTHORITIES_FORMAT, 'keys' => new stdClass()]),
    $shippedAuthorities,
    'manifests/capabilities/adapter-authorities.json is still the EMPTY v1 registry, BYTE FOR BYTE — this work '
    . 'package populates a scratch library and never the shipped one, because issuing a real key is gate G4\'s '
    . 'decision and G4 has conditions this environment cannot meet'
);
duo_check(
    !str_contains($shippedAuthorities, $platformKey['short'])
        && !str_contains($shippedAuthorities, $vendorKey['short'])
        && !str_contains($shippedAuthorities, $secondVendorKey['short']),
    'and no fixture key of this suite appears anywhere in it: the ceremony below is rehearsed over seeds checked '
    . 'into this file, not over material any party holds'
);
duo_check(
    !file_exists($repo . '/manifests/capabilities/adapter-revocations.json'),
    'the shipped manifest library still carries NO revocation document either — its absence is what makes '
    . '"nothing is revoked" an answer rather than a default (§ v3.8)'
);

// ---------------------------------------------------------------------------
echo "\n== step 1 of the ceremony: an UNSIGNED v2 registry is not a trust root ==\n";
// ---------------------------------------------------------------------------

// THE FAILING-BEFORE STEP, and it is inside the ceremony rather than beside it.
// A populated root that carries no envelope signature is exactly what a
// hand-edited file looks like, and it is the state an operator reaches by
// pasting a record into the document. § v3.7 change (4) makes it unrepresentable:
// the reader refuses it, and `make release-gate` refuses to ship it.
$unsignedRegistry = (object) [
    'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
    'keys' => (object) [
        $platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider']),
    ],
];
pop_write($authoritiesPath, Canon::encode($unsignedRegistry));
$unsignedRefusal = (string) $refusal(static fn() => $resolve($platformId));
duo_check(
    str_contains($unsignedRefusal, 'must contain exactly format, keys, signature'),
    'an enrolled-but-unsigned v2 registry is refused by the shipped reader, through the closed envelope key set '
    . '— the signature is not an adornment a populated root may omit (' . $unsignedRefusal . ')'
);

// ---------------------------------------------------------------------------
echo "\n== step 2: the reviewer verb signs it, and the shipped reader accepts ==\n";
// ---------------------------------------------------------------------------

$signRegistry = pop_run([
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'authorities-sign',
    '--authorities=' . $authoritiesPath,
    '--authority=' . $platformId,
    '--secret-key-file=' . $platformSecretPath,
]);
duo_check(
    $signRegistry['exit'] === 0,
    'the reviewer verb `authorities-sign` signs the registry in place, through the shipped producer, which '
    . 'validates every record before the private key is touched (' . trim($signRegistry['stderr']) . ')'
);
$enrolledRaw = (string) file_get_contents($authoritiesPath);
$enrolledDocument = Canon::decode($enrolledRaw);
duo_check_same(
    $platformId,
    (string) ($enrolledDocument['signature']['key_id'] ?? ''),
    'and the envelope names the key inside the document as its signer — the registry attests to ITSELF, which is '
    . 'the whole of what a signed registry proves: nobody without that key can append a record, widen a scope, '
    . 'move a window or flip a status in it'
);
[$platformRecord, $resolvedPlatformId, $platformDigest, $platformRootWord] = $resolve($platformId);
duo_check_same($platformId, $resolvedPlatformId, 'the enrolled key now resolves through the shipped selector');
duo_check_same(
    AdapterCertification::TRUST_ROOT_PLATFORM,
    $platformRootWord,
    'under trust root `platform` — the root a reviewer owns, which is what enrollment moves'
);
duo_check_same(
    2,
    $platformRecord['record_version'] ?? null,
    'and the record it carries is a `record_version: 2` record: fingerprint-derived id, mandatory window, '
    . 'namespace pattern — the enrolled format, which an empty registry is deliberately unable to be'
);

// ---------------------------------------------------------------------------
echo "\n== step 3: the platform key delegates a scoped, expiring grant to a vendor ==\n";
// ---------------------------------------------------------------------------

$delegationStatement = [
    'adapter_names' => ['acme-shop', 'acme-invoices-*'],
    'delegate' => [
        'algorithm' => 'ed25519',
        'key_id' => $vendorId,
        'public_key' => $vendorKey['encoded'],
    ],
    'delegator' => [
        'fingerprint' => $platformKey['fingerprint'],
        'key_id' => $platformId,
        'trust_root' => AdapterCertification::TRUST_ROOT_PLATFORM,
    ],
    'format' => AdapterCertification::DELEGATION_FORMAT,
    'not_after' => '2027-01-01T00:00:00Z',
    'not_before' => '2026-02-01T00:00:00Z',
    'trust_tiers' => ['declarative_manifest'],
    'version' => 1,
];
$statementPath = $root . '/delegation-statement.json';
pop_write_canon($statementPath, $delegationStatement);
$signDelegation = pop_run([
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'delegation-sign',
    '--statement=' . $statementPath,
    '--authority=' . $platformId,
    '--secret-key-file=' . $platformSecretPath,
]);
duo_check(
    $signDelegation['exit'] === 0 && $signDelegation['stdout'] !== '',
    'the reviewer verb `delegation-sign` emits the installable {signature, statement} object ('
    . trim($signDelegation['stderr']) . ')'
);
$delegation = Canon::decode($signDelegation['stdout']);
$installDelegations = static function (array $delegations) use ($site): void {
    pop_write_canon($site . '/adapters/delegations.json', (object) [
        'delegations' => (object) $delegations,
        'format' => AdapterCertification::DELEGATIONS_FORMAT,
    ]);
};
$installDelegations([$vendorId => $delegation]);

[$vendorRecord, $resolvedVendorId, , $vendorRootWord] = $resolve($vendorId);
duo_check_same($vendorId, $resolvedVendorId, 'the site installs it, and the vendor key resolves at depth 1');
duo_check_same(
    AdapterCertification::TRUST_ROOT_SITE,
    $vendorRootWord,
    'under trust root `site`, not a third word: R-13\'s third value stays unspent, because a delegated key '
    . 'certifies ONE repository and that is what `site` already means inside the signed statement'
);
duo_check_same(
    ['acme-invoices-*', 'acme-shop'],
    (static function (array $names): array {
        sort($names, SORT_STRING);
        return $names;
    })((array) $vendorRecord['adapter_names']),
    'and the grant it carries is the delegation\'s, narrowed from the delegator\'s `acme-*` — never the '
    . 'delegator\'s own'
);

// ---------------------------------------------------------------------------
echo "\n== step 4: the vendor certifies an adapter in that one repository ==\n";
// ---------------------------------------------------------------------------

$adapterName = 'acme-shop';
$adapterManifest = [
    'name' => $adapterName,
    'option_autoload' => 'preserve',
    'options' => ['acme_shop_layout' => ['class' => 'authored']],
    'plugin' => 'acme-shop/acme-shop.php',
    'post_types' => [],
    'spec_version' => DUO_SPEC_VERSION,
    'version_range' => ['max' => '3.0.0', 'min' => '1.0.0'],
];
pop_write_canon($site . '/adapters/' . $adapterName . '.json', $adapterManifest);
pop_write_canon($site . '/site.duo.json', (object) [
    'manifests' => [['name' => $adapterName, 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]);

$signSite = static fn(string $authorityId, string $secretPath): array => pop_run([
    'env',
    'DUO_MANIFESTS_DIR=' . $library,
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'sign-site',
    '--manifest-dir=' . $library,
    '--repo=' . $site,
    '--name=' . $adapterName,
    '--authority=' . $authorityId,
    '--secret-key-file=' . $secretPath,
    '--reason=grammar verified by the enrolled vendor; not exercised',
]);
$vendorSign = $signSite($vendorId, $vendorSecretPath);
duo_check(
    $vendorSign['exit'] === 0,
    'the delegated vendor key certifies the adapter through `sign-site` (' . trim($vendorSign['stderr']) . ')'
);
$certificatePath = $site . '/adapters/certifications/' . $adapterName . '.json';
$vendorVerified = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
duo_check_same(
    'certified',
    $vendorVerified['claim']['status'] ?? null,
    'and the certificate it minted verifies live, under the enrolled root it chains to'
);
duo_check_same(
    AdapterCertification::TRUST_ROOT_SITE,
    $vendorVerified['disposition']['provenance']['proof']['authority']['trust_root'] ?? null,
    'and it is SITE-rooted, carrying the delegated key as its principal: the delegation is a documented '
    . 'provenance for a key inside the existing site root, never a new custody model'
);
duo_check_same(
    $vendorId,
    $vendorVerified['disposition']['provenance']['proof']['authority']['key_id'] ?? null,
    'with the vendor\'s own id inside the signature, which is what makes the typed revocation below able to name '
    . 'it on a path that holds no delegation document'
);

// ---------------------------------------------------------------------------
echo "\n== step 5: the word an operator actually reads, projected from both roots ==\n";
// ---------------------------------------------------------------------------

// Certification is an ELEVATION the repository pin gates
// (`AdapterSources::bind_explicit_pins()`), so the word is only reachable once
// the pin binds both `source: "site"` and the final certificate-derived digest.
// Computed in two passes for the reason `duo adapter certify --pin` does it in
// two: the digest folds the certificate in, so it cannot be known before the
// certificate exists.
putenv('DUO_MANIFESTS_DIR=' . $library);
$pinRepository = static function (string $name) use ($site): string {
    // The pin is reset to its bare form first: a stale digest refuses the whole
    // repository at load (`PinResolver::validate_manifest_pins()`), and the
    // digest folds the CERTIFICATE in, so re-minting one always invalidates the
    // pin that preceded it. That is rule 2 acting on a site adapter, and it is
    // why `duo adapter certify --pin` writes the pin rather than asking for it.
    $reset = Canon::decode(Canon::read_file($site . '/site.duo.json'));
    $reset['manifests'] = [['name' => $name, 'source' => 'site']];
    pop_write_canon($site . '/site.duo.json', $reset);
    $policy = Policy::load($site);
    $digest = '';
    foreach (RepositoryCompiler::resolved_adapters($policy) as $row) {
        if ((string) $row['name'] === $name) {
            $digest = (string) $row['digest'];
        }
    }
    $document = Canon::decode(Canon::read_file($site . '/site.duo.json'));
    $document['manifests'] = [['digest' => $digest, 'name' => $name, 'source' => 'site']];
    pop_write_canon($site . '/site.duo.json', $document);

    return $digest;
};
$surveyWord = static function (string $name) use ($site): ?string {
    foreach (AdapterSources::survey($site)['adapters'] as $row) {
        if (($row['name'] ?? null) === $name) {
            return $row['certification'] === null ? null : (string) $row['certification'];
        }
    }
    return null;
};
$pinRepository($adapterName);
duo_check_same(
    AdapterSources::CERTIFICATION_SITE_SIGNED,
    $surveyWord($adapterName),
    'an adapter certified under a DELEGATED vendor key projects `site_signed` — not `third_party_signed` — and '
    . 'that is the grammar working, not a gap: the delegate resolves under trust root `site`, so the word '
    . 'follows the ROOT that vouched rather than the party that holds the key'
);

// ---------------------------------------------------------------------------
echo "\n== step 6: `third_party_signed` — what the enrolled PLATFORM key itself mints ==\n";
// ---------------------------------------------------------------------------

// The other half of the enrollment payoff, and the reason G4 keeps a separate
// condition for it. `sign_site()` refuses a platform key BY NAME: an agent-owned
// key certifies a reviewed EXERCISE or nothing, so the third-party word cannot
// be reached by relaxing the operator path — it takes a bundle a conformance run
// produced. That is condition (7) ("a third party has produced an exercised
// bundle that verifies at distance"), which no fixture can satisfy.
$platformThroughSiteVerb = (string) $refusal(static function () use ($signSite, $platformId, $platformSecretPath): void {
    $result = $signSite($platformId, $platformSecretPath);
    if ($result['exit'] !== 0) {
        throw new RuntimeException($result['stderr']);
    }
});
duo_check(
    str_contains($platformThroughSiteVerb, "authority key '$platformId' is agent-owned")
        && str_contains($platformThroughSiteVerb, 'certifies a reviewed exercise'),
    'the enrolled platform key is refused BY NAME through the operator verb: enrollment does not make the cheap '
    . 'path reachable, it makes the reviewed path signable (' . trim($platformThroughSiteVerb) . ')'
);

$bundle = pop_write_bundle($root . '/bundle', $site, $adapterName, $adapterManifest);
$platformSign = pop_run([
    'env',
    'DUO_MANIFESTS_DIR=' . $library,
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $library,
    '--repo=' . $site,
    '--name=' . $adapterName,
    '--bundle=' . $bundle,
    '--evidence-repo=' . $site,
    '--authority=' . $platformId,
    '--secret-key-file=' . $platformSecretPath,
]);
duo_check(
    $platformSign['exit'] === 0,
    'the enrolled platform key certifies the same adapter from a reviewed-exercise bundle ('
    . trim($platformSign['stderr']) . ')'
);
$platformVerified = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
$platformEnvelope = $platformVerified['envelope'];
$platformRecordDigest = (string) ($platformVerified['disposition']['provenance']['proof']['authority']['record_sha256'] ?? '');
duo_check_same(
    AdapterCertification::TRUST_ROOT_PLATFORM,
    $platformVerified['disposition']['provenance']['proof']['authority']['trust_root'] ?? null,
    'and THAT certificate is platform-rooted — the one certificate shape an enrolled trust root exists to make '
    . 'possible'
);
$pinRepository($adapterName);
duo_check_same(
    'third_party_signed',
    $surveyWord($adapterName),
    'so the operator-facing word becomes `third_party_signed`: the enrollment ceremony\'s actual product, and '
    . 'the exact word gate G4 decides whether any stranger may ever cause to appear'
);
duo_check_same(
    $platformDigest,
    $platformRecordDigest,
    'the certificate pins the enrolled record\'s own canonical digest — the value the second-enrollment '
    . 'invariant below must leave unmoved'
);

// ---------------------------------------------------------------------------
echo "\n== step 7: THE SECOND-ENROLLMENT INVARIANT — the file grows, nothing breaks ==\n";
// ---------------------------------------------------------------------------

// WP-4.8 / § v3.7 change (5), and G4's condition (2). The platform root used to
// bind the WHOLE authority record, justified by a premise enrollment falsifies:
// that the shipped file never grows under an operator's hand. It grows once per
// enrolled vendor. This is the case a fixture that only grows ONE key's record
// cannot reach — the ENVELOPE signature covers `{format, keys}`, so admitting an
// unrelated second vendor re-signs the entire document, and every certificate
// already issued has to survive that.
$enrollSecond = static function () use (
    $authoritiesPath,
    $enrolledDocument,
    $platformId,
    $secondVendorId,
    $secondVendorKey,
    $v2Record,
    $repo,
    $platformSecretPath
): array {
    $keys = (array) $enrolledDocument['keys'];
    $keys[$secondVendorId] = $v2Record($secondVendorKey['encoded'], ['zeta-*'], ['declarative_manifest']);
    // A REAL enrollment event does three things to the enrolling root's own
    // record, not one, and all three are here because all three are what the
    // pre-WP-4.8 whole-record binding invalidated a live certificate over: the
    // namespace grows to reach the new vendor, a tier is added, and the window
    // is RENEWED. `authorityIdentity()` drops exactly `adapter_names`,
    // `trust_tiers`, `not_after` and `not_before` (`:3523-3528`) — so if any of
    // the four were inside the binding, the certificate below would refuse.
    $keys[$platformId]['adapter_names'] = ['acme-*', 'zeta-*'];
    $keys[$platformId]['trust_tiers'] = ['declarative_manifest', 'native_action', 'plugin_provider'];
    $keys[$platformId]['not_after'] = '2100-01-01T00:00:00Z';
    ksort($keys, SORT_STRING);
    pop_write_canon($authoritiesPath, (object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
        'keys' => (object) $keys,
    ]);

    return pop_run([
        PHP_BINARY,
        $repo . '/scripts/adapter-certification.php',
        'authorities-sign',
        '--authorities=' . $authoritiesPath,
        '--authority=' . $platformId,
        '--secret-key-file=' . $platformSecretPath,
    ]);
};
$secondEnrollment = $enrollSecond();
duo_check(
    $secondEnrollment['exit'] === 0,
    'a SECOND, UNRELATED vendor is enrolled and the registry re-signed (' . trim($secondEnrollment['stderr']) . ')'
);
$grownDocument = Canon::decode((string) file_get_contents($authoritiesPath));
duo_check(
    (string) $grownDocument['signature']['value'] !== (string) $enrolledDocument['signature']['value']
        && count((array) $grownDocument['keys']) === 2,
    'and the ENVELOPE signature moved with it: the signature covers `{format, keys}`, so admitting one vendor '
    . 're-signs the whole document — together with the enrolling key\'s own widened namespace, added tier and '
    . 'renewed window, this is every byte a real enrollment event moves'
);
// Caught rather than called bare, and the reason is the failing-before proof
// itself: with the pre-WP-4.8 whole-record binding restored, this call does not
// return a different verdict — it THROWS "certification authority/key/
// fingerprint/trust root does not match the current platform authority record".
// An uncaught call would end the suite with a stack trace instead of a named
// failure, so the one assertion a reviewer most needs to read is the one that
// would never print.
$grown = ['claim' => ['status' => 'unread']];
try {
    $grown = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
} catch (Throwable $t) {
    $grown = ['claim' => ['status' => 'refused: ' . $t->getMessage()]];
}
duo_check_same(
    'certified',
    $grown['claim']['status'] ?? null,
    'THE INVARIANT: the certificate issued under the FIRST key still verifies after the second enrollment — '
    . 'enrollment cadence is independent of every already-certified site, which is the property that makes '
    . 'admitting a vendor a data decision rather than a fleet event'
);
duo_check_same(
    $platformRecordDigest,
    (string) ($grown['disposition']['provenance']['proof']['authority']['record_sha256'] ?? ''),
    'and the pinned authority digest is still the record the certificate was SIGNED over, so no repository pin '
    . 'moves when the trust root grows'
);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, $adapterName, $adapterManifest, $platformEnvelope)['claim']['status'] ?? null,
    'the FROZEN path answers the same way: a promoted site holding this snapshot is untouched by an enrollment '
    . 'it never saw'
);
duo_check_same(
    $vendorId,
    $resolve($vendorId)[1],
    'and the first vendor\'s delegation still resolves THROUGH the widened delegator: a delegation is judged '
    . 'live against the delegator\'s current record, so a root that grows to reach a second vendor carries its '
    . 'first vendor\'s grant across unchanged'
);

// The narrowing removed the two scope lists and NOTHING else. Asserted here
// rather than assumed, because "growth is safe" and "edits are unnoticed" are
// one line apart: a moved `status` on the SIGNING key is still an identity move.
$identityKeys = (array) $grownDocument['keys'];
$identityKeys[$platformId]['status'] = 'revoked';
pop_write_canon($authoritiesPath, (object) [
    'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
    'keys' => (object) $identityKeys,
]);
pop_run([
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'authorities-sign',
    '--authorities=' . $authoritiesPath,
    '--authority=' . $secondVendorId,
    '--secret-key-file=' . $secretFile($root . '/second-vendor.key', $secondVendorKey['secret']),
]);
$identityMoved = (string) $refusal(
    static fn() => AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath)
);
duo_check(
    str_contains($identityMoved, "site adapter '$adapterName' certification authority/key/fingerprint/trust root")
        && str_contains($identityMoved, 'does not match the current platform authority record'),
    'while flipping that key\'s `status` in the same file still stops it at once, and it is the IDENTITY '
    . 'BINDING that answers rather than the revocation seat: `authorityIdentity()` drops exactly '
    . '`adapter_names`, `trust_tiers`, `not_after` and `not_before` and NOTHING else, so scope growth and a '
    . 'window renewal are invisible to an issued certificate while `status`, `public_key` and `record_version` '
    . 'are not (' . $identityMoved . ')'
);
$enrollSecond();

// ---------------------------------------------------------------------------
echo "\n== step 8: the refusal matrix a delegated grant must have ==\n";
// ---------------------------------------------------------------------------

// Back to the DELEGATED certificate, because these three refusals are about the
// grant rather than about the root. Re-minted rather than restored from bytes:
// `sign-site` is the path an operator runs, and a fixture that replayed a saved
// certificate would stop proving the producer still agrees with the verifier.
$vendorSign = $signSite($vendorId, $vendorSecretPath);
duo_check($vendorSign['exit'] === 0, 're-minted under the delegated vendor key (' . trim($vendorSign['stderr']) . ')');
$vendorVerified = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
$vendorEnvelope = $vendorVerified['envelope'];
$pinRepository($adapterName);
duo_check_same(
    AdapterSources::CERTIFICATION_SITE_SIGNED,
    $surveyWord($adapterName),
    'and the repository is pinned to it again — the control every refusal below is measured against'
);

// (a) A NAME OUTSIDE THE GRANT. The delegator holds `acme-*`; the delegate was
// given `acme-shop` and `acme-invoices-*` and nothing more.
$outsideNamespace = (string) $refusal(
    static fn() => $scope->invoke(null, $vendorRecord, $vendorId, 'acme-catalog', 'declarative_manifest')
);
duo_check(
    str_contains($outsideNamespace, "is not scoped to site adapter 'acme-catalog'"),
    'a name inside the DELEGATOR\'s namespace but outside the DELEGATE\'s grant refuses by name: the grant is '
    . 'the delegate\'s scope, never the delegator\'s (' . $outsideNamespace . ')'
);
pop_write_canon($site . '/adapters/acme-catalog.json', ['name' => 'acme-catalog'] + $adapterManifest);
$outsideEndToEnd = pop_run([
    'env',
    'DUO_MANIFESTS_DIR=' . $library,
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'sign-site',
    '--manifest-dir=' . $library,
    '--repo=' . $site,
    '--name=acme-catalog',
    '--authority=' . $vendorId,
    '--secret-key-file=' . $vendorSecretPath,
    '--reason=grammar verified by the enrolled vendor; not exercised',
]);
duo_check(
    $outsideEndToEnd['exit'] !== 0
        && str_contains($outsideEndToEnd['stderr'], "is not scoped to site adapter 'acme-catalog'"),
    'and the same refusal fires end to end, before a private key is touched — a vendor cannot certify outside '
    . 'its namespace even in its own repository'
);
unlink($site . '/adapters/acme-catalog.json');

// (b) THE DELEGATION EXPIRES. Time is a scope like any other, judged in the same
// seat as revocation, against this host's own wall clock with no skew allowance.
$setClock('2027-01-01T00:00:00Z');
$expiredTyped = null;
try {
    AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
} catch (Throwable $t) {
    $expiredTyped = $t;
}
duo_check(
    $expiredTyped instanceof \Duo\WithdrawnAuthoritySiteAdapterCertificate
        && str_contains($expiredTyped->getMessage(), "authority key '$vendorId' expired at 2027-01-01T00:00:00Z"),
    'an EXPIRED delegation withdraws the adapter through the TYPED signal — not a whole-source refusal: a grant '
    . 'that lapsed on schedule must not brick a site (' . get_class($expiredTyped ?? new RuntimeException('none'))
    . ': ' . ($expiredTyped?->getMessage() ?? 'no refusal') . ')'
);
duo_check_same(
    'uncertified',
    $surveyWord($adapterName),
    'and the operator-facing word falls to `uncertified`: exactly as unvouched-for as an adapter nobody ever '
    . 'signed, which is what an expired grant means'
);
$setClock('2026-06-01T00:00:00Z');
duo_check_same(
    AdapterSources::CERTIFICATION_SITE_SIGNED,
    $surveyWord($adapterName),
    'moving the clock back restores it — the window is read live on every resolution, never cached into the '
    . 'certificate'
);

// (c) THE DELEGATOR IS REVOKED, through the typed out-of-band channel rather
// than through an agent release.
$installRevocations = static function (array $entries, string $signerId, string $signerSecret) use (
    $revocationsPath
): void {
    if ($entries === []) {
        if (is_file($revocationsPath)) {
            unlink($revocationsPath);
        }
        return;
    }
    pop_write($revocationsPath, AdapterCertification::signRevocations(
        Canon::encode((object) [
            'format' => AdapterCertification::REVOCATION_FORMAT,
            'issued_at' => '2026-05-01T00:00:00Z',
            'revocations' => $entries,
            'version' => 1,
        ]),
        $signerId,
        base64_encode($signerSecret)
    ));
};
$revocationEntry = static fn(string $keyId, string $fingerprint, string $reason): array => [
    'effective_at' => '2026-05-01T00:00:00Z',
    'fingerprint' => $fingerprint,
    'key_id' => $keyId,
    'reason' => $reason,
];
$installRevocations(
    [$revocationEntry($platformId, $platformKey['fingerprint'], 'platform enrollment key rotated out of service')],
    $secondVendorId,
    $secondVendorKey['secret']
);
$revokedDelegator = (string) $refusal(
    static fn() => AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath)
);
duo_check(
    str_contains($revokedDelegator, "authority key '$platformId' is revoked by the platform-signed revocation record"),
    'revoking the DELEGATOR through the typed channel invalidates its delegate on the live path at once, with '
    . 'no agent release and no site file touched (' . $revokedDelegator . ')'
);
duo_check_same(
    'certified',
    AdapterCertification::verifyFrozen($library, $adapterName, $adapterManifest, $vendorEnvelope)['claim']['status'] ?? null,
    'while the FROZEN path is unmoved by the delegator\'s revocation alone — a snapshot holds no delegation '
    . 'document, so an incident response that must reach PROMOTED sites names the DELEGATE\'s own fingerprint. '
    . 'That is what the drill below does'
);
$installRevocations([], $secondVendorId, $secondVendorKey['secret']);

$setClock(null);
putenv('DUO_MANIFESTS_DIR');

// ---------------------------------------------------------------------------
echo "\n== step 9: THE REVOCATION DRILL on WP-1.4's rehearsal fleet ==\n";
// ---------------------------------------------------------------------------

// G4's condition (3) is not "the channel exists" — that is WP-4.9, and
// `regress_revocation_reachability.php` proves it. It is "a revocation has been
// executed end to end on WP-1.4's fleet with propagation latency MEASURED,
// including the frozen-path vendor-key case". So the estate is built by its own
// driver, unchanged, and the drill runs against it in a child process at the
// estate's own state — a state is a pair of define()s and this process already
// holds the tree's.
$estate = sys_get_temp_dir() . '/duo-revocation-drill-estate-' . bin2hex(random_bytes(6));
register_shutdown_function(static fn() => pop_remove_tree($estate));
$estateDriver = __DIR__ . '/../guards/spec_migration_estate.php';
$materialize = pop_run([PHP_BINARY, $estateDriver, $estate, 'A', 'materialize']);
$observe = pop_run([PHP_BINARY, $estateDriver, $estate, 'A', 'observe']);
$observed = json_decode($observe['stdout'], true);
if ($materialize['exit'] !== 0 || $observe['exit'] !== 0 || !is_array($observed)) {
    // A driver that cannot build the estate is a broken instrument, not a
    // failing assertion: report its own words and stop rather than asserting
    // over an empty document (`regress_spec_migration_rehearsal.php:337-346`).
    fwrite(STDERR, "FAIL: the rehearsal estate driver could not build the drill fleet\n"
        . $materialize['stderr'] . $observe['stderr'] . "\n");
    exit(1);
}
$drill = pop_run([
    PHP_BINARY,
    __DIR__ . '/revocation_drill.php',
    $estate,
    (string) $observed['agent_version'],
    (string) $observed['spec_version'],
]);
$drilled = json_decode($drill['stdout'], true);
if ($drill['exit'] !== 0 || !is_array($drilled)) {
    fwrite(STDERR, "FAIL: the revocation drill exited {$drill['exit']}\n" . $drill['stderr'] . "\n");
    exit(1);
}

duo_check_same(
    ['format' => AdapterCertification::AUTHORITIES_FORMAT, 'keys' => []],
    (array) $drilled['shipped_root'],
    'the fleet\'s own manifest libraries carry the EMPTY v1 root, exactly like every agent in the field — the '
    . 'drill enrolls into a copy, and the copy is what it burns a key in'
);
duo_check(
    $drilled['revocation_document_ships'] === false,
    'and none of them ships a revocation document, which is why the drill has to install one'
);
duo_check(
    str_contains(
        (string) $drilled['inert_before_enrollment']['message'],
        'is not installed in capabilities/adapter-authorities.json'
    )
    && $drilled['inert_before_enrollment']['certified_alpha_still_verifies'] === 'certified',
    'THE DRILL\'S FIRST FINDING, and it is an ordering one: BEFORE enrollment a correctly-signed revocation is '
    . 'INERT — reported, never obeyed, and the site keeps working. Revocation capability is something enrollment '
    . 'BUYS; an agent with an empty root has none, which is why G4 orders (3) before admission'
);
foreach (['certified-alpha', 'certified-beta', 'promoted-frozen'] as $id) {
    duo_check_same(
        'certified',
        (string) $drilled['before'][$id]['verdict'],
        "control: $id verifies before the burn — every measurement below is against this"
    );
}

$alpha = (array) $drilled['after']['certified-alpha'];
$frozen = (array) $drilled['after']['promoted-frozen'];
$beta = (array) $drilled['after']['certified-beta'];
duo_check(
    $alpha['verdict'] === 'withdrawn'
        && str_contains((string) $alpha['reason'], "authority key 'site-key-alpha' is revoked by the platform-signed revocation record"),
    'THE LIVE PATH: a site certified under the burnt key withdraws its claim, naming the key, the instant and '
    . 'the reason (' . substr((string) $alpha['reason'], 0, 140) . ')'
);
duo_check(
    $frozen['verdict'] === 'withdrawn'
        && str_contains((string) $frozen['reason'], "authority key 'site-key-alpha' is revoked by the platform-signed revocation record"),
    'THE FROZEN PATH — the case the channel exists for: a PROMOTED site verifying from the snapshot it holds '
    . 'reopens no mutable site file, so the operator\'s own authorities.json can never reach it. The '
    . 'agent-owned document does'
);
duo_check(
    str_contains((string) $frozen['reason'], 'This channel reaches the frozen path, which a status flip in the operator\'s own adapters/authorities.json deliberately does not'),
    'and the refusal STATES the distinction, so an operator reading a refused snapshot can tell which of the two '
    . 'revocation mechanisms answered without reading the source'
);
duo_check_same(
    'certified',
    (string) $beta['verdict'],
    'BLAST RADIUS: the site certified under the OTHER operator key is untouched — one burnt fingerprint burns '
    . 'one identity, not a fleet'
);
foreach (['certified-alpha', 'promoted-frozen'] as $id) {
    duo_check(
        str_starts_with((string) $drilled['after'][$id]['load'], 'loaded ')
            && (string) $drilled['after'][$id]['word'] === 'uncertified',
        "and $id still LOADS with its claim withdrawn to `uncertified`: a revocation takes away the CLAIM, not "
        . 'the site (§ v3.8, G2-FIXES C3). Untyped, revoking a key to protect the fleet bricked every promoted '
        . 'site holding a certificate under it, which is the shape that makes an operator hesitate to revoke — '
        . 'the one hesitation an incident cannot afford ('
        . (string) $drilled['after'][$id]['load'] . ')'
    );
}

// THE EXPENSIVE HALF, MEASURED RATHER THAN ASSUMED. "The site is not bricked"
// is not the same claim as "the incident is free", and the drill exists to find
// out which. It is not free: the withdrawn adapter's digest moves, which carries
// the site's `manifest_hash`, which is what a HELD COMPILED ARTIFACT binds
// (AGENTS.md rule 2). So a revocation hands every affected site the flag day's
// own step 6 — recompile — in the middle of a security incident, and the
// compromise ceremony has to say so.
duo_check(
    (string) $drilled['after']['certified-alpha']['digest'] !== (string) $drilled['before']['certified-alpha']['digest']
        && (string) $drilled['after']['certified-alpha']['manifest_hash']
            !== (string) $drilled['before']['certified-alpha']['manifest_hash'],
    'the withdrawn adapter\'s DIGEST moves, and the site\'s `manifest_hash` with it — the certificate is folded '
    . 'into the row `manifest_rows()` hashes, so removing it is a content change by rule 2\'s own definition'
);
duo_check_same(
    'compiled_artifact_manifest_mismatch',
    (string) $drilled['after']['certified-alpha']['artifact'],
    'so the compiled artifact the site was HOLDING refuses by name — the incident\'s real cost, and the reason '
    . 'the compromise ceremony ends with a recompile step rather than with the revocation itself'
);
duo_check(
    str_starts_with((string) $drilled['after']['certified-alpha']['load'], 'loaded '),
    'while the repository still LOADS on a stale content pin, because a `source: "site"` pin on an adapter that '
    . 'is no longer certified is the documented edit-then-uncertified concession '
    . '(`PinResolver::validate_manifest_pins()`) — without it the remedy command could not run on the site that '
    . 'needs it'
);
duo_check_same(
    (string) $drilled['before']['certified-beta']['manifest_hash'],
    (string) $drilled['after']['certified-beta']['manifest_hash'],
    'and the site under the OTHER key does not move one identity byte: the blast radius is the fingerprint, not '
    . 'the fleet'
);
foreach (['certified-alpha', 'certified-beta', 'promoted-frozen'] as $id) {
    duo_check_same(
        'certified',
        (string) $drilled['restored'][$id],
        "stand-down: removing the document restores $id — ABSENCE means \"nothing is revoked\", which is the "
        . 'shipped state of every site and the reason this channel costs nothing until it is used'
    );
}

// THE MEASUREMENT, printed on every run rather than pinned to a number: the
// milliseconds are this machine's, and asserting one would be asserting the
// hardware. What IS asserted is the SHAPE — the whole fleet is reached inside
// one second of agent-side work, and no site needed an agent release, a
// restart or a second document.
duo_check_detail(sprintf(
    'measured propagation, agent side (this host, this run): document install %.3f ms',
    (float) $drilled['install_ms']
));
foreach (['certified-alpha', 'certified-beta', 'promoted-frozen'] as $id) {
    $row = (array) $drilled['after'][$id];
    duo_check_detail(sprintf(
        '  %-16s %-6s verify %8.3f ms   landed->effect %8.3f ms   verdict %s',
        $id,
        (string) $row['path'],
        (float) $row['elapsed_ms'],
        (float) $row['landed_to_effect_ms'],
        (string) $row['verdict']
    ));
}
duo_check(
    (float) $drilled['fleet_reached_ms'] < 1000.0 && (float) $drilled['install_ms'] < 1000.0,
    sprintf(
        'the whole fleet — live and frozen — is reached in %.3f ms of agent-side work, with no agent release, no '
        . 'restart and no cache to expire. The bound is a loose order-of-magnitude one on purpose: the '
        . 'milliseconds are this host\'s and pinning one would be pinning the hardware. The COURIER interval is '
        . 'not measured here and is not claimed — how long a cron, a configuration run or an incident paste '
        . 'takes to land the document is an operator SLO this engine cannot observe',
        (float) $drilled['fleet_reached_ms']
    )
);

// ---------------------------------------------------------------------------
echo "\n== step 10: the ceremony is WRITTEN DOWN, and the gate it serves is enumerated ==\n";
// ---------------------------------------------------------------------------

// G4's condition (5) is a DOCUMENT — "a written enrollment vetting posture and a
// platform-root rotation/compromise ceremony" — so the suite that is the
// evidence for the other conditions checks that the document exists and still
// enumerates the gate. Deliberately structural rather than a prose comparison:
// what must not rot is that every condition has a row and that the unmet ones
// are still named as unmet, because a checklist whose failing rows quietly
// disappear is worse than no checklist.
$guide = $repo . '/docs/guides/trust-enrollment.md';
$guideText = is_file($guide) ? (string) file_get_contents($guide) : '';
duo_check(
    $guideText !== '',
    'docs/guides/trust-enrollment.md exists — G4 condition (5) is a written deliverable, and this suite is the '
    . 'evidence the rest of that page cites'
);
foreach (['The vetting posture', 'The rotation ceremony', 'The compromise ceremony', 'The G4 checklist'] as $section) {
    duo_check(
        str_contains($guideText, '## ' . $section),
        "and it still carries the section '$section'"
    );
}
$enumerated = 0;
for ($condition = 1; $condition <= 8; $condition++) {
    if (str_contains($guideText, '| ' . $condition . ' | ')) {
        $enumerated++;
    }
}
duo_check_same(
    8,
    $enumerated,
    'the checklist enumerates all EIGHT G4 conditions: the gate opens on all of them or on none, so a table '
    . 'that lost a row would read as a gate that got easier'
);
duo_check(
    substr_count($guideText, '**NOT MET') >= 2
        && str_contains($guideText, 'not meetable here'),
    'and it still records the conditions that are NOT met — including the one no fixture in this repository can '
    . 'ever satisfy, because a third party actually producing an exercised bundle is exactly the thing a '
    . 'rehearsal cannot stand in for'
);
duo_check(
    str_contains($guideText, 'regress_platform_authority_population.php')
        && str_contains($guideText, 'revocation_drill.php'),
    'and it cites this suite and its drill by name, so renaming either without re-reading the page fails here '
    . 'rather than leaving the evidence column pointing at nothing'
);

duo_check_summary('platform authority population');
