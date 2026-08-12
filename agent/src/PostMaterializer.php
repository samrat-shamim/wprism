<?php
namespace Duo;

require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/Tokens.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/RelationshipMaterializer.php';
require_once __DIR__ . '/AttachmentMaterializer.php';
require_once __DIR__ . '/Blocks.php';
// Deliberately NOT require_once('Ledger.php') or require_once('Db.php') here:
// sandbox/tests/regress_code_revision_enforcement.php and
// regress_scoped_promotion_target.php both reach this file transitively
// through Apply.php (direct requires, verified) and both stub a fake
// Duo\Ledger; regress_scoped_promotion_target.php additionally stubs a fake
// Duo\Db. Requiring either here would fatal with "Cannot redeclare class"
// against whichever of the two a given suite fakes -- the identical
// exclusion RelationshipMaterializer.php already documents for the same
// reason (verified via `grep -rlE '^\s*(final\s+)?class\s+(Db|Ledger)\s*(\{|extends|implements)'`
// against each file individually, not assumed from the combined match).
// None of the five newly-required files above (Policy, Tokens,
// ApplyFieldMaterializer, RelationshipMaterializer, AttachmentMaterializer,
// Blocks) require Db.php or Ledger.php themselves, so this slice's widening
// introduces no new transitive path to either -- verified by reading each
// file's own top-of-file requires, not assumed.

/**
 * The post materializer (DUO-3347 slice 10-11, one of the "Entity
 * materializers: posts, typed tables" target seams): creates a new post's
 * ledger-mapped wp_posts row (phase 1) and finalizes it (phase 2) -- the
 * field UPDATE plus authored-meta/relationship/attachment reconciliation --
 * against the live target.
 *
 * Slice 10 moved ensure_post_row() (phase 1) alone, deliberately leaving
 * finalize_post() (phase 2) on Apply: unlike ensure_post_row(), it also
 * called resolve_login()/read $defaultAuthor/appended to $warnings, and
 * resolve_login() itself had a second caller inside run() (seeding
 * $defaultAuthor) sharing its own memoization cache. Slice 11 (this one)
 * untangles that shared state the same way slice 5's UserMetaMaterializer
 * untangled the analogous resolve_exact_login()/$exactUserIds pair:
 * resolve_login() and its $userIds cache move here too, now PUBLIC (unlike
 * resolve_exact_login(), which stayed private) because run()'s own
 * $defaultAuthor-seeding call site is the one place outside this class that
 * still needs it -- Apply keeps a thin resolve_login() facade so that call
 * site needs no edit, the same "existing internal call site unchanged"
 * principle every prior slice in this series used.
 *
 * finalize_post() itself calls three already-extracted sibling
 * materializers (reconcile_authored_meta() -> ApplyFieldMaterializer,
 * reconcile_relationships() -> RelationshipMaterializer, place_attachment()
 * -> AttachmentMaterializer) directly rather than through Apply's own thin
 * facades over them -- calling back through Apply would be circular (Apply
 * already depends on PostMaterializer via post_materializer()). This widens
 * the constructor from slice 10's lone Tokens to five collaborators; each is
 * still the same kind of narrow, already-established dependency every
 * sibling materializer already takes, just five of them instead of one to
 * three, because finalize_post() genuinely touches more surfaces than
 * finalize_menu()/finalize_term() did. The one caller-scoped value
 * RelationshipMaterializer's own reconcile_relationships() needs --
 * Apply's memoized, WordPress-registry-reading taxes_for_post_type() roster
 * -- travels as an explicit $taxesForPostType parameter instead, matching
 * TermMaterializer's $termObjectTaxes / RelationshipMaterializer's
 * $taxesForPostType precedent (slices 6/8): it is shared, memoized,
 * per-apply-run-computed state, not a stable injectable dependency the way
 * Policy/Tokens are. $defaultAuthor (a scalar Apply computes once per run)
 * and $warnings (Apply's shared diagnostics collection, appended to by
 * dozens of call sites across the whole file) travel the same way, the
 * latter by reference -- extending the exact array-output-parameter idiom
 * OptionsMaterializer::apply_options()/apply_option_sub_keys() (slice 7)
 * already established across this same class boundary.
 *
 * Moved verbatim otherwise. Apply keeps ensure_post_row()/finalize_post()/
 * resolve_login() as thin compatibility facades via the same lazily-
 * constructed instance (post_materializer()) every prior materializer in
 * this series established, now passing the four additional collaborators.
 */
final class PostMaterializer {
    private array $userIds = [];

    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer,
        private readonly RelationshipMaterializer $relationshipMaterializer,
        private readonly AttachmentMaterializer $attachmentMaterializer,
    ) {
    }

    public function ensure_post_row(array $front): bool {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_POST) !== null) {
            return false;
        }
        Db::insert($wpdb->posts, [
            'post_author' => 0,
            'post_date' => $front['date'],
            'post_date_gmt' => $front['date_gmt'],
            'post_content' => '',
            // Post-field classification: written unconditionally even for a
            // 'derived'-classified field (e.g. a Woo variation title or
            // product timestamp) — a new
            // row needs SOME starting value and there's no rebuild action to
            // conjure one; finalize_post() below is where derived fields
            // stop being overwritten, once the row actually exists.
            'post_title' => $front['title'],
            'post_excerpt' => '',
            'post_status' => $front['status'],
            'comment_status' => $front['comment_status'],
            'ping_status' => $front['ping_status'],
            'post_password' => '',
            'post_name' => $front['slug'],
            'to_ping' => '',
            'pinged' => '',
            'post_modified' => $front['modified'] ?? $front['modified_gmt'],
            'post_modified_gmt' => $front['modified_gmt'],
            'post_content_filtered' => '',
            'post_parent' => 0,
            'guid' => $this->tokens->home() . '/?duo=' . $front['uuid'],
            'menu_order' => (int) ($front['menu_order'] ?? 0),
            'post_type' => $front['type'],
            'post_mime_type' => $front['mime'] ?? '',
            'comment_count' => 0,
        ], null, 'apply insert post');
        $id = Db::insert_id('apply insert post');
        Db::insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => '_duo_uuid', 'meta_value' => $front['uuid']], null, 'apply insert post identity');
        Ledger::set($front['uuid'], 'post', Ledger::KIND_POST, $id);
        return true;
    }

    public function finalize_post(
        array $front,
        string $body,
        ?int $defaultAuthor,
        array &$warnings,
        array $taxesForPostType
    ): void {
        global $wpdb;
        $id = Ledger::id_for($front['uuid'], Ledger::KIND_POST)
            ?? throw new \RuntimeException("duo: post {$front['uuid']} missing from ledger after phase 1");

        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = $this->tokens->token_to_id($front['parent']);
        }
        $authorId = 0;
        if (!empty($front['author'])) {
            $login = substr((string) $front['author'], 5); // strip "user:"
            $authorId = $this->resolve_login($login)
                ?? $defaultAuthor
                ?? 1;
            if ($this->resolve_login($login) === null) {
                $warnings[] = "post {$front['slug']}: author '$login' not in this environment; fell back to user #$authorId";
            }
        }

        $content = $this->policy->body_mode($front['type']) === 'verbatim'
            ? $body
            : Blocks::apply_rewrite($body, $this->policy, $this->tokens);
        $fields = [
            'post_author' => $authorId,
            'post_date' => $front['date'],
            'post_date_gmt' => $front['date_gmt'],
            'post_content' => $content,
            'post_title' => $front['title'],
            'post_excerpt' => $this->tokens->detokenize_text((string) $front['excerpt']),
            'post_status' => $front['status'],
            'comment_status' => $front['comment_status'],
            'ping_status' => $front['ping_status'],
            'post_name' => $front['slug'],
            'post_modified' => $front['modified'] ?? $front['modified_gmt'],
            'post_modified_gmt' => $front['modified_gmt'],
            'post_parent' => $parentId,
            'menu_order' => (int) ($front['menu_order'] ?? 0),
            'post_mime_type' => $front['mime'] ?? '',
        ];
        // Post-FIELD classification (task #88 / Woo timestamp extension):
        // a field this post_type classifies 'derived' is dropped from this
        // UPDATE entirely rather than overwritten with the captured byte
        // string, once the row already exists. The front-matter-name =>
        // wp_posts-column translation is Policy's (DUO-3318): this loop used
        // to carry its own literal copy of it, so widening the allowlist
        // without widening the copy would have left a field a manifest may
        // legally declare `derived` still overwritten here — the
        // classification honored by capture and the hash basis but silently
        // not by apply. Only fields in that map can be omitted; an
        // undeclared front key remains authored.
        // ensure_post_row() (phase 1, moments ago in this same apply for a
        // brand-new row) already wrote captured derived values as real
        // starting values — there's no rebuild action to conjure them the way
        // _wp_attachment_metadata gets one on create, and WordPress
        // requires SOME value on insert — so this only ever skips touching
        // an ALREADY-populated column, never leaves one null.
        //
        // Argued explicitly in the post-field classification report: the alternative —
        // overwrite it on every apply, same as any authored field — would
        // make a target environment's own, more-progressed self-heal
        // regress to a stale source snapshot on every single apply cycle,
        // only to re-heal itself on the very next ordinary WooCommerce read
        // (an admin view, a Store API request) — a pointless oscillation
        // for a value nothing authored actually controls. Letting the
        // plugin's own derivation stand once the row exists is what
        // 'derived' is supposed to mean; Canon::post_hash_basis() is the
        // other half — it keeps these fields' divergence from ever
        // registering as drift/conflict in the first place, so skipping the
        // write here is consistent with what plan already told the operator
        // would happen.
        foreach (Policy::DERIVABLE_FIELD_COLUMNS as $frontField => $dbColumn) {
            if ($this->policy->field_class($front['type'], $frontField) === 'derived') {
                unset($fields[$dbColumn]);
            }
        }
        Db::update($wpdb->posts, $fields, ['ID' => $id], null, null, 'apply update post');

        // Authored post-meta is reconciled after the post row, as before. The
        // positional shortcode codec uses the frozen canonical meta map, not a
        // live read, so body rewriting remains independent of mutation order.
        $this->fieldMaterializer->reconcile_authored_meta($id, (array) ($front['meta'] ?? []), 'post');

        // term relationships for owned taxonomies
        $this->relationshipMaterializer->reconcile_relationships(
            $id,
            $front['type'],
            (array) ($front['terms'] ?? []),
            (array) ($front['term_orders'] ?? []),
            $taxesForPostType
        );

        // attachment binary + managed meta
        if ($front['type'] === 'attachment') {
            $this->attachmentMaterializer->place_attachment($id, $front);
        }
    }

    public function resolve_login(string $login): ?int {
        global $wpdb;
        if ($login === '') {
            return null;
        }
        if (!isset($this->userIds[$login])) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE user_login = %s LIMIT 1", $login
            ));
            $this->userIds[$login] = $id ? (int) $id : 0;
        }
        return $this->userIds[$login] ?: null;
    }
}
