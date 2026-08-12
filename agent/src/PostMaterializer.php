<?php
namespace Duo;

require_once __DIR__ . '/Tokens.php';
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

/**
 * The post materializer (DUO-3347 slice 10, one of the "Entity
 * materializers: posts, typed tables" target seams): creates a new post's
 * ledger-mapped wp_posts row with its captured starting field values and
 * identity postmeta, the phase-1 half of post materialization.
 *
 * Extracted from Apply.php: ensure_post_row() had exactly one caller
 * (run()'s own phase-1 loop, not extracted this slice -- run() is Apply's
 * central ~1100-line orchestrator, out of scope for a bounded slice) and a
 * reflection-based test caller (sandbox/tests/regress_post_field_
 * classification.php), fixed in that file this slice. It was otherwise
 * fully self-contained: its only external collaborator is Tokens (home())
 * plus the static Ledger/Db facades and $wpdb -- no Policy, matching
 * AttachmentMaterializer's precedent of a narrower-than-usual contract
 * when the moved code genuinely doesn't need it.
 *
 * finalize_post() (phase 2 -- the field UPDATE, authored-meta/relationship/
 * attachment reconciliation) deliberately stays on Apply this slice: unlike
 * ensure_post_row(), it also calls resolve_login()/reads $defaultAuthor and
 * appends to $warnings, and resolve_login() itself has a second caller
 * inside run() (seeding $defaultAuthor) -- untangling that shared state
 * cleanly is a separate, later slice's work, matching this series'
 * "extract one collaborator at a time" guardrail rather than widening this
 * one. This class is named PostMaterializer (not EnsurePostRowMaterializer)
 * so finalize_post() has a natural home to join later without a rename.
 *
 * Moved verbatim; Apply keeps ensure_post_row() as a thin compatibility
 * facade via a lazily-constructed instance (post_materializer()), the same
 * pattern field_materializer()/menu_materializer()/user_meta_materializer()/
 * term_materializer()/options_materializer()/relationship_materializer()/
 * attachment_materializer() already established.
 */
final class PostMaterializer {
    public function __construct(private readonly Tokens $tokens) {
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
            // conjure one; Apply::finalize_post() (unmoved this slice) is
            // where derived fields stop being overwritten, once the row
            // actually exists.
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
}
