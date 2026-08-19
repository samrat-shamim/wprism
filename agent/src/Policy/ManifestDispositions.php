<?php
namespace Duo;

/**
 * External ratification data for the shipped manifest library.
 *
 * A manifest cannot certify itself merely by existing beside the agent. The
 * separate dispositions document records the reviewed support boundary and
 * its evidence, while this loader makes omissions and malformed claims loud.
 * Custom/test manifest directories without a dispositions document keep their
 * historical policy behavior, but expose no reviewed capability claim.
 *
 * These reviewed bytes are the ONLY authored source of a product capability
 * claim: `claim_from_disposition()` below projects one, and AdapterRegistry
 * evaluates that projection against a live target. There is no second,
 * generated document to agree with.
 */
final class ManifestDispositions {
    public const FORMAT = 'duo-manifest-dispositions/v1';

    /**
     * The agent's own platform/runtime boundary, shipped beside the manifest
     * library at `capabilities/platform.json` rather than derived here: the
     * bytes of this object are what a signed site-adapter certificate binds as
     * `platform_sha256` (AdapterCertification::currentPlatform()), so it has
     * exactly one on-disk representation and both readers name this constant.
     */
    public const PLATFORM_FORMAT = 'duo-platform-boundary/v1';
    private const PLATFORM_RELATIVE = 'capabilities/platform.json';

    /**
     * The synthesized blocker status for a manifest with NO reviewed
     * disposition entry (DUO-3372). It is a RUNTIME status only — never a
     * value a disposition may DECLARE (validate_entry() refuses it) — emitted
     * by blockers()/report() so a direct caller cannot read an uncovered
     * manifest as ready.
     */
    public const STATUS_UNCOVERED = 'uncovered';
    public const UNCOVERED_REASON = 'no reviewed disposition entry — a manifest cannot certify itself merely by existing beside the agent';
    public const EVIDENCE_SCHEMA = 'duo-subject-certification-bundle/v1';

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

    /**
     * The content address of these exact reviewed bytes. It is the number a
     * host contract pins as `registry_sha256` and re-observes later, so it has
     * one definition rather than one per report producer.
     */
    public function sha256(): string {
        return hash('sha256', Canon::encode($this->data));
    }

    /**
     * The agent platform boundary this library ships, validated against the
     * loaded agent.
     *
     * The version agreement is not decoration: a platform object naming a
     * different agent/spec version than the code reading it would let a claim
     * describe a runtime nobody is running. Loud, and before any claim is
     * projected from it.
     *
     * @return array<string,mixed>
     */
    public static function platform_boundary(?string $dir = null): array {
        $file = rtrim($dir ?? self::manifests_dir(), '/') . '/' . self::PLATFORM_RELATIVE;
        $label = "agent platform boundary '$file'";
        if (!is_file($file)) {
            throw new \RuntimeException("duo: $label is absent; this manifest library declares no platform boundary");
        }
        $data = Canon::decode(Canon::read_file($file));
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'platform']
            || ($data['format'] ?? null) !== self::PLATFORM_FORMAT
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])
            || !is_array($data['platform']['compatibility'] ?? null)) {
            throw new \RuntimeException("duo: $label has an unsupported or malformed root");
        }
        $platform = $data['platform'];
        if (($platform['agent_version'] ?? null) !== (defined('DUO_AGENT_VERSION') ? DUO_AGENT_VERSION : '0.5.0')
            || ($platform['spec_version'] ?? null) !== (defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 2)) {
            throw new \RuntimeException("duo: $label platform version disagrees with the loaded agent");
        }
        return $platform;
    }

    /**
     * Resolve the manifest library without requiring Policy to be loaded: this
     * file is reachable from partially-loaded offline contexts that never
     * include Policy.php, and a hard `Policy::` call there would fatal.
     */
    private static function manifests_dir(): string {
        if (class_exists(Policy::class)) {
            return Policy::manifests_dir();
        }
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        return dirname(__DIR__, 3) . '/manifests';
    }

    /**
     * Project one reviewed disposition into the generic capability-row shape
     * without assigning an adapter digest or consulting another subject's
     * evidence.  Shipped capability reporting and separately authenticated
     * site adapters can therefore share operations/surface/environment
     * semantics; callers bind their own source-specific evidence and may add
     * the final digest only after the disposition source is settled.
     *
     * @return array<string,mixed>
     */
    public static function claim_from_disposition(
        array $manifest,
        array $disposition,
        array $evidence,
        array $platform,
        ?array $pluginExecution = null
    ): array {
        $name = (string) ($manifest['name'] ?? '');
        if ($name === '' || !is_array($disposition['capabilities'] ?? null)
            || !is_array($platform['compatibility'] ?? null)) {
            throw new \RuntimeException('duo: cannot project a malformed manifest disposition capability claim');
        }
        $status = (string) ($disposition['status'] ?? 'unsupported');
        $execution = $pluginExecution ?? [
            'mode' => 'unmodified',
            'status' => $status === 'certified'
                ? 'verified'
                : ($status === 'excluded' ? 'not-a-product-claim' : 'unverified'),
        ];
        $capabilities = $disposition['capabilities'];
        $surfaces = [];
        foreach (array_merge(
            (array) ($capabilities['entity_sections'] ?? []),
            (array) ($capabilities['field_sections'] ?? [])
        ) as $section) {
            if (!is_string($section) || $section === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has a malformed capability section");
            }
            $surfaces[] = $section;
            $value = $manifest[$section] ?? null;
            if (is_array($value) && !array_is_list($value)) {
                foreach (array_keys($value) as $key) {
                    $surfaces[] = $section . '.' . $key;
                }
            }
        }
        foreach ((array) (($capabilities['deletion_semantics']['supported'] ?? [])) as $selector) {
            if (!is_string($selector) || $selector === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has a malformed deletion selector");
            }
            $surfaces[] = 'deletions.' . $selector;
        }
        $surfaces = array_values(array_unique($surfaces, SORT_STRING));
        sort($surfaces, SORT_STRING);

        $operations = array_values((array) ($capabilities['operations'] ?? []));
        if (in_array('deploy', $operations, true) && in_array('apply', $operations, true)) {
            $operations[] = 'promote';
        }
        foreach ($operations as $operation) {
            if (!is_string($operation) || $operation === '') {
                throw new \RuntimeException("duo: manifest disposition '$name' has a malformed operation");
            }
        }
        $operations = array_values(array_unique($operations, SORT_STRING));
        sort($operations, SORT_STRING);

        return [
            'name' => $name,
            'status' => $status,
            'reason' => (string) ($disposition['reason'] ?? ''),
            'plugin_execution' => $execution,
            'authored_state' => [
                'status' => $status === 'excluded' ? 'unsupported' : $status,
                'scope' => 'only the exact registered surfaces and operations below',
            ],
            'supported_versions' => $disposition['supported_versions'] ?? new \stdClass(),
            'environment_assumptions' => [
                'site_mode' => $platform['site_mode'] ?? null,
                'php' => $platform['compatibility']['php'] ?? new \stdClass(),
                'database' => $platform['compatibility']['database'] ?? new \stdClass(),
                'wordpress' => $platform['compatibility']['wordpress'] ?? new \stdClass(),
            ],
            'operations' => $operations,
            'surfaces' => $surfaces,
            'lifecycle_phases' => $capabilities['lifecycle_phases'] ?? [],
            'deletion_semantics' => $capabilities['deletion_semantics'] ?? new \stdClass(),
            'unsupported' => $disposition['unsupported'] ?? [],
            'evidence' => $evidence,
            'platform' => $platform,
        ];
    }

    /**
     * Reuse the shipped disposition semantics for a separately authenticated
     * site-adapter entry.  The caller owns source/signature/path checks; this
     * narrow helper keeps section, version, capability, and evidence grammar
     * identical instead of growing a second long-lived validator beside it.
     */
    public static function validate_external_entry(
        string $name,
        array $entry,
        array $manifest,
        string $evidenceSchema,
        bool $requireExerciseTests = true
    ): void {
        if ($name === '' || ($entry['status'] ?? null) !== 'certified') {
            throw new \RuntimeException(
                "duo: external manifest disposition '$name' must be a certified entry"
            );
        }
        self::validate_entry($name, $entry, $manifest, $evidenceSchema, $requireExerciseTests);
    }

    /** @return list<array{name:string,status:string,reason:string}> */
    public function blockers(array $manifests): array {
        $out = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $entry = $this->entry($name);
            if ($entry === null) {
                // DUO-3372: an uncovered manifest is a BLOCKER, not a skip. On
                // the live path load()'s one-for-one coverage check refuses it
                // earlier, so this is unreachable there — but silently dropping
                // it here would let a direct caller read a manifest with no
                // reviewed disposition as ready, which is exactly the "a
                // manifest cannot certify itself merely by existing" doctrine
                // this file states. `uncovered` is a synthesized runtime status
                // only; validate_entry() still refuses it as a DECLARED status.
                $out[] = [
                    'name' => $name,
                    'status' => self::STATUS_UNCOVERED,
                    'reason' => self::UNCOVERED_REASON,
                ];
                continue;
            }
            if (($entry['status'] ?? null) === 'certified') {
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
                // DUO-3372: surface the uncovered manifest as an explicit row
                // rather than dropping it from the report — the top-level
                // `blockers`/`ready` already reflect it (blockers() above), and
                // a per-manifest report that silently omitted it would disagree
                // with its own blocker list. Minimal shape: there is no entry to
                // resolve entity/field sections from.
                $rows[] = [
                    'name' => $name,
                    'status' => self::STATUS_UNCOVERED,
                    'reason' => self::UNCOVERED_REASON,
                ];
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
            'registry_sha256' => $this->sha256(),
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
                || ($profile['evidence']['bundle_schema'] ?? null) !== self::EVIDENCE_SCHEMA
                || !self::string_list($profile['evidence']['tests'] ?? null, false, true)) {
                throw new \RuntimeException("duo: manifest disposition profile '$name' is malformed");
            }
        }
    }

    /**
     * $requireExerciseTests is false ONLY for an external site-adapter entry
     * whose bundle declares `evidence.exercised: false` (round-3 T6). The
     * caller has already proved that shape is admissible — a site trust root,
     * an empty bundle test set, no artifacts — so requiring a citation here
     * would force the certificate to name a test that provably does not exist.
     * Every SHIPPED registry row keeps the default: a reviewed certified claim
     * with no named evidence is the thing this validator exists to refuse.
     */
    private static function validate_entry(
        string $name,
        $entry,
        array $manifest,
        string $evidenceSchema = self::EVIDENCE_SCHEMA,
        bool $requireExerciseTests = true
    ): void {
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
                || ($evidence['bundle_schema'] ?? null) !== $evidenceSchema
                || !self::string_list($evidence['tests'] ?? null, !$requireExerciseTests, true)) {
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

    private static function string_list($value, bool $allowEmpty = true, bool $canonicalSlugs = false): bool {
        if (!is_array($value) || !array_is_list($value) || (!$allowEmpty && $value === [])) {
            return false;
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === ''
                || ($canonicalSlugs && preg_match('/^[a-z][a-z0-9-]*$/D', $item) !== 1)) {
                return false;
            }
        }
        return count(array_unique($value, SORT_STRING)) === count($value);
    }
}
