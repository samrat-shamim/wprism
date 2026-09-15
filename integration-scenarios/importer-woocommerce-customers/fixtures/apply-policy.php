<?php
declare(strict_types=1);

/** The caller supplies WordPress's initialized registries, not all optional adapter declarations. */
function importer_woo_authored_scope(WPrism\Policy $policy, array $postTypes, array $taxonomies, array $base): array {
    $scope = ['post_types' => $base['post_types'], 'taxonomies' => $base['taxonomies']];
    foreach ($policy->declared_post_types() as $name) {
        if (in_array($name, $postTypes, true) && ($policy->post_type_rule_details($name)['rule']['class'] ?? null) === 'authored') {
            $scope['post_types'][] = $name;
        }
    }
    foreach ($policy->declared_taxonomies() as $name) {
        if (in_array($name, $taxonomies, true) && ($policy->taxonomy_rule_details($name)['rule']['class'] ?? null) === 'authored') {
            $scope['taxonomies'][] = $name;
        }
    }
    foreach ($scope as &$names) {
        $names = array_values(array_unique($names));
        sort($names, SORT_STRING);
    }
    unset($names);
    return $scope;
}
