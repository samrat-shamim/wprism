<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/**
 * Fail-closed aggregation over independently materialized gate dependencies.
 *
 * @phpstan-type DependencyResult array{id:string,path:string,sha256:?string,state:string,message:string}
 */
final class Aggregate
{
    /** @var array<string,list<string>> */
    private readonly array $profiles;

    public function __construct(private readonly string $root)
    {
        $this->profiles = $this->loadProfiles();
    }

    /**
     * @param array<string,string> $dependencies dependency id => artifacts-relative receipt path
     * @return array{exit:int,receipt:array<string,mixed>}
     */
    public function evaluate(string $profileId, array $dependencies): array
    {
        if (!isset($this->profiles[$profileId])) {
            throw new CatalogException("unknown gate orchestration profile: $profileId");
        }
        $expected = $this->profiles[$profileId];
        $actual = array_keys($dependencies);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new CatalogException(sprintf(
                'aggregate dependency set differs from profile; missing=%s extra=%s',
                implode(',', array_diff($expected, $actual)),
                implode(',', array_diff($actual, $expected)),
            ));
        }
        $candidate = $this->candidateSha();
        /** @var list<DependencyResult> $results */
        $results = [];
        foreach ($expected as $id) {
            $results[] = $this->dependency($id, $dependencies[$id], $candidate);
        }
        $pass = true;
        foreach ($results as $row) {
            if (!in_array($row['state'], ['pass', 'not_applicable'], true)) {
                $pass = false;
                break;
            }
        }
        $receipt = [
            'format' => 'duo-gate-aggregate/v1',
            'profile_id' => $profileId,
            'state' => $pass ? 'pass' : 'fail',
            'candidate_sha' => $candidate,
            'profile_sha256' => 'sha256:' . hash('sha256', $this->canonical([
                'id' => $profileId,
                'dependencies' => $expected,
            ])),
            'dependencies' => $results,
            'finished_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        return ['exit' => $pass ? 0 : 1, 'receipt' => $receipt];
    }

    /** @param array<string,mixed> $receipt */
    public function publish(array $receipt, string $relative): void
    {
        $path = $this->artifactPath($relative);
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new CatalogException('cannot create aggregate result directory');
        }
        $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam(dirname($path), '.aggregate.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create aggregate temporary file');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !chmod($temporary, 0600) || !rename($temporary, $path)) {
                throw new CatalogException('cannot publish aggregate result');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return DependencyResult */
    private function dependency(string $id, string $relative, string $candidate): array
    {
        $path = $this->artifactPath($relative);
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($bytes)) {
            return ['id' => $id, 'path' => $relative, 'sha256' => null, 'state' => 'infra_error', 'message' => 'dependency result is absent'];
        }
        $digest = 'sha256:' . hash('sha256', $bytes);
        try {
            $receipt = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['id' => $id, 'path' => $relative, 'sha256' => $digest, 'state' => 'infra_error', 'message' => 'dependency result is invalid JSON'];
        }
        if (!is_array($receipt)) {
            return ['id' => $id, 'path' => $relative, 'sha256' => $digest, 'state' => 'infra_error', 'message' => 'dependency result is not an object'];
        }
        $normalizedReceipt = [];
        foreach ($receipt as $key => $value) {
            if (!is_string($key)) {
                return ['id' => $id, 'path' => $relative, 'sha256' => $digest, 'state' => 'infra_error', 'message' => 'dependency result has non-string fields'];
            }
            $normalizedReceipt[$key] = $value;
        }
        $receipt = $normalizedReceipt;
        $bindingField = in_array($receipt['format'] ?? null, [
            'duo-release-family-check/v1',
            'duo-assembly-reproducibility/v1',
            'duo-final-integration-close-gate/v1',
        ], true) ? 'evidence_child_sha' : 'candidate_sha';
        if (($receipt[$bindingField] ?? null) !== $candidate) {
            return ['id' => $id, 'path' => $relative, 'sha256' => $digest, 'state' => 'infra_error', 'message' => 'dependency result is bound to another candidate'];
        }
        $state = $this->normalizedState($receipt);
        if ($state === 'not_applicable' && !$this->validNotApplicable($receipt, $candidate)) {
            return ['id' => $id, 'path' => $relative, 'sha256' => $digest, 'state' => 'infra_error', 'message' => 'not_applicable lacks a valid resolved-SHA selector receipt'];
        }
        if ($state === 'pass' || $state === 'not_applicable') {
            $message = $state === 'pass' ? 'dependency passed' : 'selector proved dependency out of scope';
            return ['id' => $id, 'path' => $relative, 'sha256' => $digest, 'state' => $state, 'message' => $message];
        }
        return [
            'id' => $id,
            'path' => $relative,
            'sha256' => $digest,
            'state' => $state,
            'message' => 'dependency did not produce an accepted terminal state',
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function normalizedState(array $receipt): string
    {
        $format = $receipt['format'] ?? null;
        $state = $receipt['state'] ?? null;
        if (!is_string($format) || !is_string($state)) {
            return 'infra_error';
        }
        if ($format === 'duo-network-audit-receipt/v1') {
            return match ($state) {
                'clean' => 'pass',
                'policy_failure' => 'fail',
                default => 'infra_error',
            };
        }
        if (!in_array($state, ['pass', 'not_applicable', 'fail', 'infra_error'], true)) {
            return 'infra_error';
        }
        return $state;
    }

    /** @param array<string,mixed> $receipt */
    private function validNotApplicable(array $receipt, string $candidate): bool
    {
        if (($receipt['format'] ?? null) !== 'duo-test-run-receipt/v1'
            || ($receipt['candidate_sha'] ?? null) !== $candidate
            || !is_string($receipt['selection_sha256'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $receipt['selection_sha256']) !== 1) {
            return false;
        }
        $selection = $receipt['selection'] ?? null;
        if (!is_array($selection)
            || ($selection['selector_version'] ?? null) !== 'changed-paths/v1'
            || ($selection['authority'] ?? null) !== 'advisory'
            || ($selection['state'] ?? null) !== 'not_applicable'
            || ($selection['head_sha'] ?? null) !== $candidate) {
            return false;
        }
        foreach (['base_sha', 'head_sha', 'merge_base_sha'] as $key) {
            if (!is_string($selection[$key] ?? null) || preg_match('/^[a-f0-9]{40}$/D', $selection[$key]) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string,list<string>> */
    private function loadProfiles(): array
    {
        $bytes = file_get_contents($this->root . '/sandbox/catalog/fragments/engineering-platform/gate-profiles.json');
        if (!is_string($bytes)) {
            throw new CatalogException('gate orchestration profiles are absent');
        }
        try {
            $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException('gate orchestration profiles are invalid JSON: ' . $exception->getMessage());
        }
        if (!is_array($document)
            || ($document['format'] ?? null) !== 'duo-gate-orchestration-profiles/v1'
            || !is_array($document['profiles'] ?? null)
            || !array_is_list($document['profiles'])) {
            throw new CatalogException('gate orchestration profiles are malformed');
        }
        $profiles = [];
        foreach ($document['profiles'] as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['id', 'dependencies']
                || !is_string($row['id'])
                || !is_array($row['dependencies'])
                || !array_is_list($row['dependencies'])) {
                throw new CatalogException('gate orchestration profile is malformed');
            }
            $dependencies = [];
            foreach ($row['dependencies'] as $dependency) {
                if (!is_string($dependency) || preg_match('/^[a-z0-9][a-z0-9.-]*$/D', $dependency) !== 1) {
                    throw new CatalogException('gate orchestration dependency is invalid');
                }
                $dependencies[] = $dependency;
            }
            $sorted = $dependencies;
            sort($sorted, SORT_STRING);
            if ($sorted !== $dependencies || count($dependencies) !== count(array_unique($dependencies)) || $dependencies === []) {
                throw new CatalogException('gate orchestration dependencies must be nonempty, sorted, and unique');
            }
            if (isset($profiles[$row['id']])) {
                throw new CatalogException('gate orchestration profile is duplicated: ' . $row['id']);
            }
            $profiles[$row['id']] = $dependencies;
        }
        return $profiles;
    }

    private function candidateSha(): string
    {
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot resolve aggregate candidate');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout) || preg_match('/^[a-f0-9]{40}$/D', trim($stdout)) !== 1) {
            throw new CatalogException('aggregate candidate is not a full commit SHA');
        }
        return trim($stdout);
    }

    private function artifactPath(string $relative): string
    {
        if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative) !== 1 || str_contains($relative, '..')) {
            throw new CatalogException('aggregate dependency path must be artifacts-relative');
        }
        return $this->root . '/' . $relative;
    }

    private function canonical(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
