<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

/** Final-integration close policy for the approved frozen-candidate exception. */
final class CloseGate
{
    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function verify(
        string $candidate,
        string $evidenceChild,
        string $mainRef,
        string $releaseFamily,
        string $selection,
        string $pinRecord,
    ): array {
        $this->assertSha($candidate, 'candidate');
        $this->assertSha($evidenceChild, 'evidence child');
        if ($mainRef !== 'refs/remotes/origin/main') {
            throw new CatalogException('final integration close gate requires the freshly fetched refs/remotes/origin/main ref');
        }
        $main = trim($this->capture(['git', 'show-ref', '--verify', '--hash', $mainRef]));
        $this->assertSha($main, 'fresh main');
        foreach ([$candidate, $evidenceChild] as $commit) {
            $this->capture(['git', 'cat-file', '-e', $commit . '^{commit}']);
            if (!$this->success(['git', 'merge-base', '--is-ancestor', $commit, $main])) {
                throw new CatalogException("final integration commit $commit is not reachable from fresh main");
            }
        }
        $parents = preg_split('/\s+/', trim($this->capture(['git', 'rev-list', '--parents', '-n', '1', $evidenceChild])));
        if (!is_array($parents) || $parents !== [$evidenceChild, $candidate]) {
            throw new CatalogException('evidence child must have the frozen candidate as its sole direct parent');
        }
        $changed = array_values(array_filter(
            explode("\0", $this->capture(['git', 'diff', '--name-only', '-z', $candidate, $evidenceChild, '--'])),
            static fn(string $path): bool => $path !== '',
        ));
        if ($changed === []) {
            throw new CatalogException('evidence child must import a nonempty reviewed overlay source diff');
        }
        foreach ($changed as $path) {
            if ($path !== 'manifests/capabilities/evidence.json'
                && preg_match('~^manifests/capabilities/scoped/(?:manifests|profiles)/[^/]+/[a-f0-9]{64}/bundle\.json$~D', $path) !== 1) {
                throw new CatalogException("evidence child changes a runtime, declaration, build, or unapproved source path: $path");
            }
        }

        $release = (new Release($this->root))->verify($releaseFamily, $selection, $pinRecord);
        if (($release['state'] ?? null) !== 'pass'
            || ($release['candidate_sha'] ?? null) !== $candidate
            || ($release['evidence_child_sha'] ?? null) !== $evidenceChild
            || ($release['component_count'] ?? null) !== 4
            || ($release['source_independent_smoke'] ?? null) !== 'pass') {
            throw new CatalogException('retained release family does not bind the exact candidate/evidence-child lineage');
        }
        foreach (['lineage_sha256', 'release_family_sha256', 'target_release_set_sha256', 'target_install_sha256'] as $field) {
            if (!is_string($release[$field] ?? null)
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $release[$field]) !== 1) {
                throw new CatalogException("release verification omitted required digest $field");
            }
        }

        return [
            'format' => 'duo-final-integration-close-gate/v1',
            'state' => 'pass',
            'candidate_sha' => $candidate,
            'evidence_child_sha' => $evidenceChild,
            'fresh_main_sha' => $main,
            'fresh_main_ref' => $mainRef,
            'changed_paths' => $changed,
            'runtime_declaration_build_inputs_unchanged' => true,
            'release' => $release,
        ];
    }

    /** @param array<string,mixed> $receipt */
    public function publish(array $receipt, string $relative): void
    {
        if (preg_match('~^artifacts/(?:[A-Za-z0-9._-]+/)*[A-Za-z0-9._-]+$~D', $relative) !== 1
            || str_contains($relative, '..')) {
            throw new CatalogException('close-gate result path must be artifacts-relative');
        }
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new CatalogException('cannot create close-gate result directory');
        }
        $bytes = json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = tempnam(dirname($path), '.close-gate.');
        if (!is_string($temporary)) {
            throw new CatalogException('cannot create close-gate result');
        }
        try {
            if (file_put_contents($temporary, $bytes) !== strlen($bytes)
                || !chmod($temporary, 0600)
                || !rename($temporary, $path)) {
                throw new CatalogException('cannot publish close-gate result');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function assertSha(string $sha, string $label): void
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new CatalogException("$label must be a full commit SHA");
        }
    }

    /** @param list<string> $argv */
    private function success(array $argv): bool
    {
        return $this->process($argv)['exit'] === 0;
    }

    /** @param list<string> $argv */
    private function capture(array $argv): string
    {
        $result = $this->process($argv);
        if ($result['exit'] !== 0) {
            throw new CatalogException('close-gate Git command failed: ' . trim($result['stderr']));
        }
        return $result['stdout'];
    }

    /**
     * @param list<string> $argv
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function process(array $argv): array
    {
        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot execute close-gate Git command');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        return [
            'exit' => $exit,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }
}
