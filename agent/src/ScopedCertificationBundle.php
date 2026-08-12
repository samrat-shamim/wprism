<?php
namespace Duo;

require_once __DIR__ . '/Canon.php';

/**
 * Strict value-level verifier for one-manifest certification evidence.
 *
 * The global `duo-certification-bundle/v1` remains the full-release record.
 * This format is deliberately a separate namespace: a scoped record has one
 * manifest subject, one closure, and one exact set of claim citations. It can
 * never be mistaken for global evidence or stitched together with another
 * manifest's record.
 */
final class ScopedCertificationBundle {
    public const FORMAT = 'duo-adapter-certification-bundle/v1';

    /** @return array<string,mixed> */
    public static function validate(array $bundle, string $label = 'scoped certification bundle'): array {
        self::exactKeys($bundle, [
            'adapter_digest', 'artifacts', 'bundle_digest', 'claims', 'closure', 'created_at',
            'force_hatches', 'format', 'git_revision', 'platform', 'ratification', 'subject', 'tests', 'verdict',
        ], $label);
        if (($bundle['format'] ?? null) !== self::FORMAT
            || ($bundle['verdict'] ?? null) !== 'pass'
            || !self::sha($bundle['adapter_digest'] ?? null)
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
        self::exactKeys($bundle['subject'], ['manifest'], "$label.subject");
        $manifest = $bundle['subject']['manifest'] ?? null;
        if (!is_string($manifest) || preg_match('/^[a-z][a-z0-9-]*$/D', $manifest) !== 1) {
            throw new \RuntimeException("duo: $label.subject.manifest is malformed");
        }
        if (!is_array($bundle['platform'] ?? null) || array_is_list($bundle['platform'])
            || $bundle['platform'] === []) {
            throw new \RuntimeException("duo: $label.platform must be a non-empty object");
        }
        self::validateHatches($bundle['force_hatches'], "$label.force_hatches");
        self::validateClosure($bundle['closure'], $label);
        self::validateArtifacts($bundle['artifacts'], "$label.artifacts");
        self::validateRatification($bundle['ratification'], $manifest, "$label.ratification");
        $tests = self::validateTests($bundle['tests'], $label);
        self::validateClaims($bundle['claims'], $manifest, $tests, $label);

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
     * @return array{status:string,manifest:string,adapter_digest:string,bundle_digest:string}
     */
    public static function assertCurrent(
        array $bundle,
        string $manifest,
        string $adapterDigest,
        array $platform,
        array $boundInputs,
        array $requiredTests,
        array $ratification,
        array $artifacts
    ): array {
        self::validate($bundle);
        if (($bundle['subject']['manifest'] ?? null) !== $manifest
            || !hash_equals((string) $bundle['adapter_digest'], $adapterDigest)) {
            throw new \RuntimeException('duo: scoped certification subject or adapter digest is not current');
        }
        if (Canon::encode($bundle['platform']) !== Canon::encode($platform)) {
            throw new \RuntimeException('duo: scoped certification platform boundary is not current');
        }
        self::assertRatificationCurrent($bundle['ratification'], $manifest, $ratification);
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
        $cited = $bundle['claims']['manifests.' . $manifest] ?? null;
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
            'manifest' => $manifest,
            'adapter_digest' => $adapterDigest,
            'bundle_digest' => (string) $bundle['bundle_digest'],
        ];
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

    private static function validateClaims($claims, string $manifest, array $tests, string $label): void {
        if (!is_array($claims) || array_is_list($claims)) {
            throw new \RuntimeException("duo: $label.claims must name exactly manifests.$manifest");
        }
        $keys = array_keys($claims);
        sort($keys, SORT_STRING);
        if ($keys !== ['manifests.' . $manifest]) {
            throw new \RuntimeException("duo: $label.claims must name exactly manifests.$manifest");
        }
        $cited = self::stringList($claims['manifests.' . $manifest], "$label subject citations", false);
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
        if (!is_array($hatches) || !array_is_list($hatches) || $hatches !== []) {
            throw new \RuntimeException("duo: $label must be an empty list");
        }
    }

    private static function validateRatification($ratification, string $manifest, string $label): void {
        if (!is_array($ratification) || array_is_list($ratification)) {
            throw new \RuntimeException("duo: $label must be an object");
        }
        self::exactKeys($ratification, ['disposition', 'manifest', 'sha256'], $label);
        if (($ratification['manifest'] ?? null) !== $manifest
            || !is_array($ratification['disposition'] ?? null) || array_is_list($ratification['disposition'])
            || !self::sha($ratification['sha256'] ?? null)
            || !hash_equals((string) $ratification['sha256'], hash('sha256', Canon::encode($ratification['disposition'])))) {
            throw new \RuntimeException("duo: $label must bind the exact one-manifest disposition fragment");
        }
    }

    private static function assertRatificationCurrent(array $recorded, string $manifest, array $current): void {
        self::validateRatification($recorded, $manifest, 'scoped certification ratification');
        if (Canon::encode($recorded['disposition']) !== Canon::encode($current)) {
            throw new \RuntimeException('duo: scoped certification ratification fragment is not current');
        }
    }

    /** @return list<array{name:string,role:string,sha256:string,url:string,version:string}> */
    private static function validateArtifacts($artifacts, string $label): array {
        return self::normalizeArtifacts($artifacts, $label);
    }

    /** @return list<array{name:string,role:string,sha256:string,url:string,version:string}> */
    private static function normalizeArtifacts($artifacts, string $label): array {
        if (!is_array($artifacts) || !array_is_list($artifacts) || $artifacts === []) {
            throw new \RuntimeException("duo: $label must be a non-empty list");
        }
        $out = [];
        $seen = [];
        foreach ($artifacts as $i => $artifact) {
            if (!is_array($artifact) || array_is_list($artifact)) {
                throw new \RuntimeException("duo: {$label}[$i] must be an object");
            }
            self::exactKeys($artifact, ['name', 'role', 'sha256', 'url', 'version'], "{$label}[$i]");
            $name = $artifact['name'] ?? null;
            $role = $artifact['role'] ?? null;
            $url = $artifact['url'] ?? null;
            $version = $artifact['version'] ?? null;
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1
                || !in_array($role, ['certified-boundary', 'refusal-fixture'], true)
                || !is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false
                || !is_string($version) || $version === '' || !self::sha($artifact['sha256'] ?? null)) {
                throw new \RuntimeException("duo: {$label}[$i] is malformed");
            }
            $key = "$name\0$role\0$version";
            if (isset($seen[$key])) {
                throw new \RuntimeException("duo: {$label} contains a duplicate artifact identity");
            }
            $seen[$key] = true;
            $out[] = ['name' => $name, 'role' => $role, 'sha256' => $artifact['sha256'], 'url' => $url, 'version' => $version];
        }
        usort($out, static fn(array $a, array $b): int => strcmp(
            $a['name'] . "\0" . $a['role'] . "\0" . $a['version'],
            $b['name'] . "\0" . $b['role'] . "\0" . $b['version']
        ));
        return $out;
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
