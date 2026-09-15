<?php
namespace WPrism;

/**
 * The `derived` post-type body mode (engine feature `derived-post-body/v1`).
 *
 * WHY A MODE AND NOT A FIELD CLASS
 * --------------------------------
 * `post_types.<type>.fields.<field>.class = derived` already exists, but it
 * cannot answer this question: Policy::DERIVABLE_FIELD_COLUMNS admits only
 * title/modified/modified_gmt, and — decisively — a derived FIELD is still
 * recorded verbatim into state/ (Policy.php:3371-3373); Canon strips it from
 * the hash basis and Apply omits its column, but the bytes are on disk. For a
 * body whose problem IS the bytes, that is the wrong instrument.
 *
 * WHAT IT MEANS
 * -------------
 * The owning plugin derives this post type's post_content from state this
 * repository already carries authoritatively. So the body is not canonical
 * state: capture records an empty body, apply writes no post_content to an
 * existing row, and each site keeps whatever its own plugin last derived.
 *
 * The mode does NOT re-derive it after apply. That is target-local derived
 * state, and repairing it is a rebuild action's job (spec/repo-format.md,
 * "Structured rebuild actions and providers") — `derived` implies
 * regenerate-on-apply only where such an action exists. Without one, a row
 * apply creates keeps the '' ensure_post_row() inserted until the plugin next
 * derives it, and a row apply updates keeps its previous derivation.
 *
 * THE CASE IT SHIPS FOR
 * ---------------------
 * Contact Form 7. WPCF7_ContactForm::save() computes
 * `implode("\n", wpcf7_array_flatten($props))` as post_content
 * (includes/contact-form.php:1263), then writes those same properties to
 * `_<prop>` post meta in the same function (:1259-1307). Measured on 6.1.7,
 * the plugin never reads post_content BY NAME: its three occurrences (:1263,
 * :1270, :1277) are all inside save(). It is read implicitly — the admin forms
 * list (admin/includes/class-contact-forms-list-table.php:41) and REST
 * `contact-forms?search=` (includes/rest-api.php:172) pass `s` to find(), and
 * WP_Query searches post_content by default (wp-includes/class-wp-query.php:1465
 * on 7.1). So on a target with no rebuild action, searching forms by body text
 * sees that site's own last derivation; search by title is unaffected. CF7
 * therefore declares one: its contact-form-7-form-content provider re-derives
 * the body on the target after apply, through CF7's own loader and flattener
 * (adapter-packages/contact-form-7/package/runtime/providers/).
 *
 * WHY NOT allow_pii
 * -----------------
 * The finding was the SOURCE site's admin address inside that body. CF7's
 * default sender is `[_site_title] <from_email()>`, and from_email() returns
 * admin_email on localhost or for a same-domain admin
 * (includes/contact-form-template.php:128). The same address therefore already
 * travels in `_mail` meta under this capsule's reviewed `allow_pii` rule for
 * that key; the body was a second, UNDECLARED copy of it. An exception on the
 * body would have reviewed-in bytes that should not exist — the defect is the
 * copy, not a missing exception.
 *
 * WHY NOT A BASE MODE
 * -------------------
 * The base vocabulary is deliberately closed at three (PostTypeGrammar's
 * BODY_MODES comment): a fourth member there would be legal for a
 * `spec_version: 2` manifest, which is exactly what the feature channel exists
 * to avoid. So this arrives the way `json` did — admitted per-manifest by the
 * feature that claims it.
 */
final class DerivedBodyGrammar {
    /** Claims no top-level section: the mode carries no declaration beside it. */
    public const FEATURE = 'derived-post-body/v1';

    public const BODY_MODE = 'derived';

    /** The canonical body a derived post type always has. */
    public const BODY = '';

    /**
     * Refuse the mode declared without its feature.
     *
     * PostTypeGrammar::validate_post_type_contracts() deliberately RECOGNISES a
     * gated value rather than calling it a typo, so that § v3.2/§ v3.3's three
     * verdicts arrive first and stay distinct — a spec_version 2 manifest
     * refused BY SECTION, a v3 one without the feature BY KEY, an engine without
     * the feature BY FEATURE NAME. That deferral only works if something later
     * actually refuses, which for `json` is
     * BodyRefGrammar::assert_body_mode_gate(). This is that half for `derived`;
     * without it a spec_version 2 manifest could spend a v3 body mode, which is
     * exactly what the closed base vocabulary exists to prevent.
     *
     * @param array<string,mixed> $manifest
     */
    public static function assert_body_mode_gate(array $manifest, string $label): void {
        if (self::declares_feature($manifest)) {
            return;
        }
        foreach ((array) ($manifest['post_types'] ?? []) as $postType => $decl) {
            if (!is_array($decl) || ($decl['body'] ?? null) !== self::BODY_MODE) {
                continue;
            }
            throw new \RuntimeException(
                "wprism: $label declares post_types." . (string) $postType . ".body='" . self::BODY_MODE
                . "', which the engine feature '" . self::FEATURE . "' gates — declare it in this manifest's "
                . 'top-level "engine_features" list (which itself requires spec_version 3, spec/repo-format.md '
                . '§ v3.2). An engine that does not implement the feature refuses the adapter by feature name '
                . 'instead of mis-reading the declaration, which is what lets this body mode ship with no '
                . 'version bump'
            );
        }
    }

    /** @param array<string,mixed> $manifest */
    public static function declares_feature(array $manifest, string $name = self::FEATURE): bool {
        foreach ((array) ($manifest['engine_features'] ?? []) as $feature) {
            if ($feature === $name) {
                return true;
            }
        }

        return false;
    }
}
