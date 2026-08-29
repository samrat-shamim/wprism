<?php
namespace WPrism;

// Policy remains the standalone entry point for the pure site-policy grammar
// collaborators used below. Requiring it here preserves the existing direct
// load contract while the circular require_once is safe for the same reason
// as ManifestValidator's policy cycle.
require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/CodeConfigGrammar.php';
require_once __DIR__ . '/ScopeGrammar.php';
require_once __DIR__ . '/../Grammar/OptionGrammar.php';
require_once __DIR__ . '/../Grammar/UserMetaGrammar.php';
require_once __DIR__ . '/ManifestGrammar.php';
require_once __DIR__ . '/../Grammar/SubKeyGrammar.php';
require_once __DIR__ . '/../Kernel/ReferenceShapeGrammar.php';
// WP-2.8: the optional recorded per-release probe evidence the graduated
// outside_version_range verdict reads. Appended to the sequence rather than
// inserted into it, so every refusal that existed before this key still fires
// in the order it always did.
require_once __DIR__ . '/VersionEvidenceGrammar.php';
// WP-5.5: the operator's resolution of a plugin/theme claim two pinned
// manifests both make. Appended for the same reason VersionEvidenceGrammar
// was — every refusal that fired before this key still fires in the order it
// always did.
require_once __DIR__ . '/AdapterClaimResolutions.php';

/**
 * Pure validation of the repository-owned site.wprism.json policy envelope.
 *
 * Policy::load() and Policy::from_snapshot() must validate the same ten
 * site-level declarations in the same order, differing only in the label
 * placed into refusal messages. Keeping that sequence on Policy made the two
 * entry points easy to drift while the per-manifest sequence already had a
 * dedicated ManifestValidator. This collaborator owns only the shared site
 * grammar composition; loading, pin resolution, and runtime policy queries
 * remain on Policy (issue #3348 slice 29).
 */
final class SitePolicyValidator {
    /**
     * Validate one decoded site policy before any manifest or runtime query is
     * accepted.
     *
     * @param array<string,mixed> $site
     * @param list<string> $classes
     * @param list<string> $missingUserModes
     */
    public static function validate(
        array $site,
        string $label,
        array $classes,
        array $missingUserModes
    ): void {
        CodeConfigGrammar::validate_site_code($site, $label);
        ScopeGrammar::validate_scope_classes($site, $label, true);
        // Keep the previous expressions verbatim: a scalar policy reaches the
        // typed grammar method and raises the same TypeError rather than being
        // silently normalized into an empty policy.
        OptionGrammar::validate_option_storage($site['policy'] ?? [], $label);
        OptionGrammar::validate_env_options($site['policy'] ?? [], $label);
        UserMetaGrammar::validate_user_meta_rules(
            $site['policy'] ?? [],
            $label,
            $classes,
            $missingUserModes
        );
        // The third argument is the SITE marker (WP-6.2). An engine-feature
        // declaration is a MANIFEST section, so a repository's `policy` object
        // may not carry one, and its table overrides are held to the ungated
        // invalidate vocabulary. This file passes the flag and reads no feature
        // list of its own — ManifestGrammar's gate is the only consumer. Same
        // shape as validate_scope_classes()'s own site flag three lines up.
        ManifestGrammar::validate_tables($site['policy'] ?? [], $label, true);
        SubKeyGrammar::validate_sub_keys($site['policy'] ?? [], $label);
        ReferenceShapeGrammar::validate_reference_shapes($site['policy'] ?? [], $label);
        // WP-5.5. SHAPE only: whether a resolution decides anything is a fact
        // about the file AND the pin set, so it is asserted where the pin set
        // exists (PolicyLoadFinalizer), not here.
        AdapterClaimResolutions::validate($site['policy'] ?? [], $label);
        // Top-level, not under `policy`: this declares nothing about which
        // WordPress state WPrism owns — it records probe outcomes about upstream
        // releases, which is why it is a sibling of `manifests`/`code` rather
        // than a classification rule.
        VersionEvidenceGrammar::validate_site_version_evidence($site, $label);
    }
}
