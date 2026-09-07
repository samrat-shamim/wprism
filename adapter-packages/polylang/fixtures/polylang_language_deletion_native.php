<?php
declare(strict_types=1);

// Native fixture mutation only. Full preservation is observed outside WP by
// the unfiltered database dump; this is not another plugin-specific SQL walker.
$mode = $args[0] ?? '';
if (!in_array($mode, ['add', 'delete'], true) || !function_exists('PLL') || POLYLANG_VERSION !== '3.8.6') {
    throw new RuntimeException('Polylang language deletion requires the exact native fixture API');
}
$model = PLL()->model;
$roster = static function () use ($model): array {
    $slugs = $model->get_languages_list(['fields' => 'slug']);
    sort($slugs, SORT_STRING);
    return $slugs;
};
$default = get_option('polylang')['default_lang'] ?? null;
if (!in_array($default, ['en', 'fr', 'ar'], true)) throw new RuntimeException('Polylang deletion default language premise is absent');
if ($mode === 'add') {
    if ($roster() !== ['ar', 'en', 'fr'] || $model->get_language('nl')) {
        throw new RuntimeException('Polylang deletion fixture language is already occupied');
    }
    // Polylang 3.8.6 src/model/languages.php:183-286 exposes no_default_cat.
    // This makes a real, unused native language, not a dangling wp_terms row.
    $language = $model->add_language(['locale' => 'nl_NL', 'name' => 'Nederlands deletion witness',
        'slug' => 'nl', 'rtl' => false, 'flag' => 'nl', 'no_default_cat' => true]);
    if (is_wp_error($language) || !$language instanceof PLL_Language || $roster() !== ['ar', 'en', 'fr', 'nl']) {
        throw new RuntimeException('Polylang native add did not create the unused language');
    }
} else {
    $language = $model->get_language('nl');
    if (!$language instanceof PLL_Language || $roster() !== ['ar', 'en', 'fr', 'nl']) {
        throw new RuntimeException('Polylang native delete lacks its captured language premise');
    }
}
$ids = $language->get_tax_props('term_id');
ksort($ids, SORT_STRING);
if (array_keys($ids) !== ['language', 'term_language']) throw new RuntimeException('Polylang language taxonomy roster changed');
$identities = [];
foreach ($ids as $taxonomy => $id) {
    if (!is_int($id) || $id < 1 || get_objects_in_term($id, $taxonomy) !== []) {
        throw new RuntimeException('Polylang deletion witness is not a positive unused native language');
    }
    $uuid = $mode === 'delete' ? get_term_meta($id, '_wprism_uuid', true) : null;
    if ($mode === 'delete' && (!is_string($uuid) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/', $uuid) !== 1)) {
        throw new RuntimeException('Polylang deletion witness lacks its ordinary Capture identity');
    }
    $identities[$taxonomy] = ['term_id' => $id, 'uuid' => $uuid];
}
if ($mode === 'delete') {
    if ($model->delete_language($ids['language']) !== true || $roster() !== ['ar', 'en', 'fr']) {
        throw new RuntimeException('Polylang native delete did not restore the remaining language roster');
    }
    foreach ($ids as $taxonomy => $id) {
        if (get_term($id, $taxonomy) !== null) throw new RuntimeException('Polylang native delete retained a language term');
    }
}
if ((get_option('polylang')['default_lang'] ?? null) !== $default) throw new RuntimeException('Polylang unused-language control changed the default language');
echo json_encode(['format' => 'polylang-language-deletion-control/v1', 'mode' => $mode,
    'identities' => $identities, 'languages' => $roster(), 'default_lang' => $default], JSON_THROW_ON_ERROR), "\n";
