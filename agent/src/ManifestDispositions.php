<?php
namespace Duo;

/**
 * External ratification data for the shipped manifest library.
 *
 * A manifest cannot certify itself merely by existing beside the agent. The
 * separate dispositions document records the reviewed support boundary and
 * its evidence, while this loader makes omissions and malformed claims loud.
 * Custom/test manifest directories without the shipped registry keep their
 * historical policy behavior, but expose no certified capability claim.
 */
final class ManifestDispositions {
    public const FORMAT = 'duo-manifest-dispositions/v1';
    public const BUNDLE_SCHEMA = 'duo-certification-bundle/v1';

    private array $data;

    private function __construct(array $data) {
        $this->data = $data;
    }

    public static function load(string $dir): ?self {
        $file = rtrim($dir, '/') . '/dispositions.json';
        if (!is_file($file)) {
            return null;
        }
        $data = Canon::decode(Canon::read_file($file));
        self::validate_root($data, "manifest disposition registry '$file'");

        $files = [];
        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $manifestFile) {
            $name = basename($manifestFile, '.json');
            if ($name !== 'dispositions') {
                $files[$name] = Canon::decode(Canon::read_file($manifestFile));
            }
        }
        $declared = array_keys($data['manifests']);
        $shipped = array_keys($files);
        sort($declared, SORT_STRING);
        sort($shipped, SORT_STRING);
        if ($declared !== $shipped) {
            $missing = array_values(array_diff($shipped, $declared));
            $extra = array_values(array_diff($declared, $shipped));
            throw new \RuntimeException(
                'duo: manifest disposition coverage mismatch; missing=[' . implode(',', $missing)
                . '], extra=[' . implode(',', $extra) . ']'
            );
        }
        foreach ($files as $name => $manifest) {
            self::validate_entry($name, $data['manifests'][$name], $manifest);
        }
        self::validate_profiles($data['profiles'], array_keys($data['manifests']));
        return new self($data);
    }

    /** Revalidate frozen bytes without reopening the mutable manifest dir. */
    public static function from_snapshot(array $data, array $manifests): self {
        self::validate_root($data, 'frozen manifest disposition registry');
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '' || !isset($data['manifests'][$name])) {
                throw new \RuntimeException("duo: frozen disposition registry has no entry for manifest '$name'");
            }
            self::validate_entry($name, $data['manifests'][$name], $manifest);
        }
        self::validate_profiles($data['profiles'], array_keys($data['manifests']));
        return new self($data);
    }

    public function data(): array {
        return $this->data;
    }

    public function entry(string $name): ?array {
        $entry = $this->data['manifests'][$name] ?? null;
        return is_array($entry) ? $entry : null;
    }

    public function profiles(): array {
        return $this->data['profiles'];
    }

    /** @return list<array{name:string,status:string,reason:string}> */
    public function blockers(array $manifests): array {
        $out = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $entry = $this->entry($name);
            if ($entry === null || ($entry['status'] ?? null) === 'certified') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'status' => (string) $entry['status'],
                'reason' => (string) $entry['reason'],
            ];
        }
        return $out;
    }

    /** Machine-readable CLI view, resolved from these exact registry bytes. */
    public function report(array $manifests): array {
        $rows = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $entry = $this->entry($name);
            if ($entry === null) {
                continue;
            }
            $resolved = $entry;
            $resolved['name'] = $name;
            $resolved['entities'] = self::resolve_sections(
                $manifest,
                $entry['capabilities']['entity_sections']
            );
            $resolved['fields'] = self::resolve_sections(
                $manifest,
                $entry['capabilities']['field_sections']
            );
            unset($resolved['capabilities']['entity_sections'], $resolved['capabilities']['field_sections']);
            $rows[] = $resolved;
        }
        return [
            'schema_version' => self::FORMAT,
            'registry_sha256' => hash('sha256', Canon::encode($this->data)),
            'ready' => $this->blockers($manifests) === [],
            'blockers' => $this->blockers($manifests),
            'manifests' => $rows,
            'profiles' => $this->profiles(),
        ];
    }

    private static function resolve_sections(array $manifest, array $sections): array {
        $out = [];
        foreach ($sections as $section) {
            $value = $manifest[$section] ?? null;
            if (is_array($value) && !array_is_list($value)) {
                $keys = array_keys($value);
                sort($keys, SORT_STRING);
                $out[$section] = $keys;
            } else {
                $out[$section] = $value;
            }
        }
        return $out;
    }

    private static function validate_root(array $data, string $label): void {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'manifests', 'profiles']
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_array($data['manifests'] ?? null) || array_is_list($data['manifests'])
            || !is_array($data['profiles'] ?? null)
            || (array_is_list($data['profiles']) && $data['profiles'] !== [])) {
            throw new \RuntimeException(
                "duo: $label must contain exactly format, manifests, and profiles for " . self::FORMAT
            );
        }
    }

    private static function validate_profiles(array $profiles, array $manifestNames): void {
        foreach ($profiles as $name => $profile) {
            if (!is_array($profile) || array_is_list($profile)
                || !in_array($profile['status'] ?? null, ['certified', 'experimental'], true)
                || !is_string($profile['manifest'] ?? null) || $profile['manifest'] === ''
                || !in_array($profile['manifest'], $manifestNames, true)
                || !is_string($profile['reason'] ?? null) || trim($profile['reason']) === ''
                || !is_array($profile['supported_versions'] ?? null)
                || array_is_list($profile['supported_versions'])
                || $profile['supported_versions'] === []
                || !is_array($profile['scope'] ?? null) || array_is_list($profile['scope'])
                || !is_array($profile['evidence'] ?? null)
                || ($profile['evidence']['bundle_schema'] ?? null) !== self::BUNDLE_SCHEMA
                || !self::string_list($profile['evidence']['tests'] ?? null, false)) {
                throw new \RuntimeException("duo: manifest disposition profile '$name' is malformed");
            }
        }
    }

    private static function validate_entry(string $name, $entry, array $manifest): void {
        if (!is_array($entry) || array_is_list($entry)) {
            throw new \RuntimeException("duo: manifest disposition '$name' must be an object");
        }
        $status = $entry['status'] ?? null;
        if (!in_array($status, ['certified', 'experimental', 'excluded'], true)
            || !is_string($entry['reason'] ?? null) || trim($entry['reason']) === ''
            || !is_array($entry['supported_versions'] ?? null) || array_is_list($entry['supported_versions'])
            || $entry['supported_versions'] === []
            || !is_array($entry['capabilities'] ?? null) || array_is_list($entry['capabilities'])
            || !is_array($entry['unsupported'] ?? null) || !array_is_list($entry['unsupported'])
            || $entry['unsupported'] === []
            || !is_array($entry['default_authored_keyspaces'] ?? null)
            || !array_is_list($entry['default_authored_keyspaces'])) {
            throw new \RuntimeException("duo: manifest disposition '$name' has a malformed required field");
        }
        $cap = $entry['capabilities'];
        $capKeys = array_keys($cap);
        sort($capKeys, SORT_STRING);
        if ($capKeys !== ['deletion_semantics', 'entity_sections', 'field_sections', 'lifecycle_phases', 'operations']
            || !self::string_list($cap['entity_sections'] ?? null)
            || !self::string_list($cap['field_sections'] ?? null)
            || !self::string_list($cap['operations'] ?? null, false)
            || !self::string_list($cap['lifecycle_phases'] ?? null)
            || !is_array($cap['deletion_semantics'] ?? null)
            || array_is_list($cap['deletion_semantics'])) {
            throw new \RuntimeException("duo: manifest disposition '$name' capabilities are malformed");
        }
        $deletionKeys = array_keys($cap['deletion_semantics']);
        sort($deletionKeys, SORT_STRING);
        if ($deletionKeys !== ['supported', 'unsupported']
            || !self::string_list($cap['deletion_semantics']['supported'] ?? null)
            || !self::string_list($cap['deletion_semantics']['unsupported'] ?? null, false)) {
            throw new \RuntimeException("duo: manifest disposition '$name' deletion semantics are malformed");
        }
        foreach (array_merge($cap['entity_sections'], $cap['field_sections']) as $section) {
            if (!array_key_exists($section, $manifest)) {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' names absent manifest section '$section'"
                );
            }
        }
        foreach ($entry['unsupported'] as $i => $unsupported) {
            if (!is_array($unsupported) || array_is_list($unsupported)
                || !is_string($unsupported['surface'] ?? null) || $unsupported['surface'] === ''
                || !is_string($unsupported['operation'] ?? null) || $unsupported['operation'] === ''
                || !is_string($unsupported['reason'] ?? null) || $unsupported['reason'] === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' unsupported[$i] is malformed");
            }
        }
        $unsupportedSurfaces = array_fill_keys(array_column($entry['unsupported'], 'surface'), true);
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['class'] ?? null) === 'authored_typed_snapshot_post_v1'
                && !isset($unsupportedSurfaces["tables.$table"])) {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' must mark intent-only table '$table' unsupported"
                );
            }
        }

        $defaultRows = [];
        foreach ($entry['default_authored_keyspaces'] as $row) {
            if (!is_array($row) || array_is_list($row)
                || !is_string($row['table'] ?? null) || $row['table'] === ''
                || !in_array($row['status'] ?? null, ['justified', 'unsupported'], true)
                || !is_string($row['reason'] ?? null) || $row['reason'] === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has malformed default authored keyspace evidence");
            }
            $defaultRows[$row['table']] = true;
            if (!isset($manifest['tables'][$row['table']])
                || ($manifest['tables'][$row['table']]['default_class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' names non-default-authored keyspace '{$row['table']}'"
                );
            }
        }
        foreach (($manifest['tables'] ?? []) as $table => $rule) {
            if (($rule['default_class'] ?? null) === 'authored' && !isset($defaultRows[$table])) {
                throw new \RuntimeException(
                    "duo: manifest disposition '$name' omits default authored keyspace '$table'"
                );
            }
        }

        $evidence = $entry['evidence'] ?? null;
        if ($status === 'certified') {
            if (!is_array($evidence) || array_is_list($evidence)
                || ($evidence['bundle_schema'] ?? null) !== self::BUNDLE_SCHEMA
                || !self::string_list($evidence['tests'] ?? null, false)) {
                throw new \RuntimeException("duo: certified manifest disposition '$name' lacks current bundle evidence");
            }
            $plugin = $manifest['plugin'] ?? null;
            if (is_string($plugin) && $plugin !== '') {
                if (($entry['supported_versions']['plugin'] ?? null) !== $plugin
                    || Canon::encode($entry['supported_versions']['range'] ?? null)
                        !== Canon::encode($manifest['version_range'] ?? null)) {
                    throw new \RuntimeException(
                        "duo: certified manifest disposition '$name' versions disagree with its manifest contract"
                    );
                }
            }
        }
    }

    private static function string_list($value, bool $allowEmpty = true): bool {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                return false;
            }
        }
        return count(array_unique($value, SORT_STRING)) === count($value);
    }
}
