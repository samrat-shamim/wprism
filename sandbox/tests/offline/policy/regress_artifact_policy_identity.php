<?php
/**
 * Offline regression for ArtifactPolicyIdentity (issue #3348 slice 30).
 *
 * RepositoryCompiler's policy-bound artifact identity has no repository
 * traversal, target access, or compiler-builder state: it derives the site
 * and manifest hashes plus resolved adapter records from an already validated
 * Policy. This suite loads that collaborator directly, checks the historical
 * RepositoryCompiler entry points remain exact compatibility facades, and
 * compares the manifest row against the independent capability-registry
 * digest implementation over the shipped library.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();

function is_multisite(): bool {
    return false;
}

require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';

use WPrism\ArtifactPolicyIdentity;
use WPrism\Canon;
use WPrism\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$check(
    class_exists(\WPrism\Policy::class, false)
        && class_exists(\WPrism\Canon::class, false)
        && class_exists(\WPrism\AdapterSources::class, false)
        && class_exists(\WPrism\ManifestDispositions::class, false)
        && !class_exists(\WPrism\RepositoryCompiler::class, false),
    'ArtifactPolicyIdentity directly loads its policy identity stack without pulling in the repository-tree compiler'
);

require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';

use WPrism\RepositoryCompiler;

// Pinned explicitly rather than taking the default single-manifest load: the
// digest folds below are only exercised by adapters that declare them — acf
// carries an interpreter, woocommerce manifest-sourced providers, and
// the-events-calendar post_type regenerators — so a `core`-only policy would
// hash three absent keys and prove nothing about the walk.
try {
    $policy = Policy::load(null, ['core', 'acf', 'woocommerce', 'the-events-calendar']);
    $check(true, 'the shipped manifest policy loads through the standalone identity collaborator');
} catch (Throwable $e) {
    $check(false, 'the shipped manifest policy loads through the standalone identity collaborator (' . $e->getMessage() . ')');
}

if (isset($policy)) {
    $check(
        ArtifactPolicyIdentity::site_hash($policy) === RepositoryCompiler::site_hash($policy),
        'site_hash(): RepositoryCompiler remains an exact compatibility facade'
    );
    $check(
        ArtifactPolicyIdentity::state_site_hash($policy) === RepositoryCompiler::state_site_hash($policy),
        'state_site_hash(): RepositoryCompiler remains an exact compatibility facade'
    );
    $check(
        ArtifactPolicyIdentity::manifest_hash($policy) === RepositoryCompiler::manifest_hash($policy),
        'manifest_hash(): RepositoryCompiler remains an exact compatibility facade'
    );
    $check(
        ArtifactPolicyIdentity::resolved_adapters($policy) === RepositoryCompiler::resolved_adapters($policy),
        'resolved_adapters(): RepositoryCompiler remains an exact compatibility facade'
    );

    $rows = ArtifactPolicyIdentity::manifest_rows($policy);
    $check(
        count($rows) === count($policy->manifests) && $rows !== [],
        'manifest_rows(): one content-addressed identity row is produced for every pinned manifest'
    );
    $check(
        $policy->manifest_disposition('core') !== null,
        'direct identity loading includes reviewed manifest dispositions rather than hashing an incomplete policy projection'
    );
    // THE definition of adapter identity, and now the only one. It used to be
    // mirrored by CapabilityRegistry::adapter_digest(), which re-walked the
    // same interpreter/provider/regenerator folds so it could hash a manifest
    // without loading a compiler; this assertion compared the two walks. With
    // the mirror deleted the row IS the digest, so what has to be pinned
    // instead is that every consumer reads that one derivation — asserted
    // against the compiled row rather than against a second local copy of the
    // rule, which would just re-create the drift the deletion removed.
    $resolved = ArtifactPolicyIdentity::resolved_adapters($policy);
    $resolvedByName = [];
    foreach ($resolved as $resolvedRow) {
        $resolvedByName[(string) $resolvedRow['name']] = $resolvedRow;
    }
    $digestMismatch = [];
    foreach ($rows as $identityRow) {
        $name = (string) $identityRow['name'];
        if (($resolvedByName[$name]['digest'] ?? null) !== hash('sha256', Canon::encode($identityRow))) {
            $digestMismatch[] = $name;
        }
    }
    $check(
        $digestMismatch === [] && $rows !== [],
        'manifest_rows(): every reported adapter digest is exactly its own identity row hashed, across all '
            . count($rows) . ' shipped adapters (mismatched: ' . implode(', ', $digestMismatch) . ')'
    );
    // The folds are the part a second walk got wrong: an interpreter name and
    // its file bytes, manifest-sourced provider bytes, and the de-duplicated,
    // name-sorted regenerator bytes. At least one shipped adapter must exercise
    // each, or the assertion above is hashing three absent keys.
    $foldsSeen = [];
    foreach ($rows as $identityRow) {
        foreach (['interpreter', 'providers', 'regenerators'] as $fold) {
            if (isset($identityRow[$fold])) {
                $foldsSeen[$fold] = true;
            }
        }
    }
    $check(
        isset($foldsSeen['interpreter'], $foldsSeen['providers'], $foldsSeen['regenerators']),
        'and the shipped set really does exercise all three executable folds, so the digest is not being proved over '
            . 'rows that carry none of them'
    );
    foreach ([
        ['acf', 'interpreters', 'acf'],
        ['woocommerce', 'providers', 'woocommerce-cache'],
        ['the-events-calendar', 'regenerators', 'the-events-calendar'],
    ] as [$adapter, $kind, $id]) {
        $descriptor = ArtifactPolicyIdentity::runtime_component_descriptor($policy, $adapter, $kind, $id);
        $check(
            ($descriptor['adapter'] ?? null) === $adapter
                && ($descriptor['kind'] ?? null) === $kind
                && ($descriptor['id'] ?? null) === $id
                && is_string($descriptor['sha256'] ?? null)
                && hash_file('sha256', (string) ($descriptor['file'] ?? '')) === $descriptor['sha256']
                && ($descriptor['adapter_sha256'] ?? null) === ($resolvedByName[$adapter]['digest'] ?? null)
                && ($descriptor['class'] ?? null) === \WPrism\AdapterLibrary::runtimeClassName($kind, $id),
            "runtime_component_descriptor(): $adapter/$kind/$id carries the sole path, class, component digest, "
                . 'and adapter digest consumed by loading'
        );
    }
    $regeneratorRow = null;
    foreach ($rows as $identityRow) {
        if (isset($identityRow['regenerators']) && count($identityRow['regenerators']) > 1) {
            $regeneratorRow = $identityRow;
        }
    }
    if (is_array($regeneratorRow)) {
        $regeneratorNames = array_column($regeneratorRow['regenerators'], 'name');
        $sortedNames = $regeneratorNames;
        sort($sortedNames, SORT_STRING);
        $check(
            $regeneratorNames === $sortedNames
                && count(array_unique($regeneratorNames)) === count($regeneratorNames),
            'the regenerator fold stays de-duplicated and name-sorted — post_types{} is a map whose key order Canon '
                . 'normalizes away, so discovery order in this list would make the digest depend on nothing'
        );
    }
}

$compilerSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php');
$check(
    substr_count($compilerSource, 'ArtifactPolicyIdentity::site_hash($policy)') === 1
        && substr_count($compilerSource, 'ArtifactPolicyIdentity::state_site_hash($policy)') === 1
        && substr_count($compilerSource, 'ArtifactPolicyIdentity::manifest_rows($policy)') === 1
        && substr_count($compilerSource, 'ArtifactPolicyIdentity::manifest_hash($policy)') === 1
        && substr_count($compilerSource, 'ArtifactPolicyIdentity::resolved_adapters($policy)') === 1,
    'RepositoryCompiler delegates every extracted identity entry point instead of retaining a duplicate implementation'
);

$policySource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Policy/Policy.php');
$providersSource = (string) file_get_contents(__DIR__ . '/../../../../agent/src/Adapter/Providers.php');
$check(
    substr_count($policySource, 'ManifestExecutableLoader::load(') === 2
        && substr_count($providersSource, 'ManifestExecutableLoader::load(') === 1
        && !str_contains($policySource, 'require_once $file;')
        && !str_contains($providersSource, 'require_once $file;'),
    'providers, interpreters, and regenerators all cross the one manifest executable loader; no raw engine loading route remains'
);

echo "\n== manifest executable loader adversarial process matrix ==\n";
$probe = __DIR__ . '/../../fixtures/manifest-executable-loader-probe.php';
foreach ([
    'routes' => 'all three product routes load their descriptor-bound class',
    'drift-interpreters' => 'interpreter digest drift refuses before package marker execution',
    'drift-providers' => 'provider digest drift refuses before package marker execution',
    'drift-regenerators' => 'regenerator digest drift refuses before package marker execution',
    'occupancy-class' => 'an ambient class cannot preempt a provider descriptor',
    'occupancy-interface' => 'an ambient interface cannot occupy an interpreter class name',
    'occupancy-trait' => 'an ambient trait cannot occupy a regenerator class name',
    'occupancy-enum' => 'an ambient enum cannot occupy a provider class name',
    'autoload' => 'ambient autoload is never consulted for a manifest executable symbol',
    'reuse' => 'the same descriptor reuses one load while a different descriptor for that class refuses',
    'opcache-seam' => 'configured opcode caching refuses when status is unavailable and invalidation fails',
    'opcache-live' => 'the descriptor loader succeeds with CLI opcode caching enabled when the extension is available',
    'opcache-restricted' => 'a restricted opcode API emits only the controlled loader refusal',
    'collisions' => 'normalized class collisions in every runtime kind refuse independently of discovery order',
] as $scenario => $message) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $command = [PHP_BINARY];
    if (in_array($scenario, ['opcache-live', 'opcache-restricted'], true)) {
        array_push($command, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1');
    }
    if ($scenario === 'opcache-restricted') {
        array_push($command, '-d', 'opcache.restrict_api=/definitely/not/wprism');
    }
    array_push($command, $probe, $scenario);
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        $check(false, "$message (could not start isolated process)");
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    $check(
        $exitCode === 0 && $stdout === "PASS $scenario\n" && $stderr === '',
        $message . ($stderr === '' ? '' : " ($stderr)")
    );
}

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}

echo "\nALL PASSED\n";
