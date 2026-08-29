<?php
/**
 * Offline contract for WP-5.3 / spec/repo-format.md § v3.17: the signing
 * profile that accepts a disposition the AUTHOR wrote.
 *
 * WHAT WAS MEASURED, AND WHY IT NEEDED A RIDER
 * --------------------------------------------
 * `AdapterCertification::siteRatification()` derives every field of a site
 * certificate's claim from the manifest: `deletion_semantics.supported: []`,
 * `lifecycle_phases: []`, `evidence.tests: []`, `operations` without `delete`,
 * and one canned sentence on every refusal it emits. So `--reason` was the only
 * human input in a document the shipped validator requires a SEPARATE non-empty
 * prose reason on every `unsupported[]` row and every
 * `default_authored_keyspaces[]` row of (`ManifestDispositions::validate_entry()`).
 * A site organization that had genuinely reviewed its adapter's deletion
 * semantics signed the same sentences as one that had reviewed nothing.
 *
 * WHAT THIS SUITE IS FOR — the three claims, in the order they matter
 * ------------------------------------------------------------------
 * **1. Both profiles produce a certificate the LIVE verifier accepts.** The
 * derived floor still stands for an author who supplies no file (nothing about
 * it is a lesser certificate), and an authored entry with per-refusal prose
 * signs, verifies, and reaches the claim projection carrying the author's own
 * words — including two things the derivation structurally cannot say: a
 * reviewed deletion selector and a `justified` authored keyspace.
 *
 * **2. Every semantic refusal is the SHIPPED validator's, not a second one.**
 * The cases below drive a blank refusal reason, a section the manifest does not
 * declare, an intent-only table left unmarked, a widened version range, a cited
 * test the bundle does not hold, an `experimental` status and a boilerplate
 * entry that refuses nothing — and each is asserted on the sentence
 * `ManifestDispositions` already raised for a shipped registry row. That is the
 * property being federated: shipped code with no notion of who wrote the bytes.
 * The two refusals the profile ADDS are asserted beside them and are about
 * SCOPE only (a surface omitted, a surface named under the wrong arm), because
 * `claim_from_disposition()` builds the claim's `surfaces` list from those two
 * lists and an omitted section is a surface blocked later with nothing saying
 * why.
 *
 * **3. A refusal writes nothing, and the flag day cannot silently re-derive.**
 * `certify` restores the trust root and leaves no certificate when the authored
 * entry is refused; `wprism adapter recertify` — which derives — reports an
 * authored certificate as a `blocked` row naming the remedy instead of
 * replacing the site's own argument with the canned floor under the site's own
 * key.
 *
 * The refusal matrix runs against `sign_site()` in-process (it is what the verb
 * calls, and a subprocess per case would pay a CLI boot for a sentence the
 * signer owns); the two profiles, the file-reading refusals and one end-to-end
 * semantic refusal run through `wprism adapter certify` itself, so the flag's
 * plumbing and the wording an operator actually meets are both measured.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$wprismRoot = dirname(__DIR__, 4);
require_once $wprismRoot . '/cli/src/Adapter/AdapterCertify.php';
require_once $wprismRoot . '/cli/src/Adapter/SpecMigration.php';

use WPrism\AdapterCertification;
use WPrism\AdapterLibrary;
use WPrism\AdapterSources;
use WPrism\Canon;
use WPrism\Policy;
use WPrism\Orchestrator\AdapterCertify;
use WPrism\Orchestrator\SpecMigration;

// Boot the engine into this WordPress-free process exactly as the verb does.
// No setAccessible(): a no-op since PHP 8.1, DEPRECATED in 8.5, and this repo
// floors at >=8.3.
(new ReflectionMethod(AdapterCertify::class, 'boot'))->invoke(null);

// --------------------------------------------------------------------- harness

function ar_rmtree(string $path): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach ((array) scandir($path) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        ar_rmtree($path . '/' . $entry);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . '/wprism_authored_ratification_' . bin2hex(random_bytes(6));
mkdir($root, 0755, true);
register_shutdown_function(static fn() => ar_rmtree($root));

/** @param array<string,mixed> $manifest */
function ar_site(string $root, string $label, array $manifest): string {
    $repo = $root . '/' . $label;
    mkdir($repo . '/adapters', 0755, true);
    Canon::write_file($repo . '/adapters/' . $manifest['name'] . '.json', Canon::encode($manifest));
    Canon::write_file($repo . '/site.wprism.json', Canon::encode([
        'manifests' => ['core'],
        // An EMPTY JSON object, deliberately: PHP erases {} vs [] on an
        // associative round trip and the engine refuses the list form.
        'policy' => new stdClass(),
        'spec_version' => WPRISM_SPEC_VERSION,
    ]));

    return $repo;
}

/**
 * Run `wprism adapter <args>` in a child process, for the cases whose SENTENCE
 * matters: refusals are written to the STDERR constant, which this process
 * cannot rebind.
 *
 * @param list<string> $args
 * @return array{exit:int,out:string,err:string}
 */
function ar_cli(array $args): array {
    global $wprismRoot;
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $wprismRoot . '/cli/wprism', 'adapter'], $args),
        $descriptors,
        $pipes
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

/**
 * The ratified entry as the SIGNED STATEMENT carries it.
 *
 * Read out of the certificate rather than out of `verifyFile()`'s
 * `disposition` — that one is the Policy slot `derivedDisposition()` builds and
 * carries `reason`/`status`/proof, not the capability document. The question
 * here is what the signature covers, so the assertion is made against the bytes
 * the signature covers.
 *
 * @return array<string,mixed>
 */
function ar_ratified(string $repo, string $name): array {
    $raw = (string) file_get_contents(AdapterCertification::certificatePath($repo, $name));
    $decoded = json_decode($raw, true);
    $entry = $decoded['statement']['ratification']['manifests'][$name] ?? null;

    return is_array($entry) ? $entry : [];
}

/**
 * A manifest exercising every direction `validate_entry()` checks a disposition
 * in: a declared plugin (so `supported_versions` must mirror its contract), two
 * entity sections, three field sections, a `default_class: authored` keyspace
 * that must appear one-for-one in `default_authored_keyspaces`, and an
 * intent-only table that MUST be marked unsupported. A fixture that dodged any
 * of them would let a real adapter fail where this passed.
 */
$manifest = [
    'name' => 'acme-ledger',
    'option_autoload' => 'preserve',
    'option_namespaces' => [['match' => '^acme_ledger_']],
    'options' => ['acme_ledger_layout' => ['class' => 'authored']],
    'plugin' => 'acme-ledger/acme-ledger.php',
    'post_meta' => ['_acme_ledger_ref' => ['class' => 'authored']],
    'post_types' => ['acme_entry' => ['class' => 'authored']],
    'spec_version' => WPRISM_SPEC_VERSION,
    'tables' => [
        'acme_ledger_index' => [
            'class' => 'authored_snapshot',
            'columns' => ['label' => ['class' => 'authored']],
            'default_class' => 'authored',
            'identity' => ['mode' => 'mapped'],
            'pk' => 'id',
        ],
        'acme_ledger_intent' => [
            'class' => 'authored_typed_snapshot_post_v1',
            'columns' => ['label' => ['class' => 'authored']],
            'identity' => ['mode' => 'mapped'],
            'pk' => 'id',
        ],
    ],
    'version_range' => ['max' => '3.0.0', 'min' => '1.0.0'],
];
$name = 'acme-ledger';

/**
 * The entry an author writes: the same document shape
 * `manifests/dispositions/<name>.json` carries, with a reason on every refusal.
 *
 * Two of its claims are ones the derivation cannot make at all — a reviewed
 * deletion selector and a `justified` open-ended keyspace — which is what makes
 * this a claim a reader can weigh rather than a restatement of the manifest.
 *
 * @return array<string,mixed>
 */
function ar_entry(): array {
    return [
        'capabilities' => [
            'deletion_semantics' => [
                'supported' => ['post_types.acme_entry'],
                'unsupported' => ['tables.acme_ledger_index', 'tables.acme_ledger_intent'],
            ],
            'entity_sections' => ['post_types', 'tables'],
            'field_sections' => ['option_namespaces', 'options', 'post_meta'],
            'lifecycle_phases' => ['activate', 'retire'],
            'operations' => ['apply', 'capture', 'compile', 'delete', 'deploy', 'plan', 'recapture'],
        ],
        'default_authored_keyspaces' => [[
            'reason' => 'Acme reviewed acme_ledger_index against the 1.0.0-3.0.0 schema diff: every column a '
                . 'release in that range introduces is declared, so an unclassified key means a version '
                . 'outside the range rather than a silent gap.',
            'status' => 'justified',
            'table' => 'acme_ledger_index',
        ]],
        'evidence' => [
            'bundle_schema' => AdapterCertification::BUNDLE_FORMAT,
            'tests' => [],
        ],
        'reason' => 'Acme Ltd reviewed this adapter against its own ledger schema and exercised entry '
            . 'deletion on a staging clone; the sections below record what that review did and did not cover.',
        'status' => 'certified',
        'supported_versions' => [
            'plugin' => 'acme-ledger/acme-ledger.php',
            'range' => ['max' => '3.0.0', 'min' => '1.0.0'],
        ],
        'unsupported' => [
            [
                'operation' => 'apply',
                'reason' => 'acme_ledger_intent is an intent-only table: Acme writes rows there to request work '
                    . 'from the plugin, and the plugin rewrites them, so converging it across environments '
                    . 'would replay a request that was already served.',
                'surface' => 'tables.acme_ledger_intent',
            ],
            [
                'operation' => 'delete',
                'reason' => 'Ledger index rows are deleted by the plugin on its own schedule and carry no '
                    . 'tombstone Acme could reconcile, so a repository deletion is not propagated.',
                'surface' => 'deletions.tables.acme_ledger_index',
            ],
        ],
    ];
}

/**
 * Write an authored entry the way an author would: hand-formatted, NOT
 * canonical. The signer wraps the decoded entry in the envelope it owns and
 * canonicalizes that, so the author's formatting reaches no signature — which
 * is why `certify` does not rewrite this file the way it rewrites the adapter.
 *
 * @param array<string,mixed> $entry
 */
function ar_write_entry(string $path, array $entry): string {
    $raw = (string) json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    file_put_contents($path, $raw);

    return $raw;
}

$keyDir = $root . '/keys';
mkdir($keyDir, 0755, true);
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);
$keyId = 'site-' . substr(hash('sha256', $public), 0, 12);
$keyPath = $keyDir . '/acme.key';
file_put_contents($keyPath, base64_encode($secret) . "\n");
chmod($keyPath, 0600);

$manifestDir = AdapterLibrary::fromSourceTree($wprismRoot);
$reason = 'Acme Ltd approves these exact adapter bytes.';

// ---------------------------------------------------- 1. the derived floor stands

$floorRepo = ar_site($root, 'floor', $manifest);
$floorRun = ar_cli([
    'certify', $floorRepo, '--name=' . $name, '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $reason,
]);
wprism_check_same(0, $floorRun['exit'], 'certify with NO --ratification-file still signs: the derivation is the floor, not a fallback');
wprism_check(
    str_contains($floorRun['out'], 'claim basis: DERIVED'),
    'and says which profile signed it, because recertify treats the two differently'
);

$floorVerified = AdapterCertification::verifyFile(
    $manifestDir,
    $floorRepo,
    $name,
    $manifest,
    AdapterCertification::certificatePath($floorRepo, $name)
);
wprism_check_same('experimental', $floorVerified['claim']['status'] ?? null, 'and the live verifier keeps it below certification without exercise');
$floorDisposition = ar_ratified($floorRepo, $name);
wprism_check_same(
    [],
    $floorDisposition['capabilities']['deletion_semantics']['supported'] ?? null,
    'the derived floor claims no reviewed deletion selector — a grammar verdict reviews no deletion semantics'
);
wprism_check_same(
    [],
    $floorDisposition['capabilities']['lifecycle_phases'] ?? null,
    'and no lifecycle phase, for the same reason'
);
wprism_check_same(
    'unsupported',
    $floorDisposition['default_authored_keyspaces'][0]['status'] ?? null,
    'and records the open-ended authored keyspace unsupported: justification is a review judgement nobody made'
);

// ----------------------------------------- 2. an authored entry signs and verifies

$authoredRepo = ar_site($root, 'authored', $manifest);
$entryPath = $root . '/acme-ledger-disposition.json';
$entryRaw = ar_write_entry($entryPath, ar_entry());
$authoredRun = ar_cli([
    'certify', $authoredRepo, '--name=' . $name, '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $reason, '--ratification-file=' . $entryPath,
]);
wprism_check_same(0, $authoredRun['exit'], 'an author-written entry with per-refusal prose signs');
wprism_check(
    str_contains($authoredRun['out'], 'claim basis: AUTHORED (--ratification-file)'),
    'and the verb reports the authored profile rather than presenting it as the derived one'
);
wprism_check(
    hash_equals($entryRaw, (string) file_get_contents($entryPath)),
    'the author\'s file is not rewritten: its formatting reaches no signature, unlike the adapter\'s bytes'
);

$authoredVerified = AdapterCertification::verifyFile(
    $manifestDir,
    $authoredRepo,
    $name,
    $manifest,
    AdapterCertification::certificatePath($authoredRepo, $name)
);
$disposition = is_array($authoredVerified['disposition'] ?? null) ? $authoredVerified['disposition'] : [];
$claim = is_array($authoredVerified['claim'] ?? null) ? $authoredVerified['claim'] : [];
wprism_check_same('experimental', $claim['status'] ?? null, 'the LIVE verifier accepts the authored signature without elevating it');
wprism_check_same(
    ar_entry()['reason'],
    $disposition['reason'] ?? null,
    'the author\'s own basis is what the certificate carries, not the operator\'s one-line --reason'
);
$ratified = ar_ratified($authoredRepo, $name);
wprism_check(
    hash_equals(Canon::encode(ar_entry()), Canon::encode($ratified)),
    'the SIGNED entry is the authored one whole — no member re-derived, normalised or dropped on the way in'
);
wprism_check_same(
    ar_entry()['unsupported'][0]['reason'],
    $ratified['unsupported'][0]['reason'] ?? null,
    'and each refusal carries its OWN prose, inside the signed statement — the gap that made this rider: '
    . 'the derivation stamps one canned sentence on every refusal it emits'
);
wprism_check_same(
    'justified',
    $ratified['default_authored_keyspaces'][0]['status'] ?? null,
    'an authored entry can justify an open-ended keyspace, which the derivation cannot do at all'
);
wprism_check_same(
    ['activate', 'retire'],
    $ratified['capabilities']['lifecycle_phases'] ?? null,
    'and can state a reviewed lifecycle phase, which the derivation leaves empty by construction'
);
wprism_check(
    in_array('deletions.post_types.acme_entry', (array) ($claim['surfaces'] ?? []), true),
    'the reviewed deletion selector reaches the CLAIM projection, where a reader meets it'
);
wprism_check(
    in_array('delete', (array) ($claim['operations'] ?? []), true),
    'and so does the operation it supports'
);
wprism_check_same(
    false,
    $claim['evidence']['exercised'] ?? null,
    'while the bundle still records exercised: false — an authored claim is a stronger ARGUMENT, never evidence of a run'
);
wprism_check_same(
    [],
    $claim['evidence']['tests'] ?? null,
    'and cites no test, because the unexercised bundle holds none'
);
wprism_check_same(
    'site',
    $claim['certification']['trust_root'] ?? null,
    'under the site trust root, while exercised:false keeps the claim below Site-certified'
);

// --------------------------- 3. the refusal matrix, driven through the signer

$refuseRepo = ar_site($root, 'refuse', $manifest);
(new ReflectionMethod(AdapterCertify::class, 'registerAuthority'))->invokeArgs(null, [
    $refuseRepo, $keyId, $public, $name, AdapterSources::trust_tier($manifest),
]);

/**
 * @param callable(array<string,mixed>):array<string,mixed> $mutate
 */
$signWith = static function (callable $mutate) use ($manifestDir, $refuseRepo, $name, $keyId, $secret, $reason): void {
    AdapterCertification::sign_site(
        $manifestDir,
        $refuseRepo,
        $name,
        $keyId,
        base64_encode($secret),
        $reason,
        $mutate(ar_entry())
    );
};

// A control first: the same call with the unmutated entry SUCCEEDS, so every
// refusal below is attributable to its mutation and not to the harness.
$controlCertificate = AdapterCertification::sign_site(
    $manifestDir,
    $refuseRepo,
    $name,
    $keyId,
    base64_encode($secret),
    $reason,
    ar_entry()
);
wprism_check(
    str_contains($controlCertificate, AdapterCertification::FORMAT),
    'control: the unmutated authored entry signs through sign_site() itself'
);

// The refusals the SHIPPED validator already raised for a reviewed registry
// row, each asserted on its own sentence. `''` rather than whitespace: the
// per-row check is `=== ''` where the entry-level `reason` is `trim(…) === ''`,
// so "blank" here means exactly what validate_entry() means by it.
wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['unsupported'][0]['reason'] = '';
        return $entry;
    }),
    RuntimeException::class,
    'a blank refusal reason is refused — in validate_entry()\'s own words, the rule that made this rider necessary',
    "manifest disposition 'acme-ledger' unsupported[0] is malformed"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['capabilities']['entity_sections'][] = 'widgets';
        return $entry;
    }),
    RuntimeException::class,
    'a section the manifest does not declare is refused by the SHIPPED validator, not by the profile',
    "manifest disposition 'acme-ledger' names absent manifest section 'widgets'"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        // Over-claim: drop the intent-only table's refusal and the entry now
        // claims convergence for a table the manifest declares as a request.
        $entry['unsupported'] = [$entry['unsupported'][1]];
        return $entry;
    }),
    RuntimeException::class,
    'over-claiming an intent-only table is refused: the manifest\'s own class declaration outranks the author',
    "must mark intent-only table 'acme_ledger_intent' unsupported"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['supported_versions']['range']['max'] = '9.9.9';
        return $entry;
    }),
    RuntimeException::class,
    'over-claiming a version range the manifest does not declare is refused',
    "manifest disposition 'acme-ledger' versions disagree with its manifest contract"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        // The boilerplate shape: prose everywhere and not one refusal.
        $entry['unsupported'] = [];
        return $entry;
    }),
    RuntimeException::class,
    'an entry that refuses NOTHING is malformed to the shipped validator — a certified claim states its limits',
    "manifest disposition 'acme-ledger' has a malformed required field"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['evidence']['tests'] = ['conformance-acme-ledger'];
        return $entry;
    }),
    RuntimeException::class,
    'citing a test the unexercised bundle does not hold is refused: named evidence must exist',
    "cites absent or non-passing bundle test 'conformance-acme-ledger'"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['status'] = 'experimental';
        return $entry;
    }),
    RuntimeException::class,
    'a non-certified status is refused by validate_external_entry() itself',
    "external manifest disposition 'acme-ledger' must be a certified entry"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['default_authored_keyspaces'] = [];
        return $entry;
    }),
    RuntimeException::class,
    'omitting the open-ended authored keyspace is refused — the tripwire has to be answered, either way',
    "manifest disposition 'acme-ledger' omits default authored keyspace 'acme_ledger_index'"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['default_authored_keyspaces'][0]['reason'] = '';
        return $entry;
    }),
    RuntimeException::class,
    'and a keyspace justified with no prose is refused the same way a refusal without prose is',
    "manifest disposition 'acme-ledger' has malformed default authored keyspace evidence"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['note'] = 'a member the disposition vocabulary does not carry';
        return $entry;
    }),
    RuntimeException::class,
    'an invented member is refused by the exact-key check the site profile already applied',
    "site adapter disposition 'acme-ledger'"
);

// The two rules the PROFILE adds. Both are about scope, and neither is a
// judgement about the strength of a claim.
wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['capabilities']['field_sections'] = ['option_namespaces', 'post_meta'];
        return $entry;
    }),
    RuntimeException::class,
    'silently omitting a declared surface is refused: narrow with an unsupported[] row a reader can weigh',
    "authored site adapter disposition 'acme-ledger' omits declared field section 'options'"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['capabilities']['field_sections'][] = 'post_types';
        return $entry;
    }),
    RuntimeException::class,
    'a surface named under the wrong arm is refused: the arm decides what the claim\'s surfaces list means',
    "names 'post_types' as a field section, which this manifest's vocabulary classifies as an entity section"
);

wprism_check_throws(
    static fn() => $signWith(static function (array $entry): array {
        $entry['capabilities']['field_sections'][] = 'plugin';
        return $entry;
    }),
    RuntimeException::class,
    'and so is a manifest key that declares no state surface — validate_entry() only asks whether the key EXISTS',
    "names 'plugin' as a field section, which declares no state surface of its own"
);

// ------------------------------- 4. the verb surfaces the refusal and writes nothing

$blankRepo = ar_site($root, 'blank', $manifest);
$blankEntry = ar_entry();
$blankEntry['unsupported'][0]['reason'] = '';
$blankPath = $root . '/blank-disposition.json';
ar_write_entry($blankPath, $blankEntry);
$blankRun = ar_cli([
    'certify', $blankRepo, '--name=' . $name, '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--reason=' . $reason, '--ratification-file=' . $blankPath,
]);
wprism_check_same(2, $blankRun['exit'], 'certify exits 2 on a refused authored entry');
wprism_check(
    str_contains($blankRun['err'], 'unsupported[0] is malformed'),
    'and surfaces the engine\'s own sentence rather than a CLI paraphrase'
);
wprism_check(
    !is_file(AdapterCertification::certificatePath($blankRepo, $name)),
    'a refused certify writes no certificate'
);
wprism_check(
    !is_file($blankRepo . '/' . AdapterCertify::AUTHORITIES_RELATIVE),
    'and leaves no trust root behind — a failed certify leaves the repository as it found it'
);

$absentRun = ar_cli([
    'certify', $blankRepo, '--name=' . $name, '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--ratification-file=' . $root . '/nothing-here.json',
]);
wprism_check_same(2, $absentRun['exit'], '--ratification-file naming no file is a usage error');
wprism_check(
    str_contains($absentRun['err'], '--ratification-file must be a regular non-symlink file'),
    'and says so about the path, not about the disposition grammar'
);

$listPath = $root . '/list-disposition.json';
file_put_contents($listPath, "[\n  \"not an object\"\n]\n");
$listRun = ar_cli([
    'certify', $blankRepo, '--name=' . $name, '--secret-key-file=' . $keyPath,
    '--key-id=' . $keyId, '--ratification-file=' . $listPath,
]);
wprism_check_same(2, $listRun['exit'], 'a JSON array is not a disposition entry');
wprism_check(
    str_contains($listRun['err'], 'must be a JSON object'),
    'and the author meets a sentence about the document they wrote'
);

// ------------------------------ 5. recertify may not silently re-derive a claim

/**
 * @param list<string> $args
 * @return array{exit:int,report:array<string,mixed>}
 */
function ar_recertify(array $args): array {
    ob_start();
    $exit = SpecMigration::run($args);
    $out = (string) ob_get_clean();
    $decoded = json_decode($out, true);

    return ['exit' => $exit, 'report' => is_array($decoded) ? $decoded : []];
}

$floorRecertify = ar_recertify(['recertify', $floorRepo, '--secret-key-file=' . $keyPath, '--format=json']);
wprism_check_same(0, $floorRecertify['exit'], 'recertify still re-signs a DERIVED certificate');
wprism_check_same(
    'unchanged',
    $floorRecertify['report']['rows'][0]['outcome'] ?? null,
    'and is idempotent over it, exactly as before this rider'
);

$authoredBefore = (string) file_get_contents(AdapterCertification::certificatePath($authoredRepo, $name));
$authoredRecertify = ar_recertify(['recertify', $authoredRepo, '--secret-key-file=' . $keyPath, '--format=json']);
wprism_check_same(1, $authoredRecertify['exit'], 'recertify does NOT re-sign an authored certificate');
wprism_check_same(
    'blocked',
    $authoredRecertify['report']['rows'][0]['outcome'] ?? null,
    'it reports a blocked row: re-deriving here would replace the site\'s own argument with the canned floor'
);
wprism_check(
    str_contains(
        (string) ($authoredRecertify['report']['rows'][0]['detail'] ?? ''),
        '--ratification-file'
    ),
    'and names the remedy — the verb that took the file in the first place'
);
wprism_check(
    hash_equals($authoredBefore, (string) file_get_contents(AdapterCertification::certificatePath($authoredRepo, $name))),
    'the authored certificate\'s bytes are untouched by the attempt'
);

wprism_check_summary('regress_authored_ratification');
