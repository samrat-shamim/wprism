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

echo "\n== reference runner exact-checkout guard ==\n";
$referenceRunner = (string) file_get_contents(__DIR__ . '/certify_reference_bundle.sh');
$guardCall = strpos($referenceRunner, "assert_exact_certification_checkout\n");
$workAllocation = strpos($referenceRunner, 'WORK_ROOT=$(mktemp -d');
$pairInspection = strpos($referenceRunner, 'bash bin/pair.sh list');
check(str_contains($referenceRunner, 'rev-parse --path-format=absolute --git-dir'), 'reference certification resolves the invoking checkout git-dir');
check(str_contains($referenceRunner, 'rev-parse --path-format=absolute --git-common-dir'), 'reference certification resolves the canonical pair mount root');
check(str_contains($referenceRunner, '[ "$git_dir" != "$common_dir" ]'), 'reference certification refuses a linked-worktree mount mismatch');
check(str_contains($referenceRunner, 'status --porcelain=v1 --untracked-files=all'), 'reference certification refuses uncommitted source bytes');
check(str_contains($referenceRunner, 'DUO_AGENT_SRC='), 'reference certification validates the persisted agent mount source');
check(str_contains($referenceRunner, 'DUO_MANIFESTS_SRC='), 'reference certification validates the persisted manifest mount source');
check(
    $guardCall !== false && $workAllocation !== false && $pairInspection !== false
        && $guardCall < $workAllocation && $guardCall < $pairInspection,
    'exact-checkout refusal runs before temporary allocation or Docker pair inspection'
);

echo "\n== Contact Form 7 checker does not race Docker output against an early reader ==\n";
$cf7Checker = (string) file_get_contents(__DIR__ . '/../conformance/checks/contact-form-7.sh');
check(
    str_contains(
        $cf7Checker,
        '--post_type=wpcf7_contact_form --name=conformance-contact-form --format=ids'
    ),
    'CF7 checker asks WP-CLI for the exact authored fixture ID without a truncating reader'
);
check(
    str_contains($cf7Checker, '[[ "$CONF1_WPCF7_ID" =~ ^[0-9]+$ ]]'),
    'CF7 checker rejects zero or multiple source form IDs explicitly'
);
check(
    !preg_match('/\\$COMPOSE[^\n]*\\|[[:space:]]*head(?:[[:space:]]|$)/', $cf7Checker),
    'CF7 checker never pipes Docker output into head under pipefail'
);

echo "\n== version-matrix cross-user repository permissions ==\n";
$versionMatrix = (string) file_get_contents(__DIR__ . '/certify_version_matrix.sh');
check(
    substr_count($versionMatrix, "sh -c 'umask 000; exec wp \"\$@\"' sh \"\$@\"") === 2,
    'both version-matrix WP-CLI sides create disposable output with a host-cleanable umask'
);
check(
    str_contains($versionMatrix, 'chmod 0777 "$root"'),
    'every allowlisted reset restores uid-33 write access without replacing the bind root'
);
check(
    str_contains($versionMatrix, 'chmod 0777 "siterepo/${PAIR}2"'),
    'every clone can restore uid-33 write access to the recreated target repository root'
);
check(
    str_contains($versionMatrix, '"siterepo/${PAIR}1"|"siterepo/${PAIR}2") ;;'),
    'matrix cleanup is allowlisted to the two disposable repository roots'
);
check(
    str_contains($versionMatrix, 'find "$root" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +'),
    'matrix resets clear repository children while preserving live bind-root inodes'
);
check(
    !str_contains($versionMatrix, 'rm -rf "siterepo/origin-$PAIR.git" "siterepo/${PAIR}1"'),
    'matrix resets never remove the live source bind root'
);
check(
    preg_match_all('/^\s*reset_case_repositories\s*$/m', $versionMatrix) === 14,
    'all positive and negative matrix cases use the shared cross-user reset boundary'
);
check(
    preg_match_all('/^\s*clone_case_target\s*$/m', $versionMatrix) === 7,
    'all positive round-trip cases use the shared cross-user target-clone boundary'
);

$root = sys_get_temp_dir() . '/duo_cert_bundle_' . bin2hex(random_bytes(5));
$repo = "$root/repo";
$inputs = "$root/inputs";
$bundles = "$root/bundles";
mkdir($repo, 0777, true);
mkdir($inputs, 0777, true);
register_shutdown_function(fn() => remove_tree($root));

echo "\n== shipped artifact roles distinguish certification from refusal ==\n";
$artifactLock = json_decode(
    (string) file_get_contents(__DIR__ . '/../conformance/artifacts.lock.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$refusalArtifacts = array_fill_keys([
    'advanced-custom-fields@5.12.6',
    'contact-form-7@5.9.8',
    'elementor@3.35.9',
    'ninja-forms@3.3.21.4',
    'polylang@3.4.5',
    'woocommerce@10.9.4',
    'wordpress-seo@27.9',
], true);
foreach ($artifactLock as $plugin => $versions) {
    foreach ($versions as $version => $artifact) {
        $key = "$plugin@$version";
        $expectedRole = isset($refusalArtifacts[$key]) ? 'refusal-fixture' : 'certified-boundary';
        check(($artifact['role'] ?? null) === $expectedRole, "$key is labeled $expectedRole");
    }
}

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
write_json("$inputs/ratification.json", [
    'format' => 'duo-manifest-dispositions/v1',
    'manifests' => [
        'core' => [
            'status' => 'certified',
            'evidence' => [
                'bundle_schema' => 'duo-certification-bundle/v1',
                'tests' => ['core-conformance'],
            ],
        ],
    ],
    'profiles' => new stdClass(),
]);

$spec = [
    'repo_root' => $repo,
    'created_at' => '2026-08-08T00:00:00Z',
    'git_revision' => str_repeat('a', 40),
    'harness' => ['name' => 'duo-reference-certification', 'version' => 1],
    'force_hatches' => [],
    'environment' => "$inputs/environment.json",
    'ratification' => "$inputs/ratification.json",
    'bound_inputs' => ['agent.php', 'harness.sh'],
    'artifacts' => [[
        'name' => 'ninja-forms',
        'version' => '3.14.11',
        'url' => 'https://downloads.wordpress.org/plugin/ninja-forms.3.14.11.zip',
        'sha256' => str_repeat('b', 64),
        'role' => 'certified-boundary',
    ]],
    'tests' => [[
        'id' => 'core-conformance',
        'result' => "$inputs/core.result.json",
        'diff' => "$inputs/core.diff.json",
        'log' => "$inputs/core.log",
    ]],
];
write_json("$inputs/spec.json", $spec);

echo "\n== git revision identity is canonical before evidence can be sealed ==\n";
foreach ([
    'missing' => null,
    'empty' => '',
    'short' => str_repeat('a', 39),
    'uppercase' => str_repeat('A', 40),
] as $label => $revision) {
    $invalidRevisionSpec = $spec;
    if ($revision === null) {
        unset($invalidRevisionSpec['git_revision']);
    } else {
        $invalidRevisionSpec['git_revision'] = $revision;
    }
    write_json("$inputs/$label-revision-spec.json", $invalidRevisionSpec);
    $invalidRevision = run_bundle([
        'build',
        "$inputs/$label-revision-spec.json",
        "$root/$label-revision-bundles",
    ]);
    check($invalidRevision['exit'] === 1, "$label git revision cannot produce a certification bundle");
    check(
        str_contains((string) ($invalidRevision['json']['diagnostic'] ?? ''), 'git_revision'),
        "$label git revision refusal names the source-identity field"
    );
}

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
check(($manifest['artifacts'][0]['role'] ?? null) === 'certified-boundary', 'bundle labels supported artifacts separately from refusal fixtures');
check(($manifest['force_hatches'] ?? null) === [], 'bundle records that no force hatch was used');
check(($manifest['ratification_summary']['certified_claims'][0] ?? null) === 'manifests.core', 'bundle names the certified claim backed by its test evidence');
check(is_file("$bundle/ratification.json"), 'bundle embeds the exact ratification matrix as a hashed asset');

echo "\n== deliberate defect 1: unlabeled artifact purpose fails closed ==\n";
$missingRoleSpec = $spec;
unset($missingRoleSpec['artifacts'][0]['role']);
write_json("$inputs/missing-role-spec.json", $missingRoleSpec);
$missingRole = run_bundle(['build', "$inputs/missing-role-spec.json", "$root/missing-role-bundles"]);
check($missingRole['exit'] === 1, 'artifact without an explicit boundary/refusal role is rejected');
check(($missingRole['json']['reason'] ?? null) === 'bundle_build_error', 'unlabeled artifact returns a bundle-build error');

echo "\n== deliberate defect 2: a certified claim cannot cite absent evidence ==\n";
$missingEvidenceRatification = [
    'format' => 'duo-manifest-dispositions/v1',
    'manifests' => [
        'core' => [
            'status' => 'certified',
            'evidence' => [
                'bundle_schema' => 'duo-certification-bundle/v1',
                'tests' => ['conformance-core'],
            ],
        ],
    ],
    'profiles' => new stdClass(),
];
write_json("$inputs/missing-evidence-ratification.json", $missingEvidenceRatification);
$missingEvidenceSpec = $spec;
$missingEvidenceSpec['ratification'] = "$inputs/missing-evidence-ratification.json";
write_json("$inputs/missing-evidence-spec.json", $missingEvidenceSpec);
$missingEvidence = run_bundle(['build', "$inputs/missing-evidence-spec.json", "$root/missing-evidence-bundles"]);
check($missingEvidence['exit'] === 1, 'certified claim citing a missing conformance test is rejected');
check(str_contains((string) ($missingEvidence['json']['diagnostic'] ?? ''), 'absent bundle test'), 'missing evidence diagnostic names the absent bundle test');

echo "\n== deliberate defect 3: a changed bound code/harness input expires certification ==\n";
$originalInput = (string) file_get_contents("$repo/agent.php");
file_put_contents("$repo/agent.php", $originalInput . "// deliberate drift\n");
$expired = run_bundle(['verify', $bundle, $repo]);
check($expired['exit'] === 2, 'bound-input drift returns the distinct expired exit status 2');
check(($expired['json']['verdict'] ?? null) === 'expired', 'bound-input drift machine verdict is expired');
check(($expired['json']['expired_inputs'][0]['path'] ?? null) === 'agent.php', 'expiry names the exact changed input');
file_put_contents("$repo/agent.php", $originalInput);

echo "\n== deliberate defect 4: mutated evidence bytes are corrupt, never expired or valid ==\n";
$logPath = "$bundle/logs/core-conformance.txt";
$originalLog = (string) file_get_contents($logPath);
file_put_contents($logPath, $originalLog . "tampered\n");
$corrupt = run_bundle(['verify', $bundle, $repo]);
check($corrupt['exit'] === 1, 'tampered evidence returns non-zero');
check(($corrupt['json']['verdict'] ?? null) === 'corrupt', 'tampered evidence machine verdict is corrupt');
file_put_contents($logPath, $originalLog);

echo "\n== deliberate defect 5: malformed checker output produces a failed bundle ==\n";
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
