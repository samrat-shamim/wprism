<?php
namespace Duo\Providers;

use Duo\Policy;

/**
 * Polylang nav-menu-location synchronization provider.
 *
 * DUO-3272's finding, recorded in full in manifests/polylang.json's own notes:
 * a target that has correctly received Polylang's `nav_menus`/`default_lang`
 * bookkeeping never writes the raw `theme_mods_<stylesheet>` slot
 * `nav_menu_locations` on its own. Polylang's only unconditional write path
 * (`Languages::update_default()`) returns early when the default language is
 * already correct — which it is from the first apply — and every other path
 * is a nonce-gated wp-admin form POST, structurally unreachable from
 * automation. So the slot stays empty forever on an all-automation target.
 *
 * This provider is the DUO-3338 port of the `wp eval` one-liner that closed
 * that gap. The computation is Polylang's own, ported unchanged: for the
 * active theme, map each menu location to the menu id registered for the
 * default language, or 0 when that language has no menu there. What is new is
 * the strict readback proving the theme mod holds exactly the computed map.
 */
final class PolylangNavMenus {
    private Policy $policy;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'polylang-nav-menus',
            'plugin' => 'polylang/polylang.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * 30 seconds is deliberately tight: this is two option reads, an
     * in-memory map, and one theme-mod write. Nothing about it scales with
     * site size, so anything slower is a symptom, not a large site.
     */
    public function capabilities(): array {
        return [
            'sync_nav_menu_locations' => [
                'args' => [],
                'reads' => ['option:polylang', 'option:stylesheet'],
                'writes' => ['entity:theme-mods-nav-menu-locations'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        return match ($capability) {
            'sync_nav_menu_locations' => $this->sync_nav_menu_locations(),
            default => throw new \RuntimeException(
                "duo: Polylang nav-menu provider does not implement capability '$capability'"
            ),
        };
    }

    /**
     * Recompute and write the active theme's nav_menu_locations map.
     *
     * The no-op branch is ported behavior, not a swallowed failure: the eval
     * payload guarded on `!empty($o["nav_menus"][$t])` and silently did
     * nothing otherwise, because a site whose active theme has no Polylang
     * nav-menu bookkeeping has nothing to synchronize and must not have its
     * theme mod overwritten with an empty map. The receipt records that
     * outcome explicitly rather than letting it look like a write.
     *
     * @return array{before:array, after:array, verified:true}
     */
    private function sync_nav_menu_locations(): array {
        foreach (['get_option', 'get_theme_mod', 'set_theme_mod'] as $required) {
            if (!function_exists($required)) {
                throw new \RuntimeException(
                    "duo: Polylang nav-menu synchronization requires WordPress's $required()"
                );
            }
        }
        $polylang = get_option('polylang');
        $stylesheet = (string) get_option('stylesheet');
        $before = [
            'stylesheet' => $stylesheet,
            'nav_menu_locations' => $this->observe_locations(),
        ];

        if (!is_array($polylang) || empty($polylang['nav_menus'][$stylesheet])) {
            return [
                'before' => $before,
                'after' => $before + ['outcome' => 'no-op (no nav_menus for active theme)'],
                'verified' => true,
            ];
        }

        $defaultLang = $polylang['default_lang'] ?? null;
        $map = [];
        foreach ((array) $polylang['nav_menus'][$stylesheet] as $location => $byLanguage) {
            $map[$location] = is_array($byLanguage) && isset($byLanguage[$defaultLang])
                ? $byLanguage[$defaultLang]
                : 0;
        }
        set_theme_mod('nav_menu_locations', $map);

        // Per-entry comparison against a fresh read, deliberately NOT a
        // whole-map identity check: Polylang itself filters reads of this
        // theme mod and augments the stored value with derived entries —
        // observed live on a two-language pair, where a stored
        // {"primary": 9} read back as {"primary": 9, "footer": 0,
        // "primary___de": 10} (the language-virtual location plus the
        // theme's other registered slots). Those additions are the plugin's
        // own read semantics, not a failed write. What a failed write (or a
        // clobbering filter) cannot fake is each COMPUTED location reading
        // back with its computed menu id, so that is the value-level claim
        // this receipt makes.
        $written = $this->observe_locations();
        foreach ($map as $location => $menuId) {
            if (!array_key_exists($location, $written) || $written[$location] !== $menuId) {
                throw new \RuntimeException(
                    'duo: Polylang nav_menu_locations readback does not hold the computed value for '
                    . "location '$location' on theme '$stylesheet' (wrote "
                    . json_encode($map, JSON_UNESCAPED_SLASHES)
                    . ', read back ' . json_encode($written, JSON_UNESCAPED_SLASHES) . ')'
                );
            }
        }

        return [
            'before' => $before,
            'after' => [
                'stylesheet' => $stylesheet,
                'nav_menu_locations' => $written,
                'outcome' => 'synchronized',
            ],
            'verified' => true,
        ];
    }

    /**
     * The current raw slot. get_theme_mod() answers false when the mod is
     * unset; normalizing that to an empty array keeps the before-image and
     * the strict post-write comparison in one shape.
     *
     * @return array<string,mixed>
     */
    private function observe_locations(): array {
        $locations = get_theme_mod('nav_menu_locations');
        return is_array($locations) ? $locations : [];
    }
}
