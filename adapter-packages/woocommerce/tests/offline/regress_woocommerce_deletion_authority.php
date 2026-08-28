<?php
/**
 * Offline deletion-engine regression using a bounded synthetic Woo contract.
 *
 * This is deliberately target-free: live capture/apply owns the SQL and
 * WooCommerce API/cache probes. The pure contract here prevents a future
 * shipped Woo adapter intentionally advertises no delete authority because
 * an open extension ecosystem can add reverse references outside this grammar.
 * The synthetic declaration below keeps the generic locking, witness, typed-
 * reference, and child-before-parent machinery executable without turning
 * that mechanism test into a production capability claim.
 */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 3);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
$root = dirname(__DIR__, 4);

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
require $root . '/agent/src/Review/Canary.php';
require $root . '/agent/src/Repository/IdentityNotes.php';
require $root . '/agent/src/Repository/Snapshot.php';
require $root . '/agent/src/Kernel/TransientDbException.php';
require $root . '/agent/src/Publication/Publish.php';
require $root . '/agent/src/Kernel/PersonalData.php';
require $root . '/agent/src/Promotion/PromotionLock.php';
require $root . '/agent/src/Repository/Identity.php';
require $root . '/agent/src/Repository/IdentityBackup.php';
require $root . '/agent/src/Review/Orphans.php';
require $root . '/agent/src/Capture/Capture.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Code/CodeCompatibility.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Delete/DeleteGuardValueCodec.php';
require_once $root . '/agent/src/Apply/Apply.php';

use Duo\Deletion;
use Duo\DeleteGuardValueCodec;
use Duo\Code;
use Duo\CodeCompatibility;
use Duo\OptionState;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\Snapshot;

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
    public bool $savepointExists = false;
    public int $insert_id = 0;
    /** @var list<string> */
    public array $lockingQueries = [];
    /** @var list<string> */
    public array $metadataQueries = [];
    /** @var list<string> */
    public array $engineQueries = [];
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
        if (preg_match("/SELECT local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $sql, $m)) {
            if ($this->ledgerReadError) {
                $this->last_error = 'simulated ledger lookup failure';
                return null;
            }
            return $this->uuidToId[$m[2] . ':' . $m[1]] ?? null;
        }
        if (preg_match("/SELECT uuid FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = (\\d+)/", $sql, $m)) {
            return $this->idToUuid[$m[1] . ':' . $m[2]] ?? null;
        }
        return null;
    }

    public function get_row(string $sql, $format = null): ?array {
        if (preg_match(
            "/SELECT entity_type, local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/",
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
            "/SELECT uuid, entity_type FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = (\d+)/",
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
        if ($this->optionScanError && str_contains($sql, 'SELECT `option_name` FROM `wp_options`')) {
            $this->last_error = 'simulated option scan failure';
            return [];
        }
        if (str_contains($sql, 'information_schema.TABLES')) {
            $this->engineQueries[] = $sql;
            $this->events[] = 'engine';
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
        if (str_contains($sql, 'FOR UPDATE')) {
            $this->lockingQueries[] = $sql;
            $this->events[] = 'locking';
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
            'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
            'guards' => [
                ['column' => 'comment_post_ID', 'id_kind' => 'post', 'reason' => 'comments reference this product', 'table' => 'comments'],
                ['column' => 'post_parent', 'exclude_where' => ['post_type' => 'revision'], 'id_kind' => 'post', 'reason' => 'child posts reference this product', 'source_id_kind' => 'post', 'source_pk' => 'ID', 'table' => 'posts'],
                ['column' => 'product_id', 'id_kind' => 'post', 'reason' => 'orders reference this product', 'table' => 'wc_order_product_lookup'],
                ['column' => 'post_id', 'identity_column' => 'meta_id', 'id_kind' => 'post', 'meta_key' => '_children', 'reason' => 'grouped products reference this product', 'ref' => 'post[]', 'source_id_kind' => 'post', 'source_pk' => 'post_id', 'table' => 'postmeta'],
            ],
        ],
        'post:product_variation' => [
            'cascades' => ['postmeta', 'post_revisions', 'term_relationships'],
            'guards' => [
                ['column' => 'comment_post_ID', 'id_kind' => 'post', 'reason' => 'comments reference this product variation', 'table' => 'comments'],
                ['column' => 'product_id', 'id_kind' => 'post', 'reason' => 'orders reference this product variation', 'table' => 'wc_order_product_lookup'],
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
    \Duo\AdapterLibrary::fromSourcePackage($root, 'woocommerce')
);
check($shippedPolicy->deletion_capability('post:product') === null,
    'shipped Woo adapter keeps product deletion fail-closed for the open extension ecosystem');
$fixtureManifest = $shippedPolicy->manifests[0];
$fixtureManifest['deletions'] = synthetic_woo_deletions();
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$adapterLibrary = new ReflectionProperty(Policy::class, 'adapterLibrary');
$adapterLibrary->setValue($policy, \Duo\AdapterLibrary::fromSourcePackage($root, 'woocommerce'));
$policy->manifests = [$fixtureManifest];
$variation = $policy->deletion_capability('post:product_variation');
$product = $policy->deletion_capability('post:product');

check($variation !== null, 'synthetic product_variation contract exercises explicit deletion authority');
check(Deletion::descriptor(['type' => 'post', 'data' => ['type' => 'product_variation']]) === [
    'kind' => 'post', 'type' => 'product_variation',
], 'capture deletion descriptor preserves the product_variation selector');
check($variation['cascades'] === ['post_revisions', 'postmeta', 'term_relationships'],
    'product_variation declares the complete post-side cascade contract');
$variationReasons = array_column($variation['guards'], 'reason');
check(in_array('orders reference this product variation', $variationReasons, true),
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
// DUO-3403 (PR #176 finding 5, site 1): Apply::count_guard_refs() copies an
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
    !\Duo\CommandRefusalException::containsSensitivePublicDetail(['message' => $m]);
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
    'DUO-3403: every POLICY-REACHABLE option_name_ref_match_details() throw is enumerated for the plan-JSON closed-set pin');
foreach ($optionRefThrows as $throwMessage) {
    check($screenClean($throwMessage),
        'DUO-3403: option_name_ref plan-`blocked` message is path/credential-free (' . $throwMessage . ')');
}
// The 4th template ("did not expose its named id capture") is load-guarded
// unreachable — validate_option_name_refs enforces exactly one (?<id>)
// capture (Policy.php ~3881), so no policy path can produce it — but it is a
// member of the closed set and interpolates only the option name, so its
// literal is screened directly rather than left the one unsampled template.
check($screenClean("duo: option_name_refs rule for option 'woocommerce_flat_rate_0003_settings' did not expose its named id capture"),
    'DUO-3403: the load-unreachable option_name_ref template is also path/credential-free (closed set fully covered, not sampled)');
// Self-test: the screen this pin trusts MUST flag a path- and a credential-
// shaped variant, or the pin above would pass vacuously.
check(!$screenClean("duo: option '/Users/alice/.aws/credentials' matches a malformed option_name_refs namespace"),
    'DUO-3403 self-test: a path-shaped option_name_ref message would be caught by this pin');
check(!$screenClean("duo: option_name_refs owner leaked token sk_live_0123456789abcdef in its match"),
    'DUO-3403 self-test: a credential-shaped option_name_ref message would be caught by this pin');

$rows = Snapshot::row_tables($policy);
$order = Snapshot::topo_order($rows);
$position = array_flip($order);
check($position['woocommerce_shipping_zones'] < $position['woocommerce_shipping_zone_locations'],
    'shipping-zone parent is ordered before location child for phase-2 creation');
check($position['woocommerce_shipping_zones'] < $position['woocommerce_shipping_zone_methods'],
    'shipping-zone parent is ordered before method child for phase-2 creation');
check($position['woocommerce_tax_rates'] < $position['woocommerce_tax_rate_locations'],
    'tax-rate parent is ordered before location child for phase-2 creation');

$deletionRank = new ReflectionMethod(\Duo\ApplyPlanner::class, 'deletion_rank');
$rankApply = new \Duo\ApplyPlanner(
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
$cacheNote = (string) ($policy->manifests[0]['notes']['shipping/tax typed-row deletion cache boundary (DUO-329x)'] ?? '');
check(str_contains($cacheNote, 'WC_Cache_Helper::invalidate_cache_group')
    && str_contains($cacheNote, 'persistent object-cache'),
    'manifest pins Woo public cache invalidation for persistent shipping/tax caches');
// DUO-3338: the cache boundary moved from an eval'd command string to a
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
$compatibilityRoot = sys_get_temp_dir() . '/duo-woo-version-boundary-' . bin2hex(random_bytes(8));
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
$cacheProvider = new \Duo\Providers\WoocommerceCache(
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
$apply = new \Duo\DeleteGuardLockCoordinator(
    $policy,
    new \Duo\DeleteGuardReferenceScanner($policy),
    $rows
);
$countGuard = new ReflectionMethod(\Duo\DeleteGuardLockCoordinator::class, 'count');
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
// DUO-3347 slice 7: option_apply_target() moved from Apply onto
// OptionsMaterializer (Apply keeps only apply_options() as a facade). This
// guard fires from Policy::option_name_ref_match_details() -- the method's
// very first call, before Tokens/ApplyFieldMaterializer are ever touched --
// so an OptionsMaterializer built with only $policy set (mirroring $apply's
// own construction above: newInstanceWithoutConstructor() + policy alone)
// exercises the identical path.
$optionsMaterializerReflection = new ReflectionClass(\Duo\OptionsMaterializer::class);
$optionsMaterializer = $optionsMaterializerReflection->newInstanceWithoutConstructor();
$optionsMaterializerPolicy = $optionsMaterializerReflection->getProperty('policy');
$optionsMaterializerPolicy->setValue($optionsMaterializer, $policy);
$optionTarget = new ReflectionMethod(\Duo\OptionsMaterializer::class, 'option_apply_target');
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
$metaRaceApply = new \Duo\DeleteGuardLockCoordinator(
    $metaRacePolicy,
    new \Duo\DeleteGuardReferenceScanner($metaRacePolicy),
    Snapshot::row_tables($metaRacePolicy)
);
$lockAndRevalidate = new ReflectionMethod(\Duo\DeleteGuardLockCoordinator::class, 'lock_and_revalidate');
$deleteGuardEngines = new ReflectionMethod(\Duo\DeleteGuardLockCoordinator::class, 'assert_guard_engines');
$fakeWpdb->metaRows = [[
    'guard_id' => 200,
    'source_id' => 7,
    'meta_value' => serialize([42]),
]];
$fakeWpdb->modernIsolationError = true; // retained to prove no privileged/session isolation probe is needed
$fakeWpdb->lockingQueries = [];
$fakeWpdb->metadataQueries = [];
$fakeWpdb->engineQueries = [];
$fakeWpdb->events = [];
\Duo\DeleteGuardEvaluator::begin_authored_transaction();
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
check(count($fakeWpdb->engineQueries) === 1
    && $fakeWpdb->metadataQueries === ['SELECT 1 FROM `wp_postmeta` LIMIT 1']
    && str_contains($fakeWpdb->engineQueries[0], "TABLE_NAME IN ('wp_postmeta')")
    && !str_contains($fakeWpdb->engineQueries[0], 'wp_comments'),
    'deletion guard accepts an InnoDB table and introspects only the current prefixed guard scope');
check($fakeWpdb->events === ['metadata', 'engine', 'locking'],
    'deletion guard metadata-locks and validates engines before its first authored guard lock');

// A metadata-lock failure is a hard refusal at the first gate: information
// schema must not be consulted and no guard read may be issued after the
// database has already said the table boundary could not be acquired.
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
check(
    $metadataProbeRefused
        && $fakeWpdb->events === ['metadata']
        && $fakeWpdb->engineQueries === []
        && $fakeWpdb->lockingQueries === [],
    'deletion guard fails closed on a metadata-lock error before engine or row-lock reads'
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
    $missingEngineRowRefused = str_contains($e->getMessage(), 'missing from information_schema.TABLES')
        && str_contains($e->getMessage(), 'wp_postmeta');
}
check($missingEngineRowRefused && $fakeWpdb->lockingQueries === [],
    'deletion guard refuses a missing information_schema engine row before locking');

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
    $introspectionRefused = str_contains($e->getMessage(), 'storage-engine introspection failed')
        && str_contains($e->getMessage(), 'simulated information_schema failure');
}
check($introspectionRefused && $fakeWpdb->lockingQueries === [],
    'deletion guard refuses a failed information_schema query before locking');
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
$scopeApply = new \Duo\DeleteGuardLockCoordinator(
    $scopePolicy,
    new \Duo\DeleteGuardReferenceScanner($scopePolicy),
    Snapshot::row_tables($scopePolicy)
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
check($insertRefused,
    'metadata reference inserted after the plan is refused by the locked witness boundary');

// The same seam covers option-name guards: a settings value change is a
// witness change, not a forceable stable-reference warning.
$optionRaceManifest = $policy->manifests[0];
$optionRaceManifest['deletions']['table:woocommerce_shipping_zone_methods']['guards'] = [$optionGuard];
$optionRacePolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$optionRacePolicy->manifests = [$optionRaceManifest];
$optionRaceApply = new \Duo\DeleteGuardLockCoordinator(
    $optionRacePolicy,
    new \Duo\DeleteGuardReferenceScanner($optionRacePolicy),
    Snapshot::row_tables($optionRacePolicy)
);
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
check($optionInsertRefused,
    'shipping-method settings option inserted after the plan is refused by the locked range');
$planHash = new ReflectionMethod(\Duo\ApplyPlanner::class, 'plan_precondition_hash');
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
