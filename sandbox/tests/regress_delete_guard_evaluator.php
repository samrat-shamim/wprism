<?php
/**
 * Direct characterization for DeleteGuardEvaluator (DUO-3347): the indexed
 * lock-boundary proof used before deletion-guard FOR UPDATE reads.
 *
 * The broader target-path regression remains
 * regress_woocommerce_deletion_authority.php. This suite isolates the
 * schema-shape decision, including its prefix-index refusal boundary.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
require_once __DIR__ . '/../../agent/src/DeleteGuardEvaluator.php';

use Duo\DeleteGuardEvaluator;

final class DeleteGuardEvaluatorFakeWpdb {
    /** @param list<array<string,mixed>> $indexRows */
    public function __construct(private array $indexRows) {
    }

    public function get_results(string $sql, $format = null): array {
        return $this->indexRows;
    }
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'wrong_first', 'Seq_in_index' => 1, 'Column_name' => 'post_id', 'Sub_part' => null],
    ['Key_name' => 'meta_key_value', 'Seq_in_index' => 2, 'Column_name' => 'meta_value', 'Sub_part' => null],
    ['Key_name' => 'meta_key_value', 'Seq_in_index' => 1, 'Column_name' => 'meta_key', 'Sub_part' => 191],
]);
$check(
    DeleteGuardEvaluator::lock_index(['meta_key' => '_children'], 'wp_postmeta') === 'meta_key_value',
    'metadata guard accepts a first-column prefix index that covers its complete key'
);
$check(
    DeleteGuardEvaluator::lock_index(['meta_key' => str_repeat('x', 192)], 'wp_postmeta') === null,
    'metadata guard refuses a too-short prefix index that could miss a concurrent key'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'option_name', 'Seq_in_index' => 1, 'Column_name' => 'option_name', 'Sub_part' => null],
]);
$check(
    DeleteGuardEvaluator::lock_index(['option_name_ref' => true, 'column' => 'ignored'], 'wp_options') === 'option_name',
    'option-name guard locks the declared option-name range rather than its incidental column'
);

$GLOBALS['wpdb'] = new DeleteGuardEvaluatorFakeWpdb([
    ['Key_name' => 'unsafe-name!', 'Seq_in_index' => 1, 'Column_name' => 'target_id', 'Sub_part' => null],
    ['Key_name' => 'later_target', 'Seq_in_index' => 2, 'Column_name' => 'target_id', 'Sub_part' => null],
]);
$check(
    DeleteGuardEvaluator::lock_index(['column' => 'target_id'], 'wp_refs') === 'unsafename',
    'ordinary scalar guards use a sanitized first-column lock index'
);

$evaluator = new ReflectionClass(DeleteGuardEvaluator::class);
$check(
    (new ReflectionMethod(DeleteGuardEvaluator::class, 'lock_index'))->isPublic()
        && (new ReflectionMethod(DeleteGuardEvaluator::class, 'lock_index'))->isStatic()
        && $evaluator->getConstructor() === null,
    'evaluator exposes a dependency-free static lock-boundary contract'
);

$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$check(
    str_contains($applySource, "require_once __DIR__ . '/DeleteGuardEvaluator.php';")
        && substr_count($applySource, 'DeleteGuardEvaluator::lock_index(') === 3,
    'Apply delegates every deletion-guard lock-index decision to the evaluator'
);
$check(
    !str_contains($applySource, 'private function guard_lock_index('),
    'Apply retains no duplicate lock-boundary evaluator'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

echo "\nall DeleteGuardEvaluator checks passed\n";
