<?php
namespace Duo;

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
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}

/**
 * Read-side capture boundary for authored typed tables (DUO-3349).
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
     * @param array<string,array> $metaDecls attached-meta declarations keyed by table
     * @return array<int,array{uuid:string,type:string,path:string,content:string}>
     */
    public function capture_table(
        string $table,
        array $decl,
        array $metaDecls,
        object $tokens,
        bool $mint,
        bool $strictReadOnly = false
    ): array {
        if (($decl['identity']['mode'] ?? 'mapped') === 'composite_ref') {
            // Composite identities are derived from their refs on every pass;
            // they are neither minted nor attached-meta owners.
            return $this->capture_composite_table($table, $decl, $tokens, $strictReadOnly);
        }

        global $wpdb;
        $pk = $decl['pk'];
        $prefixed = $wpdb->prefix . $table;
        $rows = $wpdb->get_results("SELECT * FROM `$prefixed` ORDER BY `$pk` ASC", ARRAY_A) ?: [];

        $entities = [];
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
                self::guard_secret(
                    $value,
                    !empty($rule['allow_secret']),
                    "table '$table' column '$col' (row $localId)"
                );
                $columns[$col] = is_string($value) ? $tokens->tokenize_text($value) : $value;
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
                        "duo: $table row $localId has unmanaged {$ref['kind']} ref $raw in column '$col' — "
                        . 'capture scope must include the referenced row'
                    );
                }
                $columns[$col] = $token;
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

    /** Capture a pure join row whose portable identity is its resolved ref tuple. */
    private function capture_composite_table(
        string $table,
        array $decl,
        object $tokens,
        bool $strictReadOnly
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
                self::guard_secret(
                    $value,
                    !empty($rule['allow_secret']),
                    "table '$table' column '$col' (composite row $uuid)"
                );
                $columns[$col] = is_string($value) ? $tokens->tokenize_text($value) : $value;
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
                    "duo: multi-value meta key '$key' in $metaTable for parent $ownerLocalId "
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
                self::guard_secret($decoded, !empty($rule['allow_secret']), $context);
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
                self::guard_secret($plain, !empty($rule['allow_secret']), $context);
                $out[$key] = $tokens->plain_data_capture($plain);
            } elseif (!empty($rule['ref'])) {
                self::guard_secret($value, !empty($rule['allow_secret']), $context);
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
                self::guard_secret($value, !empty($rule['allow_secret']), $context);
                $out[$key] = $tokens->tokenize_text($value);
            } else {
                self::guard_secret($value, !empty($rule['allow_secret']), $context);
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Portable human-readable suffix for an ordinary row's canonical path. */
    private function slug_for(array $decl, array $row): string {
        $col = $decl['slug_column'] ?? null;
        $raw = $col !== null ? (string) ($row[$col] ?? '') : '';
        $slug = $raw !== '' ? ($this->slugify)($raw) : '';
        return $slug !== '' ? $slug : 'record';
    }

    /** Refuse high-confidence credentials in values declared authored. */
    private static function guard_secret($value, bool $allowSecret, string $where): void {
        if ($allowSecret) {
            return;
        }
        $label = Secrets::hard_match_deep($value);
        if ($label === null) {
            return;
        }
        throw new \RuntimeException(
            "duo: secret guard tripped — $where looks like a $label but is classified authored; "
            . "refusing to capture it into state/.\n"
            . "If this is really a secret, reclassify it runtime/derived/env instead of authored.\n"
            . 'If this is a false positive, declare "allow_secret": true on its rule '
            . '(the manifest, or a site.duo.json policy.tables override).'
        );
    }
}
