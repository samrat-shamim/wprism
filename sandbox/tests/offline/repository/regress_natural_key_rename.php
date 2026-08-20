<?php
/**
 * DUO-3237 offline boundary regression: natural_key is deterministic
 * bootstrap identity, while a retained UUID after a rename is ordinary
 * ledger continuity. The observation remains informational in status.
 */

require_once __DIR__ . '/../../../../agent/src/Kernel/Uuid.php';
// DUO-3318: the natural-key derivation IdentityNotes compares against is
// Snapshot's own (Policy owns the declaration grammar it reads), so that one
// derivation can never drift from the one capture actually used. Both are
// required here for that reason; neither touches a database, WordPress, or
// any other engine class along the two pure paths this file exercises.
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Snapshot.php';
require_once __DIR__ . '/../../../../agent/src/Repository/IdentityNotes.php';
require_once __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';

use Duo\IdentityNotes;
use Duo\Uuid;
use Duo\Orchestrator\PlanSummary;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$table = 'woocommerce_attribute_taxonomies';
$decl = [
    'id_kind' => 'attr_taxonomy',
    'identity' => ['mode' => 'natural_key', 'column' => 'attribute_name'],
];
$freshSlug = 'mergecert-size';
$renamedSlug = 'compact-size';
$uuid = Uuid::v5(Uuid::NAMESPACE_DUO, "$table:$freshSlug");

$check(
    IdentityNotes::natural_key_continuity($uuid, $table, $decl, ['attribute_name' => $freshSlug]) === null,
    'fresh UUIDv5 bootstrap identity was incorrectly described as a rename'
);
$expected = "$table row $renamedSlug: renamed since first capture (uuid retained via ledger)";
$check(
    IdentityNotes::natural_key_continuity($uuid, $table, $decl, ['attribute_name' => $renamedSlug]) === $expected,
    'retained UUID after a key edit lacks the exact continuity note'
);
$check(
    IdentityNotes::natural_key_continuity($uuid, $table, [
        'identity' => ['mode' => 'mapped'],
    ], ['attribute_name' => $renamedSlug]) === null,
    'mapped identity produced a natural_key note'
);
$check(
    IdentityNotes::natural_key_continuity($uuid, $table, $decl, ['attribute_label' => 'Compact Size']) === null,
    'missing identity column produced a note'
);

$plan = array_fill_keys([
    'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
    'collision', 'delete', 'delete_conflict', 'deleted',
], []);
$plan['update'][] = [
    'uuid' => $uuid,
    'type' => $table,
    'path' => "tables/$table/$uuid--$renamedSlug.json",
    'annotations' => [$expected],
];
$rendered = PlanSummary::render($plan);
$notes = array_values(array_filter(
    $rendered['lines'],
    static fn(string $line): bool => str_contains($line, $expected)
));
$check($notes === ['PLAN NOTE: ' . $expected], 'status did not render the continuity note exactly once');
$check($rendered['ok'] === true, 'informational continuity note changed readiness semantics');

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "ok: natural_key bootstrap and ledger rename continuity are distinguished without behavior changes\n";
