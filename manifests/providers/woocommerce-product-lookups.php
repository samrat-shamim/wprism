<?php
namespace Duo\Providers;

use Duo\Policy;

/**
 * WooCommerce 11.x product lookup adapter.
 *
 * Duo writes product posts and postmeta with SQL, intentionally bypassing the
 * save hooks Woo normally uses to maintain derived product state. This
 * adapter is the manifest-owned boundary for the product-meta and product-
 * attribute lookups, price, sale-schedule, and mixed product/POS visibility
 * surfaces that have independent synchronous verification.
 * It deliberately does not call on_product_changed():
 * Woo's public hook-facing method schedules Action Scheduler work by default,
 * which would leave a successful Duo apply with a pending, non-deterministic
 * lookup update.  The public data-store methods used here are synchronous.
 *
 * Dispatch note (DUO-3342): this file is now reached through the DUO-3338
 * provider contract — identity binding, negotiation before the first target
 * mutation, a declared timeout budget, and a verified receipt — rather than
 * the engine's regenerator channel it was written against. The two things
 * that forced the older channel are both gone: DUO-3369 gave a capability
 * structured arguments and the engine batch CHANNELS (`deletions`,
 * `reparents`, `retry`, `always_on_write`), and DUO-3342 made those channels
 * carry the pre-delete inventory and own their durable markers, so the
 * deletion and reparent context this adapter requires is expressible without
 * loss. Everything below invoke() is the regenerator code MOVED, not
 * rewritten: the batch entry point keeps its `regenerate_batch(array
 * $liveIds, array $deletionContext, ?callable $heartbeat = null)` shape and
 * its internal contract with the deletion-context rows, and invoke() is the
 * thin mapping from the engine envelope onto exactly those two arguments.
 *
 * What did NOT survive the move: the single-id `regenerate()` boundary, which
 * existed only for the regenerator channel's per-entity path. Nothing calls it
 * now, and leaving it would advertise participation in a contract this class
 * no longer has.
 */
final class WoocommerceProductLookups {
    private Policy $policy;

    /** @var array<string,string>|null column => validated SQL cast */
    private ?array $lookupColumnCasts = null;

    /** @var list<string>|null exact core columns safe to transfer */
    private ?array $lookupReadColumns = null;

    private const META_LOOKUP = 'wc_product_meta_lookup';
    private const ATTRIBUTE_LOOKUP = 'wc_product_attributes_lookup';
    private const CAPABILITY = 'rebuild_product_lookups';
    private const MAX_SCOPED_PRODUCTS = 50000;
    private const MAX_VISIBILITY_PRODUCTS = 50000;
    private const MAX_ATTRIBUTE_LOOKUP_ROWS = 200000;
    private const MAX_GROUPED_PARENTS = 50000;
    private const MAX_LOOKUP_SCALAR_BYTES = 1024;
    private const MAX_LOOKUP_ROW_BYTES = 16384;
    private const MAX_TABLE_COLUMNS = 4096;
    private const MAX_COGS_VALUE_BYTES = 128;
    private const MAX_DOWNLOAD_META_BYTES_PER_PRODUCT = 1048576;
    private const MAX_DOWNLOAD_META_BYTES = 16777216;
    private const MAX_DOWNLOADS_PER_PRODUCT = 1000;
    private const MAX_DOWNLOADS = 10000;
    private const MAX_DOWNLOAD_ID_BYTES = 256;
    private const MAX_DOWNLOAD_NAME_BYTES = 4096;
    private const MAX_DOWNLOAD_FILE_BYTES = 8192;
    private const MAX_DOWNLOAD_ID_BYTES_TOTAL = 1048576;
    private const MAX_DOWNLOAD_NAME_BYTES_TOTAL = 4194304;
    private const MAX_DOWNLOAD_FILE_BYTES_TOTAL = 16777216;
    private const TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';

    /** @var array<string,string> exact 11.0.0/11.0.1 core column => type grammar */
    private const LOOKUP_CORE_COLUMN_TYPES = [
        'product_id' => '/^bigint(?:\(20\))?$/D',
        'sku' => '/^varchar\(100\)$/D',
        'global_unique_id' => '/^varchar\(100\)$/D',
        'virtual' => '/^tinyint(?:\(1\))?$/D',
        'downloadable' => '/^tinyint(?:\(1\))?$/D',
        'min_price' => '/^decimal\(19,4\)$/D',
        'max_price' => '/^decimal\(19,4\)$/D',
        'onsale' => '/^tinyint(?:\(1\))?$/D',
        'stock_quantity' => '/^double$/D',
        'stock_status' => '/^varchar\(100\)$/D',
        'rating_count' => '/^bigint(?:\(20\))?$/D',
        'average_rating' => '/^decimal\(3,2\)$/D',
        'total_sales' => '/^bigint(?:\(20\))?$/D',
        'tax_status' => '/^varchar\(100\)$/D',
        'tax_class' => '/^varchar\(100\)$/D',
        // Feature-gated and added by the exact native COGS lifecycle.
        'cogs_total_value' => '/^decimal\(19,4\)$/D',
    ];
    private const CORE_PRODUCT_TYPES = [
        'simple',
        'grouped',
        'variable',
        'external',
    ];
    private const PRODUCT_VISIBILITY_TERMS = [
        'exclude-from-search',
        'exclude-from-catalog',
        'featured',
        'outofstock',
        'rated-1',
        'rated-2',
        'rated-3',
        'rated-4',
        'rated-5',
    ];
    private const MERCHANT_VISIBILITY_TERMS = [
        'exclude-from-search',
        'exclude-from-catalog',
        'featured',
    ];
    private const DERIVED_VISIBILITY_TERMS = [
        'outofstock',
        'rated-1',
        'rated-2',
        'rated-3',
        'rated-4',
        'rated-5',
    ];

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-product-lookups',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '3.0.0',
        ];
    }

    /**
     * `reads`/`writes` are the capability-level summary in the canonical
     * surface vocabulary; the precise restorable/irreversible inventory of one
     * invocation is the declaring action's own `effects` list in
     * manifests/woocommerce.json. Attribute lookup rows are now included:
     * exact Woo 11.0.0/11.0.1 expose the public synchronous
     * LookupDataStore::create_data_for_product() writer plus a checked raw SQL
     * readback whose exact scoped bytes are bound into the receipt.
     *
     * `context` names every channel this repair actually consumes, and each
     * one is load-bearing rather than aspirational:
     *   - `deletions`  drives the public product-meta lookup deletion path —
     *     the row of a removed product/variation, its captured
     *     `child_ids`, and the still-live parent whose derived price the
     *     removal changed;
     *   - `reparents`  refreshes BOTH roots of a moved variation. One row per
     *     root is how the engine normalizes a chained A->B->C move, and
     *     invoke() regroups them per entity so the accumulated root set reaches
     *     the batch entry point exactly as the durable receipt held it;
     *   - `retry`      states that a previous apply committed something and
     *     failed, which is the run on which the durable receipts above are the
     *     only evidence left;
     *   - `always_on_write` states the basis this fired on, mirroring the
     *     `batch.always_on_write` flag the retired regen_dependency declared.
     *
     * `args` is empty deliberately: every input is engine-assembled. A
     * capability may not declare the reserved `entities` argument at all
     * (\Duo\Providers::validate_capability_declaration() refuses it), because
     * the batch the engine assembled and the batch a manifest asked for must
     * not be able to disagree.
     *
     * 300 seconds is the promotion lease TTL (PromotionLock::DEFAULT_TTL), and
     * that is the honest bound rather than a guess about catalog size. The
     * provider contract has no heartbeat parameter, so the engine renews the
     * lease immediately before and after this call and cannot renew during it.
     * What that does NOT mean — stated because the obvious reading is wrong —
     * is that overrunning the TTL self-aborts: PromotionLock::heartbeat()
     * tolerates an EXPIRED lease while the promotion's own process fence is
     * continuous (same process, same owner, same artifact), so the renewal on
     * the far side of a long call still succeeds unless another writer actually
     * took the lock while this one was expired. The real exposure of a long
     * invocation is therefore that window of acquirability, and pinning the
     * budget to the same number keeps the two bounds from disagreeing: an
     * overrun is reported once, as a declared-budget failure with the measured
     * duration attached, rather than later as a lock loss whose cause nobody
     * recorded.
     */
    public function capabilities(): array {
        return [
            self::CAPABILITY => [
                'args' => [],
                'reads' => [
                    'option:wc_downloads_approved_directories_mode',
                    'option:woocommerce_feature_cost_of_goods_sold_enabled',
                    'post:product',
                    'post:product_variation',
                    'table:postmeta',
                    'table:posts',
                    'table:terms',
                    'table:term_taxonomy',
                    'table:term_relationships',
                    'table:wc_product_attributes_lookup',
                    'table:wc_product_download_directories',
                ],
                'writes' => [
                    'table:actionscheduler_actions',
                    'table:postmeta',
                    'table:term_relationships',
                    'table:term_taxonomy',
                    'table:wc_product_attributes_lookup',
                    'table:wc_product_download_directories',
                    'table:wc_product_meta_lookup',
                ],
                'scope' => 'entity',
                'idempotent' => true,
                'timeout_seconds' => 300,
                'context' => ['always_on_write', 'deletions', 'reparents', 'retry'],
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /**
     * Map the engine batch envelope onto the batch entry point below, run it,
     * and return a receipt whose `verified` is true only because
     * verify_exact_state()/verify_sale_schedules() proved every supported
     * value surface. Attribute lookup synthesis delegates to Woo's public
     * scoped writer and verification reads the exact scoped rows directly
     * from the table; recovery therefore rejects equal-count row drift.
     *
     * before/after bind every selected product-meta lookup value, scheduled
     * sale timestamp, and downloadable-file locator into non-disclosing
     * fingerprints. The exact-state pass still proves what WooCommerce
     * derives during invocation; the fingerprints let scoped recovery prove
     * that same-count post-invocation drift did not replace those values.
     *
     * @param array<string,mixed> $args
     * @return array{before:array, after:array, verified:true}
     */
    public function invoke(string $capability, array $args): array {
        if ($capability !== self::CAPABILITY) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup provider does not implement capability '$capability'"
            );
        }
        $envelope = $args[\Duo\Providers::ENTITIES_ARG] ?? null;
        if (!is_array($envelope) || !array_key_exists('entities', $envelope)
            || !array_key_exists('deletions', $envelope) || !array_key_exists('reparents', $envelope)) {
            // Unreachable through the engine, which assembles exactly the
            // declared channels or refuses. Fail closed rather than repair an
            // empty batch and report it as done: a missing channel here would
            // mean this adapter silently stopped seeing tombstones.
            throw new \RuntimeException(
                'duo: WooCommerce product lookup repair received no engine batch envelope; expected the '
                . 'entities/deletions/reparents channels its capability declares'
            );
        }
        $liveIds = [];
        foreach ((array) $envelope['entities'] as $entity) {
            $id = (int) ($entity['id'] ?? 0);
            if ($id > 0) {
                $liveIds[$id] = $id;
            }
        }
        $deletionContext = $this->deletion_context_from_channels(
            (array) $envelope['deletions'],
            (array) $envelope['reparents']
        );

        $observed = array_values($liveIds);
        foreach ($deletionContext as $context) {
            foreach (array_merge(
                [(int) ($context['id'] ?? 0)],
                array_map('intval', (array) ($context['child_ids'] ?? [])),
                array_map('intval', (array) ($context['root_ids'] ?? []))
            ) as $id) {
                if ($id > 0) {
                    $observed[] = $id;
                }
            }
        }
        $observed = array_values(array_unique($observed));
        sort($observed, SORT_NUMERIC);

        $visibilityIntent = $this->visibility_snapshot(
            array_values($liveIds),
            true,
            $this->visibility_deletion_ids($deletionContext)
        );
        $before = $this->observe_provider_state($observed, array_values($liveIds), false);
        $this->regenerate_batch(array_values($liveIds), $deletionContext, null, $visibilityIntent);
        return [
            'before' => $before,
            'after' => $this->observe_provider_state($observed, array_values($liveIds), true),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $this->scoped_postcondition($args),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        if ($capability !== self::CAPABILITY) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup provider does not implement capability '$capability'"
            );
        }
        return [
            'operation' => $operation,
            'after' => $this->scoped_postcondition($args),
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @return array<string,int|string> */
    private function scoped_postcondition(array $args): array {
        return $this->observe_provider_state(
            $this->scoped_observed_ids($args),
            $this->scoped_live_ids($args),
            true
        );
    }

    /** @param array<string,mixed> $args @return list<int> */
    private function scoped_live_ids(array $args): array {
        $envelope = $args[\Duo\Providers::ENTITIES_ARG] ?? null;
        if (!is_array($envelope) || !is_array($envelope['entities'] ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup reconciliation received no engine live-entity batch'
            );
        }
        $ids = [];
        foreach ($envelope['entities'] as $entity) {
            $id = is_array($entity) ? (int) ($entity['id'] ?? 0) : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param array<string,mixed> $args @return list<int> */
    private function scoped_observed_ids(array $args): array {
        $envelope = $args[\Duo\Providers::ENTITIES_ARG] ?? null;
        if (!is_array($envelope) || !array_key_exists('entities', $envelope)
            || !array_key_exists('deletions', $envelope) || !array_key_exists('reparents', $envelope)) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup reconciliation received no engine batch envelope; expected the '
                . 'entities/deletions/reparents channels its capability declares'
            );
        }
        $liveIds = [];
        foreach ((array) $envelope['entities'] as $entity) {
            $id = (int) ($entity['id'] ?? 0);
            if ($id > 0) {
                $liveIds[$id] = $id;
            }
        }
        $observed = array_values($liveIds);
        foreach ($this->deletion_context_from_channels(
            (array) $envelope['deletions'],
            (array) $envelope['reparents']
        ) as $context) {
            foreach (array_merge(
                [(int) ($context['id'] ?? 0)],
                array_map('intval', (array) ($context['child_ids'] ?? [])),
                array_map('intval', (array) ($context['root_ids'] ?? []))
            ) as $id) {
                if ($id > 0) {
                    $observed[] = $id;
                }
            }
        }
        $observed = array_values(array_unique($observed));
        sort($observed, SORT_NUMERIC);
        return $observed;
    }

    /**
     * Rebuild the regenerator-shaped deletion context from the two engine
     * channels.
     *
     * The deletions channel is a straight relabel — the engine row already
     * carries the captured `post_type`, `parent_id`, and `child_ids` this
     * adapter's delete path reads (DUO-3342 is what put them there).
     *
     * The reparents channel needs regrouping, and that is the load-bearing
     * half. The engine delivers ONE ROW PER ROOT, which is how a chained
     * A->B->C move survives a row grammar whose fields are scalars; the batch
     * entry point below consumes a single `root_ids` list per entity, falling
     * back to old/new only for receipts that predate it. Collapsing to the
     * old/new pair here would silently drop root A and leave its price/meta
     * lookup stale forever — the exact bug the engine's durable-marker
     * merge exists to prevent.
     *
     * @param array<int,array<string,mixed>> $deletions
     * @param array<int,array<string,mixed>> $reparents
     * @return array<int,array<string,mixed>>
     */
    private function deletion_context_from_channels(array $deletions, array $reparents): array {
        $out = [];
        foreach ($deletions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $childIds = [];
            foreach ((array) ($row['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $childIds[$childId] = $childId;
                }
            }
            $childIds = array_values($childIds);
            sort($childIds, SORT_NUMERIC);
            $out[] = [
                'kind' => 'delete',
                'uuid' => (string) ($row['uuid'] ?? ''),
                'id' => (int) ($row['id'] ?? 0),
                'post_type' => (string) ($row['post_type'] ?? ''),
                'parent_id' => (int) ($row['parent_id'] ?? 0),
                'child_ids' => $childIds,
            ];
        }

        $moves = [];
        foreach ($reparents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string) ($row['uuid'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $key = $uuid !== '' ? 'uuid:' . $uuid : 'id:' . $id;
            $surface = (string) ($row['kind'] ?? '');
            if (!isset($moves[$key])) {
                $moves[$key] = [
                    'kind' => 'reparent',
                    'uuid' => $uuid,
                    'id' => $id,
                    'post_type' => str_starts_with($surface, 'post:')
                        ? substr($surface, strlen('post:'))
                        : '',
                    'parent_id' => (int) ($row['old_parent_id'] ?? 0),
                    'old_parent_id' => (int) ($row['old_parent_id'] ?? 0),
                    'new_parent_id' => (int) ($row['new_parent_id'] ?? 0),
                    'root_ids' => [],
                    'child_ids' => [],
                ];
            }
            $rootId = (int) ($row['root_id'] ?? 0);
            if ($rootId > 0) {
                $moves[$key]['root_ids'][$rootId] = $rootId;
            }
        }
        foreach ($moves as $move) {
            $move['root_ids'] = array_values($move['root_ids']);
            sort($move['root_ids'], SORT_NUMERIC);
            $out[] = $move;
        }
        return $out;
    }

    /**
     * Bind exact lookup rows for a bounded id set without placing merchant
     * values in a receipt. The requested ids enter the fingerprint even when
     * no row exists, so absence and scope changes are distinct states. One
     * ordered SELECT per 200 products keeps recovery observation bounded.
     *
     * @param array<int,int> $ids
     * @return array{scoped_products:int, meta_lookup_rows:int, lookup_scope_sha256:string}
     */
    private function observe_lookup_state(array $ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        $this->assert_scoped_product_count($ids, 'product lookup receipt observation');
        $table = $this->prefixed_table(self::META_LOOKUP);
        $columns = $this->lookup_read_columns($table);
        $projection = implode(', ', array_map(
            static fn(string $column): string => "`$column`",
            $columns
        ));
        $rowsById = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = count($chunk) + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT $projection FROM `$table` "
                . "WHERE product_id IN ($placeholders) ORDER BY product_id ASC LIMIT $limit",
                ...$chunk
            ), 'product lookup receipt observation');
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce product lookup receipt read saturated its bounded owner scope'
                );
            }
            foreach ($rows as $row) {
                $productId = $this->strict_positive_db_uint(
                    $row['product_id'] ?? null,
                    'product lookup owner'
                );
                if (!in_array($productId, $chunk, true)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product lookup receipt read returned an out-of-scope owner'
                    );
                }
                if (isset($rowsById[$productId])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product lookup receipt read returned multiple rows for product $productId"
                    );
                }
                $normalized = [];
                $rowBytes = 0;
                if (array_keys($row) !== $columns) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product lookup receipt read returned an unexpected row shape for product $productId"
                    );
                }
                foreach ($row as $column => $value) {
                    if (!is_string($column) || $column === '' || (!is_string($value) && $value !== null)) {
                        throw new \RuntimeException(
                            "duo: WooCommerce product lookup receipt read returned non-scalar state for product $productId"
                        );
                    }
                    if ($value !== null) {
                        $bytes = strlen($value);
                        if ($bytes > self::MAX_LOOKUP_SCALAR_BYTES) {
                            throw new \RuntimeException(
                                "duo: WooCommerce product lookup receipt read returned an oversized scalar for product $productId"
                            );
                        }
                        $rowBytes += $bytes;
                    }
                    $normalized[$column] = $value;
                }
                if ($rowBytes > self::MAX_LOOKUP_ROW_BYTES) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product lookup receipt read returned an oversized row for product $productId"
                    );
                }
                ksort($normalized, SORT_STRING);
                $rowsById[$productId] = $normalized;
            }
        }

        $fingerprint = hash_init('sha256');
        foreach ($ids as $id) {
            $this->fingerprint_part($fingerprint, (string) $id);
            $row = $rowsById[$id] ?? null;
            $this->fingerprint_part($fingerprint, $row === null ? 'absent' : 'present');
            if ($row === null) {
                continue;
            }
            foreach ($row as $column => $value) {
                $this->fingerprint_part($fingerprint, $column);
                $this->fingerprint_part($fingerprint, $value === null ? 'null' : 'string');
                if ($value !== null) {
                    $this->fingerprint_part($fingerprint, $value);
                }
            }
        }
        return [
            'scoped_products' => count($ids),
            'meta_lookup_rows' => count($rowsById),
            'lookup_scope_sha256' => hash_final($fingerprint),
        ];
    }

    /**
     * Bind every exact attribute-lookup row owned by the bounded product/root
     * set. A variation id expands to its parent through one checked posts-table
     * read so a root regeneration cannot corrupt an unobserved sibling and
     * still retire recovery. Rows contain no merchant prose or credentials,
     * but receipts retain only their SHA-256 fingerprint and cardinality.
     *
     * @param list<int> $ids
     * @return array{attribute_lookup_products:int,attribute_lookup_rows:int,attribute_lookup_scope_sha256:string}
     */
    private function observe_attribute_lookup_state(array $ids): array {
        global $wpdb;
        $scope = $this->expand_attribute_scope_ids($ids);
        $table = $this->prefixed_table(self::ATTRIBUTE_LOOKUP);
        $rows = [];
        $rowCount = 0;
        foreach (array_chunk($scope, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $countValue = \Duo\ProviderSdk::checked_get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` "
                . "WHERE product_or_parent_id IN ($placeholders)",
                ...$chunk
            ), 'product attribute lookup receipt cardinality witness');
            $chunkCount = $this->strict_bounded_db_count(
                $countValue,
                self::MAX_ATTRIBUTE_LOOKUP_ROWS,
                'product attribute lookup receipt cardinality'
            );
            if ($rowCount + $chunkCount > self::MAX_ATTRIBUTE_LOOKUP_ROWS) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup receipt exceeds its aggregate row bound'
                );
            }
            if ($chunkCount === 0) {
                continue;
            }
            $limit = $chunkCount + 1;
            $payloadQuery = $wpdb->prepare(
                "SELECT product_id, product_or_parent_id, taxonomy, term_id, is_variation_attribute, in_stock "
                . "FROM `$table` "
                . "WHERE product_or_parent_id IN ($placeholders) "
                . 'ORDER BY product_or_parent_id ASC, product_id ASC, taxonomy ASC, term_id ASC, '
                . "is_variation_attribute ASC, in_stock ASC LIMIT $limit",
                ...$chunk
            );
            $chunkRows = \Duo\ProviderSdk::checked_get_results(
                $payloadQuery,
                'product attribute lookup receipt observation'
            );
            if (count($chunkRows) !== $chunkCount) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup receipt changed after its cardinality witness'
                );
            }
            $chunkState = [];
            foreach ($chunkRows as $row) {
                $normalized = $this->normalize_attribute_lookup_row($row, $chunk);
                $key = implode("\0", $normalized);
                if (isset($chunkState[$key]) || isset($rows[$key])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product attribute lookup receipt read returned duplicate scoped rows'
                    );
                }
                $chunkState[$key] = $normalized;
            }
            $confirmedRows = \Duo\ProviderSdk::checked_get_results(
                $payloadQuery,
                'product attribute lookup receipt stability verification'
            );
            if (count($confirmedRows) !== $chunkCount) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup receipt changed during bounded readback'
                );
            }
            $confirmedState = [];
            foreach ($confirmedRows as $row) {
                $normalized = $this->normalize_attribute_lookup_row($row, $chunk);
                $key = implode("\0", $normalized);
                if (isset($confirmedState[$key])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product attribute lookup receipt read returned duplicate scoped rows'
                    );
                }
                $confirmedState[$key] = $normalized;
            }
            ksort($chunkState, SORT_STRING);
            ksort($confirmedState, SORT_STRING);
            if ($confirmedState !== $chunkState) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup receipt changed during bounded readback'
                );
            }
            $rows += $confirmedState;
            $rowCount += $chunkCount;
        }
        ksort($rows, SORT_STRING);

        $fingerprint = hash_init('sha256');
        foreach ($scope as $id) {
            $this->fingerprint_part($fingerprint, 'scope:' . $id);
        }
        foreach ($rows as $row) {
            foreach ($row as $value) {
                $this->fingerprint_part($fingerprint, $value);
            }
        }
        return [
            'attribute_lookup_products' => count($scope),
            'attribute_lookup_rows' => count($rows),
            'attribute_lookup_scope_sha256' => hash_final($fingerprint),
        ];
    }

    /** @param list<int> $ids @return list<int> */
    private function expand_attribute_scope_ids(array $ids): array {
        global $wpdb;
        $scope = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $scope[$id] = $id;
            }
        }
        $this->assert_scoped_product_count(array_values($scope), 'product attribute lookup owner scope');
        foreach (array_chunk(array_values($scope), 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = count($chunk) + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT ID, post_parent FROM {$wpdb->posts} WHERE ID IN ($placeholders) "
                . "ORDER BY ID ASC LIMIT $limit",
                ...$chunk
            ), 'product attribute lookup owner expansion');
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup owner expansion saturated its bounded read'
                );
            }
            foreach ($rows as $row) {
                $id = $this->strict_positive_db_uint($row['ID'] ?? null, 'attribute lookup post ID');
                $parent = $this->strict_nonnegative_db_uint(
                    $row['post_parent'] ?? null,
                    'attribute lookup parent ID'
                );
                if (!isset($scope[$id])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product attribute lookup owner expansion returned an out-of-scope row'
                    );
                }
                if ($parent > 0) {
                    $scope[$parent] = $parent;
                    if (count($scope) > self::MAX_SCOPED_PRODUCTS) {
                        throw new \RuntimeException(
                            'duo: WooCommerce product attribute lookup owner expansion exceeds its product bound'
                        );
                    }
                }
            }
        }
        $scope = array_values($scope);
        sort($scope, SORT_NUMERIC);
        return $scope;
    }

    /** @param array<string,mixed> $row @param list<int> $scope @return list<string> */
    private function normalize_attribute_lookup_row(array $row, array $scope): array {
        $productId = $this->strict_attribute_lookup_uint($row['product_id'] ?? null, 'product_id');
        $rootId = $this->strict_attribute_lookup_uint($row['product_or_parent_id'] ?? null, 'product_or_parent_id');
        $termId = $this->strict_attribute_lookup_uint($row['term_id'] ?? null, 'term_id');
        if (!in_array($rootId, $scope, true)) {
            throw new \RuntimeException(
                'duo: WooCommerce product attribute lookup receipt read returned an out-of-scope owner'
            );
        }
        $taxonomy = $row['taxonomy'] ?? null;
        if (!is_string($taxonomy) || $taxonomy === '' || strlen($taxonomy) > 32) {
            throw new \RuntimeException(
                'duo: WooCommerce product attribute lookup receipt read returned an invalid taxonomy identity'
            );
        }
        $variation = $this->strict_attribute_lookup_flag($row['is_variation_attribute'] ?? null, 'is_variation_attribute');
        $stock = $this->strict_attribute_lookup_flag($row['in_stock'] ?? null, 'in_stock');
        return [
            (string) $productId,
            (string) $rootId,
            $taxonomy,
            (string) $termId,
            (string) $variation,
            (string) $stock,
        ];
    }

    private function strict_attribute_lookup_uint(mixed $value, string $column): int {
        try {
            return $this->strict_positive_db_uint($value, "attribute lookup $column");
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                "duo: WooCommerce product attribute lookup receipt read returned invalid $column state",
                0,
                $failure
            );
        }
    }

    private function strict_attribute_lookup_flag(mixed $value, string $column): int {
        if ((!is_int($value) && !is_string($value)) || !in_array((string) $value, ['0', '1'], true)) {
            throw new \RuntimeException(
                "duo: WooCommerce product attribute lookup receipt read returned invalid $column state"
            );
        }
        return (int) $value;
    }

    /**
     * Bind the exact active cardinality and next state of both Woo scheduled-
     * sale hooks for every observed product. Woo 11.0.0/11.0.1's public next
     * helper returns only one action, so the bounded two-row status queries
     * are what distinguish the valid 0/1 states from duplicate work without
     * exposing action ids in a receipt.
     *
     * @param list<int> $ids
     * @return array{sale_schedule_products:int,sale_schedule_actions:int,sale_schedule_scope_sha256:string}
     */
    private function observe_sale_schedule_state(array $ids): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        $this->assert_scoped_product_count($ids, 'sale schedule observation');
        $fingerprint = hash_init('sha256');
        $actions = 0;
        foreach ($ids as $id) {
            $this->fingerprint_part($fingerprint, (string) $id);
            foreach (['wc_product_start_scheduled_sale', 'wc_product_end_scheduled_sale'] as $hook) {
                $this->fingerprint_part($fingerprint, $hook);
                $state = $this->sale_action_state($id, $hook);
                $actions += $state['count'];
                $this->fingerprint_part($fingerprint, (string) $state['count']);
                $this->fingerprint_part($fingerprint, $state['next']);
            }
        }
        return [
            'sale_schedule_products' => count($ids),
            'sale_schedule_actions' => $actions,
            'sale_schedule_scope_sha256' => hash_final($fingerprint),
        ];
    }

    /** @return array{count:int,next:string} */
    private function sale_action_state(int $id, string $hook): array {
        $args = ['product_id' => $id];
        $count = 0;
        foreach (['pending', 'in-progress'] as $status) {
            $ids = \as_get_scheduled_actions([
                'hook' => $hook,
                'args' => $args,
                'group' => 'woocommerce-sales',
                'status' => $status,
                'per_page' => 2,
                'orderby' => 'none',
            ], 'ids');
            if (!is_array($ids) || count($ids) > 2) {
                throw new \RuntimeException(
                    "duo: WooCommerce sale schedule cardinality read failed for product $id ($hook)"
                );
            }
            foreach ($ids as $actionId) {
                if ((!is_int($actionId) && !is_string($actionId))
                    || (int) $actionId <= 0 || (string) (int) $actionId !== (string) $actionId) {
                    throw new \RuntimeException(
                        "duo: WooCommerce sale schedule cardinality read returned an unusable identity for product $id ($hook)"
                    );
                }
            }
            $count += count($ids);
        }

        $next = \as_next_scheduled_action($hook, $args, 'woocommerce-sales');
        if ($next === false) {
            $nextState = 'absent';
        } elseif ($next === true) {
            $nextState = 'in-progress';
        } elseif (is_int($next) && $next > 0) {
            $nextState = 'timestamp:' . $next;
        } else {
            throw new \RuntimeException(
                "duo: WooCommerce sale schedule receipt returned an unusable next state for product $id ($hook)"
            );
        }
        if (($count === 0) !== ($next === false)) {
            throw new \RuntimeException(
                "duo: WooCommerce sale schedule APIs disagreed for product $id ($hook)"
            );
        }
        return ['count' => $count, 'next' => $nextState];
    }

    /** @param resource|\HashContext $fingerprint */
    private function fingerprint_part($fingerprint, string $part): void {
        hash_update($fingerprint, strlen($part) . ':' . $part . ';');
    }

    /**
     * Bind the exact mixed product/POS visibility projection for the finite
     * product-root scope selected by this apply. Raw relationship identities
     * and public WC semantics both enter the digest; no product title, term
     * prose, SKU, or other merchant value enters the receipt.
     *
     * @param list<int> $ids
     * @return array{visibility_products:int,visibility_relationships:int,visibility_intent_sha256:string,visibility_scope_sha256:string}
     */
    private function visibility_receipt(array $ids, bool $verifyNative): array {
        $snapshot = $this->visibility_snapshot($ids, $verifyNative);
        if ($verifyNative) {
            $terms = $this->visibility_term_map($this->visibility_requires_pos($snapshot, null));
            $this->assert_visibility_projection($snapshot, null, $terms);
        }
        return [
            'visibility_products' => count($snapshot['posts']),
            'visibility_relationships' => (int) $snapshot['relationship_count'],
            'visibility_intent_sha256' => (string) $snapshot['intent_sha256'],
            'visibility_scope_sha256' => (string) $snapshot['scope_sha256'],
        ];
    }

    /** @param array<int,array<string,mixed>> $deletionContext @return list<int> */
    private function visibility_deletion_ids(array $deletionContext): array {
        $ids = [];
        foreach ($deletionContext as $context) {
            if (($context['kind'] ?? 'delete') !== 'delete') {
                continue;
            }
            foreach (array_merge(
                [(int) ($context['id'] ?? 0)],
                array_map('intval', (array) ($context['child_ids'] ?? []))
            ) as $id) {
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /**
     * @param list<int> $ids
     * @param list<int> $excludedIds
     * @return array{requested_ids:list<int>,excluded_ids:list<int>,posts:array<int,array{post_type:string,parent_id:int}>,relationships:array<int,array<string,list<array{slug:string,term_id:int,term_taxonomy_id:int,term_order:int}>>>,native:array<int,array<string,mixed>>,merchant:array<int,array{featured:bool,catalog_visibility:string,pos_hidden:bool}>,relationship_count:int,intent_sha256:string,scope_sha256:string}
     */
    private function visibility_snapshot(array $ids, bool $freshNative, array $excludedIds = []): array {
        $requested = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($requested, SORT_NUMERIC);
        $excluded = array_values(array_unique(array_filter(
            array_map('intval', $excludedIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($excluded, SORT_NUMERIC);
        if (array_intersect($requested, $excluded) !== []) {
            throw new \RuntimeException(
                'duo: WooCommerce product visibility scope declares a live product as deleted'
            );
        }
        if (count($requested) > self::MAX_VISIBILITY_PRODUCTS) {
            throw new \RuntimeException(
                'duo: WooCommerce product visibility scope exceeds the bounded product limit'
            );
        }
        $posts = $this->visibility_post_scope($requested, $excluded);
        $relationships = $this->visibility_relationship_rows(array_keys($posts));
        $native = [];
        $merchant = [];
        foreach ($posts as $id => $post) {
            $id = (int) $id;
            if ($freshNative) {
                $this->invalidate_product_caches($id);
            }
            $product = $this->load_product($id);
            if (!is_object($product)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product visibility could not load scoped product $id"
                );
            }
            foreach (['get_type', 'get_parent_id', 'get_stock_status', 'get_downloadable'] as $method) {
                if (!is_callable([$product, $method])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $id lacks public $method() for visibility projection"
                    );
                }
            }
            $type = (string) $product->get_type();
            $parentId = (int) $product->get_parent_id('edit');
            $stockStatus = (string) $product->get_stock_status('edit');
            $downloadable = $product->get_downloadable('edit');
            if ($type === '' || !in_array($stockStatus, ['instock', 'outofstock', 'onbackorder'], true)
                || !is_bool($downloadable)
                || ($post['post_type'] === 'product_variation') !== ($type === 'variation')
                || $parentId !== $post['parent_id']) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id returned incompatible native visibility inputs"
                );
            }
            $native[$id] = [
                'type' => $type,
                'parent_id' => $parentId,
                'stock_status' => $stockStatus,
                'downloadable' => $downloadable,
            ];
            if ($post['post_type'] !== 'product') {
                continue;
            }
            foreach (['get_featured', 'get_catalog_visibility', 'get_average_rating'] as $method) {
                if (!is_callable([$product, $method])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $id lacks public $method() for visibility projection"
                    );
                }
            }
            $featured = $product->get_featured('edit');
            $catalog = (string) $product->get_catalog_visibility('edit');
            $average = $product->get_average_rating('edit');
            if (!is_bool($featured)
                || !in_array($catalog, ['visible', 'catalog', 'search', 'hidden'], true)
                || (!is_int($average) && !is_float($average) && !is_string($average))
                || !is_numeric((string) $average)
                || !is_finite((float) $average)
                || (float) $average < 0.0 || (float) $average > 5.0) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id returned invalid native visibility semantics"
                );
            }
            $native[$id]['featured'] = $featured;
            $native[$id]['catalog_visibility'] = $catalog;
            $native[$id]['average_rating'] = (string) $average;

            $visibilitySlugs = $this->visibility_slugs($relationships[$id]['product_visibility'] ?? []);
            $merchant[$id] = [
                'featured' => in_array('featured', $visibilitySlugs, true),
                'catalog_visibility' => $this->catalog_visibility_from_terms($visibilitySlugs),
                'pos_hidden' => in_array(
                    'pos-hidden',
                    $this->visibility_slugs($relationships[$id]['pos_product_visibility'] ?? []),
                    true
                ),
            ];
        }
        ksort($native, SORT_NUMERIC);
        ksort($merchant, SORT_NUMERIC);

        // Only merchant-controlled intent and the finite ownership/type scope
        // enter this race fingerprint. Parent stock and rating projections may
        // legitimately change when the same provider synchronizes a variable
        // product; binding those here would reject the provider's own native
        // derivation rather than a competing authored write.
        $intentFingerprint = hash_init('sha256');
        foreach ($requested as $id) {
            $this->fingerprint_part($intentFingerprint, 'requested:' . $id);
        }
        foreach ($excluded as $id) {
            $this->fingerprint_part($intentFingerprint, 'excluded:' . $id);
        }
        foreach ($posts as $id => $post) {
            $this->fingerprint_part($intentFingerprint, 'post:' . $id);
            $this->fingerprint_part($intentFingerprint, $post['post_type']);
            $this->fingerprint_part($intentFingerprint, (string) $post['parent_id']);
            if ($post['post_type'] !== 'product') {
                continue;
            }
            $this->fingerprint_part($intentFingerprint, 'type:' . (string) $native[$id]['type']);
            $this->fingerprint_part(
                $intentFingerprint,
                'downloadable:' . ($native[$id]['downloadable'] ? 'true' : 'false')
            );
            foreach ($merchant[$id] as $key => $value) {
                if ($key === 'pos_hidden'
                    && (!in_array($native[$id]['type'], ['simple', 'variable'], true)
                        || $native[$id]['downloadable'] !== false)) {
                    $value = false;
                }
                $this->fingerprint_part(
                    $intentFingerprint,
                    'merchant:' . $key . ':'
                    . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
                );
            }
        }

        $fingerprint = hash_init('sha256');
        foreach ($requested as $id) {
            $this->fingerprint_part($fingerprint, 'requested:' . $id);
        }
        foreach ($excluded as $id) {
            $this->fingerprint_part($fingerprint, 'excluded:' . $id);
        }
        foreach ($posts as $id => $post) {
            $this->fingerprint_part($fingerprint, 'post:' . $id);
            $this->fingerprint_part($fingerprint, $post['post_type']);
            $this->fingerprint_part($fingerprint, (string) $post['parent_id']);
            foreach (['product_type', 'product_visibility', 'pos_product_visibility'] as $taxonomy) {
                $this->fingerprint_part($fingerprint, $taxonomy);
                foreach ($relationships[$id][$taxonomy] ?? [] as $row) {
                    foreach ($row as $key => $value) {
                        $this->fingerprint_part($fingerprint, $key . ':' . (string) $value);
                    }
                }
            }
            foreach ($native[$id] as $key => $value) {
                $this->fingerprint_part(
                    $fingerprint,
                    'native:' . $key . ':' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
                );
            }
        }
        return [
            'requested_ids' => $requested,
            'excluded_ids' => $excluded,
            'posts' => $posts,
            'relationships' => $relationships,
            'native' => $native,
            'merchant' => $merchant,
            'relationship_count' => array_sum(array_map(
                static fn(array $byTaxonomy): int => array_sum(array_map('count', $byTaxonomy)),
                $relationships
            )),
            'intent_sha256' => hash_final($intentFingerprint),
            'scope_sha256' => hash_final($fingerprint),
        ];
    }

    /** @param list<int> $requested @param list<int> $excluded @return array<int,array{post_type:string,parent_id:int}> */
    private function visibility_post_scope(array $requested, array $excluded): array {
        global $wpdb;
        if ($requested === []) {
            return [];
        }
        $posts = $this->visibility_read_posts($requested);
        foreach ($requested as $id) {
            if (!isset($posts[$id])) {
                throw new \RuntimeException(
                    "duo: WooCommerce visibility scope no longer contains live product $id"
                );
            }
        }
        $parentIds = [];
        foreach ($posts as $post) {
            if ($post['post_type'] === 'product_variation' && $post['parent_id'] > 0) {
                $parentIds[$post['parent_id']] = $post['parent_id'];
            }
        }
        $missingParents = array_values(array_diff(array_values($parentIds), array_keys($posts)));
        if ($missingParents !== []) {
            $posts += $this->visibility_read_posts($missingParents);
        }
        foreach ($parentIds as $parentId) {
            if (($posts[$parentId]['post_type'] ?? null) !== 'product') {
                throw new \RuntimeException(
                    'duo: WooCommerce product visibility scope contains an orphaned variation owner'
                );
            }
        }
        $roots = [];
        foreach ($posts as $id => $post) {
            if ($post['post_type'] === 'product') {
                $roots[(int) $id] = (int) $id;
            }
        }
        foreach (array_chunk(array_values($roots), 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = self::MAX_VISIBILITY_PRODUCTS + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT ID, post_parent, post_type FROM {$wpdb->posts} "
                . "WHERE post_parent IN ($placeholders) AND post_type = 'product_variation' "
                . "ORDER BY ID ASC LIMIT $limit",
                ...$chunk
            ), 'WooCommerce product visibility child-scope discovery');
            // Refuse a saturated window before tombstone filtering. Otherwise
            // MAX excluded children can consume the bounded query while a
            // still-live child sorts just beyond it and silently disappears
            // from the projection this receipt certifies.
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce product visibility child discovery saturated its bounded read'
                );
            }
            $excludedSet = array_fill_keys($excluded, true);
            foreach ($rows as $row) {
                $id = $this->visibility_uint($row['ID'] ?? null, 'post ID');
                $parentId = $this->visibility_uint($row['post_parent'] ?? null, 'parent ID');
                if (isset($excludedSet[$id])) {
                    continue;
                }
                if (($row['post_type'] ?? null) !== 'product_variation'
                    || !in_array($parentId, $chunk, true)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product visibility child discovery returned malformed or out-of-scope state'
                    );
                }
                $posts[$id] = ['post_type' => 'product_variation', 'parent_id' => $parentId];
                if (count($posts) > self::MAX_VISIBILITY_PRODUCTS) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product visibility root expands beyond the bounded product limit'
                    );
                }
            }
        }
        ksort($posts, SORT_NUMERIC);
        return $posts;
    }

    /** @param list<int> $ids @return array<int,array{post_type:string,parent_id:int}> */
    private function visibility_read_posts(array $ids): array {
        global $wpdb;
        $posts = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = count($chunk) + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT ID, post_parent, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders) "
                . "ORDER BY ID ASC LIMIT $limit",
                ...$chunk
            ), 'WooCommerce product visibility owner discovery');
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce product visibility owner discovery saturated its bounded read'
                );
            }
            foreach ($rows as $row) {
                $id = $this->visibility_uint($row['ID'] ?? null, 'post ID');
                $parent = $this->visibility_uint($row['post_parent'] ?? null, 'parent ID', true);
                $postType = $row['post_type'] ?? null;
                if (!in_array($id, $chunk, true)
                    || !in_array($postType, ['product', 'product_variation'], true)
                    || ($postType === 'product_variation' && $parent === 0)
                    || isset($posts[$id])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product visibility owner discovery returned malformed or duplicate state'
                    );
                }
                $posts[$id] = ['post_type' => $postType, 'parent_id' => $parent];
            }
        }
        return $posts;
    }

    /**
     * @param list<int> $ids
     * @return array<int,array<string,list<array{slug:string,term_id:int,term_taxonomy_id:int,term_order:int}>>>
     */
    private function visibility_relationship_rows(array $ids): array {
        global $wpdb;
        $out = [];
        foreach ($ids as $id) {
            $out[(int) $id] = [
                'product_type' => [],
                'product_visibility' => [],
                'pos_product_visibility' => [],
            ];
        }
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = count($chunk) * 11 + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT tr.object_id, tr.term_taxonomy_id, tr.term_order, tt.term_id, tt.taxonomy, t.slug, t.name "
                . "FROM {$wpdb->term_relationships} tr "
                . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
                . "INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id "
                . "WHERE tr.object_id IN ($placeholders) "
                . "AND tt.taxonomy IN ('product_type', 'product_visibility', 'pos_product_visibility') "
                . 'ORDER BY tr.object_id ASC, tt.taxonomy ASC, t.slug ASC, tr.term_taxonomy_id ASC '
                . "LIMIT $limit",
                ...$chunk
            ), 'WooCommerce product visibility relationship observation');
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce product visibility relationship scope exceeds the exact core cardinality'
                );
            }
            foreach ($rows as $row) {
                $objectId = $this->visibility_uint($row['object_id'] ?? null, 'relationship owner');
                $termId = $this->visibility_uint($row['term_id'] ?? null, 'term ID');
                $ttId = $this->visibility_uint($row['term_taxonomy_id'] ?? null, 'term-taxonomy ID');
                $order = $this->visibility_uint($row['term_order'] ?? null, 'term order', true);
                $taxonomy = $row['taxonomy'] ?? null;
                $slug = $row['slug'] ?? null;
                $name = $row['name'] ?? null;
                $allowed = $taxonomy === 'product_type'
                    ? self::CORE_PRODUCT_TYPES
                    : ($taxonomy === 'product_visibility'
                        ? self::PRODUCT_VISIBILITY_TERMS
                        : ($taxonomy === 'pos_product_visibility' ? ['pos-hidden'] : []));
                if (!in_array($objectId, $chunk, true) || !is_string($slug) || !is_string($name)
                    || !in_array($slug, $allowed, true) || $name !== $slug || $order !== 0) {
                    throw new \RuntimeException(
                        'duo: WooCommerce product visibility relationship observation returned malformed or unsupported state'
                    );
                }
                foreach ($out[$objectId][$taxonomy] as $existing) {
                    if ($existing['slug'] === $slug || $existing['term_taxonomy_id'] === $ttId) {
                        throw new \RuntimeException(
                            'duo: WooCommerce product visibility relationship observation returned duplicate state'
                        );
                    }
                }
                $out[$objectId][$taxonomy][] = [
                    'slug' => $slug,
                    'term_id' => $termId,
                    'term_taxonomy_id' => $ttId,
                    'term_order' => $order,
                ];
            }
        }
        foreach ($out as &$byTaxonomy) {
            foreach ($byTaxonomy as &$rows) {
                usort($rows, static fn(array $left, array $right): int => [
                    $left['slug'], $left['term_taxonomy_id'],
                ] <=> [
                    $right['slug'], $right['term_taxonomy_id'],
                ]);
            }
            unset($rows);
        }
        unset($byTaxonomy);
        return $out;
    }

    /** @param list<array{slug:string}> $rows @return list<string> */
    private function visibility_slugs(array $rows): array {
        $slugs = array_map(static fn(array $row): string => $row['slug'], $rows);
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    /** @param list<string> $terms */
    private function catalog_visibility_from_terms(array $terms): string {
        $excludeSearch = in_array('exclude-from-search', $terms, true);
        $excludeCatalog = in_array('exclude-from-catalog', $terms, true);
        if ($excludeSearch && $excludeCatalog) {
            return 'hidden';
        }
        if ($excludeSearch) {
            return 'catalog';
        }
        if ($excludeCatalog) {
            return 'search';
        }
        return 'visible';
    }

    /**
     * @param array<string,mixed> $intent
     * @return array<int,array<string,list<string>>>
     */
    private function expected_visibility_projection(array $intent): array {
        $expected = [];
        $rootPos = [];
        foreach ($intent['posts'] as $id => $post) {
            $id = (int) $id;
            $native = $intent['native'][$id] ?? null;
            if (!is_array($native) || $post['post_type'] !== 'product') {
                continue;
            }
            $merchant = $intent['merchant'][$id] ?? null;
            if (!is_array($merchant)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id has no captured merchant visibility intent"
                );
            }
            if (($native['featured'] ?? null) !== $merchant['featured']
                || ($native['catalog_visibility'] ?? null) !== $merchant['catalog_visibility']) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id visibility changed after intent capture; recovery_required"
                );
            }
            $terms = [];
            if ($merchant['featured']) {
                $terms[] = 'featured';
            }
            if (($native['stock_status'] ?? null) === 'outofstock') {
                $terms[] = 'outofstock';
            }
            $numberUtil = '\\Automattic\\WooCommerce\\Utilities\\NumberUtil';
            if (!class_exists($numberUtil) || !is_callable([$numberUtil, 'round'])) {
                throw new \RuntimeException(
                    'duo: WooCommerce native NumberUtil::round() is unavailable for rating visibility projection'
                );
            }
            $rating = min(5, (int) $numberUtil::round((float) $native['average_rating'], 0));
            if ($rating > 0) {
                $terms[] = 'rated-' . $rating;
            }
            if ($merchant['catalog_visibility'] === 'hidden') {
                $terms[] = 'exclude-from-search';
                $terms[] = 'exclude-from-catalog';
            } elseif ($merchant['catalog_visibility'] === 'catalog') {
                $terms[] = 'exclude-from-search';
            } elseif ($merchant['catalog_visibility'] === 'search') {
                $terms[] = 'exclude-from-catalog';
            }
            sort($terms, SORT_STRING);
            $supported = in_array($native['type'], ['simple', 'variable'], true)
                && $native['downloadable'] === false;
            $rootPos[$id] = $supported && $merchant['pos_hidden'];
            $expected[$id] = [
                'product_visibility' => $terms,
                'pos_product_visibility' => $rootPos[$id] ? ['pos-hidden'] : [],
            ];
        }
        foreach ($intent['posts'] as $id => $post) {
            $id = (int) $id;
            if ($post['post_type'] !== 'product_variation') {
                continue;
            }
            $native = $intent['native'][$id] ?? [];
            $expected[$id] = [
                'product_visibility' => ($native['stock_status'] ?? null) === 'outofstock'
                    ? ['outofstock']
                    : [],
                'pos_product_visibility' => !empty($rootPos[(int) $post['parent_id']])
                    ? ['pos-hidden']
                    : [],
            ];
        }
        ksort($expected, SORT_NUMERIC);
        return $expected;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @param array<int,array<string,list<string>>>|null $expected
     * @param array<string,array<string,array{term_id:int,term_taxonomy_id:int}>>|null $terms
     */
    private function assert_visibility_projection(array $snapshot, ?array $expected, ?array $terms = null): void {
        if ($expected === null) {
            $expected = $this->expected_visibility_projection($snapshot);
        }
        $terms ??= $this->visibility_term_map($this->visibility_requires_pos($snapshot, $expected));
        $this->assert_product_type_projection($snapshot, $terms);
        if (array_keys($expected) !== array_keys($snapshot['posts'])) {
            throw new \RuntimeException(
                'duo: WooCommerce product visibility scope changed during projection; recovery_required'
            );
        }
        foreach ($expected as $id => $taxonomies) {
            foreach (['product_visibility', 'pos_product_visibility'] as $taxonomy) {
                $wanted = $taxonomies[$taxonomy] ?? [];
                sort($wanted, SORT_STRING);
                if (!$this->visibility_relationship_matches(
                    $snapshot['relationships'][$id][$taxonomy] ?? [],
                    $wanted,
                    $taxonomy,
                    $terms
                )) {
                    throw new \RuntimeException(
                        "duo: WooCommerce $taxonomy projection disagrees for product $id; recovery_required"
                    );
                }
            }
            if (($snapshot['posts'][$id]['post_type'] ?? null) === 'product') {
                $merchant = $snapshot['merchant'][$id] ?? [];
                $native = $snapshot['native'][$id] ?? [];
                $wantedFeatured = in_array('featured', $taxonomies['product_visibility'], true);
                $wantedCatalog = $this->catalog_visibility_from_terms($taxonomies['product_visibility']);
                if (($merchant['featured'] ?? null) !== $wantedFeatured
                    || ($merchant['catalog_visibility'] ?? null) !== $wantedCatalog
                    || ($native['featured'] ?? null) !== $wantedFeatured
                    || ($native['catalog_visibility'] ?? null) !== $wantedCatalog) {
                    throw new \RuntimeException(
                        "duo: WooCommerce native featured/catalog visibility readback disagrees for product $id; recovery_required"
                    );
                }
            }
        }
    }

    /**
     * Product type is authored identity, not a class-name guess. Every root
     * must point at one exact admitted core term and WC's public object must
     * resolve the same type. Variations carry no product_type relationship and
     * their parent must be the exact variable root Woo's inheritance code
     * expects; a generic post parent alone cannot prove that semantic edge.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,array<string,array{term_id:int,term_taxonomy_id:int}>> $terms
     */
    private function assert_product_type_projection(array $snapshot, array $terms): void {
        foreach ((array) ($snapshot['posts'] ?? []) as $id => $post) {
            $id = (int) $id;
            $rows = (array) ($snapshot['relationships'][$id]['product_type'] ?? []);
            $native = (array) ($snapshot['native'][$id] ?? []);
            if (($post['post_type'] ?? null) === 'product') {
                if (count($rows) !== 1) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $id must have exactly one native product_type relationship"
                    );
                }
                $slug = (string) ($rows[0]['slug'] ?? '');
                if (!in_array($slug, self::CORE_PRODUCT_TYPES, true)
                    || ($native['type'] ?? null) !== $slug
                    || !$this->visibility_relationship_matches($rows, [$slug], 'product_type', $terms)) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $id has inconsistent native product_type identity"
                    );
                }
                continue;
            }
            if ($rows !== []) {
                throw new \RuntimeException(
                    "duo: WooCommerce variation $id carries an impossible product_type relationship"
                );
            }
            $parentId = (int) ($post['parent_id'] ?? 0);
            $parentRows = (array) ($snapshot['relationships'][$parentId]['product_type'] ?? []);
            if (($snapshot['posts'][$parentId]['post_type'] ?? null) !== 'product'
                || ($snapshot['native'][$parentId]['type'] ?? null) !== 'variable'
                || count($parentRows) !== 1
                || ($parentRows[0]['slug'] ?? null) !== 'variable'
                || !$this->visibility_relationship_matches(
                    $parentRows,
                    ['variable'],
                    'product_type',
                    $terms
                )) {
                throw new \RuntimeException(
                    "duo: WooCommerce variation $id requires one exact variable product_type parent"
                );
            }
        }
    }

    /**
     * @param list<array{slug:string,term_id:int,term_taxonomy_id:int,term_order:int}> $rows
     * @param list<string> $slugs
     * @param array<string,array<string,array{term_id:int,term_taxonomy_id:int}>> $terms
     */
    private function visibility_relationship_matches(
        array $rows,
        array $slugs,
        string $taxonomy,
        array $terms
    ): bool {
        if ($this->visibility_slugs($rows) !== $slugs || count($rows) !== count($slugs)) {
            return false;
        }
        foreach ($rows as $row) {
            $term = $terms[$taxonomy][$row['slug']] ?? null;
            if (!is_array($term)
                || $row['term_id'] !== $term['term_id']
                || $row['term_taxonomy_id'] !== $term['term_taxonomy_id']) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $snapshot @param array<int,array<string,list<string>>>|null $expected */
    private function visibility_requires_pos(array $snapshot, ?array $expected): bool {
        foreach ((array) ($snapshot['relationships'] ?? []) as $taxonomies) {
            if (($taxonomies['pos_product_visibility'] ?? []) !== []) {
                return true;
            }
        }
        foreach ((array) $expected as $taxonomies) {
            if (($taxonomies['pos_product_visibility'] ?? []) !== []) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $intent */
    private function reconcile_visibility(array $intent, ?callable $heartbeat = null): void {
        $current = $this->visibility_snapshot(
            (array) ($intent['requested_ids'] ?? []),
            true,
            (array) ($intent['excluded_ids'] ?? [])
        );
        if (!hash_equals(
            (string) ($intent['intent_sha256'] ?? ''),
            (string) $current['intent_sha256']
        )) {
            throw new \RuntimeException(
                'duo: WooCommerce product visibility changed during provider execution; recovery_required'
            );
        }
        $projection = $current;
        $projection['merchant'] = $intent['merchant'] ?? [];
        $expected = $this->expected_visibility_projection($projection);
        $terms = $this->visibility_term_map(
            $this->visibility_requires_pos($current, $expected)
        );
        foreach ($expected as $id => $taxonomies) {
            foreach ($taxonomies as $taxonomy => $slugs) {
                sort($slugs, SORT_STRING);
                if ($this->visibility_relationship_matches(
                    $current['relationships'][$id][$taxonomy] ?? [],
                    $slugs,
                    $taxonomy,
                    $terms
                )) {
                    continue;
                }
                $this->reconcile_visibility_taxonomy(
                    (int) $id,
                    $taxonomy,
                    $current['posts'][$id] ?? [],
                    $current['native'][$id] ?? [],
                    $current['relationships'][$id][$taxonomy] ?? [],
                    $slugs,
                    $terms,
                    $heartbeat
                );
            }
        }
        $after = $this->visibility_snapshot(
            (array) $intent['requested_ids'],
            true,
            (array) ($intent['excluded_ids'] ?? [])
        );
        if (!hash_equals(
            (string) ($intent['intent_sha256'] ?? ''),
            (string) $after['intent_sha256']
        )) {
            throw new \RuntimeException(
                'duo: WooCommerce product visibility intent changed during native repair; recovery_required'
            );
        }
        // Recompute the projection and native term map from the final fresh
        // snapshot. Reusing $expected would let a stock, rating, subtype,
        // downloadable, or parent change after the current snapshot bless the
        // exact stale terms this invocation was about to write.
        $this->assert_visibility_projection($after, null, null);
    }

    /**
     * Reconcile only the relationship subset this provider owns. Root
     * featured/catalog and supported-root POS terms are authored and already
     * materialized by Apply; replacing the whole taxonomy from an earlier
     * snapshot could erase a merchant write that lands immediately before the
     * native call. Derived root stock/rating and every variation projection
     * are instead added/removed by exact term id, so an interleaved authored
     * edit survives and the final intent fingerprint makes the run loud.
     *
     * @param array{post_type?:string,parent_id?:int} $post
     * @param array<string,mixed> $native
     * @param list<array{slug:string,term_id:int,term_taxonomy_id:int,term_order:int}> $rows
     * @param list<string> $wanted
     * @param array<string,array<string,array{term_id:int,term_taxonomy_id:int}>> $terms
     */
    private function reconcile_visibility_taxonomy(
        int $id,
        string $taxonomy,
        array $post,
        array $native,
        array $rows,
        array $wanted,
        array $terms,
        ?callable $heartbeat
    ): void {
        $current = $this->visibility_slugs($rows);
        $mutable = $taxonomy === 'product_visibility'
            ? self::PRODUCT_VISIBILITY_TERMS
            : ['pos-hidden'];

        if (($post['post_type'] ?? null) === 'product' && $taxonomy === 'product_visibility') {
            $currentMerchant = array_values(array_intersect($current, self::MERCHANT_VISIBILITY_TERMS));
            $wantedMerchant = array_values(array_intersect($wanted, self::MERCHANT_VISIBILITY_TERMS));
            sort($currentMerchant, SORT_STRING);
            sort($wantedMerchant, SORT_STRING);
            if ($currentMerchant !== $wantedMerchant) {
                throw new \RuntimeException(
                    "duo: WooCommerce product_visibility merchant intent changed before repair for product $id; recovery_required"
                );
            }
            $mutable = self::DERIVED_VISIBILITY_TERMS;
        } elseif (($post['post_type'] ?? null) === 'product' && $taxonomy === 'pos_product_visibility') {
            $supported = in_array($native['type'] ?? null, ['simple', 'variable'], true)
                && ($native['downloadable'] ?? null) === false;
            if ($supported) {
                throw new \RuntimeException(
                    "duo: WooCommerce supported-root POS merchant intent changed before repair for product $id; recovery_required"
                );
            }
            // POS is not a merchant surface on downloadable or unsupported
            // roots. Removing that one impossible relationship is safe; the
            // product type/downloadable fingerprint is checked again below.
            $mutable = ['pos-hidden'];
        }

        $currentMutable = array_values(array_intersect($current, $mutable));
        $wantedMutable = array_values(array_intersect($wanted, $mutable));
        sort($currentMutable, SORT_STRING);
        sort($wantedMutable, SORT_STRING);
        $remove = array_values(array_diff($currentMutable, $wantedMutable));
        $add = array_values(array_diff($wantedMutable, $currentMutable));

        if ($remove !== []) {
            $termIds = $this->visibility_term_ids($taxonomy, $remove, $terms);
            $removed = \wp_remove_object_terms($id, $termIds, $taxonomy);
            if (is_wp_error($removed) || $removed !== true) {
                throw new \RuntimeException(
                    "duo: WooCommerce $taxonomy native relationship write failed for product $id; recovery_required"
                );
            }
            $this->invalidate_product_caches($id);
            $this->heartbeat($heartbeat);
        }
        if ($add !== []) {
            $termIds = $this->visibility_term_ids($taxonomy, $add, $terms);
            $expectedTt = array_map(
                static fn(string $slug): int => $terms[$taxonomy][$slug]['term_taxonomy_id'],
                $add
            );
            $written = \wp_add_object_terms($id, $termIds, $taxonomy);
            if (is_wp_error($written) || !is_array($written)) {
                throw new \RuntimeException(
                    "duo: WooCommerce $taxonomy native relationship write failed for product $id; recovery_required"
                );
            }
            $written = array_map('intval', $written);
            sort($written, SORT_NUMERIC);
            sort($expectedTt, SORT_NUMERIC);
            if ($written !== $expectedTt) {
                throw new \RuntimeException(
                    "duo: WooCommerce $taxonomy native relationship write returned incomplete state for product $id; recovery_required"
                );
            }
            $this->invalidate_product_caches($id);
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * @param list<string> $slugs
     * @param array<string,array<string,array{term_id:int,term_taxonomy_id:int}>> $terms
     * @return list<int>
     */
    private function visibility_term_ids(string $taxonomy, array $slugs, array $terms): array {
        $ids = [];
        foreach ($slugs as $slug) {
            $term = $terms[$taxonomy][$slug] ?? null;
            if (!is_array($term)) {
                throw new \RuntimeException(
                    "duo: WooCommerce $taxonomy lacks a native term for scoped visibility repair"
                );
            }
            $ids[] = $term['term_id'];
        }
        return $ids;
    }

    /** @return array<string,array<string,array{term_id:int,term_taxonomy_id:int}>> */
    private function visibility_term_map(bool $requirePos): array {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results(
            "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy "
            . "FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id "
            . "WHERE tt.taxonomy IN ('product_type', 'product_visibility', 'pos_product_visibility') "
            . 'ORDER BY tt.taxonomy ASC, t.slug ASC, tt.term_taxonomy_id ASC LIMIT 15',
            'WooCommerce native visibility-term inventory'
        );
        if (count($rows) >= 15) {
            throw new \RuntimeException(
                'duo: WooCommerce native visibility-term inventory exceeds exact core cardinality'
            );
        }
        $out = ['product_type' => [], 'product_visibility' => [], 'pos_product_visibility' => []];
        foreach ($rows as $row) {
            $taxonomy = $row['taxonomy'] ?? null;
            $slug = $row['slug'] ?? null;
            $name = $row['name'] ?? null;
            $allowed = $taxonomy === 'product_type'
                ? self::CORE_PRODUCT_TYPES
                : ($taxonomy === 'product_visibility'
                    ? self::PRODUCT_VISIBILITY_TERMS
                    : ($taxonomy === 'pos_product_visibility' ? ['pos-hidden'] : []));
            if (!is_string($slug) || !is_string($name) || $name !== $slug
                || !in_array($slug, $allowed, true)
                || isset($out[$taxonomy][$slug])) {
                throw new \RuntimeException(
                    'duo: WooCommerce native visibility-term inventory is malformed or duplicated'
                );
            }
            $out[$taxonomy][$slug] = [
                'term_id' => $this->visibility_uint($row['term_id'] ?? null, 'term ID'),
                'term_taxonomy_id' => $this->visibility_uint(
                    $row['term_taxonomy_id'] ?? null,
                    'term-taxonomy ID'
                ),
            ];
        }
        $actual = array_keys($out['product_visibility']);
        sort($actual, SORT_STRING);
        $expected = self::PRODUCT_VISIBILITY_TERMS;
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                'duo: WooCommerce native product_visibility term inventory is incomplete'
            );
        }
        $actualProductTypes = array_keys($out['product_type']);
        sort($actualProductTypes, SORT_STRING);
        $expectedProductTypes = self::CORE_PRODUCT_TYPES;
        sort($expectedProductTypes, SORT_STRING);
        if ($actualProductTypes !== $expectedProductTypes) {
            throw new \RuntimeException(
                'duo: WooCommerce native product_type term inventory is incomplete'
            );
        }
        if ($requirePos && !isset($out['pos_product_visibility']['pos-hidden'])) {
            throw new \RuntimeException(
                'duo: WooCommerce native pos-hidden term is absent for authored POS visibility'
            );
        }
        return $out;
    }

    private function visibility_uint(mixed $value, string $field, bool $allowZero = false): int {
        $raw = is_int($value) || is_string($value) ? (string) $value : '';
        $maximum = (string) PHP_INT_MAX;
        if (preg_match($allowZero ? '/^(?:0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $raw) !== 1
            || strlen($raw) > strlen($maximum)
            || (strlen($raw) === strlen($maximum) && strcmp($raw, $maximum) > 0)) {
            throw new \RuntimeException(
                "duo: WooCommerce product visibility returned noncanonical $field state"
            );
        }
        $parsed = (int) $raw;
        if ((string) $parsed !== $raw || (!$allowZero && $parsed <= 0)) {
            throw new \RuntimeException(
                "duo: WooCommerce product visibility returned noncanonical $field state"
            );
        }
        return $parsed;
    }

    /**
     * One bounded receipt for both Woo-owned projections this provider now
     * repairs. Download URLs never enter the receipt: the fingerprint binds
     * them without leaking signed query strings or other merchant data.
     *
     * @param list<int> $lookupIds
     * @param list<int> $liveIds
     * @return array<string,int|string>
     */
    private function observe_provider_state(array $lookupIds, array $liveIds, bool $verifyNative): array {
        return array_merge(
            $this->observe_lookup_state($lookupIds),
            $this->observe_attribute_lookup_state($lookupIds),
            $this->observe_sale_schedule_state($lookupIds),
            $this->observe_cogs_state($lookupIds),
            $this->download_directory_state($liveIds, $verifyNative),
            $this->visibility_receipt($liveIds, $verifyNative)
        );
    }

    /**
     * Bind raw authored COGS rows and the exact native feature/schema state.
     * The values are hashed rather than exposed: costs are commercially
     * sensitive catalog data. Exact Woo 11.0.x ignores these rows when the
     * feature is disabled and omits cogs_total_value from its public lookup
     * derivation when the column is absent, so either mismatch must refuse
     * instead of producing a verified receipt for inert authored state.
     *
     * @param list<int> $ids
     * @return array{cogs_scoped_products:int,cogs_typed_products:int,cogs_authored_rows:int,cogs_feature_enabled:int,cogs_lookup_column_present:int,cogs_scope_sha256:string}
     */
    private function observe_cogs_state(array $ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        $this->assert_scoped_product_count($ids, 'Cost of Goods metadata observation');

        $class = '\\Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController';
        if (!class_exists($class) || !function_exists('wc_get_container')) {
            throw new \RuntimeException('duo: WooCommerce Cost of Goods service is unavailable');
        }
        try {
            $container = \wc_get_container();
            $controller = is_object($container) && is_callable([$container, 'get'])
                ? $container->get($class)
                : null;
        } catch (\Throwable $failure) {
            $controller = null;
        }
        foreach (['feature_is_enabled', 'product_meta_lookup_table_cogs_value_columns_exist'] as $method) {
            if (!is_object($controller) || !is_callable([$controller, $method])) {
                throw new \RuntimeException(
                    "duo: WooCommerce Cost of Goods service lacks public $method(); "
                    . 'the installed WooCommerce version is outside the adapter contract'
                );
            }
        }
        $featureEnabled = $controller->feature_is_enabled();
        $lookupColumnPresent = $controller->product_meta_lookup_table_cogs_value_columns_exist();
        if (!is_bool($featureEnabled) || !is_bool($lookupColumnPresent)) {
            throw new \RuntimeException('duo: WooCommerce Cost of Goods service returned an invalid feature/schema state');
        }

        $fingerprint = hash_init('sha256');
        foreach ($ids as $id) {
            $this->fingerprint_part($fingerprint, 'product:' . $id);
        }
        $rowCount = 0;
        $seen = [];
        $authoredByProduct = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = count($chunk) * 2 + 1;
            $prefixBytes = self::MAX_COGS_VALUE_BYTES + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT post_id, meta_id, BINARY meta_key AS meta_key, "
                . "LEFT(meta_value, $prefixBytes) AS meta_value, "
                . "LENGTH(meta_value) AS meta_value_bytes FROM `{$wpdb->postmeta}` "
                . "WHERE post_id IN ($placeholders) "
                . "AND meta_key IN ('_cogs_total_value', '_cogs_value_is_additive') "
                . "ORDER BY post_id ASC, meta_key ASC, meta_id ASC LIMIT $limit",
                ...$chunk
            ), 'Cost of Goods authored metadata observation');
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce Cost of Goods metadata observation saturated its bounded row read'
                );
            }
            foreach ($rows as $row) {
                $productId = $this->strict_positive_db_uint($row['post_id'] ?? null, 'COGS owner ID');
                $metaId = $this->strict_positive_db_uint($row['meta_id'] ?? null, 'COGS meta ID');
                $key = $row['meta_key'] ?? null;
                $value = $row['meta_value'] ?? null;
                $valueBytes = $this->strict_bounded_db_count(
                    $row['meta_value_bytes'] ?? null,
                    self::MAX_COGS_VALUE_BYTES + 1,
                    'Cost of Goods metadata byte witness'
                );
                if (!in_array($productId, $chunk, true)
                    || !is_string($key)
                    || !in_array($key, ['_cogs_total_value', '_cogs_value_is_additive'], true)
                    || !is_string($value)
                    || strlen($value) !== min($valueBytes, $prefixBytes)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce Cost of Goods metadata observation returned malformed or out-of-scope state'
                    );
                }
                if ($valueBytes > self::MAX_COGS_VALUE_BYTES) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $productId has oversized authored Cost of Goods metadata"
                    );
                }
                $identity = $productId . ':' . $key;
                if (isset($seen[$identity])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $productId has multiple $key rows; expected exactly one authored value"
                    );
                }
                $seen[$identity] = true;
                if (($key === '_cogs_total_value' && !self::cogs_meta_value_supported($value))
                    || ($key === '_cogs_value_is_additive' && $value !== 'yes')) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $productId has malformed authored Cost of Goods metadata"
                    );
                }
                $this->fingerprint_part($fingerprint, $identity);
                $this->fingerprint_part($fingerprint, hash('sha256', $value));
                $authoredByProduct[$productId][$key] = $value;
                $rowCount++;
            }
        }
        $typedProducts = $this->assert_cogs_product_types($authoredByProduct, $fingerprint);
        if ($rowCount > 0 && !$featureEnabled) {
            throw new \RuntimeException(
                "duo: WooCommerce Cost of Goods is disabled while $rowCount scoped authored row(s) require it; "
                . 'recovery_required'
            );
        }
        if ($rowCount > 0 && !$lookupColumnPresent) {
            throw new \RuntimeException(
                "duo: WooCommerce Cost of Goods lookup column is absent while $rowCount scoped authored row(s) require it; "
                . 'run the native WooCommerce COGS column tool and retry'
            );
        }
        return [
            'cogs_scoped_products' => count($ids),
            'cogs_typed_products' => $typedProducts,
            'cogs_authored_rows' => $rowCount,
            'cogs_feature_enabled' => $featureEnabled ? 1 : 0,
            'cogs_lookup_column_present' => $lookupColumnPresent ? 1 : 0,
            'cogs_scope_sha256' => hash_final($fingerprint),
        ];
    }

    private static function cogs_meta_value_supported(string $value): bool {
        if ($value === '' || strlen($value) > 128
            || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?(?:E[+-]?(?:0|[1-9][0-9]*))?$/D', $value) !== 1) {
            return false;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return false;
        }
        if ($value !== (string) $number) {
            return false;
        }
        $mantissa = explode('E', ltrim($value, '-'), 2)[0];
        if ($number === 0.0 && preg_match('/[1-9]/', $mantissa) === 1) {
            return false;
        }
        $transport = (float) (string) $number;
        return is_finite($transport) && abs($transport) < 1000000000000000.0;
    }

    /**
     * Bind the exact native product subtype for every owner carrying COGS.
     * A raw postmeta write bypasses WC_Product::set_cogs_value(), so receipt
     * observation must enforce the two class-dependent invariants itself:
     * base products delete numeric zero, while only variations may persist
     * the additive marker. The posts-table identity and WC factory type are
     * checked independently; either disappearing or disagreeing is a loud
     * post-materialization race rather than a verified recovery receipt.
     *
     * @param array<int,array<string,string>> $authoredByProduct
     * @param resource|\HashContext $fingerprint
     */
    private function assert_cogs_product_types(array $authoredByProduct, $fingerprint): int {
        global $wpdb;
        if ($authoredByProduct === []) {
            return 0;
        }
        $ids = array_keys($authoredByProduct);
        sort($ids, SORT_NUMERIC);
        $postTypes = [];
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $limit = count($chunk) + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID IN ($placeholders) "
                . "ORDER BY ID ASC LIMIT $limit",
                ...$chunk
            ), 'Cost of Goods product subtype observation');
            if (count($rows) >= $limit) {
                throw new \RuntimeException(
                    'duo: WooCommerce Cost of Goods subtype observation saturated its bounded owner read'
                );
            }
            foreach ($rows as $row) {
                $id = $this->strict_positive_db_uint($row['ID'] ?? null, 'COGS subtype owner ID');
                $postType = $row['post_type'] ?? null;
                if (!in_array($id, $chunk, true) || isset($postTypes[$id])
                    || !is_string($postType)
                    || !in_array($postType, ['product', 'product_variation'], true)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce Cost of Goods subtype observation returned malformed or out-of-scope state'
                    );
                }
                $postTypes[$id] = $postType;
            }
        }
        foreach ($ids as $id) {
            $postType = $postTypes[$id] ?? null;
            if (!is_string($postType)) {
                throw new \RuntimeException(
                    "duo: WooCommerce Cost of Goods owner $id is missing or is not a supported product subtype"
                );
            }
            try {
                $product = \wc_get_product($id);
            } catch (\Throwable $failure) {
                $product = null;
            }
            $nativeId = is_object($product) && is_callable([$product, 'get_id'])
                ? $product->get_id()
                : null;
            $nativeType = is_object($product) && is_callable([$product, 'get_type'])
                ? $product->get_type()
                : null;
            if (!is_int($nativeId) || $nativeId !== $id
                || !is_string($nativeType)
                || preg_match('/^[a-z0-9_-]{1,64}$/D', $nativeType) !== 1
                || (($postType === 'product_variation') !== ($nativeType === 'variation'))) {
                throw new \RuntimeException(
                    "duo: WooCommerce Cost of Goods owner $id has an inconsistent native product subtype"
                );
            }
            $isVariation = $postType === 'product_variation';
            $meta = $authoredByProduct[$id];
            if (isset($meta['_cogs_value_is_additive']) && !$isVariation) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id has variation-only additive Cost of Goods metadata"
                );
            }
            if (isset($meta['_cogs_total_value'])
                && (float) $meta['_cogs_total_value'] === 0.0
                && !$isVariation) {
                throw new \RuntimeException(
                    "duo: WooCommerce base product $id has a Cost of Goods zero that native storage deletes"
                );
            }
            $this->fingerprint_part($fingerprint, "owner:$id");
            $this->fingerprint_part($fingerprint, $postType);
            $this->fingerprint_part($fingerprint, $nativeType);
        }
        return count($ids);
    }

    /**
     * Add only parent directories required by enabled downloads on products
     * in this apply batch. Existing broader rules satisfy the requirement and
     * are left alone; unrelated rules are never listed, changed, or deleted.
     * Woo's own Register/URL classes retain ownership of normalization and
     * directory-containment semantics.
     *
     * @param list<int> $ids
     */
    private function approve_download_directories(array $ids): void {
        $downloads = $this->authored_downloads($ids);
        if ($downloads === []) {
            return;
        }
        $register = $this->download_directory_register();
        $mode = (string) $register->get_mode();
        $registerClass = '\\Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
        if ($mode !== $registerClass::MODE_ENABLED) {
            return;
        }

        foreach ($downloads as $download) {
            if ($this->download_path_is_valid($register, $download)) {
                continue;
            }
            $parent = $this->download_parent_url($download);
            try {
                $stored = $register->get_by_url($parent);
                if (is_object($stored)) {
                    if (!is_callable([$stored, 'get_id']) || !is_callable([$stored, 'is_enabled'])) {
                        throw new \RuntimeException('unreadable approved-directory record');
                    }
                    if (!$stored->is_enabled() && !$register->enable_by_id((int) $stored->get_id())) {
                        throw new \RuntimeException('approved-directory enable did not persist');
                    }
                } else {
                    $created = $register->add_approved_directory($parent, true);
                    if (!is_int($created) || $created <= 0) {
                        throw new \RuntimeException('approved-directory add returned no identity');
                    }
                }
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    'duo: WooCommerce could not approve the target directory for product '
                    . $download['product_id'] . ' download ' . $this->download_label($download)
                );
            }
            if (!$this->download_path_is_valid($register, $download)) {
                throw new \RuntimeException(
                    'duo: WooCommerce approved-directory write did not make product '
                    . $download['product_id'] . ' download ' . $this->download_label($download)
                    . ' valid; recovery_required'
                );
            }
        }
    }

    /**
     * Read the exact raw metadata Duo wrote, bypassing WordPress's potentially
     * stale post-meta cache. Repository diagnostics already reject malformed
     * rows, but the executable boundary repeats the small shape check so a
     * direct or corrupted artifact cannot turn a provider receipt into an
     * approval of an unreviewed locator.
     *
     * @param list<int> $ids
     * Exact Woo 11.0.0/11.0.1 writers persist the four WC_Product_Download
     * data keys; their reader also accepts legacy name/file-only rows and
     * derives id/enabled from the map key and approval check. Extension
     * `extra_data` keys are deliberately refused because no core semantic can
     * independently verify them.
     *
     * @return list<array{product_id:int,download_id:string,name:string,file:string,enabled:bool}>
     */
    private function authored_downloads(array $ids): array {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        $this->assert_scoped_product_count($ids, 'downloadable product metadata observation');
        if ($ids === []) {
            return [];
        }

        $seenProducts = [];
        $out = [];
        $rawBytes = 0;
        $downloadCount = 0;
        $downloadIdBytes = 0;
        $downloadNameBytes = 0;
        $downloadFileBytes = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $witnesses = $this->downloadable_meta_witness($chunk);
            foreach ($witnesses as $witness) {
                $productId = $witness['product_id'];
                if (isset($seenProducts[$productId])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $productId has multiple _downloadable_files rows; "
                        . 'the adapter supports one authored value'
                    );
                }
                $seenProducts[$productId] = true;
                if ($witness['bytes'] > self::MAX_DOWNLOAD_META_BYTES_PER_PRODUCT) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $productId has oversized downloadable-file metadata"
                    );
                }
                $rawBytes += $witness['bytes'];
                if ($rawBytes > self::MAX_DOWNLOAD_META_BYTES) {
                    throw new \RuntimeException(
                        'duo: WooCommerce downloadable-file metadata exceeds the aggregate byte bound'
                    );
                }
            }
            if ($witnesses === []) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $identityConditions = [];
            $identityArgs = [];
            foreach ($witnesses as $witness) {
                $identityConditions[] = '(post_id = %d AND meta_id = %d '
                    . 'AND LENGTH(meta_value) = %d AND SHA2(meta_value, 256) = %s)';
                array_push(
                    $identityArgs,
                    $witness['product_id'],
                    $witness['meta_id'],
                    $witness['bytes'],
                    $witness['sha256']
                );
            }
            $limit = count($witnesses) + 1;
            $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT post_id, meta_id, BINARY meta_key AS meta_key, meta_value, "
                . "LENGTH(meta_value) AS meta_value_bytes, SHA2(meta_value, 256) AS meta_sha256 "
                . "FROM `{$wpdb->postmeta}` "
                . "WHERE meta_key = '_downloadable_files' AND post_id IN ($placeholders) "
                . 'AND (' . implode(' OR ', $identityConditions) . ') '
                . "ORDER BY post_id ASC, meta_id ASC LIMIT $limit",
                ...array_merge($chunk, $identityArgs)
            ), 'downloadable product bounded metadata read');
            if (count($rows) !== count($witnesses)) {
                throw new \RuntimeException(
                    'duo: WooCommerce downloadable product metadata changed after its byte witness'
                );
            }
            $payloadWitnesses = [];
            foreach ($rows as $row) {
                $productId = $this->strict_positive_db_uint(
                    $row['post_id'] ?? null,
                    'download metadata owner ID'
                );
                $metaId = $this->strict_positive_db_uint(
                    $row['meta_id'] ?? null,
                    'download metadata row ID'
                );
                $valueBytes = $this->strict_bounded_db_count(
                    $row['meta_value_bytes'] ?? null,
                    self::MAX_DOWNLOAD_META_BYTES_PER_PRODUCT,
                    'download metadata byte readback'
                );
                $valueHash = $this->strict_db_sha256(
                    $row['meta_sha256'] ?? null,
                    'download metadata payload hash'
                );
                $value = $row['meta_value'] ?? null;
                if (!in_array($productId, $chunk, true)
                    || ($row['meta_key'] ?? null) !== '_downloadable_files'
                    || !is_string($value)
                    || strlen($value) !== $valueBytes) {
                    throw new \RuntimeException(
                        'duo: WooCommerce downloadable product metadata read returned an out-of-scope owner'
                    );
                }
                $payloadWitnesses[] = [
                    'product_id' => $productId,
                    'meta_id' => $metaId,
                    'bytes' => $valueBytes,
                    'sha256' => $valueHash,
                ];
                $expectedWitness = $witnesses[count($payloadWitnesses) - 1] ?? null;
                if ($payloadWitnesses[count($payloadWitnesses) - 1] !== $expectedWitness) {
                    throw new \RuntimeException(
                        'duo: WooCommerce downloadable product metadata identity changed after its byte witness'
                    );
                }
                $decoded = \Duo\PlainData::decode_serialized(
                    $value,
                    "WooCommerce product $productId downloadable-file metadata"
                );
                if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))
                    || count($decoded) > self::MAX_DOWNLOADS_PER_PRODUCT) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product $productId has malformed downloadable-file metadata"
                    );
                }
                $productDownloadCount = 0;
                foreach ($decoded as $downloadId => $value) {
                    $unknown = is_array($value)
                        ? array_diff(array_keys($value), ['id', 'name', 'file', 'enabled'])
                        : [];
                    $idBytes = is_string($downloadId) ? strlen($downloadId) : 0;
                    $nameBytes = is_array($value) && is_string($value['name'] ?? null)
                        ? strlen($value['name'])
                        : 0;
                    $fileBytes = is_array($value) && is_string($value['file'] ?? null)
                        ? strlen($value['file'])
                        : 0;
                    if (!is_string($downloadId) || $downloadId === ''
                        || $idBytes > self::MAX_DOWNLOAD_ID_BYTES
                        || !is_array($value) || array_is_list($value)
                        || $unknown !== []
                        || !is_string($value['name'] ?? null)
                        || $nameBytes > self::MAX_DOWNLOAD_NAME_BYTES
                        || !is_string($value['file'] ?? null) || $value['file'] === ''
                        || $fileBytes > self::MAX_DOWNLOAD_FILE_BYTES
                        || (array_key_exists('id', $value)
                            && (!is_string($value['id']) || $value['id'] !== $downloadId))
                        || (array_key_exists('enabled', $value)
                            && (!is_bool($value['enabled']) || $value['enabled'] !== true))) {
                        throw new \RuntimeException(
                            "duo: WooCommerce product $productId has an unsupported downloadable-file row"
                        );
                    }
                    $productDownloadCount++;
                    $downloadCount++;
                    $downloadIdBytes += $idBytes;
                    $downloadNameBytes += $nameBytes;
                    $downloadFileBytes += $fileBytes;
                    if ($productDownloadCount > self::MAX_DOWNLOADS_PER_PRODUCT
                        || $downloadCount > self::MAX_DOWNLOADS
                        || $downloadIdBytes > self::MAX_DOWNLOAD_ID_BYTES_TOTAL
                        || $downloadNameBytes > self::MAX_DOWNLOAD_NAME_BYTES_TOTAL
                        || $downloadFileBytes > self::MAX_DOWNLOAD_FILE_BYTES_TOTAL) {
                        throw new \RuntimeException(
                            'duo: WooCommerce downloadable-file collection exceeds its aggregate decoded bound'
                        );
                    }
                    $file = $value['file'];
                    if (str_starts_with($file, '[') && str_ends_with($file, ']')) {
                        throw new \RuntimeException(
                            "duo: WooCommerce product $productId uses a shortcode download locator; "
                            . 'extension-executed locators are outside this adapter contract'
                        );
                    }
                    $out[] = [
                        'product_id' => $productId,
                        'download_id' => $downloadId,
                        'name' => $value['name'],
                        'file' => $file,
                        'enabled' => true,
                    ];
                }
            }
            if ($this->downloadable_meta_witness($chunk) !== $witnesses) {
                throw new \RuntimeException(
                    'duo: WooCommerce downloadable product metadata changed during bounded readback'
                );
            }
        }
        usort($out, static fn(array $left, array $right): int => [
            $left['product_id'], $left['download_id'], $left['name'], $left['file'],
        ] <=> [
            $right['product_id'], $right['download_id'], $right['name'], $right['file'],
        ]);
        return $out;
    }

    /**
     * @param list<int> $chunk
     * @return list<array{product_id:int,meta_id:int,bytes:int,sha256:string}>
     */
    private function downloadable_meta_witness(array $chunk): array {
        global $wpdb;
        if ($chunk === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
        $limit = count($chunk) + 1;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT post_id, meta_id, BINARY meta_key AS meta_key, "
            . "LENGTH(meta_value) AS meta_value_bytes, SHA2(meta_value, 256) AS meta_sha256 "
            . "FROM `{$wpdb->postmeta}` "
            . "WHERE meta_key = '_downloadable_files' AND post_id IN ($placeholders) "
            . "ORDER BY post_id ASC, meta_id ASC LIMIT $limit",
            ...$chunk
        ), 'downloadable product metadata byte witness');
        $witnesses = [];
        $owners = [];
        foreach ($rows as $row) {
            $productId = $this->strict_positive_db_uint(
                $row['post_id'] ?? null,
                'download metadata witness owner ID'
            );
            $metaId = $this->strict_positive_db_uint(
                $row['meta_id'] ?? null,
                'download metadata witness row ID'
            );
            $bytes = $this->strict_nonnegative_db_uint(
                $row['meta_value_bytes'] ?? null,
                'download metadata witness bytes'
            );
            $valueHash = $this->strict_db_sha256(
                $row['meta_sha256'] ?? null,
                'download metadata witness hash'
            );
            if (isset($owners[$productId])) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $productId has multiple _downloadable_files rows; "
                    . 'the adapter supports one authored value'
                );
            }
            if (!in_array($productId, $chunk, true)
                || ($row['meta_key'] ?? null) !== '_downloadable_files') {
                throw new \RuntimeException(
                    'duo: WooCommerce downloadable product metadata witness returned malformed or aliased state'
                );
            }
            $owners[$productId] = true;
            $witnesses[] = [
                'product_id' => $productId,
                'meta_id' => $metaId,
                'bytes' => $bytes,
                'sha256' => $valueHash,
            ];
        }
        if (count($rows) >= $limit) {
            throw new \RuntimeException(
                'duo: WooCommerce downloadable product metadata saturated its bounded owner read'
            );
        }
        return $witnesses;
    }

    /** @return object WooCommerce ApprovedDirectories Register */
    private function download_directory_register(): object {
        $class = '\\Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
        if (!class_exists($class) || !function_exists('wc_get_container')) {
            throw new \RuntimeException(
                'duo: WooCommerce approved-download-directory API is unavailable'
            );
        }
        try {
            $register = \wc_get_container()->get($class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: WooCommerce approved-download-directory service could not load');
        }
        foreach (['get_mode', 'get_by_url', 'add_approved_directory', 'enable_by_id', 'is_valid_path'] as $method) {
            if (!is_object($register) || !is_callable([$register, $method])) {
                throw new \RuntimeException(
                    "duo: WooCommerce approved-download-directory service lacks public $method(); "
                    . 'the installed version is outside this adapter contract'
                );
            }
        }
        return $register;
    }

    /** @param array{product_id:int,download_id:string,name:string,file:string,enabled:bool} $download */
    private function download_parent_url(array $download): string {
        $class = '\\Automattic\\WooCommerce\\Internal\\Utilities\\URL';
        if (!class_exists($class)) {
            throw new \RuntimeException('duo: WooCommerce approved-download URL API is unavailable');
        }
        try {
            $parent = (new $class($download['file']))->get_parent_url();
        } catch (\Throwable $failure) {
            $parent = false;
        }
        if (!is_string($parent) || $parent === '') {
            throw new \RuntimeException(
                'duo: WooCommerce product ' . $download['product_id'] . ' download '
                . $this->download_label($download) . ' has no approvable parent directory'
            );
        }
        return $parent;
    }

    /** @param array{product_id:int,download_id:string,name:string,file:string,enabled:bool} $download */
    private function download_path_is_valid(object $register, array $download): bool {
        try {
            return $register->is_valid_path($download['file']) === true;
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce could not validate the target path for product '
                . $download['product_id'] . ' download ' . $this->download_label($download)
            );
        }
    }

    /** @param array{product_id:int,download_id:string,name:string,file:string,enabled:bool} $download */
    private function download_label(array $download): string {
        return substr(hash('sha256', $download['product_id'] . "\0" . $download['download_id']), 0, 12);
    }

    /**
     * @param list<int> $ids
     * @return array{download_files:int,download_directories:int,usable_download_files:int,directory_mode:string,download_scope_sha256:string}
     */
    private function download_directory_state(array $ids, bool $verifyNative): array {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        $downloads = $this->authored_downloads($ids);
        $fingerprint = hash_init('sha256');
        foreach ($ids as $id) {
            $this->fingerprint_part($fingerprint, 'product:' . $id);
        }
        foreach ($downloads as $download) {
            foreach ([
                (string) $download['product_id'],
                $download['download_id'],
                $download['name'],
                $download['file'],
                $download['enabled'] ? 'enabled' : 'disabled',
            ] as $part) {
                $this->fingerprint_part($fingerprint, $part);
            }
        }
        if ($downloads === []) {
            if ($verifyNative) {
                $this->verify_native_downloads($ids, []);
            }
            return [
                'download_files' => 0,
                'download_directories' => 0,
                'usable_download_files' => 0,
                'directory_mode' => 'not-applicable',
                'download_scope_sha256' => hash_final($fingerprint),
            ];
        }

        $register = $this->download_directory_register();
        $mode = (string) $register->get_mode();
        $registerClass = '\\Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register';
        if (!in_array($mode, [$registerClass::MODE_DISABLED, $registerClass::MODE_ENABLED], true)) {
            throw new \RuntimeException(
                'duo: WooCommerce approved-download-directory mode is outside the adapter contract'
            );
        }
        $directories = [];
        $usable = 0;
        foreach ($downloads as $download) {
            $parent = $this->download_parent_url($download);
            $directories[hash('sha256', $parent)] = true;
            if ($mode === $registerClass::MODE_DISABLED
                || $this->download_path_is_valid($register, $download)) {
                $usable++;
            }
        }
        if ($verifyNative && $usable !== count($downloads)) {
            throw new \RuntimeException(
                'duo: WooCommerce approved-directory verification left one or more scoped downloads unusable; '
                . 'recovery_required'
            );
        }
        if ($verifyNative) {
            $this->verify_native_downloads($ids, $downloads);
        }
        return [
            'download_files' => count($downloads),
            'download_directories' => count($directories),
            'usable_download_files' => $usable,
            'directory_mode' => $mode,
            'download_scope_sha256' => hash_final($fingerprint),
        ];
    }

    /**
     * @param list<int> $productIds
     * @param list<array{product_id:int,download_id:string,name:string,file:string,enabled:bool}> $downloads
     */
    private function verify_native_downloads(array $productIds, array $downloads): void {
        $byProduct = [];
        foreach ($productIds as $productId) {
            $byProduct[(int) $productId] = [];
        }
        foreach ($downloads as $download) {
            $byProduct[$download['product_id']][$download['download_id']] = $download;
        }
        foreach ($byProduct as $productId => $expected) {
            $product = \wc_get_product((int) $productId);
            if (!is_object($product) || !is_callable([$product, 'get_downloads'])) {
                throw new \RuntimeException(
                    "duo: WooCommerce could not load product $productId for native download verification"
                );
            }
            $native = $product->get_downloads();
            if (!is_array($native)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $productId returned an unreadable native download collection"
                );
            }
            if (count($native) > self::MAX_DOWNLOADS_PER_PRODUCT) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $productId returned an oversized native download collection"
                );
            }
            $expectedIds = array_keys($expected);
            sort($expectedIds, SORT_STRING);
            $nativeIds = array_keys($native);
            if (count(array_filter($nativeIds, 'is_string')) !== count($nativeIds)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $productId returned non-string native download identities; recovery_required"
                );
            }
            sort($nativeIds, SORT_STRING);
            if ($nativeIds !== $expectedIds) {
                throw new \RuntimeException(
                    "duo: WooCommerce native download cardinality or identity verification failed for product $productId; "
                    . 'recovery_required'
                );
            }
            foreach ($expected as $downloadId => $download) {
                $value = $native[$downloadId];
                $expectedName = $download['name'];
                if ($expectedName === '') {
                    $expectedName = \wc_get_filename_from_url($download['file']);
                }
                if (!is_object($value)
                    || !is_callable([$value, 'get_id'])
                    || !is_callable([$value, 'get_name'])
                    || !is_callable([$value, 'get_file'])
                    || !is_callable([$value, 'get_enabled'])
                    || !is_string($expectedName)
                    || !hash_equals($downloadId, (string) $value->get_id())
                    || !hash_equals($expectedName, (string) $value->get_name())
                    || !hash_equals($download['file'], (string) $value->get_file())
                    || $value->get_enabled() !== $download['enabled']) {
                    throw new \RuntimeException(
                        "duo: WooCommerce native download verification failed for product $productId download "
                        . $this->download_label($download) . '; recovery_required'
                    );
                }
            }
        }
    }

    /**
     * Reconcile all supplied live product ids plus pre-delete/reparent cleanup
     * context. The engine may pass a parent and several variations in any
     * order; roots are deduplicated here so variable products are synthesized
     * once. A `kind=reparent` context refreshes every root in `root_ids`
     * (falling back to old/new ids for older receipts) without treating the
     * still-live variation as deleted.
     *
     * @param array<int,int|string> $liveIds
     * @param array<int,array> $deletionContext
     * @param callable|null $heartbeat Lease-renewal callback. The provider
     *   contract has no heartbeat parameter — \Duo\Providers::invoke() passes a
     *   capability name and typed args and nothing else — so invoke() above
     *   supplies null and the engine brackets the whole call with a lease
     *   renewal instead (Apply::renew_provider_lease()). The parameter and
     *   every call site stay because they are the moved code's own shape and
     *   because a future contract that CAN pass one needs nothing rewritten
     *   here; a null heartbeat is a no-op by construction (heartbeat()).
     */
    public function regenerate_batch(
        array $liveIds,
        array $deletionContext,
        ?callable $heartbeat = null,
        ?array $visibilityIntent = null
    ): void {
        global $wpdb;
        $currentVisibility = $this->visibility_snapshot(
            $liveIds,
            true,
            $this->visibility_deletion_ids($deletionContext)
        );
        if ($visibilityIntent === null) {
            $visibilityIntent = $currentVisibility;
        } elseif (!hash_equals(
            (string) ($visibilityIntent['intent_sha256'] ?? ''),
            (string) $currentVisibility['intent_sha256']
        )) {
            throw new \RuntimeException(
                'duo: WooCommerce product visibility changed before native projection; recovery_required'
            );
        }
        // Validate the bounded whole-taxonomy inventory before any lookup,
        // scheduling, cache, or relationship mutation. A no-op projection is
        // not permission to bless duplicate/hijacked native term identities,
        // and a referenced POS term must exist before this provider changes
        // an otherwise unrelated derived surface.
        $visibilityTerms = $this->visibility_term_map(
            $this->visibility_requires_pos($currentVisibility, null)
        );
        $this->assert_product_type_projection($currentVisibility, $visibilityTerms);
        // Typed Woo attribute definitions can be applied after Woo's init
        // registration pass. Refresh the public attribute caches and register
        // any newly-created pa_* taxonomies before wc_get_product() parses
        // _product_attributes; otherwise Woo treats a global attribute as a
        // local one for the rest of this apply process, corrupting attribute
        // cache invalidation and product object semantics.
        $registeredAttributeKeys = $this->refresh_attribute_taxonomy_registry();
        $this->heartbeat($heartbeat);

        $liveIds = array_values(array_unique(array_filter(
            array_map('intval', $liveIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($liveIds, SORT_NUMERIC);

        // WooCommerce 11 validates every hydrated downloadable file against
        // a target-local approved-directory register. Duo has already
        // rebound authored file URLs to this target, but raw postmeta writes
        // bypass the native admin save that adds the new parent directory.
        // Repair that finite, apply-selected set before the first product
        // object is loaded; otherwise Woo caches those downloads as disabled
        // for the rest of the promotion process.
        $this->approve_download_directories($liveIds);
        $this->heartbeat($heartbeat);

        $deletionIds = [];
        $deletedParents = [];
        $reparentedParents = [];
        foreach ($deletionContext as $context) {
            if (($context['kind'] ?? 'delete') === 'reparent') {
                $rootIds = array_map('intval', (array) ($context['root_ids'] ?? []));
                if (!$rootIds) {
                    $rootIds = [
                        (int) ($context['old_parent_id'] ?? $context['parent_id'] ?? 0),
                        (int) ($context['new_parent_id'] ?? 0),
                    ];
                }
                foreach (array_unique($rootIds) as $rootId) {
                    if ($rootId > 0) {
                        $reparentedParents[$rootId] = $rootId;
                    }
                }
                continue;
            }
            $id = (int) ($context['id'] ?? 0);
            if ($id > 0) {
                $deletionIds[$id] = $id;
            }
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $deletionIds[$childId] = $childId;
                }
            }
            $parentId = (int) ($context['parent_id'] ?? 0);
            if (($context['post_type'] ?? '') === 'product_variation' && $parentId > 0) {
                $deletedParents[$parentId] = $parentId;
            }
        }

        // Delete the supported product-meta lookup rows first, while no WC
        // object is needed. This works even though the post itself has already
        // been removed by raw SQL.
        if ($deletionIds) {
            $productStore = \WC_Data_Store::load('product');
            foreach ($deletionIds as $id) {
                $this->heartbeat($heartbeat);
                $this->delete_meta_lookup($productStore, $id);
                $this->heartbeat($heartbeat);
            }
            $this->delete_attribute_lookup_rows(array_values($deletionIds), $heartbeat);
        }

        // Invalidate all objects before reading authored prices/attributes.
        // Raw SQL does not run Woo's usual cache invalidation hooks.
        $affectedIds = array_fill_keys($liveIds, true);
        // A deleted variation can still be present in a cached parent
        // object's child list while the parent lookup is being rebuilt. Clear
        // the deleted product instances too, so price/root synthesis sees the
        // post's absence rather than resurrecting derived product state.
        foreach ($deletionIds as $deletedId) {
            $deletedId = (int) $deletedId;
            if ($deletedId > 0) {
                $affectedIds[$deletedId] = true;
            }
        }
        foreach (array_unique(array_merge($deletedParents, $reparentedParents)) as $parentId) {
            $affectedIds[$parentId] = true;
        }
        foreach (array_keys($affectedIds) as $id) {
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches((int) $id);
        }

        $products = [];
        $variableRoots = [];
        $groupedRoots = [];
        $attributeRoots = [];
        $priceIds = [];

        foreach ($liveIds as $id) {
            $this->heartbeat($heartbeat);
            $product = $this->load_product($id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce product lookup regeneration could not load live product $id");
            }
            $products[$id] = $product;
            $type = (string) $product->get_type();
            if ($type === 'variation') {
                $parentId = (int) $product->get_parent_id('edit');
                // A same-parent variation write can still change the
                // parent's visible-child set (status/catalog visibility) or
                // other cached root inputs. Invalidate the current root
                // before the first wc_get_product(parent), just as the
                // durable reparent path does for old/new roots.
                if ($parentId > 0) {
                    $this->heartbeat($heartbeat);
                    $this->invalidate_product_caches($parentId);
                }
                $parent = $parentId > 0 ? $this->load_product($parentId) : false;
                if ($parent && $this->is_variable($parent)) {
                    $variableRoots[$parentId] = $parent;
                    $attributeRoots[$parentId] = $parent;
                    // A variation-path write still requires the complete
                    // finite child set: verification checks every child and
                    // the variable store derives the root from every visible
                    // child. Refresh each sibling here as well so a stale
                    // sibling lookup (or _price input) converges in one pass,
                    // without widening into a catalog-wide scan.
                    foreach ((array) $parent->get_children() as $childId) {
                        $childId = (int) $childId;
                        if ($childId <= 0 || isset($deletionIds[$childId])) {
                            continue;
                        }
                        $this->heartbeat($heartbeat);
                        $this->invalidate_product_caches($childId);
                        $child = $this->load_product($childId);
                        if ($child) {
                            $products[$childId] = $child;
                            $priceIds[$childId] = true;
                        }
                        $this->heartbeat($heartbeat);
                    }
                } else {
                    $attributeRoots[$id] = $product;
                }
                $priceIds[$id] = true;
                continue;
            }
            if ($this->is_variable($product)) {
                $variableRoots[$id] = $product;
                $attributeRoots[$id] = $product;
                // sync_price() reads every child _price row.  Recompute all
                // child prices first, including children not in this batch.
                foreach ((array) $product->get_children() as $childId) {
                    $childId = (int) $childId;
                    if ($childId <= 0 || isset($deletionIds[$childId])) {
                        continue;
                    }
                    $this->heartbeat($heartbeat);
                    $this->invalidate_product_caches($childId);
                    $child = $this->load_product($childId);
                    if ($child) {
                        $products[$childId] = $child;
                        $priceIds[$childId] = true;
                    }
                    $this->heartbeat($heartbeat);
                }
            } elseif ($this->is_grouped($product)) {
                $this->collect_grouped_root(
                    $product,
                    $groupedRoots,
                    $variableRoots,
                    $attributeRoots,
                    $products,
                    $priceIds,
                    $deletionIds,
                    $heartbeat
                );
            } else {
                $attributeRoots[$id] = $product;
            }
            $priceIds[$id] = true;
            $this->heartbeat($heartbeat);
        }

        // Grouped products store their child ids in _children rather than a
        // post_parent relation.  There is no reverse public Woo index, so use
        // a targeted meta-key query for each changed/deleted id and validate
        // candidates through the public grouped product object.  This is
        // bounded by the batch, never a catalog-wide WC_Product query.
        $groupedCandidateIds = array_values(array_unique(array_merge(
            $liveIds,
            array_keys($deletionIds),
            array_keys($variableRoots),
            array_keys($deletedParents),
            array_keys($reparentedParents)
        )));
        foreach ($groupedCandidateIds as $childId) {
            $childId = (int) $childId;
            if ($childId <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            foreach ($this->find_grouped_parent_ids($childId) as $parentId) {
                $this->heartbeat($heartbeat);
                $this->invalidate_product_caches($parentId);
                $parent = $this->load_product($parentId);
                if (!$parent || !$this->is_grouped($parent)) {
                    continue;
                }
                $children = array_map('intval', (array) $parent->get_children());
                if (!in_array($childId, $children, true)) {
                    continue;
                }
                $this->collect_grouped_root(
                    $parent,
                    $groupedRoots,
                    $variableRoots,
                    $attributeRoots,
                    $products,
                    $priceIds,
                    $deletionIds,
                    $heartbeat
                );
            }
            $this->heartbeat($heartbeat);
        }

        // A deleted variation, or a variation moved away from an old root,
        // changes its still-live variable parent. Reload each affected root
        // after cache invalidation and deduplicate it with any parent already
        // in the batch. Reparent contexts deliberately do not add the live
        // variation to deletionIds.
        foreach (array_unique(array_merge($deletedParents, $reparentedParents)) as $parentId) {
            $this->heartbeat($heartbeat);
            $parent = $this->load_product($parentId);
            if ($parent && $this->is_variable($parent)) {
                $variableRoots[$parentId] = $parent;
                $attributeRoots[$parentId] = $parent;
                foreach ((array) $parent->get_children() as $childId) {
                    $childId = (int) $childId;
                    if ($childId <= 0 || isset($deletionIds[$childId])) {
                        continue;
                    }
                    $this->heartbeat($heartbeat);
                    $this->invalidate_product_caches($childId);
                    $child = $this->load_product($childId);
                    if ($child) {
                        $products[$childId] = $child;
                        $priceIds[$childId] = true;
                    }
                    $this->heartbeat($heartbeat);
                }
                $priceIds[$parentId] = true;
            }
            $this->heartbeat($heartbeat);
        }

        // WooCommerce's WC_Product_Data_Store_CPT::clear_caches() queues
        // layered-navigation count transients from the product's own
        // attribute keys.  Raw SQL writes do not reach that boundary.  Keep
        // the call synchronous at the adapter boundary (Woo itself flushes
        // the queued keys at shutdown) and include every live product/root we
        // loaded.  A variation's own keys are required by Woo 11.x; its
        // parent is also present in attributeRoots when it is a variable
        // product, so parent-derived lookup changes invalidate the same
        // layered-nav family without guessing attribute names.
        $this->invalidate_attribute_counts(
            array_merge($products, $attributeRoots),
            $deletionIds ? $registeredAttributeKeys : []
        );

        // Ask WooCommerce to decide the active price from its authored
        // regular/sale/date props. WC_Product::get_price('edit') is not an
        // independent derivation in Woo 11 — it reads the stored _price prop —
        // so the public is_on_sale()/regular/sale accessors are the stable
        // business-rule boundary here.
        foreach ($priceIds as $id => $_) {
            if (isset($variableRoots[$id]) || isset($groupedRoots[$id])) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $product = $products[(int) $id] ?? $this->load_product((int) $id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce could not load product $id for public price synthesis");
            }
            $this->sync_simple_price_from_woocommerce($product);
            $this->heartbeat($heartbeat);
        }

        $variableStore = \WC_Data_Store::load('product-variable');
        if (!is_object($variableStore) || !$this->store_has($variableStore, 'sync_price')) {
            throw new \RuntimeException(
                'duo: WooCommerce product-variable data store lacks public sync_price(); '
                . 'the installed WooCommerce version is outside the adapter contract'
            );
        }
        foreach ($variableRoots as $rootId => $root) {
            $this->heartbeat($heartbeat);
            // sync_price() is public in WooCommerce 11.x and updates the
            // parent _price set from all visible child prices. Pass the object
            // by reference as the data-store contract requires. Woo's own
            // implementation also attempts to delete parent regular/sale
            // rows; protect those repository-authored rows at WordPress's
            // public metadata boundary while Woo owns every price decision.
            $this->sync_parent_price_from_woocommerce($variableStore, $root);
            $this->heartbeat($heartbeat);
        }

        if ($groupedRoots) {
            // A variable child's public sync_price() writes its new raw
            // _price, but Woo's product cache controller may deliberately
            // retain the cached product instance for that price-only write.
            // Evict and reload every grouped child after all variable roots
            // have synced, immediately before grouped sync_price() asks Woo
            // for those child objects. Otherwise a grouped root can be
            // synthesized from a stale variable-child price.
            $this->refresh_grouped_children_for_sync(
                $groupedRoots,
                $variableRoots,
                $attributeRoots,
                $products,
                $deletionIds,
                $heartbeat
            );
            $groupedStore = \WC_Data_Store::load('product-grouped');
            if (!is_object($groupedStore) || !$this->store_has($groupedStore, 'sync_price')) {
                throw new \RuntimeException(
                    'duo: WooCommerce product-grouped data store lacks public sync_price(); '
                    . 'the installed WooCommerce version is outside the adapter contract'
                );
            }
            foreach ($groupedRoots as $rootId => $root) {
                $this->heartbeat($heartbeat);
                // sync_price() is the public grouped-product boundary; it
                // derives min/max _price from the current child objects while
                // preserving the grouped root's runtime stock/order fields.
                // It also clears authored regular/sale rows, which must stay
                // target-identical just like variable roots above.
                $this->sync_parent_price_from_woocommerce($groupedStore, $root);
                $this->heartbeat($heartbeat);
            }
        }

        $productStore = \WC_Data_Store::load('product');
        if (!is_object($productStore) || !$this->store_has($productStore, 'refresh_product_lookup_table')) {
            throw new \RuntimeException(
                'duo: WooCommerce product data store lacks public refresh_product_lookup_table(); '
                . 'the installed WooCommerce version is outside the adapter contract'
            );
        }
        foreach (array_keys($priceIds + $variableRoots + $groupedRoots) as $id) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches($id);
            $productStore->refresh_product_lookup_table($id);
            $this->heartbeat($heartbeat);
        }

        // Woo's public lookup writer synchronously deletes and recreates the
        // complete scoped root (including variable children). Its own failure
        // flag is load-bearing because core catches insert exceptions; a
        // partial table must fail the apply and remain retryable. The exact
        // raw-table observation below proves the writer's bytes landed and is
        // also the receipt basis used by scoped recovery.
        $this->rebuild_attribute_lookups($attributeRoots, $heartbeat);
        $this->observe_attribute_lookup_state(array_map('intval', array_keys($attributeRoots)));

        // Sale actions are operational state derived from the exact sale-date
        // inputs on the affected products. Woo's public helper is
        // product-scoped and idempotently clears/recreates the two Action
        // Scheduler hooks; invoke it only for the bounded live/root/deletion
        // set assembled above, with a lease heartbeat around every call.
        $saleProducts = $products + $variableRoots + $groupedRoots + $attributeRoots;
        $saleIds = [];
        foreach (array_merge(
            array_keys($saleProducts),
            array_keys($deletionIds)
        ) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $saleIds[$id] = $id;
            }
        }
        ksort($saleIds, SORT_NUMERIC);
        $this->schedule_sale_events(array_values($saleIds), $deletionIds, $saleProducts, $heartbeat);

        $this->verify_exact_state(
            $productStore,
            $products,
            $variableRoots,
            $deletionIds,
            $heartbeat
        );
        $this->verify_sale_schedules(array_values($saleIds), $deletionIds, $saleProducts, $heartbeat);
        $this->download_directory_state($liveIds, true);

        // product_visibility is a mixed native projection: featured and the
        // two catalog-exclusion terms encode authored merchant intent, while
        // outofstock/rated-* are rebuilt from the product state after every
        // native price/lookup synchronization above. POS visibility is
        // authored only on supported roots and every variation inherits the
        // root. This final provider step rechecks the pre-repair intent, uses
        // WordPress's taxonomy writer, then verifies raw identities plus WC
        // CRUD semantics so a competing write anywhere in the invocation
        // cannot be blessed by the receipt.
        $this->reconcile_visibility($visibilityIntent, $heartbeat);
    }

    /** @param array<int,object> $roots */
    private function rebuild_attribute_lookups(array $roots, ?callable $heartbeat = null): void {
        $class = '\\Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore';
        $container = wc_get_container();
        if (!is_object($container) || !is_callable([$container, 'get'])) {
            throw new \RuntimeException(
                'duo: WooCommerce dependency container is unavailable for product attribute lookup repair'
            );
        }
        $store = $container->get($class);
        foreach (['create_data_for_product', 'get_last_create_operation_failed'] as $method) {
            if (!is_object($store) || !is_callable([$store, $method])) {
                throw new \RuntimeException(
                    "duo: WooCommerce product attribute lookup store lacks public $method(); "
                    . 'the installed WooCommerce version is outside the adapter contract'
                );
            }
        }
        ksort($roots, SORT_NUMERIC);
        foreach ($roots as $rootId => $root) {
            $rootId = (int) $rootId;
            if ($rootId <= 0 || !is_object($root) || !is_callable([$root, 'get_id'])
                || (int) $root->get_id() !== $rootId) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup repair received an invalid scoped root'
                );
            }
            $this->heartbeat($heartbeat);
            $store->create_data_for_product($root, false);
            if ($store->get_last_create_operation_failed() !== false) {
                throw new \RuntimeException(
                    "duo: WooCommerce product attribute lookup regeneration failed for product $rootId; recovery_required"
                );
            }
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * Woo's public deletion callback schedules its internal ACTION_DELETE and
     * therefore cannot close a synchronous promotion. Delete only rows whose
     * exact product/root ids are present in the engine's bounded tombstone
     * inventory, then let any still-live parent root regenerate natively.
     *
     * @param list<int> $ids
     */
    private function delete_attribute_lookup_rows(array $ids, ?callable $heartbeat = null): void {
        global $wpdb;
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        $table = $this->prefixed_table(self::ATTRIBUTE_LOOKUP);
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM `$table` "
                . "WHERE product_id IN ($placeholders) OR product_or_parent_id IN ($placeholders)",
                ...array_merge($chunk, $chunk)
            ));
            if ($result === false) {
                throw new \RuntimeException(
                    'duo: WooCommerce product attribute lookup deletion failed for the bounded tombstone batch; '
                    . 'recovery_required'
                );
            }
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * Re-read grouped roots and children after variable price synthesis. The
     * grouped data store loads children through wc_get_product(), so cache
     * invalidation must happen after product-variable sync_price(), not only
     * while the initial graph is collected.
     */
    private function refresh_grouped_children_for_sync(
        array &$groupedRoots,
        array &$variableRoots,
        array &$attributeRoots,
        array &$products,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        foreach ($groupedRoots as $rootId => $root) {
            $rootId = (int) $rootId;
            if ($rootId <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches($rootId);
            $freshRoot = $this->load_product($rootId);
            if (!$freshRoot || !$this->is_grouped($freshRoot)) {
                throw new \RuntimeException(
                    "duo: grouped product $rootId disappeared before public grouped price synchronization"
                );
            }
            $groupedRoots[$rootId] = $freshRoot;
            $attributeRoots[$rootId] = $freshRoot;
            $products[$rootId] = $freshRoot;
            foreach ((array) $freshRoot->get_children() as $childId) {
                $childId = (int) $childId;
                if ($childId <= 0 || isset($deletionIds[$childId])) {
                    continue;
                }
                $this->heartbeat($heartbeat);
                $this->invalidate_product_caches($childId);
                $child = $this->load_product($childId);
                if ($child) {
                    $products[$childId] = $child;
                    if ($this->is_variable($child)) {
                        $variableRoots[$childId] = $child;
                        $attributeRoots[$childId] = $child;
                    }
                }
                $this->heartbeat($heartbeat);
            }
            $this->heartbeat($heartbeat);
        }
    }

    private function heartbeat(?callable $heartbeat): void {
        if ($heartbeat !== null) {
            $heartbeat();
        }
    }

    /**
     * Reconcile per-product sale actions through Woo's public bounded API.
     * Deleted ids use Action Scheduler's public unschedule boundary because
     * Woo's maybe-schedule helper returns before clearing actions when the
     * product no longer exists.
     *
     * @param list<int> $saleIds
     * @param array<int,int> $deletionIds
     * @param array<int,mixed> $products
     */
    private function schedule_sale_events(
        array $saleIds,
        array $deletionIds,
        array $products,
        ?callable $heartbeat = null
    ): void {
        foreach ($saleIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            if (isset($deletionIds[$id])) {
                foreach (['wc_product_start_scheduled_sale', 'wc_product_end_scheduled_sale'] as $hook) {
                    $cleared = \as_unschedule_all_actions($hook, ['product_id' => $id], 'woocommerce-sales');
                    if ($cleared === false) {
                        throw new \RuntimeException(
                            "duo: WooCommerce sale-action cleanup failed for deleted product $id"
                        );
                    }
                    $this->heartbeat($heartbeat);
                }
                continue;
            }
            $product = $products[$id] ?? null;
            if (!is_object($product)) {
                $product = \wc_get_product($id);
            }
            if (!is_object($product)) {
                throw new \RuntimeException(
                    "duo: WooCommerce sale scheduling could not load affected product $id"
                );
            }
            \wc_maybe_schedule_product_sale_events($id, $product);
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * Verify both exact hook presence/absence and the timestamp derived from
     * the product's current sale dates. This is intentionally a bounded
     * Action Scheduler read; no catalog-wide scheduled-sale scan is used.
     *
     * @param list<int> $saleIds
     * @param array<int,int> $deletionIds
     * @param array<int,mixed> $products
     */
    private function verify_sale_schedules(
        array $saleIds,
        array $deletionIds,
        array $products,
        ?callable $heartbeat = null
    ): void {
        foreach ($saleIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $product = isset($deletionIds[$id]) ? false : ($products[$id] ?? null);
            if ($product === null) {
                $product = \wc_get_product($id);
            }
            $expected = [
                'wc_product_start_scheduled_sale' => null,
                'wc_product_end_scheduled_sale' => null,
            ];
            if (is_object($product)) {
                if (!is_callable([$product, 'get_date_on_sale_from'])
                    || !is_callable([$product, 'get_date_on_sale_to'])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce sale scheduling verification API is unavailable for product $id"
                    );
                }
                $expected['wc_product_start_scheduled_sale'] = $this->future_sale_timestamp(
                    $product->get_date_on_sale_from('edit')
                );
                $expected['wc_product_end_scheduled_sale'] = $this->future_sale_timestamp(
                    $product->get_date_on_sale_to('edit')
                );
            } elseif (!isset($deletionIds[$id])) {
                throw new \RuntimeException(
                    "duo: WooCommerce sale scheduling verification could not load affected product $id"
                );
            }

            foreach ($expected as $hook => $timestamp) {
                $actual = $this->sale_action_state($id, $hook);
                $this->heartbeat($heartbeat);
                if ($timestamp === null) {
                    if ($actual['count'] !== 0 || $actual['next'] !== 'absent') {
                        throw new \RuntimeException(
                            "duo: WooCommerce sale schedule verification found unexpected or duplicate $hook for product $id"
                        );
                    }
                    continue;
                }
                if ($actual['count'] !== 1 || $actual['next'] !== 'timestamp:' . $timestamp) {
                    throw new \RuntimeException(
                        "duo: WooCommerce sale schedule verification cardinality or timestamp mismatch for product $id ($hook)"
                    );
                }
            }
        }
    }

    private function future_sale_timestamp(mixed $date): ?int {
        if (!is_object($date) || !is_callable([$date, 'getTimestamp'])) {
            return null;
        }
        $timestamp = (int) $date->getTimestamp();
        return $timestamp > time() ? $timestamp : null;
    }

    /**
     * Invalidate WooCommerce's layered-navigation count transients for every
     * product object participating in this bounded batch.  The public Woo
     * boundary accepts attribute keys (for example `pa_color`), not product
     * ids; preserve those concrete keys so an effect observer can reconcile
     * the exact dynamic transient rather than treating it as a wildcard.
     *
     * @param array<int,mixed> $products
     * @param list<string> $deletionAttributeKeys Registered taxonomy keys used
     *   as a bounded fallback when a deleted product can no longer be loaded.
     */
    private function invalidate_attribute_counts(array $products, array $deletionAttributeKeys = []): void {
        if (!class_exists('WC_Cache_Helper')
            || !is_callable(['WC_Cache_Helper', 'invalidate_attribute_count'])) {
            return;
        }
        $seenProducts = [];
        foreach ($products as $product) {
            if (!is_object($product)
                || !is_callable([$product, 'get_id'])
                || !is_callable([$product, 'get_attributes'])) {
                continue;
            }
            $id = (int) $product->get_id();
            if ($id <= 0 || isset($seenProducts[$id])) {
                continue;
            }
            $seenProducts[$id] = true;
            // This exact Woo 11.x public call is intentionally kept visible
            // to the runtime effect observer. Do not collapse the keys into
            // a generic transient namespace.
            \WC_Cache_Helper::invalidate_attribute_count(array_keys((array) $product->get_attributes()));
        }
        if ($deletionAttributeKeys !== []) {
            // A deleted product has no WC_Product object after raw SQL.  Its
            // former attribute keys are therefore unavailable to this
            // adapter; invalidate the finite, currently registered Woo
            // taxonomy set rather than leaving one potentially stale count
            // alive.  The runtime observer receives each concrete taxonomy
            // key and can reconcile it individually.
            \WC_Cache_Helper::invalidate_attribute_count(array_values(array_unique(array_map(
                'strval',
                $deletionAttributeKeys
            ))));
        }
    }

    /**
     * Let WooCommerce own parent price synthesis while preserving the exact
     * authored metadata boundary declared by this adapter.
     *
     * WooCommerce 11.x's public variable/grouped sync_price() methods derive
     * _price and unconditionally delete parent _regular_price/_sale_price.
     * Those two keys are repository-authored here, so intercept only those
     * exact deletion attempts through WordPress's public metadata filter. This
     * keeps absent, empty, duplicate and multiple row shapes byte-identical
     * without snapshotting, restoring, or copying any Woo price rule. The
     * filter is synchronous and removed even when Woo's call throws. The
     * provider runs the public call in its own database transaction because
     * provider dispatch happens after Apply's authored-state commit; a failed
     * sync must not leave a durable partial derived-price mutation.
     */
    private function sync_parent_price_from_woocommerce(object $store, object $product): void {
        global $wpdb;
        $id = (int) $product->get_id();
        $inTransaction = \Duo\ProviderSdk::checked_get_var(
            'SELECT @@in_transaction',
            "transaction-state inspection for product $id"
        );
        if ($inTransaction === null) {
            throw new \RuntimeException(
                "duo: transaction-state inspection returned no value for WooCommerce product $id"
            );
        }
        if ((string) $inTransaction === '1') {
            throw new \RuntimeException(
                "duo: cannot establish an atomic WooCommerce price sync boundary for product $id inside an active transaction"
            );
        }
        if (!is_callable([$wpdb, 'query']) || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException(
                "duo: could not start an atomic WooCommerce price sync transaction for product $id"
            );
        }
        $transactionActive = true;
        $preserveAuthoredParentPrice = static function (
            mixed $check,
            int $objectId,
            string $metaKey,
            mixed $_metaValue,
            bool $_deleteAll
        ) use ($id): mixed {
            if ($objectId === $id && in_array($metaKey, ['_regular_price', '_sale_price'], true)) {
                return true;
            }
            return $check;
        };
        add_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX, 5);
        try {
            $store->sync_price($product);
            remove_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX);
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException(
                    "duo: could not commit the atomic WooCommerce price sync transaction for product $id"
                );
            }
            $transactionActive = false;
        } catch (\Throwable $failure) {
            remove_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX);
            if ($transactionActive && $wpdb->query('ROLLBACK') === false) {
                throw new \RuntimeException(
                    "duo: WooCommerce price sync failed and its transaction could not be rolled back for product $id",
                    0,
                    $failure
                );
            }
            throw $failure;
        } finally {
            remove_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX);
            $this->invalidate_product_caches($id);
        }
    }

    /** Load only the public class resolved from the exact authored product_type relationship. */
    private function load_product(int $id): object|false {
        return \wc_get_product($id);
    }

    /**
     * Make Woo's runtime taxonomy registry reflect definitions applied after
     * plugin init. WooCommerce itself registers pa_* taxonomies during init
     * from wc_get_attribute_taxonomies(); Duo's typed-table apply can land a
     * new definition later in the same request. The public cache invalidators
     * plus WooCommerce 11.0.x's own derived register_taxonomy() arguments and
     * filters restore the same product/cache/visibility contract for the
     * remainder of this bounded product repair. This is deliberately kept in
     * the version-pinned manifest provider rather than generic engine code.
     */
    /** @return list<string> registered Woo attribute taxonomy names */
    private function refresh_attribute_taxonomy_registry(): array {
        delete_transient('wc_attribute_taxonomies');
        if (!is_callable(['\\WC_Cache_Helper', 'invalidate_cache_group'])) {
            throw new \RuntimeException(
                'duo: WooCommerce cache helper lacks invalidate_cache_group(); cannot refresh attribute taxonomy registration'
            );
        }
        \WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');

        $attributes = (array) wc_get_attribute_taxonomies();

        // wc_get_permalink_structure() normalizes and persists the whole
        // woocommerce_permalinks option when defaults are missing. This
        // post-apply registry repair only needs Woo 11.0.x's derived
        // attribute rewrite base, so reproduce that option-write-free projection without
        // turning taxonomy registration into an unrelated option write.
        $savedPermalinks = (array) get_option('woocommerce_permalinks', []);
        $permalinks = wp_parse_args(
            array_filter($savedPermalinks),
            ['attribute_base' => '']
        );
        $attributeRewriteSlug = untrailingslashit($permalinks['attribute_base']);
        $registeredTaxonomies = [];
        global $wc_product_attributes;
        if (!is_array($wc_product_attributes)) {
            $wc_product_attributes = [];
        }
        foreach ($attributes as $attribute) {
            if (!is_object($attribute)) {
                continue;
            }
            $attributeName = (string) ($attribute->attribute_name ?? '');
            if ($attributeName === '') {
                continue;
            }
            $taxonomy = (string) wc_attribute_taxonomy_name($attributeName);
            if ($taxonomy === '') {
                continue;
            }

            // Keep this derivation byte-for-byte aligned with the dynamic
            // attribute block in WC_Post_Types::register_taxonomies() for the
            // certified WooCommerce 11.0.x surface. In particular, omitted
            // register_taxonomy() defaults are public/queryable/rewriteable;
            // every visibility field must therefore be explicit here.
            $attribute->attribute_public = absint(
                isset($attribute->attribute_public) ? $attribute->attribute_public : 1
            );
            $label = !empty($attribute->attribute_label)
                ? (string) $attribute->attribute_label
                : $attributeName;
            $wc_product_attributes[$taxonomy] = $attribute;
            if (taxonomy_exists($taxonomy)) {
                $registeredTaxonomies[$taxonomy] = $taxonomy;
                continue;
            }
            $taxonomyData = [
                'hierarchical' => false,
                'update_count_callback' => '_update_post_term_count',
                'labels' => [
                    'name' => sprintf(_x('Product %s', 'Product Attribute', 'woocommerce'), $label),
                    'singular_name' => $label,
                    'search_items' => sprintf(__('Search %s', 'woocommerce'), $label),
                    'all_items' => sprintf(__('All %s', 'woocommerce'), $label),
                    'parent_item' => sprintf(__('Parent %s', 'woocommerce'), $label),
                    'parent_item_colon' => sprintf(__('Parent %s:', 'woocommerce'), $label),
                    'edit_item' => sprintf(__('Edit %s', 'woocommerce'), $label),
                    'update_item' => sprintf(__('Update %s', 'woocommerce'), $label),
                    'add_new_item' => sprintf(__('Add new %s', 'woocommerce'), $label),
                    'new_item_name' => sprintf(__('New %s', 'woocommerce'), $label),
                    'not_found' => sprintf(__('No &quot;%s&quot; found', 'woocommerce'), $label),
                    'back_to_items' => sprintf(__('&larr; Back to "%s" attributes', 'woocommerce'), $label),
                ],
                'show_ui' => true,
                'show_in_quick_edit' => false,
                'show_in_menu' => false,
                'meta_box_cb' => false,
                'query_var' => 1 === $attribute->attribute_public,
                'rewrite' => false,
                'sort' => false,
                'public' => 1 === $attribute->attribute_public,
                'show_in_nav_menus' => 1 === $attribute->attribute_public
                    && apply_filters('woocommerce_attribute_show_in_nav_menus', false, $taxonomy),
                'capabilities' => [
                    'manage_terms' => 'manage_product_terms',
                    'edit_terms' => 'edit_product_terms',
                    'delete_terms' => 'delete_product_terms',
                    'assign_terms' => 'assign_product_terms',
                ],
            ];
            if (1 === $attribute->attribute_public && sanitize_title($attributeName)) {
                $taxonomyData['rewrite'] = [
                    'slug' => trailingslashit($attributeRewriteSlug)
                        . urldecode(sanitize_title($attributeName)),
                    'with_front' => false,
                    'hierarchical' => true,
                ];
            }
            $registered = register_taxonomy(
                $taxonomy,
                apply_filters("woocommerce_taxonomy_objects_{$taxonomy}", ['product']),
                apply_filters("woocommerce_taxonomy_args_{$taxonomy}", $taxonomyData)
            );
            if (is_wp_error($registered) || !taxonomy_exists($taxonomy)) {
                throw new \RuntimeException(
                    "duo: WooCommerce attribute taxonomy '$taxonomy' could not be registered for product-object reconciliation"
                );
            }
            $registeredTaxonomies[$taxonomy] = $taxonomy;
        }
        return array_values($registeredTaxonomies);
    }

    /**
     * Ask a WooCommerce data store whether it really implements a method.
     *
     * WC_Data_Store is a proxy that defines __call(), so is_callable() on one
     * of its instances is true for every name and proves nothing about the
     * store it wraps — a version-compatibility guard written that way passes
     * right up to the fatal. WooCommerce exposes has_callable() for exactly
     * this question and asks the wrapped instance. The is_callable() fallback
     * is for the plugin objects reached outside the data-store registry (the
     * ProductAttributesLookup store, WC_Product instances), which are ordinary
     * objects where it is a real check.
     */
    private function store_has(object $store, string $method): bool {
        return is_callable([$store, 'has_callable'])
            ? (bool) $store->has_callable($method)
            : is_callable([$store, $method]);
    }

    private function delete_meta_lookup(object $store, int $id): void {
        if (!$this->store_has($store, 'delete_from_lookup_table')) {
            throw new \RuntimeException(
                'duo: WooCommerce product data store lacks delete_from_lookup_table(); cannot clean deleted lookup rows'
            );
        }
        $store->delete_from_lookup_table($id, self::META_LOOKUP);
    }

    private function invalidate_product_caches(int $id): void {
        if ($id <= 0) {
            return;
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($id);
        }
        if (class_exists('WC_Cache_Helper')
            && is_callable(['WC_Cache_Helper', 'invalidate_cache_group'])) {
            \WC_Cache_Helper::invalidate_cache_group('product_' . $id);
        }
        // WC_Product_Data_Store_CPT::update_lookup_table() keeps its own
        // derived-row snapshot in this object-scoped cache and otherwise
        // may decide a stale row is already current.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('lookup_table', 'object_' . $id);
        }
        // WooCommerce 11.x's product-instance cache can return a stale
        // WC_Product (especially a variable parent with an old child list)
        // even after the ordinary WP/transient/group invalidators above.
        // Match WC_Product_Data_Store_CPT::clear_caches(): remove this object
        // last, after operations that may themselves re-cache it.
        $productCacheClass = '\\Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
        if (class_exists($productCacheClass) && function_exists('wc_get_container')) {
            try {
                $productCache = \wc_get_container()->get($productCacheClass);
            } catch (\Throwable $t) {
                throw new \RuntimeException('duo: failed to load WooCommerce ProductCache for lookup regeneration', 0, $t);
            }
            if (!is_object($productCache) || !is_callable([$productCache, 'remove'])) {
                throw new \RuntimeException(
                    'duo: WooCommerce ProductCache lacks public remove(); cannot safely read product lookup inputs'
                );
            }
            $productCache->remove($id);
        }
    }

    private function is_variable(object $product): bool {
        return is_callable([$product, 'is_type']) && $product->is_type('variable');
    }

    private function is_grouped(object $product): bool {
        return is_callable([$product, 'is_type']) && $product->is_type('grouped');
    }

    /**
     * Find grouped roots that reference one changed/deleted child.  Grouped
     * membership is serialized in _children, so this deliberately uses a
     * narrow meta-key/value lookup instead of loading every product.
     *
     * @return array<int,int>
     */
    private function find_grouped_parent_ids(int $childId): array {
        global $wpdb;
        if ($childId <= 0) {
            return [];
        }
        $integerNeedle = '%i:' . $childId . ';%';
        $stringNeedle = '%s:' . strlen((string) $childId) . ':"' . $childId . '";%';
        $rows = \Duo\ProviderSdk::checked_get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_children'
               AND p.post_type = 'product'
               AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)
             ORDER BY pm.post_id ASC
             LIMIT " . (self::MAX_GROUPED_PARENTS + 1),
            $integerNeedle,
            $stringNeedle
        ), 'grouped parent discovery');
        if (count($rows) > self::MAX_GROUPED_PARENTS) {
            throw new \RuntimeException(
                'duo: WooCommerce grouped parent discovery exceeds its bounded owner scope'
            );
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = $this->strict_positive_db_uint($row, 'grouped parent ID');
            if (isset($ids[$id])) {
                throw new \RuntimeException(
                    'duo: WooCommerce grouped parent discovery returned a duplicate owner'
                );
            }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function collect_grouped_root(
        object $root,
        array &$groupedRoots,
        array &$variableRoots,
        array &$attributeRoots,
        array &$products,
        array &$priceIds,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        $rootId = (int) $root->get_id();
        if ($rootId <= 0) {
            return;
        }
        $groupedRoots[$rootId] = $root;
        $attributeRoots[$rootId] = $root;
        $products[$rootId] = $root;
        $priceIds[$rootId] = true;
        foreach ((array) $root->get_children() as $childId) {
            $childId = (int) $childId;
            if ($childId <= 0 || isset($deletionIds[$childId])) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches($childId);
            $child = $this->load_product($childId);
            if ($child) {
                $products[$childId] = $child;
                $priceIds[$childId] = true;
                if ($this->is_variable($child)) {
                    $variableRoots[$childId] = $child;
                    $attributeRoots[$childId] = $child;
                    // A grouped child may itself be a variable product. Its
                    // variation prices must be repaired before the grouped
                    // store reads the variable root's effective price.
                    foreach ((array) $child->get_children() as $variationId) {
                        $variationId = (int) $variationId;
                        if ($variationId <= 0 || isset($deletionIds[$variationId])) {
                            continue;
                        }
                        $this->heartbeat($heartbeat);
                        $this->invalidate_product_caches($variationId);
                        $variation = $this->load_product($variationId);
                        if ($variation) {
                            $products[$variationId] = $variation;
                            $priceIds[$variationId] = true;
                        }
                        $this->heartbeat($heartbeat);
                    }
                }
            }
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * Persist the active price WooCommerce derives for one simple product or
     * variation without invoking WC_Product::save().
     *
     * is_on_sale('edit') owns Woo's comparison/date behavior; the regular and
     * sale accessors own the selected authored value. The adapter only
     * materializes that public result into Woo's derived _price key through
     * WordPress metadata APIs. save() is deliberately not the boundary: after
     * raw apply the freshly loaded object has no dirty price props, and a
     * forced save also fires asynchronous lookup hooks.
     */
    private function sync_simple_price_from_woocommerce(object $product): void {
        $id = (int) $product->get_id();
        foreach (['is_on_sale', 'get_sale_price', 'get_regular_price'] as $method) {
            if (!is_callable([$product, $method])) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id lacks public $method(); the installed version is outside the adapter contract"
                );
            }
        }
        $price = $product->is_on_sale('edit')
            ? (string) $product->get_sale_price('edit')
            : (string) $product->get_regular_price('edit');

        if (!delete_post_meta($id, '_price')) {
            $remaining = (array) get_post_meta($id, '_price', false);
            if ($remaining !== []) {
                throw new \RuntimeException("duo: failed to clear stale WooCommerce _price meta for product $id");
            }
        }
        if ($price !== '' && !add_post_meta($id, '_price', $price, false)) {
            throw new \RuntimeException("duo: failed to write WooCommerce-derived _price meta for product $id");
        }
        $this->invalidate_product_caches($id);
    }

    /**
     * Verify lookup values rather than merely checking that a row exists.
     * The Woo data-store calls above are the source of truth for synthesis;
     * these checks catch stale/partial writes and preserve runtime fields by
     * comparing them to target-local postmeta, not repository-authored data.
     */
    private function verify_exact_state(
        object $productStore,
        array $products,
        array $variableRoots,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        global $wpdb;
        $table = $this->prefixed_table(self::META_LOOKUP);
        foreach ($deletionIds as $id) {
            $this->heartbeat($heartbeat);
            $metaCountValue = \Duo\ProviderSdk::checked_get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$table` WHERE product_id = %d",
                (int) $id
            ), "product lookup deletion verification for product $id");
            $metaCount = $this->strict_bounded_db_count(
                $metaCountValue,
                1,
                "product lookup deletion count for product $id"
            );
            if ($metaCount !== 0) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup deletion verification failed for product $id "
                    . "(meta rows=$metaCount)"
                );
            }
            $this->heartbeat($heartbeat);
        }

        foreach ($products as $id => $product) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->verify_meta_row($productStore, $id, $heartbeat);
        }
        foreach ($variableRoots as $id => $root) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->verify_meta_row($productStore, $id, $heartbeat);
            foreach ((array) $root->get_children() as $childId) {
                $childId = (int) $childId;
                if ($childId > 0 && !isset($deletionIds[$childId])) {
                    $this->heartbeat($heartbeat);
                    $this->verify_meta_row($productStore, $childId, $heartbeat);
                }
            }
        }
    }

    /**
     * Prove one wc_product_meta_lookup row is what WooCommerce derives for
     * this product, on both axes that can independently go wrong.
     *
     * Two reads bracket one forced re-derivation, and each proves a different
     * thing:
     *
     *   1. The row the apply left behind is snapshotted BEFORE the refresh and
     *      compared back afterwards, over the columns Woo derives. This is the
     *      check that says "the apply converged this row". It has to be taken
     *      first: the refresh below
     *      runs with a cleared cache, so WC_Data_Store_WP::update_lookup_table()
     *      always REPLACEs, and a readback taken only afterwards would be
     *      reading a row this verification had itself just written — passing
     *      for a row the apply left divergent. The variation path now expands
     *      the loaded variable root's finite child set before this verifier,
     *      so sibling rows converge in the same pass; the before-read remains
     *      required for every row already selected by the batch itself.
     *
     *   2. The derivation WooCommerce published is compared against what is
     *      actually stored after the refresh. update_lookup_table() does not
     *      check $wpdb->replace()'s return value and sets its cache
     *      unconditionally, so a REPLACE that failed leaves Woo advertising a
     *      row that never landed. This is the check that says "Woo's write
     *      landed".
     *
     * Neither comparison rebuilds Woo's column rules, which is the point. This
     * adapter used to: the sku / virtual / onsale / stock / rating / tax column
     * set, a copied Cost of Goods Sold lookup-column gate, the
     * woocommerce_schema_version >= 920 global_unique_id gate, and a
     * per-column tolerance table for
     * reading DECIMAL/BIGINT columns back were a hand-copy of
     * WC_Product_Data_Store_CPT::get_data_for_lookup_table(), a protected
     * method whose shape this adapter is not entitled to depend on. That copy
     * could only drift one of two ways against a Woo point release: silently
     * under-verifying a column it never learned about, or failing an apply over
     * a gate Woo had since changed.
     *
     * A failure here leaves the row itself repaired — the refresh already ran —
     * while the apply fails, which is the same self-healing-on-retry shape the
     * engine's regen_pending markers already assume.
     */
    private function verify_meta_row(object $productStore, int $id, ?callable $heartbeat = null): void {
        global $wpdb;
        $table = $this->prefixed_table(self::META_LOOKUP);

        $applied = $this->read_lookup_row($table, $id);
        if ($applied === null) {
            throw new \RuntimeException("duo: WooCommerce product lookup row missing for product $id");
        }
        $countValue = \Duo\ProviderSdk::checked_get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE product_id = %d",
            $id
        ), "product lookup cardinality verification for product $id");
        $count = $this->strict_bounded_db_count(
            $countValue,
            2,
            "product lookup cardinality for product $id"
        );
        if ($count !== 1) {
            throw new \RuntimeException("duo: WooCommerce product lookup has $count rows for product $id; expected exactly one");
        }

        $derived = $this->woo_republished_lookup_row($productStore, $id);
        $this->heartbeat($heartbeat);
        $stored = $this->read_lookup_row($table, $id);
        if ($stored === null) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup row for product $id disappeared during its own refresh"
            );
        }

        // Scoped to the columns WooCommerce actually derived, deliberately.
        // update_lookup_table() writes with $wpdb->replace(), which is a
        // DELETE plus INSERT, so any column OUTSIDE the derived set — a
        // a column some extension maintains — or a stale cogs_total_value on
        // a product with no authored COGS row in this operation — comes back at its schema
        // default whatever the apply did. Comparing those would turn Woo's own
        // write into a refusal blaming the apply for a divergence it did not
        // cause. They are outside this check's authority; the derivation-bound
        // check below already covers exactly the set Woo does own.
        $this->assert_lookup_row_matches(
            $id,
            $table,
            $stored,
            array_intersect_key($applied, $derived),
            'the apply left this WooCommerce product lookup row divergent from what WooCommerce derives'
        );
        $this->assert_lookup_row_matches(
            $id,
            $table,
            $stored,
            $derived,
            'the WooCommerce product lookup write did not land the values WooCommerce derived'
        );
    }

    /** @return array<string,mixed>|null */
    private function read_lookup_row(string $table, int $id): ?array {
        global $wpdb;
        $projection = implode(', ', array_map(
            static fn(string $column): string => "`$column`",
            $this->lookup_read_columns($table)
        ));
        $row = \Duo\ProviderSdk::checked_get_row($wpdb->prepare(
            "SELECT $projection FROM `$table` WHERE product_id = %d LIMIT 1",
            $id
        ), "product lookup verification for product $id");
        return is_array($row) ? $row : null;
    }

    /**
     * Make WooCommerce re-derive this product's lookup row, and return the
     * derivation it published while doing so.
     *
     * get_data_for_lookup_table() is protected, so its result cannot be asked
     * for directly. It is observable through Woo's own public write path
     * instead: update_lookup_table() stores the row it is about to write under
     * the `lookup_table`/`object_<id>` cache key, and skips both the write and
     * that cache set only when the cache already equals the fresh derivation.
     *
     * Both cache assertions are the mechanism, not defensive noise. A surviving
     * entry means the refresh would short-circuit, so the caller's before/after
     * pair would compare a row with itself and prove nothing; an absent entry
     * afterwards means Woo published no derivation to check the write against.
     * Either one silently empties this verification, so both refuse.
     *
     * The refresh is a deliberate cost: one extra REPLACE per verified product
     * on top of the batch's own refresh pass, over a set bounded by this
     * apply's authored work with a lease heartbeat around every product.
     *
     * @return array<string,mixed>
     */
    private function woo_republished_lookup_row(object $productStore, int $id): array {
        $this->invalidate_product_caches($id);
        if (wp_cache_get('lookup_table', 'object_' . $id) !== false) {
            throw new \RuntimeException(
                "duo: WooCommerce's product lookup derivation cache survived invalidation for product $id; "
                . 'the refresh would short-circuit and the verification below would prove nothing'
            );
        }
        $productStore->refresh_product_lookup_table($id);
        $derived = wp_cache_get('lookup_table', 'object_' . $id);
        if (!is_array($derived) || $derived === []) {
            throw new \RuntimeException(
                "duo: WooCommerce published no product lookup derivation for product $id after a public "
                . 'refresh with a cleared cache; the installed WooCommerce version is outside the adapter contract'
            );
        }
        return $derived;
    }

    /**
     * Compare one set of expected values against the stored row in the
     * database rather than in PHP.
     *
     * The two callers supply values of different provenance — a previous read
     * of this same row, and Woo's PHP-typed derivation (ints, floats, nulls,
     * unformatted decimal strings) — and neither can be compared to a wpdb row
     * of strings and NULLs without a normalization rule. Writing that rule per
     * column is precisely the copied WooCommerce semantics this adapter stopped
     * carrying, so one NULL-safe `<=>` predicate per column delegates it to the
     * column's own type, which is the same coercion $wpdb->replace() applied on
     * the way in.
     *
     * Equality here is therefore the column's, not PHP's: a varchar comparison
     * follows the collation, so on a PAD SPACE collation two skus differing
     * only in trailing spaces would compare equal. That is unreachable through
     * this path — every value bound here was either read from that same column
     * or written to it by Woo — and it is the right definition of "equal" for a
     * table whose only consumers are SQL queries against these columns.
     *
     * An expected column the stored row does not have is a refusal, not a skip.
     * The old row-keyed loop skipped unknown columns, which is what let a
     * schema and an installed WooCommerce disagree without anyone noticing.
     *
     * @param array<string,mixed> $stored the row as currently stored
     * @param array<string,mixed> $expected values that row must already equal
     * @param string $failure what a mismatch means, in the caller's terms
     */
    private function assert_lookup_row_matches(
        int $id,
        string $table,
        array $stored,
        array $expected,
        string $failure
    ): void {
        global $wpdb;
        $columnCasts = $this->lookup_column_casts($table);
        $conditions = ['product_id = %d'];
        $params = [$id];
        foreach ($expected as $column => $value) {
            $column = (string) $column;
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1) {
                throw new \RuntimeException(
                    "duo: unusable WooCommerce product lookup column name '$column' for product $id"
                );
            }
            if (!array_key_exists($column, $stored)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup column '$column' for product $id is absent from $table; "
                    . 'the lookup schema and the installed WooCommerce disagree'
                );
            }
            if ($value === null) {
                $conditions[] = "`$column` IS NULL";
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException(
                    "duo: non-scalar WooCommerce product lookup value for product $id.$column"
                );
            }
            $cast = $columnCasts[$column] ?? '';
            $conditions[] = $cast === ''
                ? "`$column` <=> %s"
                : "`$column` <=> CAST(%s AS $cast)";
            // (string) is how $wpdb->replace() bound this same value on the
            // way in, so ints, floats and false reach MySQL identically here.
            $params[] = (string) $value;
        }
        $matches = \Duo\ProviderSdk::checked_get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $conditions),
            ...$params
        ), "product lookup value verification for product $id");
        $matchCount = $this->strict_bounded_db_count(
            $matches,
            1,
            "product lookup value match count for product $id"
        );
        if ($matchCount === 1) {
            return;
        }
        $columns = array_keys($expected);
        sort($columns, SORT_STRING);
        throw new \RuntimeException(
            "duo: WooCommerce product lookup verification mismatch for product $id — $failure "
            . '(columns=' . count($columns)
            . '; expected_sha256=' . $this->lookup_value_digest($expected, $columns)
            . '; stored_sha256=' . $this->lookup_value_digest($stored, $columns) . ')'
        );
    }

    /** @param array<string,mixed> $values @param list<string> $columns */
    private function lookup_value_digest(array $values, array $columns): string {
        $fingerprint = hash_init('sha256');
        foreach ($columns as $column) {
            $this->fingerprint_part($fingerprint, $column);
            if (!array_key_exists($column, $values)) {
                $this->fingerprint_part($fingerprint, 'absent');
                continue;
            }
            $value = $values[$column];
            if ($value === null) {
                $this->fingerprint_part($fingerprint, 'null');
                continue;
            }
            $this->fingerprint_part($fingerprint, get_debug_type($value));
            $this->fingerprint_part($fingerprint, is_scalar($value) ? (string) $value : 'non-scalar');
        }
        return hash_final($fingerprint);
    }

    /**
     * Return the installed lookup table's assignment casts for fixed-point
     * columns. Woo publishes its pre-insert PHP value in the lookup cache,
     * while MySQL stores that value at the column's declared scale. Comparing
     * the raw cache string to the row rejects a legitimate value such as a
     * six-decimal price in Woo's DECIMAL(19,4) lookup column; comparing it
     * through this exact schema cast models the same coercion Woo's preceding
     * $wpdb->replace() used and still detects a stale or failed write.
     *
     * The type comes from this target's table, not a copied Woo schema. Only a
     * tightly validated DECIMAL declaration becomes SQL; every other type
     * keeps the ordinary parameterized comparison above. An absent expected
     * column is still rejected separately by assert_lookup_row_matches().
     *
     * @return array<string,string> column => DECIMAL(precision,scale)
     */
    private function lookup_column_casts(string $table): array {
        global $wpdb;
        if ($this->lookupColumnCasts !== null) {
            return $this->lookupColumnCasts;
        }
        if (preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $table) !== 1) {
            throw new \RuntimeException('duo: unusable WooCommerce product lookup table name for schema verification');
        }
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            'SELECT BINARY COLUMN_NAME AS Field, LOWER(COLUMN_TYPE) AS Type '
            . 'FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s '
            . 'ORDER BY ORDINAL_POSITION ASC LIMIT ' . (self::MAX_TABLE_COLUMNS + 1),
            $table
        ),
            'product lookup schema verification'
        );
        if (count($rows) > self::MAX_TABLE_COLUMNS) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup schema returned an oversized column inventory'
            );
        }
        $casts = [];
        $seen = [];
        foreach ($rows as $row) {
            $column = $row['Field'] ?? null;
            $rawType = $row['Type'] ?? null;
            if (!is_string($column) || !is_string($rawType)
                || preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1
                || isset($seen[$column]) || strlen($rawType) > 128) {
                throw new \RuntimeException('duo: WooCommerce product lookup schema returned an unusable column name');
            }
            $seen[$column] = true;
            $type = strtolower(trim($rawType));
            $coreType = self::LOOKUP_CORE_COLUMN_TYPES[$column] ?? null;
            if (is_string($coreType) && preg_match($coreType, $type) !== 1) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup core column '$column' has an incompatible type"
                );
            }
            if (preg_match('/^decimal\(([1-9][0-9]?),([0-9]{1,2})\)(?: unsigned)?$/D', $type, $matches) !== 1) {
                continue;
            }
            $precision = (int) $matches[1];
            $scale = (int) $matches[2];
            if ($precision > 65 || $scale > 30 || $scale > $precision) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup column '$column' has an unsupported DECIMAL declaration"
                );
            }
            $casts[$column] = "DECIMAL($precision,$scale)";
        }
        $required = array_keys(self::LOOKUP_CORE_COLUMN_TYPES);
        $required = array_values(array_diff($required, ['cogs_total_value']));
        if (array_diff($required, array_keys($seen)) !== []) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup schema is missing one or more exact core columns'
            );
        }
        $readColumns = [];
        foreach (array_keys(self::LOOKUP_CORE_COLUMN_TYPES) as $column) {
            if (isset($seen[$column])) {
                $readColumns[] = $column;
            }
        }
        $this->lookupColumnCasts = $casts;
        $this->lookupReadColumns = $readColumns;
        return $casts;
    }

    /** @return list<string> */
    private function lookup_read_columns(string $table): array {
        $this->lookup_column_casts($table);
        if (!is_array($this->lookupReadColumns) || $this->lookupReadColumns === []) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup schema produced no bounded core read projection'
            );
        }
        return $this->lookupReadColumns;
    }

    private function prefixed_table(string $suffix): string {
        global $wpdb;
        $table = is_object($wpdb) && is_string($wpdb->prefix ?? null)
            ? $wpdb->prefix . $suffix
            : '';
        if (preg_match(self::TABLE_IDENTIFIER_PATTERN, $table) !== 1) {
            throw new \RuntimeException('duo: unusable WooCommerce product lookup table name');
        }
        return $table;
    }

    /** @param list<int> $ids */
    private function assert_scoped_product_count(array $ids, string $context): void {
        if (count($ids) > self::MAX_SCOPED_PRODUCTS) {
            throw new \RuntimeException("duo: WooCommerce $context exceeds its bounded product scope");
        }
    }

    private function strict_positive_db_uint(mixed $value, string $context): int {
        $number = $this->strict_nonnegative_db_uint($value, $context);
        if ($number < 1) {
            throw new \RuntimeException("duo: WooCommerce $context is not a positive database integer");
        }
        return $number;
    }

    private function strict_nonnegative_db_uint(mixed $value, string $context): int {
        if (!is_int($value) && !is_string($value)) {
            throw new \RuntimeException("duo: WooCommerce $context is not a canonical database integer");
        }
        $raw = (string) $value;
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $raw) !== 1
            || strlen($raw) > strlen((string) PHP_INT_MAX)) {
            throw new \RuntimeException("duo: WooCommerce $context is not a canonical database integer");
        }
        $number = (int) $raw;
        if ($number < 0 || (string) $number !== $raw) {
            throw new \RuntimeException("duo: WooCommerce $context exceeds the supported integer boundary");
        }
        return $number;
    }

    private function strict_bounded_db_count(mixed $value, int $maximum, string $context): int {
        $count = $this->strict_nonnegative_db_uint($value, $context);
        if ($count > $maximum) {
            throw new \RuntimeException("duo: WooCommerce $context exceeds its bounded count");
        }
        return $count;
    }

    private function strict_db_sha256(mixed $value, string $context): string {
        if (!is_string($value) || preg_match('/^[a-fA-F0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException("duo: WooCommerce $context is not an exact SHA-256 witness");
        }
        return strtolower($value);
    }

}
