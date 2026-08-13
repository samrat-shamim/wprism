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

require_once __DIR__ . '/../../agent/src/ArtifactPolicyIdentity.php';

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
        && class_exists(\Duo\CapabilityRegistry::class, false)
        && !class_exists(\Duo\RepositoryCompiler::class, false),
    'ArtifactPolicyIdentity directly loads its policy identity stack without pulling in the repository-tree compiler'
);

require_once __DIR__ . '/../../agent/src/RepositoryCompiler.php';

use Duo\CapabilityRegistry;
use Duo\RepositoryCompiler;

try {
    $policy = Policy::load(null);
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
    $first = $rows[0] ?? null;
    if (is_array($first)) {
        $manifest = $first['manifest'] ?? null;
        $disposition = $first['disposition'] ?? null;
        $check(
            is_array($manifest)
                && hash('sha256', Canon::encode($first)) === CapabilityRegistry::adapter_digest($manifest, is_array($disposition) ? $disposition : null),
            'manifest_rows(): row bytes remain identical to the independent capability-registry digest contract'
        );
    }
}

$compilerSource = (string) file_get_contents(__DIR__ . '/../../agent/src/RepositoryCompiler.php');
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
