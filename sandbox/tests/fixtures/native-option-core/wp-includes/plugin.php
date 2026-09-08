<?php
declare(strict_types=1);

/** Same shared store/gate ordering with the native dispatcher source seam. */
if (!function_exists('apply_filters')) {
    function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
        return wprism_fixture_filter_dispatch($name, $value, ...$args);
    }
}

function wprism_fixture_filter_dispatch(string $name, mixed $value, mixed ...$args): mixed {
        $all = $GLOBALS['wp_filter']['all'] ?? null;
        if (is_object($all) && method_exists($all, 'do_all_hook')) {
            $allArgs = array_merge([$name, $value], $args);
            $all->do_all_hook($allArgs);
        }
        $gate = $GLOBALS['wp_filter'][$name] ?? null;
        if (is_object($gate) && method_exists($gate, 'hook_name') && method_exists($gate, 'apply_filters')) {
            return $gate->apply_filters($value, array_merge([$value], $args));
        }
        foreach (wprism_wp_store()->sortedHooks($name) as $entry) {
            $value = ($entry['callback'])(...array_slice(array_merge([$value], $args), 0, max(1, $entry['accepted_args'])));
        }
        return $value;
}
