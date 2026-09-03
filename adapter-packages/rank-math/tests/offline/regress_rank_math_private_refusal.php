<?php

declare(strict_types=1);

$helper = realpath(__DIR__ . '/../../fixtures/private-schema-loss-evidence.php');
if (!is_string($helper)) {
    fwrite(STDERR, "FAIL: Rank Math private-refusal helper is missing\n");
    exit(1);
}

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    fwrite(STDERR, "FAIL: $message\n");
    $failures++;
};
$run = static function (array $arguments) use ($helper): array {
    $process = proc_open(
        array_merge([PHP_BINARY, $helper], $arguments),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return ['exit' => 127, 'stdout' => '', 'stderr' => 'process launch failed'];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            $removeTree($path . '/' . $name);
        }
    }
    @rmdir($path);
};
$writeRecord = static function (string $path, string $message): void {
    $record = [
        'format' => 'wprism-private-refusal-evidence/v2',
        'recorded_at' => '2026-09-03T16:45:19Z',
        'command' => 'schema-status',
        'reason_code' => 'schema_status_failed',
        'throwable' => [[
            'index' => 0,
            'parent_index' => null,
            'relation' => 'root',
            'message' => $message,
            'message_encoding' => 'utf-8',
            'message_original_bytes' => strlen($message),
            'message_sha256' => hash('sha256', $message),
            'message_truncated' => false,
        ]],
        'traversal' => ['scan_complete' => true, 'record_complete' => true],
    ];
    file_put_contents(
        $path,
        json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    chmod($path, 0600);
};

$scratch = sys_get_temp_dir() . '/wprism-rank-math-private-refusal-' . bin2hex(random_bytes(8));
$directory = $scratch . '/.wprism/refusals';
register_shutdown_function(static function () use ($scratch, $removeTree): void {
    $removeTree($scratch);
});

$empty = $run(['snapshot', $directory]);
$check(
    $empty['exit'] === 0 && $empty['stdout'] === "[]\n" && $empty['stderr'] === '',
    'a missing refusal directory has one canonical nonempty empty-list baseline'
);

mkdir($directory, 0700, true);
chmod($directory, 0700);
$staleName = '20260903-164449-schema-status-' . str_repeat('a', 24) . '.json';
$newName = '20260903-164519-schema-status-' . str_repeat('b', 24) . '.json';
$extraName = '20260903-164520-schema-status-' . str_repeat('c', 24) . '.json';
$message = "wprism: schema-settle table 'rank_math_redirections' is absent but durable identity history remains; "
    . 'restore the database-matched table instead of preparing an empty replacement';
$writeRecord($directory . '/' . $staleName, $message);
$baseline = $run(['snapshot', $directory]);
$baselineJson = json_encode([$staleName], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(
    $baseline['exit'] === 0 && trim($baseline['stdout']) === $baselineJson && $baseline['stderr'] === '',
    'snapshot inventories pre-existing command-scoped evidence canonically without reading its contents'
);

$noAppend = $run(['verify', $directory, $baselineJson]);
$check(
    $noAppend['exit'] !== 0
        && str_contains($noAppend['stderr'], 'did not append exactly one schema-status refusal record'),
    'a stale matching record cannot prove an invocation that appended nothing'
);

$writeRecord($directory . '/' . $newName, $message);
$verified = $run(['verify', $directory, $baselineJson]);
$expectedReceipt = json_encode([
    'command' => 'schema-status',
    'format' => 'wprism-rank-math-private-refusal-check/v1',
    'new_records' => 1,
    'root_message_sha256' => hash('sha256', $message),
    'verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
$check(
    $verified['exit'] === 0 && $verified['stdout'] === $expectedReceipt && $verified['stderr'] === '',
    'one appended complete v2 graph yields the fixed value-free verification receipt'
);
$check(
    !str_contains($verified['stdout'], $newName) && !str_contains($verified['stdout'], $message),
    'the verification receipt discloses neither the private filename nor its root sentence'
);

$writeRecord($directory . '/' . $newName, 'wrong private cause canary');
$wrongCause = $run(['verify', $directory, $baselineJson]);
$check(
    $wrongCause['exit'] !== 0
        && str_contains($wrongCause['stderr'], 'does not retain the exact complete root cause')
        && !str_contains($wrongCause['stderr'], 'wrong private cause canary'),
    'a wrong private cause refuses without echoing private record values'
);
$writeRecord($directory . '/' . $newName, $message);
$writeRecord($directory . '/' . $extraName, $message);
$twoAppends = $run(['verify', $directory, $baselineJson]);
$check(
    $twoAppends['exit'] !== 0
        && str_contains($twoAppends['stderr'], 'did not append exactly one schema-status refusal record'),
    'two appended records cannot be collapsed into one apparent proof by timestamp order'
);

$malformedBaseline = $run(['verify', $directory, '["not-a-record.json"]']);
$check(
    $malformedBaseline['exit'] !== 0
        && str_contains($malformedBaseline['stderr'], 'baseline contains a noncanonical record name'),
    'a caller cannot inject an unscoped filename into the baseline'
);

echo $failures === 0
    ? "PASS: Rank Math private schema-loss refusal evidence (8 assertions)\n"
    : "FAIL: Rank Math private schema-loss refusal evidence ($failures failures)\n";
exit($failures === 0 ? 0 : 1);
