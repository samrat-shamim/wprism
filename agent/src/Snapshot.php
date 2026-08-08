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
 * ---- Identity: three modes, one recovery contract ----
 *
 * Posts/terms mint a uuid and store it BACK onto the row itself (_duo_uuid
 * via postmeta/termmeta) — durable even if the duo_map ledger is ever lost,
 * because the row carries its own receipt. A custom table's row has no meta
 * space of its own and MUST NOT get one added to the plugin's own schema
 * (DESIGN.md's non-negotiable "plugins work completely unmodified"), so
 * identity for a declared table's rows lives ONLY in duo_map, keyed by
 * (id_kind, local_id) -> uuid. Three identity modes, declared per table:
 *
 * - `"identity": {"mode": "mapped"}` (the default when `identity` is
 *   omitted) — a fresh row gets a random Uuid::v7(), same as posts/terms.
 *   Because the plugin row cannot carry the UUID, `duo_map` plus the
 *   `duo-identity-ledger/v1` disaster-recovery sidecar is the durable store.
 *   A populated non-minting target with a missing mapping blocks; a source
 *   whose canonical mapped UUIDs lost their mappings also blocks instead of
 *   minting replacements. nf3_forms/nf3_fields/nf3_actions use this mode (no
 *   natural key exists — nf3_forms.key was empirically NULL on the live
 *   fixture, contradicting the one-line speculation in task #75's own
 *   description that it might serve).
 * - `"identity": {"mode": "natural_key", "column": "<col>"}` — for a table
 *   with a confirmed-stable, human-chosen, unique column (WooCommerce's
 *   `attribute_name`: verified in docs/grind/r1b-shop.md). A fresh row's
 *   uuid is DERIVED, not minted: `Uuid::v5(Uuid::NAMESPACE_DUO,
 *   "<table>:<natural key value>")` — the SAME (table, value) always
 *   produces the SAME uuid. This is BOOTSTRAP identity for a never-seen row;
 *   after first capture, the existing ledger mapping is consulted first and
 *   provides rename continuity. Consequently a mapped row whose natural key
 *   changes keeps its uuid, even though UUIDv5 of the current key differs.
 *   A fresh environment independently capturing that renamed row without
 *   ledger/repository history derives from the new key instead; table adopt
 *   is the reconciliation path. Contradictory mappings still block and
 *   ordinary Ledger::set() never deletes or rebinds identity implicitly.
 * - `"identity": {"mode": "composite_ref", "columns": ["<col1>", "<col2>"]}`
 *   (DUO-3235, task #125) — for a PURE JOIN table: no surrogate `pk` column
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
 *   `Uuid::v5(NAMESPACE_DUO, "<table>:<col1>=<ref1-uuid>:<col2>=<ref2-uuid>")`
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
 *   behind `$mint`, and unlike EITHER other mode, duo_map's role shrinks to
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
 *   override columns) — finalize_composite_row() upserts those normally.
 *   "No update bucket" describes the two-column pure-join proving fixture's
 *   OWN observed behavior (plan can never place it in `update`, because
 *   there is no content that can change independent of identity — verified
 *   live, not merely asserted, by sandbox/tests/regress_pmpro_composite_ref.sh's
 *   own `.plan.update == 0` assertion across two full round-trips; Apply::
 *   build_plan() is out of reach of this file's OWN offline harness,
 *   regress_composite_ref.php, by design — see this file's docblock,
 *   "Engine boundary"), not a hard restriction the grammar itself imposes
 *   on every future composite_ref table.
 *
 *   Mutation continuity (cross-ref DUO-3237): composite_ref's two columns
 *   are ALWAYS refs, so their stability is entirely inherited from whatever
 *   identity mode the referenced table already uses. A mapped natural_key
 *   target keeps its ledger UUID across a key rename, so a composite_ref row
 *   referencing it keeps the same derived tuple UUID too. This fixture's two ref
 *   targets — `pmpro_level` (mapped identity: a random v7, stored in
 *   duo_map, untouched by editing `name`) and `post` (the built-in
 *   `_duo_uuid` postmeta, untouched by editing title/slug) — are both
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

    /** duo_map.id_kind width — shared with Ledger's schema/migration. */
    private const MAX_ID_KIND_LEN = Ledger::ID_KIND_WIDTH;

    /**
     * DUO-3246: duo_map.entity_type/duo_state.entity_type are VARCHAR(64)
     * (Ledger::ensure() — manually synced with this constant, same
     * precedent as MAX_ID_KIND_LEN above). Unlike id_kind (a short,
     * freely-chosen abbreviation this project budgets DOWN to fit),
     * entity_type for a table row IS the table name itself — a plugin's
     * own naming choice, not ours to shorten — so this asserts against the
     * WIDENED ceiling rather than the original, narrower one: two shipped
     * tables (woocommerce_shipping_zone_locations, woocommerce_shipping_
     * zone_methods) already exceed 32 chars, silently truncated by MySQL
     * before this fix. See repair_truncated_entity_types() for rows
     * already corrupted under the old width.
     */
    private const MAX_ENTITY_TYPE_LEN = 64;

    /** Single source of truth for "is this authored_snapshot declaration a
     *  composite_ref (pure join table) identity" — every dispatch site below
     *  (schema assertion, capture, ensure/finalize, delete, ledger hygiene)
     *  branches on this SAME check, so the mode can never register as
     *  composite_ref in one place and fall through to mapped/natural_key
     *  logic in another. See this file's docblock, "Identity: three modes." */
    private static function is_composite_ref(array $decl): bool {
        return ($decl['identity']['mode'] ?? 'mapped') === 'composite_ref';
    }

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

    /**
     * DUO-3246: repairs duo_map/duo_state rows whose entity_type was
     * silently truncated by the pre-fix VARCHAR(32) schema — a table row's
     * entity_type IS its table name (see this function's own caller,
     * row_tables(), and this file's docblock), and two currently-shipped
     * tables (woocommerce_shipping_zone_locations, woocommerce_shipping_
     * zone_methods) exceed 32 chars.
     *
     * A migration-time direct UPDATE, deliberately never Ledger::set():
     * DUO-3209's identity-contradiction guard exists specifically to
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
        Ledger::ensure(); // guarantee the column is already widened before any UPDATE targets it
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
            foreach (['duo_map', 'duo_state'] as $table) {
                $affected = (int) Db::query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}{$table} SET entity_type = %s WHERE entity_type = %s",
                    $full, $truncated
                ), "ledger repair truncated entity_type in $table ($truncated -> $full)");
                if ($affected > 0) {
                    $repaired[] = "$table: '$truncated' -> '$full' ($affected row" . ($affected === 1 ? '' : 's') . ')';
                }
            }
        }
        return $repaired;
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
                        "duo: mapped identity history is missing for canonical $table entity $uuid; "
                        . 'refusing to mint a replacement. Restore the database-matched identity sidecar with '
                        . '`wp duo identity-import --repo=<repo> --in=<file>` before capture'
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
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
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
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
                continue;
            }
            $missing = $wpdb->get_var($wpdb->prepare(
                "SELECT src.`$pk` FROM `$prefixed` src "
                . "LEFT JOIN {$wpdb->prefix}duo_map m ON m.id_kind = %s AND m.local_id = src.`$pk` "
                . "WHERE m.uuid IS NULL ORDER BY src.`$pk` ASC LIMIT 1",
                $decl['id_kind']
            ));
            if ($missing !== null) {
                throw new \RuntimeException(
                    "duo: cannot export identity sidecar: mapped table '$table' row $missing has no ledger identity; "
                    . 'capture it first or recover the missing sidecar'
                );
            }
        }
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
            if (self::is_composite_ref($rowTables[$owner])) {
                // A composite_ref row has no scalar local identity of its own
                // (see this file's docblock) for a sidecar's attached_to.column
                // to key on — nothing PMPro's own composite-PK tables need
                // (neither pmpro_memberships_pages nor pmpro_memberships_
                // categories has an attached-meta table), so this stays an
                // explicit refusal rather than a half-built mechanism.
                throw new \RuntimeException(
                    "duo: table '$metaName' declares attached_to.table='$owner', which is identity.mode=composite_ref — "
                    . 'a pure join table has no scalar row identity for an attached-meta sidecar to key on'
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
        if (strlen($table) > self::MAX_ENTITY_TYPE_LEN) {
            throw new \RuntimeException(
                "duo: table '$table' name is " . strlen($table) . ' chars — a table row entity_type IS the table '
                . 'name itself, which must be 1-' . self::MAX_ENTITY_TYPE_LEN
                . ' chars (duo_map.entity_type/duo_state.entity_type are VARCHAR(' . self::MAX_ENTITY_TYPE_LEN . '))'
            );
        }
        if (self::is_composite_ref($decl)) {
            self::assert_composite_row_schema($table, $decl);
            return;
        }
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
     * Schema assertion for identity.mode=composite_ref (DUO-3235, task #125)
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
     */
    public static function assert_composite_row_schema(string $table, array $decl): void {
        // Also checked by assert_row_schema()'s own dispatch before it ever
        // reaches here (the only current call path) — duplicated anyway,
        // matching this function's own id_kind check just below, in case a
        // future caller invokes this directly.
        if (strlen($table) > self::MAX_ENTITY_TYPE_LEN) {
            throw new \RuntimeException(
                "duo: table '$table' name is " . strlen($table) . ' chars — a table row entity_type IS the table '
                . 'name itself, which must be 1-' . self::MAX_ENTITY_TYPE_LEN
                . ' chars (duo_map.entity_type/duo_state.entity_type are VARCHAR(' . self::MAX_ENTITY_TYPE_LEN . '))'
            );
        }
        if (isset($decl['pk'])) {
            throw new \RuntimeException(
                "duo: table '$table' declares identity.mode=composite_ref AND a 'pk' — "
                . "composite_ref tables have no scalar primary key; remove 'pk'"
            );
        }
        $idKind = (string) ($decl['id_kind'] ?? '');
        if ($idKind === '' || strlen($idKind) > self::MAX_ID_KIND_LEN) {
            throw new \RuntimeException(
                "duo: table '$table' declares id_kind '$idKind' — must be 1-" . self::MAX_ID_KIND_LEN
                . ' chars (duo_map.id_kind is VARCHAR(' . self::MAX_ID_KIND_LEN . '))'
            );
        }
        if (!empty($decl['invalidate'])) {
            throw new \RuntimeException(
                "duo: table '$table' declares identity.mode=composite_ref with 'invalidate' — "
                . "run_invalidate()'s {id} substitution assumes a single scalar local id, which this mode has no "
                . 'equivalent of; unsupported, not silently ignored (no composite_ref fixture has needed it — see docblock)'
            );
        }
        $idCols = $decl['identity']['columns'] ?? null;
        if (!is_array($idCols) || count($idCols) !== 2) {
            throw new \RuntimeException(
                "duo: table '$table' declares identity.mode=composite_ref with identity.columns != exactly 2 entries "
                . '— this is the only shape this engine has proven (see assert_composite_row_schema()\'s docblock)'
            );
        }
        $refCols = array_column($decl['refs'] ?? [], 'column');
        $sortedIdCols = $idCols;
        sort($sortedIdCols);
        $sortedRefCols = $refCols;
        sort($sortedRefCols);
        if ($sortedIdCols !== $sortedRefCols) {
            throw new \RuntimeException(
                "duo: table '$table' identity.columns [" . implode(', ', $idCols)
                . "] must be EXACTLY its refs[] columns [" . implode(', ', $refCols)
                . '] — composite_ref is only for pure join tables: every identity column is a ref, every ref is an identity column'
            );
        }

        $colKeys = array_keys($decl['columns'] ?? []);
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
        $accounted = array_merge($refCols, $colKeys);
        $undeclared = array_diff($live, $accounted);
        if ($undeclared) {
            sort($undeclared);
            throw new \RuntimeException(
                "duo: table '$table' has undeclared column(s): " . implode(', ', $undeclared)
                . ' — every real column must be classified (as a composite_ref identity/ref column, or a columns'
                . ' entry with class authored/runtime/derived/env) before this table can be captured'
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
     * (id_column + the owner-linking column + the key/value column pair +
     * any declared legacy mirror pair) — asserted exactly, not just "at
     * least these columns," so a plugin schema change is caught the same
     * way assert_row_schema() catches one, even though there is no per-KEY
     * enumeration to check (see this file's docblock for why that's a
     * deliberately different completeness problem for an EAV sidecar).
     *
     * `id_column` (DUO-3235, task #126) — the sidecar's own PK column NAME,
     * defaulting to `'id'` for exact backward compatibility with every
     * fixture that shipped before this field existed (nf3_*_meta,
     * woocommerce_attribute_taxonomies have no attached-meta table at all,
     * so only nf3_*_meta is a real precedent, and its PK genuinely IS
     * named 'id' — confirmed by reading manifests/ninja-forms.json's own
     * declarations, which never needed an override). Found hardcoded
     * (literal `'id'`) at THREE call sites, not just this one — capturing
     * or applying a table declaring an override would have hit the other
     * two even after this method alone stopped throwing (the identical
     * two-bugs-hiding-each-other trap DUO-3212's Blocks.php/Lint.php pair
     * hit): capture_meta_rows()'s `ORDER BY id` and reconcile_meta()'s
     * `SELECT id, ...` + its UPDATE `WHERE id = ...`. All three now read
     * this same declared/defaulted column name. Proving fixture:
     * pmpro_membership_levelmeta, whose real PK column is `meta_id` (DESCRIBE'd
     * live on this round's own sandbox pair — see manifests/paid-memberships-pro.json).
     */
    public static function assert_meta_schema(string $table, array $decl): void {
        $attachCol = (string) ($decl['attached_to']['column'] ?? '');
        if (($decl['attached_to']['table'] ?? '') === '' || $attachCol === '') {
            throw new \RuntimeException("duo: table '$table' declares " . self::CLASS_META . " with no attached_to.{table,column}");
        }
        $idCol = (string) ($decl['id_column'] ?? 'id');
        $keyCol = (string) ($decl['key_column'] ?? 'meta_key');
        $valCol = (string) ($decl['value_column'] ?? 'meta_value');
        $expected = array_unique(array_filter([
            $idCol, $attachCol, $keyCol, $valCol,
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
     * a non-minting pass derives a declared natural-key identity, but a
     * populated mapped row without ledger metadata blocks instead of
     * becoming invisible. Minting capture may create identity only for a
     * genuinely new mapped row after canonical history has been checked.
     *
     * @return array<int, array{uuid:string, type:string, path:string, content:string}>
     */
    public static function capture(Policy $policy, Tokens $tokens, bool $mint): array {
        $rowTables = self::row_tables($policy); // throws on duplicate id_kind
        if (!$rowTables) {
            return [];
        }
        self::repair_truncated_entity_types($policy); // DUO-3246 — before any Ledger::set() below can hit the guard
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
                "duo: attached-meta keys exist outside a version-pinned declared keyspace (loud-and-blocking gate):\n  - "
                . implode("\n  - ", $lines)
            );
        }

        $entities = [];
        foreach (self::topo_order($rowTables) as $table) {
            $entities = array_merge($entities, self::capture_table(
                $table, $rowTables[$table], $metaByOwner[$table] ?? [], $tokens, $mint
            ));
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
    public static function keyspace_gaps(Policy $policy): array {
        global $wpdb;
        $out = [];
        foreach (self::meta_tables($policy) as $table => $decl) {
            $keyspace = $decl['keyspace'] ?? null;
            if (!is_array($keyspace)) {
                continue;
            }
            $prefixed = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $table);
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
                continue;
            }
            $keyCol = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($decl['key_column'] ?? 'meta_key'));
            $valCol = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($decl['value_column'] ?? 'meta_value'));
            $rows = $wpdb->get_results(
                "SELECT `$keyCol` AS k, `$valCol` AS v FROM `$prefixed` ORDER BY `$keyCol` ASC",
                ARRAY_A
            ) ?: [];
            $unknown = [];
            foreach ($rows as $row) {
                $key = (string) $row['k'];
                if (self::meta_key_in_keyspace($decl, $key)) {
                    continue;
                }
                $raw = $row['v'];
                $value = is_string($raw) && is_serialized($raw)
                    ? @unserialize(trim($raw), ['allowed_classes' => false])
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

    private static function capture_table(string $table, array $decl, array $metaDecls, Tokens $tokens, bool $mint): array {
        if (self::is_composite_ref($decl)) {
            // $mint/$metaDecls are unused here on purpose: composite_ref rows
            // are never "minted" (see identify_composite_row()'s docblock)
            // and can never own an attached-meta sidecar (meta_tables_by_
            // owner() already refuses that combination before capture()
            // ever reaches this call).
            return self::capture_composite_table($table, $decl, $tokens);
        }
        global $wpdb;
        $pk = $decl['pk'];
        $prefixed = $wpdb->prefix . $table;
        $rows = $wpdb->get_results("SELECT * FROM `$prefixed` ORDER BY `$pk` ASC", ARRAY_A) ?: [];

        $entities = [];
        foreach ($rows as $row) {
            $localId = (int) $row[$pk];
            $uuid = self::identify_row($table, $decl, $row, $localId, $mint);

            $columns = [];
            foreach ($decl['columns'] ?? [] as $col => $rule) {
                if (($rule['class'] ?? '') !== 'authored') {
                    continue; // runtime/derived/env: excluded from canonical state entirely
                }
                $v = $row[$col] ?? null;
                self::guard_secret($v, !empty($rule['allow_secret']), "table '$table' column '$col' (row $localId)");
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
            $identityNote = IdentityNotes::natural_key_continuity($uuid, $table, $decl, $columns);
            if ($identityNote !== null && !in_array($identityNote, $tokens->notes, true)) {
                $tokens->notes[] = $identityNote;
            }
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
     * Capture for identity.mode=composite_ref tables (DUO-3235, task #125).
     * No $mint parameter (unlike capture_table()): a composite_ref row's
     * uuid is a pure function of its two resolved refs, recomputed fresh
     * every call — there is no un-minted state to gate visibility behind
     * (see this file's docblock, "Identity: three modes"). Every row whose
     * refs currently resolve is captured, on BOTH Capture::run() and
     * Capture::snapshot() alike.
     */
    private static function capture_composite_table(string $table, array $decl, Tokens $tokens): array {
        global $wpdb;
        $idKind = $decl['id_kind'];
        $cols = $decl['identity']['columns'];
        $prefixed = $wpdb->prefix . $table;
        $orderBy = implode(', ', array_map(fn($c) => "`$c`", $cols));
        $rows = $wpdb->get_results("SELECT * FROM `$prefixed` ORDER BY $orderBy ASC", ARRAY_A) ?: [];

        $entities = [];
        foreach ($rows as $row) {
            [$uuid, $tokensByCol, $localByCol] = self::identify_composite_row($table, $decl, $row, $tokens);

            $columns = $tokensByCol; // identity/ref columns, tokenized — same flat "columns" shape regular rows use
            foreach ($decl['columns'] ?? [] as $col => $rule) {
                if (($rule['class'] ?? '') !== 'authored') {
                    continue; // runtime/derived/env: excluded — e.g. PMPro's own `modified` auto-timestamp column
                }
                $v = $row[$col] ?? null;
                self::guard_secret($v, !empty($rule['allow_secret']), "table '$table' column '$col' (composite row $uuid)");
                $columns[$col] = is_string($v) ? $tokens->tokenize_text($v) : $v;
            }

            $packed = self::pack_composite_id($table, $localByCol);
            Ledger::set($uuid, $table, $idKind, $packed);

            $front = [
                'columns' => (object) $columns,
                'meta' => (object) [], // composite_ref tables can never own an attached-meta sidecar — see meta_tables_by_owner()
                'table' => $table,
                'uuid' => $uuid,
            ];
            // "--slug" suffix (DUO-3239 finding, overriding a previous
            // deliberate choice; also required unconditionally by
            // RepositoryCompiler's own filename validator — every non-menu
            // entity's basename must start with "<uuid>--", so this can't
            // simply be dropped): built from the TWO REFERENCED ENTITIES'
            // OWN uuids (short prefixes, via uuid_from_token() — already
            // resolved above by identify_composite_row(), not a second
            // lookup), never this environment's local ids. Still lets two
            // different rows of the same table look different in a
            // directory listing (unlike a single static per-table fallback,
            // e.g. id_kind, would), while staying fully portable.
            //
            // This used to join THIS environment's own local ids
            // ("<local-id-1>-<local-id-2>"), defended as "cosmetic only ...
            // the same as every other slug's 'renames change the filename's
            // slug half' convention already tolerates looking different
            // across environments." That analogy doesn't actually hold: a
            // post/term slug is AUTHORED content that transfers verbatim
            // across environments via apply, so it stays identical under an
            // ordinary cross-environment round-trip — it only changes when
            // the content genuinely changes. A local id is NEVER transferred
            // (Apply.php always mints a fresh one on the target) and differs
            // by construction, so the old local-id slug produced a spurious
            // filename difference on EVERY cross-environment
            // capture-apply-recapture, not just on an actual rename.
            // Confirmed live during DUO-3239: a directory-tree round-trip
            // diff (conformance/run.sh's `diff -r`) caught it;
            // regress_pmpro_composite_ref.sh's own file-content-only diff
            // (explicit `diff -u $file_a $file_b`, never a directory
            // listing) structurally could never have exercised this, so it
            // went uncaught since DUO-3235.
            $slug = implode('-', array_map(
                fn($c) => substr(self::uuid_from_token($tokensByCol[$c]), 0, 8),
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

    /**
     * Secret guard for typed-snapshot capture (DUO-3214 — this mechanism
     * had NONE before this: Capture.php's guard_secret() has gated
     * authored options/post_meta since the beginning, but never ran here,
     * even though a table column or attached-meta value is exactly as
     * capable of holding a stray API key as a post_meta value is). Same
     * posture as Capture's guard, mirrored rather than shared (Capture.php
     * is a held file, and its guard_secret() is an instance method bound to
     * an option/post_meta $section/$key shape that doesn't fit a table's
     * column/key naming anyway): a hard-pattern match on an AUTHORED
     * value aborts capture loudly, naming exactly where it was found. A
     * column or attached-meta `keys{}` entry may declare `"allow_secret":
     * true` — the SAME escape hatch option/post_meta rules already use,
     * settable in a manifest or (since Policy::declared_tables() merges
     * site.duo.json's policy.tables last) a site policy override — for a
     * confirmed false positive.
     *
     * hard_match_deep(), not hard_match(): every value this file captures
     * today is a flat string (a raw column read, or an attached-meta value
     * this file deliberately never unserializes — see capture_meta_rows()'s
     * docblock), so a plain hard_match() would suffice for the CURRENT
     * fixtures, but this mechanism makes no promise that stays true for a
     * table declared later, and the deep scan costs nothing extra on a
     * value that is already flat (hard_match_deep() on a string is exactly
     * one hard_match() call, no recursion entered).
     */
    private static function guard_secret($v, bool $allowSecret, string $where): void {
        if ($allowSecret) {
            return;
        }
        $label = Secrets::hard_match_deep($v);
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

    /**
     * Identity for one row: reuse the existing duo_map entry if one exists
     * and re-affirm it via contradiction-intolerant Ledger::set(). A
     * never-seen mapped row gets NEW identity only when $mint is true;
     * natural-key rows derive identity in either mode. See this file's
     * docblock for the mapped-vs-natural_key recovery split.
     */
    private static function identify_row(string $table, array $decl, array $row, int $localId, bool $mint): string {
        $idKind = $decl['id_kind'];
        $uuid = Ledger::uuid_for($localId, $idKind);
        if ($uuid === null) {
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
                if (!$mint) {
                    throw new \RuntimeException(
                        "duo: mapped identity missing for populated table '$table' row $localId ($idKind); "
                        . 'refusing to create or rebind it. Restore a verified identity sidecar with '
                        . '`wp duo identity-import --repo=<repo> --in=<file>` before plan/apply'
                    );
                }
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
     *   finalize_composite_row() resolves the apply direction separately)
     * @return array{0:string, 1:array<string,string>, 2:array<string,int>}
     */
    private static function identify_composite_row(string $table, array $decl, array $row, Tokens $tokens): array {
        $cols = $decl['identity']['columns'];
        $kindByCol = [];
        foreach ($decl['refs'] as $ref) {
            $kindByCol[$ref['column']] = $ref['kind'];
        }

        $uuidParts = [$table];
        $tokensByCol = [];
        $localByCol = [];
        foreach ($cols as $col) {
            $raw = (int) ($row[$col] ?? 0);
            if ($raw <= 0) {
                throw new \RuntimeException(
                    "duo: $table row has empty identity column '$col' — every composite_ref identity "
                    . 'column is structural, never optional (there is no partial version of a join fact)'
                );
            }
            $tok = $tokens->id_to_token($raw, $kindByCol[$col]);
            if ($tok === null) {
                // STRUCTURAL ref, same posture as capture_table()'s own
                // refs[] loop — see this file's docblock, "Refs: required
                // vs optional".
                throw new \RuntimeException(
                    "duo: $table row has unmanaged {$kindByCol[$col]} ref $raw in identity column '$col' — "
                    . 'capture scope must include the referenced row'
                );
            }
            $uuidParts[] = "$col=" . self::uuid_from_token($tok);
            $tokensByCol[$col] = $tok;
            $localByCol[$col] = $raw;
        }

        $uuid = Uuid::v5(Uuid::NAMESPACE_DUO, implode(':', $uuidParts));
        return [$uuid, $tokensByCol, $localByCol];
    }

    /** Extracts the bare uuid out of a "{{kind:uuid}}" token — the SAME
     *  shape Tokens::id_to_token() always produces, matched with the exact
     *  uuid pattern Tokens::token_to_id() itself validates against, so this
     *  never silently disagrees with what the rest of the engine considers
     *  a well-formed token. Used only to build composite_ref's uuid-
     *  derivation input (identify_composite_row()) from a token this file
     *  already resolved via Tokens — never a second, independent lookup. */
    private static function uuid_from_token(string $token): string {
        if (!preg_match('/^\{\{[a-z][a-z0-9_]*:([0-9a-f-]{36})\}\}$/', $token, $m)) {
            throw new \RuntimeException("duo: malformed ref token '$token' (composite_ref identity derivation)");
        }
        return $m[1];
    }

    /**
     * Packs a composite_ref row's two CURRENT local ids into duo_map's
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
    private const COMPOSITE_COMPONENT_BITS = 31;
    private const COMPOSITE_COMPONENT_MAX = (1 << self::COMPOSITE_COMPONENT_BITS) - 1;

    /**
     * @param array<string,int> $colVals identity column name => this
     *   environment's current local id, in identity.columns order (exactly
     *   what identify_composite_row()/finalize_composite_row() already build
     *   as $localByCol — passed straight through, not reassembled). Naming
     *   the table AND both column=value pairs in the failure message (not
     *   just the single offending scalar) is deliberate: an operator hitting
     *   this on a real site needs to see the WHOLE tuple to know which row
     *   is unrepresentable and why, not decode a bare number.
     */
    private static function pack_composite_id(string $table, array $colVals): int {
        $pairs = [];
        $overflow = [];
        foreach ($colVals as $col => $v) {
            $pairs[] = "$col=$v";
            if ($v < 0 || $v > self::COMPOSITE_COMPONENT_MAX) {
                $overflow[] = "$col=$v";
            }
        }
        if ($overflow) {
            throw new \RuntimeException(
                "duo: table '$table' composite identity (" . implode(', ', $pairs) . ') has out-of-budget component(s) ('
                . implode(', ', $overflow) . ') — each component must be 0..' . self::COMPOSITE_COMPONENT_MAX
                . " for this engine's packed local_id (see pack_composite_id()'s docblock)"
            );
        }
        $vals = array_values($colVals);
        return ($vals[0] << self::COMPOSITE_COMPONENT_BITS) | $vals[1];
    }

    /** @return array{0:int, 1:int} */
    private static function unpack_composite_id(int $packed): array {
        return [$packed >> self::COMPOSITE_COMPONENT_BITS, $packed & self::COMPOSITE_COMPONENT_MAX];
    }


    /**
     * An attached-meta key's classification RULE: the `keys{}`-declared
     * entry, or a synthetic {"class": default_class} when the key is
     * undeclared — the exact two-step lookup (declared entry, else the
     * table's own default_class) both capture and apply must agree on.
     * Extracted as the SINGLE source of truth for capture_meta_rows() below
     * (which key is excluded from canonical state entirely) and
     * reconcile_meta()'s delete loop (which live key an apply may remove),
     * specifically so the two can never independently drift on which keys
     * are owned (DUO-3204 — see reconcile_meta()'s docblock: before this
     * existed, its delete loop had no classification check at all).
     *
     * @param array<string,array> $keyRules decl['keys'] ?? []
     */
    private static function meta_key_rule(array $keyRules, string $default, string $key): array {
        return $keyRules[$key] ?? ['class' => $default];
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
        $idCol = $decl['id_column'] ?? 'id';
        $keyCol = $decl['key_column'] ?? 'meta_key';
        $valCol = $decl['value_column'] ?? 'meta_value';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT `$keyCol` AS k, `$valCol` AS v FROM `$prefixed` WHERE `$attachCol` = %d ORDER BY `$idCol` ASC",
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
            $rule = self::meta_key_rule($keyRules, $default, $key);
            $class = $rule['class'] ?? $default;
            if ($class !== 'authored') {
                continue; // runtime/derived/env sidecar key — excluded, mirrors post_meta
            }
            $v = $values[0];
            self::guard_secret($v, !empty($rule['allow_secret']), "table '$metaTable' key '$key' (parent $ownerLocalId)");
            if (!empty($rule['ref'])) {
                $n = (int) $v;
                if ($n <= 0) {
                    $out[$key] = null;
                    continue;
                }
                $tok = $tokens->id_to_token($n, $rule['ref']);
                if ($tok === null) {
                    // DUO-3212: id_to_token() no longer warns internally (see
                    // its own docblock) -- this was this call site's ONLY
                    // warning coverage, so it's now explicit here.
                    // OPTIONAL value ref: drop-with-warning, not a row-level throw.
                    $tokens->warnings[] = "table '$metaTable' key '$key' (parent $ownerLocalId): unmapped "
                        . "{$rule['ref']} id $n dropped (dangling reference)";
                    continue;
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
     */
    public static function find_collision(Policy $policy, array $entity): ?int {
        global $wpdb;
        $decl = self::row_tables($policy)[$entity['type']] ?? null;
        if ($decl === null || ($decl['identity']['mode'] ?? 'mapped') !== 'natural_key') {
            return null;
        }
        $front = $entity['data'] ?? Canon::decode($entity['content']);
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
        if (self::is_composite_ref($decl)) {
            // No phase-1 placeholder for this mode: a composite_ref row has
            // no identity of its own for anything ELSE to reference early
            // (it's a leaf — "a row IS the fact," see this file's docblock),
            // so the entire create-or-confirm operation happens once, in
            // finalize_composite_row() below, during phase 2.
            return false;
        }
        $idKind = $decl['id_kind'];
        $front = $entity['data'] ?? Canon::decode($entity['content']);
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
        Db::insert($prefixed, $data, $format, "apply insert typed-snapshot row {$entity['type']}");
        $localId = Db::insert_id("apply insert typed-snapshot row {$entity['type']}");
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
        if (self::is_composite_ref($decl)) {
            self::finalize_composite_row($tokens, $entity, $decl);
            return;
        }
        $idKind = $decl['id_kind'];
        $front = $entity['data'] ?? Canon::decode($entity['content']);
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
        Db::update($prefixed, $data, [$pk => $localId], $format, '%d', "apply update typed-snapshot row {$entity['type']}");

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
     * The ONLY phase for a composite_ref row (see ensure_row()'s no-op
     * branch above): resolve both identity/ref columns through THIS
     * environment's own ledger to ITS current local ids, then ensure the
     * exact-tuple row exists with its authored columns (if any — PMPro's own
     * two proven fixtures declare none beyond the identity tuple itself)
     * current. Deliberately an "exists ? update-authored-columns :
     * insert-full-row" shape, not a blind INSERT — see this file's docblock:
     * the row's IDENTITY can never appear in plan's `update` bucket (any
     * change to either ref changes the uuid), but a manifest declaring
     * genuine EXTRA authored columns beyond the tuple (unexercised this
     * round, but structurally supported — see assert_composite_row_schema())
     * would need exactly this upsert shape to converge correctly on re-apply.
     *
     * Idempotent by construction: re-applying an already-converged tuple
     * re-resolves the SAME local ids, the exists-check finds the SAME row,
     * and (with no authored columns to write) the update branch is a no-op —
     * matching plan's own "this uuid can only ever be create or unchanged,
     * never update" analysis for the pure-join proving fixture.
     */
    private static function finalize_composite_row(Tokens $tokens, array $entity, array $decl): void {
        global $wpdb;
        $idKind = $decl['id_kind'];
        // Same dual-source read as ensure_row()/finalize_row()/find_collision()
        // above: RepositoryCompiler-built tree entries (the live Apply::run()
        // path since DUO-3208) carry pre-decoded 'data', never a raw 'content'
        // string — this was the one call site DUO-3235 missed when adding
        // composite_ref, so every composite_ref row fatal'd on its first real
        // apply through the CLI (Canon::decode(null)). 'content' stays as the
        // fallback for any caller that still passes the older
        // Snapshot::load_tree_entries() shape directly.
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $uuid = $front['uuid'];
        $cols = $decl['identity']['columns'];
        $prefixed = $wpdb->prefix . $entity['type'];
        $colTypes = self::live_column_types($entity['type']) ?? [];

        $localByCol = [];
        foreach ($cols as $col) {
            $tok = (string) ($front['columns'][$col] ?? '');
            // Structural, same as capture's own throw — token_to_id() itself
            // throws when this environment cannot resolve it.
            $localByCol[$col] = $tokens->token_to_id($tok);
        }

        $authored = [];
        foreach ($decl['columns'] ?? [] as $col => $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $v = $front['columns'][$col] ?? null;
            $authored[$col] = is_string($v) ? $tokens->detokenize_text($v) : $v;
        }

        $where = [$cols[0] => $localByCol[$cols[0]], $cols[1] => $localByCol[$cols[1]]];
        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM `$prefixed` WHERE `{$cols[0]}` = %d AND `{$cols[1]}` = %d LIMIT 1",
            $localByCol[$cols[0]], $localByCol[$cols[1]]
        ));
        if ($exists) {
            if ($authored) {
                [$data, $format] = self::write_format($authored, $colTypes);
                $wpdb->update($prefixed, $data, $where, $format);
            }
        } else {
            [$data, $format] = self::write_format($where + $authored, $colTypes);
            $wpdb->insert($prefixed, $data, $format);
        }

        // Bookkeeping ONLY — never consulted to establish identity (see this
        // file's docblock) — so delete_row() can later resolve this uuid
        // back to a tuple to delete on THIS environment.
        $packed = self::pack_composite_id($entity['type'], $localByCol);
        Ledger::set($uuid, $entity['type'], $idKind, $packed);
    }

    /**
     * Reconcile an attached-meta table's rows for one owner to exactly the
     * desired key set — same "delete what's no longer wanted, upsert the
     * rest" shape as Apply::finalize_post()'s postmeta reconciliation
     * (Apply.php:806-811), and, since DUO-3204, the SAME ownership gate:
     * finalize_post() only deletes a live meta_id whose rule class ===
     * 'authored'; the delete loop below now does the exact same check,
     * via meta_key_rule() — the identical lookup capture_meta_rows() uses,
     * so the two paths can never independently disagree about which keys
     * this mechanism owns.
     *
     * Before this check existed, the delete loop ran unconditionally on
     * every live key simply absent from $desiredRaw — with no classification
     * check at all. That silently deleted manifest-declared RUNTIME keys on
     * every apply: editActive/drawerDisabled/_seq_num (manifests/
     * ninja-forms.json's nf3_*_meta declarations — the admin JS builder's
     * own ui-state scratch flags, confirmed by reading Ninja Forms' source)
     * can never appear in $desiredMeta (capture_meta_rows() already excludes
     * non-authored keys from canonical state), so their live absence from
     * $desiredRaw is expected and permanent, not "no longer wanted" — a raw
     * $wpdb->delete() fires no WordPress hooks, so this was silent and
     * invisible to the canary.
     *
     * default_class still governs an UNDECLARED key exactly as it does at
     * capture, and this is deliberate, not an oversight: every shipped
     * nf3_*_meta table declares default_class:"authored", so a live key
     * with no keys{} entry IS authored-classified — deleting it when absent
     * from canonical is correct ownership (an EAV sidecar's "capture
     * everything not explicitly excepted" discipline — see this file's
     * docblock, "The two table shapes"). Only the keys{} map's explicit
     * non-authored entries (or a hypothetical non-authored default_class)
     * are what must survive this loop.
     */
    private static function reconcile_meta(string $metaTable, array $decl, int $ownerLocalId, array $desiredMeta, Tokens $tokens): void {
        global $wpdb;
        $prefixed = $wpdb->prefix . $metaTable;
        $attachCol = $decl['attached_to']['column'];
        $idCol = $decl['id_column'] ?? 'id';
        $keyCol = $decl['key_column'] ?? 'meta_key';
        $valCol = $decl['value_column'] ?? 'meta_value';
        $keyRules = $decl['keys'] ?? [];
        $default = $decl['default_class'] ?? 'authored';

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT `$idCol` AS id, `$keyCol` AS k FROM `$prefixed` WHERE `$attachCol` = %d", $ownerLocalId
        ), ARRAY_A) ?: [];
        $existingByKey = [];
        foreach ($existing as $r) {
            $existingByKey[(string) $r['k']] = (int) $r['id'];
        }

        $desiredRaw = [];
        foreach ($desiredMeta as $key => $v) {
            if (!self::meta_key_in_keyspace($decl, (string) $key)) {
                throw new \RuntimeException(
                    "duo: repository asks apply to write table_meta:$metaTable:$key outside its declared keyspace"
                );
            }
            $rule = self::meta_key_rule($keyRules, $default, $key);
            if (!empty($rule['ref'])) {
                $desiredRaw[$key] = $v === null ? '0' : (string) $tokens->token_to_id((string) $v);
            } elseif (is_string($v)) {
                $desiredRaw[$key] = $tokens->detokenize_text($v);
            } else {
                $desiredRaw[$key] = $v === null ? null : (string) $v;
            }
        }

        foreach ($existingByKey as $key => $rowId) {
            if (array_key_exists($key, $desiredRaw)) {
                continue;
            }
            if (!self::meta_key_in_keyspace($decl, $key)) {
                continue; // outside the adapter's declared ownership; discovery gate reports it, never delete it
            }
            $rule = self::meta_key_rule($keyRules, $default, $key);
            if (($rule['class'] ?? $default) !== 'authored') {
                continue; // runtime/derived/env key — never owned by this mechanism, never deleted
            }
            Db::delete($prefixed, [$idCol => $rowId], null, "apply delete authored $metaTable sidecar row");
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
                Db::update($prefixed, $data, [$idCol => $existingByKey[$key]], null, null, "apply update $metaTable sidecar row");
            } else {
                Db::insert($prefixed, $data, null, "apply insert $metaTable sidecar row");
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
                Db::query(
                    $wpdb->prepare("DELETE FROM `$prefixed` WHERE `$col` = %d", $localId),
                    "apply invalidate $t cache row"
                );
            }
        }
        if (isset($inv['option_pattern'])) {
            $name = str_replace('{id}', (string) $localId, (string) $inv['option_pattern']);
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $name
            ));
            if ($exists !== null) {
                Db::delete($wpdb->options, ['option_name' => $name], null, 'apply invalidate option cache row');
            }
            wp_cache_delete($name, 'options');
            wp_cache_delete('alloptions', 'options');
        }
    }

    /**
     * Delete one row and its attached-meta sidecar (flag-gated by the
     * caller, matching posts/terms — Apply::delete_entity()'s existing
     * with-deletes convention). Apply::deletion_rank() owns cross-table
     * children-before-parent ordering; this helper deletes one exact row.
     */
    public static function delete_row(Policy $policy, string $uuid, string $table): void {
        $decl = self::row_tables($policy)[$table] ?? null;
        if ($decl === null) {
            return; // table no longer declared (manifest unpinned) — nothing safe to do
        }
        $idKind = $decl['id_kind'];
        $localId = Ledger::id_for($uuid, $idKind);
        if ($localId === null) {
            return;
        }
        self::delete_local_row($policy, $table, $localId);
        // Identity/base metadata is forgotten by Apply's post-rebuild ledger
        // transaction. Doing it here would commit convergence metadata with
        // the authored-row transaction before required rebuilds succeeded.
    }

    /**
     * Delete an exact local row through the same typed-snapshot cascade and
     * invalidation path as apply. Orphans uses this because a damaged row may
     * itself have lost its duo_map identity and therefore cannot be selected
     * by UUID; the table declaration + local primary key remain sufficient
     * deletion authority once the operator selects that listed orphan.
     */
    public static function delete_local_row(Policy $policy, string $table, int $localId): void {
        global $wpdb;
        $decl = self::row_tables($policy)[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot delete row from undeclared table '$table'");
        }
        if (self::is_composite_ref($decl)) {
            // No attached-meta, no invalidate (both refused at schema-assert
            // time for this mode — see assert_composite_row_schema()): the
            // packed local_id IS the bookkeeping delete_row() exists to
            // read, unpacked back into the tuple to delete on THIS
            // environment (never the tuple captured on some OTHER
            // environment — see finalize_composite_row()'s docblock).
            $cols = $decl['identity']['columns'];
            [$a, $b] = self::unpack_composite_id($localId);
            Db::delete(
                $wpdb->prefix . $table,
                [$cols[0] => $a, $cols[1] => $b],
                null,
                "apply delete composite typed-snapshot row $table"
            );
            return;
        }
        foreach (self::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            Db::delete(
                $wpdb->prefix . $metaName,
                [$metaDecl['attached_to']['column'] => $localId],
                null,
                "apply delete $metaName sidecar rows"
            );
        }
        foreach ($decl['invalidate'] ?? [] as $inv) {
            self::run_invalidate($inv, $localId);
        }
        Db::delete($wpdb->prefix . $table, [$decl['pk'] => $localId], null, "apply delete typed-snapshot row $table");
    }

    /** Reparent one scalar typed row and any declared attached-meta mirror. */
    public static function reparent_local_row(
        Policy $policy,
        string $table,
        int $localId,
        string $column,
        int $targetId
    ): void {
        global $wpdb;
        $decl = self::row_tables($policy)[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot reparent row in undeclared table '$table'");
        }
        if (self::is_composite_ref($decl)) {
            throw new \RuntimeException(
                "duo: reparenting composite_ref table '$table' changes the row's identity; delete the orphaned fact instead"
            );
        }
        $ref = null;
        foreach ($decl['refs'] ?? [] as $candidate) {
            if (($candidate['column'] ?? '') === $column) {
                $ref = $candidate;
                break;
            }
        }
        if ($ref === null) {
            throw new \RuntimeException("duo: '$column' is not a declared structural ref column of '$table'");
        }
        Db::update(
            $wpdb->prefix . $table,
            [$column => $targetId],
            [$decl['pk'] => $localId],
            ['%d'],
            ['%d'],
            "orphans reparent typed-snapshot row $table"
        );

        // Some plugins mirror a row ref into an attached EAV setting (Ninja
        // Forms' parent_id is the proving fixture). Keep that declared mirror
        // coherent in the same transaction; raw operator SQL cannot do this
        // safely because it does not know the manifest relationship.
        foreach (self::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            $rule = $metaDecl['keys'][$column] ?? null;
            if (($rule['ref'] ?? null) !== ($ref['kind'] ?? null)) {
                continue;
            }
            $data = [$metaDecl['value_column'] => (string) $targetId];
            if (isset($metaDecl['legacy_value_column'])) {
                $data[$metaDecl['legacy_value_column']] = (string) $targetId;
            }
            Db::update(
                $wpdb->prefix . $metaName,
                $data,
                [
                    $metaDecl['attached_to']['column'] => $localId,
                    $metaDecl['key_column'] => $column,
                ],
                null,
                null,
                "orphans reparent $metaName ref mirror"
            );
        }
        foreach ($decl['invalidate'] ?? [] as $inv) {
            self::run_invalidate($inv, $localId);
        }
    }

    /**
     * Prove a typed-table delete and every declared attached-meta cascade
     * affected the exact target identity. Called before commit; any survivor
     * throws and rolls the whole apply transaction back.
     */
    public static function assert_row_deleted(Policy $policy, string $table, int $localId): void {
        global $wpdb;
        $decl = self::row_tables($policy)[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot verify deletion of undeclared table '$table'");
        }
        $prefixed = $wpdb->prefix . $table;
        if (self::is_composite_ref($decl)) {
            $cols = $decl['identity']['columns'];
            [$a, $b] = self::unpack_composite_id($localId);
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$prefixed` WHERE `{$cols[0]}` = %d AND `{$cols[1]}` = %d",
                $a,
                $b
            ));
        } else {
            $pk = preg_replace('/[^A-Za-z0-9_]/', '', (string) $decl['pk']);
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$prefixed` WHERE `$pk` = %d",
                $localId
            ));
        }
        if ($remaining !== 0) {
            throw new \RuntimeException("duo: deletion verification failed for $table local id $localId");
        }
        foreach (self::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            $fk = preg_replace('/[^A-Za-z0-9_]/', '', (string) $metaDecl['attached_to']['column']);
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}$metaName` WHERE `$fk` = %d",
                $localId
            ));
            if ($count !== 0) {
                throw new \RuntimeException(
                    "duo: deletion verification failed for $table local id $localId: $count attached $metaName row(s) remain"
                );
            }
        }
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
     *
     * composite_ref tables are deliberately EXCLUDED from this loop — not a
     * silent gap: Ledger::prune_dead_table_map() joins duo_map.local_id
     * against a single live PK column (`src.\`$pk\` = m.local_id`), which
     * has no meaning for a packed composite value (see pack_composite_id()'s
     * docblock). This is a real, argued honest limitation, not an oversight:
     * a composite_ref row's uuid is NEVER looked up by its packed local_id
     * (identify_composite_row() always recomputes fresh — see this file's
     * docblock), only ever stored FOR delete_row()'s benefit, so a stale
     * entry left behind by a row deleted outside duo (e.g. an admin
     * unchecking "Require Membership") is INERT — it cannot cause a wrong
     * uuid-to-row association the way a stale mapped/natural_key entry
     * could — merely a duo_map row that lingers until the SAME uuid is ever
     * asked about again (vanishingly unlikely given uuidv5's distribution).
     * A real single-column-join-based pruner for packed composite values is
     * a straightforward future addition if this hygiene gap ever proves to
     * matter in practice; not built speculatively here.
     */
    public static function prune_dead_map(Policy $policy): void {
        $tables = [];
        foreach (self::row_tables($policy) as $name => $decl) {
            if (self::is_composite_ref($decl)) {
                continue;
            }
            $tables[$decl['id_kind']] = ['table' => $name, 'pk' => $decl['pk']];
        }
        if ($tables) {
            Ledger::prune_dead_table_map($tables);
        }
    }

    /**
     * Narrow dead-map hygiene for Capture::snapshot_options_core(). An
     * option_name_refs rule embeds a declared table row id in the option NAME
     * itself, so a stale mapping for that id_kind would mint a token for a
     * deleted row and let Apply resolve an orphan option back to the deleted
     * local id. The lifecycle boundary must reconcile those id_kinds, but it
     * must not enter the full typed-table pruner: a plugin may be inactive and
     * its declared table absent while its lifecycle hook is about to create it.
     * Ledger::prune_dead_table_map() already has the required absent-table
     * skip, so this method supplies only the option_name_refs intersection.
     *
     * Composite-ref tables are excluded for the same reason as
     * prune_dead_map(): their packed local_id has no single source PK to join
     * against, and no current option-name reference can resolve one safely.
     */
    public static function prune_option_name_ref_map(Policy $policy): void {
        $refKinds = [];
        foreach ($policy->option_name_ref_rules() as $rule) {
            $kind = (string) ($rule['id_kind'] ?? '');
            if ($kind !== '') {
                $refKinds[$kind] = true;
            }
        }
        if (!$refKinds) {
            return;
        }

        $tables = [];
        foreach (self::row_tables($policy) as $name => $decl) {
            $kind = (string) ($decl['id_kind'] ?? '');
            if (!isset($refKinds[$kind]) || self::is_composite_ref($decl)) {
                continue;
            }
            $tables[$kind] = ['table' => $name, 'pk' => $decl['pk']];
        }
        if ($tables) {
            Ledger::prune_dead_table_map($tables);
        }
    }

    /**
     * Does a live row exist for (id_kind, local_id), independent of whether
     * it has been minted a uuid yet? Task #93's option_name_refs discovery
     * (Capture::build_options()) needs this to distinguish DANGLING (no
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
}
