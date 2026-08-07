<?php
namespace Duo;

/**
 * Narrow code/state bridge.
 *
 * The code compiler/materializer must not understand canonical state or fold
 * the state revision into the code revision.  Lifecycle requirements are
 * derived here from the already-compiled state artifact and compared with an
 * opaque code descriptor.  Keeping this bridge separate makes the halves
 * independently testable and leaves Code.php responsible only for code
 * inventory, target files, ledger markers, and the promotion lease.
 */
final class CodeStateContract {
    /**
     * Validate the canonical lifecycle identities that the requested code
     * payload must satisfy before any target file is changed.
     *
     * @param array<string,mixed> $descriptor
     */
    public static function validate(CompiledRepository $compiled, array $descriptor): void {
        self::validate_requirements(self::requirements($compiled), $descriptor);
    }

    /**
     * Compiler-facing form of the same gate.  It accepts the just-built
     * canonical tree so an invalid cross-half artifact never reaches a
     * checkpoint or target contact; stage keeps the CompiledRepository form
     * as its TOCTOU revalidation.
     *
     * @param array<string,mixed> $tree
     * @param array<string,mixed> $descriptor
     */
    public static function validate_tree(array $tree, array $descriptor): void {
        self::validate_requirements(self::requirements_from_tree($tree), $descriptor);
    }

    /** @param array<string,mixed> $requirements @param array<string,mixed> $descriptor */
    private static function validate_requirements(array $requirements, array $descriptor): void {
        $availablePlugins = [];
        foreach ($descriptor['plugin_main_files'] ?? [] as $row) {
            if (is_array($row) && is_string($row['basename'] ?? null)) {
                $availablePlugins[$row['basename']] = true;
            }
        }
        foreach ($requirements['active_plugins'] ?? [] as $plugin) {
            if (!isset($availablePlugins[$plugin])) {
                throw new \RuntimeException(
                    "duo: code-stage refused — canonical active plugin '$plugin' has no matching plugin main file in code/wp-content/plugins"
                );
            }
        }
        $themes = array_fill_keys(
            array_values(array_filter($descriptor['theme_slugs'] ?? [], 'is_string')),
            true
        );
        foreach (['template', 'stylesheet'] as $slot) {
            if (array_key_exists($slot, $requirements) && !isset($themes[$requirements[$slot]])) {
                throw new \RuntimeException(
                    "duo: code-stage refused — canonical $slot '{$requirements[$slot]}' has no matching theme directory in code/wp-content/themes"
                );
            }
        }
    }

    /**
     * Return only the lifecycle identities relevant to code preflight. This
     * is intentionally not a revision input and is not persisted in the code
     * descriptor.
     *
     * @return array<string,mixed>
     */
    public static function requirements(CompiledRepository $compiled): array {
        return self::requirements_from_tree($compiled->tree());
    }

    /** @param array<string,mixed> $tree @return array<string,mixed> */
    private static function requirements_from_tree(array $tree): array {
        $optionsEntity = $tree['options/core']['data'] ?? null;
        if (!is_array($optionsEntity)) {
            return [];
        }
        $values = OptionState::values($optionsEntity);
        $requirements = [];
        if (array_key_exists('active_plugins', $values)) {
            if (!is_array($values['active_plugins']) || !array_is_list($values['active_plugins'])) {
                throw new \RuntimeException('duo: code-stage refused — active_plugins must be a list');
            }
            foreach ($values['active_plugins'] as $i => $plugin) {
                if (!is_string($plugin) || $plugin === '') {
                    throw new \RuntimeException("duo: code-stage refused — active_plugins[$i] must be a non-empty string");
                }
            }
            $requirements['active_plugins'] = $values['active_plugins'];
        }
        foreach (['template', 'stylesheet'] as $slot) {
            if (!array_key_exists($slot, $values)) {
                continue;
            }
            if (!is_string($values[$slot]) || $values[$slot] === '') {
                throw new \RuntimeException("duo: code-stage refused — canonical $slot must be a non-empty theme slug");
            }
            $requirements[$slot] = $values[$slot];
        }
        return $requirements;
    }
}
