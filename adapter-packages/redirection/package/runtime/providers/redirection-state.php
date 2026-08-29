<?php
declare(strict_types=1);

namespace WPrism\Providers;

use WPrism\ManifestProviderRuntime;

/**
 * Redirection 5.9.0 WordPress-module cache repair and native readback proof.
 *
 * Redirection's item writer rotates redirection_options.cache_key after every
 * rule change. WPrism materializes the same rows directly, so a primed persistent
 * object-cache entry can otherwise keep serving the target's pre-apply rules.
 * Apache and Nginx groups additionally own server files; those groups are
 * refused here because the manifest claims only the database-backed WordPress
 * module whose flush operation is deliberately empty in modules/wordpress.php.
 */
final class RedirectionState extends ManifestProviderRuntime {
    /** @param array<string,mixed> $args */
    protected function invoke_rebuild_redirect_state(array $args): array {
        return $this->rebuild_redirect_state();
    }

    /** @param array<string,mixed> $args @return array<string,mixed> */
    protected function reconcile_rebuild_redirect_state(array $args): array {
        return $this->rebuild_redirect_state()['after'];
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    private function rebuild_redirect_state(): array {
        $this->assert_runtime_contract();
        $before = $this->postcondition(false);

        // WordPress_Module::flush_module() is the reviewed no-op. Calling the
        // public dispatcher still proves that no unrecognised module id has
        // slipped past the boundary before cache generation changes.
        \Red_Module::flush_by_module(\WordPress_Module::MODULE_ID);

        $options = \Red_Options::get();
        $oldKey = (int) ($options['cache_key'] ?? 0);
        if ($oldKey > 0) {
            if ($oldKey === PHP_INT_MAX) {
                throw new \RuntimeException(
                    'wprism: Redirection cache generation is exhausted; refusing an unprovable cache rotation'
                );
            }
            $nextKey = max(time(), $oldKey + 1);
            $saved = \Red_Options::save(['cache_key' => $nextKey]);
            if ((int) ($saved['cache_key'] ?? 0) !== $nextKey) {
                throw new \RuntimeException(
                    'wprism: Redirection did not persist the requested cache generation'
                );
            }
        }

        \Red_Options::reset();
        \Redirect_Cache::init()->reset();
        $after = $this->postcondition(true);
        if ($oldKey > 0 && (int) $after['cache_key'] <= $oldKey) {
            throw new \RuntimeException(
                'wprism: Redirection cache generation did not advance after authored rule materialization'
            );
        }

        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    private function assert_runtime_contract(): void {
        if (is_multisite()) {
            throw new \RuntimeException(
                'wprism: Redirection state provider is certified for single-site tables only'
            );
        }
        if (!defined('REDIRECTION_VERSION') || REDIRECTION_VERSION !== '5.9.0') {
            throw new \RuntimeException(
                'wprism: Redirection state provider requires exact Redirection 5.9.0 runtime APIs'
            );
        }
    }

    /** @return array<string,mixed> */
    private function postcondition(bool $verify): array {
        $this->assert_runtime_contract();
        global $wpdb;

        $groupTable = $this->table_name('redirection_groups');
        $itemTable = $this->table_name('redirection_items');
        $wpdb->last_error = '';
        $groups = $wpdb->get_results(
            "SELECT id, name, tracking, module_id, status, position FROM `$groupTable` ORDER BY id",
            ARRAY_A
        );
        $items = $wpdb->get_results(
            "SELECT id, url, match_url, match_data, regex, position, group_id, status, "
            . "action_type, action_code, action_data, match_type, title FROM `$itemTable` ORDER BY id",
            ARRAY_A
        );
        if (!is_array($groups) || !is_array($items) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('wprism: Redirection verification query failed');
        }

        $groupIds = [];
        foreach ($groups as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || (int) ($row['module_id'] ?? 0) !== \WordPress_Module::MODULE_ID) {
                throw new \RuntimeException(
                    'wprism: Redirection adapter supports only positive-id WordPress-module groups; '
                    . 'Apache/Nginx server-file state is outside this contract'
                );
            }
            if (!in_array((string) ($row['status'] ?? ''), ['enabled', 'disabled'], true)) {
                throw new \RuntimeException('wprism: Redirection group has an unknown status');
            }
            $groupIds[$id] = true;
            if ($verify) {
                $native = \Red_Group::get($id, true);
                if (!$native instanceof \Red_Group
                    || $native->get_id() !== $id
                    || $native->get_name() !== (string) $row['name']
                    || $native->get_module_id() !== \WordPress_Module::MODULE_ID
                    || $native->is_enabled() !== ((string) $row['status'] === 'enabled')) {
                    throw new \RuntimeException(
                        'wprism: Redirection group API did not converge on the authored table'
                    );
                }
            }
        }

        $nativeItems = [];
        foreach ($items as $row) {
            $id = (int) ($row['id'] ?? 0);
            $groupId = (int) ($row['group_id'] ?? 0);
            if ($id <= 0 || !isset($groupIds[$groupId])) {
                throw new \RuntimeException(
                    'wprism: Redirection item has no supported WordPress-module group'
                );
            }
            $actionType = (string) ($row['action_type'] ?? '');
            $actionCode = (int) ($row['action_code'] ?? 0);
            $matchType = (string) ($row['match_type'] ?? '');
            if (\Red_Action::create($actionType, $actionCode) === null
                || \Red_Match::create($matchType, $row['action_data'] ?? '') === null) {
                throw new \RuntimeException(
                    'wprism: Redirection item uses an action or matcher outside the exact built-in 5.9.0 vocabulary'
                );
            }
            $this->assert_match_data($row['match_data'] ?? null);

            $native = \Red_Item::get_by_id($id);
            if (!$native instanceof \Red_Item) {
                throw new \RuntimeException('wprism: Redirection item API could not read an authored row');
            }
            $nativeItems[] = $native;
            if ($verify && !$this->item_agrees($row, $native)) {
                throw new \RuntimeException(
                    'wprism: Redirection item API did not converge on the authored table'
                );
            }
        }

        if ($verify) {
            foreach ($nativeItems as $native) {
                if (!$native->is_enabled()) {
                    continue;
                }
                $candidates = \Red_Item::get_for_matched_url($native->get_url());
                $ids = array_map(
                    static fn(\Red_Item $candidate): int => $candidate->get_id(),
                    is_array($candidates) ? $candidates : []
                );
                if (!in_array($native->get_id(), $ids, true)) {
                    throw new \RuntimeException(
                        'wprism: Redirection lookup cache did not expose an enabled authored item'
                    );
                }
            }
        }

        \Red_Options::reset();
        $options = \Red_Options::get();
        return [
            'group_count' => count($groups),
            'group_hash' => $this->digest($groups),
            'item_count' => count($items),
            'item_hash' => $this->digest($items),
            'cache_enabled' => (int) ($options['cache_key'] ?? 0) > 0,
            'cache_key' => (int) ($options['cache_key'] ?? 0),
        ];
    }

    /** @param array<string,mixed> $row */
    private function item_agrees(array $row, \Red_Item $native): bool {
        $nativeSql = $native->to_sql();
        return $native->get_id() === (int) ($row['id'] ?? 0)
            && $native->get_url() === (string) ($row['url'] ?? '')
            && $native->get_match_url() === (string) ($row['match_url'] ?? '')
            && $native->is_regex() === ((int) ($row['regex'] ?? 0) === 1)
            && $native->get_position() === (int) ($row['position'] ?? 0)
            && $native->get_group_id() === (int) ($row['group_id'] ?? 0)
            && $native->is_enabled() === ((string) ($row['status'] ?? '') === 'enabled')
            && $native->get_action_type() === (string) ($row['action_type'] ?? '')
            && $native->get_action_code() === (int) ($row['action_code'] ?? 0)
            && $native->get_match_type() === (string) ($row['match_type'] ?? '')
            && $native->get_title() === (string) ($row['title'] ?? '')
            && ($nativeSql['action_data'] ?? null) === ($row['action_data'] ?? null);
    }

    /** @param mixed $value */
    private function assert_match_data($value): void {
        if ($value === null || $value === '') {
            return;
        }
        if (!is_string($value)) {
            throw new \RuntimeException('wprism: Redirection match_data is not text');
        }
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('wprism: Redirection match_data is not valid JSON', 0, $e);
        }
        if (!is_array($decoded) || array_diff(array_keys($decoded), ['source', 'options']) !== []) {
            throw new \RuntimeException(
                'wprism: Redirection match_data is outside the built-in source/options vocabulary'
            );
        }
    }

    private function table_name(string $logical): string {
        global $wpdb;
        $table = (string) $wpdb->prefix . $logical;
        if (preg_match('/^[A-Za-z0-9_]+$/D', $table) !== 1) {
            throw new \RuntimeException('wprism: Redirection returned an unsafe table name');
        }
        return $table;
    }

    /** @param mixed $value */
    private function digest($value): string {
        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new \RuntimeException('wprism: Redirection could not encode provider evidence');
        }
        return hash('sha256', $json);
    }
}
