<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;

/**
 * Code Snippets 3.9.5/3.9.6 cache and optional flat-file repair.
 *
 * WPrism materializes the snippets table directly, bypassing save_snippet(),
 * clean_snippets_cache(), and Snippet_Files' create/update/delete hooks. A
 * stale in-process object-cache row can therefore hide the committed table,
 * while file-based execution can continue running the target's pre-apply PHP.
 * This provider clears the plugin cache, replaces only the current site's
 * hashed flat-file projection, rebuilds it through the plugin's own public
 * classes, and verifies DB/API/file agreement before returning a receipt.
 */
final class CodeSnippetsState extends ManifestProviderRuntime {
    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    protected function invoke_rebuild_snippet_state(array $args): array {
        $this->assert_runtime_contract();
        $before = $this->postcondition(false);
        $table = $this->table_name();

        \Code_Snippets\clean_snippets_cache($table);
        wp_cache_delete(\Code_Snippets\Settings\CACHE_KEY, \Code_Snippets\CACHE_GROUP);
        $settings = \Code_Snippets\Settings\get_settings_values();

        $this->purge_flat_projection();
        $filesystem = new \Code_Snippets\WordPress_File_System_Adapter();
        $files = new \Code_Snippets\Snippet_Files(
            \Code_Snippets\code_snippets()->snippet_handler_registry,
            $filesystem,
            new \Code_Snippets\Snippet_Config_Repository($filesystem)
        );
        $files->create_all_flat_files($settings);

        // Rebuild helpers read through the same cache group. Clear once more so
        // the verified API snapshot is necessarily a fresh post-repair read.
        \Code_Snippets\clean_snippets_cache($table);
        $after = $this->postcondition(true);

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array<string,mixed> */
    protected function reconcile_rebuild_snippet_state(array $args): array {
        return $this->postcondition(true);
    }

    private function assert_runtime_contract(): void {
        if (is_multisite()) {
            throw new \RuntimeException(
                'wprism: Code Snippets state provider is certified for single-site tables only'
            );
        }
        if (get_option('active_shared_network_snippets', false) !== false) {
            throw new \RuntimeException(
                'wprism: Code Snippets state provider refuses residual multisite snippet state on a single site'
            );
        }
        $plugin = \Code_Snippets\code_snippets();
        if (!is_object($plugin)
            || !isset($plugin->db, $plugin->snippet_handler_registry)
            || !is_object($plugin->db)
            || !is_object($plugin->snippet_handler_registry)) {
            throw new \RuntimeException(
                'wprism: Code Snippets 3.9.x database or flat-file API is unavailable'
            );
        }
    }

    private function table_name(): string {
        $this->assert_runtime_contract();
        $table = \Code_Snippets\code_snippets()->db->get_table_name(false);
        if (!is_string($table) || preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new \RuntimeException('wprism: Code Snippets returned an unsafe site table name');
        }
        return $table;
    }

    /** @return array<string,mixed> */
    private function postcondition(bool $verify): array {
        $this->assert_runtime_contract();
        wp_cache_delete(\Code_Snippets\Settings\CACHE_KEY, \Code_Snippets\CACHE_GROUP);
        $settings = \Code_Snippets\Settings\get_settings_values();
        $flatEnabled = !empty($settings['general']['enable_flat_files']);
        $databaseRows = $this->database_rows();
        if ($verify) {
            // Recovery can enter through reconcile_scoped() in a fresh
            // process holding a persistent pre-effect object-cache value.
            // Verification must compare the DB against a forced fresh API
            // read, never against that stale value.
            \Code_Snippets\clean_snippets_cache($this->table_name());
        }
        [$apiRows, $apiObjects] = $this->api_rows();
        $flat = $this->flat_projection($apiObjects, $flatEnabled, $verify);
        $databaseHash = $this->digest($databaseRows);
        $apiHash = $this->digest($apiRows);

        if ($verify && !hash_equals($databaseHash, $apiHash)) {
            throw new \RuntimeException(
                'wprism: Code Snippets cache repair did not converge the plugin API on the snippets table'
            );
        }

        return [
            'row_count' => count($databaseRows),
            'database_hash' => $databaseHash,
            'api_hash' => $apiHash,
            'flat_files_enabled' => $flatEnabled,
            'flat_file_count' => $flat['count'],
            'flat_tree_hash' => $flat['hash'],
        ];
    }

    /** @return list<array{id:int,name:string,description:string,code:string,tags:string,scope:string,priority:int,active:int}> */
    private function database_rows(): array {
        global $wpdb;
        $table = $this->table_name();
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT id, name, description, code, tags, scope, priority, active FROM `$table` ORDER BY id",
            ARRAY_A
        );
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                "wprism: Code Snippets verification query failed against $table"
            );
        }
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'code' => (string) $row['code'],
            'tags' => (string) $row['tags'],
            'scope' => (string) $row['scope'],
            'priority' => (int) $row['priority'],
            'active' => (int) $row['active'],
        ], $rows);
    }

    /**
     * @return array{0:list<array{id:int,name:string,description:string,code:string,tags:string,scope:string,priority:int,active:int}>,1:list<object>}
     */
    private function api_rows(): array {
        $objects = \Code_Snippets\get_snippets();
        if (!is_array($objects)) {
            throw new \RuntimeException('wprism: Code Snippets get_snippets() did not return a list');
        }
        usort($objects, static fn(object $a, object $b): int => ((int) $a->id <=> (int) $b->id));
        $rows = [];
        foreach ($objects as $snippet) {
            if (!$snippet instanceof \Code_Snippets\Snippet) {
                throw new \RuntimeException(
                    'wprism: Code Snippets get_snippets() returned a non-Snippet row'
                );
            }
            $rows[] = [
                'id' => (int) $snippet->id,
                'name' => (string) $snippet->name,
                'description' => (string) $snippet->desc,
                'code' => (string) $snippet->code,
                'tags' => (string) $snippet->tags_list,
                'scope' => (string) $snippet->scope,
                'priority' => (int) $snippet->priority,
                'active' => $snippet->is_trashed() ? -1 : (int) (bool) $snippet->active,
            ];
        }
        return [$rows, $objects];
    }

    private function purge_flat_projection(): void {
        $root = rtrim(\Code_Snippets\Snippet_Files::get_base_dir(), '/');
        $directory = $this->flat_table_directory();
        if (is_link($root) || is_link($directory)) {
            throw new \RuntimeException(
                'wprism: Code Snippets flat-file projection is symlinked; refusing recursive repair'
            );
        }
        if (file_exists($directory) && !is_dir($directory)) {
            throw new \RuntimeException(
                'wprism: Code Snippets flat-file table projection is not a directory'
            );
        }
        if (!is_dir($directory)) {
            return;
        }
        $filesystem = new \Code_Snippets\WordPress_File_System_Adapter();
        if (!$filesystem->delete($directory, true)) {
            throw new \RuntimeException(
                'wprism: Code Snippets could not remove the stale site-table flat-file projection'
            );
        }
        clearstatcache(true, $directory);
        if (file_exists($directory)) {
            throw new \RuntimeException(
                'wprism: Code Snippets stale site-table flat-file projection survived deletion'
            );
        }
    }

    private function flat_table_directory(): string {
        $root = rtrim(\Code_Snippets\Snippet_Files::get_base_dir(), '/');
        $hash = \Code_Snippets\Snippet_Files::get_hashed_table_name($this->table_name());
        $directory = rtrim(\Code_Snippets\Snippet_Files::get_base_dir($hash), '/');
        if (dirname($directory) !== $root || basename($directory) !== $hash || $hash === '') {
            throw new \RuntimeException(
                'wprism: Code Snippets returned an unsafe flat-file table projection path'
            );
        }
        return $directory;
    }

    /** @param list<object> $snippets @return array{count:int,hash:string} */
    private function flat_projection(array $snippets, bool $expectedEnabled, bool $verify): array {
        $actualEnabled = \Code_Snippets\Snippet_Files::is_active();
        $directory = $this->flat_table_directory();
        $tree = $this->flat_tree($directory);
        if ($verify && $actualEnabled !== $expectedEnabled) {
            throw new \RuntimeException(
                'wprism: Code Snippets flat-file enabled flag disagrees with plugin settings'
            );
        }
        if ($verify && !$expectedEnabled && $tree !== []) {
            throw new \RuntimeException(
                'wprism: disabled Code Snippets flat-file mode retained a site-table projection'
            );
        }
        if ($verify && $expectedEnabled) {
            $this->verify_flat_tree($directory, $tree, $snippets);
        }
        return ['count' => count($tree), 'hash' => $this->digest($tree)];
    }

    /** @return array<string,string> relative path => SHA-256 */
    private function flat_tree(string $directory): array {
        if (!file_exists($directory)) {
            return [];
        }
        if (is_link($directory) || !is_dir($directory)) {
            throw new \RuntimeException(
                'wprism: Code Snippets flat-file table projection is not a real directory'
            );
        }
        $tree = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) {
                throw new \RuntimeException(
                    'wprism: Code Snippets flat-file projection contains a non-regular entry'
                );
            }
            $path = $file->getPathname();
            $relative = substr($path, strlen($directory) + 1);
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new \RuntimeException(
                    'wprism: Code Snippets could not hash a flat-file projection entry'
                );
            }
            $tree[$relative] = $hash;
        }
        ksort($tree, SORT_STRING);
        return $tree;
    }

    /** @param array<string,string> $tree @param list<object> $snippets */
    private function verify_flat_tree(string $directory, array $tree, array $snippets): void {
        $expectedFiles = [];
        $expectedIndexes = [];
        $registry = \Code_Snippets\code_snippets()->snippet_handler_registry;
        foreach ($snippets as $snippet) {
            if (!$snippet instanceof \Code_Snippets\Snippet || !$snippet->active) {
                continue;
            }
            $handler = $registry->get_handler($snippet->type);
            if (!$handler) {
                continue;
            }
            $type = $handler->get_dir_name();
            $path = $type . '/' . (int) $snippet->id . '.' . $handler->get_file_extension();
            $expectedFiles[$path] = hash('sha256', $handler->wrap_code((string) $snippet->code));
            $expectedIndexes[$type][(int) $snippet->id] = [
                'id' => (int) $snippet->id,
                'code' => (string) $snippet->code,
                'scope' => (string) $snippet->scope,
                'priority' => (int) $snippet->priority,
                'condition_id' => (int) $snippet->condition_id,
                'active' => (int) (bool) $snippet->active,
            ];
        }

        foreach ($expectedIndexes as $type => $expectedRows) {
            ksort($expectedRows, SORT_NUMERIC);
            $indexRelative = $type . '/index.php';
            $indexPath = $directory . '/' . $indexRelative;
            if (!is_file($indexPath)) {
                throw new \RuntimeException(
                    "wprism: Code Snippets flat-file projection is missing $indexRelative"
                );
            }
            $actualRows = require $indexPath;
            if (!is_array($actualRows)) {
                throw new \RuntimeException(
                    "wprism: Code Snippets flat-file index $indexRelative did not return rows"
                );
            }
            $normalized = [];
            foreach ($actualRows as $id => $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException(
                        "wprism: Code Snippets flat-file index $indexRelative contains a malformed row"
                    );
                }
                $normalized[(int) $id] = [
                    'id' => (int) ($row['id'] ?? 0),
                    'code' => (string) ($row['code'] ?? ''),
                    'scope' => (string) ($row['scope'] ?? ''),
                    'priority' => (int) ($row['priority'] ?? 0),
                    'condition_id' => (int) ($row['condition_id'] ?? 0),
                    'active' => (int) (bool) ($row['active'] ?? false),
                ];
            }
            ksort($normalized, SORT_NUMERIC);
            if ($normalized !== $expectedRows) {
                throw new \RuntimeException(
                    "wprism: Code Snippets flat-file index $indexRelative disagrees with the plugin API"
                );
            }
            $expectedFiles[$indexRelative] = $tree[$indexRelative] ?? '';
        }
        ksort($expectedFiles, SORT_STRING);
        if ($tree !== $expectedFiles) {
            throw new \RuntimeException(
                'wprism: Code Snippets flat-file projection contains missing, stale, or unexpected files'
            );
        }
    }

    /** @param mixed $value */
    private function digest(mixed $value): string {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('wprism: Code Snippets could not encode provider evidence');
        }
        return hash('sha256', $json);
    }
}
