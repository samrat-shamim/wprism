<?php
/**
 * Offline contract for `wprism adapter discover | install | update` — the remote
 * discovery and distribution channel (WP-5.6; spec/repo-format.md § v3.19).
 *
 * WHAT THIS SUITE IS FOR, in the order it matters.
 *
 * **1. Resolution never falls through.** Every rung of the ladder is driven
 * with a fixture that breaks exactly that rung, and each one is asserted twice:
 * the command refuses with its own typed code, AND the repository's
 * `adapters/` tree is byte-for-byte what it was before the attempt. The second
 * assertion is the one that matters. A refusal that had already written the
 * adapter and then rolled back is a different product from one that never
 * wrote — the first has a crash window in which unverified bytes are on disk,
 * and no exit code distinguishes them. `dist_tree()` snapshots every file's
 * digest, and `dist_untouched()` is asserted after all fourteen refusing
 * installs below.
 *
 * **2. An unsigned or unverifiable package refuses.** "Unsigned" has two
 * meanings here and both are covered: an index entry that carries no
 * certificate members at all cannot be EXPRESSED (the closed entry key set
 * refuses it in the document), and a certificate that does not verify against
 * the repository's own trust root refuses at install. There is no third state
 * where a package installs uncertified — which is the whole attack this
 * channel would otherwise create, since an uncertified adapter still loads.
 *
 * **3. A revoked or expired authority refuses.** Not through a second opinion
 * in the distribution command: through
 * `AdapterCertification::verifyFile()`, the same call the live policy path
 * makes, so the typed revocation channel (§ v3.8) and a lapsed v2 `not_after`
 * reach an install by the identical door they reach every other verifier. The
 * cases below install a real platform-signed revocation document into a
 * hermetic manifest library and watch the install stop; standing it down
 * restores the install, because absence means "nothing is revoked".
 *
 * **4. Byte-identical catalog output.** A package installed from an index and
 * an adapter certified by hand with `wprism adapter certify` produce the same two
 * files, so `wprism adapter list --repo --format=json` prints the same document
 * for both, modulo the repository path that IS each repository's identity.
 * That is the property that lets everything already written about an installed
 * adapter — the catalog, the pin, the certificate, the claim — keep applying
 * without a word of it being restated for distributed packages.
 *
 * NO LIVE NETWORK ON ANY SUITE PATH. Every URL in every fixture is a `file://`
 * URL into this suite's own scratch root, and the one `https://` fixture
 * exists precisely to prove that an https entry is DISCOVERABLE and REFUSES at
 * install rather than being fetched. `dist_assert_no_network()` re-reads every
 * index this suite wrote and fails if any non-file URL was ever resolvable —
 * the recorded-fixture discipline `sandbox/bin/fetch-artifact.sh` keeps for its
 * own cache, asserted rather than assumed.
 *
 * Driven through the real `cli/wprism` in a subprocess rather than in-process:
 * every case here asserts a refusal's typed CODE and its sentence, and the
 * sentence goes to the STDERR constant a same-process call cannot rebind
 * (`regress_adapter_certify.php` states the same split). One boot is ~0.1 s
 * measured, and the whole suite pays about forty of them.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
// The hermetic manifest library, so the revocation cases can install a
// platform-signed document into `capabilities/` without ever writing scratch
// under the shipped `manifests/` (AGENTS.md rule 3 — sandbox/bin/pair.sh
// refuses a dirty candidate source, and an untracked file counts).
require_once __DIR__ . '/../adapter/certification_fixture.php';

$wprismRoot = dirname(__DIR__, 4);
require_once $wprismRoot . '/agent/src/Kernel/Canon.php';
require_once $wprismRoot . '/agent/src/Adapter/AdapterCertification.php';

use WPrism\AdapterCertification;
use WPrism\AdapterSources;
use WPrism\Canon;

// ------------------------------------------------------------------ harness

$root = sys_get_temp_dir() . '/wprism_adapter_distribution_' . bin2hex(random_bytes(6));
if (!mkdir($root . '/keys', 0755, true)) {
    fwrite(STDERR, "cannot create scratch root $root\n");
    exit(1);
}
register_shutdown_function(static function () use ($root): void {
    wprism_cert_remove_tree($root);
});

/** Every index document this suite writes, so the no-network premise can be re-checked at the end. */
$distIndexes = [];

/**
 * The hermetic library every subprocess resolves against.
 *
 * A COPY of the shipped library, asserted byte-identical and loadable by
 * `wprism_cert_assert_loadable()` before anything mounts it. Two things need it:
 * the revocation channel lives at `capabilities/adapter-revocations.json`
 * INSIDE a manifest library, and a suite that wrote one into `manifests/`
 * would leave scratch in the one directory pair.sh bind-mounts.
 */
$library = wprism_cert_hermetic_library($wprismRoot, $root . '/agent');

/**
 * Run the real `wprism adapter ...` and capture both streams.
 *
 * `--adapter-library` hands the command the exact hermetic archive explicitly;
 * production discovery remains bound to the installed embedded library.
 *
 * @param list<string> $args
 * @return array{exit:int,out:string,err:string}
 */
function dist_run(array $args, ?string $library = null): array {
    global $wprismRoot;
    if ($library !== null && in_array($args[0] ?? '', ['install', 'update', 'list', 'inspect', 'doctor'], true)) {
        $args[] = '--adapter-library=' . $library;
    }
    $process = proc_open(
        array_merge([PHP_BINARY, $wprismRoot . '/cli/wprism', 'adapter'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null
    );
    if (!is_resource($process)) {
        return ['exit' => -1, 'out' => '', 'err' => 'cannot start wprism'];
    }
    fclose($pipes[0]);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
}

/** A minimal wprism site repository. */
function dist_site(string $root, string $label): string {
    $repo = $root . '/' . $label;
    mkdir($repo . '/' . AdapterSources::SITE_DIR, 0755, true);
    Canon::write_file($repo . '/site.wprism.json', Canon::encode([
        'manifests' => ['core'],
        // An EMPTY JSON object, deliberately: PHP erases {} vs [] on an
        // associative round trip and the engine refuses a list here.
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));

    return (string) realpath($repo);
}

/**
 * Every file under a repository's `adapters/`, by digest.
 *
 * The premise of every "nothing was written" assertion below. Digests rather
 * than mtimes: a rewrite with identical bytes is not a change an operator
 * could observe, and a test that failed on it would be testing the filesystem.
 *
 * @return array<string,string>
 */
function dist_tree(string $repo): array {
    $base = $repo . '/' . AdapterSources::SITE_DIR;
    if (!is_dir($base)) {
        return [];
    }
    $out = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile()) {
            $out[substr($file->getPathname(), strlen($repo) + 1)] = (string) hash_file('sha256', $file->getPathname());
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** @param array<string,string> $before */
function dist_untouched(array $before, string $repo, string $what): void {
    wprism_check_same($before, dist_tree($repo), "$what — and the repository's adapters/ tree is untouched");
}

/**
 * Publish one index document and remember it for the no-network premise check.
 *
 * @param array<string,list<array<string,mixed>>> $adapters
 */
function dist_index(string $path, array $adapters): string {
    global $distIndexes;
    Canon::write_file($path, Canon::encode([
        'adapters' => $adapters === [] ? new stdClass() : $adapters,
        'format' => 'wprism-adapter-index/v1',
    ]));
    $distIndexes[] = $path;

    return $path;
}

/**
 * One index entry over two files that already exist on disk.
 *
 * @return array<string,mixed>
 */
function dist_entry(
    string $adapterPath,
    string $certificatePath,
    string $fingerprint,
    string $version,
    array $window = ['max' => '99.0.0', 'min' => '0.0.1']
): array {
    return [
        'adapter_sha256' => (string) hash_file('sha256', $adapterPath),
        'agent_versions' => $window,
        'authority_fingerprint' => $fingerprint,
        'certificate_sha256' => (string) hash_file('sha256', $certificatePath),
        'certificate_url' => 'file://' . $certificatePath,
        'url' => 'file://' . $adapterPath,
        'version' => $version,
    ];
}

/** A manifest exercising an entity section, a field section and a namespace. */
function dist_manifest(string $name, string $plugin): array {
    $slug = str_replace('-', '_', $name);

    return [
        'name' => $name,
        'option_autoload' => 'preserve',
        'options' => [$slug . '_layout' => ['class' => 'authored']],
        'option_namespaces' => [['match' => '^' . $slug . '_']],
        'plugin' => $plugin,
        'post_meta' => ['_' . $slug . '_ref' => ['class' => 'authored']],
        'post_types' => [$slug . '_item' => ['class' => 'authored']],
        'spec_version' => WPRISM_SPEC_VERSION,
        'version_range' => ['max' => '3.0.0', 'min' => '1.0.0'],
    ];
}

/**
 * Certify one adapter in a publisher repository through the real verb.
 *
 * `wprism adapter certify` is the publishing act: it registers the key in that
 * repository's own trust root and writes the certificate. Using it rather than
 * calling `sign_site()` here means the packages this suite distributes are
 * exactly the packages an author produces, with no fixture-only shortcut in
 * between.
 *
 * @return array{repo:string,adapter:string,certificate:string,key_id:string,fingerprint:string}
 */
function dist_publish(string $root, string $library, string $label, array $manifest, string $secretKey): array {
    $repo = dist_site($root, $label);
    $name = (string) $manifest['name'];
    Canon::write_file($repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json', Canon::encode($manifest));
    $certify = dist_run(
        ['certify', $repo, '--name=' . $name, '--secret-key-file=' . $secretKey],
        $library
    );
    if ($certify['exit'] !== 0) {
        fwrite(STDERR, "fixture manufacture failed: certify $name exited {$certify['exit']}\n{$certify['err']}\n");
        exit(1);
    }
    $authorities = Canon::decode(Canon::read_file(
        $repo . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE
    ));
    $keyId = (string) array_key_first($authorities['keys']);
    $public = (string) base64_decode((string) $authorities['keys'][$keyId]['public_key'], true);

    return [
        'repo' => $repo,
        'adapter' => $repo . '/' . AdapterSources::SITE_DIR . '/' . $name . '.json',
        'certificate' => $repo . '/' . AdapterSources::SITE_DIR . '/'
            . AdapterSources::CERTIFICATION_DIR . '/' . $name . '.json',
        'key_id' => $keyId,
        'fingerprint' => hash('sha256', $public),
    ];
}

/** WPRISM_SPEC_VERSION/WPRISM_AGENT_VERSION, read the way every host verb reads them. */
$agentSource = (string) file_get_contents($wprismRoot . '/agent/wprism.php');
preg_match("/define\('WPRISM_AGENT_VERSION', '([^']+)'\)/", $agentSource, $m);
define('WPRISM_AGENT_VERSION', $m[1]);
preg_match("/define\('WPRISM_SPEC_VERSION', ([0-9]+)\)/", $agentSource, $m);
define('WPRISM_SPEC_VERSION', (int) $m[1]);

// ------------------------------------------------------- the published pair

$vendorKey = $root . '/keys/vendor.key';
$keygen = dist_run(['keygen', '--out=' . $vendorKey], $library);
wprism_check_same(0, $keygen['exit'], 'a vendor mints its own certification key');

$catalog = dist_manifest('acme-catalog', 'acme-catalog/acme-catalog.php');
$published = dist_publish($root, $library, 'publisher', $catalog, $vendorKey);
wprism_check(
    is_file($published['certificate']),
    'and certifies one adapter in its own repository — that pair IS the package'
);

$indexPath = dist_index($root . '/index.json', [
    'acme-catalog' => [dist_entry(
        $published['adapter'],
        $published['certificate'],
        $published['fingerprint'],
        '1.0.0'
    )],
]);

// The consumer starts with NOTHING: no adapter, no certificate, and no trust
// root. Enrolling the vendor key is a separate, deliberate operator act, and
// the first case below is what happens when it has not been taken.
$consumer = dist_site($root, 'consumer');

echo "\n== discovery: an adapter you do not have, and cannot yet install ==\n";

$discover = dist_run(['discover', '--index=' . $indexPath, '--repo=' . $consumer, '--format=json'], $library);
wprism_check_same(0, $discover['exit'], 'discover reads the index and exits 0');
$report = json_decode($discover['out'], true);
wprism_check_same(
    'wprism-adapter-distribution/v1',
    $report['format'] ?? null,
    'it emits a wprism-adapter-distribution/v1 report'
);
wprism_check_same(1, count($report['entries'] ?? []), 'with one row for the one published entry');
$row = $report['entries'][0] ?? [];
wprism_check_same('acme-catalog', $row['name'] ?? null, 'naming the adapter this repository does not have');
wprism_check_same('not_installed', $row['state'] ?? null, 'and saying so — this is the whole missing capability');
wprism_check_same(true, $row['in_agent_window'] ?? null, 'the entry is inside this agent version window');
wprism_check_same('file', $row['transport'] ?? null, 'and there is a transport for its URL');
wprism_check_same(
    false,
    $row['authority_enrolled'] ?? null,
    'while its signer is NOT enrolled here — reported as a fact, not resolved by discovery'
);
wprism_check(
    str_contains($discover['out'], 'carries no signature and confers no trust'),
    'every discover run states out loud that the index is a pointer document with no authority'
);

$before = dist_tree($consumer);
$install = dist_run(['install', $consumer, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $install['exit'], 'installing a package whose signer is not enrolled refuses');
wprism_check(
    str_contains($install['err'], '[authority_not_enrolled]')
        && str_contains($install['err'], 'an index cannot enroll its own signer'),
    'with the typed code and the reason: trust-on-first-use is exactly what this channel must not do'
);
dist_untouched($before, $consumer, 'an unenrolled signer refuses');

echo "\n== enrollment is the operator's act, and then the package installs ==\n";

// Federation by copy, the same shape spec/repo-format.md § v3.8 describes: the
// operator takes the vendor's public record and puts it in their own root.
copy(
    $published['repo'] . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE,
    $consumer . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE
);

$install = dist_run(
    ['install', $consumer, '--index=' . $indexPath, '--name=acme-catalog', '--format=json'],
    $library
);
wprism_check_same(0, $install['exit'], 'with the key enrolled, the package installs');
$installed = json_decode($install['out'], true);
wprism_check_same('installed', $installed['outcome'] ?? null, 'and reports the outcome');
wprism_check_same(
    'experimental',
    $installed['claim_status'] ?? null,
    'the signed but unexercised package lands experimental — never certified by signature alone'
);
wprism_check_same(
    'site',
    $installed['certification']['trust_root'] ?? null,
    'under the SITE trust root, because that is where the key resolved'
);
wprism_check_same(
    (string) file_get_contents($published['adapter']),
    (string) file_get_contents($consumer . '/adapters/acme-catalog.json'),
    'the installed adapter is byte-identical to the published one — install never rewrites a byte, because '
    . 'the digest IS the identity (unlike certify, which canonicalises an author\'s hand-edit)'
);
wprism_check_same(
    (string) file_get_contents($published['certificate']),
    (string) file_get_contents($consumer . '/adapters/certifications/acme-catalog.json'),
    'and so is the certificate'
);

echo "\n== byte-identical catalog output for a locally-installed adapter ==\n";

$fromIndex = dist_run(['list', '--repo=' . $consumer, '--format=json'], $library);
$byHand = dist_run(['list', '--repo=' . $published['repo'], '--format=json'], $library);
wprism_check_same(0, $fromIndex['exit'], 'wprism adapter list reports the installed package');
wprism_check_same(
    str_replace($published['repo'], '<REPO>', $byHand['out']),
    str_replace($consumer, '<REPO>', $fromIndex['out']),
    'and prints the SAME document as for the hand-certified original, modulo the repository path that is '
    . 'each repository\'s own identity — a distributed package is not a fourth source '
    . '(AdapterCatalog.php:186-191)'
);

echo "\n== the index grammar is closed in both directions ==\n";

// EVERY refusal below drives a repository that has the trust root and nothing
// else. A refusal case run against the repository that already holds the
// package would stop at `[already_installed]` — the right refusal for the
// wrong reason, and it would leave the whole ladder below untested while every
// assertion still passed. That is not hypothetical: it is what the first run
// of this suite did.
$probe = dist_site($root, 'probe');
$consumerRoot = $consumer . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE;
copy($consumerRoot, $probe . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE);
$probeControl = dist_run(['install', $probe, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(0, $probeControl['exit'], 'the probe repository CAN install this package — the control');
unlink($probe . '/adapters/acme-catalog.json');
unlink($probe . '/adapters/certifications/acme-catalog.json');

$entry = dist_entry($published['adapter'], $published['certificate'], $published['fingerprint'], '1.0.0');

/** Publish a mutated index and assert the install refuses without writing. */
$refuses = static function (
    string $label,
    array $adapters,
    string $needle,
    array $extraArgs = []
) use ($root, $probe, $library): void {
    static $n = 0;
    $path = dist_index($root . '/bad-' . (++$n) . '.json', $adapters);
    $before = dist_tree($probe);
    $run = dist_run(
        array_merge(['install', $probe, '--index=' . $path, '--name=acme-catalog'], $extraArgs),
        $library
    );
    wprism_check_same(2, $run['exit'], $label);
    wprism_check(
        str_contains($run['err'], $needle),
        "  ...naming it: expected '$needle' in: " . trim($run['err'])
    );
    dist_untouched($before, $probe, $label);
};

$refuses(
    'an index entry carrying a `signature` member refuses',
    ['acme-catalog' => [$entry + ['signature' => 'anything']]],
    '[index_malformed]'
);
$refuses(
    'an entry with NO certificate members cannot be expressed — an unsigned package has no shape here',
    ['acme-catalog' => [array_diff_key($entry, ['certificate_sha256' => 0, 'certificate_url' => 0])]],
    '[index_malformed]'
);
$refuses(
    'a truncated digest refuses — a prefix would let two packages resolve under one pin',
    ['acme-catalog' => [['adapter_sha256' => substr((string) $entry['adapter_sha256'], 0, 32)] + $entry]],
    'must be a lowercase 64-hex sha256'
);
$refuses(
    'an upper-case digest refuses, because one pin must have one spelling',
    ['acme-catalog' => [['adapter_sha256' => strtoupper((string) $entry['adapter_sha256'])] + $entry]],
    'must be a lowercase 64-hex sha256'
);
$refuses(
    'the same version published twice for one adapter refuses — an ambiguity a resolver must break is one it '
    . 'breaks by guessing',
    ['acme-catalog' => [$entry, $entry]],
    'twice'
);
$refuses(
    'an agent window with min >= max refuses through the engine\'s own range grammar',
    ['acme-catalog' => [['agent_versions' => ['max' => '1.0.0', 'min' => '2.0.0']] + $entry]],
    'malformed range'
);
$refuses(
    'a URL with a scheme this command does not name refuses IN THE DOCUMENT, not at fetch time',
    ['acme-catalog' => [['url' => 'ftp://example.invalid/acme.json'] + $entry]],
    'must begin file:// or https://'
);
$refuses(
    'a file:// URL with a .. segment refuses',
    ['acme-catalog' => [['url' => 'file:///srv/mirror/../etc/passwd'] + $entry]],
    "with no '..' segment"
);
$refuses(
    'an empty entry list for a name refuses',
    ['acme-catalog' => []],
    'must be a non-empty list'
);

// An index publishing NOTHING is refused by name rather than reported as an
// empty offer, because `json_decode($raw, true)` renders `{}` and `[]` as the
// same value: accepting one would accept the other.
$emptyIndex = dist_index($root . '/empty-index.json', []);
$before = dist_tree($probe);
$run = dist_run(['discover', '--index=' . $emptyIndex], $library);
wprism_check_same(2, $run['exit'], 'an index that publishes nothing refuses');
wprism_check(
    str_contains($run['err'], 'publishes no adapters')
        && str_contains($run['err'], 'the same value in this language'),
    'naming the {} versus [] ambiguity as the reason rather than a policy: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an empty index refuses');

$wrongFormat = $root . '/wrong-format.json';
Canon::write_file($wrongFormat, Canon::encode([
    'adapters' => ['acme-catalog' => [$entry]],
    'format' => 'wprism-adapter-index/v2',
]));
$distIndexes[] = $wrongFormat;
$before = dist_tree($consumer);
$run = dist_run(['discover', '--index=' . $wrongFormat], $library);
wprism_check_same(2, $run['exit'], 'an index generation this agent does not implement refuses');
wprism_check(
    str_contains($run['err'], 'refused BY VERSION'),
    'by VERSION rather than being read as a document it understands: ' . trim($run['err'])
);
dist_untouched($before, $consumer, 'an unimplemented index generation refuses');

echo "\n== digest-pinned resolution never falls through ==\n";

// The published bytes move AFTER the index was written. This is the whole
// point of a digest pin, and the mutation is undone afterwards so every later
// case still resolves.
$goodAdapter = (string) file_get_contents($published['adapter']);
file_put_contents($published['adapter'], str_replace('preserve', 'preserve ', $goodAdapter));
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'bytes that moved after publication refuse');
wprism_check(
    str_contains($run['err'], '[package_digest_mismatch]')
        && str_contains($run['err'], 'never falls through to the bytes that were served'),
    'with the typed code and the discipline stated: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'a digest miss refuses');
file_put_contents($published['adapter'], $goodAdapter);

$goodCertificate = (string) file_get_contents($published['certificate']);
file_put_contents($published['certificate'], $goodCertificate . "\n");
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a certificate whose bytes moved refuses too');
wprism_check(
    str_contains($run['err'], '[certificate_digest_mismatch]'),
    'with its own typed code: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'a certificate digest miss refuses');
file_put_contents($published['certificate'], $goodCertificate);

$missing = dist_index($root . '/missing.json', [
    'acme-catalog' => [['url' => 'file://' . $root . '/nowhere/acme-catalog.json'] + $entry],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $missing, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a URL that resolves to nothing refuses');
wprism_check(
    str_contains($run['err'], '[package_unreachable]')
        && str_contains($run['err'], 'it never falls through to another source'),
    'and says it does not try somewhere else: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an unreachable package refuses');

// A SYMBOLIC LINK is refused rather than followed. A link inside a mirror
// directory is a pointer its owner can repoint after the digest was published,
// which would make the pin a pin on somebody else's choice of moment.
$linkFarm = $root . '/link-farm';
mkdir($linkFarm, 0755, true);
symlink($published['adapter'], $linkFarm . '/acme-catalog.json');
$linked = dist_index($root . '/linked.json', [
    'acme-catalog' => [['url' => 'file://' . $linkFarm . '/acme-catalog.json'] + $entry],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $linked, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a package served through a symbolic link refuses');
wprism_check(
    str_contains($run['err'], 'a link is a pointer its owner can repoint'),
    'naming why, even though the bytes behind the link hash correctly: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'a symlinked package refuses');

$before = dist_tree($probe);
$run = dist_run(
    ['install', $probe, '--index=' . $indexPath, '--name=acme-catalog', '--version=9.9.9'],
    $library
);
wprism_check_same(2, $run['exit'], 'a version the index does not publish refuses');
wprism_check(
    str_contains($run['err'], '[version_not_indexed]')
        && str_contains($run['err'], 'never approximated')
        && str_contains($run['err'], '1.0.0'),
    'listing what IS published rather than resolving the nearest: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an unpublished version refuses');

$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $indexPath, '--name=nothing-here'], $library);
wprism_check_same(2, $run['exit'], 'an adapter the index does not name refuses');
wprism_check(
    str_contains($run['err'], '[package_not_indexed]'),
    'rather than searching another source: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an unindexed adapter refuses');

echo "\n== an unverifiable package refuses, and never installs uncertified ==\n";

$junkCertificate = $root . '/junk-certificate.json';
Canon::write_file($junkCertificate, Canon::encode(['format' => 'not-a-certificate', 'value' => 1]));
$junk = dist_index($root . '/junk.json', [
    'acme-catalog' => [[
        'certificate_sha256' => (string) hash_file('sha256', $junkCertificate),
        'certificate_url' => 'file://' . $junkCertificate,
    ] + $entry],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $junk, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a certificate that is valid JSON and is not a certificate refuses');
wprism_check(
    str_contains($run['err'], '[package_unverifiable]'),
    'with the typed code — and there is no arm that installs it uncertified: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an unverifiable package refuses');

// A certificate that verifies PERFECTLY — for a different adapter. The
// derived certificate path is what stops it being reused under this name.
$other = dist_publish($root, $library, 'publisher-other', dist_manifest('acme-shop', 'acme-shop/acme-shop.php'), $vendorKey);
$swapped = dist_index($root . '/swapped.json', [
    'acme-catalog' => [[
        'certificate_sha256' => (string) hash_file('sha256', $other['certificate']),
        'certificate_url' => 'file://' . $other['certificate'],
    ] + $entry],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $swapped, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a valid certificate for a DIFFERENT adapter refuses under this name');
wprism_check(
    str_contains($run['err'], '[package_unverifiable]'),
    'because the certificate path is derived, never declared: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'a swapped certificate refuses');

// The index names a fingerprint; the certificate verifies under a DIFFERENT
// enrolled key. Both keys are trusted here, and that is exactly the point.
$secondKey = $root . '/keys/second.key';
dist_run(['keygen', '--out=' . $secondKey], $library);
// A DIFFERENT declaration, so this is a genuinely different package rather
// than the same bytes under a second signature. The first run of this suite
// used an identical manifest, and `update --to=2.0.0` then reported
// `unchanged` — correctly, since the digests matched — which is how a "the
// update replaced the package" assertion can pass while nothing was replaced.
$secondManifest = dist_manifest('acme-catalog', 'acme-catalog/acme-catalog.php');
$secondManifest['options']['acme_catalog_theme'] = ['class' => 'authored'];
ksort($secondManifest['options'], SORT_STRING);
$second = dist_publish($root, $library, 'publisher-second', $secondManifest, $secondKey);
wprism_check(
    hash_file('sha256', $second['adapter']) !== hash_file('sha256', $published['adapter']),
    'the second publisher\'s package is different bytes, so an update to it is observable'
);

// Enrol the second key in BOTH roots, so the ONLY thing wrong in the index
// below is which fingerprint it names.
$secondRoot = Canon::decode(Canon::read_file(
    $second['repo'] . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE
));
foreach ([$consumerRoot, $probe . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE] as $trustRoot) {
    $merged = Canon::decode(Canon::read_file($trustRoot));
    $merged['keys'][$second['key_id']] = $secondRoot['keys'][$second['key_id']];
    ksort($merged['keys'], SORT_STRING);
    Canon::write_file($trustRoot, Canon::encode($merged));
}

$liar = dist_index($root . '/liar.json', [
    'acme-catalog' => [[
        'adapter_sha256' => (string) hash_file('sha256', $second['adapter']),
        'authority_fingerprint' => $published['fingerprint'],
        'certificate_sha256' => (string) hash_file('sha256', $second['certificate']),
        'certificate_url' => 'file://' . $second['certificate'],
        'url' => 'file://' . $second['adapter'],
    ] + $entry],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $liar, '--name=acme-catalog'], $library);
wprism_check_same(
    2,
    $run['exit'],
    'a certificate under a DIFFERENT but equally enrolled key refuses when the index named another'
);
wprism_check(
    str_contains($run['err'], '[authority_fingerprint_mismatch]')
        && str_contains($run['err'], 'an enrolled key is not automatically the right key'),
    'because the index\'s fingerprint is a pin, not a hint: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'a fingerprint that does not match the resolved authority refuses');

echo "\n== an out-of-window package refuses, and an ambiguous one is not guessed ==\n";

$stale = dist_index($root . '/stale.json', [
    'acme-catalog' => [dist_entry(
        $published['adapter'],
        $published['certificate'],
        $published['fingerprint'],
        '0.9.0',
        ['max' => '0.2.0', 'min' => '0.1.0']
    )],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $stale, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a package offered for an older agent line refuses');
wprism_check(
    str_contains($run['err'], '[package_out_of_window]')
        && str_contains($run['err'], 'refused, not installed with a warning'),
    'the publisher\'s window is a statement about what they exercised: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an out-of-window package refuses');

$discoverStale = dist_run(['discover', '--index=' . $stale, '--format=json'], $library);
wprism_check_same(
    false,
    json_decode($discoverStale['out'], true)['entries'][0]['in_agent_window'] ?? null,
    'and discover still LISTS it, marked out of window — knowing it exists is the point'
);

$ambiguous = dist_index($root . '/ambiguous.json', [
    'acme-catalog' => [
        dist_entry($published['adapter'], $published['certificate'], $published['fingerprint'], '1.0.0'),
        dist_entry($second['adapter'], $second['certificate'], $second['fingerprint'], '2.0.0'),
    ],
]);
$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $ambiguous, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'two in-window versions and no --version refuses');
wprism_check(
    str_contains($run['err'], '[ambiguous_version]')
        && str_contains($run['err'], 'choosing for you would be a guess'),
    'rather than picking the "newest" out of strings this format defines no order over: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an ambiguous version refuses');

echo "\n== one transport ships, and the boundary is stated rather than hidden ==\n";

$remote = dist_index($root . '/remote.json', [
    'acme-catalog' => [[
        'certificate_url' => 'https://packages.example.test/acme-catalog-1.0.0.cert.json',
        'url' => 'https://packages.example.test/acme-catalog-1.0.0.json',
    ] + $entry],
]);
$discoverRemote = dist_run(['discover', '--index=' . $remote, '--format=json'], $library);
wprism_check_same(0, $discoverRemote['exit'], 'an https:// entry is DISCOVERABLE');
$remoteRow = json_decode($discoverRemote['out'], true)['entries'][0] ?? [];
wprism_check_same('acme-catalog', $remoteRow['name'] ?? null, 'and tells you the adapter exists');
wprism_check(
    array_key_exists('transport', $remoteRow) && $remoteRow['transport'] === null,
    'while reporting a NULL transport — the member is present and empty, which is a different answer from '
    . 'a row that forgot to say'
);

$before = dist_tree($probe);
$run = dist_run(['install', $probe, '--index=' . $remote, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'and installing it refuses');
wprism_check(
    str_contains($run['err'], '[transport_unavailable]')
        && str_contains($run['err'], 'Mirror the package'),
    'naming the mirror step rather than fetching: ' . trim($run['err'])
);
dist_untouched($before, $probe, 'an https package refuses');

echo "\n== a revoked authority refuses, through the engine's own verifier ==\n";

// The revocation channel needs a PLATFORM key enrolled in the library's own
// trust root — with the shipped empty `{"keys":{}}` the channel is inert by
// construction, which is the first finding the revocation drill records.
$platformPair = sodium_crypto_sign_keypair();
$platformSecret = sodium_crypto_sign_secretkey($platformPair);
$platformPublic = sodium_crypto_sign_publickey($platformPair);
$platformId = 'platform-' . substr(hash('sha256', $platformPublic), 0, 12);
$libraryAuthorities = $library . '/capabilities/adapter-authorities.json';
$shippedAuthorities = (string) file_get_contents($libraryAuthorities);
Canon::write_file($libraryAuthorities, Canon::encode([
    'format' => AdapterCertification::AUTHORITIES_FORMAT,
    'keys' => (object) [
        $platformId => [
            'adapter_names' => ['platform-root-only'],
            'algorithm' => 'ed25519',
            'public_key' => base64_encode($platformPublic),
            'scope' => 'site_adapter_certification',
            'status' => 'trusted',
            'trust_tiers' => [AdapterSources::TIER_DECLARATIVE],
        ],
    ],
]));

$revocations = $library . '/capabilities/adapter-revocations.json';
file_put_contents($revocations, AdapterCertification::signRevocations(
    Canon::encode((object) [
        'format' => AdapterCertification::REVOCATION_FORMAT,
        'issued_at' => '2020-01-01T00:00:00Z',
        'revocations' => [[
            'effective_at' => '2020-01-01T00:00:00Z',
            'fingerprint' => $published['fingerprint'],
            'key_id' => $published['key_id'],
            'reason' => 'vendor signing key disclosed in a build log',
        ]],
        'version' => 1,
    ]),
    $platformId,
    base64_encode($platformSecret)
));

$freshRepo = dist_site($root, 'revoked-consumer');
copy($consumerRoot, $freshRepo . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE);
$before = dist_tree($freshRepo);
$run = dist_run(['install', $freshRepo, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a package signed by a REVOKED key refuses to install');
wprism_check(
    str_contains($run['err'], '[authority_withdrawn:' . AdapterSources::WITHDRAWN_AUTHORITY_REVOKED . ']')
        && str_contains($run['err'], 'capabilities/adapter-revocations.json'),
    'carrying the engine\'s own withdrawal tag and its own sentence, because this command holds no second '
    . 'revocation opinion: ' . trim($run['err'])
);
dist_untouched($before, $freshRepo, 'a revoked authority refuses');

unlink($revocations);
$run = dist_run(['install', $freshRepo, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(0, $run['exit'], 'standing the revocation down restores the install — absence means nothing is revoked');
wprism_check(
    is_file($freshRepo . '/adapters/acme-catalog.json'),
    'and the package lands'
);

echo "\n== an EXPIRED authority refuses, by the same door ==\n";

// A v2 authority record carries a MANDATORY validity window and its envelope
// signature covers {format, keys} whole (§ v3.7), so this is the enrolled
// shape rather than a hand-edited v1 with an extra member. `wprism adapter
// certify` cannot produce it — `registerAuthority()` writes v1 by construction
// — and the signer refuses to mint under an already-lapsed key, so the
// certificate is minted HERE, in-process, with the one named clock every
// window in AdapterCertification reads hooked to an instant inside the window
// (the hook `regress_revocation_reachability.php` uses). The install then runs
// at the real wall clock, outside it. That is the whole case: a certificate
// that was valid when signed, and is not now.
$expiredPublisher = dist_site($root, 'expired-publisher');
$vendorSecretRaw = (string) base64_decode(trim((string) file_get_contents($vendorKey)), true);
$vendorPublic = sodium_crypto_sign_publickey_from_secretkey($vendorSecretRaw);
$vendorFingerprint = hash('sha256', $vendorPublic);
// § v3.7 change (a): a v2 key id must END in the first 12 hex of
// sha256(its own public_key), and `wprism adapter keygen` already derives
// exactly that shape, so the certificate's own `key_id` is reusable here.
$vendorV2Id = $published['key_id'];
$expiredWindow = ['not_after' => '2021-01-01T00:00:00Z', 'not_before' => '2020-01-01T00:00:00Z'];
$expiredRootDocument = AdapterCertification::signAuthorities(
    Canon::encode((object) [
        'format' => AdapterCertification::AUTHORITIES_FORMAT_V2,
        'keys' => (object) [
            $vendorV2Id => [
                'adapter_names' => ['acme-catalog'],
                'algorithm' => 'ed25519',
                'not_after' => $expiredWindow['not_after'],
                'not_before' => $expiredWindow['not_before'],
                'public_key' => base64_encode($vendorPublic),
                'record_version' => 2,
                'scope' => 'site_adapter_certification',
                'status' => 'trusted',
                'trust_tiers' => [AdapterSources::TIER_DECLARATIVE],
            ],
        ],
    ]),
    $vendorV2Id,
    base64_encode($vendorSecretRaw)
);
Canon::write_file(
    $expiredPublisher . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE,
    $expiredRootDocument
);
Canon::write_file(
    $expiredPublisher . '/' . AdapterSources::SITE_DIR . '/acme-catalog.json',
    (string) file_get_contents($published['adapter'])
);

// The signing API receives the same explicit archive the subprocesses use, so
// the certificate and the command bind one platform boundary without a
// process-global selector.
$clock = new ReflectionProperty(AdapterCertification::class, 'testAuthorityClock');
$clock->setValue(null, static fn(): int => (int) strtotime('2020-06-01T00:00:00Z'));
$expiredCertificate = AdapterCertification::sign_site(
    $library,
    $expiredPublisher,
    'acme-catalog',
    $vendorV2Id,
    base64_encode($vendorSecretRaw),
    'Signed while the vendor key was inside its declared validity window.'
);
$clock->setValue(null, null);
Canon::write_file(
    $expiredPublisher . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR
        . '/acme-catalog.json',
    $expiredCertificate
);

$expiredRepo = dist_site($root, 'expired-consumer');
Canon::write_file(
    $expiredRepo . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE,
    $expiredRootDocument
);
$expiredIndex = dist_index($root . '/expired.json', [
    'acme-catalog' => [dist_entry(
        $expiredPublisher . '/' . AdapterSources::SITE_DIR . '/acme-catalog.json',
        $expiredPublisher . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::CERTIFICATION_DIR
            . '/acme-catalog.json',
        $vendorFingerprint,
        '1.0.0'
    )],
]);
$before = dist_tree($expiredRepo);
$run = dist_run(['install', $expiredRepo, '--index=' . $expiredIndex, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a package whose authority window has lapsed refuses to install');
wprism_check(
    str_contains($run['err'], '[authority_withdrawn:' . AdapterSources::WITHDRAWN_AUTHORITY_WINDOW . ']'),
    'with the engine\'s own withdrawal tag — the window is decided by the verifier, not by this command: '
    . trim($run['err'])
);
wprism_check(
    str_contains($run['err'], $expiredWindow['not_after']),
    'naming the instant the grant lapsed: ' . trim($run['err'])
);
dist_untouched($before, $expiredRepo, 'an expired authority refuses');

// Restore the library's shipped trust root so nothing after this depends on
// the platform key this section enrolled.
Canon::write_file($libraryAuthorities, $shippedAuthorities);

echo "\n== install and update refuse the other one's job ==\n";

$before = dist_tree($consumer);
$run = dist_run(['install', $consumer, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'install over an adapter that is already there refuses');
wprism_check(
    str_contains($run['err'], '[already_installed]') && str_contains($run['err'], 'adapter update'),
    'and points at update, rather than overwriting bytes an operator may have authored: ' . trim($run['err'])
);
dist_untouched($before, $consumer, 'install never overwrites');

$emptyRepo = dist_site($root, 'empty-consumer');
copy($consumerRoot, $emptyRepo . '/' . AdapterSources::SITE_DIR . '/' . AdapterSources::SITE_AUTHORITIES_FILE);
$run = dist_run(
    ['update', $emptyRepo, '--index=' . $indexPath, '--name=acme-catalog', '--to=1.0.0'],
    $library
);
wprism_check_same(2, $run['exit'], 'update with nothing installed refuses');
wprism_check(
    str_contains($run['err'], '[not_installed]') && str_contains($run['err'], 'adapter install'),
    'and points at install: ' . trim($run['err'])
);

$run = dist_run(['update', $consumer, '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'update with no --to refuses');
wprism_check(
    str_contains($run['err'], 'defines no order over version strings'),
    'because nothing here ranks version strings for an operator: ' . trim($run['err'])
);

echo "\n== update replaces a package, and refuses to replace a local decision ==\n";

$twoVersions = dist_index($root . '/two.json', [
    'acme-catalog' => [
        dist_entry($published['adapter'], $published['certificate'], $published['fingerprint'], '1.0.0'),
        dist_entry($second['adapter'], $second['certificate'], $second['fingerprint'], '2.0.0'),
    ],
]);
$run = dist_run(
    ['update', $consumer, '--index=' . $twoVersions, '--name=acme-catalog', '--to=2.0.0', '--format=json'],
    $library
);
wprism_check_same(0, $run['exit'], 'update to a published version succeeds');
wprism_check_same('updated', json_decode($run['out'], true)['outcome'] ?? null, 'and reports it');
wprism_check_same(
    (string) file_get_contents($second['adapter']),
    (string) file_get_contents($consumer . '/adapters/acme-catalog.json'),
    'the adapter is now the new package, byte for byte'
);
wprism_check_same(
    (string) file_get_contents($second['certificate']),
    (string) file_get_contents($consumer . '/adapters/certifications/acme-catalog.json'),
    'and so is its certificate — both files move, so the pair never disagrees'
);

$before = dist_tree($consumer);
$run = dist_run(
    ['update', $consumer, '--index=' . $twoVersions, '--name=acme-catalog', '--to=2.0.0', '--format=json'],
    $library
);
wprism_check_same(0, $run['exit'], 'updating to the version already installed exits 0');
wprism_check_same(
    'unchanged',
    json_decode($run['out'], true)['outcome'] ?? null,
    'reporting `unchanged` rather than a second install'
);
dist_untouched($before, $consumer, 'a converged update writes nothing');

// `unchanged` is a claim about the PAIR. A repository holding the right
// adapter beside a certificate that is not this entry's is exactly the state
// an operator runs update to repair — and it is the state AdapterSources
// reports as `certificate_invalid` — so reading the manifest alone would
// report "nothing to do" over a broken claim.
$installedCertificate = $consumer . '/adapters/certifications/acme-catalog.json';
$goodInstalledCertificate = (string) file_get_contents($installedCertificate);
file_put_contents($installedCertificate, (string) file_get_contents($published['certificate']));
$run = dist_run(
    ['update', $consumer, '--index=' . $twoVersions, '--name=acme-catalog', '--to=2.0.0', '--format=json'],
    $library
);
wprism_check_same(0, $run['exit'], 'update over a matching adapter and a MISMATCHED certificate re-resolves');
wprism_check_same(
    'updated',
    json_decode($run['out'], true)['outcome'] ?? null,
    'reporting `updated` rather than `unchanged` — convergence is a property of the pair, not of the manifest'
);
wprism_check_same(
    $goodInstalledCertificate,
    (string) file_get_contents($installedCertificate),
    'and the certificate is the entry\'s again'
);

// A hand edit is a decision somebody took. An unsigned index does not get to
// erase one.
$edited = $consumer . '/adapters/acme-catalog.json';
$restore = (string) file_get_contents($edited);
Canon::write_file($edited, Canon::encode(dist_manifest('acme-catalog', 'acme-catalog/acme-catalog.php')
    + ['notes' => ['edited by the operator']]));
$before = dist_tree($consumer);
$run = dist_run(
    ['update', $consumer, '--index=' . $twoVersions, '--name=acme-catalog', '--to=1.0.0'],
    $library
);
wprism_check_same(2, $run['exit'], 'update over locally-edited bytes refuses');
wprism_check(
    str_contains($run['err'], '[unmanaged_installation]')
        && str_contains($run['err'], 'refuses to replace a local decision'),
    'because those bytes hash to nothing the index published: ' . trim($run['err'])
);
dist_untouched($before, $consumer, 'a local edit is never silently overwritten');
Canon::write_file($edited, $restore);

echo "\n== usage refusals ==\n";

$run = dist_run(['install', $consumer, '--index=' . $indexPath, '--index=' . $indexPath, '--name=x'], $library);
wprism_check_same(2, $run['exit'], 'a repeated flag refuses rather than last-wins');
wprism_check(str_contains($run['err'], 'duplicate flag'), 'naming it: ' . trim($run['err']));

$run = dist_run(['discover', '--index=' . $indexPath, '--format=yaml'], $library);
wprism_check_same(2, $run['exit'], '--format takes json or nothing');
wprism_check(
    str_contains($run['err'], "--format takes 'json'"),
    'rather than falling back to the human report a script cannot parse: ' . trim($run['err'])
);

$run = dist_run(['install', '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'install without a site repository refuses');
wprism_check(str_contains($run['err'], 'needs the site repository'), 'saying what is missing: ' . trim($run['err']));

$run = dist_run(['install', $root . '/keys', '--index=' . $indexPath, '--name=acme-catalog'], $library);
wprism_check_same(2, $run['exit'], 'a directory that is not a wprism site repo refuses once, as a usage error');
wprism_check(str_contains($run['err'], 'has no site.wprism.json'), 'naming what it looked for: ' . trim($run['err']));

$run = dist_run(['discover', '--index=' . $root . '/not-there.json'], $library);
wprism_check_same(2, $run['exit'], 'an index that is not a readable file refuses');
wprism_check(str_contains($run['err'], '[index_unreadable]'), 'with a typed code: ' . trim($run['err']));

echo "\n== the premise: no suite path could reach a network ==\n";

$offNetwork = [];
foreach ($distIndexes as $written) {
    if (!is_file($written)) {
        continue;
    }
    $decoded = json_decode((string) file_get_contents($written), true);
    foreach ((array) ($decoded['adapters'] ?? []) as $name => $entries) {
        foreach ((array) $entries as $candidate) {
            foreach (['certificate_url', 'url'] as $member) {
                $value = (string) (($candidate[$member] ?? '') ?: '');
                if ($value !== '' && !str_starts_with($value, 'file://')) {
                    $offNetwork[] = basename($written) . " $name.$member=$value";
                }
            }
        }
    }
}
sort($offNetwork, SORT_STRING);
wprism_check_same(
    [
        'bad-7.json acme-catalog.url=ftp://example.invalid/acme.json',
        'remote.json acme-catalog.certificate_url=https://packages.example.test/acme-catalog-1.0.0.cert.json',
        'remote.json acme-catalog.url=https://packages.example.test/acme-catalog-1.0.0.json',
    ],
    $offNetwork,
    'exactly THREE non-file URLs exist across every index this suite wrote — the ftp one that is refused in '
    . 'the document, and the two https ones that are discoverable and refuse at install. Every other URL in '
    . 'every other fixture is a file:// path inside this suite\'s own scratch root, so no case here could '
    . 'have reached a network even if a transport existed for one'
);
wprism_check(
    !is_dir($wprismRoot . '/manifests/capabilities') || !is_file($wprismRoot . '/manifests/capabilities/adapter-revocations.json'),
    'and the shipped manifest library is untouched: every revocation document this suite installed went into '
    . 'the hermetic copy (AGENTS.md rule 3)'
);

wprism_check_summary('adapter distribution');
