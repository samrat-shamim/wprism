<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ColumnValueCases.php';

require_once __DIR__ . '/../Kernel/TableRowScope.php';

// Production loads close every direct dependency here. Some compiler unit
// fixtures intentionally preload narrow class doubles; honor those isolated
// boundaries without redeclaring the doubles when Snapshot reaches us.
if (!class_exists(PlainData::class, false)) {
    require_once __DIR__ . '/../Kernel/PlainData.php';
}
if (!class_exists(OrderPreserved::class, false)) {
    require_once __DIR__ . '/../Kernel/OrderPreserved.php';
}
if (!class_exists(StructuredValue::class, false)) {
    require_once __DIR__ . '/../Kernel/StructuredValue.php';
}
if (!class_exists(ReferenceRules::class, false)) {
    require_once __DIR__ . '/../Kernel/ReferenceRules.php';
}
if (!class_exists(Secrets::class, false)) {
    require_once __DIR__ . '/../Kernel/Secrets.php';
}
if (!class_exists(PersonalData::class, false)) {
    require_once __DIR__ . '/../Kernel/PersonalData.php';
}
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(ColumnCodecGrammar::class, false)) {
    require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
}

/**
 * Read-side capture boundary for authored typed tables (issue #3349).
 *
 * This class owns the complete live-row-to-canonical-entity pipeline for
 * ordinary rows, composite-reference rows, and attached EAV sidecars. It is
 * intentionally downstream of declaration discovery, graph ordering, live
 * schema validation, and bounded-keyspace checks: Snapshot performs those
 * preflights once, then delegates each validated table here.
 *
 * Runtime identity and ledger capabilities are injected. The boundary can
 * therefore be loaded and exercised without Snapshot, Policy, Ledger,
 * IdentityNotes, Tokens, UUID services, or WordPress bootstrap code; only a
 * wpdb-shaped read handle and a token-shaped object are needed at execution.
 */
final class TypedTableCapture {
    private object $identity;
    private \Closure $recordCompositeMapping;
    private \Closure $identityNote;
    private \Closure $slugify;

    public function __construct(
        object $identity,
        \Closure $recordCompositeMapping,
        \Closure $identityNote,
        \Closure $slugify
    ) {
        $this->identity = $identity;
        $this->recordCompositeMapping = $recordCompositeMapping;
        $this->identityNote = $identityNote;
        $this->slugify = $slugify;
    }

    /**
     * Capture one validated row table into Capture-compatible entity records.
     *
     * `$columnCodecs` is the table's own `column_codecs` projection (WP-6.1),
     * passed explicitly rather than folded into `$decl`: the section is
     * declared at the manifest's top level so that § v3.2 can stage it, and
     * merging it into the table declaration here would make a codec
     * indistinguishable from a field of `tables` that an older engine ignores —
     * the exact silence the staging exists to remove.
     *
     * @param array<string,array> $metaDecls attached-meta declarations keyed by table
     * @param array<string,array{container:string,leaves:string}> $columnCodecs
     * @return array<int,array{uuid:string,type:string,path:string,content:string}>
     */
    public function capture_table(
        string $table,
        array $decl,
        array $metaDecls,
        object $tokens,
        bool $mint,
        bool $strictReadOnly = false,
        array $columnCodecs = []
    ): array {
        if (($decl['identity']['mode'] ?? 'mapped') === 'composite_ref') {
            // Composite identities are derived from their refs on every pass;
            // they are neither minted nor attached-meta owners.
            return $this->capture_composite_table($table, $decl, $tokens, $strictReadOnly, $columnCodecs);
        }

        global $wpdb;
        $pk = $decl['pk'];
        $prefixed = $wpdb->prefix . $table;
        $scope = TableRowScope::predicate($decl, $wpdb);
        $where = $scope === '' ? '' : " WHERE $scope";
        if ($scope !== '') {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results("SELECT * FROM `$prefixed`$where ORDER BY `$pk` ASC", ARRAY_A);
        if ($scope !== '' && (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '')) {
            throw new \RuntimeException("wprism: cannot read owned rows for table '$table'");
        }
        $rows = $rows ?: [];

        $entities = [];
        $naturalIdentityRows = [];
        foreach ($rows as $row) {
            $localId = (int) $row[$pk];
            $uuid = $this->identity->identifyRow(
                $table,
                $decl,
                $row,
                $localId,
                $tokens,
                $mint,
                $strictReadOnly
            );
            $columns = [];
            foreach ($decl['columns'] ?? [] as $col => $rule) {
                if (($rule['class'] ?? '') !== 'authored') {
                    continue;
                }
                $value = $row[$col] ?? null;
                $context = "table '$table' column '$col' (row $localId)";
                $codec = isset($columnCodecs[$col])
                    ? ColumnValueCases::resolve($columnCodecs[$col], $row, $context) : null;
                if ($codec === null) {
                    self::guard_value((string) $col, $value, $rule, $context);
                }
                $columns[$col] = self::capture_column(
                    $value,
                    $codec,
                    $tokens,
                    $context,
                    (string) $col,
                    $rule
                );
            }
            foreach ($decl['refs'] ?? [] as $ref) {
                $col = $ref['column'];
                $raw = (int) ($row[$col] ?? 0);
                if ($raw <= 0) {
                    $columns[$col] = null;
                    continue;
                }
                $token = $tokens->id_to_token($raw, $ref['kind']);
                if ($token === null) {
                    throw new \RuntimeException(
                        "wprism: $table row $localId has unmanaged {$ref['kind']} ref $raw in column '$col' — "
                        . 'capture scope must include the referenced row'
                    );
                }
                $columns[$col] = $token;
            }

            $naturalIdentity = self::natural_identity_key($decl, $columns);
            if ($naturalIdentity !== null) {
                if (isset($naturalIdentityRows[$naturalIdentity])) {
                    throw new \RuntimeException(
                        "wprism: table '$table' natural identity matches local ids "
                        . "{$naturalIdentityRows[$naturalIdentity]} and $localId; full natural identity must be "
                        . 'unique before capture, plan, or apply'
                    );
                }
                // UUID is deliberately not the key here: a row may retain an
                // older ledger UUID after its natural key is renamed. The
                // captured tuple is the compiler's duplicate identity fact,
                // and this is the last point that still sees both live rows
                // before a plan indexes them (Rank Math exercise, 2026-08-27).
                $naturalIdentityRows[$naturalIdentity] = $localId;
            }

            $meta = [];
            foreach ($metaDecls as $metaName => $metaDecl) {
                $meta = array_merge(
                    $meta,
                    $this->capture_meta_rows($metaName, $metaDecl, $localId, $tokens)
                );
            }

            $front = [
                'columns' => (object) $columns,
                'meta' => (object) $meta,
                'table' => $table,
                'uuid' => $uuid,
            ];
            $note = ($this->identityNote)($uuid, $table, $decl, $columns);
            if ($note !== null && !in_array($note, $tokens->notes, true)) {
                $tokens->notes[] = $note;
            }
            $slug = $this->slug_for($decl, $row);
            $entities[] = [
                'uuid' => $uuid,
                'type' => $table,
                'path' => "tables/$table/$uuid--$slug.json",
                'content' => Canon::encode($front),
            ];
        }
        return $entities;
    }

    /** The compiler compares this exact ordered tuple for natural identities. */
    private static function natural_identity_key(array $decl, array $columns): ?string {
        $identity = is_array($decl['identity'] ?? null) ? $decl['identity'] : [];
        if (($identity['mode'] ?? 'mapped') !== 'natural_key') {
            return null;
        }
        $names = isset($identity['column'])
            ? [(string) $identity['column']]
            : array_values(array_map('strval', (array) ($identity['columns'] ?? [])));
        $components = [];
        foreach ($names as $name) {
            $components[] = $columns[$name] ?? null;
        }
        return Canon::encode($components);
    }

    /**
     * Capture a pure join row whose portable identity is its resolved ref tuple.
     *
     * @param array<string,array{container:string,leaves:string}> $columnCodecs
     */
    private function capture_composite_table(
        string $table,
        array $decl,
        object $tokens,
        bool $strictReadOnly,
        array $columnCodecs = []
    ): array {
        global $wpdb;
        $idKind = $decl['id_kind'];
        $cols = $decl['identity']['columns'];
        $prefixed = $wpdb->prefix . $table;
        $orderBy = implode(', ', array_map(static fn($col) => "`$col`", $cols));
        $rows = $wpdb->get_results("SELECT * FROM `$prefixed` ORDER BY $orderBy ASC", ARRAY_A) ?: [];

        $entities = [];
        foreach ($rows as $row) {
            [$uuid, $tokensByCol, $localByCol] = $this->identity->identifyCompositeRow(
                $table,
                $decl,
                $row,
                $tokens
            );

            $columns = $tokensByCol;
            foreach ($decl['columns'] ?? [] as $col => $rule) {
                if (($rule['class'] ?? '') !== 'authored') {
                    continue;
                }
                $value = $row[$col] ?? null;
                $context = "table '$table' column '$col' (composite row $uuid)";
                $codec = isset($columnCodecs[$col])
                    ? ColumnValueCases::resolve($columnCodecs[$col], $row, $context) : null;
                if ($codec === null) {
                    self::guard_value((string) $col, $value, $rule, $context);
                }
                $columns[$col] = self::capture_column(
                    $value,
                    $codec,
                    $tokens,
                    $context,
                    (string) $col,
                    $rule
                );
            }

            $packed = $this->identity->packCompositeId($table, $localByCol);
            ($this->recordCompositeMapping)(
                $uuid,
                $table,
                $idKind,
                $packed,
                $strictReadOnly,
                "composite table '$table' row $packed"
            );

            $front = [
                'columns' => (object) $columns,
                'meta' => (object) [],
                'table' => $table,
                'uuid' => $uuid,
            ];
            // Referenced UUID prefixes are portable; local tuple ids are not.
            $slug = implode('-', array_map(
                fn($col) => substr($this->identity->uuidFromToken($tokensByCol[$col]), 0, 8),
                $cols
            ));
            $entities[] = [
                'uuid' => $uuid,
                'type' => $table,
                'path' => "tables/$table/$uuid--$slug.json",
                'content' => Canon::encode($front),
            ];
        }
        return $entities;
    }

    /** Capture one attached-meta table as an authored key/value map. */
    private function capture_meta_rows(
        string $metaTable,
        array $decl,
        int $ownerLocalId,
        object $tokens
    ): array {
        global $wpdb;
        $prefixed = $wpdb->prefix . $metaTable;
        $attachCol = $decl['attached_to']['column'];
        $idCol = $decl['id_column'] ?? 'id';
        $keyCol = $decl['key_column'] ?? 'meta_key';
        $valCol = $decl['value_column'] ?? 'meta_value';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `$keyCol` AS k, `$valCol` AS v FROM `$prefixed` WHERE `$attachCol` = %d ORDER BY `$idCol` ASC",
            $ownerLocalId
        ), ARRAY_A) ?: [];

        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string) $row['k']][] = $row['v'];
        }

        $default = $decl['default_class'] ?? 'authored';
        $out = [];
        foreach ($byKey as $key => $values) {
            if (count($values) > 1) {
                throw new \RuntimeException(
                    "wprism: multi-value meta key '$key' in $metaTable for parent $ownerLocalId "
                    . '(found ' . count($values) . ' rows) — unsupported'
                );
            }
            $rule = ReferenceRules::attached_meta_key($decl, $key);
            $class = $rule['class'] ?? $default;
            if ($class !== 'authored') {
                continue;
            }
            $value = $values[0];
            $context = "table '$metaTable' key '$key' (parent $ownerLocalId)";
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $plain = PlainData::decode($value, $context);
                PlainData::assert($plain, $context);
                $decoded = StructuredValue::decode($plain, $rule, $context);
                self::guard_value($key, $decoded, $rule, $context);
                $captured = $tokens->struct_capture(
                    $decoded,
                    $rule['json_refs'] ?? [],
                    $rule['key_refs'] ?? null
                );
                $out[$key] = !empty($rule['order_preserving'])
                    ? new OrderPreserved($captured)
                    : $captured;
            } elseif (!empty($rule['plain_data'])) {
                // Tokenizing serialized storage bytes directly corrupts PHP's
                // byte-count prefixes when a nested URL changes length. Decode
                // first, then rewrite only native string leaves, matching the
                // post/term/options plain-data path.
                $plain = PlainData::decode($value, $context);
                PlainData::assert($plain, $context);
                self::guard_value($key, $plain, $rule, $context);
                $out[$key] = $tokens->plain_data_capture($plain);
            } elseif (!empty($rule['ref'])) {
                self::guard_value($key, $value, $rule, $context);
                $localId = (int) $value;
                if ($localId <= 0) {
                    $out[$key] = null;
                    continue;
                }
                $token = $tokens->id_to_token($localId, $rule['ref']);
                if ($token === null) {
                    $tokens->warnings[] = "table '$metaTable' key '$key' (parent $ownerLocalId): unmapped "
                        . "{$rule['ref']} id $localId dropped (dangling reference)";
                    continue;
                }
                $out[$key] = $token;
            } elseif (is_string($value)) {
                self::guard_value($key, $value, $rule, $context);
                $out[$key] = $tokens->tokenize_text($value);
            } else {
                self::guard_value($key, $value, $rule, $context);
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * One authored column's value on the way into canonical state.
     *
     * With no codec this is byte for byte the treatment every authored column
     * had before WP-6.1 — `tokenize_text()` on a string, the raw value
     * otherwise. With one, the bytes are opened, their string leaves rewritten,
     * and the container re-encoded with correct length prefixes, which is the
     * half `tokenize_text()` on serialized storage bytes could never do: a
     * substituted URL of a different byte length leaves every enclosing `s:<n>:`
     * prefix stating the old count (tools/engine-gaps.json, primitive
     * `serialized_column_codec`).
     *
     * A non-string value with a codec declared refuses inside
     * ColumnCodecGrammar rather than being passed through here: the declaration
     * says these bytes are a container, and a column that holds an integer on
     * this target is a fact the author needs to see.
     *
     * @param array{container:string,leaves:string}|null $codec
     */
    private static function capture_column(
        mixed $value,
        ?array $codec,
        object $tokens,
        string $context,
        string $key,
        array $rule
    ): mixed {
        if ($codec === null) {
            return is_string($value) ? $tokens->tokenize_text($value) : $value;
        }
        return ColumnCodecGrammar::capture_value($value, $codec, $tokens, $context, $key, $rule);
    }

    /** Portable human-readable suffix for an ordinary row's canonical path. */
    private function slug_for(array $decl, array $row): string {
        $col = $decl['slug_column'] ?? null;
        $raw = $col !== null ? (string) ($row[$col] ?? '') : '';
        $slug = $raw !== '' ? ($this->slugify)($raw) : '';
        return $slug !== '' ? $slug : 'record';
    }

    /** Refuse secret/PII-shaped bytes in values declared authored. */
    private static function guard_value(string $key, $value, array $rule, string $where): void {
        if (empty($rule['allow_secret'])) {
            $label = Secrets::clearance_match_deep($key, $value);
            if ($label !== null) {
                throw new \RuntimeException(
                    "wprism: secret guard tripped — $where looks like a $label but is classified authored; "
                    . "refusing to capture it into state/.\n"
                    . "If this is really a secret, reclassify it runtime/derived/env instead of authored.\n"
                    . 'If this is a false positive, declare "allow_secret": true on its rule '
                    . '(the manifest, or a site.wprism.json policy.tables override).'
                );
            }
        }
        if (empty($rule['allow_pii'])) {
            $label = PersonalData::match_deep($key, $value);
            if ($label !== null) {
                throw new \RuntimeException(
                    "wprism: PII guard tripped — $where looks like $label but is classified authored; "
                    . 'refusing capture without an exact reviewed allow_pii=true rule'
                );
            }
        }
    }
}
