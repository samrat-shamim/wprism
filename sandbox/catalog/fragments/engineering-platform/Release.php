<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform;

use Duo\Orchestrator\ArtifactTrustVerifier;
use Duo\Orchestrator\ReleaseSelection;

final class Release
{
    public function __construct(private readonly string $root) {}

    /** @return array<string,mixed> */
    public function verify(string $bundlePath, string $selectionPath, string $pinRecordPath): array
    {
        $bundle = $this->bundleRoot($bundlePath);
        $selectionBytes = $this->authorityBytes($selectionPath, $bundle, 'release selection');
        $pinBytes = $this->authorityBytes($pinRecordPath, $bundle, 'selection pin record');
        $selection = ReleaseSelection::fromArray($this->canonicalObject($selectionBytes, 'release selection'));
        if (!hash_equals($selection->bundlePath, $bundle)) {
            throw new CatalogException('release selection does not name the supplied retained bundle');
        }

        $bytes = [];
        foreach ([
            'release_family' => 'release-family.json',
            'target_release_set' => 'target-release-set.json',
            'host_artifact' => 'host-artifact.tar',
            'target_install' => 'target-install.tar',
            'review_envelope' => 'review-envelope.json',
            'projection_pack' => 'projection-pack.json',
            'lineage' => 'lineage.json',
        ] as $key => $relative) {
            $bytes[$key] = $this->bundleBytes($bundle, $relative);
        }
        $declarations = $this->namedBytes($bundle, 'declarations');
        $evidenceInputs = $this->namedBytes($bundle, 'evidence-inputs');

        try {
            $trust = ArtifactTrustVerifier::verify(
                $selection,
                $pinBytes,
                $bytes['release_family'],
                $bytes['target_release_set'],
                $bytes['host_artifact'],
                $bytes['target_install'],
                $bytes['review_envelope'],
                $bytes['projection_pack'],
                $declarations,
                $evidenceInputs,
            );
        } catch (\RuntimeException $exception) {
            throw new CatalogException('release trust verification failed: ' . $exception->getMessage());
        }

        $lineage = $this->verifyLineage($bytes['lineage'], $bytes, $bundle);
        $targetFiles = DeterministicArchive::decode($bytes['target_install']);
        $hostFiles = DeterministicArchive::decode($bytes['host_artifact']);
        $componentFiles = [];
        foreach (['agent', 'declarations', 'recovery'] as $component) {
            $archive = $this->bundleBytes($bundle, "component-archives/$component.tar");
            $manifest = $this->bundleBytes($bundle, "component-manifests/$component.json");
            $componentFiles = array_merge(
                $componentFiles,
                $this->verifyComponent($component, $archive, $manifest, $lineage['candidate_sha']),
            );
        }
        $hostManifest = $this->bundleBytes($bundle, 'component-manifests/host-cli.json');
        $verifiedHostFiles = $this->verifyComponent('host-cli', $bytes['host_artifact'], $hostManifest, $lineage['candidate_sha']);
        if ($verifiedHostFiles !== $hostFiles) {
            throw new CatalogException('host artifact decoding disagrees with its component manifest');
        }
        usort($componentFiles, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
        if ($componentFiles !== $targetFiles) {
            throw new CatalogException('target-install archive is not the exact union of candidate component archives');
        }
        $this->verifyDetachedReconstruction($selection, $bytes);
        $this->smoke($hostFiles, $targetFiles);

        return [
            'format' => 'duo-release-family-check/v1',
            'state' => 'pass',
            'candidate_sha' => $lineage['candidate_sha'],
            'evidence_child_sha' => $lineage['evidence_child_sha'],
            'selection_sha256' => $this->digest($selectionBytes),
            'pin_record_sha256' => $this->digest($pinBytes),
            'trust' => $trust,
            'lineage_sha256' => $this->digest($bytes['lineage']),
            'release_family_sha256' => $this->digest($bytes['release_family']),
            'target_release_set_sha256' => $this->digest($bytes['target_release_set']),
            'target_install_sha256' => $this->digest($bytes['target_install']),
            'component_count' => 4,
            'source_independent_smoke' => 'pass',
        ];
    }

    /** @return array<string,mixed> */
    public function reproducibility(string $bundlePath, string $selectionPath, string $pinRecordPath): array
    {
        $verified = $this->verify($bundlePath, $selectionPath, $pinRecordPath);
        $bundle = $this->bundleRoot($bundlePath);
        $selection = ReleaseSelection::fromArray($this->canonicalObject(
            $this->authorityBytes($selectionPath, $bundle, 'release selection'),
            'release selection',
        ));
        $inputs = [
            'release_family' => $this->bundleBytes($bundle, 'release-family.json'),
            'target_release_set' => $this->bundleBytes($bundle, 'target-release-set.json'),
            'host_artifact' => $this->bundleBytes($bundle, 'host-artifact.tar'),
            'target_install' => $this->bundleBytes($bundle, 'target-install.tar'),
            'review_envelope' => $this->bundleBytes($bundle, 'review-envelope.json'),
            'projection_pack' => $this->bundleBytes($bundle, 'projection-pack.json'),
        ];
        $first = $this->assemble($selection, $inputs);
        $second = $this->assemble($selection, $inputs);
        if ($first !== $second) {
            throw new CatalogException('detached release assembly differs across independent temporary roots');
        }
        return [
            'format' => 'duo-assembly-reproducibility/v1',
            'state' => 'pass',
            'candidate_sha' => $verified['candidate_sha'],
            'evidence_child_sha' => $verified['evidence_child_sha'],
            'projection_pack_sha256' => $this->digest($first['projection_pack']),
            'target_release_set_sha256' => $this->digest($first['target_release_set']),
            'release_family_sha256' => $this->digest($first['release_family']),
            'composite_sha256' => $this->digest($first['composite']),
            'absolute_roots_differed' => true,
        ];
    }

    /**
     * @param array<string,string> $bytes
     * @return array{candidate_sha:string,evidence_child_sha:string}
     */
    private function verifyLineage(string $lineageBytes, array $bytes, string $bundle): array
    {
        $lineage = $this->canonicalObject($lineageBytes, 'release lineage');
        $this->assertKeys($lineage, [
            'candidate_release_set_sha256', 'candidate_sha', 'component_manifest_sha256',
            'evidence_child_sha', 'format', 'projection_pack_sha256', 'review_envelope_sha256',
        ], 'release lineage');
        $candidate = $this->requiredSha($lineage, 'candidate_sha', 'release lineage');
        $child = $this->requiredSha($lineage, 'evidence_child_sha', 'release lineage');
        if (($lineage['format'] ?? null) !== 'duo-evidence-child-lineage/v1'
            || ($lineage['review_envelope_sha256'] ?? null) !== $this->digest($bytes['review_envelope'])
            || ($lineage['projection_pack_sha256'] ?? null) !== $this->digest($bytes['projection_pack'])) {
            throw new CatalogException('release lineage does not bind its exact imported overlay bytes');
        }
        $candidateSet = $this->bundleBytes($bundle, 'component-manifests/candidate-release-set.json');
        if (($lineage['candidate_release_set_sha256'] ?? null) !== $this->digest($candidateSet)) {
            throw new CatalogException('release lineage does not bind the retained candidate release set');
        }
        $manifestDigests = [];
        foreach (['agent', 'declarations', 'host-cli', 'recovery'] as $component) {
            $manifestDigests[$component] = $this->digest($this->bundleBytes($bundle, "component-manifests/$component.json"));
        }
        if (($lineage['component_manifest_sha256'] ?? null) !== $manifestDigests) {
            throw new CatalogException('release lineage does not bind the retained component manifests');
        }

        $parents = preg_split('/\s+/', trim($this->capture(['git', 'rev-list', '--parents', '-n', '1', $child])));
        if (!is_array($parents) || $parents !== [$child, $candidate]) {
            throw new CatalogException('evidence child does not have the frozen candidate as its sole direct parent');
        }
        $changed = array_values(array_filter(
            explode("\0", $this->capture(['git', 'diff', '--name-only', '-z', $candidate, $child, '--'])),
            static fn(string $path): bool => $path !== '',
        ));
        if ($changed === []) {
            throw new CatalogException('evidence child has no imported review/projection source diff');
        }
        foreach ($changed as $path) {
            if ($path !== 'manifests/capabilities/evidence.json'
                && preg_match('~^manifests/capabilities/scoped/(?:manifests|profiles)/[^/]+/[a-f0-9]{64}/bundle\.json$~D', $path) !== 1) {
                throw new CatalogException("evidence child changes a path outside the approved review/projection source allowlist: $path");
            }
        }
        return ['candidate_sha' => $candidate, 'evidence_child_sha' => $child];
    }

    /**
     * @return list<array{path:string,bytes:string,mode:int}>
     */
    private function verifyComponent(string $component, string $archive, string $manifestBytes, string $candidate): array
    {
        $manifest = $this->canonicalObject($manifestBytes, "$component component manifest");
        $this->assertKeys($manifest, [
            'archive_sha256', 'build_version', 'compatibility', 'component', 'content_sha256',
            'files', 'format', 'root', 'source_commit',
        ], "$component component manifest");
        $root = $component === 'host-cli' ? 'host' : 'payload/' . $component;
        if (($manifest['format'] ?? null) !== 'duo-artifact-manifest/v1'
            || ($manifest['component'] ?? null) !== $component
            || ($manifest['source_commit'] ?? null) !== $candidate
            || ($manifest['root'] ?? null) !== $root
            || ($manifest['archive_sha256'] ?? null) !== $this->digest($archive)
            || ($manifest['build_version'] ?? null) !== '1') {
            throw new CatalogException("$component component manifest is stale or malformed");
        }
        $files = $this->requiredList($manifest, 'files', "$component component manifest");
        $decoded = DeterministicArchive::decode($archive);
        $expected = [];
        $normalizedFiles = [];
        foreach ($files as $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new CatalogException("$component component manifest file row is malformed");
            }
            $entry = $this->stringKeyed($entry, "$component component manifest file row");
            $this->assertKeys($entry, ['mode', 'path', 'sha256', 'size'], "$component component manifest file row");
            $path = $this->requiredString($entry, 'path', "$component component manifest file row");
            $mode = $this->requiredString($entry, 'mode', "$component component manifest file row");
            $sha = $this->requiredDigest($entry, 'sha256', "$component component manifest file row");
            $size = $entry['size'] ?? null;
            if (!is_int($size) || preg_match('/^0[0-7]{3}$/D', $mode) !== 1 || isset($expected[$path])) {
                throw new CatalogException("$component component manifest file row is malformed or duplicated");
            }
            $expected[$path] = ['sha256' => $sha, 'size' => $size, 'mode' => intval($mode, 8)];
            $normalizedFiles[] = $entry;
        }
        if (($manifest['content_sha256'] ?? null) !== 'sha256:' . hash('sha256', $this->canonical($normalizedFiles))) {
            throw new CatalogException("$component component content digest is invalid");
        }
        foreach ($decoded as $file) {
            if (!str_starts_with($file['path'], $root . '/')) {
                throw new CatalogException("$component archive has a member outside its component root");
            }
            $relative = substr($file['path'], strlen($root) + 1);
            $binding = $expected[$relative] ?? null;
            if (!is_array($binding)
                || $binding['sha256'] !== $this->digest($file['bytes'])
                || $binding['size'] !== strlen($file['bytes'])
                || $binding['mode'] !== $file['mode']) {
                throw new CatalogException("$component archive member disagrees with its manifest: $relative");
            }
            unset($expected[$relative]);
        }
        if ($expected !== []) {
            throw new CatalogException("$component archive omits manifested members");
        }
        return $decoded;
    }

    /** @param array<string,string> $bytes */
    private function verifyDetachedReconstruction(ReleaseSelection $selection, array $bytes): void
    {
        $assembled = $this->assemble($selection, $bytes);
        foreach (['projection_pack', 'target_release_set', 'release_family'] as $name) {
            if ($assembled[$name] !== $bytes[$name]) {
                throw new CatalogException("supplied $name bytes are not the deterministic derivation of retained inputs");
            }
        }
    }

    /**
     * @param array<string,string> $bytes
     * @return array{projection_pack:string,target_release_set:string,release_family:string,composite:string}
     */
    private function assemble(ReleaseSelection $selection, array $bytes): array
    {
        $review = $this->canonicalObject($bytes['review_envelope'], 'review envelope');
        $payload = $this->requiredObject($review, 'payload', 'review envelope');
        $projection = $this->canonical([
            'format' => 'duo-projection-pack/v1',
            'review_envelope_sha256' => $this->digest($bytes['review_envelope']),
            'reviewed_payload_sha256' => $this->digest($this->canonical($payload) . "\n"),
        ]) . "\n";
        $set = $this->canonical([
            'format' => 'duo-target-release-set/v1',
            'projection_pack_sha256' => $this->digest($projection),
            'review_envelope_sha256' => $this->digest($bytes['review_envelope']),
            'target_install_sha256' => $this->digest($bytes['target_install']),
        ]) . "\n";
        $family = $this->canonical([
            'format' => 'duo-release-family/v1',
            'host_artifact_sha256' => $this->digest($bytes['host_artifact']),
            'protocols' => $selection->expectedProtocols,
            'target_release_set_sha256' => $this->digest($set),
        ]) . "\n";
        $compositeFiles = DeterministicArchive::decode($bytes['target_install']);
        $compositeFiles[] = ['path' => 'overlay/review/review-envelope.json', 'bytes' => $bytes['review_envelope'], 'mode' => 0644];
        $compositeFiles[] = ['path' => 'overlay/projection/projection-pack.json', 'bytes' => $projection, 'mode' => 0644];
        return [
            'projection_pack' => $projection,
            'target_release_set' => $set,
            'release_family' => $family,
            'composite' => DeterministicArchive::encode($compositeFiles),
        ];
    }

    /**
     * @param list<array{path:string,bytes:string,mode:int}> $hostFiles
     * @param list<array{path:string,bytes:string,mode:int}> $targetFiles
     */
    private function smoke(array $hostFiles, array $targetFiles): void
    {
        $temporary = sys_get_temp_dir() . '/duo-release-smoke-' . bin2hex(random_bytes(8));
        try {
            foreach (array_merge($hostFiles, $targetFiles) as $file) {
                $path = $temporary . '/' . $file['path'];
                if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
                    throw new CatalogException('cannot create release smoke root');
                }
                if (file_put_contents($path, $file['bytes']) !== strlen($file['bytes']) || !chmod($path, $file['mode'])) {
                    throw new CatalogException('cannot extract retained bytes for release smoke');
                }
            }
            $this->capture([PHP_BINARY, $temporary . '/host/cli/duo', '--help'], $temporary);
            $this->capture([
                PHP_BINARY,
                '-r',
                'define("ABSPATH", __DIR__ . "/"); require $argv[1];',
                $temporary . '/payload/agent/duo/duo.php',
            ], $temporary);
            foreach ($targetFiles as $file) {
                if (str_starts_with($file['path'], 'payload/recovery/') && str_ends_with($file['path'], '.php')) {
                    $this->capture([PHP_BINARY, '-l', $temporary . '/' . $file['path']], $temporary);
                }
            }
        } finally {
            $this->removeTree($temporary);
        }
    }

    /** @return array<string,string> */
    private function namedBytes(string $bundle, string $directory): array
    {
        $root = $bundle . '/' . $directory;
        if (!is_dir($root) || is_link($root)) {
            throw new CatalogException("release $directory byte map is absent");
        }
        $entries = scandir($root);
        if (!is_array($entries)) {
            throw new CatalogException("cannot enumerate release $directory byte map");
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^[a-z][a-z0-9._-]{0,63}\.json$/D', $entry) !== 1) {
                throw new CatalogException("release $directory contains an invalid byte-map name");
            }
            $name = substr($entry, 0, -5);
            $result[$name] = $this->bundleBytes($bundle, "$directory/$entry");
        }
        if ($result === []) {
            throw new CatalogException("release $directory byte map is empty");
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    private function bundleRoot(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || is_link($path)) {
            throw new CatalogException('release family must be an absolute retained bundle directory');
        }
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real)) {
            throw new CatalogException('release family bundle is unavailable');
        }
        return $real;
    }

    private function bundleBytes(string $bundle, string $relative): string
    {
        if (preg_match('~^(?:[a-z0-9][a-z0-9._-]*/)*[a-z0-9][a-z0-9._-]*$~D', $relative) !== 1) {
            throw new CatalogException('release bundle path is invalid');
        }
        $path = $bundle . '/' . $relative;
        if (!is_file($path) || is_link($path)) {
            throw new CatalogException("release bundle member is absent or unsafe: $relative");
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new CatalogException("cannot read release bundle member: $relative");
        }
        return $bytes;
    }

    private function authorityBytes(string $path, string $bundle, string $label): string
    {
        if ($path === '' || $path[0] !== '/' || !is_file($path) || is_link($path)) {
            throw new CatalogException("$label must be an absolute external regular file");
        }
        $real = realpath($path);
        $root = realpath($this->root);
        $mode = fileperms($path);
        if (!is_string($real) || !is_string($root)
            || $real === $bundle || str_starts_with($real, $bundle . '/')
            || $real === $root || str_starts_with($real, $root . '/')
            || !is_int($mode) || ($mode & 0022) !== 0) {
            throw new CatalogException("$label must be non-writable authority controlled outside the repository and artifact bundle");
        }
        $bytes = file_get_contents($real);
        if (!is_string($bytes)) {
            throw new CatalogException("$label is unreadable");
        }
        return $bytes;
    }

    /** @return array<string,mixed> */
    private function canonicalObject(string $bytes, string $label): array
    {
        try {
            $decoded = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new CatalogException("$label is invalid JSON: {$exception->getMessage()}");
        }
        if (!is_array($decoded) || array_is_list($decoded) || $this->canonical($decoded) . "\n" !== $bytes) {
            throw new CatalogException("$label is not a canonical JSON object");
        }
        return $this->stringKeyed($decoded, $label);
    }

    /**
     * @param array<string,mixed> $value
     * @param list<string> $expected
     */
    private function assertKeys(array $value, array $expected, string $label): void
    {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($keys !== $expected) {
            throw new CatalogException("$label has missing or unrecognized fields");
        }
    }

    /** @param array<string,mixed> $value */
    private function requiredString(array $value, string $key, string $label): string
    {
        $found = $value[$key] ?? null;
        if (!is_string($found)) {
            throw new CatalogException("$label $key must be a string");
        }
        return $found;
    }

    /** @param array<string,mixed> $value */
    private function requiredSha(array $value, string $key, string $label): string
    {
        $found = $this->requiredString($value, $key, $label);
        if (preg_match('/^[a-f0-9]{40}$/D', $found) !== 1) {
            throw new CatalogException("$label $key must be a full commit SHA");
        }
        return $found;
    }

    /** @param array<string,mixed> $value */
    private function requiredDigest(array $value, string $key, string $label): string
    {
        $found = $this->requiredString($value, $key, $label);
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $found) !== 1) {
            throw new CatalogException("$label $key must be a SHA-256 digest");
        }
        return $found;
    }

    /**
     * @param array<string,mixed> $value
     * @return list<mixed>
     */
    private function requiredList(array $value, string $key, string $label): array
    {
        $found = $value[$key] ?? null;
        if (!is_array($found) || !array_is_list($found)) {
            throw new CatalogException("$label $key must be a list");
        }
        return $found;
    }

    /**
     * @param array<string,mixed> $value
     * @return array<string,mixed>
     */
    private function requiredObject(array $value, string $key, string $label): array
    {
        $found = $value[$key] ?? null;
        if (!is_array($found) || array_is_list($found)) {
            throw new CatalogException("$label $key must be an object");
        }
        return $this->stringKeyed($found, "$label $key");
    }

    /**
     * @param array<mixed,mixed> $value
     * @return array<string,mixed>
     */
    private function stringKeyed(array $value, string $label): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new CatalogException("$label must be an object");
            }
            $result[$key] = $item;
        }
        return $result;
    }

    private function digest(string $bytes): string
    {
        return 'sha256:' . hash('sha256', $bytes);
    }

    private function canonical(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(fn(mixed $item): string => $this->canonical($item), $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = json_encode((string) $key, JSON_THROW_ON_ERROR) . ':' . $this->canonical($item);
            }
            return '{' . implode(',', $pairs) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<string> $command */
    private function capture(array $command, ?string $cwd = null): string
    {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd ?? $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C', 'LANG' => 'C', 'TZ' => 'UTC'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new CatalogException('cannot execute release verification command');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new CatalogException('release verification command failed: ' . trim((string) $stderr));
        }
        return $stdout;
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (!is_dir($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new CatalogException('cannot remove release smoke path');
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        if (!rmdir($path)) {
            throw new CatalogException('cannot remove release smoke directory');
        }
    }
}
