<?php
namespace Duo;

/**
 * Generated, evidence-bound product capability claims.
 *
 * Manifest dispositions remain the reviewed source facts. This registry is
 * their generated product-facing projection: it binds those facts to the
 * exact adapter digest, platform/runtime boundary, and one current
 * content-addressed certification bundle. Missing or mismatched data never
 * inherits a broad compatibility assumption.
 */
final class CapabilityRegistry {
    public const FORMAT = 'duo-capability-registry/v1';
    public const EVIDENCE_FORMAT = 'duo-capability-evidence/v1';

    private array $data;

    private function __construct(array $data) {
        $this->data = $data;
    }

    public static function load(string $manifestDir, ManifestDispositions $dispositions, array $manifests): ?self {
        $file = rtrim($manifestDir, '/') . '/capabilities/registry.json';
        if (!is_file($file)) {
            return null;
        }
        $data = Canon::decode(Canon::read_file($file));
        self::validate($data, $dispositions, $manifests, "capability registry '$file'", $manifestDir, true);
        return new self($data);
    }

    /** Revalidate frozen bytes without reopening the mutable registry file. */
    public static function from_snapshot(
        array $data,
        ManifestDispositions $dispositions,
        array $manifests
    ): self {
        self::validate($data, $dispositions, $manifests, 'frozen capability registry', self::manifests_dir(), false);
        return new self($data);
    }

    public function data(): array {
        return $this->data;
    }

    public function claim(string $name): ?array {
        $claim = $this->data['manifests'][$name] ?? null;
        return is_array($claim) ? $claim : null;
    }

    public function profiles(): array {
        return $this->data['profiles'];
    }

    /**
     * The exact digest RepositoryCompiler exposes for a pinned adapter.
     * Keep one implementation: registry generation/validation and compiled
     * artifacts must never disagree about the bytes that identify a claim.
     */
    public static function adapter_digest(
        array $manifest,
        ?array $disposition,
        ?string $manifestDir = null
    ): string {
        $name = (string) ($manifest['name'] ?? '');
        $row = [
            'name' => $name,
            'manifest' => $manifest,
            'disposition' => $disposition,
        ];
        $interpreter = $manifest['interpreter'] ?? null;
        if (is_string($interpreter) && $interpreter !== '') {
            $dir = $manifestDir ?? self::manifests_dir();
            $file = rtrim($dir, '/') . '/interpreters/' . basename($interpreter) . '.php';
            $row['interpreter'] = [
                'name' => $interpreter,
                'sha256' => is_file($file) ? hash_file('sha256', $file) : null,
            ];
        }
        return hash('sha256', Canon::encode($row));
    }

    /**
     * Return product-readiness blockers for selected manifests. With no live
     * target facts this is the immutable/source gate. A real wp-cli request
     * supplies target facts and additionally proves runtime compatibility.
     */
    public function blockers(array $manifests, array $query = [], ?array $target = null): array {
        return $this->report($manifests, $query, $target)['blockers'];
    }

    /** Machine-readable view consumed by CLI, readiness, and host promotion. */
    public function report(array $manifests, array $query = [], ?array $target = null): array {
        $operation = (string) ($query['operation'] ?? 'promote');
        $surface = isset($query['surface']) ? (string) $query['surface'] : null;
        $revision = isset($query['revision']) ? (string) $query['revision'] : null;
        $rows = [];
        $blockers = [];

        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            $claim = $this->claim($name);
            $reasons = [];
            if ($claim === null) {
                $reasons[] = self::reason('missing_registry_entry', "no capability registry entry exists for '$name'");
                $claim = [
                    'name' => $name,
                    'status' => 'unsupported',
                    'plugin_execution' => ['mode' => 'unknown', 'status' => 'unsupported'],
                    'authored_state' => ['status' => 'unsupported'],
                    'supported_versions' => new \stdClass(),
                    'operations' => [],
                    'surfaces' => [],
                    'unsupported' => [],
                ];
            } else {
                if (($claim['status'] ?? null) !== 'certified') {
                    $reasons[] = self::reason(
                        'authored_state_not_certified',
                        "$name authored state is " . ($claim['status'] ?? 'unsupported') . ', not certified'
                    );
                }
                if (!in_array($operation, $claim['operations'] ?? [], true)) {
                    $reasons[] = self::reason(
                        'operation_not_certified',
                        "$operation is not certified for '$name'"
                    );
                }
                if ($surface !== null && $surface !== '') {
                    $surfaceReason = self::surface_reason($claim, $surface, $operation);
                    if ($surfaceReason !== null) {
                        $reasons[] = $surfaceReason;
                    }
                }
            }

            if (($this->data['evidence']['status'] ?? null) !== 'current') {
                $reasons[] = self::reason('evidence_not_current', 'the bound certification evidence is not current');
            }
            if ($revision !== null && $revision !== ''
                && !hash_equals((string) ($this->data['evidence']['git_revision'] ?? ''), $revision)) {
                $reasons[] = self::reason(
                    'revision_not_certified',
                    "revision $revision is not the evidence-bound platform revision"
                );
            }
            if ($target !== null) {
                $reasons = array_merge($reasons, $this->target_reasons($claim, $target));
            }

            $verdict = $reasons === [] ? 'certified' : 'blocked';
            $row = $claim;
            $row['verdict'] = ['status' => $verdict, 'reasons' => $reasons];
            $rows[] = $row;
            foreach ($reasons as $reason) {
                $blockers[] = [
                    'name' => $name,
                    'status' => $verdict,
                    'code' => $reason['code'],
                    'reason' => $reason['message'],
                ];
            }
        }

        return [
            'schema_version' => self::FORMAT,
            'registry_sha256' => hash('sha256', Canon::encode($this->data)),
            'platform' => $this->data['platform'],
            'evidence' => $this->data['evidence'],
            'query' => [
                'operation' => $operation,
                'surface' => $surface,
                'revision' => $revision ?? $this->data['evidence']['git_revision'],
            ],
            'target' => $target,
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'manifests' => $rows,
            'profiles' => $this->profiles(),
        ];
    }

    /** Collect only facts needed to decide the certified target boundary. */
    public static function probe_target(): ?array {
        if (!function_exists('get_bloginfo')) {
            return null;
        }
        global $wpdb;
        $databaseServer = is_object($wpdb) && method_exists($wpdb, 'get_var')
            ? (string) $wpdb->get_var('SELECT VERSION()')
            : '';
        $plugins = [];
        $themes = [];
        return [
            'wordpress' => (string) get_bloginfo('version'),
            'php' => PHP_VERSION,
            'database' => [
                'client' => is_object($wpdb) && method_exists($wpdb, 'db_version') ? (string) $wpdb->db_version() : '',
                'server' => $databaseServer,
                'engine' => stripos($databaseServer, 'mariadb') !== false ? 'MariaDB' : 'unknown',
            ],
            'multisite' => function_exists('is_multisite') ? (bool) is_multisite() : false,
            'active_plugins' => function_exists('get_option')
                ? array_values((array) get_option('active_plugins', []))
                : [],
            'active_theme' => function_exists('get_option')
                ? ['template' => (string) get_option('template'), 'stylesheet' => (string) get_option('stylesheet')]
                : ['template' => '', 'stylesheet' => ''],
            'plugins' => $plugins,
            'themes' => $themes,
        ];
    }

    private function target_reasons(array $claim, array $target): array {
        $reasons = [];
        $platform = $this->data['platform'];
        if (!empty($target['multisite'])) {
            $reasons[] = self::reason('multisite_unsupported', 'the certified v1 registry is single-site only');
        }
        $wordpress = (string) ($target['wordpress'] ?? '');
        $verifiedWordPress = (string) ($platform['compatibility']['wordpress']['last_verified'] ?? '');
        if ($wordpress === '' || $verifiedWordPress === '' || version_compare($wordpress, $verifiedWordPress, '!=')) {
            $reasons[] = self::reason(
                'wordpress_version_mismatch',
                "target WordPress " . ($wordpress !== '' ? $wordpress : '(unknown)')
                . " is not the evidence-bound $verifiedWordPress"
            );
        }
        $php = (string) ($target['php'] ?? '');
        if (!self::inside_range($php, $platform['compatibility']['php'] ?? null)) {
            $reasons[] = self::reason('php_version_mismatch', "target PHP $php is outside the certified range");
        }
        $database = is_array($target['database'] ?? null) ? $target['database'] : [];
        $dbCompat = $platform['compatibility']['database'] ?? [];
        $dbVersion = self::numeric_version((string) ($database['server'] ?? ''));
        if (($database['engine'] ?? null) !== ($dbCompat['engine'] ?? null)
            || !self::inside_range($dbVersion, $dbCompat)) {
            $reasons[] = self::reason(
                'database_version_mismatch',
                'target database ' . (($database['engine'] ?? 'unknown') . ' ' . ($database['server'] ?? 'unknown'))
                . ' is outside the certified boundary'
            );
        }

        $supported = is_array($claim['supported_versions'] ?? null) ? $claim['supported_versions'] : [];
        $plugin = $supported['plugin'] ?? null;
        $range = $supported['range'] ?? null;
        if (is_string($plugin) && $plugin !== '' && $plugin !== 'unbound') {
            $installed = isset($target['plugins'][$plugin])
                ? (string) $target['plugins'][$plugin]
                : self::installed_plugin_version($plugin);
            if ($installed === null || !self::inside_range($installed, $range)) {
                $reasons[] = self::reason(
                    'plugin_version_mismatch',
                    "$plugin " . ($installed ?? '(missing)') . ' is outside the certified range'
                );
            }
            if (isset($target['active_plugins'])
                && !in_array($plugin, (array) $target['active_plugins'], true)) {
                $reasons[] = self::reason('plugin_not_active', "$plugin is not active on the evaluated target");
            }
        }
        $theme = $supported['theme'] ?? null;
        $themeRange = $supported['theme_range'] ?? null;
        if (is_string($theme) && $theme !== '') {
            $installed = isset($target['themes'][$theme])
                ? (string) $target['themes'][$theme]
                : (function_exists('wp_get_theme') ? (string) wp_get_theme($theme)->get('Version') : '');
            if ($installed === '' || !self::inside_range($installed, $themeRange)) {
                $reasons[] = self::reason('theme_version_mismatch', "$theme $installed is outside the certified range");
            }
            $activeTheme = is_array($target['active_theme'] ?? null) ? $target['active_theme'] : [];
            if ($activeTheme !== [] && !in_array($theme, $activeTheme, true)) {
                $reasons[] = self::reason('theme_not_active', "$theme is not active on the evaluated target");
            }
        }
        return $reasons;
    }

    private static function installed_plugin_version(string $plugin): ?string {
        if (!defined('WP_PLUGIN_DIR')) {
            return null;
        }
        $file = rtrim(WP_PLUGIN_DIR, '/') . '/' . ltrim($plugin, '/');
        if (!is_file($file)) {
            return null;
        }
        if (!function_exists('get_plugin_data') && defined('ABSPATH')) {
            $include = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_file($include)) {
                require_once $include;
            }
        }
        if (!function_exists('get_plugin_data')) {
            return null;
        }
        $data = get_plugin_data($file, false, false);
        $version = is_array($data) ? (string) ($data['Version'] ?? '') : '';
        return $version !== '' ? $version : null;
    }

    private static function manifests_dir(): string {
        if (class_exists(Policy::class)) {
            return Policy::manifests_dir();
        }
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        return dirname(__DIR__, 2) . '/manifests';
    }

    private static function surface_reason(array $claim, string $surface, string $operation): ?array {
        foreach ($claim['unsupported'] ?? [] as $unsupported) {
            $unsupportedOperation = (string) ($unsupported['operation'] ?? '');
            if (($unsupported['surface'] ?? null) === $surface
                && in_array($unsupportedOperation, ['all', $operation], true)) {
                return self::reason('surface_explicitly_unsupported', (string) $unsupported['reason']);
            }
        }
        if (!in_array($surface, $claim['surfaces'] ?? [], true)) {
            return self::reason('surface_not_registered', "surface '$surface' is absent from the certified registry entry");
        }
        return null;
    }

    private static function inside_range(string $version, $range): bool {
        return $version !== '' && is_array($range)
            && is_string($range['min'] ?? null) && is_string($range['max'] ?? null)
            && version_compare($version, $range['min'], '>=')
            && version_compare($version, $range['max'], '<');
    }

    private static function numeric_version(string $raw): string {
        return preg_match('/([0-9]+(?:\.[0-9]+){1,3})/', $raw, $m) === 1 ? $m[1] : '';
    }

    private static function reason(string $code, string $message): array {
        return ['code' => $code, 'message' => $message];
    }

    private static function validate(
        array $data,
        ManifestDispositions $dispositions,
        array $manifests,
        string $label,
        string $manifestDir,
        bool $validateSourceFiles
    ): void {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['evidence', 'format', 'generated_from', 'manifests', 'platform', 'profiles']
            || ($data['format'] ?? null) !== self::FORMAT
            || !is_array($data['platform'] ?? null) || array_is_list($data['platform'])
            || !is_array($data['evidence'] ?? null) || array_is_list($data['evidence'])
            || !is_array($data['manifests'] ?? null) || array_is_list($data['manifests'])
            || !is_array($data['profiles'] ?? null)
            || !is_array($data['generated_from'] ?? null) || array_is_list($data['generated_from'])) {
            throw new \RuntimeException("duo: $label has an unsupported or malformed root");
        }
        if (($data['evidence']['format'] ?? null) !== self::EVIDENCE_FORMAT
            || ($data['evidence']['bundle_schema'] ?? null) !== ManifestDispositions::BUNDLE_SCHEMA
            || !preg_match('/^[0-9a-f]{64}$/', (string) ($data['evidence']['bundle_digest'] ?? ''))
            || !in_array($data['evidence']['status'] ?? null, ['current', 'candidate'], true)) {
            throw new \RuntimeException("duo: $label has no content-addressed certification evidence record");
        }
        if (($data['platform']['agent_version'] ?? null) !== (defined('DUO_AGENT_VERSION') ? DUO_AGENT_VERSION : '0.5.0')
            || ($data['platform']['spec_version'] ?? null) !== (defined('DUO_SPEC_VERSION') ? DUO_SPEC_VERSION : 2)) {
            throw new \RuntimeException("duo: $label platform version disagrees with the loaded agent");
        }
        $dispositionFile = rtrim($manifestDir, '/') . '/dispositions.json';
        $evidenceFile = rtrim($manifestDir, '/') . '/capabilities/evidence.json';
        $compatibilityFile = dirname(rtrim($manifestDir, '/')) . '/docs/compatibility-baseline.json';
        if ($validateSourceFiles && is_file($dispositionFile)
            && !hash_equals(
                (string) ($data['generated_from']['dispositions_sha256'] ?? ''),
                (string) hash_file('sha256', $dispositionFile)
            )) {
            throw new \RuntimeException("duo: $label is stale against dispositions.json");
        }
        if ($validateSourceFiles && is_file($evidenceFile)
            && !hash_equals(
                (string) ($data['generated_from']['evidence_sha256'] ?? ''),
                (string) hash_file('sha256', $evidenceFile)
            )) {
            throw new \RuntimeException("duo: $label is stale against its certification evidence attestation");
        }
        if ($validateSourceFiles && is_file($compatibilityFile)
            && !hash_equals(
                (string) ($data['generated_from']['compatibility_sha256'] ?? ''),
                (string) hash_file('sha256', $compatibilityFile)
            )) {
            throw new \RuntimeException("duo: $label is stale against the compatibility baseline");
        }
        $passingTests = [];
        foreach ($data['evidence']['tests'] ?? [] as $test) {
            if (is_array($test) && ($test['verdict'] ?? null) === 'pass' && is_string($test['id'] ?? null)) {
                $passingTests[$test['id']] = true;
            }
        }

        $manifestByName = [];
        foreach ($manifests as $manifest) {
            $manifestByName[(string) ($manifest['name'] ?? '')] = $manifest;
        }
        $declaredClaims = array_keys($dispositions->data()['manifests']);
        $registryClaims = array_keys($data['manifests']);
        sort($declaredClaims, SORT_STRING);
        sort($registryClaims, SORT_STRING);
        if ($declaredClaims !== $registryClaims) {
            throw new \RuntimeException("duo: $label manifest coverage disagrees with the disposition registry");
        }
        foreach ($manifestByName as $name => $manifest) {
            $claim = $data['manifests'][$name] ?? null;
            $disposition = $dispositions->entry($name);
            if (!is_array($claim) || array_is_list($claim)
                || ($claim['name'] ?? null) !== $name
                || !in_array($claim['status'] ?? null, ['certified', 'experimental', 'excluded'], true)
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($claim['adapter_digest'] ?? ''))
                || !is_array($claim['operations'] ?? null) || !array_is_list($claim['operations'])
                || !is_array($claim['surfaces'] ?? null) || !array_is_list($claim['surfaces'])
                || !is_array($claim['plugin_execution'] ?? null)
                || !is_array($claim['authored_state'] ?? null)) {
                throw new \RuntimeException("duo: $label manifest claim '$name' is malformed");
            }
            $actualDigest = self::adapter_digest($manifest, $disposition, $manifestDir);
            if (!hash_equals((string) $claim['adapter_digest'], $actualDigest)) {
                throw new \RuntimeException("duo: $label adapter digest for '$name' is stale");
            }
            if (($claim['status'] ?? null) !== ($disposition['status'] ?? null)) {
                throw new \RuntimeException("duo: $label status for '$name' disagrees with its disposition");
            }
            if (($claim['status'] ?? null) === 'certified') {
                foreach ($claim['evidence']['tests'] ?? [] as $test) {
                    if (!isset($passingTests[$test])) {
                        throw new \RuntimeException(
                            "duo: $label certified claim '$name' cites absent or non-passing evidence '$test'"
                        );
                    }
                }
            }
        }
        $dispositionProfiles = $dispositions->profiles();
        $declaredProfiles = array_keys($dispositionProfiles);
        $registryProfiles = array_keys($data['profiles']);
        sort($declaredProfiles, SORT_STRING);
        sort($registryProfiles, SORT_STRING);
        if ($declaredProfiles !== $registryProfiles) {
            throw new \RuntimeException("duo: $label profile coverage disagrees with the disposition registry");
        }
        foreach ($data['profiles'] as $name => $profile) {
            $source = $dispositionProfiles[$name] ?? null;
            if (!is_array($profile) || array_is_list($profile)
                || !isset($data['manifests'][$profile['manifest'] ?? ''])
                || !in_array($profile['status'] ?? null, ['certified', 'experimental'], true)
                || !is_array($source)
                || ($profile['manifest'] ?? null) !== ($source['manifest'] ?? null)
                || ($profile['status'] ?? null) !== ($source['status'] ?? null)
                || ($profile['reason'] ?? null) !== ($source['reason'] ?? null)
                || ($profile['supported_versions'] ?? null) !== ($source['supported_versions'] ?? null)
                || ($profile['scope'] ?? null) !== ($source['scope'] ?? null)
                || ($profile['adapter_digest'] ?? null)
                    !== ($data['manifests'][$profile['manifest']]['adapter_digest'] ?? null)) {
                throw new \RuntimeException("duo: $label profile '$name' is malformed");
            }
            if (($profile['status'] ?? null) === 'certified') {
                foreach ($profile['evidence']['tests'] ?? [] as $test) {
                    if (!isset($passingTests[$test])) {
                        throw new \RuntimeException(
                            "duo: $label certified profile '$name' cites absent or non-passing evidence '$test'"
                        );
                    }
                }
            }
        }
    }
}
