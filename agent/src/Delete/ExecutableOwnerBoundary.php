<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';
require_once __DIR__ . '/Deletion.php';

/**
 * Transaction-bound inventory of every WordPress executable owner that can
 * add a reverse reference outside a manifest's declared guard set.
 *
 * Database-backed activation facts are read directly under next-key locks;
 * get_option() is intentionally absent because its request cache can predate
 * the authored transaction. Filesystem-only MU/drop-in rosters are sampled at
 * the same boundary and sampled again immediately before each delete.
 */
final class ExecutableOwnerBoundary {
    private const PURPOSE = 'deletion executable-owner boundary';
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_OWNERS = 4096;
    private const DROP_INS = [
        'advanced-cache.php',
        'blog-deleted.php',
        'blog-inactive.php',
        'blog-suspended.php',
        'db.php',
        'db-error.php',
        'fatal-error-handler.php',
        'install.php',
        'maintenance.php',
        'object-cache.php',
        'php-error.php',
        'sunrise.php',
    ];

    /** @var ?list<string> */
    private ?array $boundOwners = null;
    /** @var list<string> */
    private array $boundSelectors = [];
    private ?string $optionIndex = null;

    public function __construct(private readonly Policy $policy) {}

    /** @param list<array<string,mixed>> $deleteWork */
    public function bind(array $deleteWork): void {
        if ($this->boundOwners !== null) {
            $this->assert_unchanged();
            return;
        }
        $capabilities = [];
        foreach ($deleteWork as $row) {
            $selector = Deletion::selector(
                (string) ($row['deletion_kind'] ?? ''),
                (string) ($row['deletion_type'] ?? '')
            );
            $capability = $this->policy->deletion_capability($selector);
            if (($capability['executable_owner_boundary'] ?? null) === 'all_active_owners') {
                $capabilities[$selector] = $capability;
            }
        }
        if ($capabilities === []) {
            return;
        }
        ksort($capabilities, SORT_STRING);
        $this->boundSelectors = array_keys($capabilities);
        $this->boundOwners = $this->live_owners(true);
        $this->assert_agreements($capabilities, $this->boundOwners);
    }

    /** Prove locked DB facts and unlocked filesystem rosters did not move. */
    public function assert_unchanged(): void {
        if ($this->boundOwners === null) {
            return;
        }
        $current = $this->live_owners(false);
        if ($current !== $this->boundOwners) {
            throw new CommandRefusalException(
                'deletion_executable_owner_changed',
                'deletion refused because the active executable-owner roster changed after its transaction lock',
                'retry after plugin/theme activation and MU/drop-in files are stable',
                [[
                    'code' => 'deletion_executable_owner_changed',
                    'surface' => implode(',', $this->boundSelectors),
                    'message' => 'active executable-owner roster changed during the deletion transaction',
                    'remediation' => 'retry after executable ownership is stable',
                ]],
                'wprism: deletion executable-owner roster changed after it was transactionally bound'
            );
        }
    }

    /** @param array<string,array<string,mixed>> $capabilities @param list<string> $owners */
    private function assert_agreements(array $capabilities, array $owners): void {
        $siteAgreements = self::site_agreements($this->policy->site['policy'] ?? []);
        $diagnostics = [];
        foreach ($capabilities as $selector => $capability) {
            $declared = array_fill_keys((array) ($capability['declaring_executable_owners'] ?? []), true);
            // This exact loader is the trusted engine executing the boundary,
            // not site code whose reverse references the Woo adapter must infer.
            $declared['mu-plugin:wprism-loader.php'] = true;
            foreach (array_keys((array) ($siteAgreements[$selector] ?? [])) as $owner) {
                $declared[$owner] = true;
            }
            foreach ($owners as $owner) {
                if (isset($declared[$owner])) {
                    continue;
                }
                [$ownerType] = explode(':', $owner, 2);
                $diagnostics[] = [
                    'code' => 'deletion_executable_owner_boundary',
                    'surface' => $selector,
                    'owner' => $owner,
                    'owner_type' => $ownerType,
                    'message' => 'active executable owner has no agreeing deletion declaration',
                    'remediation' => "pin an adapter declaration or add policy.deletion_owner_agreements.$selector.$owner with a reviewed rationale",
                ];
            }
        }
        if ($diagnostics === []) {
            return;
        }
        usort($diagnostics, static fn(array $a, array $b): int => [
            $a['surface'], $a['owner'],
        ] <=> [
            $b['surface'], $b['owner'],
        ]);
        $labels = array_values(array_unique(array_map(
            static fn(array $row): string => (string) $row['owner'],
            $diagnostics
        )));
        throw new CommandRefusalException(
            'deletion_executable_owner_boundary',
            'deletion intent is blocked by active executable owners outside the reviewed reverse-reference contract',
            'pin agreeing adapters or record exact site-owned theme/MU/drop-in agreements with review rationale',
            $diagnostics,
            'wprism: deletion intent is blocked while these active executable owners have no agreeing reverse-reference contract: '
                . implode(', ', $labels)
        );
    }

    /** @return list<string> */
    private function live_owners(bool $establish): array {
        $facts = $this->locked_site_activation_facts($establish);
        $owners = [];
        foreach ($facts['active_plugins'] as $plugin) {
            self::assert_plugin_owner($plugin, 'active_plugins');
            $owners[] = 'plugin:' . $plugin;
        }
        foreach ($facts['network_plugins'] as $plugin) {
            self::assert_plugin_owner($plugin, 'active_sitewide_plugins');
            $owners[] = 'plugin:' . $plugin;
        }
        foreach ([$facts['stylesheet'], $facts['template']] as $theme) {
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $theme) !== 1) {
                throw new \RuntimeException('wprism: deletion executable-owner boundary found a malformed active theme identity');
            }
            $owners[] = 'theme:' . $theme;
        }
        $owners = array_merge($owners, $this->filesystem_owners());
        $owners = array_values(array_unique($owners));
        if (count($owners) > self::MAX_OWNERS) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary exceeded its owner limit');
        }
        sort($owners, SORT_STRING);
        return $owners;
    }

    /** @return array{active_plugins:list<string>,network_plugins:list<string>,stylesheet:string,template:string} */
    private function locked_site_activation_facts(bool $establish): array {
        global $wpdb;
        if (!isset($wpdb->options) || !is_string($wpdb->options)) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary requires wpdb->options');
        }
        DeleteGuardEvaluator::assert_active_transaction(self::PURPOSE);
        DeleteGuardEvaluator::assert_transaction_isolation(self::PURPOSE);
        if ($establish) {
            DeleteGuardEvaluator::assert_innodb_tables([$wpdb->options], self::PURPOSE);
            $this->optionIndex = DeleteGuardEvaluator::full_width_lock_index(
                $wpdb->options,
                'option_name',
                self::PURPOSE,
                true
            );
        }
        if ($this->optionIndex === null) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary lacks its option lock index');
        }
        $values = [];
        foreach (['active_plugins', 'stylesheet', 'template'] as $name) {
            $values[$name] = $this->lock_option_value($name);
        }
        if ($values['stylesheet'] === null || $values['template'] === null) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary requires active stylesheet and template rows');
        }
        $active = $values['active_plugins'] === null
            ? []
            : self::decode_list($values['active_plugins'], 'active_plugins');

        $network = [];
        if (function_exists('is_multisite') && is_multisite()) {
            $network = $this->locked_network_plugins();
        }
        return [
            'active_plugins' => $active,
            'network_plugins' => $network,
            'stylesheet' => $values['stylesheet'],
            'template' => $values['template'],
        ];
    }

    private function lock_option_value(string $name): ?string {
        global $wpdb;
        $index = $this->optionIndex;
        $predicate = "FROM `{$wpdb->options}` FORCE INDEX (`$index`) WHERE option_name = %s "
            . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE';
        $wpdb->last_error = '';
        $sizes = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, OCTET_LENGTH(option_value) AS value_bytes $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($sizes) || !array_is_list($sizes) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: deletion executable-owner boundary could not lock option '$name'");
        }
        if ($sizes === []) {
            return null;
        }
        if (count($sizes) !== 1
            || !is_array($sizes[0])
            || array_keys($sizes[0]) !== ['option_name', 'value_bytes']
            || !hash_equals($name, (string) ($sizes[0]['option_name'] ?? ''))
            || !ctype_digit((string) ($sizes[0]['value_bytes'] ?? ''))
            || (int) $sizes[0]['value_bytes'] > self::MAX_OPTION_VALUE_BYTES) {
            throw new \RuntimeException("wprism: deletion executable-owner boundary option '$name' is ambiguous or oversized");
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value $predicate",
            $name
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1
            || !is_array($rows[0])
            || array_keys($rows[0]) !== ['option_name', 'option_value']
            || !hash_equals($name, (string) ($rows[0]['option_name'] ?? ''))
            || !is_string($rows[0]['option_value'] ?? null)
            || strlen($rows[0]['option_value']) !== (int) $sizes[0]['value_bytes']
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: deletion executable-owner boundary option '$name' changed under its lock");
        }
        return $rows[0]['option_value'];
    }

    /** @return list<string> */
    private function locked_network_plugins(): array {
        global $wpdb;
        if (!isset($wpdb->sitemeta, $wpdb->siteid)
            || !is_string($wpdb->sitemeta)
            || !is_int($wpdb->siteid)
            || $wpdb->siteid < 1) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary cannot bind multisite plugin activation facts');
        }
        DeleteGuardEvaluator::assert_innodb_tables([$wpdb->sitemeta], self::PURPOSE . ' network plugins');
        $index = DeleteGuardEvaluator::full_width_lock_index(
            $wpdb->sitemeta,
            'site_id',
            self::PURPOSE . ' network plugins'
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM `{$wpdb->sitemeta}` FORCE INDEX (`$index`) "
                . "WHERE site_id = %d AND meta_key = 'active_sitewide_plugins' ORDER BY meta_id ASC LIMIT 2 FOR UPDATE",
            $wpdb->siteid
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: deletion executable-owner boundary could not bind network plugin activation');
        }
        if ($rows === []) {
            return [];
        }
        $decoded = self::decode_array((string) ($rows[0]['meta_value'] ?? ''), 'active_sitewide_plugins');
        $plugins = array_keys($decoded);
        foreach ($plugins as $plugin) {
            if (!is_string($plugin)) {
                throw new \RuntimeException('wprism: active_sitewide_plugins contains a non-string owner');
            }
        }
        sort($plugins, SORT_STRING);
        return $plugins;
    }

    /** @return list<string> */
    private function filesystem_owners(): array {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('wprism: deletion executable-owner boundary requires WordPress WP_CONTENT_DIR');
        }
        $owners = [];
        $muRoot = defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) && WPMU_PLUGIN_DIR !== ''
            ? WPMU_PLUGIN_DIR
            : rtrim(WP_CONTENT_DIR, '/\\') . '/mu-plugins';
        foreach (self::php_files($muRoot, 'MU plugin') as $file) {
            $owners[] = 'mu-plugin:' . $file;
        }
        foreach (self::DROP_INS as $file) {
            $path = rtrim(WP_CONTENT_DIR, '/\\') . '/' . $file;
            clearstatcache(true, $path);
            if (is_file($path)) {
                $owners[] = 'dropin:' . $file;
            }
        }
        sort($owners, SORT_STRING);
        return $owners;
    }

    /** @return list<string> */
    private static function php_files(string $root, string $label): array {
        clearstatcache(true, $root);
        if (!file_exists($root)) {
            return [];
        }
        if (!is_dir($root) || !is_readable($root)) {
            throw new \RuntimeException("wprism: deletion executable-owner boundary cannot inspect $label root");
        }
        $entries = scandir($root);
        if (!is_array($entries)) {
            throw new \RuntimeException("wprism: deletion executable-owner boundary cannot enumerate $label root");
        }
        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with(strtolower($entry), '.php')) {
                continue;
            }
            $path = rtrim($root, '/\\') . '/' . $entry;
            clearstatcache(true, $path);
            if (preg_match('/^[A-Za-z0-9._-]{1,192}\.php$/D', $entry) !== 1
                || !is_file($path)) {
                throw new \RuntimeException("wprism: deletion executable-owner boundary found an unsafe $label entry");
            }
            $files[] = $entry;
            if (count($files) > self::MAX_OWNERS) {
                throw new \RuntimeException("wprism: deletion executable-owner boundary exceeded its $label limit");
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private static function assert_plugin_owner(string $plugin, string $surface): void {
        if (preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\.php$#D', $plugin) !== 1) {
            throw new \RuntimeException("wprism: $surface contains a malformed executable plugin owner");
        }
    }

    /** @return list<string> */
    private static function decode_list(string $raw, string $name): array {
        $decoded = self::decode_array($raw, $name);
        if (!array_is_list($decoded)) {
            throw new \RuntimeException("wprism: $name is not a canonical plugin list");
        }
        foreach ($decoded as $value) {
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException("wprism: $name contains a non-string plugin owner");
            }
        }
        return array_values(array_unique($decoded));
    }

    /** @return array<mixed> */
    private static function decode_array(string $raw, string $name): array {
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($decoded) || count($decoded) > self::MAX_OWNERS) {
            throw new \RuntimeException("wprism: $name has malformed serialized activation facts");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $sitePolicy @return array<string,array<string,string>> */
    private static function site_agreements(array $sitePolicy): array {
        if (!array_key_exists('deletion_owner_agreements', $sitePolicy)) {
            return [];
        }
        $declared = $sitePolicy['deletion_owner_agreements'];
        if (!is_array($declared) || array_is_list($declared)) {
            throw new \RuntimeException('wprism: policy.deletion_owner_agreements must be an object keyed by deletion selector');
        }
        $out = [];
        if (count($declared) > self::MAX_OWNERS) {
            throw new \RuntimeException('wprism: policy.deletion_owner_agreements exceeded its selector limit');
        }
        foreach ($declared as $selector => $owners) {
            if (!is_string($selector)
                || preg_match('/^(post|term|menu|table):[a-z0-9][a-z0-9._-]{0,127}$/D', $selector) !== 1
                || !is_array($owners)
                || array_is_list($owners)) {
                throw new \RuntimeException('wprism: policy.deletion_owner_agreements has a malformed selector/owner object');
            }
            if (count($owners) > self::MAX_OWNERS) {
                throw new \RuntimeException(
                    "wprism: policy.deletion_owner_agreements.$selector exceeded its owner limit"
                );
            }
            foreach ($owners as $owner => $rationale) {
                if (!is_string($owner)
                    || !self::valid_site_owner($owner)
                    || !is_string($rationale)
                    || trim($rationale) === ''
                    || strlen($rationale) > 1000) {
                    throw new \RuntimeException(
                        "wprism: policy.deletion_owner_agreements.$selector must map exact executable owners to a bounded review rationale"
                    );
                }
                $out[$selector][$owner] = $rationale;
            }
            ksort($out[$selector], SORT_STRING);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function valid_site_owner(string $owner): bool {
        if (preg_match('/^(?:plugin:[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\.php|theme:[A-Za-z0-9._-]{1,128}|mu-plugin:[A-Za-z0-9._-]{1,192}\.php)$/D', $owner) === 1) {
            return true;
        }
        return str_starts_with($owner, 'dropin:')
            && in_array(substr($owner, strlen('dropin:')), self::DROP_INS, true);
    }
}
