<?php
/**
 * Direct characterization for DeleteGuardValueCodec (DUO-3347): the
 * fail-closed representation boundary used by deletion guards before they
 * decide that a live reference is absent or explicitly repaired.
 *
 * The broader product-path regression remains
 * regress_woocommerce_deletion_authority.php: it drives the codec through
 * Apply's manifest-declared metadata guard, witness, and lock sequence.
 * This small direct suite pins the codec's ownership and the dangerous
 * malformed-value cases without needing a database or duplicating plan SQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Delete/DeleteGuardValueCodec.php';

use Duo\DeleteGuardValueCodec;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$uuid = '11111111-1111-4111-8111-111111111111';
$token = "{{post:$uuid}}";

$check(
    DeleteGuardValueCodec::meta_value_ids(serialize(['42', 7]), ['ref' => 'post[]']) === [42, 7],
    'canonical serialized list resolves only exact positive ids'
);
$check(
    DeleteGuardValueCodec::meta_value_ids(serialize([42]) . 'i:7;', ['ref' => 'post[]']) === null,
    'serialized target value with trailing bytes is refused instead of partially decoded'
);
$check(
    DeleteGuardValueCodec::meta_value_ids(serialize([(object) ['id' => 42]]), ['ref' => 'post[]']) === null,
    'serialized object value is refused without becoming a reference answer'
);
$check(
    DeleteGuardValueCodec::meta_value_ids('42,0007', ['cast' => 'csv', 'ref' => 'post[]']) === [42, 7],
    'declared csv codec preserves the existing exact decimal parsing'
);
$check(
    DeleteGuardValueCodec::meta_value_ids('42,0', ['cast' => 'csv', 'ref' => 'post[]']) === null,
    'zero csv member is unsafe rather than an absent reference'
);

$check(
    DeleteGuardValueCodec::canonical_meta_ref_contains_uuid([$token], 'post[]', $uuid) === true,
    'canonical list recognizes only the requested valid identity token'
);
$check(
    DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(['{{post:ffffffff-ffff-ffff-ffff-ffffffffffff}}'], 'post[]', $uuid) === null,
    'invalid-variant canonical token is unsafe rather than a nonmatching repair'
);
$check(
    DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(null, 'post[]', $uuid, false) === false,
    'missing desired metadata is the explicit removal representation'
);
$check(
    DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(null, 'post[]', $uuid, true) === null,
    'present null desired metadata remains unsafe rather than an implicit repair'
);

$codec = new ReflectionClass(DeleteGuardValueCodec::class);
$check(
    (new ReflectionMethod(DeleteGuardValueCodec::class, 'meta_value_ids'))->isPublic()
        && (new ReflectionMethod(DeleteGuardValueCodec::class, 'canonical_meta_ref_contains_uuid'))->isPublic()
        && (new ReflectionMethod(DeleteGuardValueCodec::class, 'strict_unserialize'))->isPrivate()
        && $codec->getConstructor() === null,
    'codec exposes only the two pure guard contracts and has no runtime dependencies'
);

$applySource = file_get_contents(__DIR__ . '/../../agent/src/Delete/DeleteGuardLockCoordinator.php');
$scannerSource = file_get_contents(__DIR__ . '/../../agent/src/Delete/DeleteGuardReferenceScanner.php');
$check(
    str_contains($applySource, "require_once __DIR__ . '/DeleteGuardReferenceScanner.php';")
        && str_contains($scannerSource, "require_once __DIR__ . '/DeleteGuardValueCodec.php';")
        && str_contains($scannerSource, 'DeleteGuardValueCodec::meta_value_ids(')
        && str_contains($scannerSource, 'DeleteGuardValueCodec::canonical_meta_ref_contains_uuid('),
    'the deletion-guard scanner delegates both target-controlled representation decisions to the codec'
);
$check(
    !str_contains($applySource, 'private function meta_guard_value_ids(')
        && !str_contains($applySource, 'DeleteGuardValueCodec::meta_value_ids(')
        && !str_contains($applySource, 'DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(')
        && !str_contains($applySource, 'private function canonical_meta_ref_contains_uuid(')
        && !str_contains($applySource, 'private function strict_unserialize('),
    'the lock coordinator retains no duplicate deletion-guard value codec'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

echo "\nall DeleteGuardValueCodec checks passed\n";
