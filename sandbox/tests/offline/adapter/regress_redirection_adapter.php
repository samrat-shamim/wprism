<?php
/**
 * Redirection 5.9.0 manifest/provider production boundary.
 *
 * The fixture uses the three action_data shapes measured through Red_Item's
 * native writer and primes the lookup cache at the pre-apply generation. The
 * provider must rotate that generation, prove fresh native reads, and refuse
 * server-file modules or malformed native projections before claiming apply.
 */
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../lib/check.php';

    define('ARRAY_A', 'ARRAY_A');
    define('REDIRECTION_VERSION', '5.9.0');

    $GLOBALS['red_multisite'] = false;
    $GLOBALS['red_options'] = ['cache_key' => 41];
    $GLOBALS['red_initial_cache_key'] = 41;
    $GLOBALS['red_option_saves'] = 0;
    $GLOBALS['red_flushes'] = [];
    $GLOBALS['red_cache_resets'] = 0;
    $GLOBALS['red_group_api_mismatch'] = false;
    $GLOBALS['red_item_api_mismatch'] = false;

    /** @return list<array<string,mixed>> */
    function red_fixture_groups(): array {
        return [
            [
                'id' => '7', 'name' => 'Redirections', 'tracking' => '1',
                'module_id' => '1', 'status' => 'enabled', 'position' => '0',
            ],
            [
                'id' => '11', 'name' => 'Summer campaign 東京 🚀', 'tracking' => '0',
                'module_id' => '1', 'status' => 'enabled', 'position' => '1',
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    function red_fixture_items(): array {
        return [
            [
                'id' => '18', 'url' => '/summer', 'match_url' => '/summer', 'match_data' => null,
                'regex' => '0', 'position' => '0', 'group_id' => '11', 'status' => 'enabled',
                'action_type' => 'url', 'action_code' => '302',
                'action_data' => 'https://source.example.test/summer-marketplace/',
                'match_type' => 'url', 'title' => 'Summer marketplace',
            ],
            [
                'id' => '19', 'url' => '^/marketplace/vendor/(.*)$', 'match_url' => 'regex',
                'match_data' => '{"source":{"flag_regex":true}}', 'regex' => '1',
                'position' => '1', 'group_id' => '11', 'status' => 'enabled',
                'action_type' => 'url', 'action_code' => '307',
                'action_data' => 'https://source.example.test/providers/$1',
                'match_type' => 'url', 'title' => 'Vendor route',
            ],
            [
                'id' => '20', 'url' => '/offers', 'match_url' => '/offers', 'match_data' => null,
                'regex' => '0', 'position' => '2', 'group_id' => '11', 'status' => 'enabled',
                'action_type' => 'url', 'action_code' => '302',
                'action_data' => serialize([
                    'language' => 'fr',
                    'url_from' => 'https://source.example.test/fr/offres/',
                    'url_notfrom' => 'https://source.example.test/offers/',
                ]),
                'match_type' => 'language', 'title' => 'Localized offers',
            ],
            [
                'id' => '21', 'url' => '/retired-service', 'match_url' => '/retired-service',
                'match_data' => null, 'regex' => '0', 'position' => '3', 'group_id' => '11',
                'status' => 'disabled', 'action_type' => 'error', 'action_code' => '410',
                'action_data' => null, 'match_type' => 'url', 'title' => null,
            ],
        ];
    }

    $GLOBALS['red_groups'] = red_fixture_groups();
    $GLOBALS['red_items'] = red_fixture_items();

    function is_multisite(): bool {
        return $GLOBALS['red_multisite'];
    }

    function wp_json_encode(mixed $value, int $flags = 0): string|false {
        return json_encode($value, $flags);
    }

    final class RedirectionWpdb {
        public string $prefix = 'wp_';
        public string $last_error = '';
        public bool $failReads = false;

        /** @return list<array<string,mixed>>|false */
        public function get_results(string $sql, string $mode): array|false {
            if ($this->failReads) {
                $this->last_error = 'fixture read failed';
                return false;
            }
            $this->last_error = '';
            if (str_contains($sql, 'redirection_groups')) {
                return $GLOBALS['red_groups'];
            }
            if (str_contains($sql, 'redirection_items')) {
                return $GLOBALS['red_items'];
            }
            return [];
        }
    }

    $GLOBALS['wpdb'] = new RedirectionWpdb();

    final class WordPress_Module {
        public const MODULE_ID = 1;
    }

    final class Red_Module {
        public static function flush_by_module(int $moduleId): void {
            $GLOBALS['red_flushes'][] = $moduleId;
        }
    }

    final class Red_Options {
        /** @return array<string,mixed> */
        public static function get(): array {
            return $GLOBALS['red_options'];
        }

        /** @param array<string,mixed> $settings @return array<string,mixed> */
        public static function save(array $settings): array {
            $GLOBALS['red_option_saves']++;
            $GLOBALS['red_options'] = array_replace($GLOBALS['red_options'], $settings);
            return $GLOBALS['red_options'];
        }

        public static function reset(): void {}
    }

    final class Redirect_Cache {
        private static ?self $instance = null;

        public static function init(): self {
            return self::$instance ??= new self();
        }

        public function reset(): void {
            $GLOBALS['red_cache_resets']++;
        }
    }

    final class Red_Group {
        /** @param array<string,mixed> $row */
        public function __construct(private array $row) {}

        public static function get(int $id, bool $clear = false): self|false {
            foreach ($GLOBALS['red_groups'] as $row) {
                if ((int) $row['id'] === $id) {
                    if ($GLOBALS['red_group_api_mismatch']) {
                        $row['name'] = 'stale group';
                    }
                    return new self($row);
                }
            }
            return false;
        }

        public function get_id(): int { return (int) $this->row['id']; }
        public function get_name(): string { return (string) $this->row['name']; }
        public function get_module_id(): int { return (int) $this->row['module_id']; }
        public function is_enabled(): bool { return $this->row['status'] === 'enabled'; }
    }

    final class Red_Action {
        public static function create(string $name, int $code): ?self {
            return in_array($name, ['url', 'error', 'nothing', 'random', 'pass'], true)
                ? new self()
                : null;
        }
    }

    final class Red_Match {
        public static function create(string $name, mixed $data = ''): ?self {
            return in_array(
                $name,
                ['url', 'referrer', 'agent', 'login', 'header', 'custom', 'cookie', 'role', 'server', 'ip', 'page', 'language'],
                true
            ) ? new self() : null;
        }
    }

    final class Red_Item {
        /** @param array<string,mixed> $row */
        public function __construct(private array $row) {}

        public static function get_by_id(int $id): self|false {
            foreach ($GLOBALS['red_items'] as $row) {
                if ((int) $row['id'] === $id) {
                    if ($GLOBALS['red_item_api_mismatch']) {
                        $row['url'] = '/stale-target-row';
                    }
                    return new self($row);
                }
            }
            return false;
        }

        /** @return list<self> */
        public static function get_for_matched_url(string $url): array {
            $key = (int) ($GLOBALS['red_options']['cache_key'] ?? 0);
            if ($key > 0 && $key <= (int) $GLOBALS['red_initial_cache_key']) {
                return [];
            }
            return array_values(array_map(
                static fn(array $row): self => new self($row),
                array_filter(
                    $GLOBALS['red_items'],
                    static fn(array $row): bool => $row['status'] === 'enabled'
                )
            ));
        }

        public function get_id(): int { return (int) $this->row['id']; }
        public function get_url(): string { return (string) $this->row['url']; }
        public function get_match_url(): string { return (string) $this->row['match_url']; }
        public function is_regex(): bool { return (int) $this->row['regex'] === 1; }
        public function get_position(): int { return (int) $this->row['position']; }
        public function get_group_id(): int { return (int) $this->row['group_id']; }
        public function is_enabled(): bool { return $this->row['status'] === 'enabled'; }
        public function get_action_type(): string { return (string) $this->row['action_type']; }
        public function get_action_code(): int { return (int) $this->row['action_code']; }
        public function get_match_type(): string { return (string) $this->row['match_type']; }
        public function get_title(): string { return (string) ($this->row['title'] ?? ''); }

        /** @return array<string,mixed> */
        public function to_sql(): array {
            return ['action_data' => $this->row['action_data']];
        }
    }
}

namespace Duo {
    final class Policy {}

    final class Providers {
        public const SCOPED_OPERATION_FORMAT = 'duo-scoped-effect-operation/v1';
    }
}

namespace {
    require_once dirname(__DIR__, 4) . '/manifests/providers/redirection-state.php';

    use Duo\Policy;
    use Duo\Providers\RedirectionState;

    $root = dirname(__DIR__, 4);
    $manifest = json_decode(
        (string) file_get_contents($root . '/manifests/redirection.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    duo_check_same('redirection', $manifest['name'] ?? null,
        'A1: the shipped manifest names Redirection');
    duo_check_same(['max' => '5.9.1', 'min' => '5.9.0'], $manifest['version_range'] ?? null,
        'A1: the manifest admits only the one exact exercised release');
    duo_check_same(
        ['mixed-column-codecs/v1', 'spec-window/v1', 'structured-evidence/v1', 'typed-column-codecs/v1'],
        $manifest['engine_features'] ?? null,
        'A2: the mixed codec and every v3 section are feature-gated'
    );
    duo_check_same(
        ['container' => 'php_serialized_or_text', 'leaves' => 'text'],
        $manifest['column_codecs']['redirection_items']['action_data'] ?? null,
        'A2: action_data declares the measured plain/serialized/NULL union'
    );
    duo_check_same('runtime', $manifest['tables']['redirection_logs']['class'] ?? null,
        'A3: redirect request logs stay target-local');
    duo_check_same('runtime', $manifest['tables']['redirection_404']['class'] ?? null,
        'A3: 404 request logs stay target-local');
    duo_check_same('runtime', $manifest['tables']['redirection_items']['columns']['last_count']['class'] ?? null,
        'A3: hit counts never enter authored state');
    duo_check_same('red_group', $manifest['tables']['redirection_groups']['id_kind'] ?? null,
        'A4: groups use a bounded mapped identity keyspace');
    duo_check_same(
        [['column' => 'group_id', 'kind' => 'red_group', 'table' => 'redirection_groups']],
        $manifest['tables']['redirection_items']['refs'] ?? null,
        'A4: item group ids are explicit cross-environment references'
    );
    duo_check_same('red_group', $manifest['options']['redirection_options']['sub_keys']['monitor_post']['ref'] ?? null,
        'A4: the selected monitor group is remapped instead of copied numerically');
    duo_check_same('derived', $manifest['options']['redirection_options']['sub_keys']['cache_key']['class'] ?? null,
        'A5: the cache generation is provider-owned derived state');
    duo_check_same('env', $manifest['options']['redirection_options']['sub_keys']['modules']['class'] ?? null,
        'A5: server-module configuration stays environment-owned');
    duo_check_same([], $manifest['deletions'] ?? [],
        'A6: no custom-table deletion authority is advertised');
    $sourceSeed = (string) file_get_contents($root . '/sandbox/conformance/seeds/redirection.sh');
    $targetSeed = (string) file_get_contents($root . '/sandbox/conformance/postdeploy/redirection.sh');
    foreach (['source' => $sourceSeed, 'target' => $targetSeed] as $side => $script) {
        duo_check(str_contains($script, 'redirection database install 2>&1')
            && str_contains($script, 'SHOW TABLES LIKE %s')
            && str_contains($script, '.groups >= 2')
            && str_contains($script, 'all(. == true)'),
            "A7: $side live fixture completes and verifies Redirection's public onboarding before authoring rows");
    }
    duo_check(str_contains($targetSeed, '.manifests = ["core", "redirection"]')
        && str_contains($targetSeed, '.policy.post_types = []')
        && str_contains($targetSeed, '.policy.taxonomies = []')
        && str_contains($targetSeed, '.policy.scope.taxonomy.category.class = "runtime"')
        && str_contains($targetSeed, '.policy.options.default_category.class = "runtime"')
        && str_contains($targetSeed, 'update_option("sidebars_widgets"')
        && str_contains($targetSeed, 'update_option("widget_block"')
        && str_contains($targetSeed, 'trap restore_redirection_identity_widgets EXIT')
        && str_contains($targetSeed, 'RESTORED_WIDGET_HASH')
        && str_contains($targetSeed, '--repo=/siterepo/.tmp-redirection-identity-repo')
        && str_contains($targetSeed, '--out=/siterepo/.tmp-redirection-identity-state')
        && str_contains($targetSeed, 'skips canonical duo_state/media publication')
        && !str_contains($targetSeed, 'duo capture --repo=/siterepo --out=/siterepo/.tmp-redirection-target-identity'),
        'A8: output-only target mapping retains core grammar without rebasing canonical conflict state');
    $matrixSeed = (string) file_get_contents($root . '/sandbox/tests/certify/matrix.d/redirection.sh');
    duo_check(str_contains($matrixSeed, 'wp2 redirection database install')
        && str_contains($matrixSeed, 'Redirection 5.9.0 boundary target database readiness')
        && str_contains($matrixSeed, 'SHOW TABLES LIKE %s')
        && str_contains($matrixSeed, '.groups >= 2')
        && str_contains($matrixSeed, 'all(. == true)'),
        'A9: exact-version target completes and verifies the same native onboarding before table reset');

    $provider = new RedirectionState(new Policy());
    duo_check_same(
        ['id' => 'redirection-state', 'plugin' => 'redirection/redirection.php', 'version' => '1.0.0'],
        $provider->identity(),
        'B1: provider identity exactly matches the manifest declaration'
    );
    $capability = $provider->capabilities()['rebuild_redirect_state'] ?? null;
    duo_check(is_array($capability)
        && ($capability['idempotent'] ?? null) === true
        && ($capability['scope'] ?? null) === 'site'
        && ($capability['scoped']['reconcile'] ?? null) === true,
        'B1: provider advertises a scoped, recoverable site postcondition');

    $receipt = $provider->invoke('rebuild_redirect_state', []);
    duo_check(($receipt['verified'] ?? null) === true,
        'B2: happy-path provider returns a verified receipt');
    duo_check_same([1], $GLOBALS['red_flushes'],
        'B2: provider reaches only the WordPress module flush boundary');
    duo_check_same(1, $GLOBALS['red_option_saves'],
        'B2: a primed cache generation is rotated exactly once');
    duo_check((int) $receipt['after']['cache_key'] > 41 && $GLOBALS['red_cache_resets'] >= 1,
        'B2: the plugin cache generation advances and its process cache resets');
    duo_check_same(2, $receipt['after']['group_count'] ?? null,
        'B3: provider receipt proves the full group cardinality');
    duo_check_same(4, $receipt['after']['item_count'] ?? null,
        'B3: provider receipt proves every mixed-shape item');
    $receiptJson = json_encode($receipt, JSON_UNESCAPED_SLASHES);
    duo_check(is_string($receiptJson)
        && !str_contains($receiptJson, 'source.example.test')
        && !str_contains($receiptJson, '/summer'),
        'B3: count/hash receipt exposes no redirect source or target value');

    $beforeReconcile = (int) $GLOBALS['red_options']['cache_key'];
    $scoped = $provider->reconcile_scoped(
        'rebuild_redirect_state',
        [],
        ['format' => 'duo-scoped-effect-operation/v1', 'id' => 'fixture']
    );
    duo_check(($scoped['verified'] ?? null) === true
        && (int) $scoped['after']['cache_key'] > $beforeReconcile,
        'B4: recovery reconciliation replays the idempotent repair and re-verifies it');

    $GLOBALS['red_options']['cache_key'] = 0;
    $GLOBALS['red_initial_cache_key'] = 0;
    $GLOBALS['red_option_saves'] = 0;
    $disabled = $provider->invoke('rebuild_redirect_state', []);
    duo_check_same(0, $GLOBALS['red_option_saves'],
        'B5: disabled plugin cache mode is preserved rather than enabled implicitly');
    duo_check_same(false, $disabled['after']['cache_enabled'] ?? null,
        'B5: disabled cache mode still passes native row readback');

    duo_check_throws(
        static fn(): array => $provider->invoke('unknown', []),
        RuntimeException::class,
        'C1: undeclared provider capability refuses',
        'does not implement capability'
    );

    $GLOBALS['red_multisite'] = true;
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C2: multisite refuses before effect',
        'single-site'
    );
    $GLOBALS['red_multisite'] = false;

    $GLOBALS['red_groups'][1]['module_id'] = '2';
    $beforeFlushes = count($GLOBALS['red_flushes']);
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C3: Apache group refuses before server-file effects',
        'Apache/Nginx'
    );
    duo_check_same($beforeFlushes, count($GLOBALS['red_flushes']),
        'C3: unsupported server module is rejected before public flush dispatch');
    $GLOBALS['red_groups'] = red_fixture_groups();

    $GLOBALS['red_items'][0]['group_id'] = '999';
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C4: orphan redirect refuses before cache rotation',
        'no supported WordPress-module group'
    );
    $GLOBALS['red_items'] = red_fixture_items();

    $GLOBALS['red_items'][0]['action_type'] = 'extension_action';
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C5: unreviewed action vocabulary refuses',
        'outside the exact built-in'
    );
    $GLOBALS['red_items'] = red_fixture_items();

    $GLOBALS['red_items'][0]['match_data'] = '{bad-json';
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C6: malformed native match JSON refuses',
        'not valid JSON'
    );
    $GLOBALS['red_items'] = red_fixture_items();

    $GLOBALS['red_options']['cache_key'] = 50;
    $GLOBALS['red_initial_cache_key'] = 50;
    $GLOBALS['red_group_api_mismatch'] = true;
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C7: stale group API readback prevents a success receipt',
        'group API did not converge'
    );
    $GLOBALS['red_group_api_mismatch'] = false;

    $GLOBALS['red_options']['cache_key'] = 60;
    $GLOBALS['red_initial_cache_key'] = 60;
    $GLOBALS['red_item_api_mismatch'] = true;
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C8: same-count stale item readback prevents a success receipt',
        'item API did not converge'
    );
    $GLOBALS['red_item_api_mismatch'] = false;

    $GLOBALS['wpdb']->failReads = true;
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_redirect_state', []),
        RuntimeException::class,
        'C9: schema/read failure refuses instead of hashing an empty projection',
        'verification query failed'
    );

    duo_check_summary('Redirection adapter/provider');
}
