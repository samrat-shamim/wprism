<?php
declare(strict_types=1);

// Deliberately synthetic mutation inputs to the actual admission closure,
// never a native run record. regress_form_tags.php owns mechanism execution.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/tag-evidence.php';
use WPFormsTagEvidence as Tags;

$form = static function (int $id, string $role): array {
    $row = array_fill_keys(['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt',
        'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
        'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order',
        'post_type', 'post_mime_type', 'comment_count'], '');
    return array_replace($row, ['ID' => (string) $id, 'post_author' => '1', 'post_parent' => '0', 'menu_order' => '0', 'comment_count' => '0',
        'post_date' => '2026-09-08 01:00:00', 'post_date_gmt' => '2026-09-08 01:00:00',
        'post_modified' => '2026-09-08 01:00:00', 'post_modified_gmt' => '2026-09-08 01:00:00',
        'post_type' => 'wpforms', 'post_status' => 'publish', 'post_name' => 'wprism-wpf-' . $role, 'post_title' => 'Tagged form',
        'comment_status' => 'closed', 'ping_status' => 'closed',
        'post_content' => json_encode(['id' => $id, 'fields' => [], 'settings' => ['form_title' => 'Tagged form'], 'field_id' => '0'], JSON_THROW_ON_ERROR)]);
};
$empty = static fn(int $id): array => ['terms' => [['term_id' => '1', 'name' => 'Uncategorized', 'slug' => 'uncategorized', 'term_group' => '0']],
    'term_taxonomy' => [['term_taxonomy_id' => '1', 'term_id' => '1', 'taxonomy' => 'category', 'description' => '', 'parent' => '0', 'count' => '0']],
    'term_relationships' => [], 'termmeta' => [], 'forms' => [$form($id, 'integer')]];
$slugs = ['Intake Ω' => 'intake-%cf%89', '701' => '701', Tags::LOCAL => 'unrelated-local-tag'];
$labels = ['first' => ['Intake Ω'], 'initial' => ['Intake Ω', '701'], 'local' => [Tags::LOCAL], 'clear' => [], 'change' => ['701']];
$choices = static function (array $physical): array {
    $terms = array_column($physical['terms'], null, 'term_id');
    $out = [];
    foreach ($physical['term_taxonomy'] as $tt) if ($tt['taxonomy'] === 'wpforms_form_tag') {
        $term = $terms[$tt['term_id']];
        $out[] = ['value' => $term['term_id'], 'slug' => $term['slug'], 'label' => $term['name'], 'count' => (int) $tt['count']];
    }
    return $out;
};
$author = static function (string $side, string $mode, array $before) use ($slugs, $labels, $choices): array {
    $id = (int) $before['forms'][0]['ID'];
    $number = $side === 'source' ? 1 : 2;
    $values = $labels[$mode];
    $beforeChoices = $choices($before);
    $next = $before;
    $postTags = [];
    $created = false;
    foreach ($values as $label) {
        $found = array_values(array_filter($beforeChoices, static fn(array $choice): bool => $choice['label'] === $label));
        $postTags[] = ['value' => $found === [] ? $label : $found[0]['value'], 'label' => $label];
        if ($found !== []) continue;
        $created = true;
        $termId = (string) (($side === 'source' ? 21 : 31) + array_search($label, ['Intake Ω', '701', Tags::LOCAL], true));
        $next['terms'][] = ['term_id' => $termId, 'name' => $label, 'slug' => $slugs[$label], 'term_group' => '0'];
        $next['term_taxonomy'][] = ['term_taxonomy_id' => (string) ((int) $termId + 20), 'term_id' => $termId,
            'taxonomy' => 'wpforms_form_tag', 'description' => '', 'parent' => '0', 'count' => '0'];
    }
    $owned = array_column(array_filter($next['term_taxonomy'], static fn(array $tt): bool => $tt['taxonomy'] === 'wpforms_form_tag'), 'term_taxonomy_id');
    $next['term_relationships'] = array_values(array_filter($next['term_relationships'], static fn(array $row): bool =>
        $row['object_id'] !== (string) $id || !in_array($row['term_taxonomy_id'], $owned, true)));
    $terms = array_column($next['terms'], null, 'term_id');
    $assigned = [];
    foreach ($next['term_taxonomy'] as &$tt) if ($tt['taxonomy'] === 'wpforms_form_tag') {
        $tt['count'] = in_array($terms[$tt['term_id']]['name'], $values, true) ? '1' : '0';
        if ($tt['count'] === '0') continue;
        $next['term_relationships'][] = ['object_id' => (string) $id, 'term_taxonomy_id' => $tt['term_taxonomy_id'], 'term_order' => '0'];
        $assigned[] = $terms[$tt['term_id']];
    }
    unset($tt);
    $body = json_decode($next['forms'][0]['post_content'], true, 32, JSON_THROW_ON_ERROR);
    $body['settings']['form_tags'] = $values;
    $next['forms'][0]['post_content'] = json_encode($body, JSON_THROW_ON_ERROR);
    $html = '<html><body><div class="wpforms-column-tags-links" data-form-id="' . $id . '" data-is-editable="1">'
        . '<a class="wpforms-column-tags-edit">Edit</a></div><script>var wpforms_admin_forms_overview = '
        . json_encode(['choicesjs_config' => [], 'edit_tags_form' => '', 'all_tags_choices' => $beforeChoices, 'strings' => ['nonce' => '123456abcd']], JSON_THROW_ON_ERROR)
        . ';</script></body></html>';
    $data = ['tags_links' => '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">No tags</span>', 'tags_ids' => '', 'tags_options' => ''];
    if ($assigned !== []) {
        $data['tags_ids'] = implode(',', array_column($assigned, 'term_id'));
        $data['tags_links'] = implode(', ', array_map(static fn(array $term): string => '<a href="http://wpftags' . $number
            . '.invalid/wp-admin/admin.php?page=wpforms-overview&#038;tags=' . rawurlencode($term['slug']) . '">' . $term['name'] . '</a>', $assigned));
        $data['tags_options'] = implode('', array_map(static fn(array $term): string => '<option value="' . $term['term_id'] . '" selected>' . $term['name'] . '</option>', $assigned));
    }
    if ($created) $data['all_tags_choices'] = $choices($next);
    $requests = [];
    foreach (['GET', 'POST'] as $index => $method) $requests[] = ['url' => 'http://wprism-wpftags-wp' . $number . '-1'
        . ($index === 0 ? '/wp-admin/admin.php?page=wpforms-overview' : '/wp-admin/admin-ajax.php'), 'host' => 'wpftags' . $number . '.invalid',
        'method' => $method, 'post' => $index === 0 ? [] : ['action' => 'wpforms_admin_forms_overview_save_tags', 'nonce' => '123456abcd', 'forms' => [(string) $id], 'tags' => $postTags],
        'status' => 200, 'headers' => [], 'body' => $index === 0 ? $html : json_encode(['success' => true, 'data' => $data], JSON_THROW_ON_ERROR)];
    return ['format' => 'wprism-wpforms-native-tag-author/v1', 'version' => '2.0.1.1', 'side' => $side, 'mode' => $mode,
        'form_id' => $id, 'home' => 'http://wpftags' . $number . '.invalid', 'actor' => 'admin',
        'capabilities' => ['edit_others_forms' => true, 'edit_form_single' => true], 'debug_config' => [true, true, false],
        'diagnostics_before' => ['present' => false, 'bytes' => ''], 'before' => $before, 'requests' => $requests,
        'session_retired' => true, 'after' => $next, 'diagnostics_after' => ['present' => false, 'bytes' => '']];
};
$authors = [];
foreach (['source' => ['first', 'initial', 'change'], 'target' => ['local', 'clear', 'initial']] as $side => $modes) {
    $physical = $empty($side === 'source' ? 11 : 111);
    foreach ($modes as $mode) {
        $record = $author($side, $mode, $physical);
        Tags::author($record, 'wpftags', $side, $mode);
        wprism_check(true, 'actual author admission accepts synthetic ' . $side . ':' . $mode);
        $authors[$side . ':' . $mode] = $record;
        $physical = $record['after'];
    }
}
$positive = $authors['source:initial'];
foreach ([
    'wrong version' => static function (&$r): void { $r['version'] = '2.0.0.4'; },
    'wrong actor' => static function (&$r): void { $r['actor'] = 'subscriber'; },
    'missing single-form capability' => static function (&$r): void { $r['capabilities']['edit_form_single'] = false; },
    'unretired session' => static function (&$r): void { $r['session_retired'] = false; },
    'disabled diagnostics' => static function (&$r): void { $r['debug_config'][1] = false; },
    'native diagnostic' => static function (&$r): void { $r['diagnostics_after']['bytes'] = 'hidden error'; },
    'wrong native host' => static function (&$r): void { $r['requests'][1]['host'] = 'foreign.invalid'; },
    'wrong native route' => static function (&$r): void { $r['requests'][1]['url'] .= '?foreign'; },
    'redirect' => static function (&$r): void { $r['requests'][1]['status'] = 302; },
    'wrong nonce' => static function (&$r): void { $r['requests'][1]['post']['nonce'] = 'abcdef1234'; },
    'extra request field' => static function (&$r): void { $r['requests'][1]['post']['hidden'] = 'value'; },
    'coerced numeric label' => static function (&$r): void { $r['requests'][1]['post']['tags'][1]['label'] = 701; },
    'new label replaced with guessed ID' => static function (&$r): void { $r['requests'][1]['post']['tags'][1]['value'] = '22'; },
    'forged success' => static function (&$r): void { $r['after'] = $r['before']; },
    'missing relationship' => static function (&$r): void { array_pop($r['after']['term_relationships']); },
    'TT mistaken for term ID' => static function (&$r): void { $r['after']['term_relationships'][1]['term_taxonomy_id'] = '22'; },
    'wrong relationship order' => static function (&$r): void { $r['after']['term_relationships'][1]['term_order'] = '3'; },
    'wrong native count' => static function (&$r): void { $r['after']['term_taxonomy'][1]['count'] = '0'; },
    'removed foreign term' => static function (&$r): void { array_shift($r['after']['terms']); },
    'changed foreign TT' => static function (&$r): void { $r['after']['term_taxonomy'][0]['description'] = 'changed'; },
    'duplicate physical term' => static function (&$r): void { $r['after']['terms'][] = $r['after']['terms'][0]; },
    'extra physical column' => static function (&$r): void { $r['after']['term_taxonomy'][0]['hidden'] = 'column'; },
    'missing form column' => static function (&$r): void { unset($r['after']['forms'][0]['guid']); },
    'unrelated form byte changed' => static function (&$r): void { $r['after']['forms'][0]['post_title'] = 'other'; },
    'wrong stored label type' => static function (&$r): void { $r['after']['forms'][0]['post_content'] = str_replace('"701"', '701', $r['after']['forms'][0]['post_content']); },
    'extra native response field' => static function (&$r): void { $body = json_decode($r['requests'][1]['body'], true); $body['data']['extra'] = true; $r['requests'][1]['body'] = json_encode($body); },
    'wrong native response IDs' => static function (&$r): void { $body = json_decode($r['requests'][1]['body'], true); $body['data']['tags_ids'] = '41,42'; $r['requests'][1]['body'] = json_encode($body); },
    'wrong complete response HTML' => static function (&$r): void { $body = json_decode($r['requests'][1]['body'], true); $body['data']['tags_links'] .= 'extra'; $r['requests'][1]['body'] = json_encode($body); },
    'missing native choice' => static function (&$r): void { $body = json_decode($r['requests'][1]['body'], true); array_pop($body['data']['all_tags_choices']); $r['requests'][1]['body'] = json_encode($body); },
    'hidden transport failure' => static function (&$r): void { $r['transport_error'] = []; },
] as $label => $mutate) {
    $record = $positive;
    $mutate($record);
    wprism_check_throws(static fn() => Tags::author($record, 'wpftags', 'source', 'initial'), RuntimeException::class,
        'actual native tag author admission refuses ' . $label, 'WPForms tag evidence:');
}

$uuid = static fn(int $id): string => '019200dd-0000-7000-8000-' . sprintf('%012d', $id);
$observe = static function (array $physical, string $side, bool $managed, bool $local) use ($form, $choices, $uuid): array {
    $physical['forms'][] = $form($side === 'source' ? 12 : 112, 'string');
    $mapping = $posts = $consumers = [];
    $terms = array_column($physical['terms'], null, 'term_id');
    foreach ($physical['term_taxonomy'] as $tt) {
        $term = $terms[$tt['term_id']];
        $tag = $tt['taxonomy'] === 'wpforms_form_tag';
        $identity = $tag ? $uuid($term['name'] === Tags::LOCAL ? 23 : ($term['name'] === '701' ? 22 : 21)) : $uuid(1);
        $active = $term['name'] === Tags::LOCAL ? $local : $managed;
        if ($tag) $mapping[$term['term_id']] = ['term' => $active ? $identity : null, 'term_taxonomy' => $active ? $identity : null];
        if ($active) $physical['termmeta'][] = ['meta_id' => (string) (100 + (int) $term['term_id']), 'term_id' => $term['term_id'], 'meta_key' => '_wprism_uuid', 'meta_value' => $identity];
    }
    foreach ($physical['forms'] as $index => $row) {
        $role = $index === 0 ? 'integer' : 'string';
        $posts[$role] = ['id' => (int) $row['ID'], 'uuid' => $uuid($index + 11), 'slug' => $row['post_name'], 'title' => $row['post_title'], 'body' => $row['post_content']];
        $native = [];
        foreach ($physical['term_relationships'] as $relationship) if ($relationship['object_id'] === $row['ID']) {
            $tt = array_values(array_filter($physical['term_taxonomy'], static fn(array $tt): bool => $tt['term_taxonomy_id'] === $relationship['term_taxonomy_id']))[0];
            if ($tt['taxonomy'] !== 'wpforms_form_tag') continue;
            $term = $terms[$tt['term_id']];
            $native[] = ['term_id' => (int) $term['term_id'], 'name' => $term['name'], 'slug' => $term['slug'], 'term_group' => 0,
                'term_taxonomy_id' => (int) $tt['term_taxonomy_id'], 'taxonomy' => $tt['taxonomy'], 'description' => '', 'parent' => 0, 'count' => (int) $tt['count'], 'filter' => 'raw'];
        }
        $consumers[$row['ID']] = ['terms' => $native, 'body' => json_decode($row['post_content'], true, 32, JSON_THROW_ON_ERROR)];
    }
    return ['posts' => $posts, 'tags' => ['physical' => $physical, 'consumers' => $consumers, 'choices' => $choices($physical),
        'identities' => $managed ? $mapping : [], 'diagnostics' => ['present' => false, 'bytes' => '']]];
};
$source = $observe($authors['source:initial']['after'], 'source', true, false);
$before = $observe($authors['target:initial']['after'], 'target', false, false);
$target = $observe($authors['target:initial']['after'], 'target', true, false);
$recaptured = $observe($authors['target:initial']['after'], 'target', true, true);
Tags::authoredToObserved($authors['source:initial'], $source['tags']);
Tags::authoredToObserved($authors['target:initial'], $before['tags']);
wprism_check(true, 'native author is bound to later complete observation with only first durable identity additions');
Tags::observation($source['tags'], $source['posts'], ['Intake Ω', '701']);
Tags::observation($before['tags'], $before['posts'], ['Intake Ω', '701']);
wprism_check(true, 'actual native observation admission binds fresh body/term consumers to complete physical rows');

require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
wprism_test_define_agent_versions();
$library = WPrism\AdapterLibrary::fromSourcePackage($root, 'wpforms-lite');
$compile = static function (array $observation, string $name) use ($library): WPrism\CompiledRepository {
    $repo = WPrismTest\FrozenPolicy::library() . '/' . $name;
    mkdir($repo . '/state', 0700, true);
    WPrism\Canon::write_file($repo . '/site.wprism.json', WPrism\Canon::encode(['spec_version' => 3, 'manifests' => ['core', 'wpforms-lite'],
        'policy' => ['post_types' => ['wpforms'], 'taxonomies' => ['wpforms_form_tag']]]));
    $terms = array_column($observation['tags']['physical']['terms'], null, 'term_id');
    mkdir($repo . '/state/terms/wpforms_form_tag', 0700, true);
    foreach ($observation['tags']['physical']['term_taxonomy'] as $tt) if ($tt['taxonomy'] === 'wpforms_form_tag') {
        $term = $terms[$tt['term_id']];
        $uuid = $observation['tags']['identities'][$tt['term_id']]['term'];
        WPrism\Canon::write_file($repo . '/state/terms/wpforms_form_tag/' . $uuid . '--' . $term['slug'] . '.json', WPrism\Canon::encode([
            'uuid' => $uuid, 'taxonomy' => 'wpforms_form_tag', 'name' => $term['name'], 'slug' => $term['slug'], 'description' => '', 'parent' => null,
            'meta' => (object) [], 'relationships' => (object) []]));
    }
    mkdir($repo . '/state/posts/wpforms', 0700, true);
    $post = $observation['posts']['integer'];
    $assigned = [];
    foreach ($observation['tags']['consumers'][$post['id']]['terms'] as $term) $assigned[] = $observation['tags']['identities'][$term['term_id']]['term'];
    sort($assigned, SORT_STRING);
    $front = ['uuid' => $post['uuid'], 'type' => 'wpforms', 'slug' => $post['slug'], 'title' => $post['title'], 'status' => 'publish',
        'date' => '2026-09-08 01:00:00', 'date_gmt' => '2026-09-08 01:00:00', 'modified' => '2026-09-08 01:00:00', 'modified_gmt' => '2026-09-08 01:00:00',
        'author' => null, 'parent' => null, 'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed', 'excerpt' => '', 'meta' => (object) [],
        'terms' => ['wpforms_form_tag' => $assigned], 'term_orders' => ['wpforms_form_tag' => array_fill_keys($assigned, 0)]];
    $policy = WPrism\Policy::load($repo, adapterLibrary: $library);
    $tokens = new WPrism\Tokens('http://wpftags.invalid', 'http://wpftags.invalid/wp-content/uploads');
    $body = WPrism\BodyRefGrammar::capture($post['body'], $policy->body_ref_rule('wpforms'),
        static fn(int $id, string $kind): string => '{{post:' . $post['uuid'] . '}}',
        static function (string $warning): never { throw new RuntimeException($warning); }, 'native tag admission fixture', $tokens->tokenize_text(...));
    WPrism\Canon::write_file($repo . '/state/posts/wpforms/' . $post['uuid'] . '--' . $post['slug'] . '.md', WPrism\Canon::post_file($front, $body));
    return WPrism\RepositoryCompiler::compile_staged($repo . '/state', $repo, $policy);
};
$sourceRepo = $compile($source, 'source');
$targetRepo = $compile($recaptured, 'target');
$targetOnly = Tags::native('baseline', $source, $before, $target, $target, $recaptured, $sourceRepo, $targetRepo);
WPrismTest\RepositoryConvergence::assertSame($sourceRepo, $targetRepo, $targetOnly);
wprism_check_same([$uuid(23)], array_keys($targetOnly), 'actual host admission permits only the independently preserved local compiled identity');
wprism_check_throws(static fn() => WPrismTest\RepositoryConvergence::assertSame($sourceRepo, $targetRepo), RuntimeException::class,
    'complete compiler rejects local inventory without explicit preservation proof', 'unproved target-only');
foreach (['compiled local name', 'compiled literal labels'] as $mutation) {
    $mutant = $recaptured;
    if ($mutation === 'compiled local name') $mutant['tags']['physical']['terms'][1]['name'] = 'Unproved local name';
    else $mutant['posts']['integer']['body'] = str_replace('"701"', '"other label"', $mutant['posts']['integer']['body']);
    $mutantRepo = $compile($mutant, str_replace(' ', '-', $mutation));
    wprism_check_throws(static fn() => Tags::native('baseline', $source, $before, $target, $target, $recaptured, $sourceRepo, $mutantRepo), RuntimeException::class,
        'actual admission independently rejects ' . $mutation . ' after successful real compilation', 'WPForms tag evidence:');
}
$sourceChanged = $observe($authors['source:change']['after'], 'source', true, false);
$targetChanged = $observe($author('target', 'change', $authors['target:initial']['after'])['after'], 'target', true, true);
$sourceChangedRepo = $compile($sourceChanged, 'changed-source');
$targetChangedRepo = $compile($targetChanged, 'changed-target');
$changedOnly = Tags::native('tags', $sourceChanged, $recaptured, $targetChanged, $targetChanged, $targetChanged, $sourceChangedRepo, $targetChangedRepo);
WPrismTest\RepositoryConvergence::assertSame($sourceChangedRepo, $targetChangedRepo, $changedOnly);
wprism_check_same([$uuid(23)], array_keys($changedOnly), 'changed native assignment admission removes stale relationships without dropping either unassigned term from full compiler inventory');
foreach ([
    'wrong literal label type' => static function (&$s, &$b, &$t, &$r, &$c): void { $t['tags']['consumers'][111]['body']['settings']['form_tags'][1] = 701; },
    'missing native term consumer' => static function (&$s, &$b, &$t, &$r, &$c): void { array_pop($t['tags']['consumers'][111]['terms']); },
    'fresh consumer uses source ID' => static function (&$s, &$b, &$t, &$r, &$c): void { $t['tags']['consumers'][111]['terms'][0]['term_id'] = 21; },
    'local term removed' => static function (&$s, &$b, &$t, &$r, &$c): void { array_splice($t['tags']['physical']['terms'], 1, 1); },
    'local row changed across all target observations' => static function (&$s, &$b, &$t, &$r, &$c): void { foreach ([&$t, &$r, &$c] as &$record) $record['tags']['physical']['terms'][1]['slug'] = 'changed'; },
    'local preimage changed' => static function (&$s, &$b, &$t, &$r, &$c): void { $b['tags']['physical']['terms'][1]['slug'] = 'changed'; },
    'foreign relationship added' => static function (&$s, &$b, &$t, &$r, &$c): void { $t['tags']['physical']['term_relationships'][] = ['object_id' => '999', 'term_taxonomy_id' => '1', 'term_order' => '0']; },
    'TT UUID remapped' => static function (&$s, &$b, &$t, &$r, &$c) use ($uuid): void { $t['tags']['identities'][31]['term_taxonomy'] = $uuid(99); },
    'duplicate durable identity' => static function (&$s, &$b, &$t, &$r, &$c): void { $t['tags']['physical']['termmeta'][] = ['meta_id' => '999', 'term_id' => '31', 'meta_key' => '_wprism_uuid', 'meta_value' => $t['tags']['identities'][31]['term']]; },
    'unexpected metadata added on recapture' => static function (&$s, &$b, &$t, &$r, &$c): void { $c['tags']['physical']['termmeta'][] = ['meta_id' => '999', 'term_id' => '33', 'meta_key' => 'unrelated', 'meta_value' => 'changed']; },
    'native retry mutation' => static function (&$s, &$b, &$t, &$r, &$c): void { $r['tags']['diagnostics']['present'] = true; },
    'native recapture mutation' => static function (&$s, &$b, &$t, &$r, &$c): void { $c['tags']['physical']['forms'][0]['guid'] = 'changed'; },
] as $label => $mutate) {
    [$s, $b, $t, $r, $c] = [$source, $before, $target, $target, $recaptured];
    $mutate($s, $b, $t, $r, $c);
    wprism_check_throws(static fn() => Tags::native('baseline', $s, $b, $t, $r, $c, $sourceRepo, $targetRepo), RuntimeException::class,
        'actual native Apply admission refuses ' . $label, 'WPForms tag evidence:');
}
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
$mounted = WPrismTest\FrozenPolicy::library() . '/mu-plugins/adapter-packages/wpforms-lite/fixtures/location-provider';
mkdir($mounted, 0700, true);
foreach (['native-tags.php', 'tag-evidence.php', 'native-admin.php'] as $file) copy(dirname(__DIR__, 2) . '/fixtures/location-provider/' . $file, $mounted . '/' . $file);
[$exit, $stdout, $stderr] = WPrismTest\ShellProbe::run(<<<'SH'
exec "$1" -d display_errors=stderr -r 'require $argv[1]; WPFormsTagEvidence::author(json_decode($argv[2], true, 32, JSON_THROW_ON_ERROR), "wpftags", "source", "initial"); if (class_exists("WPrism\\Canon", false)) throw new RuntimeException("host compiler loaded natively"); echo "MOUNTED_TAG_ASSERTIONS_READY\n";' "$2" "$3"
SH, [PHP_BINARY, $mounted . '/native-tags.php', json_encode($positive, JSON_THROW_ON_ERROR)], $root);
wprism_check_same([0, "MOUNTED_TAG_ASSERTIONS_READY\n", ''], [$exit, $stdout, $stderr], 'actual native tag assertion closure loads without host dependencies in capsule-only mount');
wprism_check_summary('regress_wpforms_lite_native_tag_evidence');
