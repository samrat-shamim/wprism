<?php
/**
 * Offline regression for ArtifactPolicyIdentity (DUO-3348 slice 30).
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

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

function is_multisite(): bool {
    return false;
}

require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';

use Duo\ArtifactPolicyIdentity;
use Duo\Canon;
use Duo\Policy;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$check(
    class_exists(\Duo\Policy::class, false)
        && class_exists(\Duo\Canon::class, false)
        && class_exists(\Duo\AdapterSources::class, false)
        && class_exists(\Duo\ManifestDispositions::class, false)
        && !class_exists(\Duo\RepositoryCompiler::class, false),
    'ArtifactPolicyIdentity directly loads its policy identity stack without pulling in the repository-tree compiler'
);

require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';

use Duo\RepositoryCompiler;

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

if ($failures !== []) {
    fwrite(STDERR, "\nFAILED " . count($failures) . " assertion(s)\n");
    exit(1);
}

echo "\nALL PASSED\n";
