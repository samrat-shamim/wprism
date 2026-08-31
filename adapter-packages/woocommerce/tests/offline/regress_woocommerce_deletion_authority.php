<?php
/**
 * Offline deletion-engine regression using the shipped product contract and a
 * bounded synthetic typed-table contract.
 *
 * This is deliberately target-free: live capture/apply owns the SQL and
 * WooCommerce API/cache probes. The shipped product declaration is exercised
 * directly; the synthetic declarations keep the generic typed-row locking,
 * witness, reference, and child-before-parent machinery executable without
 * turning those table selectors into production capability claims.
 */

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 3);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
$root = dirname(__DIR__, 4);
$executableOwnerFixture = sys_get_temp_dir() . '/wprism-woo-owners-' . bin2hex(random_bytes(8));
mkdir($executableOwnerFixture . '/mu-plugins', 0777, true);
mkdir($executableOwnerFixture . '/themes/twentytwentyfive', 0777, true);
mkdir($executableOwnerFixture . '/themes/agency-child', 0777, true);
mkdir($executableOwnerFixture . '/themes/agency-parent', 0777, true);
mkdir($executableOwnerFixture . '/plugins/woocommerce', 0777, true);
mkdir($executableOwnerFixture . '/plugins/acme-extension', 0777, true);
file_put_contents(
    $executableOwnerFixture . '/mu-plugins/wprism-loader.php',
    "<?php\nrequire __DIR__ . '/wprism-loader-dependency.inc';\n"
);
file_put_contents($executableOwnerFixture . '/mu-plugins/wprism-loader-dependency.inc', "<?php // dependency-v1\n");
file_put_contents($executableOwnerFixture . '/themes/twentytwentyfive/functions.php', "<?php // fixture theme\n");
file_put_contents($executableOwnerFixture . '/themes/agency-child/functions.php', "<?php // fixture child\n");
file_put_contents($executableOwnerFixture . '/themes/agency-parent/functions.php', "<?php // fixture parent\n");
file_put_contents($executableOwnerFixture . '/plugins/woocommerce/woocommerce.php', "<?php // Version: 11.0.1\n");
file_put_contents($executableOwnerFixture . '/plugins/woocommerce/dependency.php', "<?php // dependency-v1\n");
file_put_contents($executableOwnerFixture . '/plugins/acme-extension/acme.php', "<?php // Version: 1.0.0\n");
define('WP_CONTENT_DIR', $executableOwnerFixture);
define('WP_PLUGIN_DIR', $executableOwnerFixture . '/plugins');
define('WPMU_PLUGIN_DIR', $executableOwnerFixture . '/mu-plugins');
register_shutdown_function(static function () use ($executableOwnerFixture): void {
    if (!is_dir($executableOwnerFixture)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($executableOwnerFixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($executableOwnerFixture);
});

require $root . '/agent/src/Kernel/Canon.php';
require $root . '/agent/src/Kernel/OptionState.php';
require $root . '/agent/src/Kernel/Db.php';
require $root . '/agent/src/Kernel/Uuid.php';
require $root . '/agent/src/Repository/Ledger.php';
require $root . '/agent/src/Policy/Policy.php';
require $root . '/agent/src/Delete/Deletion.php';
// require_once, not require: WP-6.5 gave Policy.php a transitive path to this
// file (Policy -> BodyRefGrammar -> JsonRefs), so the bare require above it now
// meets a class that is already declared. Every file under agent/src uses
// require_once for exactly this reason.
require_once $root . '/agent/src/Kernel/JsonRefs.php';
require $root . '/agent/src/Grammar/Tokens.php';
require $root . '/agent/src/Grammar/Blocks.php';
require $root . '/agent/src/Repository/SidebarState.php';
require $root . '/agent/src/Grammar/Shortcodes.php';
require $root . '/agent/src/Kernel/Canary.php';
require $root . '/agent/src/Repository/IdentityNotes.php';
require $root . '/agent/src/Repository/Snapshot.php';
require $root . '/agent/src/Kernel/TransientDbException.php';
require $root . '/agent/src/Publication/Publish.php';
require_once $root . '/agent/src/Kernel/PersonalData.php';
require $root . '/agent/src/Promotion/PromotionLock.php';
require $root . '/agent/src/Repository/Identity.php';
require $root . '/agent/src/Repository/IdentityBackup.php';
require $root . '/agent/src/Delete/Orphans.php';
require $root . '/agent/src/Capture/Capture.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Code/CodeCompatibility.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Delete/DeleteGuardValueCodec.php';
require_once $root . '/agent/src/Delete/ExecutableOwnerBoundary.php';
require_once $root . '/agent/src/Apply/Apply.php';

use WPrism\Deletion;
use WPrism\DeleteGuardValueCodec;
use WPrism\Code;
use WPrism\CodeCompatibility;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\Snapshot;

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

/** @return array{format:string,root:string,sha256:string} */
function woo_fixture_code_identity(string $absoluteRoot, string $canonicalRoot): array {
    $files = [];
    if (is_file($absoluteRoot)) {
        $files[] = [
            'path' => basename($canonicalRoot),
            'sha256' => hash_file('sha256', $absoluteRoot),
        ];
    } else {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
                continue;
            }
            $files[] = [
                'path' => str_replace('\\', '/', substr($entry->getPathname(), strlen($absoluteRoot) + 1)),
                'sha256' => hash_file('sha256', $entry->getPathname()),
            ];
        }
    }
    usort($files, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));
    $payload = [
        'files' => $files,
        'format' => 'wprism-executable-tree/v1',
        'root' => $canonicalRoot,
    ];
    return [
        'format' => 'wprism-executable-tree/v1',
        'root' => $canonicalRoot,
        'sha256' => hash('sha256', \WPrism\Canon::encode($payload)),
    ];
}

/** @return array<string,mixed> */
function woo_writer_witness(): array {
    return [
        'active' => true,
        'allow_deletes' => true,
        'artifact_hash' => str_repeat('a', 64),
        'exclusion_state' => 'held',
        'format' => 'wprism-scoped-promotion-witness/v1',
        'generation' => 7,
        'ok' => true,
        'owner' => 'woo-offline',
        'receipt_format' => 'wprism-scoped-promotion-receipt/v1',
        'receipt_id' => str_repeat('r', 32),
        'receipt_payload_sha256' => str_repeat('b', 64),
        'recovery_ready' => true,
        'scope_hash' => str_repeat('c', 64),
        'signing_key_id' => 'woo-test',
        'state' => 'promoting',
        'target_id' => str_repeat('t', 32),
        'terminal' => false,
    ];
}

/** @return array<string,mixed> */
function woo_verified_writer_witness(): array {
    $witness = woo_writer_witness();
    unset($witness['scope_hash']);
    $witness['format'] = 'wprism-verified-promotion-witness/v1';
    $witness['receipt_format'] = 'wprism-rollback-receipt/v3';
    $witness['resources_inventory_sha256'] = str_repeat('d', 64);
    return $witness;
}

function woo_writer_verifier(bool &$held, int &$verifications): Closure {
    return static function (array $binding) use (&$held, &$verifications): array {
        $verifications++;
        if (!$held) {
            throw new RuntimeException('simulated external writer exclusion loss');
        }
        return $binding;
    };
}

/** Minimal target seam for Apply::count_guard_refs()'s manifest guard branches. */
final class WooDeletionFakeWpdb {
    public string $prefix = 'wp_';
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';
    public string $last_error = '';
    public string $transactionIsolation = 'REPEATABLE-READ';
    public string $legacyIsolation = 'REPEATABLE-READ';
    public bool $modernIsolationError = false;
    public bool $engineIntrospectionError = false;
    public bool $optionScanError = false;
    public bool $metadataProbeError = false;
    public bool $ledgerReadError = false;
    public ?string $guardReadErrorTable = null;
    public bool $savepointExists = false;
    public int $insert_id = 0;
    private ?int $warningCode = null;
    /** @var list<string> */
    public array $lockingQueries = [];
    /** @var list<string> */
    public array $metadataQueries = [];
    /** @var list<string> */
    public array $engineQueries = [];
    /** @var list<string> */
    public array $topologyQueries = [];
    /** @var list<string> */
    public array $events = [];
    /** @var array<string,string|null> */
    public array $tableEngines = [
        'wp_postmeta' => 'InnoDB',
        'wp_options' => 'InnoDB',
    ];
    /** @var array<string,list<array<string,mixed>>> */
    public array $indexRows = [
        'wp_postmeta' => [[
            'Key_name' => 'meta_key', 'Seq_in_index' => '1',
            'Column_name' => 'meta_key', 'Sub_part' => '191',
            'Non_unique' => '1', 'Index_type' => 'BTREE', 'Visible' => 'YES',
        ]],
        'wp_options' => [[
            'Key_name' => 'option_name', 'Seq_in_index' => '1',
            'Column_name' => 'option_name', 'Sub_part' => null,
            'Non_unique' => '0', 'Index_type' => 'BTREE', 'Visible' => 'YES',
        ]],
    ];
    /** @var array<string,int> */
    public array $uuidToId = [];
    /** @var array<string,string> */
    public array $idToUuid = [];
    /** @var list<array<string,mixed>> */
    public array $metaRows = [];
    /** @var list<array<string,mixed>> */
    public array $optionRows = [];
    /** @var array<string,string> */
    public array $activationOptions = [
        'active_plugins' => 'a:1:{i:0;s:27:"woocommerce/woocommerce.php";}',
        'stylesheet' => 'twentytwentyfive',
        'template' => 'twentytwentyfive',
    ];
    /** @var list<string> */
    public array $activationQueries = [];
    /** @var array<int,array<string,mixed>> */
    public array $shippingMethodRows = [];
    /** @var list<array<string,mixed>> */
    public array $insertedShippingMethodRows = [];

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . str_replace("'", "''", (string) $arg) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1);
        }
        return $sql;
    }

    public function get_var(string $sql) {
        if ($sql === 'SELECT @@in_transaction') {
            return '1';
        }
        if (str_contains($sql, 'SELECT @@transaction_isolation')) {
            if ($this->modernIsolationError) {
                $this->last_error = 'unknown system variable';
                return null;
            }
            return $this->transactionIsolation;
        }
        if (str_contains($sql, 'SELECT @@tx_isolation')) {
            return $this->legacyIsolation;
        }
        if (preg_match('/^SELECT 1 FROM `([A-Za-z0-9_]+)` LIMIT 0$/D', $sql, $match) === 1) {
            if (array_key_exists($match[1], $this->tableEngines)) {
                $this->last_error = '';
                $this->warningCode = null;
                return null;
            }
            $this->last_error = 'simulated absent table';
            $this->warningCode = 1146;
            return false;
        }
        if (str_contains($sql, 'SELECT 1 FROM `')) {
            $this->metadataQueries[] = $sql;
            $this->events[] = 'metadata';
            if ($this->metadataProbeError) {
                $this->last_error = 'simulated metadata probe failure';
            }
            return null;
        }
        if (str_contains($sql, 'SHOW TABLES LIKE')) {
            if (str_contains($sql, 'wp_postmeta')) {
                return 'wp_postmeta';
            }
            if (str_contains($sql, 'wp_options')) {
                return 'wp_options';
            }
            if (str_contains($sql, 'wp_woocommerce_shipping_zone_methods')) {
                return 'wp_woocommerce_shipping_zone_methods';
            }
            if (preg_match("/SHOW TABLES LIKE '([^']+)'/", $sql, $match)
                && array_key_exists($match[1], $this->tableEngines)) {
                return $match[1];
            }
            return null;
        }
        if ($this->guardReadErrorTable !== null
            && str_contains($sql, "FROM `{$this->guardReadErrorTable}`")) {
            $this->last_error = 'simulated unreadable Woo runtime guard';
            return null;
        }
        if (preg_match(
            '/SELECT `instance_id` FROM `wp_woocommerce_shipping_zone_methods` WHERE `instance_id` = (\d+) LIMIT 1/',
            $sql,
            $m
        )) {
            $id = (int) $m[1];
            return isset($this->shippingMethodRows[$id]) ? $id : null;
        }
        if (preg_match("/SELECT local_id FROM wp_wprism_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $sql, $m)) {
            if ($this->ledgerReadError) {
                $this->last_error = 'simulated ledger lookup failure';
                return null;
            }
            return $this->uuidToId[$m[2] . ':' . $m[1]] ?? null;
        }
        if (preg_match("/SELECT uuid FROM wp_wprism_map WHERE id_kind = '([^']+)' AND local_id = (\\d+)/", $sql, $m)) {
            return $this->idToUuid[$m[1] . ':' . $m[2]] ?? null;
        }
        return null;
    }

    public function get_row(string $sql, $format = null): ?array {
        if (preg_match(
            "/SELECT entity_type, local_id FROM wp_wprism_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/",
            $sql,
            $m
        )) {
            $id = $this->uuidToId[$m[2] . ':' . $m[1]] ?? null;
            return $id === null ? null : [
                'entity_type' => 'woocommerce_shipping_zone_methods',
                'local_id' => $id,
            ];
        }
        if (preg_match(
            "/SELECT uuid, entity_type FROM wp_wprism_map WHERE id_kind = '([^']+)' AND local_id = (\d+)/",
            $sql,
            $m
        )) {
            $uuid = $this->idToUuid[$m[1] . ':' . $m[2]] ?? null;
            return $uuid === null ? null : [
                'uuid' => $uuid,
                'entity_type' => 'woocommerce_shipping_zone_methods',
            ];
        }
        return null;
    }

    public function insert(string $table, array $data, $format = null): int|false {
        if ($table !== 'wp_woocommerce_shipping_zone_methods') {
            return false;
        }
        $id = (int) ($data['instance_id'] ?? 0);
        if ($id <= 0 || isset($this->shippingMethodRows[$id])) {
            return false;
        }
        $this->shippingMethodRows[$id] = $data;
        $this->insertedShippingMethodRows[] = $data;
        $this->insert_id = $id;
        return 1;
    }

    public function query(string $sql): int|false {
        if (str_starts_with($sql, 'SAVEPOINT `')) {
            $this->savepointExists = true;
            return 0;
        }
        if (str_starts_with($sql, 'RELEASE SAVEPOINT `')) {
            if (!$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 0;
        }
        return 1;
    }

    public function get_results(string $sql, $format = null): array {
        if ($sql === 'SHOW WARNINGS') {
            $this->last_error = '';
            return $this->warningCode === null ? [] : [[
                'Level' => 'Error',
                'Code' => $this->warningCode,
                'Message' => 'simulated absent table',
            ]];
        }
        if ($this->optionScanError && str_contains($sql, 'SELECT `option_name` FROM `wp_options`')) {
            $this->last_error = 'simulated option scan failure';
            return [];
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            if (str_contains($sql, 'SELECT TABLE_NAME FROM')) {
                $this->topologyQueries[] = $sql;
                $this->events[] = 'topology';
            } else {
                $this->engineQueries[] = $sql;
                $this->events[] = 'engine';
            }
            if ($this->engineIntrospectionError) {
                $this->last_error = 'simulated information_schema failure';
                return [];
            }
            $rows = [];
            foreach ($this->tableEngines as $table => $engine) {
                if (str_contains($sql, "'{$table}'")) {
                    $rows[] = ['TABLE_NAME' => $table, 'ENGINE' => $engine];
                }
            }
            return $rows;
        }
        if (str_contains($sql, 'SHOW INDEX FROM')) {
            foreach ($this->indexRows as $table => $rows) {
                if (str_contains($sql, "`$table`")) {
                    return $rows;
                }
            }
            return [];
        }
        if (str_contains($sql, 'FROM `wp_options` FORCE INDEX')
            && preg_match("/WHERE option_name = '([^']+)'/", $sql, $m)) {
            $this->activationQueries[] = $sql;
            $name = str_replace("''", "'", $m[1]);
            if (!array_key_exists($name, $this->activationOptions)) {
                return [];
            }
            $value = $this->activationOptions[$name];
            if (str_contains($sql, 'OCTET_LENGTH(option_value) AS value_bytes')) {
                return [['option_name' => $name, 'value_bytes' => (string) strlen($value)]];
            }
            if (str_contains($sql, 'SELECT option_name, option_value')) {
                return [['option_name' => $name, 'option_value' => $value]];
            }
        }
        if (str_contains($sql, 'FOR UPDATE')) {
            $this->lockingQueries[] = $sql;
            $this->events[] = 'locking';
        }
        if ($this->guardReadErrorTable !== null
            && str_contains($sql, "FROM `{$this->guardReadErrorTable}`")) {
            $this->last_error = 'simulated unreadable Woo runtime guard';
            return [];
        }
        if (str_contains($sql, 'FROM `wp_postmeta`')) {
            return $this->metaRows;
        }
        if (str_contains($sql, 'FROM `wp_options`')) {
            return $this->optionRows;
        }
        return [];
    }
}

/** @return array<string,array{cascades:list<string>,guards:list<array<string,mixed>>}> */
function synthetic_woo_deletions(): array {
    return [
        'post:product' => [
            'executable_owner_boundary' => 'all_active_owners',
            'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
            'guards' => [
                ['column' => 'comment_post_ID', 'id_kind' => 'post', 'reason' => 'comments reference this product', 'table' => 'comments'],
                ['column' => 'post_parent', 'exclude_where' => ['post_type' => 'revision'], 'id_kind' => 'post', 'reason' => 'child posts reference this product', 'source_id_kind' => 'post', 'source_pk' => 'ID', 'table' => 'posts'],
                ['column' => 'product_id', 'id_kind' => 'post', 'reason' => 'orders reference this product', 'table' => 'wc_order_product_lookup'],
                ['column' => 'post_id', 'identity_column' => 'meta_id', 'id_kind' => 'post', 'meta_key' => '_children', 'reason' => 'grouped products reference this product', 'ref' => 'post[]', 'source_id_kind' => 'post', 'source_pk' => 'post_id', 'table' => 'postmeta'],
            ],
        ],
        'post:product_variation' => [
            'executable_owner_boundary' => 'all_active_owners',
            'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
            'guards' => [
                ['column' => 'comment_post_ID', 'id_kind' => 'post', 'reason' => 'comments reference this product variation', 'table' => 'comments'],
                ['column' => 'product_id', 'id_kind' => 'post', 'reason' => 'orders reference this variation', 'table' => 'wc_order_product_lookup'],
                ['column' => 'post_id', 'identity_column' => 'meta_id', 'id_kind' => 'post', 'meta_key' => '_children', 'reason' => 'grouped products reference this product variation', 'ref' => 'post[]', 'source_id_kind' => 'post', 'source_pk' => 'post_id', 'table' => 'postmeta'],
            ],
        ],
        'table:woocommerce_shipping_zone_locations' => ['cascades' => [], 'guards' => []],
        'table:woocommerce_shipping_zone_methods' => [
            'cascades' => [],
            'guards' => [[
                'column' => 'option_name', 'id_kind' => 'wc_zone_method', 'identity_column' => 'option_id',
                'option_name_ref' => true, 'reason' => 'shipping-method instance settings option references this method',
                'table' => 'options',
            ]],
        ],
        'table:woocommerce_shipping_zones' => [
            'cascades' => [],
            'guards' => [
                ['column' => 'zone_id', 'id_kind' => 'wc_zone', 'reason' => 'shipping-zone locations reference this zone', 'source_id_kind' => 'wc_zone_loc', 'source_pk' => 'location_id', 'table' => 'woocommerce_shipping_zone_locations'],
                ['column' => 'zone_id', 'id_kind' => 'wc_zone', 'reason' => 'shipping-zone methods reference this zone', 'source_id_kind' => 'wc_zone_method', 'source_pk' => 'instance_id', 'table' => 'woocommerce_shipping_zone_methods'],
            ],
        ],
        'table:woocommerce_tax_rate_locations' => ['cascades' => [], 'guards' => []],
        'table:woocommerce_tax_rates' => [
            'cascades' => [],
            'guards' => [[
                'column' => 'tax_rate_id', 'id_kind' => 'wc_tax_rate', 'reason' => 'tax-rate locations reference this tax rate',
                'source_id_kind' => 'wc_tax_loc', 'source_pk' => 'location_id', 'table' => 'woocommerce_tax_rate_locations',
            ]],
        ],
    ];
}

$shippedPolicy = Policy::load(
    null,
    ['woocommerce'],
    false,
    null,
    \WPrism\AdapterLibrary::fromSourcePackage($root, 'woocommerce')
);
check(
    ($shippedPolicy->deletion_capability('post:product')['executable_owner_boundary'] ?? null)
        === 'all_active_owners'
        && ($shippedPolicy->deletion_capability('post:product')['declaring_executable_owners'] ?? null)
            === ['plugin:woocommerce/woocommerce.php']
        && array_column(
            (array) ($shippedPolicy->deletion_capability('post:product')['declaring_executable_owner_identities']
                ['plugin:woocommerce/woocommerce.php'] ?? []),
            'sha256'
        ) === [
            'd6f965acbb8f1e6d036c2dc6ce5300f6fb832c4a88ba3cf062c5c5ac85c47507',
            'feffc5f15e569bf5eb6baa04b9b7b6e8038b47f29e1a63e80f20c24bef0d1696',
        ],
    'shipped Woo product deletion binds its owner to both exact adapter-reviewed 11.0.0/11.0.1 trees'
);
check($shippedPolicy->deletion_capability('post:product_variation') === null,
    'shipped Woo variation deletion stays unsupported until parent regeneration has a reversible boundary');
$fixtureManifest = $shippedPolicy->manifests[0];
$fixtureManifest['deletions'] = array_merge(
    synthetic_woo_deletions(),
    (array) ($fixtureManifest['deletions'] ?? [])
);
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$adapterLibrary = new ReflectionProperty(Policy::class, 'adapterLibrary');
$adapterLibrary->setValue($policy, \WPrism\AdapterLibrary::fromSourcePackage($root, 'woocommerce'));
$policy->manifests = [$fixtureManifest];
$fixtureThemeIdentity = woo_fixture_code_identity(
    WP_CONTENT_DIR . '/themes/twentytwentyfive',
    'themes/twentytwentyfive'
);
$fixturePluginIdentity = woo_fixture_code_identity(
    WP_PLUGIN_DIR . '/woocommerce',
    'plugins/woocommerce'
);
foreach (['post:product', 'post:product_variation'] as $selector) {
    $policy->manifests[0]['deletions'][$selector]['executable_owner_identities'] = [
        'plugin:woocommerce/woocommerce.php' => [$fixturePluginIdentity],
    ];
}
$policy->site = ['policy' => ['deletion_owner_agreements' => [
    'format' => 'wprism-deletion-owner-agreements/v2',
    'selectors' => [
        [
            'selector' => 'post:product',
            'owners' => [
                [
                    'owner' => 'plugin:woocommerce/woocommerce.php',
                    'code_identity' => $fixturePluginIdentity,
                    'rationale' => 'Exact fixture Woo tree is the adapter-declared product owner.',
                ],
                [
                    'owner' => 'theme:twentytwentyfive',
                    'code_identity' => $fixtureThemeIdentity,
                    'rationale' => 'Fixture theme has no product reverse-reference persistence.',
                ],
            ],
        ],
        [
            'selector' => 'post:product_variation',
            'owners' => [
                [
                    'owner' => 'plugin:woocommerce/woocommerce.php',
                    'code_identity' => $fixturePluginIdentity,
                    'rationale' => 'Exact fixture Woo tree is the adapter-declared variation owner.',
                ],
                [
                    'owner' => 'theme:twentytwentyfive',
                    'code_identity' => $fixtureThemeIdentity,
                    'rationale' => 'Fixture theme has no variation reverse-reference persistence.',
                ],
            ],
        ],
    ],
]]];
$variation = $policy->deletion_capability('post:product_variation');
$product = $policy->deletion_capability('post:product');

check($variation !== null, 'the synthetic engine fixture still exercises explicit variation deletion authority');
check(Deletion::descriptor(['type' => 'post', 'data' => ['type' => 'product_variation']]) === [
    'kind' => 'post', 'type' => 'product_variation',
], 'capture deletion descriptor preserves the product_variation selector');
check($variation['cascades'] === ['post_revisions', 'postmeta', 'term_relationships'],
    'product_variation declares the complete post-side cascade contract');
$variationReasons = array_column($variation['guards'], 'reason');
check(in_array('orders reference this variation', $variationReasons, true),
    'product_variation deletion guards Woo order lookup rows');
$variationMetaGuard = array_values(array_filter(
    $variation['guards'],
    static fn(array $guard): bool => ($guard['meta_key'] ?? null) === '_children'
))[0] ?? null;
check(is_array($variationMetaGuard)
    && ($variationMetaGuard['table'] ?? '') === 'postmeta'
    && ($variationMetaGuard['ref'] ?? '') === 'post[]'
    && ($variationMetaGuard['id_kind'] ?? '') === 'post',
    'product_variation deletion guards serialized grouped-product _children refs');
check(is_array($variationMetaGuard) && ($variationMetaGuard['identity_column'] ?? '') === 'meta_id',
    'metadata guard enumerates stable postmeta identities for forced-delete warnings');

$productMetaGuard = array_values(array_filter(
    $product['guards'],
    static fn(array $guard): bool => ($guard['meta_key'] ?? null) === '_children'
))[0] ?? null;
check(is_array($productMetaGuard), 'simple/variable product deletion has the same grouped-child safety guard');

$tables = [
    'woocommerce_shipping_zones',
    'woocommerce_shipping_zone_locations',
    'woocommerce_shipping_zone_methods',
    'woocommerce_tax_rates',
    'woocommerce_tax_rate_locations',
];
foreach ($tables as $table) {
    $capability = $policy->deletion_capability("table:$table");
    check($capability !== null, "$table has explicit typed-row deletion authority");
    check(($capability['cascades'] ?? null) === [], "$table keeps typed-row delete cascades explicit and empty");
}

$methodCapability = $policy->deletion_capability('table:woocommerce_shipping_zone_methods');
$methodOptionGuard = array_values(array_filter(
    $methodCapability['guards'] ?? [],
    static fn(array $guard): bool => !empty($guard['option_name_ref'])
))[0] ?? null;
check(is_array($methodOptionGuard)
    && ($methodOptionGuard['table'] ?? '') === 'options'
    && ($methodOptionGuard['column'] ?? '') === 'option_name'
    && ($methodOptionGuard['id_kind'] ?? '') === 'wc_zone_method',
    'shipping-method deletion guards tokenized instance-settings options');
$invalidOptionGuardPolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$invalidOptionGuardManifest = $policy->manifests[0];
unset($invalidOptionGuardManifest['option_name_refs']);
$invalidOptionGuardPolicy->manifests = [$invalidOptionGuardManifest];
$invalidOptionGuardRejected = false;
try {
    $invalidOptionGuardPolicy->deletion_capability('table:woocommerce_shipping_zone_methods');
} catch (Throwable $e) {
    $invalidOptionGuardRejected = str_contains($e->getMessage(), 'no loaded authored option_name_refs rule');
}
check($invalidOptionGuardRejected,
    'option-name deletion guard refuses a policy with no matching option_name_refs rule');

$validOptionName = 'woocommerce_flat_rate_3_settings';
$validOptionDetails = $policy->option_name_ref_match_details($validOptionName);
check(is_array($validOptionDetails)
    && ($validOptionDetails['matches']['id'][0] ?? null) === '3',
    'option-name resolver accepts WooCommerce canonical positive local ids');
check(Policy::strict_positive_local_id('03') === null
    && Policy::strict_positive_local_id('3') === 3,
    'shared option-name local-id parser rejects leading zero spellings');
$leadingZeroRejected = false;
try {
    $policy->option_name_ref_match_details('woocommerce_flat_rate_0003_settings');
} catch (Throwable $e) {
    $leadingZeroRejected = str_contains($e->getMessage(), 'malformed option_name_refs namespace');
}
check($leadingZeroRejected,
    'option-name resolver refuses a leading-zero would-be Woo namespace instead of dropping it');
$overlapPolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$overlapPolicy->manifests = [[
    'name' => 'synthetic-overlap',
    'option_name_refs' => [
        ['class' => 'authored', 'id_kind' => 'wc_zone_method', 'match' => '^woocommerce_[a-z0-9_]+_(?<id>[1-9][0-9]*)_settings$'],
        ['class' => 'authored', 'id_kind' => 'wc_zone', 'match' => '^woocommerce_[a-z0-9_]+_(?<id>[1-9][0-9]*)_settings$'],
    ],
]];
$overlapRejected = false;
try {
    $overlapPolicy->option_name_ref_match_details($validOptionName);
} catch (Throwable $e) {
    $overlapRejected = str_contains($e->getMessage(), 'ambiguous ownership');
}
check($overlapRejected,
    'option-name resolver refuses cross-kind overlapping rules rather than selecting a pin-order winner');
$sameKindPolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$sameKindPolicy->manifests = [[
    'name' => 'synthetic-same-kind-overlap',
    'option_name_refs' => [
        ['class' => 'authored', 'id_kind' => 'wc_zone_method', 'match' => '^woocommerce_[a-z0-9_]+_(?<id>[1-9][0-9]*)_settings$'],
        ['class' => 'authored', 'id_kind' => 'wc_zone_method', 'match' => '^woocommerce_flat_rate_(?<id>[1-9][0-9]*)_settings$'],
    ],
]];
$sameKindRejected = false;
try {
    $sameKindPolicy->option_name_ref_match_details($validOptionName);
} catch (Throwable $e) {
    $sameKindRejected = str_contains($e->getMessage(), 'ambiguous ownership');
}
check($sameKindRejected,
    'option-name resolver refuses same-kind overlapping rules rather than selecting a declaration-order winner');
$legacyBroadPolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$legacyBroadPolicy->manifests = [[
    'name' => 'synthetic-broad-id',
    'option_name_refs' => [[
        'class' => 'authored', 'id_kind' => 'wc_zone_method',
        'match' => '^woocommerce_[a-z0-9_]+_(?<id>[0-9]+)_settings$',
    ]],
]];
$broadLeadingZeroRejected = false;
try {
    $legacyBroadPolicy->option_name_ref_match_details('woocommerce_flat_rate_0003_settings');
} catch (Throwable $e) {
    $broadLeadingZeroRejected = str_contains($e->getMessage(), 'invalid local id');
}
check($broadLeadingZeroRejected,
    'shared resolver rejects leading-zero ids even when a legacy manifest regex is broad');

// ---------------------------------------------------------------------------
// issue #3403 (PR #176 finding 5, site 1): Apply::count_guard_refs() copies an
// option_name_ref_match_details() throw's getMessage() into a plan row's
// `blocked` field, and `plan --format=json` publishes `blocked` on a machine
// surface. option_name_ref_match_details() is a CLOSED, engine-authored
// message set — every template embeds only a real option name plus manifest
// names, never a path or a credential — so the message is KEPT verbatim (it is
// reviewed vocabulary, and conformance's plan-JSON `.blocked` consumers read
// guard reasons, not this throw text). This pin proves the closed set stays
// closed: it enumerates every reachable throw and screens each through the
// SAME secret/path authority the JSON refusal envelope uses
// (CommandRefusalException::containsSensitivePublicDetail(), i.e.
// Secrets::hard_match() plus the credential/path/home-dir shapes). A future
// edit that splices a path- or credential-shaped byte into any template — or a
// live option name reaching the throw that is not bounded by the anchored
// authored regex — fails HERE instead of reaching stdout.
$screenClean = static fn(string $m): bool =>
    !\WPrism\CommandRefusalException::containsSensitivePublicDetail(['message' => $m]);
$optionRefThrows = [];
$collectThrow = static function (callable $run) use (&$optionRefThrows): void {
    try {
        $run();
    } catch (Throwable $e) {
        $optionRefThrows[] = $e->getMessage();
    }
};
// malformed_match namespace (embeds the real option name + declaring manifests)
$collectThrow(fn() => $policy->option_name_ref_match_details('woocommerce_flat_rate_0003_settings'));
// cross-kind ambiguity (embeds the option name + every conflicting manifest owner)
$collectThrow(fn() => $overlapPolicy->option_name_ref_match_details($validOptionName));
// same-kind ambiguity (embeds the option name + declaration-order owners)
$collectThrow(fn() => $sameKindPolicy->option_name_ref_match_details($validOptionName));
// invalid local id under a broad legacy regex (embeds the option name)
$collectThrow(fn() => $legacyBroadPolicy->option_name_ref_match_details('woocommerce_flat_rate_0003_settings'));
check(count($optionRefThrows) === 4,
    'issue #3403: every POLICY-REACHABLE option_name_ref_match_details() throw is enumerated for the plan-JSON closed-set pin');
foreach ($optionRefThrows as $throwMessage) {
    check($screenClean($throwMessage),
        'issue #3403: option_name_ref plan-`blocked` message is path/credential-free (' . $throwMessage . ')');
}
// The 4th template ("did not expose its named id capture") is load-guarded
// unreachable — validate_option_name_refs enforces exactly one (?<id>)
// capture (Policy.php ~3881), so no policy path can produce it — but it is a
// member of the closed set and interpolates only the option name, so its
// literal is screened directly rather than left the one unsampled template.
check($screenClean("wprism: option_name_refs rule for option 'woocommerce_flat_rate_0003_settings' did not expose its named id capture"),
    'issue #3403: the load-unreachable option_name_ref template is also path/credential-free (closed set fully covered, not sampled)');
// Self-test: the screen this pin trusts MUST flag a path- and a credential-
// shaped variant, or the pin above would pass vacuously.
check(!$screenClean("wprism: option '/Users/alice/.aws/credentials' matches a malformed option_name_refs namespace"),
    'issue #3403 self-test: a path-shaped option_name_ref message would be caught by this pin');
check(!$screenClean("wprism: option_name_refs owner leaked token sk_live_0123456789abcdef in its match"),
    'issue #3403 self-test: a credential-shaped option_name_ref message would be caught by this pin');

$rows = Snapshot::row_tables($policy);
$order = Snapshot::topo_order($rows);
$position = array_flip($order);
check($position['woocommerce_shipping_zones'] < $position['woocommerce_shipping_zone_locations'],
    'shipping-zone parent is ordered before location child for phase-2 creation');
check($position['woocommerce_shipping_zones'] < $position['woocommerce_shipping_zone_methods'],
    'shipping-zone parent is ordered before method child for phase-2 creation');
check($position['woocommerce_tax_rates'] < $position['woocommerce_tax_rate_locations'],
    'tax-rate parent is ordered before location child for phase-2 creation');

$deletionRank = new ReflectionMethod(\WPrism\ApplyPlanner::class, 'deletion_rank');
$rankApply = new \WPrism\ApplyPlanner(
    $policy,
    $rows,
    static fn(string $uuid, string $kind): ?int => null,
    static fn(string $uuid, string $kind): ?int => null
);
$zoneRank = $deletionRank->invoke($rankApply, [
    'deletion_kind' => 'table', 'deletion_type' => 'woocommerce_shipping_zones',
]);
$methodRank = $deletionRank->invoke($rankApply, [
    'deletion_kind' => 'table', 'deletion_type' => 'woocommerce_shipping_zone_methods',
]);
$taxRank = $deletionRank->invoke($rankApply, [
    'deletion_kind' => 'table', 'deletion_type' => 'woocommerce_tax_rates',
]);
$taxLocationRank = $deletionRank->invoke($rankApply, [
    'deletion_kind' => 'table', 'deletion_type' => 'woocommerce_tax_rate_locations',
]);
check($methodRank > $zoneRank && $taxLocationRank > $taxRank,
    'typed-row tombstone ordering deletes children before parents');

$zoneGuards = $policy->deletion_capability('table:woocommerce_shipping_zones')['guards'];
$zoneGuardKinds = array_map(static fn(array $guard): string => (string) $guard['source_id_kind'], $zoneGuards);
sort($zoneGuardKinds);
check($zoneGuardKinds === ['wc_zone_loc', 'wc_zone_method'],
    'shipping-zone parent deletion guards both typed child tables');
$taxGuards = $policy->deletion_capability('table:woocommerce_tax_rates')['guards'];
check(count($taxGuards) === 1
    && ($taxGuards[0]['source_id_kind'] ?? '') === 'wc_tax_loc'
    && ($taxGuards[0]['source_pk'] ?? '') === 'location_id',
    'tax-rate parent deletion guards typed tax-rate locations');

check(str_contains((string) ($policy->manifests[0]['notes']['shipping zones & tax rates (task #93, GRADUATED grammar)'] ?? ''),
    'WC_Tax::find_rates'),
    'manifest records the Woo shipping/tax cache/API evidence behind the typed-row contract');
$cacheNote = (string) ($policy->manifests[0]['notes']['shipping/tax typed-row deletion cache boundary'] ?? '');
check(str_contains($cacheNote, 'WC_Cache_Helper::invalidate_cache_group')
    && str_contains($cacheNote, 'persistent object-cache'),
    'manifest pins Woo public cache invalidation for persistent shipping/tax caches');
// issue #3338: the cache boundary moved from an eval'd command string to a
// plugin-owned provider. The manifest now carries identity and arguments as
// data; the executable half is the WooCommerce package's cache provider,
// exercised for real below against a fake public Woo boundary.
$cacheActions = array_values(array_filter(
    $policy->actions(),
    static fn(array $row): bool => ($row['provider'] ?? '') === 'woocommerce-cache'
));
check(count($cacheActions) === 1
    && ($cacheActions[0]['capability'] ?? '') === 'invalidate_cache_groups',
    'Woo cache invalidation is one declared provider capability, not a command string');
$cacheArgs = (array) ($cacheActions[0]['args'] ?? []);
check(($cacheArgs['groups'] ?? []) === ['woocommerce-attributes', 'shipping_zones', 'taxes'],
    'Woo cache action names the attribute, shipping-zone, and tax groups as structured arguments');
$cacheProviderDeclaration = $policy->provider_declarations()['woocommerce-cache'] ?? [];
check(($cacheProviderDeclaration['source'] ?? '') === 'manifest'
    && ($cacheProviderDeclaration['plugin'] ?? '') === 'woocommerce/woocommerce.php'
    && ($cacheProviderDeclaration['version'] ?? '') === '1.0.0',
    'Woo cache provider is manifest-shipped code owned by the version-pinned plugin');
check(($policy->manifests[0]['version_range']['min'] ?? '') === '11.0.0'
    && ($policy->manifests[0]['version_range']['max'] ?? '') === '11.0.2',
    'Woo cache boundary admits only exact certified 11.0.0 and 11.0.1 artifacts');
$wooRange = $policy->manifests[0]['version_range'];
foreach (['11.0.0', '11.0.1'] as $admittedVersion) {
    check(version_compare($admittedVersion, $wooRange['min'], '>=')
        && version_compare($admittedVersion, $wooRange['max'], '<'),
        "exact WooCommerce $admittedVersion is inside the reviewed patch window");
}
foreach (['10.9.4', '11.0.2'] as $refusedVersion) {
    check(!(version_compare($refusedVersion, $wooRange['min'], '>=')
        && version_compare($refusedVersion, $wooRange['max'], '<')),
        "adjacent WooCommerce $refusedVersion is outside the reviewed patch window");
}
$compatibilityRoot = sys_get_temp_dir() . '/wprism-woo-version-boundary-' . bin2hex(random_bytes(8));
$compatibilityPlugin = $compatibilityRoot . '/plugins/woocommerce/woocommerce.php';
if (!mkdir(dirname($compatibilityPlugin), 0700, true)) {
    throw new RuntimeException('could not create Woo version-boundary fixture');
}
$resolvedWoo = RepositoryCompiler::resolved_adapters($policy);
foreach (['11.0.0' => false, '11.0.1' => false, '10.9.4' => true, '11.0.2' => true] as $version => $refused) {
    file_put_contents(
        $compatibilityPlugin,
        "<?php\n/**\n * Plugin Name: WooCommerce\n * Version: $version\n */\n"
    );
    $diagnostics = CodeCompatibility::diagnostics(
        $compatibilityRoot,
        Code::descriptor_from_source($compatibilityRoot),
        $resolvedWoo,
        null
    );
    $outside = array_values(array_filter(
        $diagnostics,
        static fn(array $row): bool => ($row['code'] ?? '') === 'code_source_outside_version_range'
            && ($row['path'] ?? '') === 'plugins/woocommerce/woocommerce.php'
    ));
    check(($outside !== []) === $refused,
        "the executable code-source gate " . ($refused ? 'refuses' : 'admits') . " exact WooCommerce $version");
}
unlink($compatibilityPlugin);
rmdir(dirname($compatibilityPlugin));
rmdir($compatibilityRoot . '/plugins');
rmdir($compatibilityRoot);
check(str_contains((string) file_get_contents($root . '/agent/src/Adapter/Providers.php'),
    "\$provider->invoke(\$capability, \$args)"),
    'provider capabilities are invoked through the engine contract, never as an engine-executed string');
// The retired channel ran every payload in a freshly launched WP-CLI process
// (cold runtime caches by construction). Providers run IN-PROCESS, so the
// engine's compensating invariant is the pre-action object-cache flush in
// Apply::rebuild() — without it, a provider's decision reads can hit this
// process's memoized pre-commit plugin models (the reproduced Polylang 3.8.6
// class verify_convergence()'s docblock records). This pin replaces the
// deleted fresh-process assertion with the invariant that now carries its
// job: if the flush disappears, this fails, and whoever removes it must
// re-argue the execution-context question on purpose.
$actionDispatcherSource = (string) file_get_contents($root . '/agent/src/Rebuild/RebuildActionDispatcher.php');
check(str_contains($actionDispatcherSource, "Db::checkpoint('rebuild object cache (pre-action)')")
    && preg_match(
        "/rebuild object cache \\(pre-action\\)'.{0,200}wp_cache_flush\\(\\)/s",
        $actionDispatcherSource
    ) === 1,
    'the in-process action loop is preceded by the compensating object-cache flush (fresh-process successor invariant)');

// Execute the REAL provider against a tiny fake public Woo boundary. This
// proves the shipped adapter code invokes the exact namespaces and refresh
// flag, and returns a verified receipt, without loading WordPress or
// WooCommerce in the offline suite.
if (!class_exists('WC_Cache_Helper')) {
    class WC_Cache_Helper {
        public static array $calls = [];
        public static string $shippingVersion = 'fake-version';
        public static ?string $freshReadOverride = null;
        public static function invalidate_cache_group(string $group): bool {
            self::$calls[] = ['group', $group];
            return true;
        }
        public static function get_transient_version(string $group, bool $refresh = false): string {
            self::$calls[] = ['transient', $group, $refresh];
            if ($refresh) {
                self::$shippingVersion = 'fake-version';
            }
            return !$refresh && self::$freshReadOverride !== null
                ? self::$freshReadOverride
                : self::$shippingVersion;
        }
    }
}
if (!class_exists('WP_CLI')) {
    class WP_CLI {
        public static function error(string $message): void {
            throw new RuntimeException($message);
        }
    }
}
if (!function_exists('get_transient')) {
    function get_transient(string $name): mixed {
        return false;
    }
}
require_once $root . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-cache.php';
$cacheProvider = new \WPrism\Providers\WoocommerceCache(
    $policy->provider_declarations()['woocommerce-cache']
);
check($cacheProvider->identity() === [
    'id' => 'woocommerce-cache',
    'plugin' => 'woocommerce/woocommerce.php',
    'version' => '1.0.0',
], 'Woo cache provider states the exact identity its manifest declaration negotiated against');
$cacheCapability = $cacheProvider->capabilities()['invalidate_cache_groups'] ?? [];
check(($cacheCapability['scope'] ?? '') === 'site'
    && ($cacheCapability['idempotent'] ?? null) === true
    && ($cacheCapability['args']['groups'] ?? []) === ['type' => 'list<string>', 'required' => true],
    'Woo cache capability is site-scoped, idempotent, and takes a bounded group list');
$cacheReceipt = $cacheProvider->invoke('invalidate_cache_groups', $cacheArgs);
check(($cacheReceipt['verified'] ?? null) === true
    && ($cacheReceipt['after']['shipping_transient_version_fresh_read'] ?? null) === 'fake-version',
    'Woo cache provider returns a verified receipt carrying the freshly re-read shipping version');
check(WC_Cache_Helper::$calls === [
    ['group', 'woocommerce-attributes'],
    ['group', 'shipping_zones'],
    ['group', 'taxes'],
    ['transient', 'shipping', true],
    ['transient', 'shipping', false],
], 'fake Woo cache boundary receives the exact attribute/shipping/tax invalidations and fresh read');

WC_Cache_Helper::$calls = [];
WC_Cache_Helper::$freshReadOverride = 'not-persisted';
$freshReadFailure = null;
try {
    $cacheProvider->invoke('invalidate_cache_groups', $cacheArgs);
} catch (Throwable $exception) {
    $freshReadFailure = $exception->getMessage();
}
check($freshReadFailure !== null
    && str_contains($freshReadFailure, 'did not persist across a fresh read')
    && WC_Cache_Helper::$calls === [
        ['group', 'woocommerce-attributes'],
        ['group', 'shipping_zones'],
        ['group', 'taxes'],
        ['transient', 'shipping', true],
        ['transient', 'shipping', false],
    ], 'Woo cache provider fails loudly when the fresh version differs from the refresh return');
WC_Cache_Helper::$freshReadOverride = null;

$childUuid = '11111111-1111-4111-8111-111111111111';
$groupedUuid = '22222222-2222-4222-8222-222222222222';
$fakeWpdb = new WooDeletionFakeWpdb();
$fakeWpdb->uuidToId = [
    "post:$childUuid" => 42,
    "post:$groupedUuid" => 7,
];
$fakeWpdb->idToUuid = [
    'post:42' => $childUuid,
    'post:7' => $groupedUuid,
];
$fakeWpdb->metaRows = [[
    'guard_id' => 100,
    'source_id' => 7,
    'meta_value' => serialize([42]),
]];
$GLOBALS['wpdb'] = $fakeWpdb;

// A failed read of Woo-owned runtime state is non-forceable at both product
// gates. Exercise the exact shipped wc_reserved_stock guard through the plan
// annotation/coordinator path, then race it at the locked final recheck.
$reservedStockGuard = array_values(array_filter(
    (array) ($shippedPolicy->deletion_capability('post:product')['guards'] ?? []),
    static fn(array $guard): bool => ($guard['table'] ?? null) === 'wc_reserved_stock'
))[0] ?? null;
check(is_array($reservedStockGuard) && ($reservedStockGuard['forceable'] ?? null) === false,
    'shipped Woo product deletion marks live stock reservations non-forceable');
$stockNotificationGuards = [];
foreach (['post:product'] as $selector) {
    foreach ((array) ($shippedPolicy->deletion_capability($selector)['guards'] ?? []) as $guard) {
        if (($guard['table'] ?? null) === 'wc_stock_notifications') {
            $stockNotificationGuards[$selector] = $guard;
        }
    }
}
check(
    array_keys($stockNotificationGuards) === ['post:product']
        && array_reduce(
            $stockNotificationGuards,
            static fn(bool $ok, array $guard): bool => $ok
                && ($guard['column'] ?? null) === 'product_id'
                && ($guard['forceable'] ?? null) === false
                && ($guard['table_absence'] ?? null) === 'empty',
            true
        ),
    'the shipped product stock-notification guard binds the exact absence-means-empty topology and remains non-forceable when present'
);
$runtimeGuardManifest = $shippedPolicy->manifests[0];
$runtimeGuardManifest['deletions']['post:product']['guards'] = [$reservedStockGuard];
$runtimeGuardPolicy = clone $shippedPolicy;
$runtimeGuardPolicy->manifests = [$runtimeGuardManifest];
$runtimeGuardCoordinator = new \WPrism\DeleteGuardLockCoordinator(
    $runtimeGuardPolicy,
    new \WPrism\DeleteGuardReferenceScanner($runtimeGuardPolicy),
    Snapshot::row_tables($runtimeGuardPolicy),
    static fn(array $binding): array => $binding
);
$absenceTables = new ReflectionProperty(\WPrism\DeleteGuardLockCoordinator::class, 'absenceEmptyTables');
$absenceTables->setValue($runtimeGuardCoordinator, ['wp_wc_stock_notifications' => true]);
$fakeWpdb->topologyQueries = [];
$absentCommitReachedWriterGate = false;
try {
    $runtimeGuardCoordinator->assert_writer_exclusion_commit_boundary();
} catch (Throwable $failure) {
    $absentCommitReachedWriterGate = !str_contains($failure->getMessage(), 'appeared before commit');
}
check(
    $absentCommitReachedWriterGate
        && count($fakeWpdb->topologyQueries) === 1
        && str_contains($fakeWpdb->topologyQueries[0], "TABLE_NAME IN ('wp_wc_stock_notifications')"),
    'the final commit boundary re-censuses the exact absence-means-empty table before checking writer exclusion'
);
$fakeWpdb->tableEngines['wp_wc_stock_notifications'] = 'InnoDB';
$appearedBeforeCommitRefused = false;
try {
    $runtimeGuardCoordinator->assert_writer_exclusion_commit_boundary();
} catch (Throwable $failure) {
    $appearedBeforeCommitRefused = str_contains($failure->getMessage(), 'appeared before commit');
}
check(
    $appearedBeforeCommitRefused,
    'an absence-means-empty table that appears before commit rolls the destructive boundary closed'
);
unset($fakeWpdb->tableEngines['wp_wc_stock_notifications']);
$absenceTables->setValue($runtimeGuardCoordinator, []);
$fakeWpdb->tableEngines['wp_wc_reserved_stock'] = 'InnoDB';
$fakeWpdb->indexRows['wp_wc_reserved_stock'] = [[
    'Key_name' => 'product_id', 'Seq_in_index' => '1',
    'Column_name' => 'product_id', 'Sub_part' => null,
    'Non_unique' => '1', 'Index_type' => 'BTREE', 'Visible' => 'YES',
]];
$fakeWpdb->guardReadErrorTable = 'wp_wc_reserved_stock';
$fakeWpdb->last_error = '';
$runtimeGuardPlan = \WPrism\DeleteGuardEvaluator::annotate_plan_guard_findings(
    ['delete' => [[
        'deletion_kind' => 'post',
        'deletion_type' => 'product',
        'type' => 'product',
        'uuid' => $childUuid,
    ]], 'delete_conflict' => []],
    [$childUuid => ['guards' => [$reservedStockGuard]]],
    static function (array $guard, string $targetUuid, bool $forUpdate) use (
        $runtimeGuardCoordinator,
        $childUuid
    ): array {
        return $runtimeGuardCoordinator->count(
            $guard,
            $targetUuid,
            [$childUuid => true],
            [$childUuid => ['data' => ['kind' => 'post', 'type' => 'product']]],
            [],
            [],
            $forUpdate
        );
    },
    static fn(string $table): bool => false,
    str_repeat('0', 64)
);
$runtimeGuardRow = $runtimeGuardPlan['delete'][0];
$initialGuardMutationCount = 0;
$initialGuardRefused = false;
try {
    \WPrism\DeleteGuardLockCoordinator::assert_no_non_forceable_delete_guards([$runtimeGuardRow]);
    $initialGuardMutationCount++;
} catch (\WPrism\CommandRefusalException $refusal) {
    $initialGuardRefused = $refusal->reasonCode === 'apply_refused'
        && str_contains($refusal->getMessage(), 'non-forceable semantic guards')
        && str_contains((string) ($runtimeGuardRow['non_forceable_guard'] ?? ''),
            'guard query failed for wc_reserved_stock.product_id');
}
check($initialGuardRefused && $initialGuardMutationCount === 0,
    'force cannot cross an unreadable shipped Woo runtime guard during initial product preparation');

$runtimeGuardRow['guard_witnesses'] = ['0' => str_repeat('0', 64)];
$fakeWpdb->last_error = '';
$fakeWpdb->lockingQueries = [];
$finalGuardMutationCount = 0;
$finalGuardRefused = false;
\WPrism\DeleteGuardEvaluator::begin_authored_transaction();
try {
    $runtimeGuardWarnings = [];
    $runtimeGuardCoordinator->recheck(
        $runtimeGuardRow,
        [$childUuid => true],
        [$childUuid => ['data' => ['kind' => 'post', 'type' => 'product']]],
        true,
        [],
        [],
        true,
        $runtimeGuardWarnings
    );
    $finalGuardMutationCount++;
} catch (RuntimeException $failure) {
    $finalGuardRefused = str_contains($failure->getMessage(), 'simulated unreadable Woo runtime guard')
        && str_contains($failure->getMessage(), 'not forceable');
}
\WPrism\DeleteGuardEvaluator::end_authored_transaction();
check($finalGuardRefused
    && $finalGuardMutationCount === 0
    && count(array_filter(
        $fakeWpdb->lockingQueries,
        static fn(string $query): bool => str_contains($query, 'wp_wc_reserved_stock')
    )) === 1,
    'force cannot cross an unreadable shipped Woo runtime guard in the final locked race window');
$preparationSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyPreparationCoordinator.php');
check(str_contains(
    $preparationSource,
    'DeleteGuardLockCoordinator::assert_no_non_forceable_delete_guards($blocked);'
), 'the production Apply preparation path invokes the tested non-forceable refusal before forced warnings');
$fakeWpdb->guardReadErrorTable = null;
$fakeWpdb->last_error = '';
unset($fakeWpdb->tableEngines['wp_wc_reserved_stock'], $fakeWpdb->indexRows['wp_wc_reserved_stock']);

// The destructive boundary must inventory code that can execute outside the
// Woo plugin itself. Its three option facts are direct FOR UPDATE reads, and
// exact site-owned agreements are the only usable path for themes/MU/drop-ins.
\WPrism\DeleteGuardEvaluator::begin_authored_transaction();
$boundaryWork = [[
    'deletion_kind' => 'post',
    'deletion_type' => 'product',
]];
$baseBoundary = new \WPrism\ExecutableOwnerBoundary($policy);
$baseBoundary->bind($boundaryWork);
check(count($fakeWpdb->activationQueries) === 6
    && count(array_filter(
        $fakeWpdb->activationQueries,
        static fn(string $query): bool => str_contains($query, 'FOR UPDATE')
    )) === 6,
    'executable-owner boundary binds active_plugins, stylesheet, and template through direct locked reads');
$ownerBoundaryTokens = token_get_all((string) file_get_contents(
    $root . '/agent/src/Delete/ExecutableOwnerBoundary.php'
));
check(array_values(array_filter(
    $ownerBoundaryTokens,
    static fn(mixed $token): bool => is_array($token)
        && $token[0] === T_STRING
        && strtolower($token[1]) === 'get_option'
)) === [], 'executable-owner boundary cannot regress to cached activation facts');

file_put_contents(WP_PLUGIN_DIR . '/woocommerce/dependency.php', "<?php // dependency-v2\n");
$modifiedDeclaredPluginRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($policy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    foreach ($refusal->diagnostics as $diagnostic) {
        if (($diagnostic['owner'] ?? null) === 'plugin:woocommerce/woocommerce.php'
            && ($diagnostic['code'] ?? null) === 'deletion_executable_owner_identity_mismatch') {
            $modifiedDeclaredPluginRefused = true;
        }
    }
}
check($modifiedDeclaredPluginRefused,
    'a same-name and same-version modified adapter-declared plugin tree refuses before deletion');

$modifiedPluginIdentity = woo_fixture_code_identity(
    WP_PLUGIN_DIR . '/woocommerce',
    'plugins/woocommerce'
);
foreach ($policy->site['policy']['deletion_owner_agreements']['selectors'] as &$selectorAgreement) {
    foreach ($selectorAgreement['owners'] as &$ownerAgreement) {
        if (($ownerAgreement['owner'] ?? null) === 'plugin:woocommerce/woocommerce.php') {
            $ownerAgreement['code_identity'] = $modifiedPluginIdentity;
        }
    }
    unset($ownerAgreement);
}
unset($selectorAgreement);
$selfBlessedModifiedPluginRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($policy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    foreach ($refusal->diagnostics as $diagnostic) {
        if (($diagnostic['owner'] ?? null) === 'plugin:woocommerce/woocommerce.php'
            && ($diagnostic['code'] ?? null) === 'deletion_executable_owner_code_unreviewed') {
            $selfBlessedModifiedPluginRefused = true;
        }
    }
}
check(
    $selfBlessedModifiedPluginRefused,
    'copying an already-modified live tree into the site agreement cannot bypass the adapter-reviewed identity set'
);
file_put_contents(WP_PLUGIN_DIR . '/woocommerce/dependency.php', "<?php // dependency-v1\n");
foreach ($policy->site['policy']['deletion_owner_agreements']['selectors'] as &$selectorAgreement) {
    foreach ($selectorAgreement['owners'] as &$ownerAgreement) {
        if (($ownerAgreement['owner'] ?? null) === 'plugin:woocommerce/woocommerce.php') {
            $ownerAgreement['code_identity'] = $fixturePluginIdentity;
        }
    }
    unset($ownerAgreement);
}
unset($selectorAgreement);

// The WPrism loader is implicitly trusted because it executes this boundary,
// but its whole MU root is still part of the transaction binding. A dependency
// change therefore cannot hide behind that implicit owner declaration.
file_put_contents(WPMU_PLUGIN_DIR . '/wprism-loader-dependency.inc', "<?php // dependency-v2\n");
$trustedLoaderDependencyRefused = false;
try {
    $baseBoundary->assert_unchanged();
} catch (\WPrism\CommandRefusalException $refusal) {
    $trustedLoaderDependencyRefused = $refusal->reasonCode === 'deletion_executable_owner_changed';
}
file_put_contents(WPMU_PLUGIN_DIR . '/wprism-loader-dependency.inc', "<?php // dependency-v1\n");
check($trustedLoaderDependencyRefused,
    'the implicitly trusted WPrism MU loader cannot bypass a changed executable dependency in its bound root');

// Empty directories do not enter the identity payload, but they must still
// consume a finite traversal budget. Pin both the recursion-depth and shared
// entry-count refusals without manufacturing a 100k-entry test tree.
$ownerBoundaryReflection = new ReflectionClass(\WPrism\ExecutableOwnerBoundary::class);
$treeIdentityMethod = $ownerBoundaryReflection->getMethod('tree_identity');
$deepThemeRoot = WP_CONTENT_DIR . '/themes/deep-owner-fixture';
mkdir($deepThemeRoot, 0777, true);
$deepCursor = $deepThemeRoot;
for ($depth = 0; $depth < 130; $depth++) {
    $deepCursor .= '/d';
    mkdir($deepCursor);
}
$deepTreeRefused = false;
try {
    $treeIdentityMethod->invoke(null, $deepThemeRoot, 'themes/deep-owner-fixture');
} catch (RuntimeException $failure) {
    $deepTreeRefused = $failure->getMessage()
        === 'wprism: executable owner tree exceeds its depth bound';
}
check($deepTreeRefused,
    'executable-owner identity refuses an excessive empty-directory depth before unbounded traversal');

$entryThemeRoot = WP_CONTENT_DIR . '/themes/entry-owner-fixture';
mkdir($entryThemeRoot, 0777, true);
file_put_contents($entryThemeRoot . '/functions.php', "<?php // entry-bound fixture\n");
$walkTreeMethod = $ownerBoundaryReflection->getMethod('walk_tree');
$entryRows = [];
$entryBytes = 0;
$entryCount = (int) $ownerBoundaryReflection->getConstant('MAX_TREE_ENTRIES');
$entryTreeRefused = false;
try {
    $walkTreeMethod->invokeArgs(null, [
        $entryThemeRoot,
        '',
        &$entryRows,
        &$entryBytes,
        &$entryCount,
        0,
    ]);
} catch (RuntimeException $failure) {
    $entryTreeRefused = $failure->getMessage()
        === 'wprism: executable owner tree exceeds its entry bound';
}
check($entryTreeRefused && $entryRows === [] && $entryBytes === 0,
    'every traversed executable-owner entry, including directories, shares one finite budget');
$ownerBoundarySource = (string) file_get_contents(
    $root . '/agent/src/Delete/ExecutableOwnerBoundary.php'
);
$phpFilesStart = strpos($ownerBoundarySource, 'private static function php_files(');
$phpFilesEnd = $phpFilesStart === false
    ? false
    : strpos($ownerBoundarySource, 'private static function assert_plugin_owner(', $phpFilesStart);
$phpFilesSource = $phpFilesStart !== false && $phpFilesEnd !== false
    ? substr($ownerBoundarySource, $phpFilesStart, $phpFilesEnd - $phpFilesStart)
    : '';
$muBudgetCheck = strpos($phpFilesSource, 'self::consume_tree_entry($entries);');
$muPhpFilter = strpos($phpFilesSource, "str_ends_with(strtolower(\$entry), '.php')");
check(str_contains($phpFilesSource, '@opendir($root)')
    && str_contains($phpFilesSource, 'readdir($handle)')
    && !str_contains($phpFilesSource, 'scandir(')
    && $muBudgetCheck !== false
    && $muPhpFilter !== false
    && $muBudgetCheck < $muPhpFilter,
    'MU owner discovery streams entries and charges non-PHP names before roster filtering');

$fakeWpdb->activationOptions['active_plugins'] = serialize([
    'woocommerce/woocommerce.php',
    'acme-extension/acme.php',
]);
$foreignPluginRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($policy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    $foreignPluginRefused = $refusal->reasonCode === 'deletion_executable_owner_boundary'
        && ($refusal->diagnostics[0]['owner'] ?? null) === 'plugin:acme-extension/acme.php';
}
check($foreignPluginRefused,
    'transaction boundary refuses an active regular plugin with no agreeing reverse-reference declaration');

$pluginAgreementPolicy = clone $policy;
$pluginAgreementPolicy->site['policy']['deletion_owner_agreements']['selectors'][0]['owners'][] = [
    'owner' => 'plugin:acme-extension/acme.php',
    'code_identity' => woo_fixture_code_identity(
        WP_PLUGIN_DIR . '/acme-extension',
        'plugins/acme-extension'
    ),
    'rationale' => 'A site file may not grant plugin deletion authority.',
];
$pluginAgreementRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($pluginAgreementPolicy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    $pluginAgreementRefused = in_array(
        'deletion_executable_owner_not_manifest_declared',
        array_column($refusal->diagnostics, 'code'),
        true
    );
}
check($pluginAgreementRefused,
    'an exact site agreement cannot grant deletion authority to a plugin the adapter did not declare');

$fakeWpdb->activationOptions['active_plugins'] = serialize(['woocommerce/woocommerce.php']);
$legacyAgreementPolicy = clone $policy;
$legacyAgreementPolicy->site['policy']['deletion_owner_agreements'] = [
    'post:product' => ['theme:twentytwentyfive' => 'legacy rationale'],
];
$legacyAgreementRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($legacyAgreementPolicy))->bind($boundaryWork);
} catch (RuntimeException $refusal) {
    $legacyAgreementRefused = str_contains($refusal->getMessage(), 'legacy deletion_owner_agreements maps are refused');
}
check($legacyAgreementRefused, 'legacy filename/rationale owner maps refuse loudly instead of silently migrating');

$duplicateAgreementPolicy = clone $policy;
$duplicateAgreementPolicy->site['policy']['deletion_owner_agreements']['selectors'][] =
    $duplicateAgreementPolicy->site['policy']['deletion_owner_agreements']['selectors'][0];
$duplicateSelectorRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($duplicateAgreementPolicy))->bind($boundaryWork);
} catch (RuntimeException $refusal) {
    $duplicateSelectorRefused = str_contains($refusal->getMessage(), 'repeats selector');
}
check($duplicateSelectorRefused, 'v2 agreement lists preserve and reject duplicate selectors');

$duplicateAgreementPolicy = clone $policy;
$duplicateAgreementPolicy->site['policy']['deletion_owner_agreements']['selectors'][0]['owners'][] =
    $duplicateAgreementPolicy->site['policy']['deletion_owner_agreements']['selectors'][0]['owners'][0];
$duplicateOwnerRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($duplicateAgreementPolicy))->bind($boundaryWork);
} catch (RuntimeException $refusal) {
    $duplicateOwnerRefused = str_contains($refusal->getMessage(), 'repeats owner');
}
check($duplicateOwnerRefused, 'v2 agreement lists preserve and reject duplicate owners');

$fakeWpdb->activationOptions['stylesheet'] = 'agency-child';
$fakeWpdb->activationOptions['template'] = 'agency-parent';
$themeRefused = false;
try {
    (new \WPrism\ExecutableOwnerBoundary($policy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    $owners = array_column($refusal->diagnostics, 'owner');
    $themeRefused = in_array('theme:agency-child', $owners, true)
        && in_array('theme:agency-parent', $owners, true);
}
check($themeRefused,
    'transaction boundary independently covers the active stylesheet and its parent template');

file_put_contents(WPMU_PLUGIN_DIR . '/agency-mu.php', "<?php\n");
file_put_contents(WP_CONTENT_DIR . '/object-cache.php', "<?php\n");
foreach (['blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php'] as $statusDropIn) {
    file_put_contents(WP_CONTENT_DIR . '/' . $statusDropIn, "<?php\n");
}
$fakeWpdb->activationOptions['stylesheet'] = 'twentytwentyfive';
$fakeWpdb->activationOptions['template'] = 'twentytwentyfive';
$filesystemOwnersRefused = false;
$filesystemOwnerDiagnostics = [];
try {
    (new \WPrism\ExecutableOwnerBoundary($policy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    $filesystemOwnerDiagnostics = $refusal->diagnostics;
    $owners = array_column($refusal->diagnostics, 'owner');
    $filesystemOwnersRefused = in_array('mu-plugin:agency-mu.php', $owners, true)
        && in_array('dropin:object-cache.php', $owners, true)
        && in_array('dropin:blog-deleted.php', $owners, true)
        && in_array('dropin:blog-inactive.php', $owners, true)
        && in_array('dropin:blog-suspended.php', $owners, true);
}
check($filesystemOwnersRefused,
    'transaction boundary covers MU plugins, ordinary drop-ins, and all multisite status drop-ins');

$expectedSiteOwners = [
    'mu-plugin:agency-mu.php',
    'dropin:object-cache.php',
    'dropin:blog-deleted.php',
    'dropin:blog-inactive.php',
    'dropin:blog-suspended.php',
];
$copyPasteOwnerRows = [];
$safeDiagnostics = true;
foreach ($filesystemOwnerDiagnostics as $diagnostic) {
    $owner = (string) ($diagnostic['owner'] ?? '');
    if (!in_array($owner, $expectedSiteOwners, true)) {
        continue;
    }
    $identity = $diagnostic['code_identity'] ?? null;
    $identityKeys = is_array($identity) ? array_keys($identity) : [];
    sort($identityKeys, SORT_STRING);
    $safeDiagnostics = $safeDiagnostics
        && $identityKeys === ['format', 'root', 'sha256']
        && !str_contains(json_encode($identity, JSON_THROW_ON_ERROR), $executableOwnerFixture)
        && !array_key_exists('files', (array) $identity);
    $copyPasteOwnerRows[] = [
        'owner' => $owner,
        'code_identity' => $identity,
        'rationale' => 'Fixture review confirms this exact executable owner stores no product reverse references.',
    ];
}
check($safeDiagnostics && count($copyPasteOwnerRows) === count($expectedSiteOwners),
    'undeclared site-owner diagnostics expose only copy/pasteable canonical code identity, never paths or file rosters');

$reviewedPolicy = clone $policy;
$reviewedPolicy->site['policy']['deletion_owner_agreements']['selectors'][0]['owners'] = array_merge(
    $reviewedPolicy->site['policy']['deletion_owner_agreements']['selectors'][0]['owners'],
    $copyPasteOwnerRows
);
$reviewedAccepted = true;
try {
    (new \WPrism\ExecutableOwnerBoundary($reviewedPolicy))->bind($boundaryWork);
} catch (Throwable) {
    $reviewedAccepted = false;
}
check($reviewedAccepted,
    'copying the diagnostic identity into an exact rationale-bearing v2 row preserves a usable reviewed path');

file_put_contents(WP_CONTENT_DIR . '/object-cache.php', "<?php // reviewed code changed\n");
$mismatchIdentity = null;
try {
    (new \WPrism\ExecutableOwnerBoundary($reviewedPolicy))->bind($boundaryWork);
} catch (\WPrism\CommandRefusalException $refusal) {
    foreach ($refusal->diagnostics as $diagnostic) {
        if (($diagnostic['owner'] ?? null) === 'dropin:object-cache.php'
            && ($diagnostic['code'] ?? null) === 'deletion_executable_owner_identity_mismatch') {
            $mismatchIdentity = $diagnostic['code_identity'] ?? null;
        }
    }
}
$mismatchCopyPasteSafe = is_array($mismatchIdentity)
    && array_keys($mismatchIdentity) === ['format', 'root', 'sha256']
    && !str_contains(json_encode($mismatchIdentity, JSON_THROW_ON_ERROR), $executableOwnerFixture)
    && !array_key_exists('files', $mismatchIdentity);
foreach ($reviewedPolicy->site['policy']['deletion_owner_agreements']['selectors'][0]['owners'] as &$ownerRow) {
    if (($ownerRow['owner'] ?? null) === 'dropin:object-cache.php') {
        $ownerRow['code_identity'] = $mismatchIdentity;
    }
}
unset($ownerRow);
$mismatchCopyAccepted = true;
try {
    (new \WPrism\ExecutableOwnerBoundary($reviewedPolicy))->bind($boundaryWork);
} catch (Throwable) {
    $mismatchCopyAccepted = false;
}
check($mismatchCopyPasteSafe && $mismatchCopyAccepted,
    'identity-mismatch diagnostics expose a safe replacement tuple that is directly copy/pasteable after review');
unlink(WPMU_PLUGIN_DIR . '/agency-mu.php');
unlink(WP_CONTENT_DIR . '/object-cache.php');
foreach (['blog-deleted.php', 'blog-inactive.php', 'blog-suspended.php'] as $statusDropIn) {
    unlink(WP_CONTENT_DIR . '/' . $statusDropIn);
}
$fakeWpdb->activationOptions['stylesheet'] = 'twentytwentyfive';
$fakeWpdb->activationOptions['template'] = 'twentytwentyfive';

$applyWriterHeld = true;
$applyWriterVerifications = 0;
$defaultWriterCoordinator = new \WPrism\DeleteGuardLockCoordinator(
    $policy,
    new \WPrism\DeleteGuardReferenceScanner($policy),
    $rows
);
$defaultWriterCoordinator->bind_writer_exclusion(woo_writer_witness());
$defaultWriterRefused = false;
try {
    $defaultWriterCoordinator->assert_writer_exclusion_plan_authority();
} catch (\WPrism\CommandRefusalException $refusal) {
    $defaultWriterRefused = $refusal->reasonCode === 'deletion_writer_exclusion_lost';
}
check($defaultWriterRefused,
    'default deletion coordinator refuses when no installed external-exclusion verifier was injected');
$fullWriterVerifications = 0;
$fullWriterCoordinator = new \WPrism\DeleteGuardLockCoordinator(
    $policy,
    new \WPrism\DeleteGuardReferenceScanner($policy),
    $rows,
    woo_writer_verifier($applyWriterHeld, $fullWriterVerifications)
);
$fullWriterCoordinator->bind_writer_exclusion(woo_verified_writer_witness());
$fullWriterCoordinator->assert_writer_exclusion_plan_authority();
check(
    $fullWriterVerifications === 1,
    'signed full-promotion witness reaches the same reverified deletion plan frontier without a fake scope'
);
$crossProfileCoordinator = new \WPrism\DeleteGuardLockCoordinator(
    $policy,
    new \WPrism\DeleteGuardReferenceScanner($policy),
    $rows,
    static fn(array $_binding): array => woo_writer_witness()
);
$crossProfileCoordinator->bind_writer_exclusion(woo_verified_writer_witness());
$crossProfileRefused = false;
try {
    $crossProfileCoordinator->assert_writer_exclusion_plan_authority();
} catch (\WPrism\CommandRefusalException $refusal) {
    $crossProfileRefused = $refusal->reasonCode === 'deletion_writer_exclusion_changed';
}
check(
    $crossProfileRefused,
    'a scoped witness cannot replace a bound full-promotion generation at reverify'
);
$apply = new \WPrism\DeleteGuardLockCoordinator(
    $policy,
    new \WPrism\DeleteGuardReferenceScanner($policy),
    $rows,
    woo_writer_verifier($applyWriterHeld, $applyWriterVerifications)
);
$apply->bind_writer_exclusion(woo_writer_witness());
$countGuard = new ReflectionMethod(\WPrism\DeleteGuardLockCoordinator::class, 'count');
$metaGuard = $variationMetaGuard;
$treeWithRef = [$groupedUuid => ['data' => ['meta' => ['_children' => ["{{post:$childUuid}}"]]]]];
$treeWithoutRef = [$groupedUuid => ['data' => ['meta' => ['_children' => []]]]];
$treeWithExplicitNull = [$groupedUuid => ['data' => ['meta' => ['_children' => null]]]];
$deletions = [$childUuid => ['data' => ['kind' => 'post', 'type' => 'product_variation']]];
$blocked = $countGuard->invoke($apply, $metaGuard, $childUuid, [$childUuid => true], $deletions, $treeWithRef, []);
check($blocked['count'] === 1 && $blocked['error'] === null,
    'child-only grouped-product deletion is blocked by the live serialized _children ref');
$repaired = $countGuard->invoke(
    $apply,
    $metaGuard,
    $childUuid,
    [$childUuid => true],
    $deletions,
    $treeWithoutRef,
    [$groupedUuid => true]
);
check($repaired['count'] === 0 && $repaired['error'] === null,
    'parent authored update removing _children makes the same child deletion converge safely');
$stillReferenced = $countGuard->invoke(
    $apply,
    $metaGuard,
    $childUuid,
    [$childUuid => true],
    $deletions,
    $treeWithRef,
    [$groupedUuid => true]
);
check($stillReferenced['count'] === 1,
    'parent update that retains the child token remains blocked');
$explicitNull = $countGuard->invoke(
    $apply,
    $metaGuard,
    $childUuid,
    [$childUuid => true],
    $deletions,
    $treeWithExplicitNull,
    [$groupedUuid => true]
);
check($explicitNull['count'] === 0 && $explicitNull['error'] !== null,
    'parent update with an explicit null grouped-child value is unsafe rather than an implicit removal');
$fakeWpdb->metaRows = [];
$plannedDangling = $countGuard->invoke(
    $apply,
    $metaGuard,
    $childUuid,
    [$childUuid => true],
    $deletions,
    $treeWithRef,
    [$groupedUuid => true]
);
check($plannedDangling['count'] === 1 && $plannedDangling['error'] === null,
    'parent update that would introduce a new grouped-child token is blocked before apply');
$fakeWpdb->metaRows = [[
    'guard_id' => 100,
    'source_id' => 7,
    'meta_value' => serialize([42]),
]];
$parentDeleted = $countGuard->invoke(
    $apply,
    $metaGuard,
    $childUuid,
    [$childUuid => true, $groupedUuid => true],
    $deletions,
    $treeWithRef,
    []
);
check($parentDeleted['count'] === 0 && $parentDeleted['error'] === null,
    'parent-plus-child tombstones exclude the deleted owner from grouped-child guard checks');

$methodUuid = '33333333-3333-4333-8333-333333333333';
$fakeWpdb->uuidToId["wc_zone_method:$methodUuid"] = 3;
$fakeWpdb->idToUuid['wc_zone_method:3'] = $methodUuid;
$fakeWpdb->optionRows = [[
    'guard_id' => 501,
    'option_name' => 'woocommerce_flat_rate_3_settings',
]];
$optionGuard = $methodOptionGuard;
$optionBlocked = $countGuard->invoke(
    $apply,
    $optionGuard,
    $methodUuid,
    [$methodUuid => true],
    [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
    [],
    []
);
check($optionBlocked['count'] === 1 && $optionBlocked['error'] === null,
    'shipping-method tombstone blocks while its live instance-settings option survives');
$fakeWpdb->optionRows = [[
    'guard_id' => 502,
    'option_name' => 'woocommerce_flat_rate_0003_settings',
    'option_value' => serialize(['title' => 'Malformed']),
    'autoload' => 'yes',
]];
$optionMalformedGuard = $countGuard->invoke(
    $apply,
    $optionGuard,
    $methodUuid,
    [$methodUuid => true],
    [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
    [],
    []
);
check($optionMalformedGuard['count'] === 0
    && str_contains((string) $optionMalformedGuard['error'], 'malformed option_name_refs namespace'),
    'Apply option-name guard refuses a leading-zero live option instead of hiding a stale row');
// issue #3347 slice 7: option_apply_target() moved from Apply onto
// OptionsMaterializer (Apply keeps only apply_options() as a facade). This
// guard fires from Policy::option_name_ref_match_details() -- the method's
// very first call, before Tokens/ApplyFieldMaterializer are ever touched --
// so an OptionsMaterializer built with only $policy set (mirroring $apply's
// own construction above: newInstanceWithoutConstructor() + policy alone)
// exercises the identical path.
$optionsMaterializerReflection = new ReflectionClass(\WPrism\OptionsMaterializer::class);
$optionsMaterializer = $optionsMaterializerReflection->newInstanceWithoutConstructor();
$optionsMaterializerPolicy = $optionsMaterializerReflection->getProperty('policy');
$optionsMaterializerPolicy->setValue($optionsMaterializer, $policy);
$optionTarget = new ReflectionMethod(\WPrism\OptionsMaterializer::class, 'option_apply_target');
$optionTargetRejected = false;
try {
    $optionTarget->invoke($optionsMaterializer, 'woocommerce_flat_rate_0003_settings', []);
} catch (Throwable $e) {
    $optionTargetRejected = str_contains($e->getMessage(), 'malformed option_name_refs namespace');
}
check($optionTargetRejected,
    'OptionsMaterializer option target resolution refuses a leading-zero raw key before generic option dispatch');
$snapshotMalformedRejected = false;
try {
    Snapshot::option_name_ref_preserved_ids($policy, null);
} catch (Throwable $e) {
    $snapshotMalformedRejected = str_contains($e->getMessage(), 'malformed option_name_refs namespace');
}
check($snapshotMalformedRejected,
    'Snapshot live/preservation scan refuses a leading-zero option-name namespace');
$fakeWpdb->optionRows = [[
    'guard_id' => 501,
    'option_name' => 'woocommerce_flat_rate_3_settings',
]];
$canonicalSettings = "woocommerce_flat_rate_{{wc_zone_method:$methodUuid}}_settings";
$optionRepaired = $countGuard->invoke(
    $apply,
    $optionGuard,
    $methodUuid,
    [$methodUuid => true],
    [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
    ['options/core' => ['data' => OptionState::document([
        $canonicalSettings => ['state' => 'deleted', 'expected_hash' => str_repeat('0', 64)],
    ])]],
    ['options/core' => true]
);
check($optionRepaired['count'] === 0 && $optionRepaired['error'] === null,
    'paired shipping-method option tombstone permits safe child deletion');

// A deleted method row must not cause the identity map to be pruned while
// its numeric settings option (or the previous canonical tokenized record)
// is still the only evidence needed to emit the paired option tombstone.
$previousSettings = OptionState::present(['title' => 'Flat rate'], 'yes');
$canonicalSettingsDocument = OptionState::document([
    $canonicalSettings => OptionState::deleted($previousSettings),
]);
$preservedMethodIds = Snapshot::option_name_ref_preserved_ids($policy, $canonicalSettingsDocument);
check(($preservedMethodIds['wc_zone_method'] ?? []) === [3],
    'dead wc_zone_method row preserves the mapped id for its surviving numeric settings option and canonical tombstone');
$fakeWpdb->ledgerReadError = true;
$ledgerReadFailedClosed = false;
try {
    Snapshot::option_name_ref_preserved_ids($policy, $canonicalSettingsDocument);
} catch (Throwable $e) {
    $ledgerReadFailedClosed = str_contains($e->getMessage(), 'ledger read failed: identity lookup by UUID');
}
$fakeWpdb->ledgerReadError = false;
check($ledgerReadFailedClosed,
    'option-name identity preservation refuses before pruning when its ledger lookup fails');
check($optionRepaired['count'] === 0 && $optionRepaired['error'] === null,
    'supported option repair still converges after dead-row identity preservation');
$restoredMethod = [
    'type' => 'woocommerce_shipping_zone_methods',
    'data' => [
        'uuid' => $methodUuid,
        'columns' => [
            'is_enabled' => 1,
            'method_id' => 'flat_rate',
            'method_order' => 1,
            'zone_id' => '{{wc_zone:44444444-4444-4444-8444-444444444444}}',
        ],
    ],
];
check(Snapshot::ensure_row($policy, $restoredMethod),
    'apply recreates a missing typed row whose identity was retained for option-name recovery');
check(count($fakeWpdb->insertedShippingMethodRows) === 1
    && ($fakeWpdb->insertedShippingMethodRows[0]['instance_id'] ?? null) === 3
    && ($fakeWpdb->insertedShippingMethodRows[0]['zone_id'] ?? null) === 0,
    'retained typed-row recovery reuses the exact mapped primary key with unresolved refs held at zero');
check(!Snapshot::ensure_row($policy, $restoredMethod)
    && count($fakeWpdb->insertedShippingMethodRows) === 1,
    'retained typed-row recovery is idempotent once the exact mapped row exists');
$fakeWpdb->optionRows = [];
$unrelatedCanonical = OptionState::document([
    "unrelated_{{wc_zone_method:$methodUuid}}_settings" => OptionState::deleted($previousSettings),
]);
$unrelatedRejected = false;
try {
    Snapshot::option_name_ref_preserved_ids($policy, $unrelatedCanonical);
} catch (Throwable $e) {
    $unrelatedRejected = str_contains($e->getMessage(), 'not owned by exactly one authored');
}
check($unrelatedRejected,
    'canonical known-kind token in an unrelated option name is rejected rather than pinning a dead mapping');
$fakeWpdb->optionScanError = true;
$scanFailedClosed = false;
try {
    Snapshot::option_name_ref_preserved_ids($policy, null);
} catch (Throwable $e) {
    $scanFailedClosed = str_contains($e->getMessage(), 'wp_options scan failed');
}
$fakeWpdb->optionScanError = false;
$fakeWpdb->last_error = '';
check($scanFailedClosed,
    'option-name identity pruning refuses rather than deleting mappings after a wp_options scan error');

// Shape contracts are intentionally strict: a target-controlled metadata
// value may be a scalar or a flat list of exact positive ids, never a loose
// PHP cast. The canonical side is tri-state so malformed desired state can
// never be mistaken for explicit removal.
$shapeCases = [
    [serialize([42]), $metaGuard, [42], 'serialized positive integer list'],
    [serialize(['42']), $metaGuard, [42], 'serialized decimal-string list'],
    [serialize(42), ['ref' => 'post'], [42], 'serialized scalar integer'],
    [serialize('42'), ['ref' => 'post'], [42], 'serialized scalar decimal string'],
    [serialize(null), ['ref' => 'post'], [], 'serialized null optional scalar'],
    [serialize([43]) . 'i:42;', $metaGuard, null, 'serialized list with trailing payload'],
    ['a:1:{i:0;i:43;}i:1;i:42;', $metaGuard, null, 'adversarial trailing list payload cannot hide target id'],
    [serialize(42) . 'i:43;', ['ref' => 'post'], null, 'serialized scalar with trailing payload'],
    [serialize([0]), $metaGuard, null, 'serialized zero list'],
    [serialize([42.0]), $metaGuard, null, 'serialized float list'],
    [serialize([true]), $metaGuard, null, 'serialized boolean list'],
    [serialize([['42']]), $metaGuard, null, 'serialized nested list'],
    [serialize([(object) ['id' => 42]]), $metaGuard, null, 'serialized object list'],
    [serialize(['not-an-id']), $metaGuard, null, 'serialized malformed list'],
    [42.0, ['ref' => 'post'], null, 'scalar float'],
    [true, ['ref' => 'post'], null, 'scalar boolean'],
    ['42.5', ['ref' => 'post'], null, 'malformed decimal string'],
    ['42', ['ref' => 'post'], [42], 'exact scalar decimal string'],
];
foreach ($shapeCases as [$raw, $guard, $expected, $label]) {
    $actual = DeleteGuardValueCodec::meta_value_ids($raw, $guard);
    check($actual === $expected, "metadata guard rejects unsafe shape: $label");
}
$childToken = "{{post:$childUuid}}";
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid([$childToken], 'post[]', $childUuid) === true,
    'canonical metadata list recognizes an exact target token');
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid([['42']], 'post[]', $childUuid) === null,
    'canonical metadata nested list is unsafe rather than an explicit removal');
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid([$childToken, 42], 'post[]', $childUuid) === null,
    'canonical metadata mixed malformed list is unsafe rather than a partial match');
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(['{{post:ffffffff-ffff-ffff-ffff-ffffffffffff}}'], 'post[]', $childUuid) === null,
    'canonical metadata malformed UUID token is unsafe rather than a nonmatching removal');
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(42, 'post', $childUuid) === null,
    'canonical metadata scalar integer is unsafe rather than an explicit removal');
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(null, 'post[]', $childUuid, false) === false,
    'missing canonical metadata key remains an explicit removal');
check(DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(null, 'post[]', $childUuid, true) === null,
    'explicit null canonical metadata value is unsafe rather than an explicit removal');

// Adversarial seams for the authored transaction boundary. The first
// witness is taken from the fresh plan; lock_and_revalidate then acquires a
// current-read boundary before any phase-2 repair. Both an update and an
// insert after the plan are refused, even though --force-delete-referenced
// could otherwise force a stable reference.
$metaRaceManifest = $policy->manifests[0];
$metaRaceManifest['deletions']['post:product_variation']['guards'] = [$metaGuard];
$metaRacePolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$metaRacePolicy->manifests = [$metaRaceManifest];
$metaRacePolicy->site = $policy->site;
$metaWriterHeld = true;
$metaWriterVerifications = 0;
$metaRaceApply = new \WPrism\DeleteGuardLockCoordinator(
    $metaRacePolicy,
    new \WPrism\DeleteGuardReferenceScanner($metaRacePolicy),
    Snapshot::row_tables($metaRacePolicy),
    woo_writer_verifier($metaWriterHeld, $metaWriterVerifications)
);
$metaRaceApply->bind_writer_exclusion(woo_writer_witness());
$lockAndRevalidate = new ReflectionMethod(\WPrism\DeleteGuardLockCoordinator::class, 'lock_and_revalidate');
$deleteGuardEngines = new ReflectionMethod(\WPrism\DeleteGuardLockCoordinator::class, 'assert_guard_engines');
$fakeWpdb->metaRows = [[
    'guard_id' => 200,
    'source_id' => 7,
    'meta_value' => serialize([42]),
]];
$fakeWpdb->modernIsolationError = true; // retained to prove no privileged/session isolation probe is needed
$fakeWpdb->lockingQueries = [];
$fakeWpdb->metadataQueries = [];
$fakeWpdb->engineQueries = [];
$fakeWpdb->topologyQueries = [];
$fakeWpdb->events = [];
\WPrism\DeleteGuardEvaluator::begin_authored_transaction();
$plannedMeta = $countGuard->invoke(
    $metaRaceApply,
    $metaGuard,
    $childUuid,
    [$childUuid => true],
    $deletions,
    $treeWithRef,
    []
);
$metaRaceRow = [
    'deletion_kind' => 'post',
    'deletion_type' => 'product_variation',
    'type' => 'post',
    'uuid' => $childUuid,
    'guard_witnesses' => ['0' => $plannedMeta['witness']],
];
$metaLockSafe = true;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $metaLockSafe = false;
}
check($metaLockSafe && count($fakeWpdb->lockingQueries) > 0,
    'metadata guard locks its indexed current-read range before phase-2 repair');
check(count($fakeWpdb->engineQueries) === 2
    && count($fakeWpdb->topologyQueries) === 3
    && $fakeWpdb->metadataQueries === [
        'SELECT 1 FROM `wp_options` LIMIT 1',
        'SELECT 1 FROM `wp_postmeta` LIMIT 1',
    ]
    && str_contains($fakeWpdb->engineQueries[0], "TABLE_NAME IN ('wp_options')")
    && str_contains($fakeWpdb->engineQueries[1], "TABLE_NAME IN ('wp_postmeta')")
    && !str_contains($fakeWpdb->engineQueries[1], 'wp_comments'),
    'deletion guard accepts InnoDB activation/guard tables and introspects only its exact prefixed scopes');
check($fakeWpdb->events === [
    'topology', 'metadata', 'engine', 'topology', 'metadata', 'engine', 'topology', 'locking',
], 'deletion boundary binds plan topology, locks activation facts, and re-censuses before its first guard row lock');

// Guard rechecks precede context capture, so filesystem owners need one more
// census at the exact row-delete boundary. Introduce a drop-in after a real
// final guard recheck and prove the bound assertion refuses before the
// mutation seam is allowed to advance.
$deleteBoundaryWarnings = [];
$metaRaceApply->recheck(
    $metaRaceRow,
    [$childUuid => true],
    $deletions,
    true,
    $treeWithRef,
    [],
    true,
    $deleteBoundaryWarnings
);
file_put_contents(WP_CONTENT_DIR . '/blog-suspended.php', "<?php\n");
$deleteMutationCount = 0;
$lateOwnerRefused = false;
try {
    $metaRaceApply->authorize_destructive_unit();
    $deleteMutationCount++;
} catch (\WPrism\CommandRefusalException $refusal) {
    $lateOwnerRefused = $refusal->reasonCode === 'deletion_executable_owner_changed';
}
unlink(WP_CONTENT_DIR . '/blog-suspended.php');
$metaRaceApply->end_writer_exclusion_transaction();
$transactionExecutorSource = (string) file_get_contents(
    $root . '/agent/src/Apply/AuthoredTransactionExecutor.php'
);
$normalizedTransactionExecutor = preg_replace('/\s+/', ' ', $transactionExecutorSource);
check(
    $lateOwnerRefused
        && $deleteMutationCount === 0
        && $metaWriterVerifications >= 2
        && is_string($normalizedTransactionExecutor)
        && str_contains(
            $normalizedTransactionExecutor,
            '$assertDeleteBoundary(); $this->deleteExecutor->delete_entity('
        ),
    'a drop-in introduced after guard recheck is refused by the assertion consumed immediately before delete'
);

// After the exact topology census identifies a present guard table, metadata
// locking is the first physical-table gate. No engine or guard-row read may be
// issued after the database says that lock boundary could not be acquired.
$fakeWpdb->metadataProbeError = true;
$fakeWpdb->last_error = '';
$fakeWpdb->metadataQueries = [];
$fakeWpdb->engineQueries = [];
$fakeWpdb->lockingQueries = [];
$fakeWpdb->events = [];
$metadataProbeRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $metadataProbeRefused = str_contains($e->getMessage(), 'unable to acquire metadata lock')
        && str_contains($e->getMessage(), 'simulated metadata probe failure');
}
$metaRaceApply->end_writer_exclusion_transaction();
check(
    $metadataProbeRefused
        && $fakeWpdb->events === ['topology', 'metadata']
        && $fakeWpdb->engineQueries === []
        && $fakeWpdb->lockingQueries === [],
    'deletion guard fails closed on a metadata-lock error after topology but before engine or row-lock reads'
);
$fakeWpdb->metadataProbeError = false;
$fakeWpdb->last_error = '';

// Storage-engine support is part of the lock contract, not a best-effort
// diagnostic. Every refusal below must happen before guard_lock_index() or
// any SELECT ... FOR UPDATE is attempted.
$tableEngineBeforeRefusals = $fakeWpdb->tableEngines;
$fakeWpdb->tableEngines['wp_postmeta'] = 'MyISAM';
$fakeWpdb->lockingQueries = [];
$myisamRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $myisamRefused = str_contains(strtoupper($e->getMessage()), 'MYISAM')
        && str_contains($e->getMessage(), 'InnoDB required');
}
$metaRaceApply->end_writer_exclusion_transaction();
check($myisamRefused && $fakeWpdb->lockingQueries === [],
    'deletion guard refuses MyISAM before issuing any locking query');

$fakeWpdb->tableEngines['wp_postmeta'] = null;
$fakeWpdb->lockingQueries = [];
$nullEngineRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $nullEngineRefused = str_contains($e->getMessage(), 'NULL/unknown');
}
$metaRaceApply->end_writer_exclusion_transaction();
check($nullEngineRefused && $fakeWpdb->lockingQueries === [],
    'deletion guard refuses a null/unknown storage engine before locking');

unset($fakeWpdb->tableEngines['wp_postmeta']);
$fakeWpdb->lockingQueries = [];
$missingEngineRowRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $missingEngineRowRefused = str_contains($e->getMessage(), 'required guard table')
        && str_contains($e->getMessage(), 'is absent')
        && str_contains($e->getMessage(), 'wp_postmeta');
}
$metaRaceApply->end_writer_exclusion_transaction();
check($missingEngineRowRefused && $fakeWpdb->lockingQueries === [],
    'deletion guard refuses required physical table absence before engine or row locking');

$fakeWpdb->tableEngines = $tableEngineBeforeRefusals;
$fakeWpdb->engineIntrospectionError = true;
$fakeWpdb->lockingQueries = [];
$introspectionRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $introspectionRefused = str_contains($e->getMessage(), 'exact guard-table topology census failed')
        && str_contains($e->getMessage(), 'simulated information_schema failure');
}
$metaRaceApply->end_writer_exclusion_transaction();
check($introspectionRefused && $fakeWpdb->lockingQueries === [],
    'deletion guard refuses a failed exact topology census before locking');
$fakeWpdb->engineIntrospectionError = false;
$fakeWpdb->last_error = '';

// Prefixing is part of the actual table identity. The same gate must inspect
// the prefixed name that the later guard SQL will lock, not the raw manifest
// spelling.
$savedPrefix = $fakeWpdb->prefix;
$savedEngines = $fakeWpdb->tableEngines;
$fakeWpdb->prefix = 'custom_';
$fakeWpdb->tableEngines = ['custom_postmeta' => 'InnoDB'];
$fakeWpdb->engineQueries = [];
$prefixedEngineAccepted = true;
try {
    $deleteGuardEngines->invoke($metaRaceApply, [$metaRaceRow]);
} catch (Throwable $e) {
    $prefixedEngineAccepted = false;
}
check($prefixedEngineAccepted
    && count($fakeWpdb->engineQueries) === 1
    && str_contains($fakeWpdb->engineQueries[0], "TABLE_NAME IN ('custom_postmeta')")
    && !str_contains($fakeWpdb->engineQueries[0], "'postmeta'"),
    'deletion guard engine gate resolves prefixed guard table names authoritatively');
$fakeWpdb->prefix = $savedPrefix;
$fakeWpdb->tableEngines = $savedEngines;

// Duplicate guard declarations and duplicate delete rows still produce one,
// deterministic, sorted information_schema scope. Unrelated manifest tables
// never enter this query.
$scopeManifest = $metaRaceManifest;
$scopeManifest['deletions']['post:product_variation']['guards'] = [
    $optionGuard,
    $metaGuard,
    $metaGuard,
];
$scopePolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$scopePolicy->manifests = [$scopeManifest];
$scopeWriterHeld = true;
$scopeWriterVerifications = 0;
$scopeApply = new \WPrism\DeleteGuardLockCoordinator(
    $scopePolicy,
    new \WPrism\DeleteGuardReferenceScanner($scopePolicy),
    Snapshot::row_tables($scopePolicy),
    woo_writer_verifier($scopeWriterHeld, $scopeWriterVerifications)
);
$fakeWpdb->engineQueries = [];
$scopeAccepted = true;
try {
    $deleteGuardEngines->invoke($scopeApply, [$metaRaceRow, $metaRaceRow]);
} catch (Throwable $e) {
    $scopeAccepted = false;
}
check($scopeAccepted
    && count($fakeWpdb->engineQueries) === 1
    && str_contains($fakeWpdb->engineQueries[0], "TABLE_NAME IN ('wp_options','wp_postmeta')")
    && !str_contains($fakeWpdb->engineQueries[0], 'wp_comments'),
    'deletion guard engine scope is deduplicated and deterministically sorted per current delete work');

$savedMetaIndexes = $fakeWpdb->indexRows['wp_postmeta'];
$fakeWpdb->indexRows['wp_postmeta'] = [];
$unindexedRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $unindexedRefused = str_contains($e->getMessage(), 'no complete indexed lock boundary');
}
$metaRaceApply->end_writer_exclusion_transaction();
$fakeWpdb->indexRows['wp_postmeta'] = $savedMetaIndexes;
check($unindexedRefused,
    'metadata deletion guard refuses an unindexed lock boundary instead of claiming race safety');

$fakeWpdb->metaRows[0]['meta_value'] = serialize([99]);
$metaUpdateRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$metaRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $metaUpdateRefused = str_contains($e->getMessage(), 'witness changed after planning');
}
$metaRaceApply->end_writer_exclusion_transaction();
check($metaUpdateRefused,
    'concurrent grouped-child metadata update is refused before authored repair');

$fakeWpdb->metaRows = [];
$plannedEmptyMeta = $countGuard->invoke(
    $metaRaceApply,
    $metaGuard,
    $childUuid,
    [$childUuid => true],
    $deletions,
    $treeWithRef,
    []
);
$insertRaceRow = $metaRaceRow;
$insertRaceRow['guard_witnesses'] = ['0' => $plannedEmptyMeta['witness']];
$fakeWpdb->metaRows = [[
    'guard_id' => 201,
    'source_id' => 7,
    'meta_value' => serialize([42]),
]];
$insertRefused = false;
try {
    $lockAndRevalidate->invoke(
        $metaRaceApply,
        [$insertRaceRow],
        [$childUuid => true],
        $deletions,
        $treeWithRef,
        []
    );
} catch (Throwable $e) {
    $insertRefused = str_contains($e->getMessage(), 'witness changed after planning');
}
$metaRaceApply->end_writer_exclusion_transaction();
check($insertRefused,
    'metadata reference inserted after the plan is refused by the locked witness boundary');

// The same seam covers option-name guards: a settings value change is a
// witness change, not a forceable stable-reference warning.
$optionRaceManifest = $policy->manifests[0];
$optionRaceManifest['deletions']['table:woocommerce_shipping_zone_methods']['guards'] = [$optionGuard];
$optionRacePolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$optionRacePolicy->manifests = [$optionRaceManifest];
$optionWriterHeld = true;
$optionWriterVerifications = 0;
$optionRaceApply = new \WPrism\DeleteGuardLockCoordinator(
    $optionRacePolicy,
    new \WPrism\DeleteGuardReferenceScanner($optionRacePolicy),
    Snapshot::row_tables($optionRacePolicy),
    woo_writer_verifier($optionWriterHeld, $optionWriterVerifications)
);
$optionRaceApply->bind_writer_exclusion(woo_writer_witness());
$fakeWpdb->modernIsolationError = false;
$fakeWpdb->optionRows = [[
    'guard_id' => 501,
    'option_name' => 'woocommerce_flat_rate_3_settings',
    'option_value' => serialize(['title' => 'Flat rate']),
    'autoload' => 'yes',
]];
$optionPlanned = $countGuard->invoke(
    $optionRaceApply,
    $optionGuard,
    $methodUuid,
    [$methodUuid => true],
    [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
    [],
    []
);
$optionRaceRow = [
    'deletion_kind' => 'table',
    'deletion_type' => 'woocommerce_shipping_zone_methods',
    'type' => 'woocommerce_shipping_zone_methods',
    'uuid' => $methodUuid,
    'guard_witnesses' => ['0' => $optionPlanned['witness']],
];
$optionLockSafe = true;
try {
    $lockAndRevalidate->invoke(
        $optionRaceApply,
        [$optionRaceRow],
        [$methodUuid => true],
        [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
        [],
        []
    );
} catch (Throwable $e) {
    $optionLockSafe = false;
}
$optionRaceApply->end_writer_exclusion_transaction();
check($optionLockSafe,
    'option-name guard locks the indexed option-name range before deleting settings');
$fakeWpdb->optionRows[0]['option_value'] = serialize(['title' => 'Changed concurrently']);
$optionUpdateRefused = false;
try {
    $lockAndRevalidate->invoke(
        $optionRaceApply,
        [$optionRaceRow],
        [$methodUuid => true],
        [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
        [],
        []
    );
} catch (Throwable $e) {
    $optionUpdateRefused = str_contains($e->getMessage(), 'witness changed after planning');
}
$optionRaceApply->end_writer_exclusion_transaction();
check($optionUpdateRefused,
    'concurrent shipping-method settings change is refused before option deletion');
$fakeWpdb->optionRows = [];
$optionEmptyPlan = $countGuard->invoke(
    $optionRaceApply,
    $optionGuard,
    $methodUuid,
    [$methodUuid => true],
    [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
    [],
    []
);
$optionInsertRaceRow = $optionRaceRow;
$optionInsertRaceRow['guard_witnesses'] = ['0' => $optionEmptyPlan['witness']];
$fakeWpdb->optionRows = [[
    'guard_id' => 502,
    'option_name' => 'woocommerce_flat_rate_3_settings',
    'option_value' => serialize(['title' => 'Inserted concurrently']),
    'autoload' => 'yes',
]];
$optionInsertRefused = false;
try {
    $lockAndRevalidate->invoke(
        $optionRaceApply,
        [$optionInsertRaceRow],
        [$methodUuid => true],
        [$methodUuid => ['data' => ['kind' => 'table', 'type' => 'woocommerce_shipping_zone_methods']]],
        [],
        []
    );
} catch (Throwable $e) {
    $optionInsertRefused = str_contains($e->getMessage(), 'witness changed after planning');
}
$optionRaceApply->end_writer_exclusion_transaction();
check($optionInsertRefused,
    'shipping-method settings option inserted after the plan is refused by the locked range');
$planHash = new ReflectionMethod(\WPrism\ApplyPlanner::class, 'plan_precondition_hash');
$hashInputs = [
    'delete' => [['uuid' => $childUuid, 'guard_witnesses' => ['0' => str_repeat('a', 64)]]],
];
$changedHashInputs = $hashInputs;
$changedHashInputs['delete'][0]['guard_witnesses']['0'] = str_repeat('b', 64);
check($planHash->invoke(null, $hashInputs) !== $planHash->invoke(null, $changedHashInputs),
    'plan_precondition_hash binds exact deletion guard witnesses');

// Safe unserialization regression: a target-controlled object must not be
// instantiated while a metadata guard inspects its value.
class WooDeletionGuardProbe {
    public function __wakeup(): void {
        $GLOBALS['woo_guard_wakeup'] = true;
    }
}
$GLOBALS['woo_guard_wakeup'] = false;
$fakeWpdb->metaRows = [[
    'guard_id' => 101,
    'source_id' => 7,
    'meta_value' => serialize(new WooDeletionGuardProbe()),
]];
$unsafe = $countGuard->invoke($apply, $metaGuard, $childUuid, [$childUuid => true], $deletions, $treeWithRef, []);
check($unsafe['count'] === 0 && $unsafe['error'] !== null && $GLOBALS['woo_guard_wakeup'] === false,
    'metadata deletion guard decodes target values without instantiating supplied objects');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
