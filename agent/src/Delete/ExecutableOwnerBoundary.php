<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';
require_once __DIR__ . '/Deletion.php';

/**
 * Transaction-bound inventory of every WordPress executable owner that can
 * add a reverse reference outside a manifest's declared guard set.
 *
 * Database-backed activation facts are read directly under next-key locks;
 * get_option() is intentionally absent because its request cache can predate
 * the authored transaction. Theme/MU/drop-in code is sampled only while the
 * externally verified writer exclusion is held, then sampled again immediately
 * before each delete.
 */
final class ExecutableOwnerBoundary {
    private const PURPOSE = 'deletion executable-owner boundary';
    private const AGREEMENTS_FORMAT = 'wprism-deletion-owner-agreements/v2';
    private const CODE_IDENTITY_FORMAT = 'wprism-executable-tree/v1';
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_OWNERS = 4096;
    private const MAX_TREE_ENTRIES = 100000;
    private const MAX_TREE_DEPTH = 128;
    private const MAX_TREE_BYTES = 1073741824;
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

    /** @var ?array<string,?array{format:string,root:string,sha256:string}> */
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

    /**
     * @param array<string,array<string,mixed>> $capabilities
     * @param array<string,?array{format:string,root:string,sha256:string}> $owners
     */
    private function assert_agreements(array $capabilities, array $owners): void {
        $siteAgreements = self::site_agreements($this->policy->site['policy'] ?? []);
        $diagnostics = [];
        foreach ($capabilities as $selector => $capability) {
            $declared = array_fill_keys((array) ($capability['declaring_executable_owners'] ?? []), true);
            // This exact loader is the trusted engine executing the boundary,
            // not site code whose reverse references the Woo adapter must infer.
            $declared['mu-plugin:wprism-loader.php'] = true;
            foreach ((array) ($siteAgreements[$selector] ?? []) as $owner => $agreement) {
                $actualIdentity = $owners[$owner] ?? null;
                if (!is_array($actualIdentity)
                    || !hash_equals(Canon::encode($actualIdentity), Canon::encode((array) $agreement['code_identity']))) {
                    $diagnostic = [
                        'code' => 'deletion_executable_owner_identity_mismatch',
                        'surface' => $selector,
                        'owner' => $owner,
                        'owner_type' => explode(':', $owner, 2)[0],
                        'message' => 'reviewed executable owner code identity does not match the exact live tree',
                        'remediation' => 'review the changed owner code and replace the pinned v2 code identity before retrying',
                    ];
                    if (is_array($actualIdentity)) {
                        // This is intentionally only the safe canonical tuple.
                        // Absolute paths and the executable file roster stay local.
                        $diagnostic['code_identity'] = $actualIdentity;
                    }
                    $diagnostics[] = $diagnostic;
                    continue;
                }
                $declared[$owner] = true;
            }
            foreach (array_keys($owners) as $owner) {
                if (isset($declared[$owner])) {
                    continue;
                }
                [$ownerType] = explode(':', $owner, 2);
                $diagnostic = [
                    'code' => 'deletion_executable_owner_boundary',
                    'surface' => $selector,
                    'owner' => $owner,
                    'owner_type' => $ownerType,
                    'message' => 'active executable owner has no agreeing deletion declaration',
                    'remediation' => 'pin an adapter declaration or add one exact v2 theme/MU/drop-in code-identity agreement',
                ];
                if (is_array($owners[$owner])) {
                    $diagnostic['code_identity'] = $owners[$owner];
                }
                $diagnostics[] = $diagnostic;
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

    /** @return array<string,?array{format:string,root:string,sha256:string}> */
    private function live_owners(bool $establish): array {
        $facts = $this->locked_site_activation_facts($establish);
        $owners = [];
        foreach ($facts['active_plugins'] as $plugin) {
            self::assert_plugin_owner($plugin, 'active_plugins');
            $owners['plugin:' . $plugin] = null;
        }
        foreach ($facts['network_plugins'] as $plugin) {
            self::assert_plugin_owner($plugin, 'active_sitewide_plugins');
            $owners['plugin:' . $plugin] = null;
        }
        foreach ([$facts['stylesheet'], $facts['template']] as $theme) {
            if (preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $theme) !== 1) {
                throw new \RuntimeException('wprism: deletion executable-owner boundary found a malformed active theme identity');
            }
            $owners['theme:' . $theme] = self::theme_identity($theme);
        }
        foreach ($this->filesystem_owners() as $owner => $identity) {
            $owners[$owner] = $identity;
        }
        if (count($owners) > self::MAX_OWNERS) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary exceeded its owner limit');
        }
        ksort($owners, SORT_STRING);
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

    /** @return array<string,array{format:string,root:string,sha256:string}> */
    private function filesystem_owners(): array {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('wprism: deletion executable-owner boundary requires WordPress WP_CONTENT_DIR');
        }
        $owners = [];
        $muRoot = defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) && WPMU_PLUGIN_DIR !== ''
            ? WPMU_PLUGIN_DIR
            : rtrim(WP_CONTENT_DIR, '/\\') . '/mu-plugins';
        $muFiles = self::php_files($muRoot, 'MU plugin');
        $muIdentity = $muFiles === [] ? null : self::tree_identity($muRoot, 'mu-plugins');
        foreach ($muFiles as $file) {
            // WordPress executes only the top-level PHP roster, but any one of
            // those entries may include a sibling/subtree. Binding the whole MU
            // root makes every added/changed dependency move every agreement.
            $owners['mu-plugin:' . $file] = $muIdentity;
        }
        foreach (self::DROP_INS as $file) {
            $path = rtrim(WP_CONTENT_DIR, '/\\') . '/' . $file;
            clearstatcache(true, $path);
            if (is_file($path)) {
                $owners['dropin:' . $file] = self::tree_identity($path, $file);
            }
        }
        ksort($owners, SORT_STRING);
        return $owners;
    }

    /** @return array{format:string,root:string,sha256:string} */
    private static function theme_identity(string $theme): array {
        self::content_root();
        $root = rtrim(WP_CONTENT_DIR, '/\\') . '/themes/' . $theme;
        if (function_exists('get_theme_root')) {
            $reported = get_theme_root($theme);
            if (!is_string($reported) || !self::same_path($reported, dirname($root))) {
                throw new \RuntimeException(
                    "wprism: active theme '$theme' is outside the canonical wp-content/themes owner root"
                );
            }
        }
        return self::tree_identity($root, 'themes/' . $theme);
    }

    /** @return array{format:string,root:string,sha256:string} */
    private static function tree_identity(string $absoluteRoot, string $canonicalRoot): array {
        $contentRoot = self::content_root();
        $absolute = self::normalized_existing_path($absoluteRoot, 'executable owner root');
        if (!self::path_within($absolute, $contentRoot)) {
            throw new \RuntimeException('wprism: executable owner root escapes WP_CONTENT_DIR');
        }
        $expected = $contentRoot . '/' . $canonicalRoot;
        if (!self::same_path($absolute, $expected)) {
            throw new \RuntimeException(
                "wprism: executable owner root does not match canonical identity '$canonicalRoot'"
            );
        }
        $rows = [];
        $bytes = 0;
        // Count the canonical owner root as well as every descendant. Empty
        // directories consume the same finite traversal budget as files.
        $entries = 1;
        $stat = @lstat($absolute);
        if (!is_array($stat)) {
            throw new \RuntimeException('wprism: executable owner root cannot be inspected');
        }
        $kind = ((int) $stat['mode']) & 0170000;
        if ($kind === 0100000) {
            self::append_file_identity($absolute, basename($canonicalRoot), $rows, $bytes);
        } elseif ($kind === 0040000) {
            self::walk_tree($absolute, '', $rows, $bytes, $entries, 0);
        } else {
            throw new \RuntimeException('wprism: executable owner root is not a regular file or directory');
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $payload = [
            'files' => $rows,
            'format' => self::CODE_IDENTITY_FORMAT,
            'root' => $canonicalRoot,
        ];
        return [
            'format' => self::CODE_IDENTITY_FORMAT,
            'root' => $canonicalRoot,
            'sha256' => hash('sha256', Canon::encode($payload)),
        ];
    }

    /** @param list<array{path:string,sha256:string}> $rows */
    private static function walk_tree(
        string $root,
        string $relative,
        array &$rows,
        int &$bytes,
        int &$entries,
        int $depth
    ): void {
        if ($depth > self::MAX_TREE_DEPTH) {
            throw new \RuntimeException('wprism: executable owner tree exceeds its depth bound');
        }
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $handle = @opendir($directory);
        if (!is_resource($handle)) {
            throw new \RuntimeException('wprism: executable owner tree contains an unreadable directory');
        }
        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if ($entry === '' || str_contains($entry, "\0") || str_contains($entry, '/')
                    || str_contains($entry, '\\') || preg_match('/[\x00-\x1f\x7f]/', $entry) === 1) {
                    throw new \RuntimeException('wprism: executable owner tree contains an unsafe path component');
                }
                self::consume_tree_entry($entries);
                $childRelative = $relative === '' ? $entry : $relative . '/' . $entry;
                $path = $root . '/' . $childRelative;
                clearstatcache(true, $path);
                $stat = @lstat($path);
                if (!is_array($stat)) {
                    throw new \RuntimeException('wprism: executable owner tree entry cannot be inspected');
                }
                $kind = ((int) $stat['mode']) & 0170000;
                if ($kind === 0040000) {
                    self::walk_tree($root, $childRelative, $rows, $bytes, $entries, $depth + 1);
                    continue;
                }
                if ($kind !== 0100000) {
                    throw new \RuntimeException(
                        "wprism: executable owner tree entry '$childRelative' is symlinked or nonregular"
                    );
                }
                self::append_file_identity($path, $childRelative, $rows, $bytes, $stat);
            }
        } finally {
            closedir($handle);
        }
    }

    private static function consume_tree_entry(int &$entries): void {
        if ($entries >= self::MAX_TREE_ENTRIES) {
            throw new \RuntimeException('wprism: executable owner tree exceeds its entry bound');
        }
        $entries++;
    }

    /** @param list<array{path:string,sha256:string}> $rows @param ?array<string,mixed> $stat */
    private static function append_file_identity(
        string $path,
        string $relative,
        array &$rows,
        int &$bytes,
        ?array $stat = null
    ): void {
        $stat ??= @lstat($path);
        if (!is_array($stat) || (((int) $stat['mode']) & 0170000) !== 0100000 || !is_readable($path)) {
            throw new \RuntimeException("wprism: executable owner file '$relative' is nonregular or unreadable");
        }
        $size = $stat['size'] ?? null;
        if (!is_int($size) || $size < 0 || $bytes > self::MAX_TREE_BYTES - $size) {
            throw new \RuntimeException('wprism: executable owner tree exceeds its byte bound');
        }
        $digest = @hash_file('sha256', $path);
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException("wprism: executable owner file '$relative' could not be hashed");
        }
        $bytes += $size;
        $rows[] = ['path' => $relative, 'sha256' => $digest];
    }

    private static function content_root(): string {
        if (!defined('WP_CONTENT_DIR') || !is_string(WP_CONTENT_DIR) || WP_CONTENT_DIR === '') {
            throw new \RuntimeException('wprism: deletion executable-owner boundary requires WordPress WP_CONTENT_DIR');
        }
        return self::normalized_existing_path(WP_CONTENT_DIR, 'WP_CONTENT_DIR');
    }

    private static function normalized_existing_path(string $path, string $label): string {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $resolved = @realpath($path);
        if (!is_array($stat) || !is_string($resolved) || $resolved === ''
            || ((((int) $stat['mode']) & 0170000) !== 0040000
                && (((int) $stat['mode']) & 0170000) !== 0100000)
            || is_link($path)) {
            throw new \RuntimeException("wprism: $label is absent, symlinked, or nonregular");
        }
        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private static function same_path(string $left, string $right): bool {
        $leftReal = @realpath($left);
        $rightReal = @realpath($right);
        return is_string($leftReal) && is_string($rightReal)
            && hash_equals(rtrim(str_replace('\\', '/', $leftReal), '/'), rtrim(str_replace('\\', '/', $rightReal), '/'));
    }

    private static function path_within(string $path, string $root): bool {
        return hash_equals($root, $path) || str_starts_with($path, $root . '/');
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
        $handle = @opendir($root);
        if (!is_resource($handle)) {
            throw new \RuntimeException("wprism: deletion executable-owner boundary cannot enumerate $label root");
        }
        $files = [];
        $entries = 1;
        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                self::consume_tree_entry($entries);
                if (!str_ends_with(strtolower($entry), '.php')) {
                    continue;
                }
                $path = rtrim($root, '/\\') . '/' . $entry;
                clearstatcache(true, $path);
                $stat = @lstat($path);
                if (preg_match('/^[A-Za-z0-9._-]{1,192}\.php$/D', $entry) !== 1
                    || !is_array($stat)
                    || (((int) $stat['mode']) & 0170000) !== 0100000
                    || !is_readable($path)) {
                    throw new \RuntimeException("wprism: deletion executable-owner boundary found an unsafe $label entry");
                }
                $files[] = $entry;
                if (count($files) > self::MAX_OWNERS) {
                    throw new \RuntimeException("wprism: deletion executable-owner boundary exceeded its $label limit");
                }
            }
        } finally {
            closedir($handle);
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

    /**
     * V2 uses lists at both potentially repeated levels. Unlike JSON objects,
     * duplicate list entries survive json_decode(), so selector/owner
     * duplicates can be rejected instead of silently taking the last value.
     *
     * @param array<string,mixed> $sitePolicy
     * @return array<string,array<string,array{code_identity:array{format:string,root:string,sha256:string},rationale:string}>>
     */
    private static function site_agreements(array $sitePolicy): array {
        if (!array_key_exists('deletion_owner_agreements', $sitePolicy)) {
            return [];
        }
        $declared = $sitePolicy['deletion_owner_agreements'];
        if (!is_array($declared) || array_is_list($declared)) {
            throw new \RuntimeException(
                'wprism: policy.deletion_owner_agreements must use the closed wprism-deletion-owner-agreements/v2 object'
            );
        }
        $keys = array_keys($declared);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'selectors'] || ($declared['format'] ?? null) !== self::AGREEMENTS_FORMAT
            || !is_array($declared['selectors']) || !array_is_list($declared['selectors'])) {
            throw new \RuntimeException(
                'wprism: legacy deletion_owner_agreements maps are refused; migrate to the closed duplicate-resistant v2 selectors/owners lists'
            );
        }
        $selectors = $declared['selectors'];
        $out = [];
        if (count($selectors) > self::MAX_OWNERS) {
            throw new \RuntimeException('wprism: policy.deletion_owner_agreements exceeded its selector limit');
        }
        foreach ($selectors as $selectorIndex => $selectorRow) {
            if (!is_array($selectorRow) || array_is_list($selectorRow)) {
                throw new \RuntimeException('wprism: deletion_owner_agreements selector rows must be objects');
            }
            $selectorKeys = array_keys($selectorRow);
            sort($selectorKeys, SORT_STRING);
            $selector = $selectorRow['selector'] ?? null;
            $owners = $selectorRow['owners'] ?? null;
            if ($selectorKeys !== ['owners', 'selector']
                || !is_string($selector)
                || preg_match('/^(post|term|menu|table):[a-z0-9][a-z0-9._-]{0,127}$/D', $selector) !== 1
                || !is_array($owners)
                || !array_is_list($owners)) {
                throw new \RuntimeException(
                    "wprism: policy.deletion_owner_agreements selectors[$selectorIndex] is malformed"
                );
            }
            if (isset($out[$selector])) {
                throw new \RuntimeException(
                    "wprism: policy.deletion_owner_agreements repeats selector '$selector'"
                );
            }
            if (count($owners) > self::MAX_OWNERS) {
                throw new \RuntimeException(
                    "wprism: policy.deletion_owner_agreements.$selector exceeded its owner limit"
                );
            }
            $out[$selector] = [];
            foreach ($owners as $ownerIndex => $ownerRow) {
                if (!is_array($ownerRow) || array_is_list($ownerRow)) {
                    throw new \RuntimeException(
                        "wprism: policy.deletion_owner_agreements.$selector owners[$ownerIndex] must be an object"
                    );
                }
                $ownerKeys = array_keys($ownerRow);
                sort($ownerKeys, SORT_STRING);
                $owner = $ownerRow['owner'] ?? null;
                $rationale = $ownerRow['rationale'] ?? null;
                $identity = $ownerRow['code_identity'] ?? null;
                if (is_string($owner) && str_starts_with($owner, 'plugin:')) {
                    throw new \RuntimeException(
                        'wprism: policy.deletion_owner_agreements categorically rejects plugin:*; only a pinned manifest may declare a plugin executable owner'
                    );
                }
                if ($ownerKeys !== ['code_identity', 'owner', 'rationale']
                    || !is_string($owner)
                    || !self::valid_site_owner($owner)
                    || !is_string($rationale)
                    || trim($rationale) === ''
                    || strlen($rationale) > 1000
                    || !is_array($identity)
                    || array_is_list($identity)) {
                    throw new \RuntimeException(
                        "wprism: policy.deletion_owner_agreements.$selector owner rows require exact owner, code_identity, and bounded rationale"
                    );
                }
                if (isset($out[$selector][$owner])) {
                    throw new \RuntimeException(
                        "wprism: policy.deletion_owner_agreements.$selector repeats owner '$owner'"
                    );
                }
                $identityKeys = array_keys($identity);
                sort($identityKeys, SORT_STRING);
                $expectedRoot = self::expected_owner_root($owner);
                if ($identityKeys !== ['format', 'root', 'sha256']
                    || ($identity['format'] ?? null) !== self::CODE_IDENTITY_FORMAT
                    || !is_string($identity['root'] ?? null)
                    || !hash_equals($expectedRoot, (string) $identity['root'])
                    || preg_match('/^[a-f0-9]{64}$/D', (string) ($identity['sha256'] ?? '')) !== 1) {
                    throw new \RuntimeException(
                        "wprism: policy.deletion_owner_agreements.$selector owner '$owner' has a malformed or noncanonical executable code identity"
                    );
                }
                $out[$selector][$owner] = [
                    'code_identity' => [
                        'format' => self::CODE_IDENTITY_FORMAT,
                        'root' => $expectedRoot,
                        'sha256' => (string) $identity['sha256'],
                    ],
                    'rationale' => $rationale,
                ];
            }
            ksort($out[$selector], SORT_STRING);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    private static function valid_site_owner(string $owner): bool {
        if (preg_match('/^(?:theme:[A-Za-z0-9._-]{1,128}|mu-plugin:[A-Za-z0-9._-]{1,192}\.php)$/D', $owner) === 1) {
            return true;
        }
        return str_starts_with($owner, 'dropin:')
            && in_array(substr($owner, strlen('dropin:')), self::DROP_INS, true);
    }

    private static function expected_owner_root(string $owner): string {
        [$type, $name] = explode(':', $owner, 2);
        return match ($type) {
            'theme' => 'themes/' . $name,
            'mu-plugin' => 'mu-plugins',
            'dropin' => $name,
            default => throw new \RuntimeException('wprism: deletion owner agreement type is unsupported'),
        };
    }
}
