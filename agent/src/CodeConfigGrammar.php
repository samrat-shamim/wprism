<?php
namespace Duo;

require_once __DIR__ . '/CodeDescriptorCompiler.php';

/**
 * Pure top-level `site.duo.json` code declaration grammar (DUO-3348 slice
 * 25), extracted from Policy.php. The declaration is optional so the legacy
 * state-only repository shape remains valid; when present, the pure descriptor
 * compiler remains the single owner of its exact format/layout/source bytes.
 *
 * This file deliberately does not require Code.php. Repository and policy
 * validation depend only on the pure descriptor contract; target staging and
 * materialization remain behind Code's mutation facade.
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
            CodeDescriptorCompiler::assert_config($code);
        } catch (\Throwable $t) {
            throw new \RuntimeException("duo: $label code declaration is invalid: {$t->getMessage()}", 0, $t);
        }
    }
}
