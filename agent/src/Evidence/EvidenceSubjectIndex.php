<?php
declare(strict_types=1);

namespace Duo;

require_once dirname(__DIR__) . '/Canon.php';
require_once dirname(__DIR__) . '/ManifestDispositions.php';
require_once dirname(__DIR__) . '/ScopedCertificationBundle.php';
require_once __DIR__ . '/ManifestArtifactClassification.php';

/**
 * Read-only index of the reviewed subjects that may carry capability evidence.
 *
 * The index deliberately reads declarations and evidence as separate inputs.
 * A durable evidence record never creates a new subject, and a declaration
 * never makes a subject current by itself.
 */
final class EvidenceSubjectIndex {
    private string $root;
    private ManifestDispositions $dispositions;
    /** @var array<string,array<string,mixed>> */
    private array $manifests;
    /** @var array<string,mixed> */
    private array $evidence;

    /** @param array<string,array<string,mixed>> $manifests */
    private function __construct(
        string $root,
        ManifestDispositions $dispositions,
        array $manifests,
        array $evidence
    ) {
        $this->root = $root;
        $this->dispositions = $dispositions;
        $this->manifests = $manifests;
        $this->evidence = $evidence;
    }

    public static function load(string $root): self {
        $root = realpath($root) ?: '';
        if ($root === '' || !is_dir($root) || is_link($root)) {
            throw new \RuntimeException('evidence subject index: repository root is absent or unsafe');
        }
        $manifestDir = $root . '/manifests';
        $classificationPath = $manifestDir . '/capabilities/source-to-artifact.json';
        if (!is_file($classificationPath) || is_link($classificationPath)) {
            throw new \RuntimeException('evidence subject index: manifest artifact classification is absent');
        }
        ManifestArtifactClassification::load($classificationPath, self::currentManifestPaths($root));
        $dispositions = ManifestDispositions::load($manifestDir);
        if ($dispositions === null) {
            throw new \RuntimeException('evidence subject index: manifest dispositions are absent');
        }

        $manifests = [];
        foreach (glob($manifestDir . '/*.json') ?: [] as $path) {
            if (basename($path) === 'dispositions.json') {
                continue;
            }
            $manifest = Canon::decode(Canon::read_file($path));
            if (!is_array($manifest) || array_is_list($manifest)
                || !is_string($manifest['name'] ?? null) || $manifest['name'] === '') {
                throw new \RuntimeException('evidence subject index: malformed manifest ' . basename($path));
            }
            $manifests[$manifest['name']] = $manifest;
        }
        ksort($manifests, SORT_STRING);

        $evidencePath = $root . '/manifests/capabilities/evidence.json';
        $evidence = Canon::decode(Canon::read_file($evidencePath));
        if (!is_array($evidence) || array_is_list($evidence)
            || ($evidence['format'] ?? null) !== 'duo-capability-evidence/v2'
            || !is_array($evidence['records'] ?? null)
            || (array_is_list($evidence['records']) && $evidence['records'] !== [])) {
            throw new \RuntimeException('evidence subject index: evidence attestation is malformed');
        }

        return new self($root, $dispositions, $manifests, $evidence);
    }

    public function root(): string {
        return $this->root;
    }

    public function dispositions(): ManifestDispositions {
        return $this->dispositions;
    }

    /** @return array<string,array<string,mixed>> */
    public function manifests(): array {
        return $this->manifests;
    }

    /** @return array<string,mixed> */
    public function evidence(): array {
        return $this->evidence;
    }

    /** @return array<string,mixed>|null */
    public function evidenceRecord(string $subjectKey): ?array {
        $record = $this->evidence['records'][$subjectKey] ?? null;
        return is_array($record) ? $record : null;
    }

    /**
     * Return certified declaration subjects in canonical key order.
     *
     * @return list<array{key:string,kind:string,name:string,manifest_name:string,manifest:array,claim:array,status:string,tests:list<string>}>
     */
    public function certifiedSubjects(): array {
        $subjects = [];
        foreach ($this->dispositions->data()['manifests'] as $name => $claim) {
            if (!is_array($claim) || ($claim['status'] ?? null) !== 'certified') {
                continue;
            }
            $subjects[] = $this->buildSubject('manifest', (string) $name, $claim);
        }
        foreach ($this->dispositions->profiles() as $name => $claim) {
            if (!is_array($claim) || ($claim['status'] ?? null) !== 'certified') {
                continue;
            }
            $subjects[] = $this->buildSubject('profile', (string) $name, $claim);
        }
        usort($subjects, static fn(array $a, array $b): int => strcmp($a['key'], $b['key']));
        return $subjects;
    }

    /**
     * Resolve a reviewed subject without consulting runtime WordPress state.
     *
     * @return array{key:string,kind:string,name:string,manifest_name:string,manifest:array,claim:array,status:string,tests:list<string>}
     */
    public function subject(string $kind, string $name): array {
        $claim = $kind === 'manifest'
            ? $this->dispositions->entry($name)
            : ($this->dispositions->profiles()[$name] ?? null);
        if (!is_array($claim)) {
            throw new \RuntimeException("evidence subject index: reviewed subject '$kind:$name' is absent");
        }
        return $this->buildSubject($kind, $name, $claim);
    }

    /** @return array<string,mixed> */
    public function platform(): array {
        $agentSource = Canon::read_file($this->root . '/agent/duo.php');
        if (preg_match("/define\\('DUO_AGENT_VERSION', '([^']+)'\\)/", $agentSource, $agentMatch) !== 1) {
            throw new \RuntimeException('evidence subject index: agent version is unavailable');
        }
        if (preg_match("/define\\('DUO_SPEC_VERSION', ([0-9]+)\\)/", $agentSource, $specMatch) !== 1) {
            throw new \RuntimeException('evidence subject index: spec version is unavailable');
        }
        $compatibility = Canon::decode(Canon::read_file($this->root . '/docs/compatibility-baseline.json'));
        if (!is_array($compatibility) || array_is_list($compatibility)) {
            throw new \RuntimeException('evidence subject index: compatibility baseline is malformed');
        }
        unset($compatibility['_comment']);
        return [
            'agent_version' => $agentMatch[1],
            'branchable_state' => 'only exact certified registry surfaces and operations',
            'compatibility' => $compatibility,
            'plugin_execution' => 'unmodified',
            'site_mode' => 'single-site',
            'spec_version' => (int) $specMatch[1],
        ];
    }

    /** @return array{key:string,kind:string,name:string,manifest_name:string,manifest:array,claim:array,status:string,tests:list<string>} */
    private function buildSubject(string $kind, string $name, array $claim): array {
        if (!in_array($kind, ['manifest', 'profile'], true)
            || preg_match('/^[a-z][a-z0-9-]*$/D', $name) !== 1) {
            throw new \RuntimeException('evidence subject index: subject identity is malformed');
        }
        $manifestName = $kind === 'manifest' ? $name : ($claim['manifest'] ?? null);
        if (!is_string($manifestName) || !isset($this->manifests[$manifestName])) {
            throw new \RuntimeException("evidence subject index: subject '$kind:$name' has no shipped manifest");
        }
        $tests = $claim['evidence']['tests'] ?? null;
        if (!is_array($tests) || !array_is_list($tests) || $tests === []) {
            throw new \RuntimeException("evidence subject index: subject '$kind:$name' has no evidence tests");
        }
        foreach ($tests as $test) {
            if (!is_string($test) || preg_match('/^[a-z][a-z0-9-]*$/D', $test) !== 1) {
                throw new \RuntimeException("evidence subject index: subject '$kind:$name' has malformed evidence tests");
            }
        }
        return [
            'key' => ScopedCertificationBundle::subjectKey($kind, $name),
            'kind' => $kind,
            'name' => $name,
            'manifest_name' => $manifestName,
            'manifest' => $this->manifests[$manifestName],
            'claim' => $claim,
            'status' => (string) ($claim['status'] ?? ''),
            'tests' => array_values($tests),
        ];
    }

    /** @return list<string> */
    private static function currentManifestPaths(string $root): array {
        $manifestDir = $root . '/manifests';
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($manifestDir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isLink()) {
                throw new \RuntimeException('evidence subject index: manifest tree contains a symlink');
            }
            if (!$file->isFile()) {
                continue;
            }
            $absolute = $file->getPathname();
            $relative = substr($absolute, strlen($root) + 1);
            if ($relative === 'manifests/capabilities/source-to-artifact.json') {
                continue;
            }
            $paths[] = $relative;
        }
        sort($paths, SORT_STRING);
        return $paths;
    }
}
