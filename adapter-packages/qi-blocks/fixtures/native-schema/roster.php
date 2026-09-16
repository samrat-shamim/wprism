<?php
declare(strict_types=1);

/** Retained schema names are independent of the effective manifest projection. */
final class QiNativeSchemaRoster {
    public static function expand(array $groups): array {
        $out = [];
        foreach ($groups as $group) {
            $names = $group['attributes'] ?? [];
            foreach ($group['attribute_bases'] ?? [] as $base) {
                foreach ($group['attribute_suffixes'] as $suffix) $names[] = $base . $suffix;
            }
            foreach ($group['blocks'] as $block) foreach ($names as $attribute) {
                if (isset($out[$block][$attribute])) throw new RuntimeException('duplicate retained Qi schema name');
                $out[$block][$attribute] = $group['value']['native_type'];
            }
        }
        ksort($out, SORT_STRING);
        foreach ($out as &$attributes) ksort($attributes, SORT_STRING);
        unset($attributes);
        return $out;
    }
}
