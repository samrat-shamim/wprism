<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php', 'ShellProbe.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Review/BlockReferenceScanner.php";
require_once "$root/agent/src/Review/Lint.php";
require_once "$root/agent/src/Review/LintEnvironment.php";
wprism_test_define_agent_versions();

use WPrism\{Blocks, BlockReferenceScanner, BlockValueGrammar, Canon, Lint, LintEnvironment, RepositoryCompiler, ValueShapeContract};
use WPrismTest\{FakeWpdb, FrozenPolicy, ShellProbe};

$string = ['class' => 'authored', 'plain_data' => true, 'scalar_type' => 'string'];
$number = ['class' => 'authored', 'plain_data' => true, 'scalar_type' => 'number'];
$boolean = ['class' => 'authored', 'plain_data' => true, 'scalar_type' => 'boolean'];
$literal = ['class' => 'authored', 'enum' => [false]];
$object = ['class' => 'authored', 'object_fields' => ['id' => ['class' => 'authored', 'ref' => 'post', 'on_unmapped' => 'refuse'],
    'label' => $string, 'width' => $number, 'enabled' => $boolean]];
$choice = ['class' => 'authored', 'one_of' => [$literal, $object]];
$manifest = ['name' => 'shape-fixture', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => [BlockValueGrammar::FEATURE, BlockValueGrammar::CONTRACT_FEATURE, ValueShapeContract::FEATURE, 'spec-window/v1'],
    'post_types' => ['page' => ['class' => 'authored']],
    'widgets' => ['block' => ['settings' => ['content' => ['class' => 'authored', 'codec' => 'blocks']]]],
    'block_values' => ['fixture/shape' => ['selection' => $choice]]];
sort($manifest['engine_features'], SORT_STRING);
$load = static fn(array $m) => FrozenPolicy::policy([$m], FrozenPolicy::site([$m], WPRISM_SPEC_VERSION));
$policy = $load($manifest);
$before = Canon::encode($manifest);
$rules = $policy->block_attr_rules();
wprism_check_same($before, Canon::encode($manifest), 'shape projection preserves authored identity inputs');
$reversed = $manifest;
$reversed['block_values']['fixture/shape']['selection']['one_of'] = [$object, $literal];
$reversePolicy = $load($reversed);
$block = static fn(mixed $value): string => '<!-- wp:fixture/shape ' . serialize_block_attributes(['selection' => $value]) . ' /-->';
$source = new WPrism\Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$target = new WPrism\Tokens('https://target.test/longer', 'https://target.test/longer/wp-content/uploads');
$uuid = '11111111-1111-4111-8111-111111111111';
$database = static fn(int $id) => FakeWpdb::install()->seedTable('wp_wprism_map', [
    ['uuid' => $uuid, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => $id],
]);
foreach ([false, ['id' => 1, 'label' => 'https://source.test/label', 'width' => 1.5, 'enabled' => false],
    ['width' => 0], ['enabled' => true], ['label' => '']] as $native) {
    $database(1);
    $canonical = Blocks::capture_rewrite($block($native), $policy, $source);
    wprism_check_same($canonical, Blocks::capture_rewrite($block($native), $reversePolicy, $source),
        'disjoint shape selection has no declaration-order fallback');
    $database(801);
    $applied = Blocks::apply_rewrite($canonical, $policy, $target);
    $expected = is_array($native) ? array_replace($native,
        isset($native['id']) ? ['id' => 801] : [], isset($native['label']) && $native['label'] !== '' ? ['label' => 'https://target.test/longer/label'] : []) : $native;
    wprism_check_same($block($expected), $applied, 'selected existing codecs preserve strict flags and numbers while rebinding IDs and text');
    wprism_check_same($canonical, Blocks::capture_rewrite($applied, $policy, $target), 'both literal and partial-object native postimages recapture exactly');
    wprism_check_same([], BlockReferenceScanner::scan(parse_blocks($canonical), $rules, 'first.md', 'https://source.test'),
        'parsed product review admits valid literal and closed object variants');
}
foreach (['feature', 'contracts', 'v2', 'scalar-codec', 'scalar-name', 'scalar-extra', 'case-null', 'case-map', 'case-short',
    'case-long', 'case-overlap', 'case-derived', 'case-plain', 'case-extra', 'nested-feature', 'deep', 'keyspace', 'option', 'column'] as $fault) {
    $bad = $manifest;
    $rule = &$bad['block_values']['fixture/shape']['selection'];
    if ($fault === 'feature') $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [ValueShapeContract::FEATURE]));
    if ($fault === 'contracts') $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [BlockValueGrammar::CONTRACT_FEATURE]));
    if ($fault === 'v2') $bad['spec_version'] = 2;
    if ($fault === 'scalar-codec') $rule = ['class' => 'authored', 'ref' => 'post', 'scalar_type' => 'number'];
    if ($fault === 'scalar-name') $rule = ['class' => 'authored', 'plain_data' => true, 'scalar_type' => 'integer'];
    if ($fault === 'scalar-extra') $rule = $string + ['cast' => 'string'];
    if ($fault === 'case-null') $rule['one_of'] = null;
    if ($fault === 'case-map') $rule['one_of'] = ['literal' => $literal, 'object' => $object];
    if ($fault === 'case-short') $rule['one_of'] = [$literal];
    if ($fault === 'case-long') $rule['one_of'][] = $literal;
    if ($fault === 'case-overlap') $rule['one_of'] = [$literal, $literal];
    if ($fault === 'case-derived') $rule['one_of'][0] = ['class' => 'derived'];
    if ($fault === 'case-plain') $rule['one_of'][0] = ['class' => 'authored', 'plain_data' => true];
    if ($fault === 'case-extra') $rule['plain_data'] = true;
    if ($fault === 'nested-feature') { $rule = $object;
    $bad['engine_features'] = array_values(array_diff($bad['engine_features'], [ValueShapeContract::FEATURE])); }
    if ($fault === 'deep') { $rule = $choice;
    for ($i = 0; $i < 5; $i++) $rule = ['class' => 'authored', 'object_fields' => ['nested' => $rule]]; }
    if ($fault === 'keyspace') $rule['one_of'][1]['object_fields']['id']['ref'] = 'private-space';
    if ($fault === 'option') { unset($bad['block_values']);
    $bad['options'] = ['shape' => ['class' => 'authored', 'sub_keys' => ['selection' => $choice]]]; }
    if ($fault === 'column') {
        unset($bad['block_values']);
        array_push($bad['engine_features'], 'json-column-codecs/v1', 'typed-column-codecs/v1', 'typed-column-values/v1');
        sort($bad['engine_features'], SORT_STRING);
        $bad['tables'] = ['shape' => ['class' => 'authored_snapshot', 'pk' => 'id', 'id_kind' => 'shape',
            'slug_column' => 'name', 'identity' => ['mode' => 'mapped'],
            'columns' => ['name' => ['class' => 'authored'], 'data' => ['class' => 'authored']], 'refs' => []]];
        $bad['column_codecs'] = ['shape' => ['data' => ['container' => 'json', 'value' => $choice]]];
        $control = $bad;
        $control['column_codecs']['shape']['data']['value'] = ['class' => 'authored', 'plain_data' => true];
        wprism_check($load($control) instanceof WPrism\Policy, 'the column transport control is independently legal without block shapes');
    }
    unset($rule);
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'manifest load refuses invalid or unnegotiated shape contract: ' . $fault,
        $fault === 'column' ? ValueShapeContract::FEATURE : null);
}
$bounded = $manifest;
$bounded['block_values'] = [];
$fields = array_fill_keys(array_map(static fn(int $i): string => 'field' . $i, range(1, 256)), $choice);
foreach (range(1, 35) as $i) $bounded['block_values']['fixture/shape' . $i] = ['selection' => ['class' => 'authored', 'object_fields' => $fields]];
// This boundary belongs to recursive declaration grammar. A frozen-policy
// envelope would duplicate the 15MB synthetic declaration before reaching it.
BlockValueGrammar::validate($bounded);
wprism_check(true, '62,755 recursively expanded rules including inactive alternatives fit the shared contract budget');
foreach (range(36, 40) as $i) $bounded['block_values']['fixture/shape' . $i] = ['selection' => ['class' => 'authored', 'object_fields' => $fields]];
wprism_check_throws(static fn() => BlockValueGrammar::validate($bounded), RuntimeException::class, '71,720 recursively expanded rules including inactive alternatives refuse at the shared contract budget');
$scratch = sys_get_temp_dir() . '/wprism-block-shapes-' . bin2hex(random_bytes(12));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/sidebars', 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path);
    return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-16 00:00:00', 'date_gmt' => '2026-09-16 00:00:00',
    'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [], 'modified' => '2026-09-16 00:00:00', 'modified_gmt' => '2026-09-16 00:00:00',
    'parent' => null, 'ping_status' => 'closed', 'slug' => 'first', 'status' => 'publish', 'terms' => (object) [], 'title' => 'first', 'type' => 'page', 'uuid' => $uuid];
$post = $scratch . '/state/posts/page/' . $uuid . '--first.md';
$widget = $scratch . '/state/sidebars/main.json';
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($policy->site));
$database(1);
$queries = $GLOBALS['wpdb']->queries();
foreach ([true, null, 0, 'false', [], ['unknown' => 'private@example.test'], ['label' => ['id' => 1]],
    ['width' => '32'], ['width' => false], ['enabled' => 1], ['id' => 1]] as $invalid) {
    $body = $block($invalid);
    foreach (['capture' => static fn() => Blocks::capture_rewrite($body, $policy, $source),
        'apply' => static fn() => Blocks::apply_rewrite($body, $policy, $target)] as $verb => $run) {
        // The final raw reference is valid native Capture but invalid canonical Apply.
        if ($invalid === ['id' => 1] && $verb === 'capture') continue;
        wprism_check_throws($run, RuntimeException::class, 'direct ' . $verb . ' refuses an invalid active shape before materialization');
    }
    $findings = BlockReferenceScanner::scan(parse_blocks($body), $rules, 'first.md', 'https://source.test');
    wprism_check_same(['invalid_block_value'], array_column($findings, 'class'), 'product review reports the invalid selected shape');
    wprism_check(!str_contains(Canon::encode($findings), 'private@example.test'), 'shape diagnostics do not display hostile values');
    foreach (['post', 'widget'] as $surface) {
        Canon::write_file($post, Canon::post_file($front, $surface === 'post' ? $body : $block(false)));
        if ($surface === 'widget') Canon::write_file($widget, Canon::encode(['widgets' => [[
            'uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'block', 'settings' => ['content' => $body]]]]));
        wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), WPrism\RepositoryCompilationException::class,
            'target-free immutable ' . $surface . ' compilation refuses invalid selected values');
        foreach ([true, false] as $parser) {
            $env = LintEnvironment::recorded(['format' => LintEnvironment::FORMAT, 'home' => 'https://source.test',
                'entities' => [], 'column_types' => [], 'probe_hash' => null, 'scanned' => ['blocks' => $parser, 'shortcodes' => false],
                'state_hash' => LintEnvironment::state_hash($scratch . '/state')]);
            wprism_check_same(['invalid_block_value'], array_column(Lint::scan_tree($scratch . '/state', $policy, $env), 'class'),
                'public ' . $surface . ' Lint enforces shapes exactly once with ' . ($parser ? 'WordPress parsing' : 'no native parser'));
        }
        if (file_exists($widget)) unlink($widget);
    }
}
wprism_check_same($queries, $GLOBALS['wpdb']->queries(), 'hostile shape admission, review and compilation never query or mutate the target database');
Canon::write_file($post, Canon::post_file($front, $block(false)));
Canon::write_file($widget, Canon::encode(['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333',
    'type' => 'block', 'settings' => ['content' => $block(['width' => 0, 'enabled' => false])]]]]));
wprism_check_same(2, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'literal and strict-scalar object variants compile together as real post and widget entities');
unlink($widget);
Canon::write_file($post, Canon::post_file($front, $block(['width' => '32']) . $block(['width' => '32'])));
foreach ([true, false] as $parser) {
    $env = LintEnvironment::recorded(['format' => LintEnvironment::FORMAT, 'home' => 'https://source.test',
        'entities' => [], 'column_types' => [], 'probe_hash' => null, 'scanned' => ['blocks' => $parser, 'shortcodes' => false],
        'state_hash' => LintEnvironment::state_hash($scratch . '/state')]);
    wprism_check_same(['invalid_block_value', 'invalid_block_value'], array_column(Lint::scan_tree($scratch . '/state', $policy, $env), 'class'),
        'raw/parsed deduplication preserves both independently invalid occurrences');
}
foreach (['<!-- wp:fixture/shape {"selection":broken} /-->', '<!-- wp:fixture/shape [false] /-->',
    '<!-- wp:fixture/shape {"selection":false}'] as $malformed) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($malformed, $policy, $source), RuntimeException::class,
        'shape Capture refuses original malformed comments before WordPress can erase the value');
    wprism_check_throws(static fn() => Blocks::apply_rewrite($malformed, $policy, $target), RuntimeException::class,
        'shape Apply refuses original malformed comments before WordPress can erase the value');
    wprism_check_same(['invalid_block_attributes'], array_column(BlockReferenceScanner::scan_closed_document($malformed, $rules, 'first.md'), 'class'),
        'pure shape review rejects corrupt comment framing without the native parser');
}
[$status, $stdout, $stderr] = ShellProbe::run('exec php "$1" "$2"', [__DIR__ . '/regress_block_attribute_values.php', '--value-shapes'], $root);
wprism_check_same(0, $status, 'shape contracts pass the complete reused compiler/SQL/fault/retry/post/widget proof');
wprism_check_same('', $stderr, 'shape product proof emits no diagnostics');
wprism_check(str_contains($stdout, 'full native post recapture is canonical byte equality'), 'shape product proof reaches actual post recapture');
wprism_check_summary('block value shapes');
