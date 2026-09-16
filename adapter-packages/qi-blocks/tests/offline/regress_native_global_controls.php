<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Grammar/Tokens.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Review/Lint.php";
require_once dirname(__DIR__, 2) . '/fixtures/native-global-controls/corpus.php';
wprism_test_define_agent_versions();

use WPrism\{BlockReferenceScanner, Blocks, Canon, Lint, LintEnvironment, RepositoryCompiler, Tokens};
use WPrismTest\{FakeWpdb, FrozenPolicy};

$capsule = dirname(__DIR__, 2);
$bytes = Canon::read_file($capsule . '/fixtures/native-global-controls/observations.json');
wprism_check_same('3e1104098f4412cf43fc5977fdf6704f5d011ca36e1aa3b2163e8aec580d48c2', hash('sha256', $bytes),
    'native WordPress global-control fragments retain their independently observed saved bytes');
$record = Canon::decode($bytes);
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$policy = FrozenPolicy::policy([$core, $manifest], FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION));
$source = new Tokens('http://localhost:9508', 'http://localhost:9508/wp-content/uploads');
$target = new Tokens('https://target.example.test', 'https://target.example.test/wp-content/uploads');
$uuid = '11111111-1111-4111-8111-111111111111';
$database = static fn(int $id) => FakeWpdb::install()->seedTable('wp_wprism_map', [
    ['uuid' => $uuid, 'entity_type' => 'post:attachment', 'id_kind' => 'post', 'local_id' => $id],
]);
$scratch = sys_get_temp_dir() . '/wprism-qi-global-controls-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/posts/attachment', 0700);
mkdir($scratch . '/state/sidebars', 0700);
mkdir($scratch . '/media', 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path);
    return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-16 00:00:00', 'date_gmt' => '2026-09-16 00:00:00',
    'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [], 'modified' => '2026-09-16 00:00:00', 'modified_gmt' => '2026-09-16 00:00:00',
    'parent' => null, 'ping_status' => 'closed', 'slug' => 'globals', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Globals', 'type' => 'page', 'uuid' => '22222222-2222-4222-8222-222222222222'];
$post = $scratch . '/state/posts/page/' . $front['uuid'] . '--globals.md';
$widget = $scratch . '/state/sidebars/main.json';
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($policy->site));
// Full compilation must retain the referenced image owner; omitting it
// correctly trips semantic_delete_reference before this value-shape proof.
$imageBytes = Canon::read_file($capsule . '/fixtures/native-media/original.png');
$blob = hash('sha256', $imageBytes) . '.png';
Canon::write_file($scratch . '/media/' . $blob, $imageBytes);
$image = array_replace($front, ['uuid' => $uuid, 'type' => 'attachment', 'slug' => 'image', 'title' => 'Image', 'status' => 'inherit',
    'file' => '2026/09/tmp-qi-image.png', 'media' => $blob, 'mime' => 'image/png', 'alt' => '']);
Canon::write_file($scratch . '/state/posts/attachment/' . $uuid . '--image.md', Canon::post_file($image, ''));
$lint = static function (bool $parser) use ($scratch, $policy): array {
    $env = LintEnvironment::recorded(['format' => LintEnvironment::FORMAT, 'home' => 'http://localhost:9508',
        'entities' => [], 'column_types' => [], 'probe_hash' => null, 'scanned' => ['blocks' => $parser, 'shortcodes' => false],
        'state_hash' => LintEnvironment::state_hash($scratch . '/state')]);
    return Lint::scan_tree($scratch . '/state', $policy, $env);
};
$empty = '<!-- wp:qi-blocks/single-image /-->';
$block = static fn(array $attrs): string => '<!-- wp:qi-blocks/single-image ' . serialize_block_attributes($attrs) . ' /-->';
$states = [];
$original = parse_blocks(Canon::read_file($capsule . '/fixtures/native-blocks.html'));
foreach ($record['cases'] as $case) {
    $native = array_values(array_filter(parse_blocks($case['saved']), static fn(array $block): bool => $block['blockName'] !== null));
    wprism_check_same(1, count($native), 'each native global-control fixture has one complete selected owner');
    wprism_check_same('qi-blocks/single-image', $native[0]['blockName'], 'global-control fixture remains a real Qi block');
    wprism_check_same($case['lock'], $native[0]['attrs']['lock'], 'native lock readback preserves both strict boolean members');
    wprism_check_same($case['metadata'], $native[0]['attrs']['metadata'] ?? null, 'native metadata observations retain their complete active or absent shape');
    $states[Canon::encode($case['lock'])] = true;
    $replayed = parse_blocks(QiNativeGlobalControlsCorpus::saved(serialize_blocks($original), $bytes, $case['case']));
    wprism_check_same(count($original), count($replayed), 'retained native global replay preserves the complete original corpus census');
    $other = $original;
    $otherAfter = $replayed;
    wprism_check_same($case['lock'], $replayed[0]['attrs']['lock'], 'full-corpus replay retains the independently observed global lock shape');
    wprism_check_same($case['metadata'], $replayed[0]['attrs']['metadata'] ?? null, 'full-corpus replay retains native metadata shape and absence');
    unset($other[0], $otherAfter[0]);
    wprism_check_same($other, $otherAfter, 'replay changes only the explicitly selected native Single Image owner');
    $database(1);
    $canonical = Blocks::capture_rewrite($case['saved'], $policy, $source);
    $database(801);
    $applied = Blocks::apply_rewrite($canonical, $policy, $target);
    $after = array_values(array_filter(parse_blocks($applied), static fn(array $block): bool => $block['blockName'] !== null))[0];
    wprism_check_same($case['lock'], $after['attrs']['lock'], 'global-control transport preserves both native lock flags without coercion');
    wprism_check_same($case['metadata'], $after['attrs']['metadata'] ?? null, 'rename and visibility retain their exact active or absent shapes');
    wprism_check_same(801, $after['attrs']['image']['id'], 'native global controls compose with ordinary divergent media identity');
    wprism_check(str_contains($applied, 'wp-image-801') && !str_contains($applied, 'http://localhost:9508'), 'complete native saved HTML and image URLs use the target coordinates');
    wprism_check_same($canonical, Blocks::capture_rewrite($applied, $policy, $target), 'each complete observed native global-control fragment has an exact canonical fixed point');
    Canon::write_file($post, Canon::post_file($front, $canonical));
    Canon::write_file($widget, Canon::encode(['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333',
        'type' => 'block', 'settings' => ['content' => $canonical]]]]));
    $queries = $GLOBALS['wpdb']->queries();
    wprism_check_same(3, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'the actual saved global-control fragment compiles as both post and block-widget content with its referenced image owner');
    foreach ([true, false] as $parser) {
        wprism_check_same([], $lint($parser), 'public post/widget review admits the canonical observed shapes with ' . ($parser ? 'native parsing' : 'parser-free shape validation'));
    }
    wprism_check_same($queries, $GLOBALS['wpdb']->queries(), 'canonical global-control compilation and review never query the target');
    unlink($widget);
}
wprism_check_same(4, count($states), 'native saves independently observed all four movement/removal lock states');

// The first actual target Save removes four LF bytes at two independently
// retained locations. Freeze the whole writer body instead of teaching any
// runtime or oracle to normalize whitespace (target observations: first_save_serialization).
$targetBytes = Canon::read_file($capsule . '/fixtures/native-global-controls/target-observations.json');
wprism_check_same('24633ceb15ad04ec48e1b4894f366cfbda1acf5f17f019d0dcfdc84cb5f649e2', hash('sha256', $targetBytes), 'complete target browser observations retain their independent bytes');
$targetRecord = Canon::decode($targetBytes);
$firstSave = Canon::read_file($capsule . '/fixtures/native-global-controls/target-first-save.html');
wprism_check_same('227e9797f6f2d6c71e97714cf14f3920f2618cc39862d92f153c081e560de961', hash('sha256', $firstSave), 'complete first target editor Save retains its observed content.raw hash');
$beforeSave = QiConformanceCorpus::applied_body(QiNativeGlobalControlsCorpus::saved(Canon::read_file($capsule . '/fixtures/native-blocks.html'), $bytes, 'global-lock-rename'),
    $targetRecord['ids'], $targetRecord['target_home'], $targetRecord['target_home'] . '/wp-content/uploads/2026/09/tmp-qi-image.png');
wprism_check_same($targetRecord['first_save_serialization']['before_sha256'], hash('sha256', $beforeSave), 'independent source replay still supplies the exact complete pre-editor body');
$deletions = $targetRecord['first_save_serialization']['deletions'];
wprism_check_same([['offset' => 19426, 'bytes_hex' => '0a0a'], ['offset' => 55604, 'bytes_hex' => '0a0a']], $deletions, 'only two exact double-LF deletions were observed on first Save');
foreach (array_reverse($deletions) as $deletion) {
    wprism_check_same("\n\n", substr($beforeSave, $deletion['offset'], 2), 'observed native serialization deletion has its exact independently retained LF premise');
    $beforeSave = substr_replace($beforeSave, '', $deletion['offset'], 2);
}
wprism_check_same($firstSave, $beforeSave, 'first native writer changed no other byte outside the independently observed global owner');
wprism_check_same(12, count($targetRecord['cases']), 'target browser coverage retains all seven source cases plus individual, combined and reset viewport controls');
$baselineOwner = $targetRecord['cases'][0]['saved'];
$targetSource = new Tokens($targetRecord['target_home'], $targetRecord['target_home'] . '/wp-content/uploads');
$extraMetadata = ['global-tablet-hidden' => ['blockVisibility' => ['viewport' => ['tablet' => false]]],
    'global-desktop-hidden' => ['blockVisibility' => ['viewport' => ['desktop' => false]]],
    'global-mobile-only' => ['blockVisibility' => ['viewport' => ['mobile' => false]]],
    'global-all-viewports-hidden' => ['blockVisibility' => ['viewport' => ['desktop' => false, 'tablet' => false, 'mobile' => false]]],
    'global-reset-final' => null];
$sourceCases = QiNativeGlobalControlsCorpus::cases($bytes);
foreach ($targetRecord['cases'] as $case) {
    $body = str_replace($baselineOwner, $case['saved'], $firstSave, $replacements);
    wprism_check_same(1, $replacements, 'whole native target case changes exactly one selected owner');
    wprism_check_same($case['body_sha256'], hash('sha256', $body), 'complete native case reconstructs its observed whole-body hash without filtering');
    wprism_check_same($case['body_bytes'], strlen($body), 'complete native target case retains every saved byte');
    wprism_check_same(50, count(QiConformanceCorpus::block_names($body)), 'every target global Save retains all 50 standalone Qi instances');
    $expectedMetadata = $sourceCases[$case['case']]['metadata'] ?? $extraMetadata[$case['case']] ?? null;
    wprism_check_same($expectedMetadata, $case['metadata'], 'target native metadata matches independent source observations or the exact additional modal checkbox state');
    $expectedLock = $sourceCases[$case['case']]['lock'] ?? ['move' => false, 'remove' => false];
    wprism_check_same($expectedLock, $case['lock'], 'all target lock flags retain their independent native meaning');
    if (isset($sourceCases[$case['case']])) {
        $independent = str_replace('http://localhost:9508', $targetRecord['target_home'], $sourceCases[$case['case']]['saved']);
        $independent = str_replace(['"id":1,', 'wp-image-1"'], ['"id":9,', 'wp-image-9"'], $independent);
        wprism_check_same($independent, $case['saved'], 'complete target native saved owner equals the independent source writer after only its proven image coordinates rebind');
    }
    $database(9);
    $canonical = Blocks::capture_rewrite($case['saved'], $policy, $targetSource);
    $database(801);
    $applied = Blocks::apply_rewrite($canonical, $policy, $target);
    $after = parse_blocks($applied)[0];
    wprism_check_same($expectedMetadata, $after['attrs']['metadata'] ?? null, 'actual target browser visibility/rename shapes survive generic transport');
    wprism_check_same($expectedLock, $after['attrs']['lock'], 'actual target browser lock shapes survive generic transport');
    wprism_check_same($canonical, Blocks::capture_rewrite($applied, $policy, $target), 'every complete actual target fragment has an exact portable fixed point');
    Canon::write_file($post, Canon::post_file($front, $canonical));
    Canon::write_file($widget, Canon::encode(['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333', 'type' => 'block', 'settings' => ['content' => $canonical]]]]));
    wprism_check_same(3, count(RepositoryCompiler::compile($scratch, $policy)->tree()), 'complete actual target fragment compiles through post and widget product paths with its image owner');
    foreach ([true, false] as $parser) wprism_check_same([], $lint($parser), 'target browser fragments pass public review with and without native parsing');
    unlink($widget);
    $rows = [];
    foreach ($targetRecord['ids'] as $role => $id) $rows[] = ['uuid' => $role === 'image' ? $uuid : '44444444-4444-4444-8444-' . sprintf('%012d', $id),
        'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id];
    FakeWpdb::install()->seedTable('wp_wprism_map', $rows);
    $fullCanonical = Blocks::capture_rewrite($body, $policy, $targetSource);
    foreach ($rows as &$row) $row['local_id'] += 800;
    unset($row);
    FakeWpdb::install()->seedTable('wp_wprism_map', $rows);
    $fullApplied = Blocks::apply_rewrite($fullCanonical, $policy, $target);
    wprism_check_same(50, count(QiConformanceCorpus::block_names($fullApplied)), 'whole target writer body retains every Qi instance through generic transport');
    wprism_check_same($fullCanonical, Blocks::capture_rewrite($fullApplied, $policy, $target), 'complete target writer corpus reaches an exact canonical fixed point with all four identity coordinates divergent');
}
wprism_check_same(7, count($targetRecord['observed_writers']), 'only the seven complete observed native REST response windows provide HTTP claims');
foreach ($targetRecord['observed_writers'] as $writer) {
    $case = array_values(array_filter($targetRecord['cases'], static fn(array $case): bool => $case['case'] === $writer['case']))[0];
    wprism_check_same(200, $writer['status'], 'observed actual target editor Save succeeded');
    wprism_check_same($case['body_sha256'], $writer['content_raw_sha256'], 'complete observed REST content matches independently captured native storage');
}
foreach ($targetRecord['computed_visibility'] as $measurement) {
    $case = array_values(array_filter($targetRecord['cases'], static fn(array $case): bool => $case['case'] === $measurement['case']))[0];
    $omitted = ($case['metadata']['blockVisibility'] ?? null) === false;
    wprism_check_same($omitted ? 0 : 1, $measurement['owner_count'], 'native omission differs from viewport hiding: retained markup stays present for responsive rules');
    wprism_check_same($omitted ? 0 : 1, $measurement['image_count'], 'every retained frontend owner keeps exactly one selected image');
    if ($omitted || $measurement['viewport'] === null) continue;
    $width = $measurement['viewport'][0];
    $viewport = $width <= 480 ? 'mobile' : ($width <= 782 ? 'tablet' : 'desktop');
    $hidden = ($case['metadata']['blockVisibility']['viewport'][$viewport] ?? null) === false;
    wprism_check_same($hidden ? 'none' : 'inline-block', $measurement['display'], 'computed target visibility agrees with the actual native WordPress CSS breakpoints');
    wprism_check_same($hidden ? 0 : 1, $measurement['rect_count'], 'hidden owners have no layout rectangles while visible owners retain theirs');
}
wprism_check_throws(static fn() => QiNativeGlobalControlsCorpus::saved(serialize_blocks($original), $bytes . ' ', $record['cases'][0]['case']), RuntimeException::class, 'replay refuses changed independent observation bytes');
wprism_check_throws(static fn() => QiNativeGlobalControlsCorpus::saved(serialize_blocks($original), $bytes, 'global-unknown'), RuntimeException::class, 'replay refuses an invented native case');
wprism_check_throws(static fn() => QiNativeGlobalControlsCorpus::saved(serialize_blocks(array_slice($original, 1)), $bytes, $record['cases'][0]['case']), RuntimeException::class, 'replay refuses a missing original native owner');
wprism_check_throws(static fn() => QiNativeGlobalControlsCorpus::saved(serialize_blocks([...$original, $original[0]]), $bytes, $record['cases'][0]['case']), RuntimeException::class, 'replay refuses a duplicated original native owner');
$valid = $block(['metadata' => ['name' => 'http://localhost:9508/renamed', 'blockVisibility' => false], 'lock' => ['move' => false]]);
$database(1);
$captured = Blocks::capture_rewrite($valid, $policy, $source);
wprism_check(str_contains($captured, '{{home}}/renamed'), 'strict scalar names still receive existing text and privacy processing');
wprism_check_same($captured, Blocks::capture_rewrite(Blocks::apply_rewrite($captured, $policy, $target), $policy, $target), 'partial native lock members and strict scalar text retain existing codec composition');

// Native reset removes metadata; neither a reference-blind empty-object
// allowance nor another extension's bindings/notes/pattern IDs is reviewed.
$invalid = [];
foreach ([false, null, [], ['move' => 1], ['remove' => 'false'], ['move' => ['id' => 1]], ['extension' => 1]] as $value) $invalid[] = ['lock' => $value];
foreach ([false, null, [], ['name' => 1], ['name' => false], ['name' => null], ['name' => ['entity' => 1]],
    ['bindings' => ['entity' => 1]], ['notes' => '{{post:' . $uuid . '}}'], ['patternIds' => [1]],
    ['blockVisibility' => true], ['blockVisibility' => 0], ['blockVisibility' => 'false'], ['blockVisibility' => []],
    ['blockVisibility' => ['viewport' => []]], ['blockVisibility' => ['viewport' => ['mobile' => true]]],
    ['blockVisibility' => ['viewport' => ['mobile' => 0]]], ['blockVisibility' => ['viewport' => ['mobile' => 'false']]],
    ['blockVisibility' => ['viewport' => ['watch' => false]]], ['blockVisibility' => ['entity' => 1]]] as $value) $invalid[] = ['metadata' => $value];
$queries = $GLOBALS['wpdb']->queries();
foreach ($invalid as $attrs) {
    $body = $block($attrs);
    foreach (['capture' => static fn() => Blocks::capture_rewrite($body, $policy, $source),
        'apply' => static fn() => Blocks::apply_rewrite($body, $policy, $target)] as $verb => $run) {
        wprism_check_throws($run, RuntimeException::class, 'direct Qi ' . $verb . ' refuses wrong types, unknown metadata and unreviewed empty containers');
    }
    $findings = BlockReferenceScanner::scan_closed_document($body, $policy->block_attr_rules(), 'globals.md');
    wprism_check_same(['invalid_block_value'], array_column($findings, 'class'), 'pure Qi review validates the complete present global value');
    wprism_check(!str_contains(Canon::encode($findings), 'bindings') && !str_contains(Canon::encode($findings), $uuid), 'global-control findings display neither unknown metadata names nor hostile values');
    foreach (['post', 'widget'] as $surface) {
        Canon::write_file($post, Canon::post_file($front, $surface === 'post' ? $body : $empty));
        if ($surface === 'widget') Canon::write_file($widget, Canon::encode(['widgets' => [['uuid' => '33333333-3333-4333-8333-333333333333',
            'type' => 'block', 'settings' => ['content' => $body]]]]));
        $before = Canon::read_file($surface === 'post' ? $post : $widget);
        wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), WPrism\RepositoryCompilationException::class,
            'immutable Qi ' . $surface . ' compilation refuses an invalid global before materialization');
        foreach ([true, false] as $parser) wprism_check_same(['invalid_block_value'], array_column($lint($parser), 'class'),
            'public Qi ' . $surface . ' review reports one global failure with or without the native parser');
        wprism_check_same($before, Canon::read_file($surface === 'post' ? $post : $widget), 'global compilation/review refusal preserves the exact authored input');
        if (file_exists($widget)) unlink($widget);
    }
}
wprism_check_same($queries, $GLOBALS['wpdb']->queries(), 'invalid globals refuse capture, apply, compilation and review before any database access');
foreach (['<!-- wp:qi-blocks/single-image {"metadata":broken} /-->', '<!-- wp:qi-blocks/single-image {"lock":false}'] as $broken) {
    wprism_check_throws(static fn() => Blocks::capture_rewrite($broken, $policy, $source), RuntimeException::class, 'Qi global Capture refuses original corrupt comment framing');
    wprism_check_throws(static fn() => Blocks::apply_rewrite($broken, $policy, $target), RuntimeException::class, 'Qi global Apply refuses original corrupt comment framing');
    wprism_check_same(['invalid_block_attributes'], array_column(BlockReferenceScanner::scan_closed_document($broken, $policy->block_attr_rules(), 'globals.md'), 'class'), 'pure global review cannot lose corrupt comments through WordPress parsing');
}
wprism_check_summary('Qi native global control boundaries');
