<?php
namespace Duo;

// Policy remains the standalone entry point for the pure site-policy grammar
// collaborators used below. Requiring it here preserves the existing direct
// load contract while the circular require_once is safe for the same reason
// as ManifestValidator's policy cycle.
require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/CodeConfigGrammar.php';
require_once __DIR__ . '/ScopeGrammar.php';
require_once __DIR__ . '/OptionGrammar.php';
require_once __DIR__ . '/UserMetaGrammar.php';
require_once __DIR__ . '/ManifestGrammar.php';
require_once __DIR__ . '/SubKeyGrammar.php';
require_once __DIR__ . '/ReferenceShapeGrammar.php';

/**
 * Pure validation of the repository-owned site.duo.json policy envelope.
 *
 * Policy::load() and Policy::from_snapshot() must validate the same eight
 * site-level declarations in the same order, differing only in the label
 * placed into refusal messages. Keeping that sequence on Policy made the two
 * entry points easy to drift while the per-manifest sequence already had a
 * dedicated ManifestValidator. This collaborator owns only the shared site
 * grammar composition; loading, pin resolution, and runtime policy queries
 * remain on Policy (DUO-3348 slice 29).
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
        ManifestGrammar::validate_tables($site['policy'] ?? [], $label);
        SubKeyGrammar::validate_sub_keys($site['policy'] ?? [], $label);
        ReferenceShapeGrammar::validate_reference_shapes($site['policy'] ?? [], $label);
    }
}
