<?php
declare(strict_types=1);

namespace {
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../lib/FakeWpdb.php';
    require_once __DIR__ . '/../../support/wp_cli_child_process_fake.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PlainData.php';

    $GLOBALS['nf_provider_multisite'] = false;
    $GLOBALS['nf_provider_command_calls'] = [];
    $GLOBALS['nf_provider_command_result'] = null;
    $GLOBALS['nf_provider_command_throw'] = null;
    $GLOBALS['nf_provider_after_command'] = null;
    $GLOBALS['nf_provider_wakeup_count'] = 0;

    function is_multisite(): bool {
        return $GLOBALS['nf_provider_multisite'];
    }

    final class WP_CLI {
        use \DuoTest\WpCliChildRuntime;

        public static function runcommand(string $command, array $options): mixed {
            $GLOBALS['nf_provider_command_calls'][] = [$command, $options];
            if ($GLOBALS['nf_provider_command_throw'] instanceof \Throwable) {
                throw $GLOBALS['nf_provider_command_throw'];
            }
            if (is_callable($GLOBALS['nf_provider_after_command'])) {
                ($GLOBALS['nf_provider_after_command'])();
            }
            $result = $GLOBALS['nf_provider_command_result'];
            return is_callable($result) ? $result() : $result;
        }
    }

    final class NinjaFormsCacheWakeupProbe {
        public function __wakeup(): void {
            $GLOBALS['nf_provider_wakeup_count']++;
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
    require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderSdk.php';
    require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ManifestProviderRuntime.php';
    require_once dirname(__DIR__, 4) . '/manifests/providers/ninja-forms-form-cache.php';

    use Duo\Providers\NinjaFormsFormCache;
    use DuoTest\FakeWpdb;

    /** @return array<string,array<string,string>> */
    function nf_provider_columns(): array {
        $columns = [
            'nf3_forms' => [
                'id', 'title', 'key', 'created_at', 'updated_at', 'views', 'subs',
                'form_title', 'default_label_pos', 'show_title', 'clear_complete',
                'hide_complete', 'logged_in', 'seq_num',
            ],
            'nf3_form_meta' => ['id', 'parent_id', 'key', 'value', 'meta_key', 'meta_value'],
            'nf3_fields' => [
                'id', 'label', 'key', 'type', 'parent_id', 'created_at', 'updated_at',
                'field_label', 'field_key', 'order', 'required', 'default_value',
                'label_pos', 'personally_identifiable',
            ],
            'nf3_field_meta' => ['id', 'parent_id', 'key', 'value', 'meta_key', 'meta_value'],
            'nf3_actions' => [
                'id', 'title', 'key', 'type', 'active', 'parent_id', 'created_at',
                'updated_at', 'label',
            ],
            'nf3_action_meta' => ['id', 'parent_id', 'key', 'value', 'meta_key', 'meta_value'],
            'nf3_upgrades' => ['id', 'cache', 'stage', 'maintenance'],
            'options' => ['option_id', 'option_name', 'option_value', 'autoload'],
        ];
        $out = [];
        foreach ($columns as $table => $names) {
            foreach ($names as $name) {
                $out[$table][$name] = $name === 'id' || $name === 'parent_id' ? 'bigint' : 'longtext';
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    function nf_provider_row(string $table, array $values): array {
        $columns = nf_provider_columns()[$table];
        return array_replace(array_fill_keys(array_keys($columns), ''), $values);
    }

    function nf_provider_cache_fingerprint(): string {
        /** @var FakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        $rows = $db->rows('nf3_upgrades');
        usort($rows, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $projection = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            $maintenance = $row['maintenance'] ?? null;
            if ($maintenance === 0 || $maintenance === '0' || $maintenance === "\0") {
                $maintenance = 0;
            } elseif ($maintenance === 1 || $maintenance === '1' || $maintenance === "\1") {
                $maintenance = 1;
            } else {
                throw new \RuntimeException('fixture cache maintenance flag is invalid');
            }
            $projection[$id] = [
                'cache_sha256' => hash('sha256', (string) ($row['cache'] ?? '')),
                'stage' => (int) ($row['stage'] ?? -1),
                'maintenance' => $maintenance,
            ];
        }
        ksort($projection, SORT_NUMERIC);
        return hash('sha256', serialize($projection));
    }

    /** @return object{return_code:int,stdout:string,stderr:string} */
    function nf_provider_result(int $forms = 2, ?string $cacheFingerprint = null): object {
        return (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'duo-ninja-forms-cache-rebuild/v1',
                'form_count' => $forms,
                'rebuilt_form_count' => $forms,
                'cache_fingerprint' => $cacheFingerprint ?? nf_provider_cache_fingerprint(),
                'verified' => true,
            ], JSON_UNESCAPED_SLASHES),
            'stderr' => '',
        ];
    }

    /** @return array<string,mixed> */
    function nf_provider_cache(int $formId): array {
        /** @var FakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        $forms = array_values(array_filter(
            $db->rows('nf3_forms'),
            static fn(array $row): bool => (int) $row['id'] === $formId
        ));
        $fields = array_values(array_filter(
            $db->rows('nf3_fields'),
            static fn(array $row): bool => (int) $row['parent_id'] === $formId
        ));
        $actions = array_values(array_filter(
            $db->rows('nf3_actions'),
            static fn(array $row): bool => (int) $row['parent_id'] === $formId
        ));
        usort($fields, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        usort($actions, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        return [
            'id' => $formId,
            'fields' => array_map(static fn(array $row): array => [
                'settings' => ['type' => (string) $row['type'], 'label' => (string) $row['label']],
                'id' => (int) $row['id'],
            ], $fields),
            'actions' => array_map(static fn(array $row): array => [
                'settings' => ['type' => (string) $row['type'], 'label' => (string) $row['label']],
                'id' => (int) $row['id'],
            ], $actions),
            'settings' => ['title' => (string) ($forms[0]['title'] ?? '')],
        ];
    }

    function nf_provider_rebuild(): void {
        /** @var FakeWpdb $db */
        $db = $GLOBALS['wpdb'];
        $forms = $db->rows('nf3_forms');
        usort($forms, static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $rows = [];
        foreach ($forms as $form) {
            $id = (int) $form['id'];
            $rows[] = [
                'id' => $id,
                'cache' => serialize(nf_provider_cache($id)),
                'stage' => 14,
                'maintenance' => 0,
            ];
        }
        $db->seedTable('nf3_upgrades', $rows);
        $db->seedTable('options', array_values(array_filter(
            $db->rows('options'),
            static fn(array $row): bool => preg_match(
                '/^nf_form_[1-9][0-9]*$/D',
                (string) ($row['option_name'] ?? '')
            ) !== 1
        )));
    }

    function nf_provider_reset(): NinjaFormsFormCache {
        $GLOBALS['nf_provider_multisite'] = false;
        $GLOBALS['nf_provider_command_calls'] = [];
        $GLOBALS['nf_provider_command_throw'] = null;
        $GLOBALS['nf_provider_wakeup_count'] = 0;
        $GLOBALS['duo_wp_cli_child_fake_stderr_first'] = false;
        $GLOBALS['nf_provider_after_command'] = static function (): void {
            nf_provider_rebuild();
        };
        $GLOBALS['nf_provider_command_result'] = static fn(): object => nf_provider_result();

        $db = FakeWpdb::install();
        foreach (nf_provider_columns() as $table => $columns) {
            $db->setColumns($table, $columns);
        }
        $secret = 'nf-live-secret-sk_test_readiness_123456789';
        $db->seedTable('nf3_forms', [
            nf_provider_row('nf3_forms', [
                'id' => 1,
                'title' => 'Job Application — مرحبا',
                'key' => 'job_application',
                'form_title' => 'Job Application — مرحبا',
                'created_at' => '2026-08-23 00:00:00',
                'updated_at' => '2026-08-23 00:00:00',
            ]),
            nf_provider_row('nf3_forms', [
                'id' => 900000001,
                'title' => 'Large identity form',
                'key' => 'large_identity',
                'form_title' => 'Large identity form',
                'created_at' => '2026-08-23 00:00:01',
                'updated_at' => '2026-08-23 00:00:01',
            ]),
        ]);
        $db->seedTable('nf3_form_meta', [
            nf_provider_row('nf3_form_meta', [
                'id' => 101, 'parent_id' => 1,
                'key' => 'success-msg', 'value' => 'Thanks — ありがとう',
                'meta_key' => 'success-msg', 'meta_value' => 'Thanks — ありがとう',
            ]),
            nf_provider_row('nf3_form_meta', [
                'id' => 102, 'parent_id' => 900000001,
                'key' => 'form_title', 'value' => 'Large identity form',
                'meta_key' => 'form_title', 'meta_value' => 'Large identity form',
            ]),
            nf_provider_row('nf3_form_meta', [
                'id' => 103, 'parent_id' => 1,
                'key' => 'seq_num', 'value' => null,
                'meta_key' => 'seq_num', 'meta_value' => null,
            ]),
        ]);
        $db->seedTable('nf3_fields', [
            nf_provider_row('nf3_fields', [
                'id' => 11, 'parent_id' => 1, 'type' => 'textbox',
                'label' => 'Name', 'key' => 'name', 'field_label' => 'Name', 'field_key' => 'name',
            ]),
            nf_provider_row('nf3_fields', [
                'id' => 12, 'parent_id' => 1, 'type' => 'email',
                'label' => 'Email', 'key' => 'email', 'field_label' => 'Email', 'field_key' => 'email',
            ]),
            nf_provider_row('nf3_fields', [
                'id' => 900000011, 'parent_id' => 900000001, 'type' => 'textarea',
                'label' => 'Payload', 'key' => 'payload', 'field_label' => 'Payload', 'field_key' => 'payload',
            ]),
        ]);
        $db->seedTable('nf3_field_meta', [
            nf_provider_row('nf3_field_meta', [
                'id' => 201, 'parent_id' => 11,
                'key' => 'placeholder', 'value' => 'Full name',
                'meta_key' => 'placeholder', 'meta_value' => 'Full name',
            ]),
            nf_provider_row('nf3_field_meta', [
                'id' => 202, 'parent_id' => 12,
                'key' => 'admin_email', 'value' => $secret,
                'meta_key' => 'admin_email', 'meta_value' => $secret,
            ]),
            nf_provider_row('nf3_field_meta', [
                'id' => 203, 'parent_id' => 900000011,
                'key' => 'options', 'value' => serialize(['nested' => ['utf8' => 'こんにちは']]),
                'meta_key' => 'options', 'meta_value' => serialize(['nested' => ['utf8' => 'こんにちは']]),
            ]),
        ]);
        $db->seedTable('nf3_actions', [
            nf_provider_row('nf3_actions', [
                'id' => 21, 'parent_id' => 1, 'type' => 'email',
                'title' => 'Email owner', 'label' => 'Email owner', 'key' => 'email_owner', 'active' => 1,
            ]),
            nf_provider_row('nf3_actions', [
                'id' => 900000021, 'parent_id' => 900000001, 'type' => 'successmessage',
                'title' => 'Success', 'label' => 'Success', 'key' => 'success', 'active' => 1,
            ]),
        ]);
        $db->seedTable('nf3_action_meta', [
            nf_provider_row('nf3_action_meta', [
                'id' => 301, 'parent_id' => 21,
                'key' => 'subject', 'value' => 'New application',
                'meta_key' => 'subject', 'meta_value' => 'New application',
            ]),
            nf_provider_row('nf3_action_meta', [
                'id' => 302, 'parent_id' => 900000021,
                'key' => 'message', 'value' => 'Saved',
                'meta_key' => 'message', 'meta_value' => 'Saved',
            ]),
        ]);
        $db->seedTable('nf3_upgrades', [
            ['id' => 1, 'cache' => serialize([
                'id' => 1, 'fields' => [], 'actions' => [], 'settings' => ['title' => 'stale'],
            ]), 'stage' => 2, 'maintenance' => 0],
            ['id' => 77, 'cache' => serialize([
                'id' => 77, 'fields' => [], 'actions' => [], 'settings' => ['title' => 'orphan'],
            ]), 'stage' => 2, 'maintenance' => 0],
        ]);
        $db->seedTable('options', [
            ['option_id' => 1, 'option_name' => 'nf_form_1', 'option_value' => serialize(['secret' => $secret]), 'autoload' => 'yes'],
            ['option_id' => 2, 'option_name' => 'nf_form_77', 'option_value' => serialize(['orphan' => $secret]), 'autoload' => 'yes'],
            ['option_id' => 3, 'option_name' => 'ninja_forms_target_runtime', 'option_value' => 'target-owned', 'autoload' => 'yes'],
        ]);
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/ninja-forms.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        return new NinjaFormsFormCache($manifest['providers'][0]);
    }

    /** @return array<string,string> */
    function nf_provider_operation(): array {
        return [
            'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
            'authority_hash' => str_repeat('a', 64),
            'lease_session_id' => 'ninja-readiness-session',
            'operation_id' => 'ninja-readiness-operation',
            'input_hash' => str_repeat('b', 64),
            'effect_hash' => str_repeat('c', 64),
        ];
    }

    /** @return string */
    function nf_provider_throw_message(callable $call): string {
        try {
            $call();
        } catch (\Throwable $t) {
            return $t->getMessage();
        }
        throw new \RuntimeException('expected Ninja Forms provider refusal');
    }

    /** @param callable(list<array<string,mixed>>):list<array<string,mixed>> $mutate */
    function nf_provider_mutate_projection_after_child(callable $mutate): void {
        nf_provider_rebuild();
        $GLOBALS['nf_provider_command_result'] = nf_provider_result();
        $rows = $GLOBALS['wpdb']->rows('nf3_upgrades');
        $GLOBALS['wpdb']->seedTable('nf3_upgrades', $mutate($rows));
    }

    function nf_provider_mutate_cache_after_child(callable $mutate): void {
        nf_provider_mutate_projection_after_child(static function (array $rows) use ($mutate): array {
            $rows[0]['cache'] = $mutate((string) $rows[0]['cache']);
            return $rows;
        });
    }

    function nf_provider_expect_invalid_cache(string $label, callable $mutate): void {
        $provider = nf_provider_reset();
        $GLOBALS['nf_provider_after_command'] = static function () use ($mutate): void {
            nf_provider_mutate_cache_after_child($mutate);
        };
        duo_check_throws(
            static fn(): array => $provider->invoke('rebuild_form_caches', []),
            \RuntimeException::class,
            $label,
            'invalid_form_caches'
        );
    }

    $provider = nf_provider_reset();
    duo_check_same(
        ['id' => 'ninja-forms-form-cache', 'plugin' => 'ninja-forms/ninja-forms.php', 'version' => '2.2.0'],
        $provider->identity(),
        'provider identity makes the strengthened fresh-process contract fleet-visible'
    );
    duo_check_same(
        [
            'args' => [],
            'reads' => [
                'table:nf3_forms', 'table:nf3_form_meta', 'table:nf3_fields',
                'table:nf3_field_meta', 'table:nf3_actions', 'table:nf3_action_meta',
                'table:options',
            ],
            'writes' => ['table:nf3_upgrades', 'entity:ninja-forms-legacy-form-option'],
            'scope' => 'site',
            'idempotent' => true,
            'timeout_seconds' => 300,
            'scoped' => [
                'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                'reconcile' => true,
            ],
        ],
        $provider->capabilities()['rebuild_form_caches'] ?? null,
        'provider declares every authored read, derived write, scope, timeout and recovery property'
    );
    duo_check_throws(
        static fn(): array => $provider->invoke('unknown', []),
        \RuntimeException::class,
        'unknown full invocation capability refuses',
        'does not implement capability'
    );
    duo_check_throws(
        static fn(): array => $provider->reconcile_scoped('unknown', [], nf_provider_operation()),
        \RuntimeException::class,
        'unknown reconciliation capability refuses',
        'does not implement capability'
    );

    $receipt = $provider->invoke_scoped('rebuild_form_caches', [], nf_provider_operation());
    duo_check_same(true, $receipt['verified'] ?? null, 'scoped cache rebuild verifies its postcondition');
    duo_check_same(nf_provider_operation(), $receipt['operation'] ?? null, 'receipt binds the exact scoped operation');
    duo_check_same(2, $receipt['before']['forms'] ?? null, 'before receipt counts the bounded form population');
    duo_check_same(3, $receipt['before']['form_meta_rows'] ?? null, 'native mirrored SQL NULL form metadata is admitted exactly');
    duo_check_same(2, $receipt['before']['cache_rows'] ?? null, 'before receipt records stale plus orphan cache rows');
    duo_check_same(1, $receipt['before']['missing_form_caches'] ?? null, 'before receipt detects the missing large-id cache');
    duo_check_same(1, $receipt['before']['orphan_form_caches'] ?? null, 'before receipt detects orphan target cache state');
    duo_check_same(2, $receipt['before']['invalid_form_caches'] ?? null, 'before receipt detects stale and orphan cache content');
    duo_check_same(2, $receipt['before']['legacy_form_caches'] ?? null, 'before receipt detects current-id and orphan legacy option caches');
    foreach (['missing_form_caches', 'orphan_form_caches', 'invalid_form_caches', 'maintenance_form_caches', 'legacy_form_caches'] as $field) {
        duo_check_same(0, $receipt['after'][$field] ?? null, "after receipt closes $field");
    }
    duo_check_same(2, $receipt['after']['cache_rows'] ?? null, 'after receipt has exactly one cache per authored form');
    duo_check_same(3, $receipt['after']['fields'] ?? null, 'after receipt binds the full field inventory');
    duo_check_same(2, $receipt['after']['actions'] ?? null, 'after receipt binds the full action inventory');
    duo_check(
        preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['source_fingerprint'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['cache_fingerprint'] ?? '')) === 1
            && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['legacy_cache_fingerprint'] ?? '')) === 1,
        'receipt exposes only bounded source/cache fingerprints'
    );
    $published = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    duo_check(
        is_string($published)
            && !str_contains($published, 'nf-live-secret')
            && !str_contains($published, 'Job Application')
            && !str_contains($published, 'こんにちは'),
        'receipt never exposes authored values, UTF-8 payloads, or credential-shaped data'
    );
    $childPublished = (string) nf_provider_result()->stdout;
    $childReceipt = json_decode($childPublished, true, 16, JSON_THROW_ON_ERROR);
    duo_check_same(
        ['format', 'form_count', 'rebuilt_form_count', 'cache_fingerprint', 'verified'],
        array_keys($childReceipt),
        'child receipt exposes only counts, a bounded exact cache fingerprint, and verification state'
    );
    duo_check(
        preg_match('/^[a-f0-9]{64}$/D', (string) ($childReceipt['cache_fingerprint'] ?? '')) === 1
            && !str_contains($childPublished, 'nf-live-secret')
            && !str_contains($childPublished, 'Job Application')
            && !str_contains($childPublished, 'こんにちは'),
        'child receipt is hash-only and never exposes cache settings or authored values'
    );
    duo_check_same(1, count($GLOBALS['nf_provider_command_calls']), 'provider launches exactly one fresh process');
    [$command, $options] = $GLOBALS['nf_provider_command_calls'][0];
    duo_check(
        str_starts_with($command, 'exec ') && str_contains($command, ' eval '),
        'provider uses the bounded fresh wp-cli eval process'
    );
    duo_check(
        str_contains($command, 'DELETE FROM `$cache`')
            && str_contains($command, 'WPN_Helper::build_nf_cache')
            && str_contains($command, 'serialize($expected)')
            && str_contains($command, 'hash_equals')
            && str_contains($command, '$cache_projection')
            && str_contains($command, 'cache_fingerprint')
            && str_contains($command, 'serialize($cache_projection)')
            && str_contains($command, 'nf_form_')
            && str_contains($command, 'delete_option($name)')
            && strpos($command, 'DELETE FROM `$cache`') < strpos($command, 'WPN_Helper::build_nf_cache'),
        'child purges before native build and binds its exact normalized cache projection into the receipt'
    );
    duo_check_same(
        ['launch' => true, 'return' => 'all', 'exit_error' => false],
        $options,
        'child invocation uses the isolated checked process receipt boundary'
    );
    duo_check_same([1, 900000001], array_map(
        static fn(array $row): int => (int) $row['id'],
        $GLOBALS['wpdb']->rows('nf3_upgrades')
    ), 'stale and orphan caches are replaced by exact small and large form identities');
    duo_check_same(
        ['ninja_forms_target_runtime'],
        array_map(static fn(array $row): string => (string) $row['option_name'], $GLOBALS['wpdb']->rows('options')),
        'legacy cache purge leaves unrelated target runtime options untouched'
    );

    $firstFingerprint = $receipt['after']['cache_fingerprint'];
    $retry = $provider->invoke_scoped('rebuild_form_caches', [], nf_provider_operation());
    duo_check_same($firstFingerprint, $retry['before']['cache_fingerprint'] ?? null, 'retry starts from the verified cache bytes');
    duo_check_same($firstFingerprint, $retry['after']['cache_fingerprint'] ?? null, 'idempotent retry preserves the exact cache projection');
    $reconciled = $provider->reconcile_scoped('rebuild_form_caches', [], nf_provider_operation());
    duo_check_same(true, $reconciled['verified'] ?? null, 'read-only recovery reconciliation verifies a healthy projection');
    duo_check_same($firstFingerprint, $reconciled['after']['cache_fingerprint'] ?? null, 'reconciliation observes the same exact cache projection');

    $provider = nf_provider_reset();
    nf_provider_rebuild();
    $rows = $GLOBALS['wpdb']->rows('nf3_upgrades');
    foreach ($rows as &$row) {
        $row['maintenance'] = "\0";
    }
    unset($row);
    $GLOBALS['wpdb']->seedTable('nf3_upgrades', $rows);
    $binaryZero = $provider->reconcile_scoped('rebuild_form_caches', [], nf_provider_operation());
    duo_check_same(
        0,
        $binaryZero['after']['maintenance_form_caches'] ?? null,
        'MariaDB BIT(1) binary zero is read as an exact non-maintenance flag'
    );

    $rows = $GLOBALS['wpdb']->rows('nf3_upgrades');
    $rows[0]['maintenance'] = "\1";
    $GLOBALS['wpdb']->seedTable('nf3_upgrades', $rows);
    duo_check_throws(
        static fn(): array => $provider->reconcile_scoped('rebuild_form_caches', [], nf_provider_operation()),
        \RuntimeException::class,
        'MariaDB BIT(1) binary one blocks recovery reconciliation',
        'maintenance_form_caches'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_multisite'] = true;
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'multisite scope refuses before any child process',
        'single-site tables only'
    );
    duo_check_same([], $GLOBALS['nf_provider_command_calls'], 'multisite refusal performs no mutation');

    $provider = nf_provider_reset();
    $columns = nf_provider_columns()['nf3_upgrades'];
    unset($columns['maintenance']);
    $GLOBALS['wpdb']->setColumns('nf3_upgrades', $columns);
    $rows = $GLOBALS['wpdb']->rows('nf3_upgrades');
    foreach ($rows as &$row) {
        unset($row['maintenance']);
    }
    unset($row);
    $GLOBALS['wpdb']->seedTable('nf3_upgrades', $rows);
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'schema drift refuses before cache mutation',
        'missing required column(s): maintenance'
    );

    $provider = nf_provider_reset();
    $GLOBALS['wpdb']->failNextQuery('schema probe token sk_schema_must_not_escape', 'SHOW COLUMNS FROM `wp_nf3_fields`');
    $message = nf_provider_throw_message(static fn(): array => $provider->invoke('rebuild_form_caches', []));
    duo_check(str_contains($message, 'schema probe failed') && !str_contains($message, 'sk_schema'), 'schema probe failures are loud and value-redacted');

    $provider = nf_provider_reset();
    $GLOBALS['wpdb']->failNextQuery('source query token sk_source_must_not_escape', 'SELECT * FROM `wp_nf3_field_meta`');
    $message = nf_provider_throw_message(static fn(): array => $provider->invoke('rebuild_form_caches', []));
    duo_check(str_contains($message, 'source inventory query failed') && !str_contains($message, 'sk_source'), 'authored source read failures are loud and value-redacted');

    $provider = nf_provider_reset();
    $rows = $GLOBALS['wpdb']->rows('nf3_fields');
    $rows[0]['parent_id'] = 999999;
    $GLOBALS['wpdb']->seedTable('nf3_fields', $rows);
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'orphan authored child identities refuse before rebuild',
        'field inventory has an invalid id or parent_id'
    );

    $provider = nf_provider_reset();
    $rows = $GLOBALS['wpdb']->rows('nf3_field_meta');
    $rows[0]['meta_value'] = 'diverged-current-value';
    $GLOBALS['wpdb']->seedTable('nf3_field_meta', $rows);
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'legacy/current metadata divergence refuses before rebuild',
        'meta legacy/current columns diverge'
    );

    $provider = nf_provider_reset();
    $rows = $GLOBALS['wpdb']->rows('nf3_form_meta');
    $rows[2]['meta_value'] = '';
    $GLOBALS['wpdb']->seedTable('nf3_form_meta', $rows);
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'one-sided native NULL metadata still refuses before rebuild',
        'form meta legacy/current columns diverge'
    );

    $provider = nf_provider_reset();
    $rows = $GLOBALS['wpdb']->rows('nf3_upgrades');
    $rows[0]['maintenance'] = 1;
    $GLOBALS['wpdb']->seedTable('nf3_upgrades', $rows);
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'plugin maintenance mode refuses instead of racing an upgrade',
        'maintenance mode'
    );
    duo_check_same([], $GLOBALS['nf_provider_command_calls'], 'maintenance refusal performs no mutation');

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_after_command'] = static function (): void {
        nf_provider_mutate_cache_after_child(static function (string $raw): string {
            $cache = unserialize($raw, ['allowed_classes' => false]);
            $cache['fields'][0]['id'] = 31337;
            return serialize($cache);
        });
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'wrong cached child identity refuses after native process success',
        'invalid_form_caches'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_after_command'] = static function (): void {
        nf_provider_mutate_cache_after_child(static fn(string $raw): string => str_repeat('x', 16777217));
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'cache larger than the reviewed 16 MiB boundary refuses before unserialize',
        'invalid_form_caches'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_after_command'] = static function (): void {
        nf_provider_rebuild();
        $GLOBALS['nf_provider_command_result'] = nf_provider_result();
        $rows = $GLOBALS['wpdb']->rows('nf3_forms');
        $rows[0]['title'] = 'concurrent source edit';
        $GLOBALS['wpdb']->seedTable('nf3_forms', $rows);
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'authored source race during child regeneration refuses recovery-required',
        'authored table graph changed'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_after_command'] = static function (): void {
        nf_provider_mutate_cache_after_child(static function (string $raw): string {
            $cache = unserialize($raw, ['allowed_classes' => false]);
            $cache['settings']['title'] = 'retained competing form settings';
            $cache['fields'][0]['settings']['label'] = 'retained competing field settings';
            $cache['actions'][0]['settings']['label'] = 'retained competing action settings';
            return serialize($cache);
        });
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'same-count same-identity retained cache rewrite cannot be blessed by the parent',
        'child cache projection disagrees with parent readback'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_after_command'] = static function (): void {
        nf_provider_mutate_projection_after_child(static function (array $rows): array {
            $rows[0]['stage'] = 15;
            return $rows;
        });
    };
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'retained stage rewrite cannot drift between child proof and parent readback',
        'child cache projection disagrees with parent readback'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_command_result'] = static fn(): object => nf_provider_result(
        2,
        str_repeat('d', 64)
    );
    duo_check_throws(
        static fn(): array => $provider->invoke('rebuild_form_caches', []),
        \RuntimeException::class,
        'success-shaped child receipt with a different exact projection refuses',
        'child cache projection disagrees with parent readback'
    );

    nf_provider_expect_invalid_cache(
        'canonical serialized cache refuses a valid prefix followed by trailing bytes',
        static fn(string $raw): string => $raw . 'TRAILING'
    );
    nf_provider_expect_invalid_cache(
        'malformed serialized cache refuses without accepting a partial outer structure',
        static fn(string $raw): string => 'a:4:{s:2:"id";i:1;'
    );
    nf_provider_expect_invalid_cache(
        'noncanonical serialized integer spelling refuses despite PHP accepting its value',
        static function (string $raw): string {
            $mutated = preg_replace('/s:2:"id";i:1;/', 's:2:"id";i:01;', $raw, 1);
            return is_string($mutated) ? $mutated : $raw;
        }
    );
    nf_provider_expect_invalid_cache(
        'serialized object cache refuses without invoking target-controlled wakeup code',
        static function (string $raw): string {
            $cache = unserialize($raw, ['allowed_classes' => false]);
            $cache['settings']['probe'] = new NinjaFormsCacheWakeupProbe();
            return serialize($cache);
        }
    );
    duo_check_same(0, $GLOBALS['nf_provider_wakeup_count'], 'object refusal executes no __wakeup side effect');
    nf_provider_expect_invalid_cache(
        'shared-reference serialized settings refuse as non-portable cache data',
        static function (string $raw): string {
            $cache = unserialize($raw, ['allowed_classes' => false]);
            $shared = ['credential' => 'sk_reference_must_not_escape'];
            $cache['settings']['left'] = &$shared;
            $cache['settings']['right'] = &$shared;
            return serialize($cache);
        }
    );
    nf_provider_expect_invalid_cache(
        'serialized settings beyond the reviewed plain-data depth refuse',
        static function (string $raw): string {
            $cache = unserialize($raw, ['allowed_classes' => false]);
            $deep = 'leaf';
            for ($depth = 0; $depth <= \Duo\PlainData::MAX_DEPTH + 2; $depth++) {
                $deep = ['next' => $deep];
            }
            $cache['settings']['deep'] = $deep;
            return serialize($cache);
        }
    );

    $hostile = 'child process token sk_child_must_not_escape';
    $validFingerprint = str_repeat('a', 64);
    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => '{}',
        'stderr' => str_repeat('credential-shaped-warning-', 4000),
    ];
    $GLOBALS['duo_wp_cli_child_fake_stderr_first'] = true;
    $stderrFirstMessage = nf_provider_throw_message(static fn(): array => $provider->invoke('rebuild_form_caches', []));
    duo_check(
        str_contains($stderrFirstMessage, 'emitted stderr despite exit 0')
            && !str_contains($stderrFirstMessage, 'credential-shaped'),
        'stderr-first output larger than a pipe reaches Ninja warning policy without leaking bytes'
    );

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_command_result'] = (object) [
        'return_code' => 0,
        'stdout' => str_repeat('credential-shaped-boot-output-', 12000),
        'stderr' => '',
    ];
    $overflowMessage = nf_provider_throw_message(static fn(): array => $provider->invoke('rebuild_form_caches', []));
    duo_check(
        str_contains($overflowMessage, 'fresh cache-rebuild process could not start')
            && !str_contains($overflowMessage, 'credential-shaped'),
        'Ninja wraps helper overflow in its stable command failure without a verified receipt or output leak'
    );

    $cases = [
        'unreadable result' => ['result' => ['not-an-object'], 'needle' => 'could not start'],
        'noninteger return code' => ['result' => (object) ['return_code' => '0', 'stdout' => '', 'stderr' => ''], 'needle' => 'could not start'],
        'nonzero exit' => ['result' => (object) ['return_code' => 9, 'stdout' => '', 'stderr' => $hostile], 'needle' => 'exited 9'],
        'stderr on success' => ['result' => (object) ['return_code' => 0, 'stdout' => '{}', 'stderr' => $hostile], 'needle' => 'emitted stderr'],
        'malformed json' => ['result' => (object) ['return_code' => 0, 'stdout' => '{' . $hostile, 'stderr' => ''], 'needle' => 'malformed receipt'],
        'extra receipt key' => ['result' => (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'duo-ninja-forms-cache-rebuild/v1', 'form_count' => 2,
                'rebuilt_form_count' => 2, 'cache_fingerprint' => $validFingerprint,
                'verified' => true, 'secret' => $hostile,
            ]),
            'stderr' => '',
        ], 'needle' => 'invalid receipt'],
        'missing fingerprint' => ['result' => (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'duo-ninja-forms-cache-rebuild/v1', 'form_count' => 2,
                'rebuilt_form_count' => 2, 'verified' => true,
            ]),
            'stderr' => '',
        ], 'needle' => 'invalid receipt'],
        'malformed fingerprint' => ['result' => (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'duo-ninja-forms-cache-rebuild/v1', 'form_count' => 2,
                'rebuilt_form_count' => 2, 'cache_fingerprint' => 'not-a-hash',
                'verified' => true,
            ]),
            'stderr' => '',
        ], 'needle' => 'invalid receipt'],
        'negative count' => ['result' => (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'duo-ninja-forms-cache-rebuild/v1', 'form_count' => -1,
                'rebuilt_form_count' => -1, 'cache_fingerprint' => $validFingerprint,
                'verified' => true,
            ]),
            'stderr' => '',
        ], 'needle' => 'invalid receipt'],
        'rebuilt count mismatch' => ['result' => (object) [
            'return_code' => 0,
            'stdout' => json_encode([
                'format' => 'duo-ninja-forms-cache-rebuild/v1', 'form_count' => 2,
                'rebuilt_form_count' => 1, 'cache_fingerprint' => $validFingerprint,
                'verified' => true,
            ]),
            'stderr' => '',
        ], 'needle' => 'invalid receipt'],
        'checked population mismatch' => [
            'result' => static fn(): object => nf_provider_result(1),
            'needle' => 'disagrees with the checked form population',
        ],
    ];
    foreach ($cases as $label => $case) {
        $provider = nf_provider_reset();
        $GLOBALS['nf_provider_command_result'] = $case['result'];
        $message = nf_provider_throw_message(static fn(): array => $provider->invoke('rebuild_form_caches', []));
        duo_check(
            str_contains($message, $case['needle']) && !str_contains($message, 'sk_child'),
            "$label refuses without exposing child output"
        );
    }

    $provider = nf_provider_reset();
    $GLOBALS['nf_provider_command_throw'] = new \RuntimeException($hostile);
    $message = nf_provider_throw_message(static fn(): array => $provider->invoke('rebuild_form_caches', []));
    duo_check(str_contains($message, 'could not start') && !str_contains($message, 'sk_child'), 'child launch exception is wrapped and value-redacted');

    $provider = nf_provider_reset();
    foreach (['nf3_forms', 'nf3_form_meta', 'nf3_fields', 'nf3_field_meta', 'nf3_actions', 'nf3_action_meta'] as $table) {
        $GLOBALS['wpdb']->seedTable($table, []);
    }
    $GLOBALS['nf_provider_command_result'] = static fn(): object => nf_provider_result(0);
    $receipt = $provider->invoke('rebuild_form_caches', []);
    duo_check_same(0, $receipt['after']['forms'] ?? null, 'zero-form population is a valid exact projection');
    duo_check_same(0, $receipt['after']['cache_rows'] ?? null, 'zero-form rebuild removes all stale/orphan cache rows');

    $provider = nf_provider_reset();
    nf_provider_rebuild();
    $rows = $GLOBALS['wpdb']->rows('nf3_upgrades');
    $rows[] = [
        'id' => 123456789,
        'cache' => serialize(['id' => 123456789, 'fields' => [], 'actions' => [], 'settings' => []]),
        'stage' => 14,
        'maintenance' => 0,
    ];
    $GLOBALS['wpdb']->seedTable('nf3_upgrades', $rows);
    duo_check_throws(
        static fn(): array => $provider->reconcile_scoped('rebuild_form_caches', [], nf_provider_operation()),
        \RuntimeException::class,
        'recovery reconciliation refuses an orphan cache instead of blessing it',
        'orphan_form_caches'
    );

    duo_check_summary('Ninja Forms cache provider');
}
