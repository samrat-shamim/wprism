<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';

/**
 * Strict value-level verifier for one independently certified subject.
 *
 * A subject is either one manifest or one profile. Each record owns one
 * closure and one exact set of claim citations, so evidence can never leak
 * between extensions, between a profile and its parent manifest, or across
 * unrelated changes.
 */
final class ScopedCertificationBundle {
    public const FORMAT = 'duo-subject-certification-bundle/v1';
    public const PAIR_BUDGET_OVERRIDE_HATCH = 'DUO_PAIR_BUDGET_OVERRIDE';

    /** @return array<string,mixed> */
    public static function validate(array $bundle, string $label = 'scoped certification bundle'): array {
        self::exactKeys($bundle, [
            'artifacts', 'bundle_digest', 'claims', 'closure', 'created_at',
            'force_hatches', 'format', 'git_revision', 'platform', 'ratification', 'subject', 'tests', 'verdict',
            'subject_digest',
        ], $label);
        if (($bundle['format'] ?? null) !== self::FORMAT
            || ($bundle['verdict'] ?? null) !== 'pass'
            || !self::sha($bundle['subject_digest'] ?? null)
            || !self::sha($bundle['bundle_digest'] ?? null)
            || !is_string($bundle['git_revision'] ?? null)
            || preg_match('/^[0-9a-f]{40}$/D', $bundle['git_revision']) !== 1
            || !is_string($bundle['created_at'] ?? null)
            || strtotime($bundle['created_at']) === false) {
            throw new \RuntimeException("duo: $label identity is malformed");
        }
        if (!is_array($bundle['subject'] ?? null) || array_is_list($bundle['subject'])) {
            throw new \RuntimeException("duo: $label.subject must be an object");
        }
        self::exactKeys($bundle['subject'], ['kind', 'name'], "$label.subject");
        $kind = $bundle['subject']['kind'] ?? null;
        $name = $bundle['subject']['name'] ?? null;
        if (!in_array($kind, ['manifest', 'profile'], true)
            || !is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
            throw new \RuntimeException("duo: $label.subject is malformed");
        }
        if (!is_array($bundle['platform'] ?? null) || array_is_list($bundle['platform'])
            || $bundle['platform'] === []) {
            throw new \RuntimeException("duo: $label.platform must be a non-empty object");
        }
        self::validateHatches($bundle['force_hatches'], "$label.force_hatches");
        self::validateClosure($bundle['closure'], $label);
        self::validateArtifacts($bundle['artifacts'], "$label.artifacts");
        self::validateRatification($bundle['ratification'], $kind, $name, "$label.ratification");
        $tests = self::validateTests($bundle['tests'], $label);
        self::validateClaims($bundle['claims'], $kind, $name, $tests, $label);

        $actual = self::digest($bundle);
        if (!hash_equals((string) $bundle['bundle_digest'], $actual)) {
            throw new \RuntimeException("duo: $label content-addressed digest is corrupt");
        }
        return $bundle;
    }

    /**
     * Require that a validated record is current for exactly one subject.
     * `$boundInputs` and `$requiredTests` are caller-owned current projections;
     * an unrelated file or adapter therefore cannot expire this record.
     *
     * @param list<array{path:string,sha256:string,size:int}> $boundInputs
     * @param list<string> $requiredTests
     * @return array{status:string,subject:string,subject_digest:string,bundle_digest:string}
     */
    public static function assertCurrent(
        array $bundle,
        string $kind,
        string $name,
        string $subjectDigest,
        array $platform,
        array $boundInputs,
        array $requiredTests,
        array $ratification,
        array $artifacts
    ): array {
        self::validate($bundle);
        if (($bundle['subject']['kind'] ?? null) !== $kind
            || ($bundle['subject']['name'] ?? null) !== $name
            || !hash_equals((string) $bundle['subject_digest'], $subjectDigest)) {
            throw new \RuntimeException('duo: scoped certification subject identity is not current');
        }
        if (Canon::encode($bundle['platform']) !== Canon::encode($platform)) {
            throw new \RuntimeException('duo: scoped certification platform boundary is not current');
        }
        self::assertRatificationCurrent($bundle['ratification'], $kind, $name, $ratification);
        if (Canon::encode(self::normalizeArtifacts($bundle['artifacts'], 'scoped certification artifacts'))
            !== Canon::encode(self::normalizeArtifacts($artifacts, 'current scoped certification artifacts'))) {
            throw new \RuntimeException('duo: scoped certification artifacts are not current');
        }
        $currentInputs = self::normalizeInputs($boundInputs, 'current bound inputs');
        $recordedInputs = self::normalizeInputs($bundle['closure']['inputs'], 'scoped certification closure');
        if (Canon::encode($currentInputs) !== Canon::encode($recordedInputs)
            || !hash_equals((string) $bundle['closure']['digest'], self::closureDigest($currentInputs))) {
            throw new \RuntimeException('duo: scoped certification closure is not current');
        }
        $required = self::stringList($requiredTests, 'required scoped certification tests', false);
        sort($required, SORT_STRING);
        $subject = self::subjectKey($kind, $name);
        $cited = $bundle['claims'][$subject] ?? null;
        if (!is_array($cited)) {
            throw new \RuntimeException('duo: scoped certification has no current subject claim');
        }
        $cited = array_values($cited);
        sort($cited, SORT_STRING);
        if ($cited !== $required) {
            throw new \RuntimeException('duo: scoped certification citations are incomplete or cross-scoped');
        }
        return [
            'status' => 'current',
            'subject' => $subject,
            'subject_digest' => $subjectDigest,
            'bundle_digest' => (string) $bundle['bundle_digest'],
        ];
    }

    public static function subjectKey(string $kind, string $name): string {
        if (!in_array($kind, ['manifest', 'profile'], true)
            || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
            throw new \RuntimeException('duo: certification subject key is malformed');
        }
        return $kind . 's.' . $name;
    }

    /**
     * Derive the complete source closure for one subject from conventions.
     *
     * This is the single closure-membership authority used by the runner,
     * builder, importer, and full-source runtime verifier. A certificate may
     * supply hashes, but it never gets to choose which files are authoritative.
     * The shared artifact lock is intentionally absent: the signed `artifacts`
     * projection binds only the bootstrap, entry, and matrix rows exercised by
     * this subject, so adding another extension cannot expire unrelated subjects.
     *
     * @param list<string> $requiredTests
     * @return list<string>
     */
    public static function subjectInputPaths(
        string $root,
        string $kind,
        string $name,
        array $manifest,
        array $requiredTests
    ): array {
        self::subjectKey($kind, $name);
        $root = realpath($root);
        if ($root === false || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('duo: certification source root is absent or unsafe');
        }
        $manifestName = $kind === 'manifest' ? $name : ($manifest['name'] ?? null);
        if (!is_string($manifestName) || preg_match('/^[a-z][a-z0-9-]*$/D', $manifestName) !== 1) {
            throw new \RuntimeException('duo: certification subject manifest identity is malformed');
        }
        $tests = self::stringList($requiredTests, 'required scoped certification tests', false);
        foreach ($tests as $test) {
            if (preg_match('/^[a-z][a-z0-9-]*$/D', $test) !== 1) {
                throw new \RuntimeException("duo: certification test id '$test' is not a canonical slug");
            }
        }

        $paths = [];
        $add = static function (string $relative, bool $required = true) use ($root, &$paths): void {
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")
                || str_contains($relative, '\\') || str_contains($relative, '//') || str_ends_with($relative, '/')
                || in_array('.', explode('/', $relative), true) || in_array('..', explode('/', $relative), true)) {
                throw new \RuntimeException("duo: certification input path is unsafe: $relative");
            }
            $absolute = $root . '/' . $relative;
            if (!file_exists($absolute) && !is_link($absolute)) {
                if ($required) {
                    throw new \RuntimeException("duo: certification input is absent: $relative");
                }
                return;
            }
            self::secureRegularFile($root, $relative);
            $paths[$relative] = true;
        };
        $addTree = static function (string $relative, bool $required = true) use ($root, &$paths, $add): void {
            $absolute = $root . '/' . $relative;
            if (!file_exists($absolute) && !is_link($absolute)) {
                if ($required) {
                    throw new \RuntimeException("duo: certification input directory is absent: $relative");
                }
                return;
            }
            self::secureDirectory($root, $relative);
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $file) {
                if ($file->isLink()) {
                    throw new \RuntimeException('duo: certification input tree contains a symbolic link: '
                        . substr($file->getPathname(), strlen($root) + 1));
                }
                if (!$file->isFile()) {
                    continue;
                }
                $path = substr($file->getPathname(), strlen($root) + 1);
                $add($path);
            }
        };

        foreach (['agent', 'cli', 'sandbox/bin'] as $tree) {
            $addTree($tree);
        }
        foreach ([
            'Makefile',
            'docs/compatibility-baseline.json',
            'sandbox/conformance/asserts.sh',
            'sandbox/conformance/run.sh',
            'sandbox/db.yml',
            'sandbox/init-cli.Dockerfile',
            'sandbox/tests/certify_subject_bundle.sh',
            'scripts/capability-registry.php',
        ] as $path) {
            $add($path);
        }
        foreach (glob($root . '/sandbox/lib/pair_*.sh') ?: [] as $path) {
            $add(substr($path, strlen($root) + 1));
        }
        foreach (glob($root . '/sandbox/pair*.yml') ?: [] as $path) {
            $add(substr($path, strlen($root) + 1));
        }

        $add("manifests/$manifestName.json");
        $add("sandbox/conformance/entries/$name.json", false);
        foreach (['seeds', 'postdeploy', 'checks'] as $hook) {
            $add("sandbox/conformance/$hook/$name.sh", false);
        }

        foreach ($tests as $test) {
            if ($test === "conformance-$name") {
                continue;
            }
            if ($test === 'exact-artifact-version-matrix' && $kind === 'manifest') {
                $driver = "sandbox/certification/version-matrix/$name.sh";
                if (is_file($root . '/' . $driver)) {
                    if (!is_executable($root . '/' . $driver)) {
                        throw new \RuntimeException("duo: version-matrix driver is not executable: $driver");
                    }
                    $add($driver);
                    $addTree("sandbox/certification/version-matrix/$name", false);
                } else {
                    $add('sandbox/tests/certify_version_matrix.sh');
                }
                continue;
            }
            if ($test === 'multisite-refusal' && $kind === 'manifest' && $name === 'core') {
                $add('sandbox/tests/regress_multisite_refusal.sh');
                continue;
            }
            $custom = "sandbox/certification/tests/$test.sh";
            $add($custom);
            if (!is_executable($root . '/' . $custom)) {
                throw new \RuntimeException("duo: custom certification test is not executable: $custom");
            }
            $addTree("sandbox/certification/tests/$test", false);
        }

        $interpreter = $manifest['interpreter'] ?? null;
        if (is_string($interpreter) && $interpreter !== '') {
            $add("manifests/interpreters/$interpreter.php");
        }
        foreach ($manifest['providers'] ?? [] as $provider) {
            if (is_array($provider) && ($provider['source'] ?? null) === 'manifest'
                && is_string($provider['id'] ?? null) && $provider['id'] !== '') {
                $add('manifests/providers/' . $provider['id'] . '.php');
            }
        }
        foreach ($manifest['post_types'] ?? [] as $postType) {
            $regenerator = is_array($postType) ? ($postType['regen_dependency']['regenerator'] ?? null) : null;
            if (is_string($regenerator) && $regenerator !== '') {
                $add("manifests/regenerators/$regenerator.php");
            }
        }

        $out = array_keys($paths);
        sort($out, SORT_STRING);
        if ($out === []) {
            throw new \RuntimeException('duo: certification subject closure is empty');
        }
        return $out;
    }

    /**
     * Project only the locked artifacts that the named subject's declared
     * tests actually consume. The shared lock itself is intentionally not a
     * closure input, so adding an unrelated extension does not expire an
     * existing subject; changing one of these selected rows does.
     *
     * @param list<string> $requiredTests
     * @return list<array{kind:string,name:string,role:string,sha256:string,url:string,version:string}>
     */
    public static function subjectArtifacts(
        string $root,
        string $kind,
        string $name,
        array $manifest,
        array $requiredTests
    ): array {
        self::subjectKey($kind, $name);
        $tests = self::stringList($requiredTests, 'required scoped certification tests', false);
        $lock = Canon::decode(Canon::read_file(rtrim($root, '/') . '/sandbox/conformance/artifacts.lock.json'));
        if (!is_array($lock) || array_is_list($lock)) {
            throw new \RuntimeException('duo: certification artifact lock is malformed');
        }

        $selected = [];
        $select = static function (string $artifactKind, string $slug, string $version) use ($lock, &$selected): void {
            $section = $artifactKind === 'plugin' ? 'plugins' : ($artifactKind === 'theme' ? 'themes' : '');
            if ($section === '' || !self::artifactSlug($slug)
                || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version) !== 1) {
                throw new \RuntimeException('duo: certification artifact request is malformed');
            }
            $entry = $lock[$section][$slug][$version] ?? null;
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \RuntimeException("duo: certification artifact '$artifactKind:$slug@$version' is absent");
            }
            $record = [
                'archive_root' => $entry['archive_root'] ?? null,
                'kind' => $artifactKind,
                'name' => $slug,
                'role' => $entry['role'] ?? null,
                'sha256' => $entry['sha256'] ?? null,
                'url' => $entry['url'] ?? null,
                'version' => $version,
            ];
            // Reuse the bundle schema validator for URL, digest, role, and
            // identity validation before any record can be selected.
            $normalized = self::normalizeArtifacts([$record], 'selected certification artifact')[0];
            $key = $artifactKind . "\0" . $slug . "\0" . $version . "\0" . $normalized['role'];
            $selected[$key] = $normalized;
        };

        if (in_array("conformance-$name", $tests, true)) {
            $bootstrap = $lock['themes']['twentytwentyone'] ?? null;
            if (!is_array($bootstrap) || array_is_list($bootstrap) || count($bootstrap) !== 1) {
                throw new \RuntimeException('duo: certification bootstrap theme lock is missing or ambiguous');
            }
            $select('theme', 'twentytwentyone', (string) array_key_first($bootstrap));

            $entryPath = rtrim($root, '/') . "/sandbox/conformance/entries/$name.json";
            $entry = Canon::decode(Canon::read_file($entryPath));
            if (!is_array($entry) || array_is_list($entry) || ($entry['manifest'] ?? null) !== $name
                || !is_array($entry['entry'] ?? null) || array_is_list($entry['entry'])) {
                throw new \RuntimeException("duo: certification conformance entry '$name' is malformed");
            }
            foreach (['plugins' => 'plugin', 'themes' => 'theme'] as $field => $artifactKind) {
                $specs = $entry['entry'][$field] ?? [];
                if (!is_array($specs) || !array_is_list($specs)) {
                    throw new \RuntimeException("duo: certification conformance entry '$name' $field are malformed");
                }
                foreach ($specs as $spec) {
                    if (!is_array($spec) || array_is_list($spec)
                        || !is_string($spec['slug'] ?? null) || !is_string($spec['version'] ?? null)) {
                        throw new \RuntimeException("duo: certification conformance entry '$name' has a malformed $artifactKind");
                    }
                    $select($artifactKind, $spec['slug'], $spec['version']);
                }
            }
        }

        if ($kind === 'manifest' && in_array('exact-artifact-version-matrix', $tests, true)) {
            $plugin = $manifest['plugin'] ?? null;
            if (!is_string($plugin) || !str_contains($plugin, '/')) {
                throw new \RuntimeException("duo: exact-artifact subject '$name' is not plugin-backed");
            }
            $slug = strstr($plugin, '/', true);
            $versions = $lock['plugins'][$slug] ?? null;
            if (!is_array($versions) || array_is_list($versions)) {
                throw new \RuntimeException("duo: exact-artifact subject '$name' has no plugin lock rows");
            }
            $foundBoundary = false;
            foreach ($versions as $version => $entry) {
                $role = is_array($entry) ? ($entry['role'] ?? null) : null;
                if (!in_array($role, ['certified-boundary', 'refusal-fixture'], true)) {
                    continue;
                }
                $select('plugin', $slug, (string) $version);
                $foundBoundary = true;
            }
            if (!$foundBoundary) {
                throw new \RuntimeException("duo: exact-artifact subject '$name' has no boundary or refusal rows");
            }
        }

        $out = array_values($selected);
        usort($out, static fn(array $a, array $b): int => strcmp(
            $a['kind'] . "\0" . $a['name'] . "\0" . $a['role'] . "\0" . $a['version'],
            $b['kind'] . "\0" . $b['name'] . "\0" . $b['role'] . "\0" . $b['version']
        ));
        return $out;
    }

    /** @param list<string> $paths @return list<array{path:string,sha256:string,size:int}> */
    public static function currentInputsForPaths(string $root, array $paths): array {
        $out = [];
        foreach ($paths as $path) {
            if (!is_string($path)) {
                throw new \RuntimeException('duo: certification input path must be a string');
            }
            $out[] = self::fileAsset($root, $path);
        }
        return self::normalizeInputs($out, 'current subject input projection');
    }

    /**
     * Prove that the recorded closure bytes existed at the named source
     * commit. Later unrelated commits remain allowed because the comparison is
     * restricted to the canonical subject path set.
     */
    public static function assertGitRevisionInputs(string $root, array $bundle): void {
        self::validate($bundle);
        $root = realpath($root);
        if ($root === false || !is_dir($root) || !file_exists($root . '/.git')) {
            throw new \RuntimeException('duo: certification source has no Git identity');
        }
        $inputs = self::normalizeInputs($bundle['closure']['inputs'], 'scoped certification Git closure');
        foreach ($inputs as $input) {
            self::secureRegularFile($root, $input['path']);
        }

        $revision = (string) $bundle['git_revision'];
        $type = self::gitCapture($root, ['cat-file', '-t', $revision]);
        if ($type['exit'] !== 0 || trim($type['stdout']) !== 'commit') {
            throw new \RuntimeException("duo: certification Git revision is not a commit: $revision");
        }
        $wanted = array_fill_keys(array_column($inputs, 'path'), true);
        $tree = self::gitCapture($root, ['ls-tree', '-rz', '--full-tree', $revision]);
        if ($tree['exit'] !== 0) {
            throw new \RuntimeException('duo: could not inspect certification Git revision: ' . trim($tree['stderr']));
        }
        $objects = [];
        foreach (explode("\0", $tree['stdout']) as $line) {
            if ($line === '') {
                continue;
            }
            if (preg_match('/^(100644|100755) blob ([0-9a-f]{40,64})\t(.+)$/D', $line, $match) !== 1) {
                continue;
            }
            $path = $match[3];
            if (!isset($wanted[$path])) {
                continue;
            }
            if (isset($objects[$path])) {
                throw new \RuntimeException("duo: certification Git revision duplicates closure member: $path");
            }
            $objects[$path] = $match[2];
        }
        foreach ($inputs as $input) {
            if (!isset($objects[$input['path']])) {
                throw new \RuntimeException(
                    "duo: certification input is absent from Git revision $revision: {$input['path']}"
                );
            }
        }
        if (count($objects) !== count($inputs)) {
            throw new \RuntimeException('duo: certification Git revision returned an unexpected closure projection');
        }

        $requests = tmpfile();
        if ($requests === false) {
            throw new \RuntimeException('duo: could not stage certification Git blob requests');
        }
        foreach ($inputs as $input) {
            fwrite($requests, $objects[$input['path']] . "\n");
        }
        rewind($requests);
        $pipes = [];
        $process = proc_open(['git', '-C', $root, 'cat-file', '--batch'], [
            0 => $requests,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            fclose($requests);
            throw new \RuntimeException('duo: could not verify certification Git revision');
        }
        $error = null;
        foreach ($inputs as $input) {
            $header = fgets($pipes[1]);
            if (!is_string($header)
                || preg_match('/^[0-9a-f]{40,64} blob ([0-9]+)\n$/D', $header, $match) !== 1) {
                $error = "could not read Git blob for {$input['path']}";
                break;
            }
            $remaining = (int) $match[1];
            $context = hash_init('sha256');
            $size = $remaining;
            while ($remaining > 0) {
                $bytes = fread($pipes[1], min(1048576, $remaining));
                if (!is_string($bytes) || $bytes === '') {
                    $error = "Git blob ended early for {$input['path']}";
                    break 2;
                }
                hash_update($context, $bytes);
                $remaining -= strlen($bytes);
            }
            if (fread($pipes[1], 1) !== "\n"
                || $size !== $input['size'] || !hash_equals($input['sha256'], hash_final($context))) {
                $error = "Git blob does not match certified input descriptor: {$input['path']}";
                break;
            }
        }
        fclose($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        fclose($requests);
        if ($error !== null || $exit !== 0) {
            throw new \RuntimeException('duo: certification closure does not match Git revision '
                . $revision . ': ' . ($error ?? trim($stderr)));
        }
    }

    /**
     * A record is not evidence until every cited result, diff, and log is
     * present at the published content-addressed location and agrees with the
     * descriptor it signed.  Descriptor hashes alone deliberately do not
     * make a missing external file evidence.
     */
    public static function assertEvidenceAssets(array $bundle, string $bundleDir): void {
        self::validate($bundle);
        if (!is_dir($bundleDir) || is_link($bundleDir)) {
            throw new \RuntimeException('duo: scoped certification evidence directory is absent or unsafe');
        }
        foreach ($bundle['tests'] as $test) {
            $assets = [];
            foreach (['result', 'diff', 'log'] as $kind) {
                $asset = self::asset($test[$kind] ?? null, "scoped certification test {$test['id']} $kind");
                $actual = self::fileAsset($bundleDir, $asset['path']);
                if (Canon::encode($actual) !== Canon::encode($asset)) {
                    throw new \RuntimeException("duo: scoped certification test {$test['id']} $kind asset is absent or corrupt");
                }
                $assets[$kind] = $bundleDir . '/' . $asset['path'];
            }
            if (!hash_equals((string) $test['evidence_sha256'], self::evidenceDigest([
                self::fileAsset($bundleDir, $test['result']['path']),
                self::fileAsset($bundleDir, $test['diff']['path']),
                self::fileAsset($bundleDir, $test['log']['path']),
            ]))) {
                throw new \RuntimeException("duo: scoped certification test {$test['id']} evidence assets do not match their digest");
            }
            $result = self::jsonAsset($assets['result'], "scoped certification test {$test['id']} result");
            $diff = self::jsonAsset($assets['diff'], "scoped certification test {$test['id']} diff");
            if (($result['test'] ?? null) !== $test['id'] || ($result['verdict'] ?? null) !== 'pass'
                || ($result['exit_code'] ?? null) !== 0 || ($diff['status'] ?? null) !== 'clean') {
                throw new \RuntimeException("duo: scoped certification test {$test['id']} has no passing clean result/diff evidence");
            }
            $log = @file_get_contents($assets['log']);
            if (!is_string($log) || $log === '') {
                throw new \RuntimeException("duo: scoped certification test {$test['id']} has an empty evidence log");
            }
        }
    }

    /** @return list<array{path:string,sha256:string,size:int}> */
    public static function currentInputs(string $root, array $recorded): array {
        $inputs = self::normalizeInputs($recorded, 'scoped certification closure');
        $out = [];
        foreach ($inputs as $input) {
            $out[] = self::fileAsset($root, $input['path']);
        }
        return $out;
    }

    /**
     * Recheck the closed subset of an imported certificate that a deployed
     * Duo installation actually ships. Full-source certification also binds
     * harness, CLI, and Makefile inputs; those are verified by the host-side
     * importer and deliberately are not present in a runtime installation.
     *
     * This is not a relaxed closure check: every agent and manifest byte named
     * by the sealed record must be present and exact, and a record with no
     * target-installed inputs is refused. Callers retain the original full
     * closure when subsequently checking its digest.
     *
     * @param list<array{path:string,sha256:string,size:int}> $recorded
     */
    public static function assertRuntimeInputsCurrent(string $agentDir, string $manifestDir, array $recorded): void {
        $inputs = self::normalizeInputs($recorded, 'scoped certification closure');
        $checked = 0;
        foreach ($inputs as $input) {
            $path = $input['path'];
            if ($path === 'agent/duo-loader.php') {
                // The loader is deliberately a sibling of the installed
                // agent directory: WordPress discovers only top-level
                // mu-plugin files. It is nevertheless sealed under the
                // source-tree agent/ namespace with the rest of the drop-in.
                $actual = self::fileAsset(dirname(rtrim($agentDir, '/')), 'duo-loader.php');
            } elseif (str_starts_with($path, 'agent/')) {
                $actual = self::fileAsset(rtrim($agentDir, '/'), substr($path, strlen('agent/')));
            } elseif (str_starts_with($path, 'manifests/')) {
                $actual = self::fileAsset(rtrim($manifestDir, '/'), substr($path, strlen('manifests/')));
            } else {
                continue;
            }
            // fileAsset() reports paths relative to its physical mount; the
            // certificate binds source-tree names, so restore that sealed
            // identity before comparing the complete descriptor.
            $actual['path'] = $path;
            if (Canon::encode($actual) !== Canon::encode($input)) {
                throw new \RuntimeException(
                    "duo: scoped certification runtime input is not current: $path"
                );
            }
            $checked++;
        }
        if ($checked === 0) {
            throw new \RuntimeException('duo: scoped certification closure has no target-installed inputs');
        }
    }

    public static function digest(array $bundle): string {
        $unsigned = $bundle;
        unset($unsigned['bundle_digest']);
        return hash('sha256', json_encode(
            Canon::normalize($unsigned),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n");
    }

    /** @param list<array{path:string,sha256:string,size:int}> $inputs */
    public static function closureDigest(array $inputs): string {
        return hash('sha256', json_encode(
            Canon::normalize(self::normalizeInputs($inputs, 'closure inputs')),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n");
    }

    private static function validateClosure($closure, string $label): void {
        if (!is_array($closure) || array_is_list($closure)) {
            throw new \RuntimeException("duo: $label.closure must be an object");
        }
        self::exactKeys($closure, ['digest', 'inputs'], "$label.closure");
        if (!self::sha($closure['digest'] ?? null)) {
            throw new \RuntimeException("duo: $label.closure.digest is malformed");
        }
        $inputs = self::normalizeInputs($closure['inputs'] ?? null, "$label.closure.inputs");
        if (!hash_equals((string) $closure['digest'], self::closureDigest($inputs))) {
            throw new \RuntimeException("duo: $label.closure.digest does not bind its inputs");
        }
    }

    /** @return array<string,bool> */
    private static function validateTests($tests, string $label): array {
        if (!is_array($tests) || !array_is_list($tests) || $tests === []) {
            throw new \RuntimeException("duo: $label.tests must be a non-empty list");
        }
        $seen = [];
        foreach ($tests as $i => $test) {
            if (!is_array($test) || array_is_list($test)) {
                throw new \RuntimeException("duo: {$label}.tests[$i] must be an object");
            }
            self::exactKeys($test, ['diff', 'evidence_sha256', 'exit_code', 'id', 'log', 'result', 'verdict'], "{$label}.tests[$i]");
            $id = $test['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z][a-z0-9-]*$/D', $id) !== 1
                || isset($seen[$id]) || ($test['verdict'] ?? null) !== 'pass'
                || ($test['exit_code'] ?? null) !== 0 || !self::sha($test['evidence_sha256'] ?? null)) {
                throw new \RuntimeException("duo: $label.tests[$i] is not a unique passing evidence record");
            }
            $assets = [
                self::asset($test['result'] ?? null, "{$label}.tests[$i].result"),
                self::asset($test['diff'] ?? null, "{$label}.tests[$i].diff"),
                self::asset($test['log'] ?? null, "{$label}.tests[$i].log"),
            ];
            if (!hash_equals((string) $test['evidence_sha256'], self::evidenceDigest($assets))) {
                throw new \RuntimeException("duo: $label.tests[$i].evidence_sha256 does not bind its result, diff, and log");
            }
            $seen[$id] = true;
        }
        return $seen;
    }

    private static function validateClaims($claims, string $kind, string $name, array $tests, string $label): void {
        $subject = self::subjectKey($kind, $name);
        if (!is_array($claims) || array_is_list($claims)) {
            throw new \RuntimeException("duo: $label.claims must name exactly $subject");
        }
        $keys = array_keys($claims);
        sort($keys, SORT_STRING);
        if ($keys !== [$subject]) {
            throw new \RuntimeException("duo: $label.claims must name exactly $subject");
        }
        $cited = self::stringList($claims[$subject], "$label subject citations", false);
        foreach ($cited as $test) {
            if (!isset($tests[$test])) {
                throw new \RuntimeException("duo: $label subject cites absent test '$test'");
            }
        }
    }

    /** @return list<array{path:string,sha256:string,size:int}> */
    private static function normalizeInputs($inputs, string $label): array {
        if (!is_array($inputs) || !array_is_list($inputs) || $inputs === []) {
            throw new \RuntimeException("duo: $label must be a non-empty list");
        }
        $out = [];
        $seen = [];
        foreach ($inputs as $i => $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw new \RuntimeException("duo: {$label}[$i] must be an object");
            }
            self::exactKeys($input, ['path', 'sha256', 'size'], "{$label}[$i]");
            $path = $input['path'] ?? null;
            if (!is_string($path) || $path === '' || str_starts_with($path, '/')
                || str_contains($path, '\\') || str_contains($path, "\0")
                || str_contains($path, '//') || str_ends_with($path, '/')
                || in_array('.', explode('/', $path), true)
                || in_array('..', explode('/', $path), true) || isset($seen[$path])
                || !self::sha($input['sha256'] ?? null) || !is_int($input['size'] ?? null) || $input['size'] < 0) {
                throw new \RuntimeException("duo: {$label}[$i] is malformed or duplicated");
            }
            $seen[$path] = true;
            $out[] = ['path' => $path, 'sha256' => $input['sha256'], 'size' => $input['size']];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        return $out;
    }

    private static function validateHatches($hatches, string $label): void {
        // A forced run remains sealed as evidence, but callers that publish a
        // current capability claim must reject it. Keeping this narrow list
        // makes the accepted override explicit rather than an open-ended
        // force-flag approval channel.
        if (!is_array($hatches) || !array_is_list($hatches)
            || ($hatches !== [] && $hatches !== [self::PAIR_BUDGET_OVERRIDE_HATCH])) {
            throw new \RuntimeException("duo: $label must be empty or exactly [\"" . self::PAIR_BUDGET_OVERRIDE_HATCH . '"]');
        }
    }

    private static function validateRatification($ratification, string $kind, string $name, string $label): void {
        if (!is_array($ratification) || array_is_list($ratification)) {
            throw new \RuntimeException("duo: $label must be an object");
        }
        self::exactKeys($ratification, ['claim', 'kind', 'name', 'sha256'], $label);
        if (($ratification['kind'] ?? null) !== $kind || ($ratification['name'] ?? null) !== $name
            || !is_array($ratification['claim'] ?? null) || array_is_list($ratification['claim'])
            || !self::sha($ratification['sha256'] ?? null)
            || !hash_equals((string) $ratification['sha256'], hash('sha256', Canon::encode($ratification['claim'])))) {
            throw new \RuntimeException("duo: $label must bind the exact one-subject disposition fragment");
        }
    }

    private static function assertRatificationCurrent(array $recorded, string $kind, string $name, array $current): void {
        self::validateRatification($recorded, $kind, $name, 'scoped certification ratification');
        if (Canon::encode($recorded['claim']) !== Canon::encode($current)) {
            throw new \RuntimeException('duo: scoped certification ratification fragment is not current');
        }
    }

    /** @return list<array{archive_root:?string,kind:string,name:string,role:string,sha256:string,url:string,version:string}> */
    private static function validateArtifacts($artifacts, string $label): array {
        return self::normalizeArtifacts($artifacts, $label);
    }

    /** @return list<array{archive_root:?string,kind:string,name:string,role:string,sha256:string,url:string,version:string}> */
    private static function normalizeArtifacts($artifacts, string $label): array {
        if (!is_array($artifacts) || !array_is_list($artifacts)) {
            throw new \RuntimeException("duo: $label must be a list");
        }
        $out = [];
        $seen = [];
        foreach ($artifacts as $i => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                throw new \RuntimeException("duo: {$label}[$i] must be an object");
            }
            self::exactKeys($artifact, ['archive_root', 'kind', 'name', 'role', 'sha256', 'url', 'version'], "{$label}[$i]");
            $archiveRoot = $artifact['archive_root'] ?? null;
            $kind = $artifact['kind'] ?? null;
            $name = $artifact['name'] ?? null;
            $role = $artifact['role'] ?? null;
            $url = $artifact['url'] ?? null;
            $version = $artifact['version'] ?? null;
            if (!in_array($kind, ['plugin', 'theme'], true)
                || !is_string($name) || !self::artifactSlug($name)
                || ($archiveRoot !== null && (!is_string($archiveRoot) || !self::artifactSlug($archiveRoot)))
                || !in_array($role, ['certified-boundary', 'exercise-fixture', 'refusal-fixture'], true)
                || !is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || !is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version) !== 1
                || !self::sha($artifact['sha256'] ?? null)) {
                throw new \RuntimeException("duo: {$label}[$i] is malformed");
            }
            $key = "$kind\0$name\0$role\0$version";
            if (isset($seen[$key])) {
                throw new \RuntimeException("duo: {$label} contains a duplicate artifact identity");
            }
            $seen[$key] = true;
            $out[] = [
                'archive_root' => $archiveRoot,
                'kind' => $kind,
                'name' => $name,
                'role' => $role,
                'sha256' => $artifact['sha256'],
                'url' => $url,
                'version' => $version,
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp(
            $a['kind'] . "\0" . $a['name'] . "\0" . $a['role'] . "\0" . $a['version'],
            $b['kind'] . "\0" . $b['name'] . "\0" . $b['role'] . "\0" . $b['version']
        ));
        return $out;
    }

    private static function artifactSlug(string $slug): bool {
        return preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/D', $slug) === 1;
    }

    /** @return array{path:string,sha256:string,size:int} */
    private static function asset($asset, string $label): array {
        if (!is_array($asset) || array_is_list($asset)) {
            throw new \RuntimeException("duo: $label must be an asset descriptor");
        }
        self::exactKeys($asset, ['path', 'sha256', 'size'], $label);
        $path = $asset['path'] ?? null;
        if (!is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || str_contains($path, "\0") || str_contains($path, '//') || str_ends_with($path, '/')
            || in_array('.', explode('/', $path), true) || in_array('..', explode('/', $path), true)
            || !self::sha($asset['sha256'] ?? null) || !is_int($asset['size'] ?? null) || $asset['size'] < 0) {
            throw new \RuntimeException("duo: $label is malformed");
        }
        return ['path' => $path, 'sha256' => $asset['sha256'], 'size' => $asset['size']];
    }

    /** @return array{path:string,sha256:string,size:int} */
    private static function fileAsset(string $root, string $relative): array {
        $relative = self::asset(['path' => $relative, 'sha256' => str_repeat('0', 64), 'size' => 0], 'scoped certification asset')['path'];
        $path = self::secureRegularFile($root, $relative);
        $hash = hash_file('sha256', $path);
        $size = filesize($path);
        if ($hash === false || $size === false) {
            throw new \RuntimeException("duo: scoped certification asset cannot be read: $relative");
        }
        return ['path' => $relative, 'sha256' => $hash, 'size' => $size];
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function gitCapture(string $root, array $arguments): array {
        $pipes = [];
        $process = proc_open(array_merge(['git', '-C', $root], $arguments), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('duo: could not start Git verification');
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

    private static function secureRegularFile(string $root, string $relative): string {
        return self::securePath($root, $relative, false);
    }

    private static function secureDirectory(string $root, string $relative): string {
        return self::securePath($root, $relative, true);
    }

    private static function securePath(string $root, string $relative, bool $directory): string {
        $root = realpath($root);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('duo: scoped certification root is absent or unsafe');
        }
        $current = $root;
        foreach (explode('/', $relative) as $component) {
            $current .= '/' . $component;
            if (is_link($current)) {
                throw new \RuntimeException("duo: scoped certification path has a symbolic-link component: $relative");
            }
            if (lstat($current) === false) {
                throw new \RuntimeException("duo: scoped certification asset is absent or unsafe: $relative");
            }
        }
        $resolved = realpath($current);
        if ($resolved === false || !str_starts_with($resolved, $root . '/')) {
            throw new \RuntimeException("duo: scoped certification path escapes its root: $relative");
        }
        if (($directory && !is_dir($resolved)) || (!$directory && !is_file($resolved))) {
            throw new \RuntimeException("duo: scoped certification asset has the wrong type: $relative");
        }
        return $resolved;
    }

    private static function jsonAsset(string $path, string $label): array {
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new \RuntimeException("duo: $label cannot be read");
        }
        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("duo: $label is not JSON", 0, $e);
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("duo: $label must be a JSON object");
        }
        return $value;
    }

    /** @param list<array{path:string,sha256:string,size:int}> $assets */
    public static function evidenceDigest(array $assets): string {
        return hash('sha256', json_encode(
            Canon::normalize($assets), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n");
    }

    /** @param list<string> $values */
    private static function stringList($values, string $label, bool $allowEmpty): array {
        if (!is_array($values) || !array_is_list($values) || (!$allowEmpty && $values === [])) {
            throw new \RuntimeException("duo: $label must be a " . ($allowEmpty ? 'list' : 'non-empty list'));
        }
        $seen = [];
        foreach ($values as $value) {
            if (!is_string($value) || preg_match('/^[a-z][a-z0-9-]*$/D', $value) !== 1 || isset($seen[$value])) {
                throw new \RuntimeException("duo: $label must contain unique canonical strings");
            }
            $seen[$value] = true;
        }
        return $values;
    }

    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $label has unsupported keys");
        }
    }

    private static function sha($value): bool {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/D', $value) === 1;
    }
}
