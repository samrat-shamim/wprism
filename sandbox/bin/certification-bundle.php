<?php
/**
 * Build and verify DUO-3223's content-addressed certification evidence.
 *
 * build <spec.json> <output-root>
 * verify <bundle-dir> <repo-root>
 *
 * stdout is always one machine-readable JSON verdict. Human diagnostics go
 * to stderr, and the exit status agrees: 0=valid/pass, 1=failed or corrupt,
 * 2=expired because a bound input changed. A failed checker still produces a
 * bundle when its log exists; invalid/missing result JSON becomes an explicit
 * invalid_checker_output failure instead of disappearing into a false pass.
 */

const DUO_CERT_SCHEMA = 'duo-certification-bundle/v1';

function cert_canonical(mixed $value): mixed {
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map('cert_canonical', $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) {
        $value[$key] = cert_canonical($item);
    }
    return $value;
}

function cert_json(mixed $value, bool $pretty = false): string {
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
    if ($pretty) {
        $flags |= JSON_PRETTY_PRINT;
    }
    return json_encode(cert_canonical($value), $flags) . "\n";
}

function cert_emit(array $result, int $exit): never {
    fwrite(STDOUT, cert_json($result));
    exit($exit);
}

function cert_human(string $message): void {
    fwrite(STDERR, $message . "\n");
}

function cert_read_json(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException("missing JSON file: $path");
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException("JSON root must be an object: $path");
    }
    return $decoded;
}

function cert_sha(string $path): string {
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        throw new RuntimeException("could not hash file: $path");
    }
    return $hash;
}

function cert_asset(string $path, string $relative): array {
    return [
        'path' => $relative,
        'sha256' => cert_sha($path),
        'size' => filesize($path),
    ];
}

function cert_safe_id(mixed $raw, string $label): string {
    $id = is_string($raw) ? $raw : '';
    if (!preg_match('/^[a-z][a-z0-9-]*$/', $id)) {
        throw new RuntimeException("$label must match ^[a-z][a-z0-9-]*$: '$id'");
    }
    return $id;
}

function cert_safe_relative(mixed $raw, string $label): string {
    $path = is_string($raw) ? $raw : '';
    if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
        throw new RuntimeException("$label must be a non-empty relative path");
    }
    $parts = preg_split('#[/\\\\]+#', $path) ?: [];
    if (in_array('..', $parts, true)) {
        throw new RuntimeException("$label may not traverse outside the repository: $path");
    }
    return implode('/', array_values(array_filter($parts, fn(string $part): bool => $part !== '' && $part !== '.')));
}

function cert_mkdir(string $path): void {
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException("could not create directory: $path");
    }
}

function cert_remove_tree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        cert_remove_tree($item->getPathname());
    }
    rmdir($path);
}

function cert_copy(string $source, string $dest): void {
    cert_mkdir(dirname($dest));
    if (!copy($source, $dest)) {
        throw new RuntimeException("could not copy $source to $dest");
    }
}

function cert_build(string $specPath, string $outputRoot): never {
    $spec = cert_read_json($specPath);
    $repo = realpath((string) ($spec['repo_root'] ?? ''));
    if ($repo === false || !is_dir($repo)) {
        cert_emit(['schema_version' => DUO_CERT_SCHEMA, 'verdict' => 'failed', 'reason' => 'invalid_repo_root'], 1);
    }
    $outputRoot = rtrim($outputRoot, '/');
    cert_mkdir($outputRoot);
    $stage = $outputRoot . '/.building-' . bin2hex(random_bytes(6));
    cert_mkdir($stage);
    cert_mkdir("$stage/logs");
    cert_mkdir("$stage/results");
    cert_mkdir("$stage/diffs");

    try {
        $environmentSource = (string) ($spec['environment'] ?? '');
        $environment = cert_read_json($environmentSource);
        cert_copy($environmentSource, "$stage/environment.json");

        $bound = [];
        $seenInputs = [];
        foreach (($spec['bound_inputs'] ?? []) as $i => $rawPath) {
            $relative = cert_safe_relative($rawPath, "bound_inputs[$i]");
            if (isset($seenInputs[$relative])) {
                throw new RuntimeException("duplicate bound input: $relative");
            }
            $seenInputs[$relative] = true;
            $absolute = "$repo/$relative";
            if (!is_file($absolute)) {
                throw new RuntimeException("bound input is absent or not a file: $relative");
            }
            $bound[] = cert_asset($absolute, $relative);
        }
        if (!$bound) {
            throw new RuntimeException('bound_inputs must name at least one code, manifest, or harness file');
        }

        $artifacts = $spec['artifacts'] ?? [];
        if (!is_array($artifacts) || !array_is_list($artifacts)) {
            throw new RuntimeException('artifacts must be a JSON array');
        }
        foreach ($artifacts as $i => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)
                || !is_string($artifact['name'] ?? null) || ($artifact['name'] ?? '') === ''
                || !is_string($artifact['version'] ?? null) || ($artifact['version'] ?? '') === ''
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($artifact['sha256'] ?? ''))
                || !filter_var($artifact['url'] ?? '', FILTER_VALIDATE_URL)) {
                throw new RuntimeException("artifacts[$i] is malformed");
            }
        }

        $tests = $spec['tests'] ?? [];
        if (!is_array($tests) || !array_is_list($tests) || !$tests) {
            throw new RuntimeException('tests must be a non-empty JSON array');
        }
        $testRecords = [];
        $overall = 'pass';
        $seenTests = [];
        foreach ($tests as $i => $test) {
            if (!is_array($test) || array_is_list($test)) {
                throw new RuntimeException("tests[$i] must be an object");
            }
            $id = cert_safe_id($test['id'] ?? '', "tests[$i].id");
            if (isset($seenTests[$id])) {
                throw new RuntimeException("duplicate test id: $id");
            }
            $seenTests[$id] = true;
            $logSource = (string) ($test['log'] ?? '');
            $diffSource = (string) ($test['diff'] ?? '');
            if (!is_file($logSource)) {
                throw new RuntimeException("test '$id' has no log file");
            }
            $diff = cert_read_json($diffSource);
            $resultSource = (string) ($test['result'] ?? '');
            $result = null;
            try {
                $candidate = cert_read_json($resultSource);
                $validVerdict = in_array($candidate['verdict'] ?? null, ['pass', 'fail'], true);
                $validExit = is_int($candidate['exit_code'] ?? null)
                    && (($candidate['verdict'] ?? null) === 'pass'
                        ? $candidate['exit_code'] === 0
                        : $candidate['exit_code'] !== 0);
                if (($candidate['test'] ?? null) !== $id || !$validVerdict || !$validExit) {
                    throw new RuntimeException('checker result disagrees with test id, verdict, or exit status');
                }
                $result = $candidate;
            } catch (Throwable $e) {
                $result = [
                    'schema_version' => 1,
                    'test' => $id,
                    'verdict' => 'fail',
                    'exit_code' => 70,
                    'reason' => 'invalid_checker_output',
                    'diagnostic' => $e->getMessage(),
                ];
            }
            if ($result['verdict'] !== 'pass') {
                $overall = 'fail';
            }

            $logDest = "logs/$id.txt";
            $resultDest = "results/$id.json";
            $diffDest = "diffs/$id.json";
            cert_copy($logSource, "$stage/$logDest");
            file_put_contents("$stage/$resultDest", cert_json($result, true));
            cert_copy($diffSource, "$stage/$diffDest");
            $testRecords[] = [
                'id' => $id,
                'verdict' => $result['verdict'],
                'result' => cert_asset("$stage/$resultDest", $resultDest),
                'diff' => cert_asset("$stage/$diffDest", $diffDest),
                'log' => cert_asset("$stage/$logDest", $logDest),
            ];
        }

        $harness = $spec['harness'] ?? [];
        if (!is_array($harness) || array_is_list($harness)
            || !is_string($harness['name'] ?? null) || ($harness['name'] ?? '') === ''
            || !is_int($harness['version'] ?? null) || $harness['version'] < 1) {
            throw new RuntimeException('harness must contain a non-empty name and positive integer version');
        }
        $createdAt = (string) ($spec['created_at'] ?? '');
        if ($createdAt === '' || strtotime($createdAt) === false) {
            throw new RuntimeException('created_at must be an ISO-8601 timestamp');
        }
        $forceHatches = $spec['force_hatches'] ?? [];
        if (!is_array($forceHatches) || !array_is_list($forceHatches)) {
            throw new RuntimeException('force_hatches must be an array');
        }

        $manifest = [
            'schema_version' => DUO_CERT_SCHEMA,
            'verdict' => $overall,
            'created_at' => $createdAt,
            'git_revision' => (string) ($spec['git_revision'] ?? ''),
            'harness' => $harness,
            'force_hatches' => $forceHatches,
            'artifacts' => $artifacts,
            'environment_summary' => $environment,
            'environment' => cert_asset("$stage/environment.json", 'environment.json'),
            'bound_inputs' => $bound,
            'tests' => $testRecords,
        ];
        $digest = hash('sha256', cert_json($manifest));
        $manifest['bundle_digest'] = $digest;
        file_put_contents("$stage/bundle.json", cert_json($manifest, true));

        $final = "$outputRoot/$digest";
        if (file_exists($final)) {
            cert_remove_tree($stage);
        } elseif (!rename($stage, $final)) {
            throw new RuntimeException("could not publish bundle atomically to $final");
        }
        cert_human("certification bundle {$overall}: $final");
        cert_emit([
            'schema_version' => DUO_CERT_SCHEMA,
            'verdict' => $overall,
            'bundle_digest' => $digest,
            'bundle' => $final,
        ], $overall === 'pass' ? 0 : 1);
    } catch (Throwable $e) {
        cert_remove_tree($stage);
        cert_human('certification bundle build failed: ' . $e->getMessage());
        cert_emit([
            'schema_version' => DUO_CERT_SCHEMA,
            'verdict' => 'failed',
            'reason' => 'bundle_build_error',
            'diagnostic' => $e->getMessage(),
        ], 1);
    }
}

function cert_verify(string $bundleDir, string $repoRoot): never {
    try {
        $bundleDir = rtrim($bundleDir, '/');
        $repo = realpath($repoRoot);
        if ($repo === false || !is_dir($repo)) {
            throw new RuntimeException('invalid repository root');
        }
        $manifest = cert_read_json("$bundleDir/bundle.json");
        if (($manifest['schema_version'] ?? null) !== DUO_CERT_SCHEMA) {
            throw new RuntimeException('unsupported certification bundle schema');
        }
        $claimed = (string) ($manifest['bundle_digest'] ?? '');
        if (!preg_match('/^[0-9a-f]{64}$/', $claimed)) {
            throw new RuntimeException('bundle_digest is absent or malformed');
        }
        $unsigned = $manifest;
        unset($unsigned['bundle_digest']);
        $actual = hash('sha256', cert_json($unsigned));
        if (!hash_equals($claimed, $actual) || basename($bundleDir) !== $claimed) {
            throw new RuntimeException('bundle manifest digest or content-addressed directory name is corrupt');
        }

        $assets = [$manifest['environment'] ?? null];
        foreach (($manifest['tests'] ?? []) as $test) {
            $assets[] = $test['result'] ?? null;
            $assets[] = $test['diff'] ?? null;
            $assets[] = $test['log'] ?? null;
        }
        foreach ($assets as $asset) {
            if (!is_array($asset) || array_is_list($asset)) {
                throw new RuntimeException('bundle asset descriptor is malformed');
            }
            $relative = cert_safe_relative($asset['path'] ?? '', 'bundle asset path');
            $path = "$bundleDir/$relative";
            if (!is_file($path)
                || !hash_equals((string) ($asset['sha256'] ?? ''), cert_sha($path))
                || (int) ($asset['size'] ?? -1) !== filesize($path)) {
                throw new RuntimeException("bundle asset is missing or corrupt: $relative");
            }
        }

        $expired = [];
        foreach (($manifest['bound_inputs'] ?? []) as $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw new RuntimeException('bound input descriptor is malformed');
            }
            $relative = cert_safe_relative($input['path'] ?? '', 'bound input path');
            $path = "$repo/$relative";
            if (!is_file($path)) {
                $expired[] = ['path' => $relative, 'reason' => 'missing'];
                continue;
            }
            if (!hash_equals((string) ($input['sha256'] ?? ''), cert_sha($path))
                || (int) ($input['size'] ?? -1) !== filesize($path)) {
                $expired[] = ['path' => $relative, 'reason' => 'digest_mismatch'];
            }
        }
        if ($expired) {
            cert_human('certification expired: one or more bound code/manifest/harness inputs changed');
            cert_emit([
                'schema_version' => DUO_CERT_SCHEMA,
                'verdict' => 'expired',
                'bundle_digest' => $claimed,
                'expired_inputs' => $expired,
            ], 2);
        }
        if (($manifest['verdict'] ?? null) !== 'pass') {
            cert_human('certification evidence is intact but records a failed run');
            cert_emit([
                'schema_version' => DUO_CERT_SCHEMA,
                'verdict' => 'failed',
                'bundle_digest' => $claimed,
                'certification_verdict' => $manifest['verdict'] ?? null,
            ], 1);
        }
        cert_human("certification bundle valid: $claimed");
        cert_emit([
            'schema_version' => DUO_CERT_SCHEMA,
            'verdict' => 'valid',
            'bundle_digest' => $claimed,
        ], 0);
    } catch (Throwable $e) {
        cert_human('certification bundle corrupt: ' . $e->getMessage());
        cert_emit([
            'schema_version' => DUO_CERT_SCHEMA,
            'verdict' => 'corrupt',
            'reason' => $e->getMessage(),
        ], 1);
    }
}

if ($argc < 2) {
    cert_emit(['schema_version' => DUO_CERT_SCHEMA, 'verdict' => 'failed', 'reason' => 'usage'], 1);
}
try {
    match ($argv[1]) {
        'build' => $argc === 4
            ? cert_build($argv[2], $argv[3])
            : cert_emit(['schema_version' => DUO_CERT_SCHEMA, 'verdict' => 'failed', 'reason' => 'usage: build <spec.json> <output-root>'], 1),
        'verify' => $argc === 4
            ? cert_verify($argv[2], $argv[3])
            : cert_emit(['schema_version' => DUO_CERT_SCHEMA, 'verdict' => 'failed', 'reason' => 'usage: verify <bundle-dir> <repo-root>'], 1),
        default => cert_emit(['schema_version' => DUO_CERT_SCHEMA, 'verdict' => 'failed', 'reason' => 'unknown_command'], 1),
    };
} catch (Throwable $e) {
    cert_human('certification bundle fatal: ' . $e->getMessage());
    cert_emit(['schema_version' => DUO_CERT_SCHEMA, 'verdict' => 'failed', 'reason' => $e->getMessage()], 1);
}
