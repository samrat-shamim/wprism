<?php
declare(strict_types=1);

// Only the existing test-gated capture barrier is changed. A stale marker
// cannot be silently cleared into a fresh overlap premise.
if (count($args) !== 1 || !in_array($args[0], ['phase', 'release'], true)) throw new RuntimeException('unknown native concurrency operation');
$phase = (string) \WPrism\Ledger::kv_get('capture_test_phase');
if (!in_array($phase, ['', 'locked', 'release'], true)) throw new RuntimeException('native capture phase malformed');
if ($args[0] === 'release') {
    if ($phase !== 'locked') throw new RuntimeException('native capture holder is no longer paused');
    \WPrism\Ledger::kv_set('capture_test_phase', 'release');
    $phase = (string) \WPrism\Ledger::kv_get('capture_test_phase');
}
echo json_encode(['format' => 'wprism-map-concurrency-phase/v1', 'phase' => $phase], JSON_THROW_ON_ERROR);
