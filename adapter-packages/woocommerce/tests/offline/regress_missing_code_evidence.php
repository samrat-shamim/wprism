<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
require_once $root . '/agent/src/Kernel/PrivateRefusalEvidence.php';
require_once dirname(__DIR__, 2) . '/fixtures/missing-code-evidence.php';

$failure = null;
try {
    WPrism\LifecyclePlanner::deployment_status_from_observation(new WPrism\Policy(),
        ['active_plugins' => ['woocommerce/woocommerce.php']], false,
        ['recorded_raw' => null, 'plugins' => [], 'active_plugins' => [], 'plugin_exists' => []]);
} catch (RuntimeException $caught) { $failure = $caught; }
wprism_check($failure instanceof RuntimeException, 'actual lifecycle preflight refuses absent declared Woo code');
$profile = WooCommerceMissingCodeEvidence::profile();
WPrismTest\PrivateRefusalReceipt::assertGraph(WPrism\PrivateRefusalEvidence::graph($failure), $profile['nodes']);
wprism_check(true, 'independently declared cause matches the actual product refusal graph');
$directory = sys_get_temp_dir() . '/woo-missing-code-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$write = static function (string $stage, array $record, string $exit = "0\n", string $stderr = '') use ($directory): void {
    foreach (['stdout' => json_encode($record, JSON_THROW_ON_ERROR), 'stderr' => $stderr, 'exit' => $exit] as $suffix => $bytes) {
        file_put_contents("$directory/$stage.$suffix", $bytes); chmod("$directory/$stage.$suffix", 0600);
    }
};
$record = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'lifecycle-status',
    'reason_code' => 'lifecycle_status_failed', ...WPrism\PrivateRefusalEvidence::graph($failure)];
$name = '20260915-090001-lifecycle-status-' . str_repeat('b', 24) . '.json';
$diagnostic = static function (array $record) use ($name): array {
    $bytes = json_encode($record, JSON_THROW_ON_ERROR);
    return ['command' => 'lifecycle-status', 'format' => 'wprism-private-refusal-diagnostic/v1', 'new_records' => 1,
        'purpose' => 'diagnostic_only', 'verified' => false, 'records' => [[
            'bytes' => strlen($bytes), 'contents_base64' => base64_encode($bytes), 'name' => $name, 'sha256' => hash('sha256', $bytes),
        ]]];
};
$baseline = ['command' => 'lifecycle-status', 'baseline' => '[]'];
try {
    $write('baseline', $baseline); $write('private', $diagnostic($record));
    WooCommerceMissingCodeEvidence::verify($directory, 'woorefusal');
    wprism_check(true, 'fresh complete private evidence for the product cause passes');
    foreach (['wrong-cause', 'wrong-command', 'incomplete', 'stale', 'missing', 'multiple', 'collector-failed', 'diagnostics'] as $fault) {
        $bad = $record; $base = $baseline;
        if ($fault === 'wrong-cause') $bad = array_replace($bad, WPrism\PrivateRefusalEvidence::graph(new RuntimeException('unrelated safety gate')));
        if ($fault === 'wrong-command') $bad['command'] = 'apply';
        if ($fault === 'incomplete') $bad['traversal']['record_complete'] = false;
        if ($fault === 'stale') $base['baseline'] = json_encode([$name]);
        $captured = $diagnostic($bad);
        if ($fault === 'missing') { $captured['new_records'] = 0; $captured['records'] = []; }
        if ($fault === 'multiple') { $captured['new_records'] = 2; $captured['records'][] = $captured['records'][0]; }
        $write('baseline', $base);
        $write('private', $captured, $fault === 'collector-failed' ? "1\n" : "0\n", $fault === 'diagnostics' ? "PHP Warning: diagnostic\n" : '');
        wprism_check_throws(static fn() => WooCommerceMissingCodeEvidence::verify($directory, 'woorefusal'), RuntimeException::class,
            "missing-code evidence rejects $fault");
    }
} finally {
    foreach (glob($directory . '/*') ?: [] as $file) unlink($file);
    rmdir($directory);
}
wprism_check_summary('WooCommerce missing-code lifecycle evidence');
