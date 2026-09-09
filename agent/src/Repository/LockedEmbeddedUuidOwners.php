<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';
require_once __DIR__ . '/../Kernel/DatabaseLockBoundary.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';
require_once __DIR__ . '/../Kernel/Uuid.php';

/** Bounded reserved-UUID ranges and live-owner gaps, without ownership policy. */
final readonly class LockedEmbeddedUuidOwners {
    private const IDENTITY_KEY = '_wprism_uuid';
    private const MAX_IDENTITY_ROWS = 100000;
    private const OWNER_LOCK_CHUNK = 500;
    private const MAX_REQUEST_UUIDS = 16;

    /** @param array<string,string> $tables @param array<string,string> $indexes */
    private function __construct(
        private TransactionAuthority $authority,
        private string $subject,
        private array $tables,
        private array $indexes
    ) {}

    /**
     * Prove physical/index premises without taking the semantic row locks.
     * The caller can therefore retain map -> post -> identity-range order.
     */
    public static function prepare(TransactionAuthority $authority, string $subject): self {
        if (preg_match('/^[a-z][a-z0-9 -]{0,95}$/D', $subject) !== 1) {
            throw new \InvalidArgumentException('locked embedded identity subject is malformed');
        }
        $purpose = $subject . ' locking';
        $continuity = static fn() => self::assert_same_authority($authority, $purpose . ' continuity');
        $continuity();
        $tables = self::current_tables();
        DatabaseLockBoundary::assert_innodb_tables(array_values($tables), $purpose, $continuity);
        $indexes = [
            'post' => DatabaseLockBoundary::full_width_lock_index($tables['post'], 'ID', $purpose, true, $continuity),
            'term' => DatabaseLockBoundary::full_width_lock_index($tables['term'], 'term_id', $purpose, true, $continuity),
            'postmeta' => DatabaseLockBoundary::bounded_prefix_lock_index(
                $tables['postmeta'], 'meta_key', strlen(self::IDENTITY_KEY), $purpose, $continuity
            ),
            'termmeta' => DatabaseLockBoundary::bounded_prefix_lock_index(
                $tables['termmeta'], 'meta_key', strlen(self::IDENTITY_KEY), $purpose, $continuity
            ),
        ];
        $prepared = new self($authority, $subject, $tables, $indexes);
        $prepared->assert_current('prepared identity indices');
        return $prepared;
    }

    public function post_index(): string {
        $this->assert_current('post index handoff');
        return $this->indexes['post'];
    }

    /**
     * Facts only: Apply requires one owner; an explicit fork may admit two.
     * Global row shape is validated, but unrelated UUID values are not given
     * new semantics. In particular, collation-equal aliases remain visible to
     * the caller rather than disappearing behind a binary-only SQL filter.
     * Returned arrays are observations, not durable or reusable authority.
     *
     * @param list<string> $uuids
     * @return array{
     *   post_rows:list<array<string,mixed>>,term_rows:list<array<string,mixed>>,
     *   live_post_owners:array<int,true>,live_term_owners:array<int,true>
     * }
     */
    public function lock(array $uuids): array {
        if (!array_is_list($uuids) || $uuids === [] || count($uuids) > self::MAX_REQUEST_UUIDS) {
            throw new \InvalidArgumentException('locked embedded identity request is outside its UUID bound');
        }
        foreach ($uuids as $uuid) {
            if (!is_string($uuid) || strlen($uuid) !== 36 || !Uuid::is($uuid)) {
                throw new \InvalidArgumentException('locked embedded identity request has a malformed UUID');
            }
        }
        if (count(array_unique($uuids, SORT_STRING)) !== count($uuids)) {
            throw new \InvalidArgumentException('locked embedded identity request repeats a UUID');
        }
        $this->assert_current('identity range preflight');
        $postRows = $this->locked_meta_rows($this->tables['postmeta'], 'post_id', $this->indexes['postmeta']);
        $termRows = $this->locked_meta_rows($this->tables['termmeta'], 'term_id', $this->indexes['termmeta']);
        $livePost = $this->locked_live_owner_ids(
            $this->tables['post'], 'ID', $this->indexes['post'], $this->matching_owner_ids($postRows, $uuids)
        );
        $liveTerm = $this->locked_live_owner_ids(
            $this->tables['term'], 'term_id', $this->indexes['term'], $this->matching_owner_ids($termRows, $uuids)
        );
        $this->assert_current('identity range postflight');
        return [
            'post_rows' => $postRows,
            'term_rows' => $termRows,
            'live_post_owners' => $livePost,
            'live_term_owners' => $liveTerm,
        ];
    }

    /** @return list<array<string,mixed>> */
    private function locked_meta_rows(string $table, string $ownerColumn, string $index): array {
        global $wpdb;
        $rows = $this->checked_rows($wpdb->prepare(
            "SELECT meta_id, `$ownerColumn` AS owner_id, meta_key, "
            . 'LEFT(meta_value, 37) AS meta_value_prefix, OCTET_LENGTH(meta_value) AS meta_value_bytes '
            . "FROM `$table` FORCE INDEX (`$index`) WHERE meta_key = %s "
            . 'ORDER BY meta_id ASC LIMIT ' . (self::MAX_IDENTITY_ROWS + 1) . ' FOR UPDATE',
            self::IDENTITY_KEY
        ), "locked $table identity range");
        if (count($rows) > self::MAX_IDENTITY_ROWS) {
            throw new \RuntimeException("wprism: {$this->subject} range exceeded its bounded row limit");
        }
        $previousMetaId = 0;
        foreach ($rows as $position => $row) {
            $metaId = is_array($row) ? MetaRows::positive_id($row['meta_id'] ?? null) : null;
            $ownerId = is_array($row) ? MetaRows::positive_id($row['owner_id'] ?? null) : null;
            $valueBytes = is_array($row) ? ($row['meta_value_bytes'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'owner_id', 'meta_key', 'meta_value_prefix', 'meta_value_bytes']
                || $metaId === null
                || $ownerId === null
                || $metaId <= $previousMetaId
                || !is_string($row['meta_key'] ?? null)
                || strlen($row['meta_key']) > 1020
                || !(is_string($row['meta_value_prefix'] ?? null) || ($row['meta_value_prefix'] ?? null) === null)
                || !(is_string($valueBytes) || $valueBytes === null)
                || (is_string($valueBytes) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $valueBytes) !== 1)) {
                throw new \RuntimeException(
                    "wprism: {$this->subject} range returned a malformed row at bounded position $position"
                );
            }
            $previousMetaId = $metaId;
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param list<string> $uuids @return list<int> */
    private function matching_owner_ids(array $rows, array $uuids): array {
        $requested = array_fill_keys($uuids, true);
        $ownerIds = [];
        foreach ($rows as $row) {
            if (($row['meta_key'] ?? null) !== self::IDENTITY_KEY
                || ($row['meta_value_bytes'] ?? null) !== '36'
                || !is_string($row['meta_value_prefix'] ?? null)
                || !isset($requested[$row['meta_value_prefix']])) {
                continue;
            }
            $ownerId = MetaRows::positive_id($row['owner_id'] ?? null);
            if ($ownerId === null) {
                throw new \RuntimeException("wprism: {$this->subject} range lost a validated owner");
            }
            $ownerIds[$ownerId] = true;
        }
        $ids = array_keys($ownerIds);
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /**
     * Lock each matching owner or unique-PK absence gap after the metadata
     * ranges. An orphan cannot become live, nor a live duplicate disappear,
     * while the caller classifies this observation in the same transaction.
     *
     * @param list<int> $ownerIds
     * @return array<int,true>
     */
    private function locked_live_owner_ids(string $table, string $primaryKey, string $index, array $ownerIds): array {
        global $wpdb;
        if ($ownerIds === []) {
            return [];
        }
        $requested = array_fill_keys($ownerIds, true);
        $live = [];
        foreach (array_chunk($ownerIds, self::OWNER_LOCK_CHUNK) as $chunkOffset => $chunk) {
            $chunkSet = array_fill_keys($chunk, true);
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            $rows = $this->checked_rows($wpdb->prepare(
                "SELECT `$primaryKey` AS owner_id FROM `$table` FORCE INDEX (`$index`) "
                . "WHERE `$primaryKey` IN ($placeholders) ORDER BY `$primaryKey` ASC LIMIT "
                . (count($chunk) + 1) . ' FOR UPDATE',
                ...$chunk
            ), "locked $table live-owner lookup");
            if (count($rows) > count($chunk)) {
                throw new \RuntimeException(
                    "wprism: {$this->subject} $table live-owner lookup exceeded its chunk bound"
                );
            }
            $previous = 0;
            foreach ($rows as $position => $row) {
                $ownerId = is_array($row) ? MetaRows::positive_id($row['owner_id'] ?? null) : null;
                if (!is_array($row)
                    || array_keys($row) !== ['owner_id']
                    || $ownerId === null
                    || !isset($requested[$ownerId])
                    || !isset($chunkSet[$ownerId])
                    || $ownerId <= $previous) {
                    $boundedPosition = ($chunkOffset * self::OWNER_LOCK_CHUNK) + $position;
                    throw new \RuntimeException(
                        "wprism: {$this->subject} $table live-owner lookup returned a malformed row "
                        . "at bounded position $boundedPosition"
                    );
                }
                $live[$ownerId] = true;
                $previous = $ownerId;
            }
        }
        return $live;
    }

    /** @return list<array<string,mixed>> */
    private function checked_rows(mixed $sql, string $context): array {
        if (!is_string($sql) || $sql === '') {
            throw new \RuntimeException("wprism: {$this->subject} $context failed");
        }
        $this->assert_current($context . ' preflight');
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        $this->assert_current($context . ' postflight');
        if (!is_array($rows) || !array_is_list($rows) || $error !== '') {
            throw new \RuntimeException("wprism: {$this->subject} $context failed");
        }
        return $rows;
    }

    private function assert_current(string $context): void {
        self::assert_same_authority($this->authority, $this->subject . ' ' . $context);
        if ($this->tables !== self::current_tables()) {
            throw new DatabaseTransactionOutcomeException($this->subject . ' changed physical identity table bindings');
        }
    }

    private static function assert_same_authority(TransactionAuthority $expected, string $context): void {
        if (!$expected->equals(Db::repeatable_read_authority($context))) {
            throw new DatabaseTransactionOutcomeException($context . ' changed database session authority');
        }
    }

    /** @return array<string,string> */
    private static function current_tables(): array {
        global $wpdb;
        return [
            'post' => $wpdb->posts,
            'term' => $wpdb->terms,
            'postmeta' => $wpdb->postmeta,
            'termmeta' => $wpdb->termmeta,
        ];
    }
}
