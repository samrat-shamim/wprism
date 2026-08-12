<?php
namespace Duo;

require_once __DIR__ . '/Canon.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
// Deliberately NOT require_once('CompiledArtifact.php') here (the file that
// declares CompiledRepository): sandbox/tests/regress_code_revision_enforcement.php
// stubs a fake Duo\CompiledRepository and reaches this file transitively
// through Apply.php (a direct require_once, verified) without ever loading
// the real CompiledArtifact.php; requiring it here fatals that suite with
// "Cannot redeclare class Duo\CompiledRepository" (caught by
// regress-offline-all while verifying this file). Checked individually,
// not assumed: no other file in the repo fakes CompiledRepository (grep
// -rlE '^\s*(final\s+)?class\s+CompiledRepository\s*(\{|extends|implements)'
// sandbox/tests/*.php returns exactly this one match), and the other class
// this file references, Canon, is faked by exactly one file
// (regress_cli_json_refusals.php) that does NOT reach Apply.php's require
// chain at all (traced its full require list -- Secrets/CommandRefusal/
// Deletion/RepositoryAuthorization/Code/RepositoryCompiler/Cli.php, none of
// which requires Apply.php), so Canon.php is safely required directly below.

/**
 * The attachment materializer (DUO-3347 slice 9, one of the "Entity
 * materializers: posts, attachments, typed tables" target seams): writes an
 * attachment's binary content to the uploads directory (byte-identical
 * short-circuit if the destination already matches) and its two managed
 * postmeta keys (_wp_attached_file, _wp_attachment_image_alt).
 *
 * Extracted from Apply.php: place_attachment() had exactly one caller
 * (finalize_post(), which stays on Apply for now, called only when
 * $front['type'] === 'attachment'). It was otherwise fully self-contained:
 * its external collaborators are the immutable compiled repository (for
 * media_content(), the repository-authorized attachment bytes) and the
 * shared meta writer -- no Policy, no Tokens, matching
 * RelationshipMaterializer's precedent of a narrower-than-usual contract
 * when the moved code genuinely doesn't need the full (Policy, Tokens,
 * ApplyFieldMaterializer) shape Menu/UserMeta/Term/Options established.
 *
 * Unlike the shared, memoized, per-call state TermMaterializer's
 * $termObjectTaxes and RelationshipMaterializer's $taxesForPostType had to
 * take as explicit method parameters, CompiledRepository is a genuinely
 * immutable value object -- set once in Apply's own constructor and never
 * mutated for the lifetime of one apply run (docs/proposals/fresh-roadmap's
 * own refactoring rule: "prefer immutable inputs/results across seams") --
 * so it is injected once through the constructor here, not threaded through
 * on every call.
 *
 * Moved verbatim; Apply keeps place_attachment() as a thin compatibility
 * facade via a lazily-constructed instance (attachment_materializer()), the
 * same pattern field_materializer()/menu_materializer()/
 * user_meta_materializer()/term_materializer()/options_materializer()/
 * relationship_materializer() already established.
 */
final class AttachmentMaterializer {
    public function __construct(
        private readonly ApplyFieldMaterializer $fieldMaterializer,
        private readonly CompiledRepository $compiled
    ) {
    }

    public function place_attachment(int $id, array $front): void {
        global $wpdb;
        $bytes = $this->compiled->media_content((string) $front['media']);
        $up = wp_upload_dir(null, false);
        $dst = trailingslashit($up['basedir']) . $front['file'];
        if (!is_file($dst) || hash_file('sha256', $dst) !== hash('sha256', $bytes)) {
            Canon::write_file($dst, $bytes);
        }
        $this->fieldMaterializer->upsert_meta($wpdb->postmeta, 'post_id', $id, '_wp_attached_file', $front['file']);
        $this->fieldMaterializer->upsert_meta($wpdb->postmeta, 'post_id', $id, '_wp_attachment_image_alt', (string) ($front['alt'] ?? ''));
    }
}
