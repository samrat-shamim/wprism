<?php
namespace Duo;

/**
 * Pure top-level `site.duo.json` code declaration grammar (DUO-3348 slice
 * 25), extracted from Policy.php. The declaration is optional so the legacy
 * state-only repository shape remains valid; when present, the existing Code
 * contract remains the single owner of its exact format/layout/source bytes.
 *
 * This file deliberately does not require Code.php. Policy.php had the same
 * implicit Code dependency before this extraction, and Code.php's bootstrap
 * graph includes materialization classes that standalone Policy consumers do
 * not otherwise need. Keeping that load boundary unchanged also preserves
 * offline fixtures that install narrow engine seams before requiring a larger
 * collaborator. Callers that validate an opted-in code declaration must load
 * the normal Code boundary, exactly as they did before this move.
 */
final class CodeConfigGrammar {
    /**
     * Validate the optional code envelope on one decoded site document.
     *
     * @param array<string,mixed> $site
     */
    public static function validate_site_code(array $site, string $label): void {
        if (!array_key_exists('code', $site)) {
            return; // legacy state-only repositories remain fully supported
        }
        $code = $site['code'];
        if (!is_array($code) || array_is_list($code)) {
            throw new \RuntimeException(
                "duo: $label code must be an object with exactly format, layout, and source"
            );
        }
        try {
            Code::assert_config($code);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: $label code declaration is invalid: {$t->getMessage()}", 0, $t);
        }
    }
}
