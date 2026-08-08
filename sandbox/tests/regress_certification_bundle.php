<?php
/** Offline adversarial contract for certification-bundle.php. */

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

/** @return array{exit:int,stdout:string,stderr:string,json:?array} */
function run_bundle(array $args): array {
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/../bin/certification-bundle.php'], $args);
    $pipes = [];
    $process = proc_open($cmd, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('could not start certification bundle command');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $json = json_decode(trim($stdout), true);
    return [
        'exit' => $exit,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'json' => is_array($json) ? $json : null,
    ];
}

function write_json(string $path, array $value): void {
    file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        remove_tree($item->getPathname());
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . '/duo_cert_bundle_' . bin2hex(random_bytes(5));
$repo = "$root/repo";
$inputs = "$root/inputs";
$bundles = "$root/bundles";
mkdir($repo, 0777, true);
mkdir($inputs, 0777, true);
register_shutdown_function(fn() => remove_tree($root));

file_put_contents("$repo/agent.php", "<?php // exact product input\n");
file_put_contents("$repo/harness.sh", "#!/usr/bin/env bash\n# exact harness input\n");
write_json("$inputs/environment.json", [
    'wordpress' => '6.8.2',
    'php' => PHP_VERSION,
    'database' => 'MariaDB 11.4',
    'multisite' => false,
]);
file_put_contents("$inputs/core.log", "core conformance\nPASS\n");
write_json("$inputs/core.result.json", [
    'schema_version' => 1,
    'test' => 'core-conformance',
    'verdict' => 'pass',
    'exit_code' => 0,
    'assertions' => ['round_trip', 'render'],
]);
write_json("$inputs/core.diff.json", ['status' => 'clean', 'changed' => []]);

$spec = [
    'repo_root' => $repo,
    'created_at' => '2026-08-08T00:00:00Z',
    'git_revision' => str_repeat('a', 40),
    'harness' => ['name' => 'duo-reference-certification', 'version' => 1],
    'force_hatches' => [],
    'environment' => "$inputs/environment.json",
    'bound_inputs' => ['agent.php', 'harness.sh'],
    'artifacts' => [[
        'name' => 'ninja-forms',
        'version' => '3.14.11',
        'url' => 'https://downloads.wordpress.org/plugin/ninja-forms.3.14.11.zip',
        'sha256' => str_repeat('b', 64),
    ]],
    'tests' => [[
        'id' => 'core-conformance',
        'result' => "$inputs/core.result.json",
        'diff' => "$inputs/core.diff.json",
        'log' => "$inputs/core.log",
    ]],
];
write_json("$inputs/spec.json", $spec);

echo "\n== valid bundle: content-addressed, self-verifying, machine-readable ==\n";
$build = run_bundle(['build', "$inputs/spec.json", $bundles]);
check($build['exit'] === 0, 'valid checker result builds with exit 0');
check(($build['json']['verdict'] ?? null) === 'pass', 'build machine verdict is pass');
$digest = (string) ($build['json']['bundle_digest'] ?? '');
$bundle = (string) ($build['json']['bundle'] ?? '');
check((bool) preg_match('/^[0-9a-f]{64}$/', $digest), 'bundle digest is a full sha256');
check(basename($bundle) === $digest && is_file("$bundle/bundle.json"), 'bundle directory is named by its own digest');
$verify = run_bundle(['verify', $bundle, $repo]);
check($verify['exit'] === 0, 'unchanged bundle verifies with exit 0');
check(($verify['json']['verdict'] ?? null) === 'valid', 'verify machine verdict is valid');

$manifest = json_decode((string) file_get_contents("$bundle/bundle.json"), true);
check(($manifest['environment_summary']['wordpress'] ?? null) === '6.8.2', 'bundle carries the environment record');
check(($manifest['tests'][0]['id'] ?? null) === 'core-conformance', 'bundle carries named test evidence');
check(($manifest['artifacts'][0]['version'] ?? null) === '3.14.11', 'bundle carries exact artifact/version evidence');
check(($manifest['force_hatches'] ?? null) === [], 'bundle records that no force hatch was used');

echo "\n== deliberate defect 1: a changed bound code/harness input expires certification ==\n";
$originalInput = (string) file_get_contents("$repo/agent.php");
file_put_contents("$repo/agent.php", $originalInput . "// deliberate drift\n");
$expired = run_bundle(['verify', $bundle, $repo]);
check($expired['exit'] === 2, 'bound-input drift returns the distinct expired exit status 2');
check(($expired['json']['verdict'] ?? null) === 'expired', 'bound-input drift machine verdict is expired');
check(($expired['json']['expired_inputs'][0]['path'] ?? null) === 'agent.php', 'expiry names the exact changed input');
file_put_contents("$repo/agent.php", $originalInput);

echo "\n== deliberate defect 2: mutated evidence bytes are corrupt, never expired or valid ==\n";
$logPath = "$bundle/logs/core-conformance.txt";
$originalLog = (string) file_get_contents($logPath);
file_put_contents($logPath, $originalLog . "tampered\n");
$corrupt = run_bundle(['verify', $bundle, $repo]);
check($corrupt['exit'] === 1, 'tampered evidence returns non-zero');
check(($corrupt['json']['verdict'] ?? null) === 'corrupt', 'tampered evidence machine verdict is corrupt');
file_put_contents($logPath, $originalLog);

echo "\n== deliberate defect 3: malformed checker output produces a failed bundle ==\n";
file_put_contents("$inputs/core.result.json", "not-json\n");
$badBundles = "$root/bad-bundles";
$badBuild = run_bundle(['build', "$inputs/spec.json", $badBundles]);
check($badBuild['exit'] === 1, 'malformed checker output makes bundle build exit non-zero');
check(($badBuild['json']['verdict'] ?? null) === 'fail', 'malformed checker output is recorded as a failed certification run');
$badBundle = (string) ($badBuild['json']['bundle'] ?? '');
$badManifest = json_decode((string) file_get_contents("$badBundle/bundle.json"), true);
$badResultPath = "$badBundle/" . ($badManifest['tests'][0]['result']['path'] ?? '');
$badResult = json_decode((string) file_get_contents($badResultPath), true);
check(($badResult['reason'] ?? null) === 'invalid_checker_output', 'failed evidence names invalid_checker_output rather than fabricating zero findings');
$badVerify = run_bundle(['verify', $badBundle, $repo]);
check($badVerify['exit'] === 1 && ($badVerify['json']['verdict'] ?? null) === 'failed', 'intact failed evidence never verifies as green');

if ($failures) {
    fwrite(STDERR, "\n$failures certification-bundle regression assertion(s) failed\n");
    exit(1);
}
echo "\n✔ REGRESS_CERTIFICATION_BUNDLE PASSED\n";
