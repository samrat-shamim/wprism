<?php

declare(strict_types=1);

$helper = realpath(__DIR__ . '/../../fixtures/private-refusal-evidence.php');
if (!is_string($helper)) {
    fwrite(STDERR, "FAIL: Rank Math private-refusal helper is missing\n");
    exit(1);
}
require_once $helper;

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
$writeRecord = static function (string $path, string $profileName, string $message): void {
    $profile = rank_math_private_refusal_profile($profileName);
    $record = [
        'format' => 'wprism-private-refusal-evidence/v2',
        'recorded_at' => '2026-09-03T16:45:19Z',
        'command' => $profile['command'],
        'reason_code' => $profile['reason_code'],
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

$empty = $run(['snapshot', 'schema-loss', $directory]);
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
$writeRecord($directory . '/' . $staleName, 'schema-loss', $message);
$baseline = $run(['snapshot', 'schema-loss', $directory]);
$baselineJson = json_encode([$staleName], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(
    $baseline['exit'] === 0 && trim($baseline['stdout']) === $baselineJson && $baseline['stderr'] === '',
    'snapshot inventories pre-existing command-scoped evidence canonically without reading its contents'
);

$noAppend = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$check(
    $noAppend['exit'] !== 0
        && str_contains($noAppend['stderr'], 'did not append exactly one schema-status refusal record'),
    'a stale matching record cannot prove an invocation that appended nothing'
);

$writeRecord($directory . '/' . $newName, 'schema-loss', $message);
$verified = $run(['verify', 'schema-loss', $directory, $baselineJson]);
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

$writeRecord($directory . '/' . $newName, 'schema-loss', 'wrong private cause canary');
$wrongCause = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$check(
    $wrongCause['exit'] !== 0
        && str_contains($wrongCause['stderr'], 'does not retain the exact complete root cause')
        && !str_contains($wrongCause['stderr'], 'wrong private cause canary'),
    'a wrong private cause refuses without echoing private record values'
);
$writeRecord($directory . '/' . $newName, 'schema-loss', $message);
$writeRecord($directory . '/' . $extraName, 'schema-loss', $message);
$twoAppends = $run(['verify', 'schema-loss', $directory, $baselineJson]);
$check(
    $twoAppends['exit'] !== 0
        && str_contains($twoAppends['stderr'], 'did not append exactly one schema-status refusal record'),
    'two appended records cannot be collapsed into one apparent proof by timestamp order'
);

$malformedBaseline = $run(['verify', 'schema-loss', $directory, '["not-a-record.json"]']);
$check(
    $malformedBaseline['exit'] !== 0
        && str_contains($malformedBaseline['stderr'], 'baseline contains a noncanonical record name'),
    'a caller cannot inject an unscoped filename into the baseline'
);

$missingCodeBaseline = $run(['snapshot', 'missing-code', $directory]);
$check(
    $missingCodeBaseline['exit'] === 0
        && $missingCodeBaseline['stdout'] === "[]\n"
        && $missingCodeBaseline['stderr'] === '',
    'command-scoped inventory excludes the existing schema-status records'
);
$missingCodeName = '20260903-164521-lifecycle-status-' . str_repeat('d', 24) . '.json';
$missingCodeMessage = rank_math_private_refusal_profile('missing-code')['message'];
$writeRecord($directory . '/' . $missingCodeName, 'missing-code', $missingCodeMessage);
$missingCodeVerified = $run(['verify', 'missing-code', $directory, '[]']);
$check(
    $missingCodeVerified['exit'] === 0
        && $missingCodeVerified['stdout'] === rank_math_private_refusal_receipt('missing-code') . "\n"
        && !str_contains($missingCodeVerified['stdout'], $missingCodeMessage)
        && hash('sha256', $missingCodeMessage)
            === '9145307bebd452be68d85b17d6bcd43b48921710b4a3f4fd9ffd0010d7690d93',
    'the lifecycle profile proves the exact private missing-code cause without disclosing it'
);
$virginSchemaBaseline = $run(['snapshot', 'virgin-schema', $directory]);
$check(
    $virginSchemaBaseline['exit'] === 0
        && $virginSchemaBaseline['stdout'] === "[]\n"
        && $virginSchemaBaseline['stderr'] === '',
    'plan-scoped inventory excludes schema-status and lifecycle-status records'
);
$virginSchemaName = '20260903-164522-plan-' . str_repeat('e', 24) . '.json';
$virginSchemaMessage = rank_math_private_refusal_profile('virgin-schema')['message'];
$writeRecord($directory . '/' . $virginSchemaName, 'virgin-schema', $virginSchemaMessage);
$virginSchemaVerified = $run(['verify', 'virgin-schema', $directory, '[]']);
$check(
    $virginSchemaVerified['exit'] === 0
        && $virginSchemaVerified['stdout'] === rank_math_private_refusal_receipt('virgin-schema') . "\n"
        && !str_contains($virginSchemaVerified['stdout'], $virginSchemaMessage)
        && hash('sha256', $virginSchemaMessage)
            === '4a8208927399b3863b0973d34410b2fd71bfc406d286d10dc14de0b24763ff76',
    'the plan profile proves the exact private virgin-schema cause without disclosing it'
);
$unknownProfile = $run(['snapshot', 'not-a-profile', $directory]);
$check(
    $unknownProfile['exit'] !== 0
        && str_contains($unknownProfile['stderr'], 'unknown verification profile'),
    'an unknown verifier profile refuses before inspecting private storage'
);

echo $failures === 0
    ? "PASS: Rank Math private refusal evidence (13 assertions)\n"
    : "FAIL: Rank Math private refusal evidence ($failures failures)\n";
exit($failures === 0 ? 0 : 1);
