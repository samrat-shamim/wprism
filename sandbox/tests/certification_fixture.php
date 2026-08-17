<?php
/**
 * Build a hermetic copy of the shipped manifest library with synthetic,
 * independently current subject records. The fixture exercises gates behind
 * certification without mutating checked-in evidence or pretending one
 * release-wide result can authorize every extension.
 */
declare(strict_types=1);

if (!class_exists('Duo\\Canon', false)) {
    require_once dirname(__DIR__, 2) . '/agent/src/Kernel/Canon.php';
}
require_once dirname(__DIR__, 2) . '/agent/src/Policy/ManifestDispositions.php';
require_once dirname(__DIR__, 2) . '/agent/src/Adapter/CapabilityRegistry.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;
use Duo\ScopedCertificationBundle;

function duo_cert_copy_tree(string $from, string $to): void {
    if (!is_dir($to) && !mkdir($to, 0777, true) && !is_dir($to)) {
        throw new RuntimeException("certification fixture manufacture failed: cannot create $to");
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir("$from/$entry")) {
            duo_cert_copy_tree("$from/$entry", "$to/$entry");
        } elseif (!copy("$from/$entry", "$to/$entry")) {
            throw new RuntimeException("certification fixture manufacture failed: cannot copy $from/$entry");
        }
    }
}

function duo_cert_copy_file(string $repo, string $root, string $relative): void {
    $source = rtrim($repo, '/') . '/' . $relative;
    $target = rtrim($root, '/') . '/' . $relative;
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException("certification fixture manufacture failed: source input is absent or unsafe: $relative");
    }
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
        throw new RuntimeException("certification fixture manufacture failed: cannot create " . dirname($target));
    }
    if (!copy($source, $target) || !chmod($target, fileperms($source) & 0777)) {
        throw new RuntimeException("certification fixture manufacture failed: cannot copy $source");
    }
}

/** @return array{exit:int,stdout:string,stderr:string} */
function duo_cert_process(array $command): array {
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('certification fixture manufacture failed: could not start subprocess');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [
        'exit' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function duo_cert_git(string $root, array $arguments): string {
    $result = duo_cert_process(array_merge(['git', '-C', $root], $arguments));
    if ($result['exit'] !== 0) {
        throw new RuntimeException(
            'certification fixture manufacture failed: git ' . implode(' ', $arguments)
            . ' failed: ' . trim($result['stderr'])
        );
    }
    return trim($result['stdout']);
}

function duo_cert_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        duo_cert_remove_tree($item->getPathname());
    }
    rmdir($path);
}

/** Shared digest helper; all independently scoped subject records use this basis. */
function duo_cert_bundle_digest(array $bundle): string {
    return ScopedCertificationBundle::digest($bundle);
}

/** @return array<string,string> */
function duo_cert_library_bytes(string $manifestDir): array {
    $out = [];
    foreach (glob("$manifestDir/*.json") ?: [] as $file) {
        $out[basename($file)] = (string) hash_file('sha256', $file);
    }
    foreach (['interpreters', 'providers', 'regenerators'] as $sub) {
        foreach (glob("$manifestDir/$sub/*") ?: [] as $file) {
            $out["$sub/" . basename($file)] = (string) hash_file('sha256', $file);
        }
    }
    ksort($out, SORT_STRING);
    return $out;
}

/** @return array{path:string,sha256:string,size:int} */
function duo_cert_input(string $root, string $relative): array {
    $path = rtrim($root, '/') . '/' . $relative;
    $digest = is_file($path) ? hash_file('sha256', $path) : false;
    $size = is_file($path) ? filesize($path) : false;
    if ($digest === false || $size === false) {
        throw new RuntimeException("certification fixture manufacture failed: bound input is absent: $relative");
    }
    return ['path' => $relative, 'sha256' => $digest, 'size' => $size];
}

/** @return array{path:string,sha256:string,size:int} */
function duo_cert_asset(string $path, string $relative): array {
    $digest = hash_file('sha256', $path);
    $size = filesize($path);
    if ($digest === false || $size === false) {
        throw new RuntimeException("certification fixture manufacture failed: cannot seal asset $relative");
    }
    return ['path' => $relative, 'sha256' => $digest, 'size' => $size];
}

/** @return array{bundle:array,path:string} */
function duo_cert_make_subject_record(
    string $repo,
    string $fixtureRoot,
    array $platform,
    string $kind,
    string $name,
    array $manifest,
    array $claim,
    string $subjectDigest,
    string $gitRevision
): array {
    $subjectKey = ScopedCertificationBundle::subjectKey($kind, $name);
    $tests = $claim['evidence']['tests'] ?? [];
    if (!is_array($tests) || !array_is_list($tests) || $tests === []) {
        throw new RuntimeException("certification fixture manufacture failed: '$subjectKey' has no test citations");
    }
    $stage = "$fixtureRoot/manifests/capabilities/.fixture-$kind-$name";
    duo_cert_remove_tree($stage);
    mkdir($stage, 0777, true);
    $testRecords = [];
    foreach ($tests as $id) {
        $resultRelative = "results/$id.json";
        $diffRelative = "diffs/$id.json";
        $logRelative = "logs/$id.txt";
        foreach ([dirname("$stage/$resultRelative"), dirname("$stage/$diffRelative"), dirname("$stage/$logRelative")] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
        Canon::write_file("$stage/$resultRelative", Canon::encode([
            'exit_code' => 0,
            'reason' => 'fixture-pass',
            'schema_version' => 1,
            'test' => $id,
            'verdict' => 'pass',
        ]));
        Canon::write_file("$stage/$diffRelative", Canon::encode(['status' => 'clean', 'test' => $id]));
        file_put_contents("$stage/$logRelative", "$id fixture passed\n");
        $assets = [
            duo_cert_asset("$stage/$resultRelative", $resultRelative),
            duo_cert_asset("$stage/$diffRelative", $diffRelative),
            duo_cert_asset("$stage/$logRelative", $logRelative),
        ];
        $testRecords[] = [
            'diff' => $assets[1],
            'evidence_sha256' => ScopedCertificationBundle::evidenceDigest($assets),
            'exit_code' => 0,
            'id' => $id,
            'log' => $assets[2],
            'result' => $assets[0],
            'verdict' => 'pass',
        ];
    }
    $inputPaths = ScopedCertificationBundle::subjectInputPaths($fixtureRoot, $kind, $name, $manifest, $tests);
    $inputs = array_map(fn(string $path): array => duo_cert_input($fixtureRoot, $path), $inputPaths);
    $bundle = [
        'artifacts' => ScopedCertificationBundle::subjectArtifacts($repo, $kind, $name, $manifest, $tests),
        'bundle_digest' => str_repeat('0', 64),
        'claims' => [$subjectKey => array_values($tests)],
        'closure' => ['digest' => ScopedCertificationBundle::closureDigest($inputs), 'inputs' => $inputs],
        'created_at' => '2026-08-14T00:00:00Z',
        'force_hatches' => [],
        'format' => ScopedCertificationBundle::FORMAT,
        'git_revision' => $gitRevision,
        'platform' => $platform,
        'ratification' => [
            'claim' => $claim,
            'kind' => $kind,
            'name' => $name,
            'sha256' => hash('sha256', Canon::encode($claim)),
        ],
        'subject' => ['kind' => $kind, 'name' => $name],
        'subject_digest' => $subjectDigest,
        'tests' => $testRecords,
        'verdict' => 'pass',
    ];
    $bundle['bundle_digest'] = ScopedCertificationBundle::digest($bundle);
    ScopedCertificationBundle::validate($bundle, "fixture certification '$subjectKey'");
    $relative = 'scoped/' . $kind . 's/' . $name . '/' . $bundle['bundle_digest'];
    $final = "$fixtureRoot/manifests/capabilities/$relative";
    if (!is_dir(dirname($final))) {
        mkdir(dirname($final), 0777, true);
    }
    if (!rename($stage, $final)) {
        throw new RuntimeException("certification fixture manufacture failed: cannot publish '$subjectKey'");
    }
    Canon::write_file("$final/bundle.json", Canon::encode($bundle));
    ScopedCertificationBundle::assertEvidenceAssets($bundle, $final);
    return ['bundle' => $bundle, 'path' => $relative];
}

function duo_cert_seal_library(string $repo, string $root): string {
    $repo = rtrim($repo, '/');
    $root = rtrim($root, '/');
    if (!is_dir($root) && !mkdir($root, 0777, true) && !is_dir($root)) {
        throw new RuntimeException("certification fixture manufacture failed: cannot create $root");
    }
    duo_cert_copy_tree("$repo/manifests", "$root/manifests");
    duo_cert_remove_tree("$root/manifests/capabilities/scoped");

    $registry = Canon::decode(Canon::read_file("$repo/manifests/capabilities/registry.json"));
    $dispositions = ManifestDispositions::load("$root/manifests");
    if ($dispositions === null) {
        throw new RuntimeException('certification fixture manufacture failed: dispositions are absent');
    }
    $sourcePaths = [];
    foreach ($registry['manifests'] as $name => $row) {
        if (($row['status'] ?? null) !== 'certified') {
            continue;
        }
        $manifest = Canon::decode(Canon::read_file("$repo/manifests/$name.json"));
        $claim = $dispositions->entry($name);
        foreach (ScopedCertificationBundle::subjectInputPaths(
            $repo, 'manifest', $name, $manifest, $claim['evidence']['tests'] ?? []
        ) as $path) {
            $sourcePaths[$path] = true;
        }
    }
    foreach ($registry['profiles'] as $name => $row) {
        if (($row['status'] ?? null) !== 'certified') {
            continue;
        }
        $claim = $dispositions->profiles()[$name];
        $manifest = Canon::decode(Canon::read_file("$repo/manifests/{$claim['manifest']}.json"));
        foreach (ScopedCertificationBundle::subjectInputPaths(
            $repo, 'profile', $name, $manifest, $claim['evidence']['tests'] ?? []
        ) as $path) {
            $sourcePaths[$path] = true;
        }
    }
    $sourcePaths['sandbox/conformance/artifacts.lock.json'] = true;
    ksort($sourcePaths, SORT_STRING);
    foreach (array_keys($sourcePaths) as $path) {
        duo_cert_copy_file($repo, $root, $path);
    }

    duo_cert_git($root, ['init', '-q']);
    duo_cert_git($root, ['config', 'user.name', 'Duo Certification Fixture']);
    duo_cert_git($root, ['config', 'user.email', 'fixture@invalid.example']);
    duo_cert_git($root, ['add', '--all']);
    duo_cert_git($root, ['commit', '-qm', 'Seal certification source fixture']);
    $gitRevision = duo_cert_git($root, ['rev-parse', '--verify', 'HEAD^{commit}']);

    $records = [];
    foreach ($registry['manifests'] as $name => &$row) {
        if (($row['status'] ?? null) !== 'certified') {
            continue;
        }
        $manifest = Canon::decode(Canon::read_file("$root/manifests/$name.json"));
        $claim = $dispositions->entry($name);
        $record = duo_cert_make_subject_record(
            $root, $root, $registry['platform'], 'manifest', $name, $manifest, $claim, $row['adapter_digest'], $gitRevision
        );
        $key = 'manifests.' . $name;
        $records[$key] = $record;
        $row['evidence'] = duo_cert_project_evidence($record['bundle'], $key, $claim['evidence']['tests']);
    }
    unset($row);
    foreach ($registry['profiles'] as $name => &$row) {
        if (($row['status'] ?? null) !== 'certified') {
            continue;
        }
        $claim = $dispositions->profiles()[$name];
        $manifest = Canon::decode(Canon::read_file("$root/manifests/{$claim['manifest']}.json"));
        $record = duo_cert_make_subject_record(
            $root, $root, $registry['platform'], 'profile', $name, $manifest, $claim, $row['subject_digest'], $gitRevision
        );
        $key = 'profiles.' . $name;
        $records[$key] = $record;
        $row['evidence'] = duo_cert_project_evidence($record['bundle'], $key, $claim['evidence']['tests']);
    }
    unset($row);
    ksort($records, SORT_STRING);
    $evidence = ['format' => CapabilityRegistry::EVIDENCE_FORMAT, 'records' => $records];
    $evidenceFile = "$root/manifests/capabilities/evidence.json";
    Canon::write_file($evidenceFile, Canon::encode($evidence));
    $registry['generated_from']['evidence_sha256'] = hash_file('sha256', $evidenceFile);
    Canon::write_file("$root/manifests/capabilities/registry.json", Canon::encode($registry));
    duo_cert_assert_sealed($repo, "$root/manifests");
    return "$root/manifests";
}

function duo_cert_project_evidence(array $bundle, string $subject, array $tests): array {
    return [
        'bundle_digest' => $bundle['bundle_digest'],
        'bundle_schema' => ScopedCertificationBundle::FORMAT,
        'closure_digest' => $bundle['closure']['digest'],
        'force_hatches' => [],
        'git_revision' => $bundle['git_revision'],
        'status' => 'current',
        'subject' => $subject,
        'subject_digest' => $bundle['subject_digest'],
        'tests' => $tests,
    ];
}

function duo_cert_assert_sealed(string $repo, string $manifestDir): void {
    if (duo_cert_library_bytes("$repo/manifests") !== duo_cert_library_bytes($manifestDir)) {
        throw new RuntimeException('certification fixture manufacture failed: library bytes changed outside capabilities');
    }
    $evidence = Canon::decode(Canon::read_file("$manifestDir/capabilities/evidence.json"));
    $registry = Canon::decode(Canon::read_file("$manifestDir/capabilities/registry.json"));
    $records = $evidence['records'] ?? null;
    $expectedSubjects = [];
    foreach (['manifests' => 'manifests.', 'profiles' => 'profiles.'] as $section => $prefix) {
        foreach (($registry[$section] ?? []) as $name => $row) {
            if (($row['status'] ?? null) === 'certified') {
                $expectedSubjects[] = $prefix . $name;
            }
        }
    }
    sort($expectedSubjects, SORT_STRING);
    $recordSubjects = is_array($records) ? array_keys($records) : [];
    sort($recordSubjects, SORT_STRING);
    if (($evidence['format'] ?? null) !== CapabilityRegistry::EVIDENCE_FORMAT
        || !is_array($records) || $recordSubjects !== $expectedSubjects) {
        throw new RuntimeException('certification fixture manufacture failed: records do not exactly cover the certified subjects');
    }
    foreach ($records as $subject => $entry) {
        $bundle = $entry['bundle'] ?? null;
        $path = $entry['path'] ?? null;
        if (!is_array($bundle) || !is_string($path)) {
            throw new RuntimeException("certification fixture manufacture failed: malformed record '$subject'");
        }
        ScopedCertificationBundle::assertEvidenceAssets($bundle, "$manifestDir/capabilities/$path");
        ScopedCertificationBundle::currentInputs(dirname($manifestDir), $bundle['closure']['inputs']);
    }
    $manifests = [];
    foreach (glob("$manifestDir/*.json") ?: [] as $file) {
        if (basename($file) !== 'dispositions.json') {
            $manifests[] = Canon::decode(Canon::read_file($file));
        }
    }
    $loaded = CapabilityRegistry::load($manifestDir, ManifestDispositions::load($manifestDir), $manifests);
    if ($loaded === null) {
        throw new RuntimeException('certification fixture manufacture failed: sealed registry did not load');
    }
    foreach (['manifests', 'profiles'] as $section) {
        foreach ($registry[$section] as $name => $row) {
            if (($row['status'] ?? null) === 'certified' && ($row['evidence']['status'] ?? null) !== 'current') {
                throw new RuntimeException("certification fixture manufacture failed: '$section.$name' is not independently current");
            }
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (($argc ?? 0) !== 2 || $argv[1] === '') {
        fwrite(STDERR, 'usage: php ' . basename(__FILE__) . " <scratch-root>\n");
        exit(2);
    }
    try {
        $manifestDir = duo_cert_seal_library(dirname(__DIR__, 2), $argv[1]);
        $evidence = Canon::decode(Canon::read_file("$manifestDir/capabilities/evidence.json"));
        fwrite(STDERR, sprintf(
            "certification fixture sealed: %d library files, %d subject records\n",
            count(duo_cert_library_bytes($manifestDir)),
            count($evidence['records'])
        ));
        fwrite(STDOUT, $manifestDir . "\n");
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
