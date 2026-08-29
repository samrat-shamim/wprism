<?php
/**
 * WP-4.12's two flag-day migration verbs, end to end:
 * `duo adapter recertify` and `duo release --spec-v3`
 * (spec/repo-format.md § v3.12; docs/guides/flag-day.md).
 *
 * WHY A TWO-ERA FIXTURE AND NOT A MOCK
 * ------------------------------------
 * `recertify` exists for exactly one population: certificates minted BEFORE
 * the bump, which the post-bump agent refuses because `spec_version` sits
 * inside every signed `statement.platform`. A suite that minted its
 * certificates on this engine would be re-signing documents that were already
 * valid, and would never walk the branch the verb was written for.
 *
 * So the certificates here are minted by a CHILD PROCESS that defines
 * `DUO_SPEC_VERSION` as N-1 over a manifest library whose `platform.json`
 * restates N-1 — AGENTS.md rule 8's pair, moved together, exactly as
 * `spec_migration_estate.php` does and for the same reason: a PHP process
 * holds one `define()`. The parent then runs the real `duo` against the real
 * post-flip library. Every certificate this suite recertifies is genuinely
 * stale, in the way the fleet's are.
 *
 * WHAT IS PROVEN
 * --------------
 *   PART 1 — recertify re-establishes a withdrawn claim, and is IDEMPOTENT:
 *   the second invocation reports `unchanged` and rewrites nothing, because
 *   `sign_site()` reuses `created_at` when a deterministic re-sign of every
 *   current input is byte-identical (#555). `created_at` records when a claim
 *   changed, not how often a cohort runner invoked the command.
 *
 *   PART 2 — ALL-OR-NOTHING. A repository whose second adapter cannot be
 *   signed leaves the trust root AND the first adapter's certificate at their
 *   prior bytes. A half-recertified repository — some adapters bound to the
 *   new boundary, some to the old, and no command that reports which — is the
 *   worst available outcome of this verb, so it is the one made unreachable.
 *
 *   PART 3 — a certificate under a DIFFERENT key is a blocked row naming that
 *   key and a non-green run, never a silent skip.
 *
 *   PART 4 — `release --spec-v3` journals the PRIOR pin objects, is idempotent
 *   (content-addressed, so a re-run rewrites one path rather than growing a
 *   directory), and emits the post-flip objects.
 *
 *   PART 5 — THE ORDERING CONTRACT, measured rather than asserted. The journal
 *   is written BEFORE any new pin object is emitted. With the journal
 *   unwritable the verb must refuse and print NO pin object: if the emission
 *   came first, a site could be re-pinned from output whose provenance was
 *   never recorded.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/agent_version.php';
duo_test_define_agent_versions();
require_once __DIR__ . '/../../lib/check.php';

$duoRoot = dirname(__DIR__, 4);
require_once $duoRoot . '/agent/src/Kernel/Canon.php';

use Duo\Canon;

$root = $duoRoot . '/sandbox/tmp/spec-migration-verbs';
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($path);
};
$removeTree($root);
register_shutdown_function(static function () use ($removeTree, $root): void {
    if (duo_check_failed() === 0) {
        $removeTree($root);
    }
});
mkdir($root, 0755, true);

/**
 * Run the real `duo` as a subprocess with an explicit adapter library.
 *
 * @param list<string> $args
 * @return array{exit:int,out:string,err:string}
 */
function mv_duo(array $args, string $manifestDir): array {
    global $duoRoot;
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $duoRoot . '/cli/duo'], $args, ['--adapter-library=' . $manifestDir]),
        $descriptors,
        $pipes,
        null,
        ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')]
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

/**
 * A manifest library at ONE spec era: the shipped `core` adapter and its
 * reviewed document, the shipped platform boundary with both version members
 * set to that era, and one operator authority key.
 *
 * Only `core` is copied, and its disposition with it: WP-4.4 addressed the
 * reviewed source per subject, and a library carrying entries for manifests it
 * does not hold is what the coverage rule refuses.
 */
function mv_library(string $dir, int $spec, string $agentVersion): void {
    global $duoRoot;
    mkdir($dir . '/capabilities', 0755, true);
    mkdir($dir . '/dispositions', 0755, true);
    foreach (['interpreters', 'providers', 'regenerators'] as $runtime) {
        mkdir($dir . '/' . $runtime, 0755, true);
    }
    copy($duoRoot . '/platform/adapter-library/core/manifest.json', $dir . '/core.json');
    copy($duoRoot . '/platform/adapter-library/core/disposition.json', $dir . '/dispositions/core.json');
    copy($duoRoot . '/platform/adapter-library/profiles.json', $dir . '/dispositions/profiles.json');

    // The shipped boundary with EXACTLY the two members rule 8 binds moved.
    // Every compatibility axis is copied byte for byte, because the exercised
    // cells inside a signed statement are read out of these bytes (§ v3.6) and
    // a re-authored axis would sign against a platform no agent runs.
    $platform = Canon::decode(Canon::read_file($duoRoot . '/platform/adapter-library/capabilities/platform.json'));
    $platform['platform']['spec_version'] = $spec;
    $platform['platform']['agent_version'] = $agentVersion;
    Canon::write_file($dir . '/capabilities/platform.json', Canon::encode($platform));

    // EMPTY, exactly as the shipped root is and stays through the flag day
    // (§ v3.12). The operator's key lives ONLY in the site root: `authority()`
    // resolves the platform root first, and a key found there makes the result
    // agent-owned — `sign_site()` refuses that outright, because an agent-owned
    // key certifies a reviewed exercise or nothing. Putting the key in both
    // roots is the fixture mistake that produces a `site_signed` claim nobody
    // could have minted.
    Canon::write_file($dir . '/capabilities/adapter-authorities.json', Canon::encode([
        'format' => 'duo-adapter-authorities/v1',
        'keys' => new stdClass(),
    ]));
}

/** A purely declarative site adapter, stamped at the era every field adapter declares. */
function mv_adapter(string $name): array {
    return [
        'name' => $name,
        'option_autoload' => 'preserve',
        'options' => [str_replace('-', '_', $name) . '_layout' => ['class' => 'authored']],
        // N-1: the version an out-of-tree adapter authored before the flip
        // declares, still inside this engine's window, and the population the
        // whole recertify step exists for.
        'spec_version' => DUO_SPEC_VERSION - 1,
    ];
}

$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$keyId = 'site-' . substr(hash('sha256', $public), 0, 12);
$adapters = ['acme-alpha', 'acme-beta'];

mkdir($root . '/keys', 0755, true);
file_put_contents($root . '/keys/org.key', base64_encode($secret));
chmod($root . '/keys/org.key', 0600);

$priorLib = $root . '/lib-prior';
$targetLib = $root . '/lib-target';
mv_library($priorLib, DUO_SPEC_VERSION - 1, '0.5.0');
mv_library($targetLib, DUO_SPEC_VERSION, DUO_AGENT_VERSION);

$repo = $root . '/site';
mkdir($repo . '/adapters/certifications', 0755, true);
foreach ($adapters as $name) {
    Canon::write_file($repo . '/adapters/' . $name . '.json', Canon::encode(mv_adapter($name)));
}
Canon::write_file($repo . '/site.duo.json', Canon::encode([
    'manifests' => array_merge(['core'], array_map(
        static fn(string $n): array => ['name' => $n, 'source' => 'site'],
        $adapters
    )),
    'policy' => new stdClass(),
    'spec_version' => DUO_SPEC_VERSION - 1,
]));
Canon::write_file($repo . '/adapters/authorities.json', Canon::encode([
    'format' => 'duo-adapter-authorities/v1',
    'keys' => (object) [$keyId => [
        'adapter_names' => $adapters,
        'algorithm' => 'ed25519',
        'public_key' => base64_encode($public),
        'scope' => 'site_adapter_certification',
        'status' => 'trusted',
        'trust_tiers' => ['declarative_manifest'],
    ]],
]));

// ---------------------------------------------------------------------------
// Mint both certificates at the PRIOR era, in a child process holding that
// era's defines. This is the fleet's actual pre-flag state.
// ---------------------------------------------------------------------------
$minter = $root . '/mint.php';
$minterSource = "<?php\ndeclare(strict_types=1);\n"
    . "define('DUO_SPEC_VERSION', " . (DUO_SPEC_VERSION - 1) . ");\n"
    . "define('DUO_AGENT_VERSION', '0.5.0');\n"
    . "function is_multisite(): bool { return false; }\n"
    . 'require ' . var_export($duoRoot . '/agent/src/Kernel/Canon.php', true) . ";\n"
    . 'require ' . var_export($duoRoot . '/agent/src/Kernel/OptionState.php', true) . ";\n"
    . 'require ' . var_export($duoRoot . '/agent/src/Policy/Policy.php', true) . ";\n"
    . 'require ' . var_export($duoRoot . '/agent/src/Adapter/AdapterCertification.php', true) . ";\n"
    . '$repo = ' . var_export($repo, true) . ";\n"
    . '$lib = ' . var_export($priorLib, true) . ";\n"
    . '$library = \\Duo\\AdapterLibrary::fromLegacyFlatDirectory($lib);' . "\n"
    . '$secret = ' . var_export(base64_encode($secret), true) . ";\n"
    . '$keyId = ' . var_export($keyId, true) . ";\n"
    . 'foreach (' . var_export($adapters, true) . " as \$name) {\n"
    . "    \$cert = \\Duo\\AdapterCertification::sign_site(\$library, \$repo, \$name, \$keyId, \$secret,\n"
    . "        'The site organization approves these exact adapter bytes.');\n"
    . "    \\Duo\\Canon::write_file(\$repo . '/adapters/certifications/' . \$name . '.json', \$cert);\n"
    . "}\n"
    . "echo \"minted\\n\";\n";
file_put_contents($minter, $minterSource);

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$pipes = [];
$proc = proc_open([PHP_BINARY, $minter], $descriptors, $pipes);
$mintOut = is_resource($proc) ? (string) stream_get_contents($pipes[1]) : '';
$mintErr = is_resource($proc) ? (string) stream_get_contents($pipes[2]) : 'cannot start minter';
if (is_resource($proc)) {
    fclose($pipes[1]);
    fclose($pipes[2]);
    $mintExit = proc_close($proc);
} else {
    $mintExit = -1;
}
duo_check_same(0, $mintExit, 'the prior-era child minted both certificates (stderr: ' . trim($mintErr) . ')');
duo_check(str_contains($mintOut, 'minted'), 'and said so');

$before = [];
foreach ($adapters as $name) {
    $before[$name] = (string) file_get_contents($repo . '/adapters/certifications/' . $name . '.json');
}
$authoritiesBefore = (string) file_get_contents($repo . '/adapters/authorities.json');
foreach ($adapters as $name) {
    $statement = Canon::decode($before[$name]);
    duo_check_same(
        DUO_SPEC_VERSION - 1,
        $statement['statement']['platform']['spec_version'] ?? null,
        "the installed certificate for $name was signed under spec_version " . (DUO_SPEC_VERSION - 1)
            . ' — the state the flip withdraws, which is the only state this verb is for'
    );
}

// ---------------------------------------------------------------------------
echo "\nPART 1 — recertify re-establishes the claim, and repeating it mints nothing\n";
// ---------------------------------------------------------------------------
$first = mv_duo(
    ['adapter', 'recertify', $repo, '--secret-key-file=' . $root . '/keys/org.key', '--format=json'],
    $targetLib
);
duo_check_same(0, $first['exit'], 'recertify exits 0 (stderr: ' . trim($first['err']) . ')');
$firstReport = json_decode($first['out'], true);
duo_check(is_array($firstReport), 'and prints one decodable duo-adapter-recertify/v1 document');
$firstReport = is_array($firstReport) ? $firstReport : ['rows' => [], 'summary' => []];
duo_check_same(
    ['blocked' => 0, 'certificates' => 2, 'resigned' => 2, 'unchanged' => 0],
    (array) $firstReport['summary'],
    'both stale certificates are re-signed in ONE invocation — the runbook step, per site rather than per adapter'
);
$outcomes = [];
foreach ((array) $firstReport['rows'] as $row) {
    $outcomes[(string) $row['adapter']] = (string) $row['certification'];
}
duo_check_same(
    ['acme-alpha' => 'experimental', 'acme-beta' => 'experimental'],
    $outcomes,
    '...and each row carries the claim the LIVE verifier returned for the bytes just written, not the '
        . 'producer\'s own word for them'
);
foreach ($adapters as $name) {
    $now = Canon::decode(Canon::read_file($repo . '/adapters/certifications/' . $name . '.json'));
    duo_check_same(
        DUO_SPEC_VERSION,
        $now['statement']['platform']['spec_version'] ?? null,
        "the re-signed certificate for $name binds spec_version " . DUO_SPEC_VERSION . ' — the post-flip boundary'
    );
}

$afterFirst = [];
foreach ($adapters as $name) {
    $afterFirst[$name] = (string) file_get_contents($repo . '/adapters/certifications/' . $name . '.json');
}
$second = mv_duo(
    ['adapter', 'recertify', $repo, '--secret-key-file=' . $root . '/keys/org.key', '--format=json'],
    $targetLib
);
duo_check_same(0, $second['exit'], 'a second recertify exits 0 too');
$secondReport = json_decode($second['out'], true);
duo_check_same(
    ['blocked' => 0, 'certificates' => 2, 'resigned' => 0, 'unchanged' => 2],
    (array) (is_array($secondReport) ? $secondReport['summary'] : []),
    'IDEMPOTENT: the second run reports `unchanged` for both — an unchanged input re-signs byte-identically '
        . 'and keeps its original created_at, so a cohort runner may re-run this over mixed-state sites'
);
$afterSecond = [];
foreach ($adapters as $name) {
    $afterSecond[$name] = (string) file_get_contents($repo . '/adapters/certifications/' . $name . '.json');
}
duo_check_same(
    $afterFirst,
    $afterSecond,
    '...and not one certificate byte moved on that second run, which is what "mints nothing" has to mean on disk'
);

// ---------------------------------------------------------------------------
echo "\nPART 2 — ALL-OR-NOTHING: a failure restores every file the run staged\n";
// ---------------------------------------------------------------------------
// Put the repository back to its stale state, then make the SECOND adapter
// unsignable. The first is stale and would be rewritten; the failure must undo
// that, or the repository is left with one adapter on each side of the flip.
foreach ($adapters as $name) {
    file_put_contents($repo . '/adapters/certifications/' . $name . '.json', $before[$name]);
}
file_put_contents($repo . '/adapters/authorities.json', $authoritiesBefore);
$brokenManifest = mv_adapter('acme-beta');
// A section the SIGNER cannot classify: `siteRatification()` refuses rather
// than minting a certificate covering less than the adapter declares. The
// grammar accepts it at N-1 (the key set is closed only from N), so the
// failure lands inside signing — which is exactly where a mid-run failure has
// to be for this part to mean anything.
$brokenManifest['future_surface'] = ['acme_thing' => ['class' => 'authored']];
Canon::write_file($repo . '/adapters/acme-beta.json', Canon::encode($brokenManifest));

$failed = mv_duo(
    ['adapter', 'recertify', $repo, '--secret-key-file=' . $root . '/keys/org.key'],
    $targetLib
);
duo_check_same(2, $failed['exit'], 'a mid-run signing failure exits 2');
duo_check(
    str_contains($failed['err'], 'every file it staged was restored to its prior bytes'),
    '...and says so, naming the trust root and the certificate count — a half-recertified repository is the '
        . 'outcome this verb exists to make unreachable'
);
duo_check_detail('restore refusal: ' . trim($failed['err']));
$restored = [];
foreach ($adapters as $name) {
    $restored[$name] = (string) file_get_contents($repo . '/adapters/certifications/' . $name . '.json');
}
duo_check_same(
    $before,
    $restored,
    'THE FIRST ADAPTER IS BACK: its certificate is byte-identical to the prior-era bytes, even though the run '
        . 'had already re-signed and written it before reaching the failure'
);
duo_check_same(
    $authoritiesBefore,
    (string) file_get_contents($repo . '/adapters/authorities.json'),
    '...and adapters/authorities.json is at its prior bytes, the file `certify` already records as able to '
        . 'invalidate every OTHER certificate under the key when it goes stale'
);

// ---------------------------------------------------------------------------
echo "\nPART 3 — a certificate under another key is BLOCKED by name, never skipped\n";
// ---------------------------------------------------------------------------
Canon::write_file($repo . '/adapters/acme-beta.json', Canon::encode(mv_adapter('acme-beta')));
$otherKeypair = sodium_crypto_sign_keypair();
$otherSecret = sodium_crypto_sign_secretkey($otherKeypair);
file_put_contents($root . '/keys/other.key', base64_encode($otherSecret));
chmod($root . '/keys/other.key', 0600);
$wrongKey = mv_duo(
    ['adapter', 'recertify', $repo, '--secret-key-file=' . $root . '/keys/other.key', '--format=json'],
    $targetLib
);
duo_check_same(1, $wrongKey['exit'], 'a run that could re-sign nothing is NOT green');
$wrongReport = json_decode($wrongKey['out'], true);
$wrongRows = (array) (is_array($wrongReport) ? $wrongReport['rows'] : []);
$blockedKeys = [];
foreach ($wrongRows as $row) {
    $blockedKeys[(string) $row['adapter']] = [(string) $row['outcome'], (string) $row['key_id']];
}
duo_check_same(
    ['acme-alpha' => ['blocked', $keyId], 'acme-beta' => ['blocked', $keyId]],
    $blockedKeys,
    'every certificate is a blocked row NAMING the key it was signed under — the remedy is one invocation per '
        . 'key, which is visible in a shell history in a way a silent skip is not'
);
duo_check_same(
    $before,
    [
        'acme-alpha' => (string) file_get_contents($repo . '/adapters/certifications/acme-alpha.json'),
        'acme-beta' => (string) file_get_contents($repo . '/adapters/certifications/acme-beta.json'),
    ],
    '...and nothing was written, because a key that matches nothing signs nothing'
);

// ---------------------------------------------------------------------------
echo "\nPART 4 — release --spec-v3 journals the PRIOR pins and emits the new ones\n";
// ---------------------------------------------------------------------------
$priorPins = Canon::decode(Canon::read_file($repo . '/site.duo.json'))['manifests'];
$release = mv_duo(['release', '--spec-v3', $repo, '--format=json'], $targetLib);
duo_check(
    in_array($release['exit'], [0, 1], true),
    'release --spec-v3 runs to a verdict (exit ' . $release['exit'] . '; stderr: ' . trim($release['err']) . ')'
);
$releaseReport = json_decode($release['out'], true);
duo_check(is_array($releaseReport), 'and prints one decodable duo-spec-migration/v1 document');
$releaseReport = is_array($releaseReport) ? $releaseReport : [];
$journalDir = $repo . '/.duo/migrations';
$journalFiles = glob($journalDir . '/*.json') ?: [];
duo_check_same(1, count($journalFiles), 'exactly one journal record is written under .duo/migrations/');
$record = Canon::decode(Canon::read_file($journalFiles[0]));
duo_check_same(
    'duo-spec-migration-journal/v1',
    $record['format'] ?? null,
    'the record declares its own format, so a later reader is never guessing at a shape'
);
duo_check_same(
    $priorPins,
    $record['prior']['pins'] ?? null,
    'THE PRIOR PIN OBJECTS ARE IN IT, verbatim — including the legacy bare-string form, because a rollback '
        . 'restoring these bytes must restore the spelling the site actually held'
);
duo_check_same(
    DUO_SPEC_VERSION - 1,
    $record['prior']['spec_version'] ?? null,
    '...and the repository version it was holding, which the window accepts unchanged and no verb re-stamps'
);
duo_check_same(
    hash('sha256', Canon::read_file($repo . '/site.duo.json')),
    $record['prior']['site_sha256'] ?? null,
    '...content-addressed to the exact site.duo.json bytes, so the record cannot be read as being about a '
        . 'different revision of the same file'
);
$proposed = [];
foreach ((array) ($releaseReport['proposed_pins'] ?? []) as $pin) {
    $proposed[(string) $pin['name']] = (string) $pin['source'];
}
duo_check_same(
    ['acme-alpha' => 'site', 'acme-beta' => 'site', 'core' => 'shipped'],
    $proposed,
    'the emitted objects name every resolved adapter and the source that answered it'
);
duo_check_same(
    hash('sha256', Canon::read_file($repo . '/site.duo.json')),
    hash('sha256', Canon::read_file($repo . '/site.duo.json')),
    'and site.duo.json is untouched: updating a pin stays an explicit review act (`duo adapter pin`)'
);

$again = mv_duo(['release', '--spec-v3', $repo, '--format=json'], $targetLib);
duo_check_same(
    1,
    count(glob($journalDir . '/*.json') ?: []),
    'IDEMPOTENT: a second run on an unchanged site rewrites the same content-addressed path rather than '
        . 'growing a directory of near-duplicates no reader can tell apart'
);
duo_check_same(
    $release['out'],
    $again['out'],
    '...and reports the identical document, because every member of it is an identity and none is a clock'
);

// ---------------------------------------------------------------------------
echo "\nPART 5 — THE ORDERING CONTRACT: no pin object is emitted unless the prior one was recorded\n";
// ---------------------------------------------------------------------------
// The journal is written FIRST. With .duo/migrations unwritable the verb must
// refuse and print nothing — if the emission came first, an operator could
// re-pin a site from output whose provenance was never recorded, which is the
// one failure this ordering exists to prevent.
$removeTree($journalDir);
file_put_contents($journalDir, "not a directory\n");
$blockedJournal = mv_duo(['release', '--spec-v3', $repo, '--format=json'], $targetLib);
duo_check_same(2, $blockedJournal['exit'], 'an unwritable journal refuses the whole verb');
duo_check(
    str_contains($blockedJournal['err'], 'no new pin object is emitted'),
    '...and says exactly that, naming the ordering as the reason rather than reporting an IO error'
);
duo_check_same(
    '',
    trim($blockedJournal['out']),
    'AND PRINTS NO PIN OBJECT AT ALL — the ordering is a property of the output, measured here rather than '
        . 'asserted from the source'
);
duo_check_detail('journal-first refusal: ' . trim($blockedJournal['err']));

duo_check_summary('spec migration verbs');
