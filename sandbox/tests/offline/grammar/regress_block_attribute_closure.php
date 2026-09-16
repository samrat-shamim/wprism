<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Review/BlockReferenceScanner.php";
require_once "$root/agent/src/Review/Lint.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
wprism_test_define_agent_versions();

use WPrism\{BlockValueGrammar, BlockReferenceScanner, Blocks, Canon, RepositoryCompiler, Tokens};
use WPrism\{Lint, LintEnvironment};
use WPrismTest\{FakeWpdb, FrozenPolicy};

$plain = ['class' => 'authored', 'plain_data' => true];
$open = ['name' => 'closure-fixture', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'engine_features' => [BlockValueGrammar::FEATURE, 'spec-window/v1'],
    'post_types' => ['page' => ['class' => 'authored']],
    'widgets' => ['block' => ['settings' => ['content' => ['class' => 'authored', 'codec' => 'blocks']]]],
    'block_values' => ['fixture/one' => ['text' => $plain], 'fixture/two' => ['text' => $plain]],
    'block_attrs' => ['fixture/one' => [['path' => 'caption', 'tokenize' => 'text']]]];
$closed = $open;
$closed['engine_features'][] = BlockValueGrammar::CLOSURE_FEATURE;
sort($closed['engine_features'], SORT_STRING);
$closed[BlockValueGrammar::CLOSURE_FIELD] = ['fixture/one', 'fixture/two'];
$load = static fn(array $m) => FrozenPolicy::policy([$m], FrozenPolicy::site([$m], WPRISM_SPEC_VERSION));
$openPolicy = $load($open);
$policy = $load($closed);
$before = Canon::encode($closed);
$rules = $policy->block_attr_rules();
wprism_check_same($before, Canon::encode($closed), 'closure projection never changes authored identity inputs');
wprism_check_same([true, true], array_column($rules['fixture/one'], 'closed_attributes'), 'closure annotates value and disjoint legacy paths in one normalized roster');
wprism_check_same([], array_column($openPolicy->block_attr_rules()['fixture/one'], 'closed_attributes'), 'existing open declarations retain byte-identical runtime rows');
$block = static fn(array $attrs, string $name = 'fixture/one'): string => '<!-- wp:' . $name . ' ' . serialize_block_attributes($attrs) . ' /-->';
$source = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$target = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$valid = $block(['text' => 'https://source.test/text', 'caption' => 'https://source.test/caption']);
$canonical = Blocks::capture_rewrite($valid, $policy, $source);
wprism_check_same($block(['text' => '{{home}}/text', 'caption' => '{{home}}/caption']), $canonical, 'closed roster composes value codecs and disjoint legacy text rules');
wprism_check_same($canonical, Blocks::capture_rewrite(Blocks::apply_rewrite($canonical, $policy, $target), $policy, $target), 'closed native content keeps the exact cross-home fixed point');
wprism_check_same('<!-- wp:fixture/one /-->', Blocks::capture_rewrite('<!-- wp:fixture/one /-->', $policy, $source), 'absence of optional fields stays absent');
$partial = $closed;
$partial[BlockValueGrammar::CLOSURE_FIELD] = ['fixture/one'];
wprism_check_same($block(['extension' => ['entity' => 1]], 'fixture/two'), Blocks::capture_rewrite($block(['extension' => ['entity' => 1]], 'fixture/two'), $load($partial), $source), 'closure only affects explicitly selected owned blocks');
foreach (['text' => null, 'caption' => '', 'extension' => ['entity' => 1]] as $key => $value) if ($key !== 'extension') {
    Blocks::capture_rewrite($block([$key => $value]), $policy, $source);
    wprism_check(true, 'declared native field remains governed by its ordinary codec: ' . $key);
}
foreach (['feature', 'base-feature', 'v2', 'null', 'scalar', 'empty', 'associative', 'owner', 'duplicate', 'order', 'limit', 'length', 'missing-values'] as $fault) {
    $bad = $closed;
    if ($fault === 'feature') $bad['engine_features'] = $open['engine_features'];
    if ($fault === 'base-feature') $bad['engine_features'] = [BlockValueGrammar::CLOSURE_FEATURE, 'spec-window/v1'];
    if ($fault === 'v2') $bad['spec_version'] = 2;
    if ($fault === 'null') $bad[BlockValueGrammar::CLOSURE_FIELD] = null;
    if ($fault === 'scalar') $bad[BlockValueGrammar::CLOSURE_FIELD] = true;
    if ($fault === 'empty') $bad[BlockValueGrammar::CLOSURE_FIELD] = [];
    if ($fault === 'associative') $bad[BlockValueGrammar::CLOSURE_FIELD] = ['owner' => 'fixture/one'];
    if ($fault === 'owner') $bad[BlockValueGrammar::CLOSURE_FIELD] = ['fixture/unknown'];
    if ($fault === 'duplicate') $bad[BlockValueGrammar::CLOSURE_FIELD] = ['fixture/one', 'fixture/one'];
    if ($fault === 'order') $bad[BlockValueGrammar::CLOSURE_FIELD] = ['fixture/two', 'fixture/one'];
    if ($fault === 'limit') $bad[BlockValueGrammar::CLOSURE_FIELD] = array_fill(0, 4097, 'fixture/one');
    if ($fault === 'length') {
        $name = 'fixture/' . str_repeat('x', 121);
        $bad['block_values'][$name] = ['text' => $plain];
        $bad[BlockValueGrammar::CLOSURE_FIELD] = [$name];
    }
    if ($fault === 'missing-values') unset($bad['block_values']);
    wprism_check_throws(static fn() => $load($bad), RuntimeException::class, 'manifest loading refuses malformed closure: ' . $fault);
}
$siteOverride = FrozenPolicy::site([$closed], WPRISM_SPEC_VERSION);
$siteOverride['policy'][BlockValueGrammar::CLOSURE_FIELD] = ['fixture/one'];
wprism_check_throws(static fn() => FrozenPolicy::policy([$closed], $siteOverride), RuntimeException::class, 'site policy cannot replace the manifest closure roster');
$injected = $open;
$injected['block_attrs']['fixture/one'][0]['closed_attributes'] = true;
wprism_check_throws(static fn() => $load($injected), RuntimeException::class, 'authored legacy rules cannot inject internal closure metadata');

$scratch = sys_get_temp_dir() . '/wprism-block-closure-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/sidebars', 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path);
    return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$uuid = '11111111-1111-4111-8111-111111111111';
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-16 00:00:00', 'date_gmt' => '2026-09-16 00:00:00',
    'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [], 'modified' => '2026-09-16 00:00:00', 'modified_gmt' => '2026-09-16 00:00:00',
    'parent' => null, 'ping_status' => 'closed', 'slug' => 'first', 'status' => 'publish', 'terms' => (object) [], 'title' => 'first', 'type' => 'page', 'uuid' => $uuid];
$post = $scratch . '/state/posts/page/' . $uuid . '--first.md';
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($policy->site));
$database = FakeWpdb::install();
$queries = $database->queries();
$compileRefusal = static function (string $surface) use ($scratch, $policy): void {
    try { RepositoryCompiler::compile($scratch, $policy);
    $diagnostics = []; } catch (WPrism\RepositoryCompilationException $e) { $diagnostics = $e->diagnostics; }
    $closure = array_values(array_filter($diagnostics, static fn(array $d): bool => $d['code'] === 'repository_block_attribute_undeclared'));
    wprism_check_same(1, count($closure), 'immutable ' . $surface . ' compiler names the exact closure boundary');
    wprism_check(!str_contains(Canon::encode($diagnostics), 'private@example.test'), 'immutable ' . $surface . ' diagnostics omit unreviewed field names');
};
foreach ([null, '', [], ['entity' => 1], 'https://source.test/private', '{{post:22222222-2222-4222-8222-222222222222}}'] as $unknown) {
    $body = $block(['private@example.test' => $unknown]);
    $expected = "wprism: block 'fixture/one' contains an undeclared attribute outside its closed roster";
    foreach (['capture' => static fn() => Blocks::capture_rewrite($body, $policy, $source, true),
        'apply' => static fn() => Blocks::apply_rewrite($body, $policy, $target)] as $verb => $run) {
        try { $run();
        $message = null; } catch (RuntimeException $e) { $message = $e->getMessage(); }
        wprism_check_same($expected, $message, 'direct ' . $verb . ' refuses unknown presence without names, values or a force bypass');
    }
    $findings = BlockReferenceScanner::scan(parse_blocks($body), $rules, 'first.md', 'https://source.test', static fn(): ?array => null);
    wprism_check_same(['undeclared_block_attribute'], array_column($findings, 'class'), 'lint closes nested values without relying on ID-shaped heuristics');
    wprism_check(!str_contains(Canon::encode($findings), 'private@example.test'), 'lint never displays unreviewed field names');
    Canon::write_file($post, Canon::post_file($front, $body));
    $rejected = file_get_contents($post);
    $compileRefusal('post');
    wprism_check_same($rejected, file_get_contents($post), 'refused compilation preserves the exact rejected post input');
    Canon::write_file($post, Canon::post_file($front, $canonical));
    Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode(['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333',
        'type' => 'block', 'settings' => ['content' => $body]]]]));
    $compileRefusal('widget');
    unlink($scratch . '/state/sidebars/main.json');
}
wprism_check_same($queries, $database->queries(), 'all closure refusals are target-free and leave the database untouched');
foreach (['<!-- wp:fixture/one {"text":broken} /-->', '<!-- wp:fixture/one [4] /-->', '<!-- wp:fixture/one {"text":4}'] as $malformed) {
    foreach (['capture' => static fn() => Blocks::capture_rewrite($malformed, $policy, $source),
        'apply' => static fn() => Blocks::apply_rewrite($malformed, $policy, $target)] as $verb => $run) {
        wprism_check_throws($run, RuntimeException::class, 'closed ' . $verb . ' validates original bytes before WordPress can erase malformed attributes');
    }
    wprism_check_same(['invalid_block_attributes'], array_column(BlockReferenceScanner::scan_closed_document($malformed, $rules, 'first.md'), 'class'),
        'parser-free review detects malformed closed attribute framing');
}
foreach (['post', 'widget'] as $surface) foreach ([true, false] as $parser) {
    Canon::write_file($post, Canon::post_file($front, $surface === 'post' ? $block(['extension' => ['entity' => 1]]) : $canonical));
    if ($surface === 'widget') Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode(['widgets' => [[
        'uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'block', 'settings' => ['content' => $block(['extension' => ['entity' => 1]])]]]]));
    $env = LintEnvironment::recorded(['format' => LintEnvironment::FORMAT, 'home' => 'https://source.test',
        'entities' => [], 'column_types' => [], 'probe_hash' => null, 'scanned' => ['blocks' => $parser, 'shortcodes' => false],
        'state_hash' => LintEnvironment::state_hash($scratch . '/state')]);
    wprism_check_same(['undeclared_block_attribute'], array_column(Lint::scan_tree($scratch . '/state', $policy, $env), 'class'),
        'public ' . $surface . ' Lint enforces closure exactly once with ' . ($parser ? 'WordPress parsing' : 'no block parser'));
    if ($surface === 'widget') unlink($scratch . '/state/sidebars/main.json');
}
$openBody = $block(['extension' => ['entity' => 1]]);
Canon::write_file($post, Canon::post_file($front, $openBody));
wprism_check_same($openBody, Blocks::capture_rewrite($openBody, $openPolicy, $source), 'legacy capture retains the established open behavior');
wprism_check_same(1, count(RepositoryCompiler::compile($scratch, $openPolicy)->tree()), 'legacy immutable compilation remains compatible');
$nested = '<!-- wp:group --><div>' . $block(['extension' => 1]) . '</div><!-- /wp:group -->';
wprism_check_throws(static fn() => Blocks::capture_rewrite($nested, $policy, $source), RuntimeException::class, 'capture enforces closure inside nested core containers');
$findings = BlockReferenceScanner::scan(parse_blocks($nested), $rules, 'first.md', 'https://source.test', static fn(): ?array => null);
wprism_check_same(['undeclared_block_attribute'], array_column($findings, 'class'), 'lint enforces the same nested closure');
Canon::write_file($post, Canon::post_file($front, $canonical));
Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode(['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333',
    'type' => 'block', 'settings' => ['content' => $canonical]]]]));
wprism_check_same(2, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'known post and widget content compile together under the same closed roster');
$grouped = $closed;
$grouped['engine_features'][] = BlockValueGrammar::GROUP_FEATURE;
sort($grouped['engine_features'], SORT_STRING);
$grouped['block_values'] = ['groups' => [['blocks' => ['fixture/one', 'fixture/two'], 'attributes' => ['text'], 'value' => $plain]]];
wprism_check_same($rules, $load($grouped)->block_attr_rules(), 'closure consumes compact groups through the existing normalization');
$large = $closed;
unset($large['block_attrs']);
$large['block_values'] = [];
$large[BlockValueGrammar::CLOSURE_FIELD] = [];
foreach (range(0, 4095) as $i) {
    $name = 'fixture/b' . sprintf('%04d', $i);
    $large['block_values'][$name] = ['text' => $plain];
    $large[BlockValueGrammar::CLOSURE_FIELD][] = $name;
}
wprism_check_same(4096, count($load($large)->block_attr_rules()), 'complete maximum-size closure roster is admitted');

$process = proc_open([PHP_BINARY, __DIR__ . '/regress_block_attribute_values.php', '--closed-attributes'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot execute the closed-roster product proof');
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);
wprism_check_same(0, proc_close($process), 'closed roster passes the complete compiler/SQL/rollback/retry/post/widget proof');
wprism_check_same('', $stderr, 'closed roster product proof emits no diagnostics');
wprism_check(str_contains($output, 'full native post recapture is canonical byte equality'), 'reused proof reaches product recapture');
wprism_check_summary('block attribute closure');
