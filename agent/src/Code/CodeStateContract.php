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

    /**
     * Expose the same lifecycle-only extraction used by validate_tree() to the
     * separate source/plugin compatibility bridge.  No code facts are derived
     * here: callers receive only canonical active_plugins/theme identities and
     * record-presence state, so CodeStateContract remains the narrow
     * code/state lifecycle bridge.
     *
     * @param array<string,mixed> $tree
     * @return array<string,mixed>
     */
    public static function lifecycle_requirements_from_tree(array $tree): array {
        return self::requirements_from_tree($tree);
    }

    /** @param array<string,mixed> $requirements @param array<string,mixed> $descriptor */
    private static function validate_requirements(array $requirements, array $descriptor): void {
        self::assert_explicit_lifecycle_intent($requirements);
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
        if (isset($requirements['stylesheet'], $requirements['template'])) {
            $stylesheet = $requirements['stylesheet'];
            $template = $requirements['template'];
            if (!array_key_exists('theme_templates', $descriptor)) {
                if ($stylesheet !== $template) {
                    throw new \RuntimeException(
                        "duo: code-stage refused — canonical child stylesheet '$stylesheet' requires Template header "
                        . "'$template', but this frozen legacy code descriptor has no theme_templates relation; recompile the artifact"
                    );
                }
                return;
            }
            $declared = $descriptor['theme_templates'][$stylesheet] ?? null;
            $expected = $stylesheet === $template ? null : $template;
            if ($declared !== $expected) {
                $actual = $declared === null
                    ? 'no Template header'
                    : "Template header '$declared'";
                if ($expected === null) {
                    throw new \RuntimeException(
                        "duo: code-stage refused — canonical standalone stylesheet '$stylesheet' requires no Template header, "
                        . "but code/wp-content/themes/$stylesheet/style.css declares $actual"
                    );
                }
                throw new \RuntimeException(
                    "duo: code-stage refused — canonical child stylesheet '$stylesheet' requires Template header "
                    . "'$expected', but code/wp-content/themes/$stylesheet/style.css declares $actual"
                );
            }
        }
    }

    /**
     * A code descriptor has removal authority at finalize.  The compiler
     * cannot inspect a target or its historical descriptors, so it must not
     * treat an absent/deleted lifecycle record as an instruction to leave
     * lifecycle alone: Deploy intentionally ignores those records.  Requiring
     * all three present records makes plugin deactivation and theme switching
     * explicit for every code-enabled transition, including one that removes
     * the last currently described plugin or theme.
     *
     * @param array<string,mixed> $requirements
     */
    private static function assert_explicit_lifecycle_intent(array $requirements): void {
        $states = $requirements['lifecycle_record_states'] ?? [];
        foreach (['active_plugins', 'template', 'stylesheet'] as $name) {
            $state = is_array($states) ? ($states[$name] ?? null) : null;
            if ($state === 'present') {
                continue;
            }
            $actual = $state === null ? 'missing' : "'$state'";
            throw new \RuntimeException(
                "duo: code-stage refused — canonical $name lifecycle record must be present in state/options/core.json; it is $actual. "
                . 'Absent or deleted records do not express WordPress lifecycle intent, so code could be pruned without reconciliation.'
            );
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
        $states = array_fill_keys(['active_plugins', 'template', 'stylesheet'], null);
        $optionsEntity = $tree['options/core']['data'] ?? null;
        if (!is_array($optionsEntity)) {
            return ['lifecycle_record_states' => $states];
        }
        $records = OptionState::records($optionsEntity);
        $requirements = ['lifecycle_record_states' => $states];
        foreach (array_keys($states) as $name) {
            if (array_key_exists($name, $records)) {
                $requirements['lifecycle_record_states'][$name] = $records[$name]['state'];
            }
        }
        if (($requirements['lifecycle_record_states']['active_plugins'] ?? null) === 'present') {
            $activePlugins = $records['active_plugins']['value'];
            if (!is_array($activePlugins) || !array_is_list($activePlugins)) {
                throw new \RuntimeException('duo: code-stage refused — active_plugins must be a list');
            }
            foreach ($activePlugins as $i => $plugin) {
                if (!is_string($plugin) || $plugin === '') {
                    throw new \RuntimeException("duo: code-stage refused — active_plugins[$i] must be a non-empty string");
                }
            }
            $requirements['active_plugins'] = $activePlugins;
        }
        foreach (['template', 'stylesheet'] as $slot) {
            if (($requirements['lifecycle_record_states'][$slot] ?? null) !== 'present') {
                continue;
            }
            $value = $records[$slot]['value'];
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException("duo: code-stage refused — canonical $slot must be a non-empty theme slug");
            }
            $requirements[$slot] = $value;
        }
        return $requirements;
    }
}
