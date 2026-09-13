<?php
declare(strict_types=1);

// REST replay of retained UI bodies is API-authored evidence. Distinct dates
// and reversed target creation order keep SQL tie ordering out of the oracle.
$phase = $args[0] ?? '';
$check = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException('Visual Portfolio query evidence: ' . $reason);
};
$check(current_user_can('manage_options') && defined('VISUAL_PORTFOLIO_VERSION') && VISUAL_PORTFOLIO_VERSION === '3.8.1', 'exact native subject');
$check(get_stylesheet() === 'twentytwentyone' && wp_get_theme()->get('Version') === '2.9', 'exact pair theme');
$subjects = [6 => ['portfolio', 'Harbor Light'], 7 => ['portfolio', 'Paper Garden'],
    8 => ['portfolio', 'Quiet Shapes'], 9 => ['portfolio', 'Open Horizon'], 12 => ['post', 'Ocean Letter'],
    13 => ['post', 'Garden Letter'], 14 => ['post', 'Twin Letter'], 15 => ['post', 'Twin Letter']];
$cases = ['default', 'manual', 'post-types', 'filters', 'reset', 'duplicates', 'taxonomy-exclusion'];
global $wpdb;
$post = static function (string $slug) use ($wpdb, $check): int {
    $wpdb->last_error = '';
    $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_status = 'publish'", $slug));
    $check($wpdb->last_error === '' && count($ids) === 1, 'one native post for ' . $slug);
    return (int) $ids[0];
};
$save = static function (string $case, int $page) use ($subjects, $post, $check): string {
    $fixture = $case === 'taxonomy-exclusion' ? 'post-types' : $case;
    $blocks = parse_blocks((string) file_get_contents(__DIR__ . '/' . $fixture . '.html'));
    if (isset($blocks[0]['attrs']['postsQuery'])) {
        $query =& $blocks[0]['attrs']['postsQuery'];
        foreach (['ids', 'excludeIds'] as $field) foreach ($query[$field] as &$id) {
            $check(isset($subjects[(int) $id]), 'known retained query identity');
            $id = (string) $post('vp-subject-' . $id);
        }
        unset($id);
        if ($query['customQuery'] !== '') $query['customQuery'] = 'post_type=post&p=' . $post('vp-subject-12');
        if ($case === 'taxonomy-exclusion') {
            $term = get_term_by('slug', 'vp-garden', 'category');
            $check($term instanceof WP_Term, 'native category identity');
            $query['taxonomies'] = [(string) $term->term_id];
            $query['excludeIds'] = [(string) $post('vp-subject-15')];
        }
        unset($query);
    }
    $body = serialize_blocks($blocks);
    $request = new WP_REST_Request('POST', '/wp/v2/pages/' . $page);
    $request->set_param('content', $body);
    $response = rest_do_request($request);
    $check($response->get_status() === 200 && get_post_field('post_content', $page, 'raw') === $body, 'complete REST writer readback');
    return $body;
};
if ($phase === 'seed-source' || $phase === 'seed-target') {
    $source = $phase === 'seed-source';
    if (!$source) for ($i = 0; $i < 32; $i++) {
        $check(is_int(wp_insert_post(['post_status' => 'trash', 'post_title' => 'Target padding ' . $i], true)), 'target ID padding');
        $padding = wp_insert_term('Target padding ' . $i, 'category');
        $check(!is_wp_error($padding) && wp_delete_term($padding['term_id'], 'category') === true, 'target term ID padding');
    }
    $term = wp_insert_term('Garden studies', 'category', ['slug' => 'vp-garden']);
    $check(!is_wp_error($term), 'native category creation');
    $ids = [];
    foreach ($source ? array_keys($subjects) : array_reverse(array_keys($subjects)) as $oldId) {
        [$type, $title] = $subjects[$oldId];
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish', 'post_name' => 'vp-subject-' . $oldId,
            'post_title' => $title, 'post_content' => $oldId === 15 ? '<p>Garden story.</p>' : '<p>Native story.</p>',
            'post_date' => sprintf('2026-08-%02d 12:00:00', $oldId), 'post_date_gmt' => sprintf('2026-08-%02d 12:00:00', $oldId)], true);
        $check(is_int($id) && $id > 0, 'native subject Save');
        if (in_array($oldId, [13, 15], true)) $check(!is_wp_error(wp_set_object_terms($id, [(int) $term['term_id']], 'category')), 'native category assignment');
        $ids[$oldId] = $id;
    }
    $bodies = [];
    foreach ($cases as $case) {
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'vp-query-' . $case,
            'post_title' => 'Query ' . $case, 'post_content' => '<p>Target has no query yet.</p>', 'post_date' => '2026-08-20 12:00:00'], true);
        $check(is_int($id) && $id > 0, 'native query page');
        $bodies[$case] = $source ? $save($case, $id) : get_post_field('post_content', $id, 'raw');
        if (!$source) update_post_meta($id, '_vp_views_count', '42');
    }
    if ($source) WPrism\Canon::write_file('/siterepo/site.wprism.json', WPrism\Canon::encode([
        'manifests' => ['core', 'visual-portfolio'], 'spec_version' => WPRISM_SPEC_VERSION,
        'policy' => ['post_types' => ['post', 'page', 'attachment', 'portfolio'],
            'taxonomies' => ['category', 'post_tag', 'portfolio_category', 'portfolio_tag']],
    ]));
    echo json_encode(['phase' => $phase, 'ids' => $ids, 'bodies' => $bodies], JSON_THROW_ON_ERROR), "\n";
    return;
}
if (in_array($phase, ['custom', 'hidden-custom', 'missing'], true)) {
    $body = $save($phase, $post('vp-query-manual'));
    if ($phase === 'missing') $check(wp_delete_post($post('vp-subject-15'), true) instanceof WP_Post, 'native deletion leaves its saved selector unresolved');
    echo json_encode(['phase' => $phase, 'body' => $body], JSON_THROW_ON_ERROR), "\n";
    return;
}
$check($phase === 'observe', 'known observation phase');
$orders = ['posts' => 'ID', 'postmeta' => 'meta_id', 'options' => 'option_id', 'terms' => 'term_id',
    'term_taxonomy' => 'term_taxonomy_id', 'term_relationships' => 'object_id,term_taxonomy_id',
    'termmeta' => 'meta_id', 'users' => 'ID', 'usermeta' => 'umeta_id'];
$rows = static function (string $table, string $order) use ($wpdb, $check): array {
    $wpdb->last_error = '';
    $data = $wpdb->get_results("SELECT * FROM $table ORDER BY $order LIMIT 4097", ARRAY_A);
    $check($wpdb->last_error === '' && is_array($data) && count($data) <= 4096
        && strlen(json_encode($data, JSON_THROW_ON_ERROR)) <= 8388608, 'complete bounded native table');
    return $data;
};
$tables = [];
foreach ($orders as $name => $order) $tables[$name] = $rows($wpdb->$name, $order);
$map = $rows($wpdb->prefix . 'wprism_map', 'uuid,id_kind');
$pages = [];
foreach ($cases as $case) {
    $id = $post('vp-query-' . $case);
    $body = get_post_field('post_content', $id, 'raw');
    $pages[$case] = ['id' => $id, 'body' => $body, 'query' => parse_blocks($body)[0]['attrs']['postsQuery'] ?? null];
}
foreach ($orders as $name => $order) $check($tables[$name] === $rows($wpdb->$name, $order), 'observer preserves complete native tables');
$check($map === $rows($wpdb->prefix . 'wprism_map', 'uuid,id_kind'), 'observer preserves the complete identity map');
echo json_encode(['format' => 'wprism-vp-native-queries/v1', 'wordpress' => get_bloginfo('version'), 'plugin' => VISUAL_PORTFOLIO_VERSION,
    'theme' => ['stylesheet' => get_stylesheet(), 'version' => wp_get_theme()->get('Version')],
    'php' => PHP_VERSION, 'database' => $wpdb->db_server_info(), 'home' => home_url(), 'pages' => $pages,
    'tables' => $tables, 'map' => $map], JSON_THROW_ON_ERROR), "\n";
