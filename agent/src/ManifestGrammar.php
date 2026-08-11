<?php
namespace Duo;

/**
 * The pure, offline-checkable-from-manifest-bytes-alone grammar half of
 * declared `tables.<name>` and `widgets.<type>` sections (DUO-3318's split,
 * first extracted seam of DUO-3348/DUO-3335's "ManifestValidator with
 * grammar-specific validators" decomposition target).
 *
 * Named `ManifestGrammar` rather than the issue's literal "ManifestValidator"
 * to stay unambiguous beside the pre-existing, unrelated
 * `Duo\Orchestrator\ManifestValidate` (cli/src/ManifestValidate.php, the
 * `duo manifest-validate` adapter-draft CLI command) — same decomposition
 * intent, distinct class, distinct namespace, easily confused by name alone.
 *
 * `Policy` keeps `assert_table_grammar()`, `natural_key_columns()`, and
 * `assert_widget_grammar()` as thin compatibility facades delegating here, so
 * existing external callers (Snapshot.php's live schema re-checks,
 * SidebarState.php's capture-time re-check) are unaffected by this move. Only
 * Policy's OWN internal call sites (validate_tables(), validate_widgets(),
 * closed_vocabularies()) were repointed directly at this class.
 *
 * Future slices of the same decomposition target may add the remaining
 * grammar-specific validators (option/post-type/action/provider/effect
 * shapes, etc.) beside these, per the issue's "extract one collaborator at a
 * time" guardrail.
 */
final class ManifestGrammar {
    /**
     * The closed `tables.<name>.class` vocabulary (DUO-3318).
     *
     * Exactly two values carry engine behavior: Snapshot.php selects
     * `authored_snapshot` (a row table with identity of its own) and
     * `authored_snapshot_meta` (an EAV sidecar with none) and deliberately
     * gives no meaning to anything else. The remaining four are honest
     * dispositions rather than mechanisms — `authored_typed_snapshot_post_v1`
     * is the pre-existing "declared, and loudly not implemented yet" marker,
     * and runtime/derived/env record a reviewed decision that a table's
     * contents are target-local (core.json alone classifies 44 tables that
     * way, which is exactly what keeps them out of `duo pending`'s unknown
     * queue). The two literals repeat Snapshot::CLASS_ROW/CLASS_META rather
     * than referencing them: this file must stay loadable with no other
     * engine class present (RepositoryCompiler validates a revision in a
     * process that never constructs Ledger/Tokens/Snapshot), and both
     * spellings are wire format a manifest already carries, not an internal
     * name either side is free to change.
     *
     * Closed because a typo was previously indistinguishable from a
     * deliberate inert marker: `authored_snaphot` simply never matched
     * Snapshot's own filter, so the table silently dropped out of capture
     * with no diagnostic anywhere — a whole plugin's authored rows missing
     * from canonical state because of one transposed letter. The vocabulary
     * is engine-owned: a new class value means new engine behavior, so it is
     * an engine change with a spec bump, never a manifest declaration.
     */
    private const TABLE_CLASSES = [
        'authored_snapshot',
        'authored_snapshot_meta',
        'authored_typed_snapshot_post_v1',
        'runtime',
        'derived',
        'env',
    ];

    /** The closed `tables.<t>.identity.mode` vocabulary (see assert_table_grammar()). */
    private const IDENTITY_MODES = ['mapped', 'natural_key', 'composite_ref'];

    /** The closed `widgets.<t>.settings.<s>.codec` vocabulary. @see assert_widget_grammar() */
    private const WIDGET_SETTING_CODECS = ['blocks'];
    /** The closed `widgets.<t>.settings.<s>.ref` vocabulary. @see assert_widget_grammar() */
    private const WIDGET_SETTING_REFS = ['term'];

    /** @return list<string> Policy::closed_vocabularies()'s read of TABLE_CLASSES. */
    public static function tableClasses(): array {
        return self::TABLE_CLASSES;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of IDENTITY_MODES. */
    public static function identityModes(): array {
        return self::IDENTITY_MODES;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of WIDGET_SETTING_CODECS. */
    public static function widgetSettingCodecs(): array {
        return self::WIDGET_SETTING_CODECS;
    }

    /** @return list<string> Policy::closed_vocabularies()'s read of WIDGET_SETTING_REFS. */
    public static function widgetSettingRefs(): array {
        return self::WIDGET_SETTING_REFS;
    }

    /**
     * The natural-key identity components of one table declaration, in the
     * exact order the manifest declared them (DUO-3318).
     *
     * `{"column": "<col>"}` is the original single-column spelling and stays
     * valid as the 1-component case; `{"columns": [...]}` is the parent-scoped
     * form, for a table whose authored key is unique only WITHIN a parent row
     * (a slot code unique per room, an option key unique per form). Declared
     * order is load-bearing — it is part of the derivation input, so
     * reordering `columns` is an identity change, not a cosmetic edit; that
     * is why this returns the list as written rather than sorting it.
     *
     * Returns [] for any other identity mode, so a caller can branch on
     * emptiness without repeating the mode check.
     *
     * @return list<string>
     */
    public static function natural_key_columns(array $decl): array {
        if (!is_array($decl['identity'] ?? null) || ($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return [];
        }
        $identity = $decl['identity'];
        if (array_key_exists('columns', $identity)) {
            return array_values(array_map('strval', (array) $identity['columns']));
        }
        return array_key_exists('column', $identity) ? [(string) $identity['column']] : [];
    }

    /**
     * The pure-grammar half of a `tables.<name>` declaration (DUO-3318).
     *
     * Everything checkable from the manifest bytes alone lives here, and this
     * is the only implementation of it: Policy::load()/from_snapshot() run it
     * for every declared table so a malformed declaration refuses offline,
     * before any target contact, and Snapshot::assert_row_schema()/
     * assert_meta_schema() run it again immediately before their own LIVE
     * `SHOW COLUMNS` half (re-checking a pure function of already-loaded bytes
     * costs nothing, and keeps a directly-constructed Policy — the shape
     * several offline harnesses build — covered by the same rules).
     *
     * The split is exactly "does answering this need the database": every
     * check below reads only $decl. The two facts that stay in Snapshot are
     * the ledger COLUMN WIDTHS (duo_map.id_kind, duo_map.entity_type), which
     * are Ledger's schema rather than the manifest's grammar — and, decisively,
     * naming Ledger here would drag a second engine class into a file whose
     * whole point is that it loads alone.
     *
     * $source names the declaring manifest when one is known. It is appended,
     * never interpolated into the existing sentences, so Snapshot's long-
     * standing "duo: table '<t>' …" wordings stay byte-identical for the
     * capture-time caller that has no manifest name to report.
     */
    public static function assert_table_grammar(string $table, mixed $decl, ?string $source = null): void {
        $where = $source === null ? '' : " (declared by $source)";
        // `mixed`, not `array`, so a scalar or list declaration produces this
        // engine's ordinary "duo: " refusal rather than a PHP TypeError at the
        // call site — a promise assert_table_section_shapes() below now keeps
        // for the declaration's INNER sections too, which used to reach
        // array_column()/array_keys() as scalars and raise a TypeError. An
        // EMPTY object decodes to `[]`, which array_is_list() calls a list, so
        // it deliberately falls through to the class check below — "declares
        // class=NULL" says far more than "not an object".
        if (!is_array($decl) || (array_is_list($decl) && $decl !== [])) {
            throw new \RuntimeException(
                "duo: table '$table'$where must be declared as an object of table rules, got " . gettype($decl)
            );
        }
        $class = $decl['class'] ?? null;
        if (!in_array($class, self::TABLE_CLASSES, true)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares class=" . var_export($class, true)
                . ' but the table class vocabulary is closed (' . implode(', ', self::TABLE_CLASSES)
                . ') — it is engine-owned, because only the engine can act on a class; a new one is an engine '
                . 'change with a spec bump, not a manifest declaration'
            );
        }
        if ($class === 'authored_snapshot_meta') {
            // assert_meta_schema()'s pure half. The remaining columns of an
            // EAV sidecar are checked against the live schema there; what a
            // manifest alone can get wrong is failing to say which row table
            // owns the sidecar and through which column.
            $attachCol = (string) ($decl['attached_to']['column'] ?? '');
            if ((string) ($decl['attached_to']['table'] ?? '') === '' || $attachCol === '') {
                throw new \RuntimeException("duo: table '$table'$where declares authored_snapshot_meta with no attached_to.{table,column}");
            }
            return;
        }
        if ($class !== 'authored_snapshot') {
            return; // an inert marker or a target-local disposition: nothing further is declarable
        }

        self::assert_table_section_shapes($table, $decl, $where);
        $mode = $decl['identity']['mode'] ?? 'mapped';
        if (!in_array($mode, self::IDENTITY_MODES, true)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares unknown identity.mode " . var_export($mode, true)
                . ' — the identity vocabulary is closed and engine-owned: "mapped" (default; a surrogate primary '
                . 'key with no portable key of its own, identity minted into duo_map), "natural_key" (a stable '
                . 'authored column, or an ordered tuple of them, identity derived from the value), "composite_ref" '
                . '(a pure join table with no primary key, identity derived from the referenced rows\' own uuids). '
                . 'Each serves a different table SHAPE; a new mode is an engine change with a spec bump'
            );
        }
        $refCols = array_column($decl['refs'] ?? [], 'column');
        $colKeys = array_keys($decl['columns'] ?? []);
        $overlap = array_intersect($colKeys, $refCols);
        if ($overlap) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares column(s) in BOTH columns and refs: " . implode(', ', $overlap)
            );
        }
        if ($mode === 'composite_ref') {
            self::assert_composite_ref_grammar($table, $decl, $refCols, $where);
            return;
        }

        $pk = (string) ($decl['pk'] ?? '');
        if ($pk === '') {
            throw new \RuntimeException("duo: table '$table'$where declares authored_snapshot with no 'pk'");
        }
        if ($mode === 'natural_key') {
            self::assert_natural_key_grammar($table, $decl, $pk, $refCols, $colKeys, $where);
        }
        if (array_key_exists('slug_column', $decl)) {
            $slugCol = $decl['slug_column'];
            $slugRule = is_string($slugCol) && $slugCol !== ''
                ? ($decl['columns'][$slugCol] ?? null)
                : null;
            if (!is_array($slugRule) || ($slugRule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: table '$table'$where slug_column must name a non-empty authored columns entry — "
                    . 'primary keys, refs, runtime, derived, and env columns are environment-local and cannot name canonical files'
                );
            }
        }
        self::assert_invalidate_grammar($table, $decl, $where);
    }

    /**
     * The SHAPE of an authored_snapshot declaration's four structural
     * sections, checked before anything reads them (DUO-3318 review, S1).
     *
     * Every check here closes a case where PHP's own coercion answered a
     * malformed declaration instead of this engine doing so:
     *   - `"identity": "natural_key"` — a string, not an object — makes
     *     `$decl['identity']['mode'] ?? 'mapped'` evaluate to 'mapped' (the
     *     `??` swallows the illegal string offset), so the table silently
     *     becomes surrogate-identity: every row mints a UUIDv7 that is
     *     environment-local, which is precisely what declaring natural_key
     *     was meant to prevent.
     *   - `"refs": ["room_id"]` — a list of strings — makes
     *     array_column($refs, 'column') return `[]`, so the ref column is
     *     treated as an ordinary scalar and its raw local id reaches
     *     canonical state; a scalar `refs` or `columns` reached array_column()
     *     /array_keys() and raised a PHP TypeError instead of this engine's
     *     "duo: " refusal (the docblock above already promised otherwise).
     *   - `"pk": ["id"]` casts to the string "Array" (a Warning, not an
     *     error), which is non-empty and therefore passed the pk check, then
     *     named a column no table has.
     *
     * The messages name the object/list form the author meant, because the
     * mistake is almost always a spelling of the right intent.
     */
    private static function assert_table_section_shapes(string $table, array $decl, string $where): void {
        if (array_key_exists('pk', $decl) && (!is_string($decl['pk']) || $decl['pk'] === '')) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares pk=" . var_export($decl['pk'], true)
                . ' — pk must be a non-empty string naming this table\'s own primary key column'
            );
        }
        $refs = $decl['refs'] ?? [];
        if (!is_array($refs) || !array_is_list($refs)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares refs=" . var_export($refs, true)
                . ' — refs must be a LIST of {"column": "<col>", "kind": "<ref kind>"} objects (an empty list when '
                . 'the table references nothing); a ref that is not declared in this shape is captured as an '
                . 'ordinary scalar, which puts an environment-local id into canonical state'
            );
        }
        foreach ($refs as $i => $ref) {
            if (!is_array($ref) || (array_is_list($ref) && $ref !== [])) {
                throw new \RuntimeException(
                    "duo: table '$table'$where declares refs[$i]=" . var_export($ref, true)
                    . ' — every refs[] entry must be an object declaring both `column` and `kind`'
                );
            }
            foreach (['column', 'kind'] as $key) {
                if (!is_string($ref[$key] ?? null) || $ref[$key] === '') {
                    throw new \RuntimeException(
                        "duo: table '$table'$where declares refs[$i].$key=" . var_export($ref[$key] ?? null, true)
                        . " — every refs[] entry needs a non-empty string `column` (the column holding the id) and "
                        . '`kind` (the keyspace it points into)'
                    );
                }
            }
        }
        $columns = $decl['columns'] ?? [];
        if (!is_array($columns) || (array_is_list($columns) && $columns !== [])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares columns=" . var_export($columns, true)
                . ' — columns must be an object keyed by column name, each value a rule declaring its `class`'
            );
        }
        $identity = $decl['identity'] ?? [];
        if (!is_array($identity) || (array_is_list($identity) && $identity !== [])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity=" . var_export($identity, true)
                . ' — identity must be an OBJECT naming the mode, e.g. {"mode": "natural_key", "column": "<col>"}; '
                . 'a bare string is read as no identity declaration at all, which silently means '
                . 'identity.mode=mapped (surrogate, environment-local identity)'
            );
        }
        if (array_key_exists('column', $identity)
            && (!is_string($identity['column']) || $identity['column'] === '')) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.column=" . var_export($identity['column'], true)
                . ' — identity.column is the SINGLE-component spelling and must be one non-empty column name; the '
                . 'ordered multi-component form is identity.columns: ["<col>", ...]'
            );
        }
        if (array_key_exists('columns', $identity)) {
            $idCols = $identity['columns'];
            $ok = is_array($idCols) && array_is_list($idCols) && $idCols !== [];
            foreach ($ok ? $idCols : [] as $col) {
                $ok = $ok && is_string($col) && $col !== '';
            }
            if (!$ok) {
                throw new \RuntimeException(
                    "duo: table '$table'$where declares identity.columns=" . var_export($idCols, true)
                    . ' — identity.columns is the ordered LIST spelling and must be a non-empty list of column '
                    . 'names; the one-component case is spelled identity.column: "<col>" instead, and exactly one '
                    . 'of the two may be declared'
                );
            }
        }
    }

    /**
     * composite_ref's own grammar half — kept a separate function for the
     * same reason Snapshot::assert_composite_row_schema() is separate from
     * assert_row_schema(): the invariants genuinely differ (no pk at all;
     * identity.columns must EQUAL refs[] columns), so interleaving them
     * would obscure both.
     *
     * @param list<string> $refCols
     */
    private static function assert_composite_ref_grammar(string $table, array $decl, array $refCols, string $where): void {
        if (isset($decl['pk'])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=composite_ref AND a 'pk' — "
                . "composite_ref tables have no scalar primary key; remove 'pk'"
            );
        }
        if (!empty($decl['invalidate'])) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=composite_ref with 'invalidate' — "
                . "run_invalidate()'s {id} substitution assumes a single scalar local id, which this mode has no "
                . 'equivalent of; unsupported, not silently ignored (no composite_ref fixture has needed it — see docblock)'
            );
        }
        $idCols = $decl['identity']['columns'] ?? null;
        if (!is_array($idCols) || count($idCols) !== 2) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=composite_ref with identity.columns != exactly 2 entries "
                . '— this is the only shape this engine has proven (see assert_composite_row_schema()\'s docblock)'
            );
        }
        $sortedIdCols = $idCols;
        sort($sortedIdCols);
        $sortedRefCols = $refCols;
        sort($sortedRefCols);
        if ($sortedIdCols !== $sortedRefCols) {
            throw new \RuntimeException(
                "duo: table '$table'$where identity.columns [" . implode(', ', $idCols)
                . "] must be EXACTLY its refs[] columns [" . implode(', ', $refCols)
                . '] — composite_ref is only for pure join tables: every identity column is a ref, every ref is an identity column'
            );
        }
    }

    /**
     * natural_key's grammar half, including DUO-3318's parent-scoped
     * multi-column form.
     *
     * Every component must be a declared ref column or a declared `columns{}`
     * entry — the two buckets whose values capture actually carries into the
     * canonical file, and therefore the only ones a derivation can read back
     * on another environment. The primary key is refused by name rather than
     * merely being absent from those buckets: a surrogate auto-increment id
     * is the exact category of value this engine's whole token grammar exists
     * to keep out of portable identity, so a manifest reaching for it has
     * made a specific mistake worth naming.
     *
     * A multi-column key REQUIRES slug_column. Single-column tables have a
     * long-standing fallback (the literal `record` suffix), but a tuple has
     * no honest one-line spelling — joining component display values would
     * put a resolved ref's raw local id or a foreign row's label into a
     * filename, and both change across environments. Requiring the declaration
     * keeps filenames portable by construction instead of by convention.
     *
     * @param list<string> $refCols
     * @param list<string> $colKeys
     */
    private static function assert_natural_key_grammar(
        string $table,
        array $decl,
        string $pk,
        array $refCols,
        array $colKeys,
        string $where
    ): void {
        $identity = $decl['identity'];
        if (array_key_exists('column', $identity) && array_key_exists('columns', $identity)) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=natural_key with BOTH 'column' and 'columns' — "
                . "these are one vocabulary with two spellings ('column' is the 1-component case); declare exactly one"
            );
        }
        $columns = self::natural_key_columns($decl);
        if ($columns === []) {
            throw new \RuntimeException(
                "duo: table '$table'$where declares identity.mode=natural_key with no 'column' and no non-empty "
                . "'columns' — a derived identity needs at least one authored component to derive from"
            );
        }
        $seen = [];
        foreach ($columns as $column) {
            if ($column === '') {
                throw new \RuntimeException(
                    "duo: table '$table'$where declares an empty identity column name for identity.mode=natural_key"
                );
            }
            if (isset($seen[$column])) {
                throw new \RuntimeException(
                    "duo: table '$table'$where repeats identity column '$column' — a repeated component adds no "
                    . 'distinguishing power and makes the declared order ambiguous'
                );
            }
            $seen[$column] = true;
            if ($column === $pk) {
                throw new \RuntimeException(
                    "duo: table '$table'$where names its primary key '$pk' as a natural_key identity column — a "
                    . 'surrogate primary key is an environment-local auto-increment value, so deriving identity '
                    . 'from it would mint a different uuid per environment for the same authored fact; use '
                    . 'identity.mode=mapped when a table has no portable key of its own'
                );
            }
            if (!in_array($column, $refCols, true) && !in_array($column, $colKeys, true)) {
                throw new \RuntimeException(
                    "duo: table '$table'$where names identity column '$column', which is neither a declared "
                    . 'refs[] column nor a declared columns{} entry — an identity component must be a column this '
                    . 'manifest actually classifies, or capture has nothing portable to derive from'
                );
            }
        }
        if (count($columns) > 1 && (string) ($decl['slug_column'] ?? '') === '') {
            throw new \RuntimeException(
                "duo: table '$table'$where declares a multi-column natural_key (" . implode(', ', $columns)
                . ") without 'slug_column' — a tuple has no portable one-line filename spelling (a resolved ref "
                . "component is an environment-local id), so the declaration must name the authored column that "
                . 'supplies the human-readable half of the path'
            );
        }
    }

    /**
     * `invalidate[]` grammar (DUO-3318): each entry is exactly one targeted
     * row delete `{table, column}` or one named option `{option_pattern}`.
     *
     * Both branches are generic, plugin-blind primitives, and both are
     * silently no-ops when misspelled: Snapshot::run_invalidate() dispatches
     * on `isset($inv['table'])` / `isset($inv['option_pattern'])`, so a
     * mistyped key used to mean "this cache is never invalidated" with no
     * diagnostic — the exact failure Ninja Forms' stale-cache finding was
     * filed for in the first place. `{id}` is required in an option_pattern
     * for the same reason: a pattern with no substitution point names ONE
     * fixed option row for every row of the table, which is either a no-op or
     * a delete of an unrelated option.
     */
    private static function assert_invalidate_grammar(string $table, array $decl, string $where): void {
        if (!array_key_exists('invalidate', $decl)) {
            return;
        }
        $entries = $decl['invalidate'];
        if (!is_array($entries) || !array_is_list($entries) || $entries === []) {
            throw new \RuntimeException("duo: table '$table'$where invalidate must be a non-empty list");
        }
        foreach ($entries as $i => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \RuntimeException("duo: table '$table'$where invalidate[$i] must be an object");
            }
            $keys = array_keys($entry);
            sort($keys, SORT_STRING);
            if ($keys === ['column', 'table']) {
                foreach (['table', 'column'] as $key) {
                    if (!is_string($entry[$key]) || $entry[$key] === '') {
                        throw new \RuntimeException(
                            "duo: table '$table'$where invalidate[$i].$key must be a non-empty string"
                        );
                    }
                }
                continue;
            }
            if ($keys === ['option_pattern']) {
                if (!is_string($entry['option_pattern']) || !str_contains($entry['option_pattern'], '{id}')) {
                    throw new \RuntimeException(
                        "duo: table '$table'$where invalidate[$i].option_pattern must be a string containing the "
                        . '{id} substitution point — without it every row of this table would name the same one '
                        . 'option row'
                    );
                }
                continue;
            }
            throw new \RuntimeException(
                "duo: table '$table'$where invalidate[$i] declares [" . implode(', ', $keys)
                . '] but the invalidation vocabulary is closed and engine-owned: exactly {table, column} for a '
                . 'targeted row delete, or exactly {option_pattern} for a named option. Anything a plugin owns '
                . 'beyond those two generic primitives belongs in a native action or a provider capability'
            );
        }
    }

    /**
     * The pure-grammar half of ONE `widgets.<type>` declaration — the exact
     * mirror of assert_table_grammar() above, and for the same reason
     * (DUO-3318 review, S4).
     *
     * This is the only implementation of these rules: validate_widgets() runs
     * it for every declared type at load, and SidebarState::assert_policy()
     * runs it again immediately before its own genuinely-live work (re-checking
     * a pure function of already-loaded bytes costs nothing, and keeps a
     * directly-constructed Policy — the shape several offline harnesses build —
     * covered by the same rules). SidebarState keeps exactly one check of its
     * own, the one that is genuinely its own: the duo_map.id_kind width budget
     * its DERIVED `widget_<type>` kind has to fit, which is Ledger's schema
     * rather than the manifest's grammar.
     *
     * The two copies used to disagree in three places, all of them the same
     * direction — SidebarState accepted what a load-time check refused, so a
     * declaration could pass sidebar capture and still fail the next `Policy::
     * load()`: an EMPTY settings map, a settings LIST rather than an object,
     * and `"codec": null`/`"ref": null` (isset() reads a declared null as
     * absent). The stricter reading is the correct one in all three: a widget
     * whose allowlist names no field can never capture an instance, and an
     * explicitly-null codec is a declaration the author meant to write.
     *
     * $source names the declaring manifest when one is known; it is prefixed
     * to the existing "widgets.<type>…" wordings rather than interpolated into
     * them, so the live caller (which has no manifest name to report) still
     * gets a complete sentence.
          * A JSON key "0" decodes to an int PHP key; the declared
     * ^[a-z0-9_-]+$ rule legally admits it after string coercion, so a
     * mixed int/string key map loads — a syntactically legal id_base,
     * not a validation gap.
     *
     * `codec`/`ref` stay deliberately narrow: 'blocks' is the only settings
     * codec the engine implements, and 'term' the only ref kind a core widget
     * setting has ever carried. Both are engine-owned — a widget setting's
     * value passes through engine codecs, not adapter code — so widening
     * either is an engine change with a spec bump, not a manifest
     * declaration.
     */
    public static function assert_widget_grammar(string $type, mixed $decl, ?string $source = null): void {
        $where = ($source === null ? '' : "$source ") . "widgets.$type";
        if (!preg_match('/^[a-z0-9_-]+$/', $type)) {
            throw new \RuntimeException(
                "duo: $where names an invalid widget type — a type is WordPress's own id_base "
                . '(the widget_<type> option name), matching ^[a-z0-9_-]+$'
            );
        }
        $settings = is_array($decl) ? ($decl['settings'] ?? null) : null;
        if (!is_array($settings) || $settings === [] || array_is_list($settings)) {
            throw new \RuntimeException(
                "duo: $where must declare a non-empty `settings` object — capture refuses any live setting "
                . 'this map does not name, so an absent map makes every instance of the type uncapturable'
            );
        }
        foreach ($settings as $setting => $rule) {
            if (!is_string($setting) || $setting === '' || !is_array($rule)
                || ($rule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting must declare class=authored — a widget settings map is an "
                    . 'allowlist of portable fields, so a non-authored entry has nothing to mean (leave the '
                    . 'field out to exclude it)'
                );
            }
            if (array_key_exists('codec', $rule) && !in_array($rule['codec'], self::WIDGET_SETTING_CODECS, true)) {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting declares codec=" . var_export($rule['codec'], true)
                    . ' but the widget settings codec vocabulary is closed and engine-owned (blocks)'
                );
            }
            if (array_key_exists('ref', $rule) && !in_array($rule['ref'], self::WIDGET_SETTING_REFS, true)) {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting declares ref=" . var_export($rule['ref'], true)
                    . ' but the widget settings ref vocabulary is closed and engine-owned (term)'
                );
            }
            if (array_key_exists('codec', $rule) && array_key_exists('ref', $rule)) {
                throw new \RuntimeException(
                    "duo: $where.settings.$setting cannot declare codec and ref — a setting value is either a "
                    . 'structured document the engine decodes or a single entity reference it resolves'
                );
            }
        }
    }
}
