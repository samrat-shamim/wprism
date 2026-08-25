<?php
/**
 * WP-5.2 — the REVIEWER TIER: federating the `exercised` leg of a claim
 * (spec/repo-format.md § v3.16; gate G4).
 *
 * WHAT THIS RIDER ACTUALLY CHANGED, AND WHAT IT DELIBERATELY DID NOT
 * ------------------------------------------------------------------
 * Everything a reviewer-tier certificate needs was already shipped and is NOT
 * this rider's work: `sign()` has always taken an `$evidenceRepo` separate from
 * the site repository (`AdapterCertification::sign()`), the bundle has always
 * been a content-addressed, git-revision-bound record whose assets are opened
 * and digest-checked at import (`verifyBundleAssets()`), and
 * `verifyRatification()` has always refused a disposition that CITES a test the
 * bundle does not carry as passing — "cites absent or non-passing bundle test".
 * What was missing was two words: admission of `evidence.reviewer`, and a
 * vocabulary that could say a named party exercised it.
 *
 * So the flip is small and the evidence has to be about the whole path anyway,
 * because the claim being made is a TRUST claim: that a bundle produced
 * somewhere this repository cannot see, over tests this repository does not
 * have, still verifies here and still refuses for every reason it refused
 * before. This suite drives that end to end through the shipped verbs.
 *
 * THE SEVEN THINGS PROVED, IN ORDER
 * ---------------------------------
 *  1. THE FOREIGN EVIDENCE REPOSITORY IS FOREIGN. The cited tests exist in a
 *     scratch checkout that is not duo-wp and not the site repo; this
 *     repository holds no file, no conformance suite and no Makefile target by
 *     those names, and that is ASSERTED rather than asserted-in-prose.
 *  2. THE MINT AND THE WORD. `sign --evidence-repo=<foreign>` produces a
 *     certificate whose signed bundle names a reviewing party, and the pinned
 *     repository projects `reviewer_signed`.
 *  3. AT DISTANCE. The foreign evidence repository is then DELETED and the
 *     certificate re-verified from the site alone. Runtime verification reopens
 *     no evidence checkout, so the proof travels inside the signature — which
 *     is the property that makes a third-party exercise usable at all.
 *  4. THE WORD NEVER COLLAPSES. The same estate mints all three signed words —
 *     `site_signed` (operator's own root), `third_party_signed` (platform root,
 *     exercised, no reviewer) and `reviewer_signed` — and they are three
 *     distinct strings from three distinct facts. The reviewer word is refused
 *     to a bundle that names no exercise, to one whose reviewer IS the signer,
 *     and to one whose reviewer is not an identity.
 *  5. THE CITED-TEST RULE IS UNRELAXED, in all three of its arms: a cited test
 *     absent from the bundle refuses; one present but recorded `fail` in the
 *     manifest refuses; one present and passing in the manifest whose RESULT
 *     asset records a non-zero exit refuses. A federated tier that admitted any
 *     of these would be laundering, not federating.
 *  6. THE SIX EXISTING WORDS DID NOT MOVE — same meanings, same precedence,
 *     same closed vocabularies on both sides of the wire, and the ladder still
 *     answers `certification_unjudged`/`uncertified`/`signed_unpinned` before it
 *     ever asks who exercised anything.
 *  7. NO CERTIFICATE IN THE FIELD MOVED. A bundle that declares no reviewer
 *     produces a `proof.bundle` with EXACTLY the seven members it had before
 *     this rider, which is what keeps the adapter digest — and therefore every
 *     `site.duo.json` pin binding it — where it was (AGENTS.md rule 2).
 *
 * FAILING BEFORE: revert the admission in `AdapterCertification::bundleEvidence()`
 * (restore the `array_key_exists(EVIDENCE_REVIEWER, …)` refusal) and step 2's
 * mint refuses with "the reviewer evidence member is reserved"; restore the
 * root-only ternary in `AdapterSources::site_certification()` and step 2's word
 * collapses to `third_party_signed`, which is step 4's whole subject.
 *
 * NO REAL REVIEWER WAS ENROLLED. `acme-*` is a fixture namespace and every key
 * is a deterministic seed checked into this file; the shipped trust root is
 * still `{"keys":{}}` and this suite re-asserts that on every run. Gate G4's
 * condition 7 — a third party ACTUALLY doing this — is not satisfiable by any
 * fixture and this one does not pretend to (docs/guides/trust-enrollment.md).
 *
 * @see sandbox/tests/offline/adapter/regress_platform_authority_population.php
 *      — the enrollment ceremony this suite reuses, and the two root-derived
 *      words it measures.
 * @see sandbox/tests/offline/adapter/regress_v3_reservations.php — the
 *      reservation this rider flipped, and the pins that still refuse.
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

// The WordPress seams a policy load can touch. They REFUSE rather than answer,
// exactly as the population suite's do: a suite that reached a target would stop
// being offline, and a silent stub would hide the reach.
if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        throw new RuntimeException("reviewer-tier fixture: target contact get_option($name)");
    }
}
if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(...$args): array {
        throw new RuntimeException('reviewer-tier fixture: target contact wp_upload_dir');
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
    fwrite(STDERR, "FAIL: the PHP sodium extension is required for the reviewer tier\n");
    exit(1);
}

$repo = dirname(__DIR__, 4);

function rev_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        rev_remove_tree($item->getPathname());
    }
    rmdir($path);
}

function rev_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        throw new RuntimeException("reviewer-tier fixture: cannot create $to");
    }
    foreach (new FilesystemIterator($from) as $item) {
        $target = $to . '/' . $item->getBasename();
        if ($item->isDir() && !$item->isLink()) {
            rev_copy_tree($item->getPathname(), $target);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('reviewer-tier fixture: cannot copy ' . $item->getPathname());
        }
    }
}

function rev_write(string $path, string $bytes): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("reviewer-tier fixture: cannot create $dir");
    }
    if (file_put_contents($path, $bytes) === false) {
        throw new RuntimeException("reviewer-tier fixture: cannot write $path");
    }
}

/** @param mixed $value */
function rev_write_canon(string $path, $value): void {
    rev_write($path, Canon::encode($value));
}

/** @return array{exit:int,stdout:string,stderr:string} */
function rev_run(array $command): array {
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

/**
 * The FOREIGN reviewed-exercise bundle, written into an evidence repository
 * that is neither duo-wp nor the managed site.
 *
 * Suite-local for the reason `sign_site()` states about its own internal
 * builder: the bundle grammar belongs to the engine (`verifyBundleManifest()`
 * refuses any deviation by exact key set) and the reviewed-exercise producer is
 * DELIBERATELY external — it is the output of a real conformance run this
 * repository has no business performing. Every mutation this suite needs is an
 * override rather than a second builder, so each refusal below differs from the
 * accepted bundle in exactly the one member it is about.
 *
 * @param array<string,mixed> $overrides `evidence`, `tests`, `result_exit`, `cited`
 */
function rev_write_bundle(
    string $dir,
    string $evidenceRoot,
    string $name,
    array $manifest,
    array $overrides = []
): string {
    rev_remove_tree($dir);
    $pretty = static fn($value): string => json_encode(
        Canon::normalize($value),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
    $descriptor = static function (string $path, string $relative): array {
        $contents = (string) file_get_contents($path);
        return ['path' => $relative, 'sha256' => hash('sha256', $contents), 'size' => strlen($contents)];
    };

    // The test the DISPOSITION cites. It is named once here and reused, so the
    // "cited but absent" case below is a change to one array and not to a
    // string somebody has to keep in two places.
    $cited = (array) ($overrides['cited'] ?? [REVIEWER_TIER_TEST_ID]);
    $carried = (array) ($overrides['tests'] ?? [['id' => REVIEWER_TIER_TEST_ID, 'verdict' => 'pass']]);

    // Inside the shipped boundary's own exercised series, because the library
    // this signs against IS the shipped one: 8.3 -> 8.3.33 and 7.0 -> 7.0.3 are
    // the exact patches manifests/capabilities/platform.json records a live
    // proof ran on.
    $environment = ['multisite' => false, 'php' => '8.3.33', 'wordpress' => '7.0.3'];
    rev_write_canon($dir . '/environment.json', $environment);
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
                    'tests' => $cited,
                ],
                'reason' => 'An independent conformance lab exercised this exact declarative adapter and '
                    . 'published the run; the platform root vouches for the lab, not for the run it did not make.',
                'status' => 'certified',
                // `ManifestDispositions::validate_entry()` refuses a certified
                // entry whose versions disagree with the manifest contract, so
                // this is the manifest's own `plugin` and `version_range`
                // verbatim.
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
    rev_write_canon($dir . '/ratification.json', $ratification);

    $tests = [];
    foreach ($carried as $row) {
        $id = (string) $row['id'];
        rev_write($dir . '/results/' . $id . '.json', $pretty([
            // The RESULT asset is a separate fact from the manifest row's
            // verdict, and `verifyBundleAssets()` reads both: a manifest that
            // says `pass` over a result recording a non-zero exit is the
            // sharpest "present but failing" case there is.
            'exit_code' => (int) ($overrides['result_exit'] ?? 0),
            'schema_version' => 1,
            'test' => $id,
            'verdict' => ((int) ($overrides['result_exit'] ?? 0)) === 0 ? 'pass' : 'fail',
        ]));
        rev_write_canon($dir . '/diffs/' . $id . '.json', ['changed' => [], 'status' => 'clean']);
        rev_write($dir . '/logs/' . $id . '.txt', "$id exercised by an independent lab\n");
        $tests[] = [
            'diff' => $descriptor($dir . '/diffs/' . $id . '.json', 'diffs/' . $id . '.json'),
            'id' => $id,
            'log' => $descriptor($dir . '/logs/' . $id . '.txt', 'logs/' . $id . '.txt'),
            'result' => $descriptor($dir . '/results/' . $id . '.json', 'results/' . $id . '.json'),
            'verdict' => (string) $row['verdict'],
        ];
    }

    $bundle = [
        'artifacts' => [[
            'name' => 'acme-shop',
            'role' => 'certified-boundary',
            'sha256' => str_repeat('c', 64),
            'url' => 'https://example.invalid/acme-shop-2.4.1.zip',
            'version' => '2.4.1',
        ]],
        // Resolved against the EVIDENCE root, not the site: this is the one
        // place the foreign checkout is read, and it is read at mint time only.
        'bound_inputs' => [$descriptor($evidenceRoot . '/adapters/' . $name . '.json', 'adapters/' . $name . '.json')],
        'created_at' => '2026-08-20T00:00:00Z',
        'environment' => $descriptor($dir . '/environment.json', 'environment.json'),
        'environment_summary' => $environment,
        'evidence' => (array) ($overrides['evidence'] ?? [
            'exercised' => true,
            'grammar' => 'ok',
            'reason' => 'an independent conformance lab exercised this adapter against a live target',
            'reviewer' => REVIEWER_TIER_PARTY,
        ]),
        'force_hatches' => [],
        // The FOREIGN repository's own revision, deliberately not duo-wp's
        // HEAD: a bundle that borrowed this repository's commit would be
        // claiming provenance it does not have.
        'git_revision' => str_repeat('7e', 20),
        'harness' => ['name' => 'acme-conformance-lab-harness', 'version' => 3],
        'ratification' => $descriptor($dir . '/ratification.json', 'ratification.json'),
        'ratification_summary' => [
            'certified_claims' => ['manifests.' . $name],
            'manifest_count' => 1,
            'profile_count' => 0,
        ],
        'schema_version' => AdapterCertification::BUNDLE_FORMAT,
        'subject' => ['kind' => 'site_adapter', 'name' => $name],
        'tests' => $tests,
        'verdict' => 'pass',
    ];
    $digestInput = $bundle;
    unset($digestInput['bundle_digest']);
    $bundle['bundle_digest'] = hash('sha256', json_encode(
        Canon::normalize($digestInput),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n");
    rev_write($dir . '/bundle.json', $pretty($bundle));

    return $dir;
}

/**
 * The cited test and the reviewing party, named once.
 *
 * Both are deliberately spelled so that step 1 can prove this repository has
 * neither: a test id that collided with a real suite here would make the
 * at-distance claim untestable, quietly.
 */
const REVIEWER_TIER_TEST_ID = 'acme-lab-federated-conformance';
const REVIEWER_TIER_PARTY = 'acme-conformance-lab';

// Rule 3: scratch never lives under agent/ or manifests/ — sandbox/bin/pair.sh
// refuses on an untracked file there. A unique root per run, because the corpus
// runs concurrently.
$root = sys_get_temp_dir() . '/duo-reviewer-tier-' . bin2hex(random_bytes(6));
register_shutdown_function(static fn() => rev_remove_tree($root));

$library = $root . '/library';
$site = $root . '/site';
$foreign = $root . '/foreign-evidence';
rev_copy_tree($repo . '/manifests', $library);
if (!mkdir($site . '/adapters', 0777, true) || !mkdir($foreign . '/adapters', 0777, true)) {
    fwrite(STDERR, "FAIL: cannot create the scratch site/evidence repositories under $root\n");
    exit(1);
}

$report = static function (string $line): void {
    duo_check_detail($line);
};
$refusal = static function (callable $fn): ?string {
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return $e->getMessage();
    }
};

/** Deterministic fixture keypairs, with the v2 fingerprint id the grammar derives. */
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
$platformKey = $pair('W');
$operatorKey = $pair('O');
$platformId = 'platform-' . $platformKey['short'];
$operatorId = 'acme-ops';

$secretFile = static function (string $path, string $secret): string {
    rev_write($path, base64_encode($secret) . "\n");
    chmod($path, 0600);
    return $path;
};
$platformSecretPath = $secretFile($root . '/platform-root.key', $platformKey['secret']);
$operatorSecretPath = $secretFile($root . '/operator.key', $operatorKey['secret']);

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
rev_write_canon($site . '/adapters/' . $adapterName . '.json', $adapterManifest);
// THE FOREIGN CHECKOUT of the same adapter. `assertBundleSubjectInput()` binds
// the exact RAW bytes, so the two copies must be byte-identical — which is what
// "the lab exercised THIS adapter" means and all it can mean. Written from the
// same canonical encoder rather than copied, so a divergence would be a real
// divergence and not a copy artefact.
rev_write_canon($foreign . '/adapters/' . $adapterName . '.json', $adapterManifest);
rev_write_canon($site . '/site.duo.json', (object) [
    'manifests' => [['name' => $adapterName, 'source' => 'site']],
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION,
]);

$certificatePath = $site . '/adapters/certifications/' . $adapterName . '.json';
$authoritiesPath = $library . '/capabilities/adapter-authorities.json';

$v2Record = static fn(string $encodedPublic, array $names, array $tiers): array => [
    'adapter_names' => $names,
    'algorithm' => 'ed25519',
    'not_after' => '2099-01-01T00:00:00Z',
    'not_before' => '2026-01-01T00:00:00Z',
    'public_key' => $encodedPublic,
    'record_version' => 2,
    'scope' => 'site_adapter_certification',
    'status' => 'trusted',
    'trust_tiers' => $tiers,
];

putenv('DUO_MANIFESTS_DIR=' . $library);

/**
 * Reset the pin to bare, load, read the certificate-derived digest back, and
 * write the exact pin. Two passes because the digest folds the CERTIFICATE in,
 * so it cannot be known before the certificate exists — the same two passes
 * `duo adapter certify --pin` makes.
 */
$pinRepository = static function (string $name) use ($site): string {
    $reset = Canon::decode(Canon::read_file($site . '/site.duo.json'));
    $reset['manifests'] = [['name' => $name, 'source' => 'site']];
    rev_write_canon($site . '/site.duo.json', $reset);
    $policy = Policy::load($site);
    $digest = '';
    foreach (RepositoryCompiler::resolved_adapters($policy) as $row) {
        if ((string) $row['name'] === $name) {
            $digest = (string) $row['digest'];
        }
    }
    $document = Canon::decode(Canon::read_file($site . '/site.duo.json'));
    $document['manifests'] = [['digest' => $digest, 'name' => $name, 'source' => 'site']];
    rev_write_canon($site . '/site.duo.json', $document);

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

// ===========================================================================
echo "\n== step 1: the evidence repository is FOREIGN, and this one holds none of it ==\n";
// ===========================================================================

$shippedAuthorities = (string) file_get_contents($repo . '/manifests/capabilities/adapter-authorities.json');
duo_check_same(
    Canon::encode((object) ['format' => AdapterCertification::AUTHORITIES_FORMAT, 'keys' => new stdClass()]),
    $shippedAuthorities,
    'the shipped trust root is still the EMPTY v1 registry, byte for byte: this rider opens a TIER, and issuing '
    . 'a key to a real reviewer is still gate G4\'s separate decision'
);

// The claim the whole rider rests on, measured rather than asserted. If a suite
// or a conformance fixture in THIS repository happened to be named after the
// cited test, "verifies with this repo holding none of them" would be true by
// accident and would stop being true silently.
$localNamesakes = [];
foreach ([$repo . '/sandbox/tests', $repo . '/sandbox/conformance'] as $tree) {
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if ($file->isFile() && str_contains($file->getFilename(), REVIEWER_TIER_TEST_ID)) {
            $localNamesakes[] = $file->getPathname();
        }
    }
}
duo_check_same(
    [],
    $localNamesakes,
    'this repository holds NO file named for the cited test `' . REVIEWER_TIER_TEST_ID . '` — the run being '
    . 'certified below happened somewhere duo-wp cannot see, which is the entire content of the word "federated"'
);
duo_check(
    !str_contains((string) file_get_contents($repo . '/Makefile'), REVIEWER_TIER_TEST_ID),
    'and no Makefile target names it either, so there is no target this gate could run to re-derive the evidence '
    . 'it is about to trust'
);
duo_check(
    is_file($foreign . '/adapters/' . $adapterName . '.json')
        && !is_file($foreign . '/site.duo.json')
        && realpath($foreign) !== realpath($site) && realpath($foreign) !== realpath($repo),
    'the evidence repository is a third checkout — not the managed site and not duo-wp — carrying the adapter '
    . 'under test and nothing else a site would carry'
);
duo_check_same(
    hash_file('sha256', $site . '/adapters/' . $adapterName . '.json'),
    hash_file('sha256', $foreign . '/adapters/' . $adapterName . '.json'),
    'and its copy of the adapter is byte-identical to the site\'s: `assertBundleSubjectInput()` binds the exact '
    . 'raw bytes, so "the lab exercised THIS adapter" is a digest comparison and never a name comparison'
);

// ===========================================================================
echo "\n== step 2: the platform root admits the foreign bundle, and the word appears ==\n";
// ===========================================================================

rev_write_canon($authoritiesPath, (object) [
    'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
    'keys' => (object) [
        $platformId => $v2Record($platformKey['encoded'], ['acme-*'], ['declarative_manifest', 'plugin_provider']),
    ],
]);
$signRegistry = rev_run([
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'authorities-sign',
    '--authorities=' . $authoritiesPath,
    '--authority=' . $platformId,
    '--secret-key-file=' . $platformSecretPath,
]);
duo_check(
    $signRegistry['exit'] === 0,
    'a scratch platform root is enrolled through the shipped `authorities-sign` verb (' . trim($signRegistry['stderr']) . ')'
);

$bundleDir = rev_write_bundle($foreign . '/runs/' . $adapterName, $foreign, $adapterName, $adapterManifest);
$signFrom = static fn(string $bundle): array => rev_run([
    'env',
    'DUO_MANIFESTS_DIR=' . $library,
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'sign',
    '--manifest-dir=' . $library,
    '--repo=' . $site,
    '--name=' . $adapterName,
    '--bundle=' . $bundle,
    // THE FEDERATION SEAM: the evidence repository is not the site repository
    // and not this one. `sign()` has always taken it separately; what was
    // missing was a vocabulary for what comes back.
    '--evidence-repo=' . $foreign,
    '--authority=' . $platformId,
    '--secret-key-file=' . $platformSecretPath,
]);
$reviewerSign = $signFrom($bundleDir);
duo_check(
    $reviewerSign['exit'] === 0,
    'the platform root signs a bundle carrying `evidence.reviewer` — the slot § v3.10 reserved as a refusal is '
    . 'OPEN (' . trim($reviewerSign['stderr'] . ' ' . $reviewerSign['stdout']) . ')'
);

$verified = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
duo_check_same(
    'certified',
    $verified['claim']['status'] ?? null,
    'and the certificate it minted verifies live: nothing about verification was relaxed to admit the tier'
);
duo_check_same(
    REVIEWER_TIER_PARTY,
    $verified['disposition']['provenance']['proof']['bundle']['reviewer'] ?? null,
    'the reviewing party is carried on the verified proof, projected out of the signed bundle rather than '
    . 're-derived — it is inside the signature and folded into the adapter digest'
);
duo_check_same(
    REVIEWER_TIER_PARTY,
    $verified['claim']['certification']['reviewer'] ?? null,
    'and onto the claim beside `principal`, which is the SIGNER: two named parties, one who exercised and one '
    . 'who vouched, is exactly the information a single root-derived word cannot carry'
);
duo_check(
    ($verified['claim']['certification']['principal'] ?? null) === $platformId
        && ($verified['claim']['certification']['principal'] ?? null)
            !== ($verified['claim']['certification']['reviewer'] ?? null),
    'and they are different parties on the wire, not two labels for one key'
);

$reviewerDigest = $pinRepository($adapterName);
duo_check_same(
    AdapterSources::CERTIFICATION_REVIEWER_SIGNED,
    $surveyWord($adapterName),
    'so the operator-facing word is `reviewer_signed`: the tier gate G4 decides, projected from a bundle a '
    . 'party outside this repository produced'
);
$report('reviewer-tier adapter digest: ' . substr($reviewerDigest, 0, 16) . '…');

// ===========================================================================
echo "\n== step 3: AT DISTANCE — the evidence repository is deleted, the proof stays ==\n";
// ===========================================================================

// This is the acceptance in one move. Runtime verification reopens NO evidence
// checkout: every asset is content-addressed inside the signed bundle, so the
// certificate carries its own proof and the lab's disk is not a dependency of
// the site that trusts it.
rev_remove_tree($foreign);
duo_check(
    !is_dir($foreign),
    'the foreign evidence repository is removed from disk entirely — no adapter copy, no bundle, no test assets'
);
$atDistance = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
duo_check_same(
    'certified',
    $atDistance['claim']['status'] ?? null,
    'and the certificate still verifies with this repository holding none of the cited tests and the evidence '
    . 'checkout gone: the bundle manifest, its asset digests and the ratification are all inside the signature'
);
duo_check_same(
    AdapterSources::CERTIFICATION_REVIEWER_SIGNED,
    $surveyWord($adapterName),
    'and still projects the reviewer word, which is what makes the tier usable rather than merely representable'
);
duo_check_same(
    [REVIEWER_TIER_TEST_ID],
    array_values((array) ($atDistance['disposition']['provenance']['proof']['bundle']['tests'] ?? [])),
    'the proof names the cited test by id, so an operator can see WHICH run they are trusting even though they '
    . 'cannot run it'
);

// ===========================================================================
echo "\n== step 4: the word never collapses into either existing one ==\n";
// ===========================================================================

$words = [
    AdapterSources::CERTIFICATION_REVIEWER_SIGNED,
    AdapterSources::CERTIFICATION_SITE_SIGNED,
    'third_party_signed',
];
duo_check_same(
    3,
    count(array_unique($words)),
    'the three signed words are three distinct strings — a tier that reused an existing spelling would be a '
    . 'relabelling, not a third fact'
);

// The SAME estate, the SAME key, the SAME exercised bundle, with the reviewer
// member removed: the word falls back to the root-derived one. That is what
// makes step 2 a measurement of the member rather than of the fixture.
rev_write_canon($foreign . '/adapters/' . $adapterName . '.json', $adapterManifest);
$plainDir = rev_write_bundle($foreign . '/runs/plain', $foreign, $adapterName, $adapterManifest, [
    'evidence' => [
        'exercised' => true,
        'grammar' => 'ok',
        'reason' => 'an independent conformance lab exercised this adapter against a live target',
    ],
]);
$plainSign = $signFrom($plainDir);
duo_check($plainSign['exit'] === 0, 'the same bundle without the reviewer member signs exactly as it did before this rider');
$plainVerified = AdapterCertification::verifyFile($library, $site, $adapterName, $adapterManifest, $certificatePath);
$pinRepository($adapterName);
duo_check_same(
    'third_party_signed',
    $surveyWord($adapterName),
    'and projects `third_party_signed` — the root-derived word, unchanged: removing one optional member is the '
    . 'only difference between these two certificates and it is the only difference in the answer'
);
duo_check_same(
    null,
    $plainVerified['disposition']['provenance']['proof']['bundle']['reviewer'] ?? null,
    'with no reviewer anywhere on its proof'
);

// The operator's own root, for the third word. `sign_site()` refuses a
// platform key by name, so this is the only path to `site_signed` and it takes
// the operator's own key in the operator's own repository.
rev_write_canon($site . '/adapters/authorities.json', [
    'format' => AdapterCertification::AUTHORITIES_FORMAT,
    'keys' => (object) [
        $operatorId => [
            'adapter_names' => [$adapterName],
            'algorithm' => 'ed25519',
            'public_key' => $operatorKey['encoded'],
            'scope' => 'site_adapter_certification',
            'status' => 'trusted',
            'trust_tiers' => ['declarative_manifest'],
        ],
    ],
]);
$siteSign = rev_run([
    'env',
    'DUO_MANIFESTS_DIR=' . $library,
    PHP_BINARY,
    $repo . '/scripts/adapter-certification.php',
    'sign-site',
    '--manifest-dir=' . $library,
    '--repo=' . $site,
    '--name=' . $adapterName,
    '--authority=' . $operatorId,
    '--secret-key-file=' . $operatorSecretPath,
    '--reason=grammar verified by the site operator; not exercised',
]);
duo_check($siteSign['exit'] === 0, 'the operator certifies the same adapter under their OWN root (' . trim($siteSign['stderr']) . ')');
$pinRepository($adapterName);
duo_check_same(
    AdapterSources::CERTIFICATION_SITE_SIGNED,
    $surveyWord($adapterName),
    'and gets `site_signed`: all three words are minted in one estate, from three different facts, so the tier '
    . 'is measured against its neighbours rather than in isolation'
);

// ===========================================================================
echo "\n== step 5: the reviewer word is unreachable by relabelling ==\n";
// ===========================================================================

$mintRefusal = static function (array $overrides) use (&$signFrom, $foreign, $adapterName, $adapterManifest): string {
    $dir = rev_write_bundle($foreign . '/runs/probe', $foreign, $adapterName, $adapterManifest, $overrides);
    $result = $signFrom($dir);
    if ($result['exit'] === 0) {
        return '';
    }

    return trim($result['stderr']);
};

$unexercised = $mintRefusal([
    'evidence' => [
        'exercised' => false,
        'grammar' => 'ok',
        'reason' => 'grammar only',
        'reviewer' => REVIEWER_TIER_PARTY,
    ],
    'tests' => [],
    'cited' => [],
]);
duo_check(
    str_contains($unexercised, 'beside exercised false')
        && str_contains($unexercised, 'has none to attribute'),
    'a bundle naming a reviewer beside `exercised: false` refuses: the tier states WHO exercised it, and the '
    . 'sentence a tier must never be able to print is "reviewed by X, exercised by nobody" (' . $unexercised . ')'
);

$selfReview = $mintRefusal([
    'evidence' => [
        'exercised' => true,
        'grammar' => 'ok',
        'reason' => 'exercised',
        'reviewer' => $platformId,
    ],
]);
duo_check(
    str_contains($selfReview, 'which is the signing authority itself'),
    'and a bundle whose reviewer IS the signing key refuses: one party wearing a third word says nothing the '
    . 'trust root did not already say, which is the laundering direction (' . $selfReview . ')'
);

$notAnIdentity = $mintRefusal([
    'evidence' => [
        'exercised' => true,
        'grammar' => 'ok',
        'reason' => 'exercised',
        'reviewer' => 'Acme Conformance Lab, Inc.',
    ],
]);
duo_check(
    str_contains($notAnIdentity, 'canonical lowercase ASCII slugs'),
    'and free text is not a party: the reviewer is held to the same identity grammar as an adapter name and an '
    . 'authority key id, so it is comparable across certificates (' . $notAnIdentity . ')'
);

$unknownMember = $mintRefusal([
    'evidence' => [
        'exercised' => true,
        'grammar' => 'ok',
        'reason' => 'exercised',
        'reviewer' => REVIEWER_TIER_PARTY,
        'totally_made_up_member' => true,
    ],
]);
duo_check(
    str_contains($unknownMember, 'must contain exactly exercised, grammar, reason'),
    'and the evidence object is still CLOSED around it: this rider admitted one named member, not the key set — '
    . 'an ordinary unknown member gets the same sentence it always got (' . $unknownMember . ')'
);

// ===========================================================================
echo "\n== step 6: the cited-test rule is unrelaxed, in all three arms ==\n";
// ===========================================================================

// The bundle carries a real passing run — just not the one the disposition
// cites. `tests: []` would refuse one step earlier ("has no named tests"),
// which is a different rule about a different mistake; the case this arm is
// about is a bundle that looks complete and cites something it does not carry.
$absent = $mintRefusal([
    'tests' => [['id' => 'acme-lab-some-other-run', 'verdict' => 'pass']],
    'cited' => [REVIEWER_TIER_TEST_ID],
]);
duo_check(
    str_contains($absent, 'cites absent or non-passing bundle test')
        && str_contains($absent, REVIEWER_TIER_TEST_ID),
    'a disposition citing a test the bundle does not carry refuses, BY NAME — a federated tier whose citation '
    . 'list was decorative would certify a run nobody made (' . $absent . ')'
);

$failing = $mintRefusal([
    'tests' => [['id' => REVIEWER_TIER_TEST_ID, 'verdict' => 'fail']],
]);
duo_check(
    str_contains($failing, 'must contain each named passing test exactly once'),
    'a cited test PRESENT in the bundle but recorded `fail` refuses at the manifest, before any asset is opened '
    . '(' . $failing . ')'
);

$failingAsset = $mintRefusal(['result_exit' => 1]);
duo_check(
    str_contains($failingAsset, 'does not record a named passing zero-exit test')
        && str_contains($failingAsset, REVIEWER_TIER_TEST_ID),
    'and a cited test the manifest calls `pass` whose RESULT asset records a non-zero exit refuses too: the '
    . 'manifest row and the content-addressed asset are two facts, and both are checked (' . $failingAsset . ')'
);

// ===========================================================================
echo "\n== step 7: the six existing words, and every certificate in the field ==\n";
// ===========================================================================

// Both vocabularies are read out of the SHIPPED SOURCE rather than by loading
// the two classes: the agent's observer pulls a WordPress-facing require chain
// and cli/duo's is bootstrap-order-sensitive, and neither is this suite's
// subject. The regex is anchored on the const declaration each file actually
// carries, so a renamed or deleted list fails here rather than matching nothing
// and comparing two empty arrays.
$wordsIn = static function (string $file): array {
    $source = (string) file_get_contents($file);
    if (preg_match('/const CERTIFICATIONS = \[(.*?)\];/s', $source, $m) !== 1) {
        throw new RuntimeException("no CERTIFICATIONS vocabulary found in $file");
    }
    preg_match_all("/'([a-z_]+)'/", $m[1], $found);
    $words = $found[1];
    sort($words, SORT_STRING);

    return $words;
};
$agentWords = $wordsIn($repo . '/agent/src/Adapter/AdapterObservation.php');
$hostWords = $wordsIn($repo . '/cli/src/Adapter/AdapterObservation.php');
duo_check_same(
    [
        'certification_unjudged', 'registry', 'reviewer_signed', 'signed_unpinned', 'site_signed',
        'third_party_signed', 'uncertified',
    ],
    $agentWords,
    'the emitting vocabulary is the six it was plus exactly one: nothing was renamed, nothing was dropped, and '
    . 'the six existing words mean what they meant'
);
duo_check_same(
    $agentWords,
    $hostWords,
    'and the host validator carries the SAME set — a closed enum the target can emit and the host cannot read '
    . 'refuses the whole observation, which is why the word had to be admitted on both sides in one change'
);

// PRECEDENCE, driven rather than asserted. The three states that mean "there is
// no reviewed signature here" are answered BEFORE anyone asks who exercised
// what, so the reviewer member can never elevate an unpinned or unjudged row.
$unpinnedSite = Canon::decode(Canon::read_file($site . '/site.duo.json'));
$unpinnedSite['manifests'] = [['name' => $adapterName, 'source' => 'site']];
rev_write_canon($site . '/site.duo.json', $unpinnedSite);
$reviewerBundle = rev_write_bundle($foreign . '/runs/' . $adapterName, $foreign, $adapterName, $adapterManifest);
duo_check($signFrom($reviewerBundle)['exit'] === 0, 'the reviewer-tier certificate is re-minted for the precedence probe');
duo_check_same(
    'signed_unpinned',
    $surveyWord($adapterName),
    'a reviewer-tier certificate whose repository has NOT pinned it is `signed_unpinned`, not `reviewer_signed`: '
    . 'certification is an elevation the pin gates, and a named reviewer does not buy past it'
);
$pinRepository($adapterName);
duo_check_same(
    AdapterSources::CERTIFICATION_REVIEWER_SIGNED,
    $surveyWord($adapterName),
    'and the exact pin is what elevates it, exactly as it does for the two words beside it'
);

// THE FIELD INVARIANT. `proof.bundle` is folded into the adapter digest every
// `site.duo.json` pin binds (AGENTS.md rule 2), so a member written
// unconditionally — even as `null` — would have moved the pinned digest of every
// site-certified adapter on every fleet, for a tier none of them claims.
$plainMembers = array_keys((array) ($plainVerified['disposition']['provenance']['proof']['bundle'] ?? []));
sort($plainMembers, SORT_STRING);
duo_check_same(
    ['digest', 'exercised', 'force_hatches', 'git_revision', 'schema', 'signed_at', 'tests'],
    $plainMembers,
    'a bundle that names no reviewer produces the EXACT seven-member proof it produced before this rider — no '
    . '`reviewer: null` — so every certificate in the field keeps its digest and every pin binding it still binds'
);
$reviewerMembers = array_keys((array) ($atDistance['disposition']['provenance']['proof']['bundle'] ?? []));
sort($reviewerMembers, SORT_STRING);
duo_check_same(
    ['digest', 'exercised', 'force_hatches', 'git_revision', 'reviewer', 'schema', 'signed_at', 'tests'],
    $reviewerMembers,
    'and one that does adds exactly one member — the fact, and nothing beside it'
);

duo_check_summary('reviewer evidence tier');
