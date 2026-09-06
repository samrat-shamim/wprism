#!/usr/bin/env bash
# The matrix calls this observer after its conformance wrapper has returned.
# Bind repository and transport at each call: leaked wp_conf functions and
# dynamic CONF_REPO locals are not a persistent site identity.

observe_rank_math() { # <label> <host repository> <WP command>
  [ "$#" -eq 3 ] || fail 'Rank Math observation requires an explicit repository and WP command'
  local label="$1" repo="$2" wp_command="$3" file out
  [ -d "$repo" ] || fail "$label Rank Math observation repository is unavailable"
  command -v "$wp_command" >/dev/null \
    || fail "$label Rank Math observation WP command is unavailable"
  file="$repo/.tmp-rank-math-observe.php"
  if ! cat > "$file" <<'PHPEOF'
<?php
global $wpdb;
$read = static function (callable $query, string $label) use ($wpdb) {
    // wpdb reports a failed SELECT as null/[] and the next successful query
    // clears last_error. Check each result at its own boundary and keep the
    // driver payload out of the public conformance diagnostic.
    $suppressed = $wpdb->suppress_errors(true);
    $wpdb->last_error = '';
    try {
        $result = $query();
        if ((string) $wpdb->last_error !== '') {
            throw new RuntimeException('incomplete read');
        }
        return $result;
    } catch (Throwable) {
        throw new RuntimeException('Rank Math conformance observation could not read ' . $label);
    } finally {
        $wpdb->suppress_errors($suppressed);
    }
};
$readRows = static function (string $sql, string $label, array $columns, bool $required = true) use ($wpdb, $read): array {
    $rows = $read(static fn() => $wpdb->get_results($sql, ARRAY_A), $label);
    if (!is_array($rows) || !array_is_list($rows) || ($required && $rows === [])) {
        throw new RuntimeException('Rank Math conformance observation has an invalid row set for ' . $label);
    }
    foreach ($rows as $row) {
        if (!is_array($row) || array_keys($row) !== $columns) {
            throw new RuntimeException('Rank Math conformance observation has invalid columns for ' . $label);
        }
        foreach ($row as $key => $value) {
            if (!is_string($key) || $key === '' || ($value !== null && !is_string($value))) {
                throw new RuntimeException('Rank Math conformance observation has an invalid value for ' . $label);
            }
        }
    }
    return $rows;
};
$readRow = static function (string $sql, string $label, array $columns) use ($wpdb, $read): array {
    $row = $read(static fn() => $wpdb->get_row($sql, ARRAY_A), $label);
    if (!is_array($row) || array_keys($row) !== $columns) {
        throw new RuntimeException('Rank Math conformance observation has an invalid row for ' . $label);
    }
    foreach ($row as $key => $value) {
        if (!is_string($key) || $key === '' || ($value !== null && !is_string($value))) {
            throw new RuntimeException('Rank Math conformance observation has an invalid value for ' . $label);
        }
    }
    return $row;
};
$readList = static function (callable $query, string $label) use ($read): array {
    $values = $read($query, $label);
    if (!is_array($values) || !array_is_list($values) || $values === []
        || array_filter($values, static fn($value): bool => !is_string($value)) !== []) {
        throw new RuntimeException('Rank Math conformance observation has an invalid list for ' . $label);
    }
    return $values;
};
$readCount = static function (string $sql, string $label) use ($wpdb, $read): int {
    $value = $read(static fn() => $wpdb->get_var($sql), $label);
    if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
        throw new RuntimeException('Rank Math conformance observation has an invalid count for ' . $label);
    }
    $count = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (!is_int($count)) {
        throw new RuntimeException('Rank Math conformance observation count exceeds the PHP integer domain for ' . $label);
    }
    return $count;
};
$post = get_page_by_path('rank-math-article', OBJECT, 'post');
$hub = get_page_by_path('rank-math-hub', OBJECT, 'page');
$category = get_term_by('slug', 'rank-math-primary', 'category');
$secondary = get_term_by('slug', 'rank-math-secondary', 'category');
$tag = get_term_by('slug', 'rank-math-portable', 'post_tag');
$attachment = get_page_by_path('wprism-rank-math-social', OBJECT, 'attachment');
if (!$post instanceof WP_Post || !$hub instanceof WP_Post || !$attachment instanceof WP_Post
    || !$category instanceof WP_Term || !$secondary instanceof WP_Term || !$tag instanceof WP_Term) {
    throw new RuntimeException('Rank Math native content fixture is incomplete');
}

$redirections = $readRows(
    "SELECT id,sources,url_to,header_code,hits,status,created,updated,last_accessed "
        . "FROM {$wpdb->prefix}rank_math_redirections ORDER BY id",
    'redirections',
    ['id', 'sources', 'url_to', 'header_code', 'hits', 'status', 'created', 'updated', 'last_accessed']
);
$redirection = null;
foreach ((array) $redirections as $candidate) {
    $sources = maybe_unserialize($candidate['sources'] ?? '');
    if (is_array($sources) && ($sources[0]['pattern'] ?? null) === 'rank-math-old') {
        $candidate['sources_shape'] = $sources;
        $redirection = $candidate;
        break;
    }
}
$links = $readRows($wpdb->prepare(
    "SELECT url,post_id,target_post_id,type FROM {$wpdb->prefix}rank_math_internal_links "
        . "WHERE post_id=%d ORDER BY type,url,target_post_id",
    $post->ID
), 'post links', ['url', 'post_id', 'target_post_id', 'type']);
$postCounts = $readRow($wpdb->prepare(
    "SELECT internal_link_count,external_link_count,incoming_link_count "
        . "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $post->ID
), 'post link counts', ['internal_link_count', 'external_link_count', 'incoming_link_count']);
$hubCounts = $readRow($wpdb->prepare(
    "SELECT internal_link_count,external_link_count,incoming_link_count "
        . "FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id=%d",
    $hub->ID
), 'hub link counts', ['internal_link_count', 'external_link_count', 'incoming_link_count']);
$schema = [];
foreach (['rank_math_internal_links', 'rank_math_internal_meta', 'rank_math_redirections', 'rank_math_redirections_cache'] as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $found = $read(
        static fn() => $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))),
        'table presence ' . $suffix
    );
    if (!is_string($found) || !hash_equals($table, $found)) {
        throw new RuntimeException('Rank Math conformance observation requires the exact table ' . $suffix);
    }
    $schema[$suffix] = [
        'present' => true,
        'columns' => $readList(static fn() => $wpdb->get_col("SHOW COLUMNS FROM `$table`"), 'columns ' . $suffix),
    ];
}
$titles = (array) get_option('rank-math-options-titles', []);
$general = (array) get_option('rank-math-options-general', []);
$instant = (array) get_option('rank-math-options-instant-indexing', []);
$sitemap = (array) get_option('rank-math-options-sitemap', []);
echo wp_json_encode([
    'version' => defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null,
    'ids' => [
        'attachment' => $attachment->ID,
        'category' => $category->term_id,
        'hub' => $hub->ID,
        'post' => $post->ID,
        'secondary' => $secondary->term_id,
        'tag' => $tag->term_id,
    ],
    'modules' => array_values((array) get_option('rank_math_modules', [])),
    'setup' => [
        'configured' => get_option('rank_math_is_configured', null),
        'registration_skip' => get_option('rank_math_registration_skip', null),
    ],
    'options' => [
        'breadcrumbs_home_label' => $general['breadcrumbs_home_label'] ?? null,
        'plain_large_bytes' => strlen((string) ($general['wprism_plain_data']['large'] ?? '')),
        'plain_nested' => $general['wprism_plain_data']['nested'] ?? null,
        'homepage_image_id' => (int) ($titles['homepage_facebook_image_id'] ?? 0),
        'local_seo_about_page' => (int) ($titles['local_seo_about_page'] ?? 0),
        'local_seo_contact_page' => (int) ($titles['local_seo_contact_page'] ?? 0),
        'logo_id' => (int) ($titles['knowledgegraph_logo_id'] ?? 0),
        'open_graph_image_id' => (int) ($titles['open_graph_image_id'] ?? 0),
    ],
    'post' => [
        'title' => get_post_meta($post->ID, 'rank_math_title', true),
        'description' => get_post_meta($post->ID, 'rank_math_description', true),
        'canonical' => get_post_meta($post->ID, 'rank_math_canonical_url', true),
        'facebook_image_id' => (int) get_post_meta($post->ID, 'rank_math_facebook_image_id', true),
        'primary_category' => (int) get_post_meta($post->ID, 'rank_math_primary_category', true),
        'processed' => (bool) get_post_meta($post->ID, 'rank_math_internal_links_processed', true),
    ],
    'term' => [
        'description' => get_term_meta($category->term_id, 'rank_math_description', true),
        'facebook_image_id' => (int) get_term_meta($category->term_id, 'rank_math_facebook_image_id', true),
        'title' => get_term_meta($category->term_id, 'rank_math_title', true),
    ],
    'redirection' => $redirection,
    'redirection_count' => count((array) $redirections),
    'redirection_cache_count' => $readCount(
        "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_redirections_cache",
        'redirection cache count'
    ),
    'links' => $links,
    'post_counts' => $postCounts,
    'hub_counts' => $hubCounts,
    'schema' => $schema,
    'target_owned' => [
        'instant_key' => $instant['indexnow_api_key'] ?? null,
        'indexnow_log' => get_option('rank_math_indexnow_log', null),
        'sitemap_posts' => $sitemap['exclude_posts'] ?? null,
        'notifications' => get_option('rank_math_notifications', null),
        'neighbor' => get_option('wprism_rank_math_target_neighbor', null),
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  then
    fail "$label Rank Math observation program could not be written"
  fi
  capture_wprism_json_success out "$label Rank Math observation" \
    "$wp_command" eval-file /siterepo/.tmp-rank-math-observe.php
  rm -f "$file"
  printf '%s\n' "$out"
}
