<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/SerializedDataPreflight.php';

require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/ExecutableTreeIdentity.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
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
 * before each delete. Adapter ownership admits an executable basename; it
 * never substitutes for the site's exact live-tree agreement.
 */
final class ExecutableOwnerBoundary {
    private const PURPOSE = 'deletion executable-owner boundary';
    private const AGREEMENTS_FORMAT = 'wprism-deletion-owner-agreements/v2';
    private const MAX_OPTION_VALUE_BYTES = 16777216;
    private const MAX_OWNERS = 4096;
    private const MAX_OWNER_ROSTER_ENTRIES = 100000;
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

    /**
     * Observe one caller-selected owner without selecting it for a policy.
     *
     * The returned tuple is inert: deletion still requires an active-owner
     * snapshot, an adapter declaration where applicable, and a reviewed site
     * agreement. This public seam exists only so authoring uses the exact same
     * bounded digest implementation as the enforcement path.
     *
     * @return array{owner:string,code_identity:array{format:string,root:string,sha256:string}}
     */
    public static function observe_owner(string $owner): array {
        if (!self::valid_site_owner($owner)) {
            throw new \RuntimeException('wprism: executable owner observation requires one canonical owner');
        }
        [$type, $name] = explode(':', $owner, 2);
        $identity = match ($type) {
            'plugin' => self::plugin_identity($name),
            'theme' => self::theme_identity($name),
            'mu-plugin' => self::mu_plugin_identity($name),
            'dropin' => self::dropin_identity($name),
            default => throw new \RuntimeException('wprism: executable owner observation type is unsupported'),
        };
        return ['owner' => $owner, 'code_identity' => $identity];
    }

    /**
     * Exact database rows this boundary will lock in the authored transaction.
     *
     * @param list<array<string,mixed>> $deleteWork
     * @return list<string>
     */
    public function transaction_read_tables(array $deleteWork): array {
        global $wpdb;
        if ($this->capabilities_for($deleteWork) === []) {
            return [];
        }
        if (!isset($wpdb->options) || !is_string($wpdb->options)) {
            throw new \RuntimeException('wprism: deletion executable-owner boundary requires wpdb->options');
        }
        $tables = [$wpdb->options];
        if (function_exists('is_multisite') && is_multisite()) {
            if (!isset($wpdb->sitemeta) || !is_string($wpdb->sitemeta)) {
                throw new \RuntimeException(
                    'wprism: deletion executable-owner boundary cannot profile multisite plugin activation facts'
                );
            }
            $tables[] = $wpdb->sitemeta;
        }
        DeleteGuardEvaluator::assert_table_identifiers($tables, self::PURPOSE);
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        return $tables;
    }

    /** @param list<array<string,mixed>> $deleteWork */
    public function bind(array $deleteWork): void {
        if ($this->boundOwners !== null) {
            $this->assert_unchanged();
            return;
        }
        $capabilities = $this->capabilities_for($deleteWork);
        if ($capabilities === []) {
            return;
        }
        ksort($capabilities, SORT_STRING);
        $this->boundSelectors = array_keys($capabilities);
        $this->boundOwners = $this->live_owners(true);
        $this->assert_agreements($capabilities, $this->boundOwners);
    }

    /**
     * @param list<array<string,mixed>> $deleteWork
     * @return array<string,array<string,mixed>>
     */
    private function capabilities_for(array $deleteWork): array {
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
        ksort($capabilities, SORT_STRING);
        return $capabilities;
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
            $manifestDeclared = array_fill_keys(
                (array) ($capability['declaring_executable_owners'] ?? []),
                true
            );
            $manifestIdentities = (array) ($capability['declaring_executable_owner_identities'] ?? []);
            $declared = [];
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
                if (str_starts_with($owner, 'plugin:') && !isset($manifestDeclared[$owner])) {
                    $diagnostics[] = [
                        'code' => 'deletion_executable_owner_not_manifest_declared',
                        'surface' => $selector,
                        'owner' => $owner,
                        'owner_type' => 'plugin',
                        'message' => 'a site agreement cannot grant deletion authority to a plugin without an adapter declaration',
                        'remediation' => 'install a pinned adapter that declares this exact plugin owner before reviewing its code identity',
                    ];
                    continue;
                }
                if (isset($manifestDeclared[$owner])) {
                    $identityReviewed = false;
                    foreach ((array) ($manifestIdentities[$owner] ?? []) as $reviewedIdentity) {
                        if (is_array($reviewedIdentity)
                            && hash_equals(Canon::encode($actualIdentity), Canon::encode($reviewedIdentity))) {
                            $identityReviewed = true;
                            break;
                        }
                    }
                    if (!$identityReviewed) {
                        $diagnostics[] = [
                            'code' => 'deletion_executable_owner_code_unreviewed',
                            'surface' => $selector,
                            'owner' => $owner,
                            'owner_type' => explode(':', $owner, 2)[0],
                            'message' => 'live executable owner bytes are outside the adapter-reviewed identity set',
                            'remediation' => 'install an exact adapter-reviewed artifact or certify and pin the new executable-tree identity in the manifest',
                            'code_identity' => $actualIdentity,
                        ];
                        continue;
                    }
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
                    'remediation' => isset($manifestDeclared[$owner])
                        ? 'record one exact v2 live-tree identity agreement for this adapter-declared owner'
                        : 'pin an adapter declaration where required and add one exact v2 code-identity agreement',
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
            'pin agreeing adapters and record exact live executable-tree agreements with review rationale',
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
            $owners['plugin:' . $plugin] = self::plugin_identity($plugin);
        }
        foreach ($facts['network_plugins'] as $plugin) {
            self::assert_plugin_owner($plugin, 'active_sitewide_plugins');
            $owners['plugin:' . $plugin] = self::plugin_identity($plugin);
        }
        foreach ([$facts['stylesheet'], $facts['template']] as $theme) {
            if (!self::valid_theme_name($theme)) {
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
        $authority = Db::transaction_authority(self::PURPOSE . ' network plugin authority');
        $predicate = "FROM `{$wpdb->sitemeta}` FORCE INDEX (`$index`) "
            . "WHERE site_id = %d AND meta_key = 'active_sitewide_plugins' "
            . 'AND CONNECTION_ID() = %s '
            . 'AND BINARY @wprism_tx_session = BINARY %s '
            . 'ORDER BY meta_id ASC LIMIT 2 FOR UPDATE';
        $args = [
            $wpdb->siteid,
            $authority->connection_id(),
            $authority->session_nonce(),
        ];
        $wpdb->last_error = '';
        $sizes = $wpdb->get_results($wpdb->prepare(
            'SELECT meta_id, meta_key, OCTET_LENGTH(meta_value) AS meta_value_bytes '
                . $predicate,
            ...$args
        ), ARRAY_A);
        self::assert_transaction_authority($authority, self::PURPOSE . ' network size witness');
        if (!is_array($sizes) || !array_is_list($sizes) || count($sizes) > 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: deletion executable-owner boundary could not bind network plugin activation');
        }
        if ($sizes === []) {
            return [];
        }
        $size = $sizes[0];
        $metaId = is_array($size) ? self::canonical_positive_id($size['meta_id'] ?? null) : null;
        $valueBytes = is_array($size) ? self::canonical_nonnegative_size($size['meta_value_bytes'] ?? null) : null;
        if (!is_array($size)
            || array_keys($size) !== ['meta_id', 'meta_key', 'meta_value_bytes']
            || $metaId === null
            || !is_string($size['meta_key'] ?? null)
            || !hash_equals('active_sitewide_plugins', $size['meta_key'])
            || $valueBytes === null
            || $valueBytes > self::MAX_OPTION_VALUE_BYTES) {
            throw new \RuntimeException(
                'wprism: deletion executable-owner boundary network plugin activation is ambiguous or oversized'
            );
        }

        $wpdb->last_error = '';
        $hashRows = $wpdb->get_results($wpdb->prepare(
            'SELECT meta_id, meta_key, SHA2(meta_value, 256) AS meta_value_sha256 '
                . $predicate,
            ...$args
        ), ARRAY_A);
        self::assert_transaction_authority($authority, self::PURPOSE . ' network hash witness');
        if (!is_array($hashRows) || !array_is_list($hashRows) || count($hashRows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                'wprism: deletion executable-owner boundary network plugin activation changed under its lock'
            );
        }
        $hashRow = $hashRows[0];
        $valueHash = is_array($hashRow) ? ($hashRow['meta_value_sha256'] ?? null) : null;
        if (!is_array($hashRow)
            || array_keys($hashRow) !== ['meta_id', 'meta_key', 'meta_value_sha256']
            || self::canonical_positive_id($hashRow['meta_id'] ?? null) !== $metaId
            || !is_string($hashRow['meta_key'] ?? null)
            || !hash_equals('active_sitewide_plugins', $hashRow['meta_key'])
            || !is_string($valueHash)
            || preg_match('/^[a-f0-9]{64}$/D', $valueHash) !== 1) {
            throw new \RuntimeException(
                'wprism: deletion executable-owner boundary network plugin activation hash is malformed'
            );
        }

        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT meta_id, meta_key, meta_value ' . $predicate,
            ...$args
        ), ARRAY_A);
        self::assert_transaction_authority($authority, self::PURPOSE . ' network value witness');
        if (!is_array($rows) || !array_is_list($rows) || count($rows) !== 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                'wprism: deletion executable-owner boundary network plugin activation changed under its lock'
            );
        }
        $row = $rows[0];
        $raw = is_array($row) ? ($row['meta_value'] ?? null) : null;
        if (!is_array($row)
            || array_keys($row) !== ['meta_id', 'meta_key', 'meta_value']
            || self::canonical_positive_id($row['meta_id'] ?? null) !== $metaId
            || !is_string($row['meta_key'] ?? null)
            || !hash_equals('active_sitewide_plugins', $row['meta_key'])
            || !is_string($raw)
            || strlen($raw) !== $valueBytes
            || !hash_equals($valueHash, hash('sha256', $raw))) {
            throw new \RuntimeException(
                'wprism: deletion executable-owner boundary network plugin activation disagrees with its bounded witness'
            );
        }
        $decoded = self::decode_array($raw, 'active_sitewide_plugins');
        $plugins = array_keys($decoded);
        foreach ($plugins as $plugin) {
            if (!is_string($plugin)) {
                throw new \RuntimeException('wprism: active_sitewide_plugins contains a non-string owner');
            }
        }
        sort($plugins, SORT_STRING);
        return $plugins;
    }

    private static function assert_transaction_authority(
        TransactionAuthority $authority,
        string $context
    ): void {
        if (!$authority->equals(Db::transaction_authority($context))) {
            throw new \RuntimeException("wprism: $context changed database session authority");
        }
    }

    private static function canonical_positive_id(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }

    private static function canonical_nonnegative_size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $size = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($size) && $size >= 0 ? $size : null;
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
        $muIdentity = $muFiles === []
            ? null
            : ExecutableTreeIdentity::observe(self::content_root(), $muRoot, 'mu-plugins');
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
                $owners['dropin:' . $file] = ExecutableTreeIdentity::observe(
                    self::content_root(),
                    $path,
                    $file
                );
            }
        }
        ksort($owners, SORT_STRING);
        return $owners;
    }

    /** @return array{format:string,root:string,sha256:string} */
    private static function theme_identity(string $theme): array {
        if (!self::valid_theme_name($theme)) {
            throw new \RuntimeException('wprism: executable owner observation contains a malformed theme owner');
        }
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
        return ExecutableTreeIdentity::observe(self::content_root(), $root, 'themes/' . $theme);
    }

    /** @return array{format:string,root:string,sha256:string} */
    private static function plugin_identity(string $plugin): array {
        self::assert_plugin_owner($plugin, 'executable owner observation');
        self::content_root();
        $pluginsRoot = defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR) && WP_PLUGIN_DIR !== ''
            ? WP_PLUGIN_DIR
            : rtrim(WP_CONTENT_DIR, '/\\') . '/plugins';
        $expectedPluginsRoot = rtrim(WP_CONTENT_DIR, '/\\') . '/plugins';
        if (!self::same_path($pluginsRoot, $expectedPluginsRoot)) {
            throw new \RuntimeException(
                "wprism: active plugin '$plugin' is outside the canonical wp-content/plugins owner root"
            );
        }
        $directory = str_replace('\\', '/', dirname($plugin));
        $canonicalRoot = $directory === '.' ? 'plugins/' . $plugin : 'plugins/' . $directory;
        $absoluteRoot = rtrim($pluginsRoot, '/\\') . '/'
            . ($directory === '.' ? $plugin : $directory);
        $main = rtrim($pluginsRoot, '/\\') . '/' . $plugin;
        clearstatcache(true, $main);
        if (!is_file($main) || is_link($main) || !is_readable($main)) {
            throw new \RuntimeException("wprism: active plugin '$plugin' has an unsafe or unreadable main file");
        }
        return ExecutableTreeIdentity::observe(self::content_root(), $absoluteRoot, $canonicalRoot);
    }

    /** @return array{format:string,root:string,sha256:string} */
    private static function mu_plugin_identity(string $file): array {
        $contentRoot = self::content_root();
        $root = defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR) && WPMU_PLUGIN_DIR !== ''
            ? WPMU_PLUGIN_DIR
            : $contentRoot . '/mu-plugins';
        if (!in_array($file, self::php_files($root, 'MU plugin'), true)) {
            throw new \RuntimeException(
                "wprism: MU plugin owner '$file' is not an active ordinary top-level file"
            );
        }
        return ExecutableTreeIdentity::observe($contentRoot, $root, 'mu-plugins');
    }

    /** @return array{format:string,root:string,sha256:string} */
    private static function dropin_identity(string $file): array {
        if (!in_array($file, self::DROP_INS, true)) {
            throw new \RuntimeException('wprism: executable owner observation contains an unsupported drop-in owner');
        }
        $contentRoot = self::content_root();
        $path = $contentRoot . '/' . $file;
        clearstatcache(true, $path);
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new \RuntimeException("wprism: drop-in owner '$file' is absent, symlinked, or unreadable");
        }
        return ExecutableTreeIdentity::observe($contentRoot, $path, $file);
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
                self::consume_owner_roster_entry($entries);
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

    private static function consume_owner_roster_entry(int &$entries): void {
        if ($entries >= self::MAX_OWNER_ROSTER_ENTRIES) {
            throw new \RuntimeException('wprism: executable owner tree exceeds its entry bound');
        }
        $entries++;
    }

    private static function assert_plugin_owner(string $plugin, string $surface): void {
        if (!self::valid_plugin_name($plugin)) {
            throw new \RuntimeException("wprism: $surface contains a malformed executable plugin owner");
        }
    }

    private static function valid_plugin_name(string $plugin): bool {
        if (preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*\.php$#D', $plugin) !== 1) {
            return false;
        }
        foreach (explode('/', $plugin) as $component) {
            if ($component === '.' || $component === '..') {
                return false;
            }
        }
        return true;
    }

    private static function valid_theme_name(string $theme): bool {
        return $theme !== '.' && $theme !== '..'
            && preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $theme) === 1;
    }

    /** @return list<string> */
    private static function decode_list(string $raw, string $name): array {
        $decoded = self::decode_array($raw, $name);
        // The locked raw active_plugins row is native storage, not an artifact
        // list. WordPress deactivation leaves holes; those positions must not
        // hide an owner or require rewriting state before destructive work.
        if (!PlainData::has_nonnegative_integer_keys($decoded)) {
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
        $decoded = SerializedDataPreflight::decode($raw, $name);
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
                    || ($identity['format'] ?? null) !== ExecutableTreeIdentity::FORMAT
                    || !is_string($identity['root'] ?? null)
                    || !hash_equals($expectedRoot, (string) $identity['root'])
                    || preg_match('/^[a-f0-9]{64}$/D', (string) ($identity['sha256'] ?? '')) !== 1) {
                    throw new \RuntimeException(
                        "wprism: policy.deletion_owner_agreements.$selector owner '$owner' has a malformed or noncanonical executable code identity"
                    );
                }
                $out[$selector][$owner] = [
                    'code_identity' => [
                        'format' => ExecutableTreeIdentity::FORMAT,
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
        if (str_starts_with($owner, 'plugin:')) {
            return self::valid_plugin_name(substr($owner, strlen('plugin:')));
        }
        if (str_starts_with($owner, 'theme:')) {
            return self::valid_theme_name(substr($owner, strlen('theme:')));
        }
        if (str_starts_with($owner, 'mu-plugin:')) {
            $file = substr($owner, strlen('mu-plugin:'));
            return preg_match('/^[A-Za-z0-9._-]{1,192}\.php$/D', $file) === 1;
        }
        return str_starts_with($owner, 'dropin:')
            && in_array(substr($owner, strlen('dropin:')), self::DROP_INS, true);
    }

    private static function expected_owner_root(string $owner): string {
        [$type, $name] = explode(':', $owner, 2);
        return match ($type) {
            'plugin' => dirname($name) === '.' ? 'plugins/' . $name : 'plugins/' . dirname($name),
            'theme' => 'themes/' . $name,
            'mu-plugin' => 'mu-plugins',
            'dropin' => $name,
            default => throw new \RuntimeException('wprism: deletion owner agreement type is unsupported'),
        };
    }
}
