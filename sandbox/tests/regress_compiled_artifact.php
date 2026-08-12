<?php
/**
 * Offline regression for CompiledArtifact.php (DUO-3348 slice 2: the
 * CompiledRepository/RepositoryCompilationException value objects, moved out
 * of RepositoryCompiler.php into their own file). Pure file relocation, no
 * logic change — existing suites (regress_repository_compiler.sh,
 * regress_plan_explain.php, regress_capture_atomicity.php, etc.) already
 * exercise these classes in depth through the real compiler; this file is
 * deliberately narrow: it proves agent/src/CompiledArtifact.php is
 * independently loadable (no WordPress/DB/docker) and that the basic
 * create/export/write/from_array round-trip and hash-verification refusals
 * still work when required standalone, without the rest of RepositoryCompiler.
 */
declare(strict_types=1);

// CompiledArtifact.php deliberately does not require Canon.php itself (see its
// own docblock) -- callers that need Canon::encode()/write_file() to actually
// run (as this test's create()/export()/write() calls do) must require it
// themselves.
require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/CompiledArtifact.php';

use Duo\CompiledRepository;
use Duo\RepositoryCompilationException;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$payload = [
    'tree' => ['u1' => ['type' => 'post', 'data' => ['type' => 'page']]],
    'deletions' => [],
    'revision_hash' => str_repeat('a', 64),
    'manifest_hash' => str_repeat('b', 64),
    'site_hash' => str_repeat('c', 64),
];

$compiled = CompiledRepository::create($payload);
$check($compiled->tree() === $payload['tree'], 'create(): tree is preserved verbatim');
$check($compiled->deletions() === [], 'create(): empty deletions default');
$check(preg_match('/^[0-9a-f]{64}$/', $compiled->artifact_hash()) === 1, 'create(): artifact_hash is a sha256 hex digest');
$check($compiled->code_descriptor() === null, 'create(): no code key means no code descriptor');
$check($compiled->code_revision() === null, 'create(): no code descriptor means no code revision');
$check($compiled->resolved_adapters() === [], 'create(): resolved_adapters defaults to empty');
$check($compiled->uploads_inventory() === [], 'create(): no attachments means empty uploads inventory');
$check($compiled->effects_inventory() === [], 'create(): effects_inventory defaults to empty list');

$exported = $compiled->export();
$check($exported['format'] === CompiledRepository::FORMAT, 'export(): carries the versioned format');
$check($exported['artifact_hash'] === $compiled->artifact_hash(), 'export(): artifact_hash matches the accessor');

$roundTripped = CompiledRepository::from_array($exported);
$check($roundTripped->artifact_hash() === $compiled->artifact_hash(), 'from_array(): round-trips the exact same artifact_hash');
$check($roundTripped->tree() === $compiled->tree(), 'from_array(): round-trips the exact same tree');

$tmp = tempnam(sys_get_temp_dir(), 'duo-compiled-artifact-test-');
$compiled->write($tmp);
$writtenRoundTrip = CompiledRepository::from_array(json_decode(file_get_contents($tmp), true));
$check($writtenRoundTrip->artifact_hash() === $compiled->artifact_hash(), 'write(): file round-trips through from_array() with the same hash');
unlink($tmp);

$tamperedExport = $exported;
$tamperedExport['tree']['u1']['data']['type'] = 'post_tampered';
$threwOnTamper = false;
try {
    CompiledRepository::from_array($tamperedExport);
} catch (\RuntimeException $e) {
    $threwOnTamper = str_contains($e->getMessage(), 'content hash does not verify');
}
$check($threwOnTamper, 'from_array(): a tampered tree fails hash verification, not silently accepted');

$missingUploadsInventory = $exported;
unset($missingUploadsInventory['uploads_inventory']);
$threwOnMissingInventory = false;
try {
    CompiledRepository::from_array($missingUploadsInventory);
} catch (\RuntimeException $e) {
    // Removing uploads_inventory changes the payload the hash was computed
    // over, so this is refused as a hash mismatch before the inventory check
    // itself is ever reached -- both are fail-closed, either is acceptable.
    $threwOnMissingInventory = str_contains($e->getMessage(), 'content hash does not verify')
        || str_contains($e->getMessage(), 'upload inventory does not match');
}
$check($threwOnMissingInventory, 'from_array(): a missing/mismatched uploads_inventory is refused, not silently derived');

$refusal = new RepositoryCompilationException([
    ['code' => 'bad_ref', 'path' => 'state/posts/page/x.md', 'locator' => 'terms.category', 'message' => 'unknown uuid'],
]);
$check($refusal->payload()['error'] === 'repository_compilation_failed', 'RepositoryCompilationException: exact error code');
$check(count($refusal->payload()['diagnostics']) === 1, 'RepositoryCompilationException: diagnostics carried verbatim');
$check(str_contains($refusal->getMessage(), '[bad_ref] state/posts/page/x.md:terms.category — unknown uuid'),
    'RepositoryCompilationException: human message names path, locator, and message');

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall CompiledArtifact checks passed\n";
exit(0);
