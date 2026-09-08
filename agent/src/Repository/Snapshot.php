<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/SerializedDataPreflight.php';

require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';
require_once __DIR__ . '/../Kernel/OrderPreserved.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Kernel/ReferenceRules.php';
require_once __DIR__ . '/../Kernel/TableGraph.php';
require_once __DIR__ . '/../Kernel/TableSchema.php';
require_once __DIR__ . '/SnapshotIdentity.php';
require_once __DIR__ . '/SnapshotPruner.php';
require_once __DIR__ . '/../Capture/TypedTableCapture.php';
require_once __DIR__ . '/../Apply/TypedTableMaterializer.php';

/**
 * Typed snapshot: capture/apply for authored custom tables (DESIGN.md §3.3's
 * "middle tier" the design review named but never built — finding #8: opaque
 * pick-side replay of FK-bearing custom-table rows corrupts silently, so
 * refusing opaque mode isn't enough; something has to actually capture these
 * tables safely). Primary fixture: Ninja Forms' nf3_forms/nf3_fields/
 * nf3_actions + their _meta twins (task #75, grind round R1-A).
 * Secondary: WooCommerce's woocommerce_attribute_taxonomies (grind round
 * R1-B). Both grounded empirically on live installs, not assumed — see the
 * manifests' own notes for the DESCRIBE/data evidence, and
 * sandbox/tests/grind/grind_r1a_forms.sh / grind_r1b_shop.sh for the runnable
 * rounds themselves.
 *
 * ---- The two table shapes ----
 *
 * A manifest's `"tables"` section entry is one of two class values (a THIRD,
 * `"authored_typed_snapshot_post_v1"`, remains the pre-existing honest-intent
 * marker for tables nobody has implemented yet — this file gives no meaning
 * to it, on purpose, exactly as before):
 *
 * 1. `"authored_snapshot"` — a ROW table: has its own primary key and its
 *    own identity (a wprism_map ledger row). Every real, live column MUST be
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
 * they need no entry in wprism_map at all.
 *
 * ---- Identity: three modes, one recovery contract ----
 *
 * Posts/terms mint a uuid and store it BACK onto the row itself (_wprism_uuid
 * via postmeta/termmeta) — durable even if the wprism_map ledger is ever lost,
 * because the row carries its own receipt. A custom table's row has no meta
 * space of its own and MUST NOT get one added to the plugin's own schema
 * (DESIGN.md's non-negotiable "plugins work completely unmodified"), so
 * identity for a declared table's rows lives ONLY in wprism_map, keyed by
 * (id_kind, local_id) -> uuid. Three identity modes, declared per table:
 *
 * - `"identity": {"mode": "mapped"}` (the default when `identity` is
 *   omitted) — a fresh row gets a random Uuid::v7(), same as posts/terms.
 *   Because the plugin row cannot carry the UUID, `wprism_map` plus the
 *   `wprism-identity-ledger/v1` disaster-recovery sidecar is the durable store.
 *   A populated non-minting target with a missing mapping blocks; a source
 *   whose canonical mapped UUIDs lost their mappings also blocks instead of
 *   minting replacements. nf3_forms/nf3_fields/nf3_actions use this mode (no
 *   natural key exists — nf3_forms.key was empirically NULL on the live
 *   fixture, contradicting the one-line speculation in task #75's own
 *   description that it might serve).
 * - `"identity": {"mode": "natural_key", "column": "<col>"}` — for a table
 *   with a confirmed-stable, human-chosen, unique column (WooCommerce's
 *   `attribute_name`: WooCommerce's own admin/wc-cli create path refuses a
 *   duplicate name at the application layer, which is what makes it stable in
 *   practice even though the DB index is merely MUL — verified live in grind
 *   round R1-B). A fresh row's
 *   uuid is DERIVED, not minted: `Uuid::v5(Uuid::NAMESPACE_WPRISM,
 *   "<table>:<natural key value>")` — the SAME (table, value) always
 *   produces the SAME uuid. This is BOOTSTRAP identity for a never-seen row;
 *   after first capture, the existing ledger mapping is consulted first and
 *   provides rename continuity. Consequently a mapped row whose natural key
 *   changes keeps its uuid, even though UUIDv5 of the current key differs.
 *   A fresh environment independently capturing that renamed row without
 *   ledger/repository history derives from the new key instead; table adopt
 *   is the reconciliation path. Contradictory mappings still block and
 *   ordinary Ledger::set() never deletes or rebinds identity implicitly.
 *
 *   `"columns": ["<col>", ...]` (issue #3318) is the PARENT-SCOPED form of the
 *   same mode, for the far more common real schema: a key that is unique
 *   only WITHIN a parent row (a slot code unique per room, an option key
 *   unique per form). `column` is exactly its 1-component case, unchanged
 *   and frozen. A component that is a declared `refs[]` column contributes
 *   the REFERENCED ROW'S OWN UUID, not the local id sitting in the column —
 *   the identical argument composite_ref's docblock makes below, for the
 *   identical reason: an auto-increment parent id would derive a different
 *   uuid per environment for the same authored fact. A scalar component
 *   contributes its raw value. `pk` stays REQUIRED here (unlike
 *   composite_ref): the table still has its own surrogate primary key, and
 *   wprism_map's local_id stays that plain scalar — no packing, so delete,
 *   adopt, and `invalidate` all keep working exactly as they do for any
 *   other row table. Filenames need `slug_column` for this form (a tuple has
 *   no portable one-line spelling; see ManifestGrammar::assert_natural_key_grammar()).
 * - `"identity": {"mode": "composite_ref", "columns": ["<col1>", "<col2>"]}`
 *   (issue #3235, task #125) — for a PURE JOIN table: no surrogate `pk` column
 *   exists at all, and its real, live composite PRIMARY KEY is exactly the
 *   two FK columns already declared in `refs[]` (checked as an exact set
 *   equality in assert_composite_row_schema() — `identity.columns` must be
 *   precisely the table's `refs[]` columns, neither more nor fewer: this
 *   mode is for tables where the identity IS the pair of things referenced,
 *   nothing else). Proving fixture: PMPro's `pmpro_memberships_pages`
 *   (`membership_id` -> a declared `pmpro_level` row, `page_id` -> a post) —
 *   DESCRIBE'd live: `PRIMARY KEY (page_id, membership_id)`, no `id` column
 *   at all — PMPro's real "Require Membership" content-restriction fact.
 *
 *   The uuid is derived, like natural_key mode, but from a DIFFERENT input:
 *   `Uuid::v5(NAMESPACE_WPRISM, "<table>:<col1>=<ref1-uuid>:<col2>=<ref2-uuid>")`
 *   — the tuple of the REFERENCED ROWS' OWN uuids, never the raw local ids
 *   the live join row currently holds. This is load-bearing, not a stylistic
 *   choice: unlike `attribute_name` (a human-authored STRING, stable and
 *   portable across every environment by construction), `membership_id`/
 *   `page_id` are environment-local auto-increment integers — the exact
 *   category this whole engine's token grammar exists to make non-portable.
 *   Deriving from raw ids would mint a DIFFERENT uuid for "the same fact" on
 *   every environment (source captures level-2/page-14 as one uuid; a fresh
 *   target's own local ids for the identical two entities are unrelated
 *   numbers, so recapturing there would derive a second, different uuid for
 *   what is semantically one authored fact) — silently breaking this
 *   project's entire "one immutable revision, one meaning" identity model
 *   for this table class alone. Deriving from the referenced uuids instead
 *   is exactly "natural key over the tuple," just one level of indirection
 *   from a raw column value: the natural key of a join row, in a system
 *   whose whole point is that portable identity is the uuid, not the local
 *   id, is unavoidably the pair of uuids it joins.
 *
 *   Consequence, stated as designed rather than discovered as a limitation:
 *   a composite_ref row's identity is a PURE FUNCTION of its two resolved
 *   refs, recomputed fresh on every capture — never looked up, nothing to
 *   "mint." So unlike mapped mode, there is no un-minted state to gate
 *   behind `$mint`, and unlike EITHER other mode, wprism_map's role shrinks to
 *   pure bookkeeping for delete_row() (see pack_composite_id()'s docblock),
 *   never consulted to establish identity itself — the strongest of the
 *   three modes' self-healing properties, precisely because there was never
 *   a scalar local_id to lose in the first place. A ref that fails to
 *   resolve throws unconditionally (identify_composite_row(), same
 *   structural posture as an ordinary row's refs[] — see "Refs: required
 *   vs optional" below — deliberately NOT softened for a merely-snapshot
 *   call: Capture::snapshot() already throws identically for an ordinary
 *   already-mapped row's broken structural ref, so composite_ref does not
 *   invent a new asymmetry here, it just extends the existing one).
 *
 *   "Reconciliation is exists/absent, no update bucket" (the framing this
 *   mode was commissioned under) is true of the IDENTITY itself — changing
 *   either resolved ref changes the uuid, i.e. is a different fact, not an
 *   edited one — but this mode still allows an ordinary `columns{}` map for
 *   any OTHER live column (PMPro's own `modified` — an auto `ON UPDATE
 *   CURRENT_TIMESTAMP()` column, confirmed live, classified `runtime` in
 *   the shipped manifest) or even genuine extra AUTHORED data columns (a
 *   real, DESCRIBE'd but NOT this round's fixture: `pmpro_discount_codes_
 *   levels` has a composite `(code_id, level_id)` PK plus nine real pricing-
 *   override columns) — TypedTableMaterializer's composite phase upserts
 *   those normally.
 *   "No update bucket" describes the two-column pure-join proving fixture's
 *   OWN observed behavior (plan can never place it in `update`, because
 *   there is no content that can change independent of identity — verified
 *   live, not merely asserted, by sandbox/tests/live/regress_pmpro_composite_ref.sh's
 *   own `.plan.update == 0` assertion across two full round-trips; Apply::
 *   build_plan() is out of reach of this file's OWN offline harness,
 *   regress_composite_ref.php, by design — see this file's docblock,
 *   "Engine boundary"), not a hard restriction the grammar itself imposes
 *   on every future composite_ref table.
 *
 *   Mutation continuity (cross-ref issue #3237): composite_ref's two columns
 *   are ALWAYS refs, so their stability is entirely inherited from whatever
 *   identity mode the referenced table already uses. A mapped natural_key
 *   target keeps its ledger UUID across a key rename, so a composite_ref row
 *   referencing it keeps the same derived tuple UUID too. This fixture's two ref
 *   targets — `pmpro_level` (mapped identity: a random v7, stored in
 *   wprism_map, untouched by editing `name`) and `post` (the built-in
 *   `_wprism_uuid` postmeta, untouched by editing title/slug) — are both
 *   likewise rename-stable. A fresh environment independently bootstrapping
 *   an already-renamed natural_key target without its ledger/repository
 *   history can derive a different target UUID and thus a different tuple;
 *   that is the same documented bootstrap/continuity boundary, not a new
 *   composite_ref mutation rule. The DIFFERENT, ORDINARY-OPERATION question — an admin re-points a
 *   restriction from one page to another — is empirically answered (not
 *   merely argued) by regress_pmpro_composite_ref.sh's step 8: delete-old-
 *   uuid + create-new-uuid + zero updates, live. This is the correct
 *   outcome, not a rename-continuity failure: unlike an attribute key edit,
 *   a join row's
 *   tuple IS its entire meaning — "level 2 restricts page 14" and "level 2
 *   restricts page 20" are two DIFFERENT facts, not one fact relabeled, so
 *   delete+create is the semantically correct shape, matching what hand-
 *   edited canonical JSON files would show too (one deleted, one created).
 *
 * `id_kind` values share wprism_map.id_kind's column budget with post/term/
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
 * carve-out). WooCommerce's analogous hazard (the wc_attribute_taxonomies
 * TRANSIENT survives a raw table write and then produces a real "slug already
 * in use" error against an empty table — confirmed live in grind round R1-B)
 * needs no equivalent here: it is blanket, not row-id-keyed,
 * so the top-level manifest `"actions"` channel already covers it with zero
 * plugin logic in engine code: the closed native action `transient.delete`,
 * named with `wc_attribute_taxonomies` as structured data — see
 * manifests/woocommerce.json.
 *
 * ---- What this file does NOT do (by design, this round) ----
 *
 * - No direct WooCommerce CRUD/API or plugin-hook emulation for typed-row
 *   deletes: authored Woo rows and option-name refs are represented and
 *   guarded generically from their manifest declarations, while Woo's
 *   version-pinned cache boundaries are run by Apply's manifest-declared
 *   rebuild actions.
 *   Plugin-specific side effects outside those declared state and cache
 *   boundaries remain unsupported until a manifest capability grants them
 *   explicit authority.
 * - Delete ordering is the inverse of creation ordering: Apply's
 *   deletion_rank() sorts declared typed rows child-before-parent, while
 *   Snapshot::phase2_rank() keeps ordinary writes parent-before-child. The
 *   order is deterministic even though WordPress plugin tables rarely carry
 *   real InnoDB foreign keys; it makes child cleanup/cache invalidation happen
 *   before the parent tombstone and keeps the boundary safe if a future
 *   adapter does add an FK.
 *
 * ---- Engine boundary ----
 *
 * This file depends only on Policy/Ledger/Tokens/Canon/Uuid and the extracted
 * SnapshotIdentity boundary — never on
 * Capture/Apply/Pending/Lint/Cli, so it composes into their capture/apply/
 * lint loops via a few call sites there (see the wiring task) without this
 * file itself needing any of their in-flight changes.
 */
final class Snapshot {
    public const CLASS_ROW = TableGraph::CLASS_ROW;
    public const CLASS_META = TableGraph::CLASS_META;

    /** Single source of truth for "is this authored_snapshot declaration a
     *  composite_ref (pure join table) identity" — every dispatch site below
     *  (schema assertion, capture, ensure/finalize, delete, ledger hygiene)
     *  branches on this SAME check, so the mode can never register as
     *  composite_ref in one place and fall through to mapped/natural_key
     *  logic in another. See this file's docblock, "Identity: three modes." */
    private static function is_composite_ref(array $decl): bool {
        return TableGraph::is_composite_ref($decl);
    }

    // ------------------------------------------------------------- manifest

    /**
     * Declared ROW tables (class === authored_snapshot), keyed by table
     * name. Throws if two tables declare the same id_kind (wprism_map's
     * unique key is (id_kind, local_id); two tables sharing one id_kind
     * would collide their rows' identities the instant both have a row
     * with the same local_id) — checked here, once, rather than at every
     * lookup call site. Also throws if a table name collides with a
     * reserved entity type (see RESERVED_TYPES): a table row entity's own
     * 'type' field IS its table name (not a generic "table" wrapper plus a
     * separate name field) — the single source of truth Apply's dispatch,
     * wprism_state's entity_type, and DELETE planning (which has no captured
     * file left to read a name back out of) all share, so it must never
     * collide with the four names posts/terms/menus/options already own.
     */
    public static function row_tables(Policy $policy): array {
        return TableGraph::row_tables($policy->declared_tables());
    }

    /**
     * issue #3246: repairs wprism_map/wprism_state rows whose entity_type was
     * silently truncated by the pre-fix VARCHAR(32) schema — a table row's
     * entity_type IS its table name (see this function's own caller,
     * row_tables(), and this file's docblock), and two currently-shipped
     * tables (woocommerce_shipping_zone_locations, woocommerce_shipping_
     * zone_methods) exceed 32 chars.
     *
     * A migration-time direct UPDATE, deliberately never Ledger::set():
     * issue #3209's identity-contradiction guard exists specifically to
     * refuse an ORDINARY code path silently retyping an identity —
     * repairing a KNOWN truncation artifact is not an ordinary retype, it
     * is restoring the value that should have been written the first
     * time. Routing this through Ledger::set() would immediately hit the
     * exact guard this function exists to get past; going around it here,
     * once, for a named and understood reason, is not the same thing as
     * softening the guard itself (see Ledger::set()'s own docblock — that
     * guard's semantics are not this file's to change unilaterally).
     *
     * Detection: entity_type values sitting EXACTLY at the old 32-char
     * ceiling that are an UNAMBIGUOUS prefix of one of THIS policy's
     * currently declared row-table names (mapped/natural_key AND
     * composite_ref alike — row_tables() already returns both). A 32-char
     * value that is genuinely, intentionally 32 chars (one exists today:
     * woocommerce_attribute_taxonomies) is left alone — it is not a
     * prefix of any OTHER declared name, so it never matches. If a
     * truncated prefix were ever ambiguous between two declared tables,
     * this skips it rather than guessing (loud-not-silent, same posture
     * as every other identity decision in this file).
     *
     * Idempotent: a policy with no truncated rows costs a handful of cheap
     * queries and mutates nothing. Safe to call on every capture/plan/
     * apply/deploy entry point (see its own call sites).
     *
     * @return string[] human-readable "table: old -> new (N rows)" repair
     *   log entries, empty when nothing needed fixing (for regression
     *   evidence and CLI/log visibility — not required by callers)
     */
    public static function repair_truncated_entity_types(Policy $policy): array {
        global $wpdb;
        // Every production entry point completes Ledger::ensure() before it
        // reaches this helper. Do not repeat it here: Snapshot::capture() is
        // called inside Capture's consistent-snapshot transaction and even
        // CREATE TABLE IF NOT EXISTS causes an implicit commit in MySQL and
        // MariaDB. That would make an earlier dead-map prune durable when a
        // later capture gate refuses the candidate, defeating Capture's
        // rollback guarantee. Schema initialization/migration therefore
        // belongs to the entry-point boundary; this helper is DML-only so it
        // remains safe both inside Capture's transaction and at Apply's
        // already-initialized call sites.
        $declared = array_keys(self::row_tables($policy));
        $oldCeiling = 32;
        $long = array_filter($declared, fn($name) => strlen($name) > $oldCeiling);
        $repaired = [];
        foreach ($long as $full) {
            $truncated = substr($full, 0, $oldCeiling);
            $ambiguous = false;
            foreach ($long as $other) {
                if ($other !== $full && substr($other, 0, $oldCeiling) === $truncated) {
                    $ambiguous = true;
                    break;
                }
            }
            if ($ambiguous) {
                continue;
            }
            foreach (['wprism_map', 'wprism_state'] as $table) {
                $affected = Db::mutation(
                    $wpdb->prepare(
                        "UPDATE {$wpdb->prefix}{$table} SET entity_type = %s",
                        $full
                    ),
                    $wpdb->prepare('entity_type = %s', $truncated),
                    '',
                    "ledger repair truncated entity_type in $table ($truncated -> $full)"
                );
                if ($affected > 0) {
                    $repaired[] = "$table: '$truncated' -> '$full' ($affected row" . ($affected === 1 ? '' : 's') . ')';
                }
            }
        }
        return $repaired;
    }

    /** Declared ATTACHED-META tables (class === authored_snapshot_meta), keyed by table name. */
    public static function meta_tables(Policy $policy): array {
        return TableGraph::meta_tables($policy->declared_tables());
    }

    /**
     * A mapped table may mint genuinely new live rows, but it may not mint
     * replacements for canonical identities whose ledger rows disappeared.
     * That shape is indistinguishable from a database restore without its
     * identity sidecar, so capture fails closed and asks for recovery.
     */
    public static function assert_mapped_history_present(
        Policy $policy,
        string $repo,
        array $observedDeleted = []
    ): void {
        $observedDeleted = array_fill_keys($observedDeleted, true);
        foreach (self::row_tables($policy) as $table => $decl) {
            if (($decl['identity']['mode'] ?? 'mapped') !== 'mapped') {
                continue;
            }
            foreach (glob(rtrim($repo, '/') . "/state/tables/$table/*.json") ?: [] as $file) {
                $front = Canon::decode(Canon::read_file($file));
                $uuid = (string) ($front['uuid'] ?? '');
                if (!Uuid::is($uuid)
                    || (Ledger::id_for($uuid, $decl['id_kind']) === null && !isset($observedDeleted[$uuid]))) {
                    throw new \RuntimeException(
                        "wprism: mapped identity history is missing for canonical $table entity $uuid; "
                        . 'refusing to mint a replacement. Restore the database-matched identity sidecar with '
                        . '`wp wprism identity-import --repo=<repo> --in=<file>` before capture'
                    );
                }
            }
        }
    }

    /**
     * Record mapped identities whose exact local row is absent before dead-
     * map pruning. Capture may turn only these witnessed disappearances into
     * tombstones; a canonical mapped UUID with no mapping at all remains the
     * database/sidecar recovery failure asserted above.
     *
     * @return string[] deleted UUIDs
     */
    public static function observed_deleted_mapped_uuids(Policy $policy): array {
        global $wpdb;
        $out = [];
        foreach (self::row_tables($policy) as $table => $decl) {
            if (($decl['identity']['mode'] ?? 'mapped') !== 'mapped') {
                continue;
            }
            $prefixed = $wpdb->prefix . $table;
            $pk = preg_replace('/[^A-Za-z0-9_]/', '', (string) $decl['pk']);
            if (!$wpdb->get_var($wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($prefixed)
            ))) {
                continue;
            }
            foreach (Ledger::all_map() as $map) {
                if ($map['id_kind'] !== $decl['id_kind']) {
                    continue;
                }
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT `$pk` FROM `$prefixed` WHERE `$pk` = %d LIMIT 1",
                    $map['local_id']
                ));
                if ($exists === null) {
                    $out[] = $map['uuid'];
                }
            }
        }
        sort($out, SORT_STRING);
        return array_values(array_unique($out));
    }

    /** A DR export must cover every live mapped row, not just known ones. */
    public static function assert_all_mapped_rows_managed(Policy $policy): void {
        global $wpdb;
        foreach (self::row_tables($policy) as $table => $decl) {
            if (($decl['identity']['mode'] ?? 'mapped') !== 'mapped') {
                continue;
            }
            $prefixed = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $pk = preg_replace('/[^A-Za-z0-9_]/', '', $decl['pk']);
            if (!$wpdb->get_var($wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($prefixed)
            ))) {
                continue;
            }
            $missing = $wpdb->get_var($wpdb->prepare(
                "SELECT src.`$pk` FROM `$prefixed` src "
                . "LEFT JOIN {$wpdb->prefix}wprism_map m ON m.id_kind = %s AND m.local_id = src.`$pk` "
                . "WHERE m.uuid IS NULL ORDER BY src.`$pk` ASC LIMIT 1",
                $decl['id_kind']
            ));
            if ($missing !== null) {
                throw new \RuntimeException(
                    "wprism: cannot export identity sidecar: mapped table '$table' row $missing has no ledger identity; "
                    . 'capture it first or recover the missing sidecar'
                );
            }
        }
    }

    /** meta table name => decl, grouped by owning row table name. */
    private static function meta_tables_by_owner(array $rowTables, array $metaTables): array {
        return TableGraph::meta_tables_by_owner($rowTables, $metaTables);
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
        return TableGraph::topo_order($rowTables);
    }

    /** Rank of $table in topo order (0 = no unresolved deps). Used by the
     *  apply-phase2 ordering wiring, offset past posts/terms' own ranks. */
    public static function phase2_rank(Policy $policy, string $table): int {
        return TableGraph::phase2_rank(self::row_tables($policy), $table);
    }

    // -------------------------------------------------------------- schema

    /**
     * @return ?array<string,string> live column name => live MySQL type
     *   (e.g. "bit(1)", "int(11)", "longtext"), or null if the table
     *   doesn't exist on this environment. Fetching the TYPE alongside the
     *   name (not just names, as an earlier version of this method did) is
     *   what makes TableSchema::write_format() possible — see its docblock for
     *   why BIT columns specifically need it.
     */
    private static function live_column_types(string $table): ?array {
        return TableSchema::live_column_types($table);
    }

    /** @return ?string[] live column names, or null if the table doesn't exist on this environment */
    private static function live_columns(string $table): ?array {
        return TableSchema::live_columns($table);
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
        return TableSchema::is_bit_column($liveType);
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
        return TableSchema::write_format($data, $colTypes);
    }

    /**
     * wprism_map.entity_type/wprism_state.entity_type are VARCHAR(64) and a table
     * row's entity_type IS its table name. A ledger COLUMN WIDTH, not manifest
     * grammar — which is exactly why it stays here rather than moving to
     * Policy::assert_table_grammar() with the rest of the declaration checks
     * (issue #3318): Policy.php is deliberately loadable with no other engine
     * class present, and naming Ledger there would end that.
     */
    private static function assert_entity_type_width(string $table): void {
        TableSchema::assert_entity_type_width($table);
    }

    /** wprism_map.id_kind's own width. @see assert_entity_type_width() */
    private static function assert_id_kind_width(string $table, array $decl): void {
        TableSchema::assert_id_kind_width($table, $decl, Ledger::ID_KIND_WIDTH);
    }

    /**
     * The finding-#8 rule made absolute: every live column of a declared
     * ROW table must be exactly one of {pk, a ref column, a columns entry}
     * — checked against a live SHOW COLUMNS, not just the manifest's own
     * idea of the schema, so a plugin update that adds a column this
     * manifest has never heard of refuses capture instead of silently
     * treating the new column as invisible. A pure schema check (never
     * data-dependent), so it's cheap to always run.
     *
     * issue #3318 split this function in two along one line: "does answering
     * this need the database". Everything answerable from the declaration
     * alone is Policy::assert_table_grammar()'s, reached from Policy::load()
     * so a malformed declaration refuses OFFLINE — before a target exists,
     * let alone is contacted — and re-run here because it is pure and a
     * directly-constructed Policy (the shape several offline harnesses build)
     * never went through load(). What remains here is the half that genuinely
     * needs a live target: the column reconciliation below, plus the two
     * ledger column widths above.
     */
    public static function assert_row_schema(string $table, array $decl): void {
        TableSchema::assert_row_schema(
            $table,
            $decl,
            Ledger::ID_KIND_WIDTH,
            static fn(string $name, array $rule): mixed => Policy::assert_table_grammar($name, $rule)
        );
    }

    /**
     * Schema assertion for identity.mode=composite_ref (issue #3235, task #125)
     * — the "pure join table" shape (see this file's docblock, "Identity:
     * three modes"). Deliberately a SEPARATE method from assert_row_schema()
     * rather than more branches threaded through it: the invariants differ
     * enough (no pk; identity.columns must equal refs[] columns EXACTLY)
     * that interleaving would obscure both, the same call made for
     * assert_meta_schema() living apart from assert_row_schema() already.
     *
     * `identity.columns` is asserted to be exactly 2 entries: this round's
     * ONLY proven fixture (PMPro's pmpro_memberships_pages/_categories) is
     * 2-column; N>2 is a straightforward mechanical extension (see
     * pack_composite_id()'s docblock for the bit-budget arithmetic it would
     * need) but unexercised, so refused rather than half-supported.
     *
     * issue #3318: the declaration half of all of that now lives in
     * Policy::assert_table_grammar()'s composite branch (see
     * assert_row_schema() for the split rule). This function keeps the two
     * ledger column widths and the live column reconciliation, and re-runs the
     * pure grammar because it is public — a caller reaching it directly gets
     * the same verdict as one arriving through assert_row_schema(), and
     * re-checking a pure function of already-loaded bytes costs nothing.
     */
    public static function assert_composite_row_schema(string $table, array $decl): void {
        TableSchema::assert_composite_row_schema(
            $table,
            $decl,
            Ledger::ID_KIND_WIDTH,
            static fn(string $name, array $rule): mixed => Policy::assert_table_grammar($name, $rule)
        );
    }

    /**
     * An attached-meta table's live schema is the fixed EAV shape itself
     * (id_column + the owner-linking column + the key/value column pair +
     * any declared legacy mirror pair) — asserted exactly, not just "at
     * least these columns," so a plugin schema change is caught the same
     * way assert_row_schema() catches one, even though there is no per-KEY
     * enumeration to check (see this file's docblock for why that's a
     * deliberately different completeness problem for an EAV sidecar).
     *
     * `id_column` (issue #3235, task #126) — the sidecar's own PK column NAME,
     * defaulting to `'id'` for exact backward compatibility with every
     * fixture that shipped before this field existed (nf3_*_meta,
     * woocommerce_attribute_taxonomies have no attached-meta table at all,
     * so only nf3_*_meta is a real precedent, and its PK genuinely IS
     * named 'id' — confirmed by reading manifests/ninja-forms.json's own
     * declarations, which never needed an override). Found hardcoded
     * (literal `'id'`) at THREE call sites, not just this one — capturing
     * or applying a table declaring an override would have hit the other
     * two even after this method alone stopped throwing (the identical
     * two-bugs-hiding-each-other trap issue #3212's Blocks.php/Lint.php pair
     * hit): TypedTableCapture's `ORDER BY id` and TypedTableMaterializer's
     * reconciliation
     * `SELECT id, ...` + its UPDATE `WHERE id = ...`. All three now read
     * this same declared/defaulted column name. Proving fixture:
     * pmpro_membership_levelmeta, whose real PK column is `meta_id` (DESCRIBE'd
     * live on this round's own sandbox pair — see manifests/paid-memberships-pro.json).
     */
    public static function assert_meta_schema(string $table, array $decl): void {
        TableSchema::assert_meta_schema(
            $table,
            $decl,
            static fn(string $name, array $rule): mixed => Policy::assert_table_grammar($name, $rule)
        );
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
     * a non-minting pass derives a declared natural-key identity, but a
     * populated mapped row without ledger metadata blocks instead of
     * becoming invisible. Minting capture may create identity only for a
     * genuinely new mapped row after canonical history has been checked.
     *
     * @return array<int, array{uuid:string, type:string, path:string, content:string}>
     */
    public static function capture(
        Policy $policy,
        Tokens $tokens,
        bool $mint,
        bool $strictReadOnly = false,
        ?DatabaseWorkAuthority $workAuthority = null
    ): array {
        $rowTables = self::row_tables($policy); // throws on duplicate id_kind
        if (!$rowTables) {
            return [];
        }
        // A normal capture repairs legacy schema damage before it records a
        // new candidate. Production export has no such authority: it must
        // observe an already-valid ledger or refuse, never turn a read into
        // an identity repair.
        if (!$strictReadOnly) {
            self::repair_truncated_entity_types($policy); // issue #3246
        }
        $metaTables = self::meta_tables($policy);
        $metaByOwner = self::meta_tables_by_owner($rowTables, $metaTables); // throws on a dangling attached_to.table

        foreach ($rowTables as $table => $decl) {
            self::assert_row_schema($table, $decl);
        }
        foreach ($metaTables as $metaName => $metaDecl) {
            self::assert_meta_schema($metaName, $metaDecl);
        }
        $keyspaceGaps = self::keyspace_gaps($policy);
        if ($keyspaceGaps) {
            $lines = [];
            foreach ($keyspaceGaps as $gap) {
                $lines[] = "table_meta:{$gap['table']}:{$gap['key']} owner candidate {$gap['owner']}; "
                    . "{$gap['count']} row(s), shape(s) " . implode(',', $gap['value_shapes'])
                    . "; {$gap['reason']}";
            }
            throw new \RuntimeException(
                "wprism: attached-meta keys exist outside a version-pinned declared keyspace (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
            );
        }

        $entities = [];
        foreach (self::topo_order($rowTables) as $table) {
            // Typed tables do not yet admit a count/byte-bounded row roster.
            // The complete table, not each returned row, therefore remains
            // one finite query unit within the 256-table profile frontier.
            $entities = array_merge($entities, DatabaseQueryIsolation::work_unit($workAuthority, static fn(): array => self::capture_table(
                $table,
                $rowTables[$table],
                $metaByOwner[$table] ?? [],
                $tokens,
                $mint,
                $strictReadOnly,
                // WP-6.1: the table's own `column_codecs` projection, read here
                // because this is the last frame that still holds a Policy —
                // TypedTableCapture is deliberately Policy-free.
                $policy->column_codec_rules($table)
            )));
        }
        return $entities;
    }

    /**
     * Enumerate unknown keys in opted-in EAV keyspaces. `default_class`
     * remains the classification for keys INSIDE the bounded keyspace; it
     * is never permission to absorb a plugin-upgrade-added key outside it.
     *
     * @return list<array{table:string,key:string,owner:string,count:int,value_shapes:string[],reason:string}>
     */
    public static function keyspace_gaps(
        Policy $policy,
        ?callable $observationReadCheckpoint = null
    ): array {
        global $wpdb;
        $out = [];
        foreach (self::meta_tables($policy) as $table => $decl) {
            $keyspace = $decl['keyspace'] ?? null;
            if (!is_array($keyspace)) {
                continue;
            }
            $prefixed = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $table);
            $exists = $wpdb->get_var($wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($prefixed)
            ));
            self::checkpoint_observation_read($observationReadCheckpoint);
            if (!$exists) {
                continue;
            }
            $keyCol = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($decl['key_column'] ?? 'meta_key'));
            $valCol = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($decl['value_column'] ?? 'meta_value'));
            $rows = $wpdb->get_results(
                "SELECT `$keyCol` AS k, `$valCol` AS v FROM `$prefixed` ORDER BY `$keyCol` ASC",
                ARRAY_A
            ) ?: [];
            self::checkpoint_observation_read($observationReadCheckpoint);
            $unknown = [];
            foreach ($rows as $row) {
                $key = (string) $row['k'];
                if (self::meta_key_in_keyspace($decl, $key)) {
                    continue;
                }
                $raw = $row['v'];
                $value = is_string($raw) && is_serialized($raw)
                    ? SerializedDataPreflight::decode(trim($raw), "unclassified $table metadata")
                    : $raw;
                $unknown[$key]['count'] = ($unknown[$key]['count'] ?? 0) + 1;
                $unknown[$key]['shapes'][get_debug_type($value)] = true;
            }
            $owner = $policy->declared_table_details($table)['source'] ?? '?';
            $range = $keyspace['version_range'];
            foreach ($unknown as $key => $evidence) {
                $out[] = [
                    'table' => $table,
                    'key' => $key,
                    'owner' => (string) $owner,
                    'count' => $evidence['count'],
                    'value_shapes' => array_keys($evidence['shapes']),
                    'reason' => "not declared for adapter keyspace [{$range['min']}, {$range['max']})",
                ];
            }
        }
        return $out;
    }

    /** Invoke adapter observation's strict read check without changing normal snapshot behavior. */
    private static function checkpoint_observation_read(?callable $observationReadCheckpoint): void {
        if ($observationReadCheckpoint !== null) {
            $observationReadCheckpoint();
        }
    }

    /** True when an attached-meta key is inside its declared adapter keyspace. */
    public static function meta_key_in_keyspace(array $decl, string $key): bool {
        $keyspace = $decl['keyspace'] ?? null;
        if (!is_array($keyspace)) {
            return true; // legacy open keyspace; adapters opt in explicitly
        }
        if (array_key_exists($key, $decl['keys'] ?? [])
            || in_array($key, array_map('strval', $keyspace['keys'] ?? []), true)) {
            return true;
        }
        foreach ($keyspace['patterns'] ?? [] as $pattern) {
            if (preg_match('/' . $pattern['match'] . '/', $key)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,array{container:string,leaves:string}> $columnCodecs */
    private static function capture_table(
        string $table,
        array $decl,
        array $metaDecls,
        Tokens $tokens,
        bool $mint,
        bool $strictReadOnly = false,
        array $columnCodecs = []
    ): array {
        return self::typed_table_capture()->capture_table(
            $table,
            $decl,
            $metaDecls,
            $tokens,
            $mint,
            $strictReadOnly,
            $columnCodecs
        );
    }

    /** Bind Snapshot's runtime collaborators to the extracted capture seam. */
    private static function typed_table_capture(): TypedTableCapture {
        return new TypedTableCapture(
            self::snapshot_identity(),
            static function (
                string $uuid,
                string $table,
                string $idKind,
                int $packed,
                bool $strictReadOnly,
                string $context
            ): void {
                if ($strictReadOnly) {
                    Ledger::require_read_only_mapping($uuid, $table, $idKind, $packed, $context);
                } else {
                    Ledger::set($uuid, $table, $idKind, $packed);
                }
            },
            static fn(string $uuid, string $table, array $decl, array $columns): ?string =>
                IdentityNotes::natural_key_continuity($uuid, $table, $decl, $columns),
            static fn(string $raw): string => sanitize_title($raw)
        );
    }

    /** Bind Snapshot's runtime collaborators to the extracted identity seam. */
    private static function snapshot_identity(?Policy $policy = null): SnapshotIdentity {
        return new SnapshotIdentity(
            static fn(array $decl): array => Policy::natural_key_columns($decl),
            static fn(): array => $policy === null ? [] : self::row_tables($policy),
            static fn(int $localId, string $kind): ?string => Ledger::uuid_for($localId, $kind),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for($uuid, Tokens::ledger_kind($kind)),
            static function (string $uuid, string $table, string $kind, int $localId): void {
                Ledger::set($uuid, $table, $kind, $localId);
            },
            static function (string $uuid, string $table, string $kind, int $localId, string $context): void {
                Ledger::require_read_only_mapping($uuid, $table, $kind, $localId, $context);
            },
            static fn(string $name): string => Uuid::v5(Uuid::NAMESPACE_WPRISM, $name),
            static fn(): string => Uuid::v7(),
            static fn(string $content): array => Canon::decode($content),
            static function (string $table, string $pk, array $predicates, array $args): ?int {
                global $wpdb;
                $prefixed = $wpdb->prefix . $table;
                $id = $wpdb->get_var($wpdb->prepare(
                    "SELECT `$pk` FROM `$prefixed` WHERE " . implode(' AND ', $predicates) . ' LIMIT 1',
                    $args
                ));
                return $id !== null ? (int) $id : null;
            }
        );
    }

    /**
     * The exact UUIDv5 name a natural_key row derives its identity from
     * (issue #3318) — one function, so capture, the continuity note, the
     * repository compiler's duplicate-identity check, and adoption can never
     * disagree about what "the same row" means.
     *
     * The single-component spelling is FROZEN at "<table>:<value>": every
     * natural_key uuid ever minted by this engine derives from it, and a
     * cosmetic reformatting here would re-derive every one of them into a new
     * identity on the next fresh-environment bootstrap. The multi-component
     * spelling therefore gets its own unambiguous shape,
     * "<table>:<col>=<component>:<col>=<component>", which cannot collide with
     * the single form (a one-column key would have to contain a literal "="
     * in a column named exactly like the table's other column to alias, and
     * even then the component ordering differs) and matches
     * identify_composite_row()'s existing tuple spelling rather than
     * inventing a third one.
     *
     * A component is a REF column's referenced-row uuid, or a scalar column's
     * raw value — that substitution is the entire point of the parent-scoped
     * form: a slot code that is unique only within its room is portable only
     * when the room contributes its portable identity, never its local id.
     *
     * @param array<string,string> $components identity column => component value
     */
    public static function natural_key_name(string $table, array $decl, array $components): string {
        return self::snapshot_identity()->naturalKeyName($table, $decl, $components);
    }

    /**
     * A natural_key row's identity components read back out of an already-
     * CAPTURED file's flat `columns` map (issue #3318) — the apply/compile/plan
     * direction, where a ref column holds a "{{kind:uuid}}" token rather than
     * a live local id, so the referenced uuid is already present and needs no
     * ledger lookup at all.
     *
     * Returns null when any component is absent, empty, or — issue #3318 review
     * (N3) — carries a ref token this engine cannot parse. That is not a
     * failure: it is the ordinary state of a file captured before the
     * declaration existed, or of a row whose optional-looking component was
     * never populated, and every caller of this treats "cannot derive" as
     * "say nothing" rather than as an error. The malformed-token case joined
     * that list because both callers are INFORMATIONAL (the identity-
     * continuity note, at capture and in a plan row): hard-throwing there made
     * one corrupt byte in one repository file abort a whole `wprism plan` from an
     * annotation nobody asked for. The fail-closed reading of the same token
     * still exists where it belongs — capture's own live derivation
     * (SnapshotIdentity's live component reader) and apply's adoption lookup
     * (find_collision()) both refuse a malformed token outright.
     *
     * @param array<string,mixed> $columns the file's own flat columns map
     * @return array<string,string>|null identity column => component value
     */
    public static function natural_key_components_from_front(array $decl, array $columns): ?array {
        return self::snapshot_identity()->naturalKeyComponentsFromFront($decl, $columns);
    }

    /**
     * Identity for one row: reuse the existing wprism_map entry if one exists
     * and re-affirm it via contradiction-intolerant Ledger::set(). A
     * never-seen mapped row gets NEW identity only when $mint is true;
     * natural-key rows derive identity in either mode. See this file's
     * docblock for the mapped-vs-natural_key recovery split.
     *
     * $tokens is the capture-direction resolver for a REF identity component
     * (issue #3318's parent-scoped natural key): the referenced row's uuid, never
     * the live local id sitting in the column.
     */
    private static function identify_row(
        string $table,
        array $decl,
        array $row,
        int $localId,
        Tokens $tokens,
        bool $mint,
        bool $strictReadOnly = false
    ): string {
        return self::snapshot_identity()->identifyRow(
            $table,
            $decl,
            $row,
            $localId,
            $tokens,
            $mint,
            $strictReadOnly
        );
    }

    /**
     * Identity for one composite_ref row: NEVER a ledger lookup (contrast
     * identify_row() above, which tries Ledger::uuid_for() first) — always
     * recomputed from the row's two CURRENTLY-resolved refs, because that is
     * the whole point of this mode (see this file's docblock). Throws
     * (structural-ref posture, unconditionally — same as an ordinary row's
     * refs[] loop in capture_table(), not softened for a mere Capture::
     * snapshot() call) when either component is zero/empty or fails to
     * resolve: a composite_ref row's TWO identity columns are never optional
     * (that's the entire content of a pure join row — nothing to have a
     * "half-formed" version of).
     *
     * @param string[] $tokensByCol out: identity column => token string, in
     *   the SAME shape regular refs[] columns already produce for a file's
     *   flat `columns` map
     * @param int[] $localByCol out: identity column => this environment's
     *   OWN current local id for that column (capture direction only —
     *   TypedTableMaterializer resolves the apply direction separately)
     * @return array{0:string, 1:array<string,string>, 2:array<string,int>}
     */
    private static function identify_composite_row(string $table, array $decl, array $row, Tokens $tokens): array {
        return self::snapshot_identity()->identifyCompositeRow($table, $decl, $row, $tokens);
    }

    /**
     * SnapshotIdentity's fail-closed token spelling, for the CAPTURE direction and
     * for apply-time resolution — every caller here holds a token this same
     * process just produced through Tokens (identify_composite_row()) or a
     * token an already-validated repository file carries as structural
     * identity (find_collision()), so a malformed one is a corruption to
     * refuse, never a value to derive something wrong from.
     *
     * issue #3318 review (N3): the attribution used to say "composite_ref
     * identity derivation", which was true when composite_ref was the only
     * mode that read a ref token for identity; the parent-scoped natural key
     * reaches it too.
     */
    private static function uuid_from_token(string $token): string {
        return self::snapshot_identity()->uuidFromToken($token);
    }

    /**
     * Packs a composite_ref row's two CURRENT local ids into wprism_map's
     * existing, unchanged `local_id BIGINT UNSIGNED` column — deliberately
     * NOT a schema migration (see this file's docblock): (id_kind, local_id)
     * stays the SAME lookup shape delete_row() and every other ledger
     * consumer already use, just carrying a packed value instead of a bare
     * scalar for this one identity mode. Each component is budgeted to 31
     * bits (0..2^31-1, ~2.1 billion — no real WordPress row count will ever
     * approach this) rather than the naively-tempting 32: at 32 bits, a
     * component with its own top bit set would push the packed value's bit
     * 63 high, which PHP represents as a NEGATIVE 64-bit signed int (PHP has
     * no native unsigned integer type) even though MySQL's BIGINT UNSIGNED
     * column has no trouble with the same bit pattern — a representation
     * mismatch, not a real capacity concern at any plausible scale. 31 bits
     * per component keeps the packed value's magnitude strictly under 2^62,
     * safely inside PHP's positive signed range with zero ambiguity, at the
     * cost of a budget so generous the difference from 32 is unobservable
     * in practice. Components exceeding the budget throw rather than
     * silently truncate or wrap.
     */
    /**
     * @param array<string,int> $colVals identity column name => this
     *   environment's current local id, in identity.columns order (exactly
     *   what SnapshotIdentity/TypedTableMaterializer already build
     *   as $localByCol — passed straight through, not reassembled). Naming
     *   the table AND both column=value pairs in the failure message (not
     *   just the single offending scalar) is deliberate: an operator hitting
     *   this on a real site needs to see the WHOLE tuple to know which row
     *   is unrepresentable and why, not decode a bare number.
     */
    private static function pack_composite_id(string $table, array $colVals): int {
        return self::snapshot_identity()->packCompositeId($table, $colVals);
    }

    /** @return array{0:int, 1:int} */
    private static function unpack_composite_id(int $packed): array {
        return self::snapshot_identity()->unpackCompositeId($packed);
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
                'data' => $front,
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
     *
     * $tree/$cache/$seen carry the parent-resolution fallback for a
     * parent-scoped key's ref component (issue #3318 review, S2) and mirror
     * Apply::find_collision()'s own signature: $tree is the compiled
     * repository keyed by uuid, $cache is Apply's shared per-uuid collision
     * memo, and $seen is the recursion path (by VALUE, so it scopes itself to
     * one branch) that keeps two tables whose keys reference each other from
     * recursing forever. A caller with no tree — every offline/unit caller —
     * simply gets the ledger-only behavior that shipped before.
     *
     * @param array<string,array> $tree
     * @param array<string,?int> $cache
     * @param array<string,bool> $seen
     * @param \Closure(string,string):?int|null $ledgerIdFor optional resolver
     *        used by planner-owned collision lookups; it receives the token's
     *        manifest ref kind and owns any ledger-kind translation. The
     *        legacy null path retains Snapshot's direct Ledger lookup.
     */
    public static function find_collision(
        Policy $policy,
        array $entity,
        array $tree = [],
        array &$cache = [],
        array $seen = [],
        ?\Closure $ledgerIdFor = null
    ): ?int {
        return self::snapshot_identity($policy)->findCollision($entity, $tree, $cache, $seen, $ledgerIdFor);
    }

    /** Claim an unmanaged env row by writing identity only — table rows have
     *  no meta-column identity marker to also write, unlike adopt() for
     *  posts/terms in Apply.php. */
    public static function adopt(Policy $policy, string $uuid, string $table, int $envId): void {
        self::snapshot_identity($policy)->adopt($uuid, $table, $envId);
    }

    /** Bind Snapshot's runtime capabilities to the extracted write seam. */
    private static function typed_table_materializer(Policy $policy): TypedTableMaterializer {
        return new TypedTableMaterializer(
            static fn(): array => self::row_tables($policy),
            static fn(): array => self::meta_tables($policy),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for($uuid, $kind),
            static function (string $uuid, string $table, string $kind, int $localId): void {
                Ledger::set($uuid, $table, $kind, $localId);
            },
            static fn(string $table, array $columns): int => self::pack_composite_id($table, $columns),
            static fn(int $packed): array => self::unpack_composite_id($packed),
            static fn(array $decl, string $key): bool => self::meta_key_in_keyspace($decl, $key),
            static fn($value) => maybe_serialize($value),
            static fn(string $key, string $group): bool => wp_cache_delete($key, $group),
            // WP-6.1: the write half of the same `column_codecs` projection
            // capture() reads. Bound here for the same reason as every other
            // capability above — TypedTableMaterializer never sees a Policy.
            static fn(string $table): array => $policy->column_codec_rules($table)
        );
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
        return self::typed_table_materializer($policy)->ensureRow($entity);
    }

    /**
     * Phase 2: resolve every ref column through the ledger, reconcile the
     * attached-meta sidecar as an owned key-set, run any declared
     * per-row cache invalidation.
     */
    public static function finalize_row(Policy $policy, Tokens $tokens, array $entity): void {
        self::typed_table_materializer($policy)->finalizeRow($tokens, $entity);
    }

    /**
     * Delete one row and its attached-meta sidecar (flag-gated by the
     * caller, matching posts/terms — Apply::delete_entity()'s existing
     * with-deletes convention). Apply::deletion_rank() owns cross-table
     * children-before-parent ordering; this helper deletes one exact row.
     */
    public static function delete_row(Policy $policy, string $uuid, string $table): void {
        self::typed_table_materializer($policy)->deleteRow($uuid, $table);
        // Identity/base metadata is forgotten by Apply's post-rebuild ledger
        // transaction. Doing it here would commit convergence metadata with
        // the authored-row transaction before required rebuilds succeeded.
    }

    /**
     * Delete an exact local row through the same typed-snapshot cascade and
     * invalidation path as apply. Orphans uses this because a damaged row may
     * itself have lost its wprism_map identity and therefore cannot be selected
     * by UUID; the table declaration + local primary key remain sufficient
     * deletion authority once the operator selects that listed orphan.
     */
    public static function delete_local_row(Policy $policy, string $table, int $localId): void {
        self::typed_table_materializer($policy)->deleteLocalRow($table, $localId);
    }

    /** Reparent one scalar typed row and any declared attached-meta mirror. */
    public static function reparent_local_row(
        Policy $policy,
        string $table,
        int $localId,
        string $column,
        int $targetId
    ): void {
        self::typed_table_materializer($policy)->reparentLocalRow($table, $localId, $column, $targetId);
    }

    /**
     * Prove a typed-table delete and every declared attached-meta cascade
     * affected the exact target identity. Called before commit; any survivor
     * throws and rolls the whole apply transaction back.
     */
    public static function assert_row_deleted(Policy $policy, string $table, int $localId): void {
        self::typed_table_materializer($policy)->assertRowDeleted($table, $localId);
    }

    // ------------------------------------------------------------- ledger

    /** Bind Snapshot's runtime collaborators to the extracted pruning seam. */
    private static function snapshot_pruner(Policy $policy): SnapshotPruner {
        return new SnapshotPruner(
            $policy,
            static fn(array $document): array => OptionState::records($document),
            static fn(string $uuid, string $kind): ?int => Ledger::id_for($uuid, $kind),
            static fn($value): ?int => Policy::strict_positive_local_id($value),
            static function (array $tables, array $preserved): void {
                Ledger::prune_dead_table_map($tables, $preserved);
            },
            static function (array $tables): void {
                Ledger::prune_dead_composite_table_map(
                    $tables,
                    SnapshotIdentity::compositeComponentBits()
                );
            },
            static fn(array $decl): bool => self::is_composite_ref($decl)
        );
    }

    /**
     * Preserve typed-row identities which an authored option-name namespace
     * still needs. A Woo shipping-method row can disappear before its
     * `woocommerce_<method>_<instance>_settings` option does; pruning the
     * wc_zone_method mapping at that point would make the live option look
     * like an unrelated/unowned row before capture can emit its paired
     * canonical tombstone. The live option scan covers that recovery case;
     * the frozen options document additionally covers a previous canonical
     * deletion record when the option is already absent on this target.
     *
     * This is deliberately identity preservation only. It does not mint a
     * UUID, infer a missing mapping, or widen option ownership: every kept
     * id still has to match an authored option_name_refs rule for its own
     * id_kind, and canonical tokens must resolve through the existing ledger.
     *
     * @param null|list<string> $liveOptionNames The producer's bounded namespace, when already observed in its transaction.
     * @param null|\Closure(\Closure():void):void $observeCanonicalName The caller's complete per-name observation boundary.
     * @return array<string,int[]> id_kind => local ids to exclude from dead-map pruning
     */
    public static function option_name_ref_preserved_ids(
        Policy $policy,
        ?array $repositoryOptions = null,
        ?array $liveOptionNames = null,
        ?\Closure $observeCanonicalName = null
    ): array {
        return self::snapshot_pruner($policy)->option_name_ref_preserved_ids($repositoryOptions, $liveOptionNames, $observeCanonicalName);
    }

    /** Full capture dead-map hygiene for every declared typed-table row. */
    public static function prune_dead_map(Policy $policy, ?array $repositoryOptions = null): void {
        self::snapshot_pruner($policy)->prune_dead_map(self::row_tables($policy), $repositoryOptions);
    }

    /**
     * Does a live row exist for (id_kind, local_id), independent of whether
     * it has been minted a uuid yet? Task #93's option_name_refs discovery
     * (OptionsCapture::capture()) needs this to distinguish DANGLING (no
     * such row exists anywhere — the #73 dangling class, warn+drop) from
     * UNSCOPED (the row genuinely exists in its declared table but was
     * never minted — e.g. the owning table isn't itself pinned as
     * authored_snapshot in currently-loaded manifests — the #73 unscoped
     * class, mirrored onto table id_kinds: loud, blocking, a policy gap a
     * human can fix, never silent data loss). Returns false, never throws,
     * when $idKind names no currently-declared table at all — from the
     * caller's point of view that's indistinguishable from dangling
     * (nothing to check against), not a distinct third state.
     */
    public static function row_exists_for_kind(Policy $policy, string $idKind, int $localId): bool {
        global $wpdb;
        foreach (self::row_tables($policy) as $table => $decl) {
            if (($decl['id_kind'] ?? '') !== $idKind) {
                continue;
            }
            if (self::is_composite_ref($decl)) {
                // No single scalar local_id for a composite_ref table to
                // test against this single-id existence check (see
                // pack_composite_id()'s docblock) — no current caller
                // reaches this (nothing embeds a composite_ref id_kind in
                // an option NAME), but this stays a defined, argued "no"
                // rather than an undefined-index crash if one ever does.
                return false;
            }
            $prefixed = $wpdb->prefix . $table;
            $pk = $decl['pk'];
            return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM `$prefixed` WHERE `$pk` = %d", $localId));
        }
        return false;
    }

    /**
     * Re-prove one selected authored_snapshot map tuple against its physical
     * row without pruning or repairing it. Unlike row_exists_for_kind(), this
     * path supports composite_ref's packed local id because scoped recovery
     * must bind the exact retained tuple rather than merely classify an
     * option-name reference.
     */
    public static function assert_read_only_selected_mapping(
        Policy $policy,
        string $table,
        string $uuid,
        string $idKind,
        int $localId
    ): void {
        global $wpdb;
        $decl = self::row_tables($policy)[$table] ?? null;
        if (!is_array($decl) || (string) ($decl['id_kind'] ?? '') !== $idKind || $localId <= 0) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        if (!self::read_only_mapped_row_exists($policy, $table, $localId)) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        try {
            Ledger::require_read_only_mapping($uuid, $table, $idKind, $localId, "selected table '$table' row");
        } catch (\Throwable $failure) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired($failure);
        }
    }

    /** Checked SELECT-only physical existence for regular and composite rows. */
    public static function read_only_mapped_row_exists(Policy $policy, string $table, int $localId): bool {
        global $wpdb;
        $decl = self::row_tables($policy)[$table] ?? null;
        if (!is_array($decl) || $localId <= 0) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        $prefixed = $wpdb->prefix . $table;
        if (self::is_composite_ref($decl)) {
            [$left, $right] = self::unpack_composite_id($localId);
            $columns = array_values((array) ($decl['identity']['columns'] ?? []));
            if (count($columns) !== 2) {
                throw CommandRefusalException::scopedIdentityRecoveryRequired();
            }
            $sql = $wpdb->prepare(
                "SELECT 1 FROM `$prefixed` WHERE `{$columns[0]}` = %d AND `{$columns[1]}` = %d LIMIT 1",
                $left,
                $right
            );
        } else {
            $pk = (string) ($decl['pk'] ?? '');
            $sql = $wpdb->prepare("SELECT 1 FROM `$prefixed` WHERE `$pk` = %d LIMIT 1", $localId);
        }
        $wpdb->last_error = '';
        $exists = $wpdb->get_var($sql);
        if ($exists === false || !empty($wpdb->last_error)) {
            throw CommandRefusalException::scopedIdentityRecoveryRequired();
        }
        return $exists !== null;
    }
}
