<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;

/** Checkpoint-backed completion gate for Visual Portfolio's native DB migrations. */
final class VisualPortfolioMigrations extends ManifestProviderRuntime {
    private const CURRENT_VERSION = '3.8.1';
    private const MAX_ROWS = 4096;
    private const MAX_RAW_BYTES = 4194304;

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_settle_storage(array $args): array {
        self::arguments($args);
        self::runtime();

        $first = $this->migration_pass();
        if ($this->durable_snapshot() !== $first['after']) {
            self::refuse('committed migration state changed before readback; recovery_required');
        }
        $second = $this->migration_pass();
        if ($first['after'] !== $second['before'] || $second['before'] !== $second['after']) {
            self::refuse('native migration did not reach a bounded durable fixed point; recovery_required');
        }
        if ($this->durable_snapshot() !== $second['after']) {
            self::refuse('fixed-point migration state changed after commit; recovery_required');
        }

        return ['before' => $first['before'], 'after' => $second['after'], 'verified' => true];
    }

    /** Independent physical readback in the engine's second fresh WordPress boot. */
    protected function observe_fresh_postimage_settle_storage(array $args): array {
        self::arguments($args);
        return self::physical_state();
    }

    /** @return array{cursor:string,options_sha256:string,posts_sha256:string,postmeta_sha256:string} */
    protected function project_fresh_postimage_settle_storage(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        if ($keys !== ['cursor', 'options_sha256', 'postmeta_sha256', 'posts_sha256']
            || $value['cursor'] !== self::CURRENT_VERSION) {
            self::refuse('migration postimage is malformed or version-incomplete; recovery_required');
        }
        foreach (['options_sha256', 'posts_sha256', 'postmeta_sha256'] as $key) {
            if (!is_string($value[$key]) || preg_match('/^[a-f0-9]{64}$/D', $value[$key]) !== 1) {
                self::refuse('migration postimage is malformed or version-incomplete; recovery_required');
            }
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private function durable_snapshot(): array {
        return ProviderSdk::database_read_contract_snapshot(
            'Visual Portfolio durable migration state',
            static fn(): array => self::physical_state()
        );
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>} */
    private function migration_pass(): array {
        return ProviderSdk::database_write_contract_transaction(
            'Visual Portfolio native migration',
            static function (): array {
                $before = self::physical_state();
                self::assert_automatic_migration_boundary($before['cursor']);
                if ($before['cursor'] !== self::CURRENT_VERSION) {
                    (new \Visual_Portfolio_Migrations())->init();
                }
                $after = self::physical_state();
                if ($after['cursor'] !== self::CURRENT_VERSION) {
                    self::refuse('native migration did not advance the exact storage version; recovery_required');
                }
                return ['before' => $before, 'after' => $after];
            },
            static function (array $result): string {
                $actual = self::physical_state();
                if ($actual === $result['after']) {
                    return ProviderSdk::DATABASE_POSTIMAGE_APPLIED;
                }
                if ($actual === $result['before']) {
                    return ProviderSdk::DATABASE_POSTIMAGE_NOT_APPLIED;
                }
                return ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN;
            }
        );
    }

    private static function assert_automatic_migration_boundary(?string $cursor): void {
        $general = ProviderSdk::checked_durable_option(
            'vp_general',
            false,
            'Visual Portfolio legacy migration settings'
        );
        $savedVersion = $cursor ?? '1.16.2';
        if (version_compare($savedVersion, '2.15.0', '<')
            && is_array($general)
            && isset($general['portfolio_slug'])) {
            self::refuse(
                'legacy archive-slug migration requires native maintenance with irreversible rewrite effects'
            );
        }
    }

    /**
     * This is the exact 3.8.1 migration surface: the named option rows, every
     * page/saved-layout post, the post named by the legacy archive option, and
     * every Visual Portfolio metadata row. The native callbacks walk the same
     * rows (classes/class-migration.php:44-306).
     *
     * @return array{cursor:?string,options_sha256:string,posts_sha256:string,postmeta_sha256:string}
     */
    private static function physical_state(): array {
        global $wpdb;
        if (!is_object($wpdb)) {
            self::refuse('migration database runtime is unavailable');
        }
        foreach (['options', 'posts', 'postmeta'] as $property) {
            $table = $wpdb->{$property} ?? null;
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                self::refuse('migration table identity is malformed');
            }
        }

        $options = self::rows(
            "SELECT option_name, option_value, autoload FROM `{$wpdb->options}` "
                . "WHERE BINARY option_name IN ("
                . "'_transient_timeout_vp_flush_rewrite_rules', '_transient_vp_flush_rewrite_rules', "
                . "'_vp_add_archive_page', '_vp_trying_to_add_archive_page', 'vp_general', 'vp_images', "
                . "'vp_popup_gallery', 'vpf_db_version') ORDER BY option_name LIMIT 4097",
            'Visual Portfolio migration options'
        );
        $cursorRows = array_values(array_filter(
            $options['rows'],
            static fn(array $row): bool => ($row['option_name'] ?? null) === 'vpf_db_version'
        ));
        if (count($cursorRows) > 1) {
            self::refuse('migration cursor is physically ambiguous');
        }
        $cursor = $cursorRows[0]['option_value'] ?? null;
        if ($cursor !== null && (!is_string($cursor) || strlen($cursor) > 64
            || preg_match('/^[0-9A-Za-z._-]+$/D', $cursor) !== 1)) {
            self::refuse('migration cursor is malformed');
        }

        $posts = self::rows(
            "SELECT * FROM `{$wpdb->posts}` WHERE BINARY post_type IN ('page', 'vp_lists') "
                . 'ORDER BY ID LIMIT 4097',
            'Visual Portfolio migration posts'
        );
        $archiveId = self::archive_post_id($options['rows']);
        if ($archiveId !== null) {
            $archive = self::rows(
                "SELECT * FROM `{$wpdb->posts}` WHERE ID = {$archiveId} ORDER BY ID LIMIT 2",
                'Visual Portfolio legacy archive post'
            );
            if (count($archive['rows']) > 1) {
                self::refuse('legacy archive post identity is physically ambiguous');
            }
            $byId = [];
            foreach (array_merge($posts['rows'], $archive['rows']) as $row) {
                $id = $row['ID'] ?? null;
                if (!is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1) {
                    self::refuse('migration post identity is malformed');
                }
                $byId[$id] = $row;
            }
            ksort($byId, SORT_NUMERIC);
            $posts = self::project_rows(array_values($byId));
        }
        $postmeta = self::rows(
            "SELECT * FROM `{$wpdb->postmeta}` WHERE BINARY LEFT(meta_key, 3) = 'vp_' "
                . "OR BINARY LEFT(meta_key, 4) = '_vp_' ORDER BY meta_id LIMIT 4097",
            'Visual Portfolio migration post metadata'
        );

        return [
            'cursor' => $cursor,
            'options_sha256' => $options['sha256'],
            'posts_sha256' => $posts['sha256'],
            'postmeta_sha256' => $postmeta['sha256'],
        ];
    }

    /** @return array{rows:list<array<string,mixed>>,sha256:string} */
    private static function rows(string $sql, string $context): array {
        $rows = ProviderSdk::checked_get_results(
            $sql,
            $context,
            null,
            'wprism: Visual Portfolio migration projection could not read its bounded physical rows'
        );
        return self::project_rows($rows);
    }

    /** @param list<array<string,mixed>> $rows
     *  @return array{rows:list<array<string,mixed>>,sha256:string}
     */
    private static function project_rows(array $rows): array {
        if (count($rows) > self::MAX_ROWS) {
            self::refuse('migration projection exceeds its row boundary');
        }
        $bytes = serialize($rows);
        if (strlen($bytes) > self::MAX_RAW_BYTES) {
            self::refuse('migration projection exceeds its byte boundary');
        }
        return ['rows' => $rows, 'sha256' => hash('sha256', $bytes)];
    }

    /** @param list<array<string,mixed>> $options */
    private static function archive_post_id(array $options): ?int {
        $values = [];
        foreach ($options as $row) {
            if (($row['option_name'] ?? null) === '_vp_add_archive_page') {
                $values[] = $row['option_value'] ?? null;
            }
        }
        if (count($values) > 1) {
            self::refuse('legacy archive option is physically ambiguous');
        }
        $value = $values[0] ?? null;
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || (string) (int) $value !== $value) {
            self::refuse('legacy archive option is not a bounded post identity');
        }
        return (int) $value;
    }

    private static function runtime(): void {
        if (is_multisite()
            || !defined('VISUAL_PORTFOLIO_VERSION')
            || VISUAL_PORTFOLIO_VERSION !== self::CURRENT_VERSION
            || !class_exists('Visual_Portfolio_Migrations', false)) {
            self::refuse('requires initialized single-site Visual Portfolio 3.8.1 migration authority');
        }
    }

    private static function arguments(array $args): void {
        if ($args !== []) {
            self::refuse('storage settlement accepts no authored arguments');
        }
    }

    private static function refuse(string $reason): never {
        throw new \RuntimeException('wprism: Visual Portfolio ' . $reason);
    }
}
