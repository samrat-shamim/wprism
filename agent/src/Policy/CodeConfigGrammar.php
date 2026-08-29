<?php
namespace WPrism;

/**
 * Pure top-level `site.wprism.json` code declaration grammar (issue #3348 slice
 * 25), extracted from Policy.php. The declaration is optional so the legacy
 * state-only repository shape remains valid; when present, the existing Code
 * contract remains the single owner of its exact format/layout/source bytes.
 *
 * issue #3499 added `format: 2` (the split declaration, whose fourth key names
 * `code/wprism-code.lock.json`). This gate deliberately did not learn a second
 * grammar for it: Code::assert_config() is still the single owner of every
 * legal shape and of the exact refusal bytes for each, so format 1 keeps its
 * historical message verbatim and format 2 gets its own. The one message this
 * file owns — the non-object refusal below — is unchanged for the same reason
 * it was worded that way originally: it fires before any format is known.
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
                "wprism: $label code must be an object with exactly format, layout, and source"
            );
        }
        try {
            Code::assert_config($code);
        } catch (\Throwable $t) {
            throw new \RuntimeException("wprism: $label code declaration is invalid: {$t->getMessage()}", 0, $t);
        }
    }
}
