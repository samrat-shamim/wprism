<?php
namespace Duo;

/**
 * Typed snapshot: capture/apply for authored custom tables (DESIGN.md §3.3's
 * "middle tier" the design review named but never built — finding #8: opaque
 * pick-side replay of FK-bearing custom-table rows corrupts silently, so
 * refusing opaque mode isn't enough; something has to actually capture these
 * tables safely). Primary fixture: Ninja Forms' nf3_forms/nf3_fields/
 * nf3_actions + their _meta twins (task #75, docs/grind/r1a-forms.md).
 * Secondary: WooCommerce's woocommerce_attribute_taxonomies (docs/grind/
 * r1b-shop.md). Both grounded empirically on live installs, not assumed —
 * see the manifests' own notes for the DESCRIBE/data evidence.
 *
 * ---- The two table shapes ----
 *
 * A manifest's `"tables"` section entry is one of two class values (a THIRD,
 * `"authored_typed_snapshot_post_v1"`, remains the pre-existing honest-intent
 * marker for tables nobody has implemented yet — this file gives no meaning
 * to it, on purpose, exactly as before):
 *
 * 1. `"authored_snapshot"` — a ROW table: has its own primary key and its
 *    own identity (a duo_map ledger row). Every real, live column MUST be
 *    accounted for by exactly one of: `pk` (the primary key, implicit,
 *    never in `columns`/`refs`), `refs[]` (FK-shaped columns, resolved
 *    through the ledger like {{post:uuid}} today), or `columns{}` (every
 *    other column, individually classified authored/runtime/derived/env —
 *    exactly the post_meta discipline, generalized from per-KEY to
 *    per-COLUMN because a table's columns are static schema, not dynamic
 *    per-row data). An undeclared column — the finding-#8 rule, made
 *    absolute — refuses capture loudly, naming it: an FK-shaped or
 *    otherwise unclassified column must never silently reach canonical
 *    state, whether or not it happens to hold an id THIS round. Example
 *    (nf3_fields, single-target FK into nf3_forms — see manifests/
 *    ninja-forms.json):
 *      {"class": "authored_snapshot", "pk": "id", "id_kind": "nf3_field",
 *       "slug_column": "label",
 *       "columns": {"label": {"class": "authored"}, ...},
 *       "refs": [{"column": "parent_id", "kind": "nf3_form"}]}
 *
 * 2. `"authored_snapshot_meta"` — an ATTACHED-META table: an EAV sidecar
 *    with no independent identity at all (nf3_field_meta: one row per
 *    settings KEY for a single owning nf3_fields row, not a fixed schema).
 *    Declares `attached_to: {table, column}` (which ROW table owns it and
 *    the FK column linking back), `key_column`/`value_column` (which pair
 *    of columns holds the live key/value — nf3_*_meta tables carry a
 *    legacy `key`/`value` pair ALONGSIDE `meta_key`/`meta_value`, a back-
 *    compat artifact confirmed empirically to always hold identical
 *    content; `legacy_key_column`/`legacy_value_column` declare the second
 *    pair so apply writes both, matching what the plugin's own code does),
 *    a `keys{}` map of KNOWN-non-authored or KNOWN-ref key names (e.g.
 *    `editActive`/`drawerDisabled` are the admin JS builder's own ui-state
 *    scratch flags, confirmed by reading Ninja Forms' source, not guessed —
 *    classified runtime; `parent_id` as a META KEY — distinct from the
 *    `attached_to.column` of the SAME name — mirrors the owning row's OWN
 *    ref-to-form value and is declared `{"class": "authored", "ref":
 *    "nf3_form"}` so it round-trips correctly instead of leaking a raw
 *    local id), and a `default_class` for every OTHER key this table has
 *    ever been observed to carry (documented, not silently assumed — see
 *    the manifest's own notes for exactly which ~30-60 keys were verified
 *    live and why "authored, no further per-key enumeration" is the
 *    honest default for an EAV sidecar that is, structurally, 100%
 *    site-builder settings once the two UI-state exceptions are pulled
 *    out). This is a DELIBERATE, narrower discipline than row tables' full
 *    per-column enumeration: an EAV table's key SPACE is open-ended by
 *    construction (every NF field TYPE can mint its own setting names —
 *    this fixture only exercises the ~13 field types the Job Application
 *    template uses), so "enumerate every column" (finite, static schema)
 *    and "enumerate every key ever" (unbounded, data-driven) are genuinely
 *    different completeness problems; `default_class` is the SAME kind of
 *    audited, explicit escape hatch as `lint_ok`/`allow_secret` elsewhere in
 *    this engine, not a silent gap.
 *
 * A row table's captured file inlines refs and plain data into ONE flat
 * `columns` map (a ref column's value is just a token string at that same
 * key — no separate "refs" bucket in the FILE, mirroring how a post's
 * `meta` map already mixes ref and non-ref values with no marker beyond the
 * manifest declaration) plus a `meta` map for whatever attached-meta table
 * is folded in. One file per ROW (`state/tables/<table>/<uuid>--<slug>.json`,
 * the SAME entity-per-file discipline posts/terms already use and for the
 * SAME reason: two branches inserting different rows of the same table must
 * never collide the way two lines appended to one shared file would).
 * Attached-meta rows get NO file and NO uuid of their own — they are pure
 * sidecar data, reconciled as an owned key-set on apply exactly like
 * postmeta (Apply::finalize_post()'s existing pattern), which is also why
 * they need no entry in duo_map at all.
 *
 * ---- Identity: two modes, one honest trade-off ----
 *
 * Posts/terms mint a uuid and store it BACK onto the row itself (_duo_uuid
 * via postmeta/termmeta) — durable even if the duo_map ledger is ever lost,
 * because the row carries its own receipt. A custom table's row has no meta
 * space of its own and MUST NOT get one added to the plugin's own schema
 * (DESIGN.md's non-negotiable "plugins work completely unmodified"), so
 * identity for a declared table's rows lives ONLY in duo_map, keyed by
 * (id_kind, local_id) -> uuid. Two identity modes, declared per table:
 *
 * - `"identity": {"mode": "mapped"}` (the default when `identity` is
 *   omitted) — a fresh row gets a random Uuid::v7(), same as posts/terms.
 *   Honest limitation, stated plainly rather than glossed over: if duo_map
 *   is ever lost for a mapped-identity table (an operator TRUNCATE, never
 *   anything this engine itself does), identity for its rows is genuinely,
 *   irrecoverably gone — recapture mints NEW random uuids for the same
 *   physical rows. This is not a gap unique to typed snapshot: an ordinary
 *   post that somehow lost its _duo_uuid postmeta row would hit the exact
 *   same fate. It is simply less disguised here, because table rows have no
 *   redundant recovery path AT ALL, whereas a post's is merely also-fragile
 *   rather than doubly-redundant. nf3_forms/nf3_fields/nf3_actions use this
 *   mode (no natural key exists — nf3_forms.key was empirically NULL on the
 *   live fixture, contradicting the one-line speculation in task #75's own
 *   description that it might serve).
 * - `"identity": {"mode": "natural_key", "column": "<col>"}` — for a table
 *   with a confirmed-stable, human-chosen, unique column (WooCommerce's
 *   `attribute_name`: verified in docs/grind/r1b-shop.md). A fresh row's
 *   uuid is DERIVED, not minted: `Uuid::v5(Uuid::NAMESPACE_DUO,
 *   "<table>:<natural key value>")` — the SAME (table, value) always
 *   produces the SAME uuid. This is deliberately stronger than mapped mode:
 *   even a fully lost duo_map self-heals on next capture, because the uuid
 *   is re-derived from the live row rather than looked up, and
 *   Ledger::set()'s own existing (uuid,id_kind) upsert semantics then
 *   correctly REBIND that recovered uuid to whatever local_id the row
 *   currently has (even a different one than before) — verified by
 *   composing the two mechanisms on paper, not by adding new special-case
 *   code: v5's determinism plus Ledger::set()'s pre-existing "a stale
 *   (id_kind, local_id) row under a different uuid loses" rule are
 *   individually simple and correctly compose into full recovery.
 *
 * `id_kind` values share duo_map.id_kind's column budget with post/term/
 * term_taxonomy (VARCHAR(16), no enum constraint — confirmed by reading
 * Ledger::ensure()'s CREATE TABLE): this file enforces that ceiling at
 * assert_row_schema() time rather than migrating the column, matching what
 * "term_taxonomy" (14 chars) already implies was a deliberate existing
 * budget. Chosen kinds for this round: nf3_form/nf3_field/nf3_action (8-10
 * chars) and attr_taxonomy (13 chars) for woocommerce_attribute_taxonomies.
 *
 * ---- Refs: required (structural) vs optional (value) ----
 *
 * A ROW table's `refs[]` entries are STRUCTURAL — exactly the FK a field/
 * action genuinely cannot exist without, the same category post_parent
 * already occupies. Capture::build_post() throws (not drop-with-warning) on
 * an unmanaged non-zero post_parent, and this file does the identical thing
 * for an unmanaged non-zero row-level ref: "capture scope must include the
 * referenced row." Spec's drop-with-warning dangling-reference convention
 * (spec/repo-format.md's "Dangling references" section, DESIGN.md finding
 * #21) is about OPTIONAL meta/option VALUES, a different category — and
 * this file honors that distinction rather than contradicting it: an
 * attached-meta table's `value_refs`-declared keys (a settings sidecar's
 * mirror of the owning row's own ref, e.g. nf3_field_meta's "parent_id"
 * key) DO drop-with-warning on an unmapped id, because dropping one
 * optional sidecar key while keeping the row and its other 20+ settings is
 * proportionate, whereas throwing away an entire field because one
 * redundant mirror value didn't resolve would not be.
 *
 * ---- Cache invalidation: declarative, never plugin PHP in the engine ----
 *
 * Ninja Forms caches a form's merged settings in nf3_upgrades, keyed by the
 * SAME id as nf3_forms.id (WPN_Helper::get_nf_cache()/update_nf_cache(),
 * read via Abstracts/ModelFactory.php) — reproduced empirically in Grind
 * R1-A: an id-reused form silently served the PREVIOUS form's stale cached
 * settings. Traced to source for this implementation (Helper.php, not
 * guessed): WPN_Helper::delete_nf_cache($id) does exactly two things —
 * `DELETE FROM nf3_upgrades WHERE id = $id` and `delete_option('nf_form_'
 * . $id)` (a legacy pre-table cache) — and ModelFactory's own cache-read
 * site (`if ($form_cache && isset($form_cache['settings'])) { ... }`)
 * correctly falls through to a FRESH read straight from nf3_forms/
 * nf3_fields when the cache is simply ABSENT. So the fix needs no eager
 * rebuild, only invalidation — and both operations are entirely generic
 * (delete a row from a named table by column, delete a named option), so a
 * table declares them WITHOUT any plugin code loading into the engine
 * (unlike the existing `interpreter` mechanism, which does load manifest-
 * shipped PHP under a defined trust boundary — this needs less trust than
 * that, so it gets a smaller mechanism):
 *   "invalidate": [
 *     {"table": "nf3_upgrades", "column": "id"},
 *     {"option_pattern": "nf_form_{id}"}
 *   ]
 * `{id}` substitutes the row's own newly-resolved local id. Run once per
 * finalized row, inside the same transaction as the rest of apply (a raw
 * DELETE fires no WordPress hooks, so this needs no special canary
 * carve-out). WooCommerce's analogous hazard (wc_attribute_taxonomies
 * TRANSIENT survives a raw table write, confirmed in docs/grind/
 * r1b-shop.md) needs no equivalent here: it is blanket, not row-id-keyed,
 * so the EXISTING top-level manifest `"rebuilders"` mechanism (a real
 * wp-cli command, `transient delete wc_attribute_taxonomies`) already
 * covers it with zero new engine code — see manifests/woocommerce.json.
 *
 * ---- What this file does NOT do (by design, this round) ----
 *
 * - No support for a ref whose TARGET column lives in an option NAME rather
 *   than a table column (WooCommerce's `woocommerce_<method>_<instance>_
 *   settings` shape, docs/grind/r1b-shop.md) — characterized as a fourth,
 *   harder mechanism there; shipping_zones/tax_rates stay honest intent
 *   markers (`authored_typed_snapshot_post_v1`), not attempted here.
 * - No cross-table delete ORDERING (children before parents): verified
 *   empirically via SHOW CREATE TABLE that nf3_fields/nf3_actions carry no
 *   real FOREIGN KEY constraint (WordPress plugins essentially never use
 *   InnoDB FK enforcement), so a same-transaction delete of a parent before
 *   its now-also-deleted children never trips a database error — cosmetic
 *   ordering, not a correctness gap, and left as a documented follow-up.
 *
 * ---- Engine boundary ----
 *
 * This file depends only on Policy/Ledger/Tokens/Canon/Uuid — never on
 * Capture/Apply/Pending/Lint/Cli, so it composes into their capture/apply/
 * lint loops via a few call sites there (see the wiring task) without this
 * file itself needing any of their in-flight changes.
 */
final class Snapshot {
    public const CLASS_ROW = 'authored_snapshot';
    public const CLASS_META = 'authored_snapshot_meta';

    /** duo_map.id_kind is VARCHAR(16) — see this file's docblock. */
    private const MAX_ID_KIND_LEN = 16;

    // ------------------------------------------------------------- manifest

    /** Entity 'type' values already spoken for by posts/terms/menus/options
     *  (Apply's own dispatch discriminant) — see row_tables()'s reserved-
     *  name guard for why a declared table can never legally reuse one. */
    private const RESERVED_TYPES = ['post', 'term', 'menu', 'options'];

    /**
     * Declared ROW tables (class === authored_snapshot), keyed by table
     * name. Throws if two tables declare the same id_kind (duo_map's
     * unique key is (id_kind, local_id); two tables sharing one id_kind
     * would collide their rows' identities the instant both have a row
     * with the same local_id) — checked here, once, rather than at every
     * lookup call site. Also throws if a table name collides with a
     * reserved entity type (see RESERVED_TYPES): a table row entity's own
     * 'type' field IS its table name (not a generic "table" wrapper plus a
     * separate name field) — the single source of truth Apply's dispatch,
     * duo_state's entity_type, and DELETE planning (which has no captured
     * file left to read a name back out of) all share, so it must never
     * collide with the four names posts/terms/menus/options already own.
     */
    public static function row_tables(Policy $policy): array {
        $out = [];
        $seenKind = [];
        foreach ($policy->declared_tables() as $name => $decl) {
            if (($decl['class'] ?? '') !== self::CLASS_ROW) {
                continue;
            }
            if (in_array($name, self::RESERVED_TYPES, true)) {
                throw new \RuntimeException(
                    "duo: table '$name' collides with a reserved entity type name (" . implode('/', self::RESERVED_TYPES) . ')'
                );
            }
            $kind = (string) ($decl['id_kind'] ?? '');
            if (isset($seenKind[$kind])) {
                throw new \RuntimeException(
                    "duo: id_kind '$kind' is declared by both '{$seenKind[$kind]}' and '$name' — "
                    . 'each authored_snapshot table needs its own unique id_kind'
                );
            }
            $seenKind[$kind] = $name;
            $out[$name] = $decl;
        }
        return $out;
    }

    /** Declared ATTACHED-META tables (class === authored_snapshot_meta), keyed by table name. */
    public static function meta_tables(Policy $policy): array {
        $out = [];
        foreach ($policy->declared_tables() as $name => $decl) {
            if (($decl['class'] ?? '') === self::CLASS_META) {
                $out[$name] = $decl;
            }
        }
        return $out;
    }

    /** meta table name => decl, grouped by owning row table name. */
    private static function meta_tables_by_owner(array $rowTables, array $metaTables): array {
        $out = [];
        foreach ($metaTables as $metaName => $metaDecl) {
            $owner = $metaDecl['attached_to']['table'] ?? null;
            if ($owner === null || !isset($rowTables[$owner])) {
                throw new \RuntimeException(
                    "duo: table '$metaName' declares class " . self::CLASS_META . ' with attached_to.table='
                    . var_export($owner, true) . ', which is not itself a declared ' . self::CLASS_ROW . ' table'
                );
            }
            $out[$owner][$metaName] = $metaDecl;
        }
        return $out;
    }

    /**
     * Stable topological order (parents before children), derived from
     * `refs[].kind` edges between declared row tables — never a manually
     * numbered "phase" a manifest author has to compute by hand. Ties
     * (tables with no ordering constraint between them) keep their
     * original declaration order (Kahn's algorithm processes $remaining
     * in-order every pass). Throws on a cycle — should never happen for a
     * legitimate manifest; silently misordering or looping forever would
     * both be worse than a loud, immediate failure.
     */
    public static function topo_order(array $rowTables): array {
        $idKindToTable = [];
        foreach ($rowTables as $name => $decl) {
            $idKindToTable[$decl['id_kind']] = $name;
        }
        $deps = [];
        foreach ($rowTables as $name => $decl) {
            $deps[$name] = [];
            foreach ($decl['refs'] ?? [] as $ref) {
                $target = $idKindToTable[$ref['kind']] ?? null;
                if ($target !== null && $target !== $name) {
                    $deps[$name][$target] = true;
                }
            }
        }
        $order = [];
        $placed = [];
        $remaining = array_keys($rowTables);
        while ($remaining) {
            $progressed = false;
            foreach ($remaining as $i => $name) {
                $ready = true;
                foreach (array_keys($deps[$name]) as $dep) {
                    if (!isset($placed[$dep])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $order[] = $name;
                    $placed[$name] = true;
                    unset($remaining[$i]);
                    $progressed = true;
                }
            }
            if (!$progressed) {
                throw new \RuntimeException(
                    'duo: cyclic ref dependency among declared tables: ' . implode(', ', $remaining)
                );
            }
        }
        return $order;
    }

    /** Rank of $table in topo order (0 = no unresolved deps). Used by the
     *  apply-phase2 ordering wiring, offset past posts/terms' own ranks. */
    public static function phase2_rank(Policy $policy, string $table): int {
        $order = self::topo_order(self::row_tables($policy));
        $idx = array_search($table, $order, true);
        return $idx === false ? 0 : $idx;
    }

    // -------------------------------------------------------------- schema

    /**
     * @return ?array<string,string> live column name => live MySQL type
     *   (e.g. "bit(1)", "int(11)", "longtext"), or null if the table
     *   doesn't exist on this environment. Fetching the TYPE alongside the
     *   name (not just names, as an earlier version of this method did) is
     *   what makes write_format() below possible — see its docblock for
     *   why BIT columns specifically need it.
     */
    private static function live_column_types(string $table): ?array {
        global $wpdb;
        $t = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $prefixed = $wpdb->prefix . $t;
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
            return null;
        }
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `$prefixed`", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[$r['Field']] = $r['Type'];
        }
        return $out;
    }

    /** @return ?string[] live column names, or null if the table doesn't exist on this environment */
    private static function live_columns(string $table): ?array {
        $types = self::live_column_types($table);
        return $types === null ? null : array_keys($types);
    }

    /**
     * Empirically confirmed the hard way (task #75 standalone verification,
     * not from MySQL documentation alone): a quoted string literal ('0' or
     * '1') assigned to a BIT(1) column does NOT parse as the decimal value
     * 0/1 the way it does for every other numeric MySQL type — reproduced
     * directly against this environment's own MariaDB: inserting the PHP
     * string "0" (or even the PHP int 0, since $wpdb->insert()/update()
     * quote every value as '%s' by default regardless of PHP type) into a
     * fresh BIT(1) test column landed as bit value 1 in EVERY case tested
     * (both "0" and "1", both string and int PHP types) — only an explicit
     * unquoted numeric literal (an explicit '%d' format, or a raw unquoted
     * SQL literal) writes the correct bit. nf3_forms.show_title/
     * clear_complete/hide_complete/logged_in and nf3_fields.required/
     * personally_identifiable are all BIT(1) — silently writing the wrong
     * boolean for every one of them on every apply, with a byte-diff
     * round-trip in the FILE (the captured "0"/"1" was always correct)
     * giving zero indication anything was wrong, is exactly the class of
     * silent corruption this project's byte-fidelity discipline exists to
     * catch. Scoped to nf3_forms/nf3_fields alone having any BIT columns at
     * all (nf3_actions.active is tinyint(1), woocommerce_attribute_
     * taxonomies.attribute_public is int(1) — neither is BIT, neither has
     * this quirk), but implemented as a live-type check, not a hardcoded
     * column list, so any FUTURE declared table with a BIT column is
     * handled correctly without anyone having to rediscover this.
     */
    private static function is_bit_column(string $liveType): bool {
        return (bool) preg_match('/^bit\(/i', $liveType);
    }

    /**
     * Build $data/$format arrays for $wpdb->insert()/update() together, in
     * matching order (wpdb's $format array is consumed POSITIONALLY via
     * array_shift() as $data is iterated — not matched by field name — so
     * the two arrays must be built in lockstep, never assembled separately
     * and zipped later). BIT columns get their value cast to a real PHP int
     * and an explicit '%d' so MySQL sees an unquoted numeric literal (see
     * is_bit_column()); everything else keeps the engine's existing '%s'-
     * for-everything default, which is correct for every other type these
     * tables use (int/varchar/longtext/timestamp/datetime all coerce a
     * quoted numeric string just fine — this is a BIT-specific quirk, not
     * a general one). A null value's format entry is irrelevant (wpdb
     * special-cases null to a literal SQL NULL before format is ever
     * applied — Apply::upsert_meta()'s existing nullable-value precedent).
     *
     * @param array<string,mixed> $data column => value, built so far
     * @param array<string,string> $colTypes live column name => MySQL type
     * @return array{0: array, 1: string[]} [$data with bit values cast to int, $format]
     */
    private static function write_format(array $data, array $colTypes): array {
        $format = [];
        foreach ($data as $col => $v) {
            if (self::is_bit_column($colTypes[$col] ?? '')) {
                $data[$col] = $v === null ? null : (int) $v;
                $format[] = '%d';
            } else {
                $format[] = '%s';
            }
        }
        return [$data, $format];
    }

    /**
     * The finding-#8 rule made absolute: every live column of a declared
     * ROW table must be exactly one of {pk, a ref column, a columns entry}
     * — checked against a live SHOW COLUMNS, not just the manifest's own
     * idea of the schema, so a plugin update that adds a column this
     * manifest has never heard of refuses capture instead of silently
     * treating the new column as invisible. A pure schema check (never
     * data-dependent), so it's cheap to always run.
     */
    public static function assert_row_schema(string $table, array $decl): void {
        $pk = (string) ($decl['pk'] ?? '');
        if ($pk === '') {
            throw new \RuntimeException("duo: table '$table' declares " . self::CLASS_ROW . " with no 'pk'");
        }
        $idKind = (string) ($decl['id_kind'] ?? '');
        if ($idKind === '' || strlen($idKind) > self::MAX_ID_KIND_LEN) {
            throw new \RuntimeException(
                "duo: table '$table' declares id_kind '$idKind' — must be 1-" . self::MAX_ID_KIND_LEN
                . ' chars (duo_map.id_kind is VARCHAR(' . self::MAX_ID_KIND_LEN . '))'
            );
        }
        $mode = $decl['identity']['mode'] ?? 'mapped';
        if (!in_array($mode, ['mapped', 'natural_key'], true)) {
            throw new \RuntimeException("duo: table '$table' declares unknown identity.mode '$mode'");
        }
        if ($mode === 'natural_key' && empty($decl['identity']['column'])) {
            throw new \RuntimeException("duo: table '$table' declares identity.mode=natural_key with no 'column'");
        }

        $colKeys = array_keys($decl['columns'] ?? []);
        $refCols = array_column($decl['refs'] ?? [], 'column');
        $overlap = array_intersect($colKeys, $refCols);
        if ($overlap) {
            throw new \RuntimeException(
                "duo: table '$table' declares column(s) in BOTH columns and refs: " . implode(', ', $overlap)
            );
        }

        $live = self::live_columns($table);
        if ($live === null) {
            throw new \RuntimeException(
                "duo: declared table '$table' does not exist on this environment (plugin inactive, or manifest stale?)"
            );
        }
        $accounted = array_merge([$pk], $colKeys, $refCols);
        $undeclared = array_diff($live, $accounted);
        if ($undeclared) {
            sort($undeclared);
            throw new \RuntimeException(
                "duo: table '$table' has undeclared column(s): " . implode(', ', $undeclared)
                . " — every real column must be classified in the manifest (as the pk, a ref, or a columns entry"
                . ' with class authored/runtime/derived/env) before this table can be captured; an FK-shaped or'
                . ' otherwise unclassified column must never silently reach canonical state'
            );
        }
        $missing = array_diff($accounted, $live);
        if ($missing) {
            sort($missing);
            throw new \RuntimeException(
                "duo: table '$table' declares column(s) absent from this environment: " . implode(', ', $missing)
                . ' (plugin schema changed? manifest may be pinned to the wrong version range)'
            );
        }
    }

    /**
     * An attached-meta table's live schema is the fixed EAV shape itself
     * (id + the owner-linking column + the key/value column pair + any
     * declared legacy mirror pair) — asserted exactly, not just "at least
     * these columns," so a plugin schema change is caught the same way
     * assert_row_schema() catches one, even though there is no per-KEY
     * enumeration to check (see this file's docblock for why that's a
     * deliberately different completeness problem for an EAV sidecar).
     */
    public static function assert_meta_schema(string $table, array $decl): void {
        $attachCol = (string) ($decl['attached_to']['column'] ?? '');
        if (($decl['attached_to']['table'] ?? '') === '' || $attachCol === '') {
            throw new \RuntimeException("duo: table '$table' declares " . self::CLASS_META . " with no attached_to.{table,column}");
        }
        $keyCol = (string) ($decl['key_column'] ?? 'meta_key');
        $valCol = (string) ($decl['value_column'] ?? 'meta_value');
        $expected = array_unique(array_filter([
            'id', $attachCol, $keyCol, $valCol,
            $decl['legacy_key_column'] ?? null, $decl['legacy_value_column'] ?? null,
        ]));
        sort($expected);

        $live = self::live_columns($table);
        if ($live === null) {
            throw new \RuntimeException("duo: declared attached-meta table '$table' does not exist on this environment");
        }
        sort($live);
        if ($expected !== $live) {
            throw new \RuntimeException(
                "duo: attached-meta table '$table' schema mismatch — expected columns [" . implode(', ', $expected)
                . '], found [' . implode(', ', $live) . '] (plugin schema changed? manifest declaration is stale)'
            );
        }
    }

    // ------------------------------------------------------------- capture

    /**
     * Full typed-snapshot capture: every declared row table's rows (in
     * topo order), each with its attached-meta sidecar folded in as a
     * `meta` map. Mirrors Capture::build()'s entity shape exactly — uuid/
     * type/path/content, where 'type' is the DECLARED TABLE NAME itself
     * (not a generic "table" wrapper — see row_tables()'s docblock for why)
     * — so the return value merges straight into Capture::build()'s own
     * $entities array with zero adaptation.
     *
     * $mint follows Capture::run() (true) vs Capture::snapshot() (false):
     * a non-minting pass never creates new identity for a previously-
     * unmanaged row (it becomes invisible, same as an un-uuid'd post is
     * invisible to a non-minting snapshot) but still re-affirms identity
     * for rows already in duo_map — see identify_row().
     *
     * @return array<int, array{uuid:string, type:string, path:string, content:string}>
     */
    public static function capture(Policy $policy, Tokens $tokens, bool $mint): array {
        $rowTables = self::row_tables($policy); // throws on duplicate id_kind
        if (!$rowTables) {
            return [];
        }
        $metaTables = self::meta_tables($policy);
        $metaByOwner = self::meta_tables_by_owner($rowTables, $metaTables); // throws on a dangling attached_to.table

        foreach ($rowTables as $table => $decl) {
            self::assert_row_schema($table, $decl);
        }
        foreach ($metaTables as $metaName => $metaDecl) {
            self::assert_meta_schema($metaName, $metaDecl);
        }

        $entities = [];
        foreach (self::topo_order($rowTables) as $table) {
            $entities = array_merge($entities, self::capture_table(
                $table, $rowTables[$table], $metaByOwner[$table] ?? [], $tokens, $mint
            ));
        }
        return $entities;
    }

    private static function capture_table(string $table, array $decl, array $metaDecls, Tokens $tokens, bool $mint): array {
        global $wpdb;
        $pk = $decl['pk'];
        $prefixed = $wpdb->prefix . $table;
        $rows = $wpdb->get_results("SELECT * FROM `$prefixed` ORDER BY `$pk` ASC", ARRAY_A) ?: [];

        $entities = [];
        foreach ($rows as $row) {
            $localId = (int) $row[$pk];
            $uuid = self::identify_row($table, $decl, $row, $localId, $mint);
            if ($uuid === null) {
                continue; // snapshot-only view, never captured before — invisible, matching posts/terms
            }

            $columns = [];
            foreach ($decl['columns'] ?? [] as $col => $rule) {
                if (($rule['class'] ?? '') !== 'authored') {
                    continue; // runtime/derived/env: excluded from canonical state entirely
                }
                $v = $row[$col] ?? null;
                $columns[$col] = is_string($v) ? $tokens->tokenize_text($v) : $v;
            }
            foreach ($decl['refs'] ?? [] as $ref) {
                $col = $ref['column'];
                $raw = (int) ($row[$col] ?? 0);
                if ($raw <= 0) {
                    $columns[$col] = null; // WordPress's own "unset" convention for an id column, e.g. post_parent=0
                    continue;
                }
                $tok = $tokens->id_to_token($raw, $ref['kind']);
                if ($tok === null) {
                    // STRUCTURAL ref: same posture as Capture::build_post()'s
                    // unmanaged post_parent — throw, don't drop-with-warning
                    // (see this file's docblock, "Refs: required vs optional").
                    throw new \RuntimeException(
                        "duo: $table row $localId has unmanaged {$ref['kind']} ref $raw in column '$col' — "
                        . 'capture scope must include the referenced row'
                    );
                }
                $columns[$col] = $tok;
            }

            $meta = [];
            foreach ($metaDecls as $metaName => $metaDecl) {
                $meta = array_merge($meta, self::capture_meta_rows($metaName, $metaDecl, $localId, $tokens));
            }

            $front = [
                'columns' => (object) $columns,
                'meta' => (object) $meta,
                'table' => $table,
                'uuid' => $uuid,
            ];
            $slug = self::slug_for($decl, $row, $localId);
            $entities[] = [
                'uuid' => $uuid,
                'type' => $table,
                'path' => "tables/$table/$uuid--$slug.json",
                'content' => Canon::encode($front),
            ];
        }
        return $entities;
    }

    /**
     * Identity for one row: reuse the existing duo_map entry if one exists
     * (and re-affirm it via Ledger::set(), which self-heals a reused-
     * local_id collision — the SAME hygiene posts/terms already get on
     * every capture, now extended to table rows that have no meta-column
     * fallback of their own to fall back on). A never-seen row only gets
     * NEW identity when $mint is true; see this file's docblock for the
     * mapped-vs-natural_key mode split.
     */
    private static function identify_row(string $table, array $decl, array $row, int $localId, bool $mint): ?string {
        $idKind = $decl['id_kind'];
        $uuid = Ledger::uuid_for($localId, $idKind);
        if ($uuid === null) {
            if (!$mint) {
                return null;
            }
            $mode = $decl['identity']['mode'] ?? 'mapped';
            if ($mode === 'natural_key') {
                $col = $decl['identity']['column'];
                $key = (string) ($row[$col] ?? '');
                if ($key === '') {
                    throw new \RuntimeException(
                        "duo: table '$table' row $localId: identity.mode=natural_key column '$col' is empty — "
                        . 'cannot mint a stable identity for this row'
                    );
                }
                $uuid = Uuid::v5(Uuid::NAMESPACE_DUO, "$table:$key");
            } else {
                $uuid = Uuid::v7();
            }
        }
        Ledger::set($uuid, $table, $idKind, $localId);
        return $uuid;
    }

    private static function slug_for(array $decl, array $row, int $localId): string {
        $col = $decl['slug_column'] ?? null;
        $raw = $col !== null ? (string) ($row[$col] ?? '') : '';
        $slug = $raw !== '' ? sanitize_title($raw) : '';
        return $slug !== '' ? $slug : (string) $localId;
    }

    /**
     * One attached-meta table's rows for a single owner, as a flat
     * key=>value map. Throws on a genuine SQL-level duplicate key for the
     * same owner (mirrors Capture::build_post()'s identical multi-value
     * guard for authored post_meta — an unexpected shape, not something to
     * silently pick a winner for). Ref-declared keys drop-with-warning on
     * an unmapped id (the OPTIONAL-value convention — see this file's
     * docblock); everything else is opaque text, tokenize_text()'d for
     * URL safety only — deliberately never unserialize()'d/re-encoded even
     * when the value LOOKS like PHP-serialized data (Ninja Forms'
     * `formContentData`/`calculations`/choice-field `options` values all
     * do), because nothing observed inside them is ever a numeric ref
     * (confirmed empirically — see the manifest's own notes) and byte-
     * verbatim passthrough is strictly safer than a decode/re-encode round
     * trip this mechanism doesn't need to attempt.
     */
    private static function capture_meta_rows(string $metaTable, array $decl, int $ownerLocalId, Tokens $tokens): array {
        global $wpdb;
        $prefixed = $wpdb->prefix . $metaTable;
        $attachCol = $decl['attached_to']['column'];
        $keyCol = $decl['key_column'] ?? 'meta_key';
        $valCol = $decl['value_column'] ?? 'meta_value';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `$keyCol` AS k, `$valCol` AS v FROM `$prefixed` WHERE `$attachCol` = %d ORDER BY id ASC",
            $ownerLocalId
        ), ARRAY_A) ?: [];

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[(string) $r['k']][] = $r['v'];
        }

        $keyRules = $decl['keys'] ?? [];
        $default = $decl['default_class'] ?? 'authored';
        $out = [];
        foreach ($byKey as $key => $values) {
            if (count($values) > 1) {
                throw new \RuntimeException(
                    "duo: multi-value meta key '$key' in $metaTable for parent $ownerLocalId "
                    . '(found ' . count($values) . ' rows) — unsupported'
                );
            }
            $rule = $keyRules[$key] ?? ['class' => $default];
            $class = $rule['class'] ?? $default;
            if ($class !== 'authored') {
                continue; // runtime/derived/env sidecar key — excluded, mirrors post_meta
            }
            $v = $values[0];
            if (!empty($rule['ref'])) {
                $n = (int) $v;
                if ($n <= 0) {
                    $out[$key] = null;
                    continue;
                }
                $tok = $tokens->id_to_token($n, $rule['ref']); // warns internally if unmapped
                if ($tok === null) {
                    continue; // OPTIONAL value ref: drop-with-warning, not a row-level throw
                }
                $out[$key] = $tok;
            } elseif (is_string($v)) {
                $out[$key] = $tokens->tokenize_text($v);
            } else {
                $out[$key] = $v;
            }
        }
        return $out;
    }

    // --------------------------------------------------------------- apply

    /**
     * Load every captured table row into the SAME shape Apply::load_tree()
     * assembles for posts/terms/menus/options — merge the return value into
     * that method's own $out array. 'type' is the table name itself (see
     * row_tables()'s docblock for why), read back from the file's own front
     * matter — never guessed from the directory path, which is cosmetic.
     *
     * @return array<string, array{type:string, path:string, hash:string, content:string}>
     */
    public static function load_tree_entries(string $stateDir): array {
        $out = [];
        foreach (glob($stateDir . '/tables/*/*.json') ?: [] as $f) {
            $content = Canon::read_file($f);
            $front = Canon::decode($content);
            $out[$front['uuid']] = [
                'type' => $front['table'],
                'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', $content),
                'content' => $content,
            ];
        }
        return $out;
    }

    /**
     * Collision/adoption check for Apply::find_collision()'s table-entity
     * branch: only natural_key-identity tables have a meaningful notion of
     * "this row already exists, unmanaged, under a different/no uuid" (a
     * pre-provisioned WooCommerce attribute, matching find_collision()'s
     * existing slug-based adoption for posts/terms by the same idea, keyed
     * on the table's OWN natural key instead of a slug). mapped-identity
     * tables have no natural collision key at all — always create fresh.
     */
    public static function find_collision(Policy $policy, array $entity): ?int {
        global $wpdb;
        $decl = self::row_tables($policy)[$entity['type']] ?? null;
        if ($decl === null || ($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return null;
        }
        $front = Canon::decode($entity['content']);
        $col = $decl['identity']['column'];
        $value = $front['columns'][$col] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        $prefixed = $wpdb->prefix . $entity['type'];
        $pk = $decl['pk'];
        $id = $wpdb->get_var($wpdb->prepare("SELECT `$pk` FROM `$prefixed` WHERE `$col` = %s LIMIT 1", $value));
        return $id !== null ? (int) $id : null;
    }

    /** Claim an unmanaged env row by writing identity only — table rows have
     *  no meta-column identity marker to also write, unlike adopt() for
     *  posts/terms in Apply.php. */
    public static function adopt(Policy $policy, string $uuid, string $table, int $envId): void {
        $decl = self::row_tables($policy)[$table];
        Ledger::set($uuid, $table, $decl['id_kind'], $envId);
    }

    /**
     * Phase 1: insert a placeholder row (every authored column at its real,
     * final value — none of them depend on another row in this same apply
     * run; every ref column at a 0 placeholder, resolved in phase 2).
     *
     * Takes the tree entry (as built by load_tree_entries() / Apply's own
     * $tree) — NOT a shape carrying its own 'uuid' field (matching
     * Apply::load_tree()'s existing convention for posts/terms/menus, where
     * uuid is the array KEY, never a value field too); the uuid actually
     * used is decoded from the file's own front matter, exactly like
     * Apply::ensure_post_row($front) already reads $front['uuid']-equivalent
     * information from decoded content rather than a caller-supplied field.
     *
     * @return bool true when a new row was inserted (false: already adopted
     *   or ledger'd from a prior pass in this same run)
     */
    public static function ensure_row(Policy $policy, array $entity): bool {
        global $wpdb;
        $decl = self::row_tables($policy)[$entity['type']];
        $idKind = $decl['id_kind'];
        $front = Canon::decode($entity['content']);
        $uuid = $front['uuid'];
        if (Ledger::id_for($uuid, $idKind) !== null) {
            return false;
        }
        $prefixed = $wpdb->prefix . $entity['type'];
        $colTypes = self::live_column_types($entity['type']) ?? [];

        $data = [];
        foreach ($decl['columns'] ?? [] as $col => $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $data[$col] = $front['columns'][$col] ?? null;
        }
        foreach ($decl['refs'] ?? [] as $ref) {
            $data[$ref['column']] = 0;
        }
        [$data, $format] = self::write_format($data, $colTypes);
        $wpdb->insert($prefixed, $data, $format);
        $localId = (int) $wpdb->insert_id;
        Ledger::set($uuid, $entity['type'], $idKind, $localId);
        return true;
    }

    /**
     * Phase 2: resolve every ref column through the ledger, reconcile the
     * attached-meta sidecar as an owned key-set, run any declared
     * per-row cache invalidation.
     */
    public static function finalize_row(Policy $policy, Tokens $tokens, array $entity): void {
        global $wpdb;
        $decl = self::row_tables($policy)[$entity['type']];
        $idKind = $decl['id_kind'];
        $front = Canon::decode($entity['content']);
        $uuid = $front['uuid'];
        $localId = Ledger::id_for($uuid, $idKind)
            ?? throw new \RuntimeException("duo: table row $uuid ({$entity['type']}) missing from ledger after phase 1");
        $prefixed = $wpdb->prefix . $entity['type'];
        $pk = $decl['pk'];
        $colTypes = self::live_column_types($entity['type']) ?? [];

        $data = [];
        foreach ($decl['columns'] ?? [] as $col => $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $v = $front['columns'][$col] ?? null;
            $data[$col] = is_string($v) ? $tokens->detokenize_text($v) : $v;
        }
        foreach ($decl['refs'] ?? [] as $ref) {
            $col = $ref['column'];
            $v = $front['columns'][$col] ?? null;
            $data[$col] = $v === null ? 0 : $tokens->token_to_id((string) $v);
        }
        [$data, $format] = self::write_format($data, $colTypes);
        $wpdb->update($prefixed, $data, [$pk => $localId], $format, '%d');

        foreach (self::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $entity['type']) {
                continue;
            }
            self::reconcile_meta($metaName, $metaDecl, $localId, (array) ($front['meta'] ?? []), $tokens);
        }

        foreach ($decl['invalidate'] ?? [] as $inv) {
            self::run_invalidate($inv, $localId);
        }
    }

    /**
     * Reconcile an attached-meta table's rows for one owner to exactly the
     * desired key set — same "delete what's no longer wanted, upsert the
     * rest" shape as Apply::finalize_post()'s postmeta reconciliation.
     * Writes BOTH column pairs when a legacy pair is declared (matching
     * what Ninja Forms' own code does — confirmed empirically identical on
     * both pairs, every row, in docs/grind/r1a-forms.md's evidence).
     */
    private static function reconcile_meta(string $metaTable, array $decl, int $ownerLocalId, array $desiredMeta, Tokens $tokens): void {
        global $wpdb;
        $prefixed = $wpdb->prefix . $metaTable;
        $attachCol = $decl['attached_to']['column'];
        $keyCol = $decl['key_column'] ?? 'meta_key';
        $valCol = $decl['value_column'] ?? 'meta_value';
        $keyRules = $decl['keys'] ?? [];
        $default = $decl['default_class'] ?? 'authored';

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT id, `$keyCol` AS k FROM `$prefixed` WHERE `$attachCol` = %d", $ownerLocalId
        ), ARRAY_A) ?: [];
        $existingByKey = [];
        foreach ($existing as $r) {
            $existingByKey[(string) $r['k']] = (int) $r['id'];
        }

        $desiredRaw = [];
        foreach ($desiredMeta as $key => $v) {
            $rule = $keyRules[$key] ?? ['class' => $default];
            if (!empty($rule['ref'])) {
                $desiredRaw[$key] = $v === null ? '0' : (string) $tokens->token_to_id((string) $v);
            } elseif (is_string($v)) {
                $desiredRaw[$key] = $tokens->detokenize_text($v);
            } else {
                $desiredRaw[$key] = $v === null ? null : (string) $v;
            }
        }

        foreach ($existingByKey as $key => $rowId) {
            if (!array_key_exists($key, $desiredRaw)) {
                $wpdb->delete($prefixed, ['id' => $rowId]);
            }
        }
        foreach ($desiredRaw as $key => $val) {
            $data = [$attachCol => $ownerLocalId, $keyCol => $key, $valCol => $val];
            if (isset($decl['legacy_key_column'])) {
                $data[$decl['legacy_key_column']] = $key;
            }
            if (isset($decl['legacy_value_column'])) {
                $data[$decl['legacy_value_column']] = $val;
            }
            if (isset($existingByKey[$key])) {
                $wpdb->update($prefixed, $data, ['id' => $existingByKey[$key]]);
            } else {
                $wpdb->insert($prefixed, $data);
            }
        }
    }

    /**
     * A declared `invalidate` entry: either a targeted row delete on a
     * named table/column (nf3_upgrades keyed by the SAME id as the row
     * just written) or a named option, with `{id}` substituted for the
     * row's own local id — see this file's docblock, "Cache invalidation."
     * Both are generic, plugin-blind primitives; the table branch no-ops
     * quietly when the target table doesn't exist on this environment
     * (mirrors Apply::count_guard_refs()'s identical "table absent" guard).
     */
    private static function run_invalidate(array $inv, int $localId): void {
        global $wpdb;
        if (isset($inv['table'])) {
            $t = preg_replace('/[^A-Za-z0-9_]/', '', $inv['table']);
            $col = preg_replace('/[^A-Za-z0-9_]/', '', $inv['column'] ?? 'id');
            $prefixed = $wpdb->prefix . $t;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
                $wpdb->query($wpdb->prepare("DELETE FROM `$prefixed` WHERE `$col` = %d", $localId));
            }
        }
        if (isset($inv['option_pattern'])) {
            delete_option(str_replace('{id}', (string) $localId, (string) $inv['option_pattern']));
        }
    }

    /**
     * Delete one row and its attached-meta sidecar (flag-gated by the
     * caller, matching posts/terms — Apply::delete_entity()'s existing
     * with-deletes convention). No cross-table ordering: see this file's
     * docblock, "What this file does NOT do."
     */
    public static function delete_row(Policy $policy, string $uuid, string $table): void {
        global $wpdb;
        $decl = self::row_tables($policy)[$table] ?? null;
        if ($decl === null) {
            return; // table no longer declared (manifest unpinned) — nothing safe to do
        }
        $idKind = $decl['id_kind'];
        $localId = Ledger::id_for($uuid, $idKind);
        if ($localId === null) {
            return;
        }
        foreach (self::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            $wpdb->delete($wpdb->prefix . $metaName, [$metaDecl['attached_to']['column'] => $localId]);
        }
        foreach ($decl['invalidate'] ?? [] as $inv) {
            self::run_invalidate($inv, $localId);
        }
        $wpdb->delete($wpdb->prefix . $table, [$decl['pk'] => $localId]);
        Ledger::forget($uuid);
    }

    // ------------------------------------------------------------- ledger

    /**
     * Ledger::prune_dead_table_map()'s policy-aware caller — run this
     * alongside the existing Ledger::prune_dead_map() at every capture/
     * snapshot call site (see the wiring task): declared-table id_kinds
     * need the SAME dead-map hygiene post/term/term_taxonomy already get,
     * built from whichever manifests are CURRENTLY pinned (an unpinned
     * table's id_kind is simply not checked — its rows, if any duo_map
     * entries remain, are inert until/unless the manifest is pinned again).
     */
    public static function prune_dead_map(Policy $policy): void {
        $tables = [];
        foreach (self::row_tables($policy) as $name => $decl) {
            $tables[$decl['id_kind']] = ['table' => $name, 'pk' => $decl['pk']];
        }
        if ($tables) {
            Ledger::prune_dead_table_map($tables);
        }
    }
}
