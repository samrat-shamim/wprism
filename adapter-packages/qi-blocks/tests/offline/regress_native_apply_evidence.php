<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4); $capsule = dirname(__DIR__, 2);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) require_once "$root/sandbox/tests/lib/$file";
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$capsule/fixtures/native-apply/evidence.php";
wprism_test_define_agent_versions();

use WPrism\{Blocks, Canon, Tokens};
use WPrismTest\{FakeWpdb, FrozenPolicy};

// Admission controls use small native-row doubles plus the existing full saved
// corpus. They do not count as native execution or manufacture a live receipt.
$saved = file_get_contents($capsule . '/fixtures/native-blocks.html');
$styleFixture = file_get_contents($capsule . '/fixtures/native-options.json');
$sourceIds = ['image' => 8, 'first' => 9, 'second' => 10, 'page' => 13];
$targetIds = array_map(static fn(int $id): int => $id + 100, $sourceIds);
$uuids = array_map(static fn(int $id): string => '11111111-1111-4111-8111-' . sprintf('%012d', $id), $sourceIds);
$seed = ['ids' => $sourceIds, 'home' => 'https://source.example.test', 'image_url' => 'https://source.example.test/wp-content/uploads/2026/09/tmp-qi-image.png'];
$row = static fn(int $id, string $type, string $slug, string $body = ''): array => ['ID' => (string) $id, 'post_type' => $type,
    'post_name' => $slug, 'post_status' => $type === 'attachment' ? 'inherit' : 'publish', 'post_content' => $body];
$trash = [];
foreach (range(20, 27) as $id) $trash[] = array_replace($row($id, 'post', 'local-' . $id, 'Retained native row ' . $id), ['post_status' => 'trash']);
$beforeOptions = [
    ['option_name' => 'qi_blocks_cropped_images', 'option_value' => 'a:0:{}', 'autoload' => 'auto'],
    ['option_name' => 'qi_blocks_custom_templates_flag', 'option_value' => 'target-local-qi-probe', 'autoload' => 'auto'],
    ['option_name' => 'qi_blocks_global_styles', 'option_value' => serialize(['posts' => [], 'widgets' => [], 'templates' => [], 'undefined' => []]), 'autoload' => 'auto'],
];
$before = ['format' => 'wprism-qi-native-apply/v1', 'home' => 'https://target.example.test', 'ids' => [], 'uuids' => [],
    'featured' => [], 'attachment' => null, 'page_body' => null, 'posts' => $trash, 'options' => $beforeOptions, 'uploads' => []];
$sourceBody = QiConformanceCorpus::body($saved, $sourceIds, $seed['home'], $seed['image_url']);
$manifests = [Canon::decode(file_get_contents($root . '/platform/adapter-library/core/manifest.json')), Canon::decode(file_get_contents($capsule . '/package/manifest.json'))];
$site = FrozenPolicy::site($manifests, WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['post', 'page', 'attachment']; $site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy($manifests, $site);
$database = static function (array $ids) use ($uuids): void {
    $rows = [];
    foreach ($ids as $role => $id) $rows[] = ['uuid' => $uuids[$role], 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $id];
    FakeWpdb::install()->seedTable('wp_wprism_map', $rows);
};
$database($sourceIds);
$canonical = Blocks::capture_rewrite($sourceBody, $policy, new Tokens($seed['home'], $seed['home'] . '/wp-content/uploads'));
$database($targetIds);
$targetBody = Blocks::apply_rewrite($canonical, $policy, new Tokens($before['home'], $before['home'] . '/wp-content/uploads'));
wprism_check_same(QiConformanceCorpus::applied_body($saved, $targetIds, $before['home'], str_replace($seed['home'], $before['home'], $seed['image_url'])),
    $targetBody, 'independent Apply expectation equals complete product block materialization');
$records = [];
foreach (['source' => $sourceIds, 'target' => $targetIds] as $side => $ids) {
    $home = $side === 'source' ? $seed['home'] : $before['home'];
    $body = $side === 'source' ? $sourceBody : $targetBody;
    $image = str_replace($seed['home'], $home, $seed['image_url']);
    $styles = serialize(QiConformanceCorpus::global_styles(QiConformanceCorpus::styles($styleFixture, $body, $ids, $home, $image), $ids['page']));
    $posts = $side === 'target' ? $trash : [];
    foreach (['image' => ['attachment', 'qi-conformance-image'], 'first' => ['post', 'qi-first-source-post'],
        'second' => ['post', 'qi-second-source-post'], 'page' => ['page', 'qi-native-corpus']] as $role => [$type, $slug]) $posts[] = $row($ids[$role], $type, $slug, $role === 'page' ? $body : '');
    $options = $beforeOptions; $options[2]['option_value'] = $styles;
    $file = '2026/09/tmp-qi-image.png'; $files = []; $sizes = [];
    foreach ([[1200, 800], [1024, 683], [1200, 650], [150, 150], [300, 200], [650, 650], [650, 800], [768, 512]] as $index => [$width, $height]) {
        $path = $index === 0 ? $file : '2026/09/tmp-qi-image-' . $width . 'x' . $height . '.png';
        $files[$path] = ['sha256' => hash('sha256', $path), 'bytes' => 100 + $index, 'width' => $width, 'height' => $height, 'mime' => 'image/png'];
        if ($index > 0) $sizes['size-' . $index] = ['file' => basename($path), 'width' => $width, 'height' => $height, 'mime-type' => 'image/png'];
    }
    if ($side === 'source') $files['2026/09/tmp-qi-image-333x211.png'] = ['sha256' => hash('sha256', 'unselected crop'), 'bytes' => 800, 'width' => 333, 'height' => 211, 'mime' => 'image/png'];
    ksort($files, SORT_STRING);
    $records[$side] = ['format' => 'wprism-qi-native-apply/v1', 'home' => $home, 'ids' => $ids, 'uuids' => $uuids,
        'featured' => ['first' => $ids['image'], 'second' => $ids['image']], 'page_body' => $body, 'styles' => $styles,
        'posts' => $posts, 'options' => $options, 'uploads' => $files, 'attachment' => ['id' => $ids['image'], 'url' => $image, 'file' => $file,
            'metadata' => ['file' => $file, 'width' => 1200, 'height' => 800, 'sizes' => $sizes]]];
}
$admit = static fn(array $source, array $target, array $initial = []) => QiNativeApplyEvidence::native($source, $target, $initial === [] ? $before : $initial, $seed, $saved, $styleFixture);
$admit($records['source'], $records['target']);
wprism_check(true, 'complete native evidence admits divergent IDs, full corpus, preserved target witnesses and unselected crop exclusion');
$faults = [
    'same target identity' => static function (array &$r): void { $r['ids']['image'] = 8; },
    'changed durable identity' => static function (array &$r): void { $r['uuids']['page'] = $r['uuids']['image']; },
    'altered physical body' => static function (array &$r): void { $r['posts'][11]['post_content'] .= 'changed'; },
    'missing block content' => static function (array &$r): void { $r['page_body'] = ''; $r['posts'][11]['post_content'] = ''; },
    'modified local trash' => static function (array &$r): void { $r['posts'][0]['post_content'] .= 'changed'; },
    'extra physical row' => static function (array &$r): void { $r['posts'][] = $r['posts'][0]; },
    'missing physical row' => static function (array &$r): void { array_pop($r['posts']); },
    'wrong featured image' => static function (array &$r): void { $r['featured']['first'] = 8; },
    'runtime crop cache copied' => static function (array &$r): void { $r['options'][0]['option_value'] = 'copied'; },
    'runtime autoload changed' => static function (array &$r): void { $r['options'][1]['autoload'] = 'off'; },
    'extra Qi option' => static function (array &$r): void { $r['options'][] = ['option_name' => 'qi_blocks_new_state', 'option_value' => 'unproved', 'autoload' => 'auto']; },
    'lost CSS container roots' => static function (array &$r): void {
        $styles = WPrism\PhpContainerValue::restore(WPrism\PhpContainerValue::capture_raw($r['styles'], 'test'), 'test');
        unset($styles['widgets']); $r['styles'] = serialize($styles);
    },
    'wrong CSS page frames' => static function (array &$r): void { $r['styles'] = str_replace('-113', '-999', $r['styles']); },
    'original attachment URL' => static function (array &$r): void { $r['attachment']['url'] = str_replace('target', 'source', $r['attachment']['url']); },
    'changed native metadata' => static function (array &$r): void { $r['attachment']['metadata']['width'] = 999; },
    'missing image file' => static function (array &$r): void { unset($r['uploads']['2026/09/tmp-qi-image.png']); },
    'changed image bytes' => static function (array &$r): void { $r['uploads']['2026/09/tmp-qi-image.png']['sha256'] = str_repeat('f', 64); },
    'extra image file' => static function (array &$r): void { $r['uploads']['extra.png'] = $r['uploads']['2026/09/tmp-qi-image.png']; },
    'copied unselected crop' => static function (array &$r) use ($records): void { $r['uploads']['2026/09/tmp-qi-image-333x211.png'] = $records['source']['uploads']['2026/09/tmp-qi-image-333x211.png']; },
];
foreach ($faults as $label => $mutate) {
    $target = $records['target']; $mutate($target);
    wprism_check_throws(static fn() => $admit($records['source'], $target), RuntimeException::class, 'native evidence rejects ' . $label);
}
$initial = $before; array_pop($initial['posts']);
wprism_check_throws(static fn() => $admit($records['source'], $records['target'], $initial), RuntimeException::class, 'evidence refuses an incomplete target preimage');

$plan = ['create' => array_map(static fn(string $uuid): array => ['uuid' => $uuid], array_values($uuids)), 'update' => [[], []],
    'adopt' => [['type' => 'term', 'env_id' => 1, 'title' => 'Uncategorized', 'uuid' => '22222222-2222-4222-8222-222222222222', 'path' => 'terms/category/native.json']],
    'adapter_dispositions' => [['code' => 'authored_state_not_certified']]];
foreach (['warnings', 'provider_problems', 'drift', 'conflict', 'collision', 'delete', 'delete_conflict', 'deleted', 'code_mismatch',
    'code_drift', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user', 'skipped_user_meta', 'selected_actions', 'regen_pending', 'regen_context', 'env_missing'] as $field) $plan[$field] = [];
$apply = ['applied' => 7, 'warnings' => ['adopted env term 1 as 22222222-2222-4222-8222-222222222222 (terms/category/native.json)'],
    'canary' => 'clean', 'drift' => [], 'actions' => [], 'verification' => ['verifier' => 'canonical-recapture/v1', 'result' => 'pass', 'live_entities' => 7, 'deletions' => 0, 'skipped_user_meta' => 0]];
$repeat = array_replace($apply, ['applied' => 0, 'warnings' => []]);
$captures = array_fill(0, 5, ['counts' => ['post' => 4, 'term' => 1, 'menu' => 0, 'sidebar' => 1, 'options' => 1, 'deletion' => 0],
    'media' => 1, 'notes' => [], 'warnings' => [], 'initial_code_baseline' => null]);
$productInputs = [$plan, $apply, $repeat, $captures];
$product = static fn(array $inputs) => QiNativeApplyEvidence::product(...[...$inputs, $records['source']]);
$product($productInputs);
wprism_check(true, 'receipt admission requires exact scope, diagnostics, product verifier and zero-write repeat');
foreach ([
    'unexpected plan action' => static function (array &$r): void { $r[0]['selected_actions'][] = ['unproved']; },
    'masked experimental boundary' => static function (array &$r): void { $r[0]['adapter_dispositions'] = []; },
    'ignored Apply diagnostic' => static function (array &$r): void { $r[1]['warnings'][] = 'unproved warning'; },
    'unclean canary' => static function (array &$r): void { $r[1]['canary'] = 'dirty'; },
    'failed native verifier' => static function (array &$r): void { $r[1]['verification']['result'] = 'fail'; },
    'partial native verification' => static function (array &$r): void { $r[1]['verification']['live_entities'] = 6; },
    'repeat writes' => static function (array &$r): void { $r[2]['applied'] = 1; },
    'missing media capture' => static function (array &$r): void { $r[3][2]['media'] = 0; },
    'missing capture stage' => static function (array &$r): void { array_pop($r[3]); },
] as $label => $mutate) {
    $bad = $productInputs; $mutate($bad);
    wprism_check_throws(static fn() => $product($bad), RuntimeException::class, 'product receipt rejects ' . $label);
}

$html = static fn(int $id, string $home): string => '<html><head><style id="qi-blocks-main-inline-css">'
    . str_repeat('body[class*="-' . $id . '"] .qodef-block{background-image:url(' . $home . '/image.png);color:red}', 204)
    . '</style></head><body class="page page-id-' . $id . '"></body></html>';
$sourceHtml = $html(13, $seed['home']); $targetHtml = $html(113, $before['home']);
$css = static fn(string $target) => QiNativeApplyEvidence::css($sourceHtml, $target, $records['source'], $records['target']);
$css($targetHtml);
wprism_check(true, 'HTTP admission compares all frames and the full independently rebound stylesheet');
foreach (['wrong page' => $html(13, $before['home']), 'missing CSS' => '<html><body></body></html>',
    'duplicate stylesheet' => str_replace('</head>', '<style id="qi-blocks-main-inline-css"></style></head>', $targetHtml),
    'altered declaration' => str_replace('color:red', 'color:blue', $targetHtml),
    'unmatched body' => str_replace('page-id-113', 'page-id-114', $targetHtml),
    'missing frame' => str_replace('body[class*="-113"] .qodef-block', '.qodef-block', $targetHtml),
    'unexpected crop consumer' => str_replace('</body>', '<img src="tmp-qi-image-333x211.png"></body>', $targetHtml)] as $label => $bad) {
    wprism_check_throws(static fn() => $css($bad), RuntimeException::class, 'HTTP evidence rejects ' . $label);
}

$scratch = sys_get_temp_dir() . '/wprism-qi-apply-evidence-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$write = static function (string $name, mixed $value) use ($scratch): string {
    $path = $scratch . '/' . $name;
    file_put_contents($path, is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR));
    return $path;
};
$runAdmission = static function (array $arguments): array {
    $process = proc_open($arguments, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('cannot execute Qi roundtrip admission');
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
};
$evidenceCli = $capsule . '/fixtures/native-apply/evidence.php';
[$exit, $output, $diagnostics] = $runAdmission([PHP_BINARY, $evidenceCli, '--admit-roundtrip-native',
    $write('source.json', $records['source']), $write('target.json', $records['target']), $write('before.json', $before),
    $write('seed.json', $seed), $capsule . '/fixtures/native-blocks.html', $capsule . '/fixtures/native-options.json']);
wprism_check($exit === 0 && $diagnostics === '' && json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'] === 'pass',
    'shared conformance native admission boots its block parser and accepts the complete independent postimage');
[$exit, $output, $diagnostics] = $runAdmission([PHP_BINARY, $evidenceCli, '--admit-roundtrip-fixed-point',
    $write('apply.json', $apply), $write('repeat.json', $repeat), $write('source.html', $sourceHtml), $write('target.html', $targetHtml),
    $scratch . '/source.json', $scratch . '/target.json', $scratch . '/target.json']);
wprism_check($exit === 0 && $diagnostics === '' && json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'] === 'pass',
    'shared conformance fixed-point admission accepts repeat Apply, native stability and complete HTTP CSS');
$streams = static function (string $stdout, string $stderr = '', string $exit = "0\n") use ($scratch): void {
    foreach (['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit] as $suffix => $value) {
        file_put_contents($scratch . '/native.' . $suffix, $value); chmod($scratch . '/native.' . $suffix, 0600);
    }
};
$read = static fn(): array => QiNativeApplyEvidence::object($scratch . '/native', 'qitest', $root, 'capture');
$streams('{"result":"ok"}', " Container wprism-qitest-cli1-run-a1b2c3 Created \nprivate command diagnostics (unverified): $root/sandbox/tmp/wprism-conformance-capture.qitest.abc123\n");
wprism_check_same(['result' => 'ok'], $read(), 'native transport admits only exact selected compose and private-pointer diagnostics');
foreach ([['{"result":"ok"}', 'unexpected diagnostic'], ['{"result":"ok"}', '', "1\n"],
    ["PHP Warning: fake fixture\n{\"result\":\"ok\"}"], ['[]'], ['{}{}']] as $index => $case) {
    $streams(...$case);
    wprism_check_throws($read, Throwable::class, 'native transport refuses malformed or failed stream ' . $index);
}

// Execute the runner's actual snapshot body with distinct per-site catalogs.
// This pin fails if a target snapshot accidentally copies source/media again.
$runner = file_get_contents($capsule . '/tests/live/regress_native_apply.sh');
wprism_check_same(1, preg_match('/snapshot\(\) \{\n.*?\n\}/s', $runner, $match), 'live runner has one testable snapshot operation');
foreach (['source', 'target'] as $side) {
    mkdir("$scratch/$side/state", 0700, true); mkdir("$scratch/$side/media", 0700);
    file_put_contents("$scratch/$side/site.wprism.json", $side . ' policy');
    file_put_contents("$scratch/$side/state/native.json", $side . ' state');
    file_put_contents("$scratch/$side/media/native.png", $side . ' media');
}
mkdir($scratch . '/sink', 0700);
$script = "set -euo pipefail\npair_live_ownership_repo_host() { :; }\n" . $match[0]
    . "\nsink=\"\$1/sink\"\nR1=\"\$1/source\"\nsnapshot target \"\$1/target\" \"\$1/target/state\"\n";
$process = proc_open(['bash', '-c', $script, 'qi-snapshot-test', $scratch], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
if (!is_resource($process)) throw new RuntimeException('cannot exercise native snapshot');
fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
wprism_check_same(0, proc_close($process), 'live snapshot operation succeeds');
wprism_check_same('', $output, 'live snapshot operation emits no diagnostics');
foreach (['site.wprism.json' => 'target policy', 'state/native.json' => 'target state', 'media/native.png' => 'target media'] as $path => $expected) {
    wprism_check_same($expected, file_get_contents($scratch . '/sink/target/' . $path), 'native snapshot retains its owning target ' . $path);
}
wprism_check(strpos($runner, 'snapshot source "$R1" "$R1/state"') < strpos($runner, 'capture plan2')
    && strpos($runner, 'snapshot target-input "$R2" "$R2/state"') < strpos($runner, 'capture plan2'), 'both native input repositories survive a failed target plan or Apply');

require_once "$capsule/fixtures/native-media/evidence.php";
$mediaSaved = QiNativeMediaCorpus::saved($saved, file_get_contents($capsule . '/fixtures/native-media/blocks.html'));
$mediaRecords = $records;
foreach ($mediaRecords as $side => &$record) {
    $record['page_body'] = $side === 'source'
        ? QiConformanceCorpus::body($mediaSaved, $record['ids'], $record['home'], $record['attachment']['url'])
        : QiConformanceCorpus::applied_body($mediaSaved, $record['ids'], $record['home'], $record['attachment']['url']);
    foreach ($record['posts'] as &$post) if ((int) $post['ID'] === $record['ids']['page']) $post['post_content'] = $record['page_body'];
    unset($post);
}
unset($record);
$database($sourceIds);
$portableMedia = Blocks::capture_rewrite($mediaRecords['source']['page_body'], $policy, new Tokens($seed['home'], $seed['home'] . '/wp-content/uploads'));
$database($targetIds);
wprism_check_same($mediaRecords['target']['page_body'], Blocks::apply_rewrite($portableMedia, $policy, new Tokens($before['home'], $before['home'] . '/wp-content/uploads')),
    'complete native media corpus expectation equals actual product block materialization');
$http = [];
foreach ($mediaRecords as $side => &$record) {
    $http[$side] = ['format' => 'wprism-qi-native-media-pixels/v1', 'home' => $record['home'],
        'page' => $record['ids']['page'], 'attachment' => $record['ids']['image'],
        'original_samples' => [[0, 0, 0, 0], [255, 0, 0, 0], [0, 255, 173, 0], [255, 255, 173, 0], [127, 127, 0, 0]], 'images' => []];
    foreach (QiNativeMediaCorpus::DIMENSIONS as $i => [$width, $height]) {
        $path = '2026/09/tmp-qi-image-' . $width . 'x' . $height . '.png';
        $file = ['sha256' => hash('sha256', $path), 'bytes' => 90 + $i, 'width' => $width, 'height' => $height, 'mime' => 'image/png'];
        $record['uploads'][$path] = $file;
        if ($side === 'target') $record['attachment']['metadata']['sizes']['wprism_recipe_' . hash('sha256', $path)] = [
            'file' => basename($path), 'width' => $width, 'height' => $height, 'mime-type' => 'image/png', 'filesize' => $file['bytes'],
        ];
        $http[$side]['images'][] = ['width' => $width, 'height' => $height,
            'url' => $record['home'] . '/wp-content/uploads/' . $path, 'status' => 200,
            'bytes_sha256' => hash('sha256', $side . $path), 'pixels_sha256' => hash('sha256', $path)];
    }
}
unset($record);
$nativeMedia = static fn(array $target) => QiNativeMediaEvidence::native($mediaRecords['source'], $target, $records['target'], $seed, $mediaSaved);
$nativeMedia($mediaRecords['target']);
wprism_check(true, 'native crop admission checks complete body, preserved rows/options/files and all four metadata entries');
foreach ([
    'missing nested file' => static function (array &$r): void { unset($r['uploads']['2026/09/tmp-qi-image-293x181.png']); },
    'wrong record format' => static function (array &$r): void { $r['format'] = 'unproved'; },
    'wrong target home' => static function (array &$r): void { $r['home'] = 'https://elsewhere.example.test'; },
    'wrong crop metadata' => static function (array &$r): void { $key = array_key_last($r['attachment']['metadata']['sizes']); $r['attachment']['metadata']['sizes'][$key]['width'] = 1; },
    'unowned metadata' => static function (array &$r): void { $key = array_key_last($r['attachment']['metadata']['sizes']); $r['attachment']['metadata']['sizes']['unowned'] = $r['attachment']['metadata']['sizes'][$key]; unset($r['attachment']['metadata']['sizes'][$key]); },
    'changed runtime' => static function (array &$r): void { $r['options'][0]['option_value'] = 'changed'; },
    'changed trash' => static function (array &$r): void { $r['posts'][0]['post_content'] = 'changed'; },
    'changed page row only' => static function (array &$r): void { $r['posts'][array_key_last($r['posts'])]['post_content'] = 'changed'; },
    'missing body selection' => static function (array &$r): void { $r['page_body'] = str_replace('293x181', 'original', $r['page_body']); },
] as $label => $edit) {
    $bad = $mediaRecords['target']; $edit($bad);
    wprism_check_throws(static fn() => $nativeMedia($bad), RuntimeException::class, 'crop admission rejects ' . $label);
}
$httpMedia = static fn(array $target) => QiNativeMediaEvidence::http($http['source'], $target, $mediaRecords['source'], $mediaRecords['target']);
$httpMedia($http['target']);
wprism_check(true, 'all four HTTP images bind native owners, URLs, dimensions and decoded pixels independently of PNG encoding');
foreach (['missing image', 'wrong pixels', '404', 'wrong dimensions', 'wrong home', 'missing digest', 'uniform original', 'missing original premise'] as $fault) {
    $bad = $http['target'];
    if ($fault === 'missing image') array_pop($bad['images']);
    if ($fault === 'wrong pixels') $bad['images'][1]['pixels_sha256'] = str_repeat('f', 64);
    if ($fault === 'uniform original') $bad['original_samples'] = array_fill(0, 5, [43, 108, 132, 0]);
    if ($fault === 'missing original premise') unset($bad['original_samples']);
    if ($fault === '404') $bad['images'][1]['status'] = 404;
    if ($fault === 'wrong dimensions') $bad['images'][1]['width'] = 295;
    if ($fault === 'wrong home') $bad['home'] = $seed['home'];
    if ($fault === 'missing digest') $bad['images'][1]['bytes_sha256'] = '';
    wprism_check_throws(static fn() => $httpMedia($bad), RuntimeException::class, 'crop HTTP admission rejects ' . $fault);
}
$servedImage = $http['target']['images'][0]; unset($servedImage['status']);
$servedImage['bytes_sha256'] = hash('sha256', 'native png bytes');
$servedHtml = '<img src="' . $servedImage['url'] . '">';
wprism_check_same($servedImage + ['status' => 200], QiNativeMediaEvidence::served($servedImage, ['status' => 200], 'native png bytes', $servedHtml),
    'actual curl status and bytes plus frontend selection complete the native pixel observation');
foreach ([[['status' => 404], 'native png bytes', $servedHtml], [['status' => 200], 'wrong bytes', $servedHtml],
    [['status' => 200], 'native png bytes', '<img src="elsewhere">']] as [$receipt, $bytes, $html]) {
    wprism_check_throws(static fn() => QiNativeMediaEvidence::served($servedImage, $receipt, $bytes, $html), RuntimeException::class,
        'served crop cannot hide a failed response, wrong bytes or missing frontend consumer');
}
$cropApply = array_replace($apply, ['applied' => 1, 'warnings' => []]);
QiNativeApplyEvidence::content_update($cropApply, $repeat);
wprism_check(true, 'content-only crop Apply and zero-write repeat require the actual full product verifier');
foreach (['warning', 'partial verifier', 'repeat write'] as $fault) {
    $badApply = $cropApply; $badRepeat = $repeat;
    if ($fault === 'warning') $badApply['warnings'][] = 'unproved';
    if ($fault === 'partial verifier') $badApply['verification']['live_entities'] = 6;
    if ($fault === 'repeat write') $badRepeat['applied'] = 1;
    wprism_check_throws(static fn() => QiNativeApplyEvidence::content_update($badApply, $badRepeat), RuntimeException::class, 'crop product admission rejects ' . $fault);
}
require_once "$capsule/fixtures/native-controls/evidence.php";
$gallerySaved = QiNativeControlsCorpus::saved($saved, file_get_contents($capsule . '/fixtures/native-controls/blocks.html'));
$gallerySource = $records['source']; $galleryTarget = $records['target'];
$gallerySource['page_body'] = QiConformanceCorpus::body($gallerySaved, $sourceIds, $seed['home'], $seed['image_url']);
$database($sourceIds);
$galleryCanonical = Blocks::capture_rewrite($gallerySource['page_body'], $policy, new Tokens($seed['home'], $seed['home'] . '/wp-content/uploads'));
$database($targetIds);
$galleryTarget['page_body'] = Blocks::apply_rewrite($galleryCanonical, $policy, new Tokens($before['home'], $before['home'] . '/wp-content/uploads'));
foreach ([&$gallerySource, &$galleryTarget] as &$record) {
    foreach ($record['posts'] as &$post) if ((int) $post['ID'] === $record['ids']['page']) $post['post_content'] = $record['page_body'];
    unset($post);
}
unset($record);
$galleryAdmission = static fn(array $source, array $target) => QiNativeControlsEvidence::native($source, $target, $records['target'], $seed, $gallerySaved);
$galleryAdmission($gallerySource, $galleryTarget);
wprism_check(true, 'native gallery admission compares complete product output to independently retained picker expectations');
foreach (['body', 'cache', 'row', 'upload', 'option', 'identity', 'format'] as $fault) {
    $bad = $galleryTarget;
    if ($fault === 'body') $bad['page_body'] .= '<p>Lost equality</p>';
    if ($fault === 'cache') $bad['page_body'] .= '<!-- wp:qi-blocks/image-gallery {"gallery":[{"id":108,"nonces":{"edit":"unexpected"}}]} /-->';
    if ($fault === 'row') $bad['posts'][0]['post_content'] .= 'changed';
    if ($fault === 'upload') $bad['uploads']['extra.png'] = [];
    if ($fault === 'option') $bad['options'][0]['option_value'] = 'copied';
    if ($fault === 'identity') $bad['ids']['image'] = $sourceIds['image'];
    if ($fault === 'format') $bad['format'] = 'unknown';
    wprism_check_throws(static fn() => $galleryAdmission($gallerySource, $bad), RuntimeException::class, 'gallery native admission rejects ' . $fault);
}
$galleryHtml = '';
foreach (parse_blocks($galleryTarget['page_body']) as $block) if (in_array($block['blockName'],
    [...QiNativeControlsCorpus::GALLERIES, ...QiNativeControlsCorpus::SIMPLE], true)) $galleryHtml .= $block['innerHTML'];
QiNativeControlsEvidence::images($galleryHtml, $galleryTarget);
wprism_check(true, 'picker frontend admission requires galleries, signature and both progress patterns');
foreach (['', $galleryHtml . $galleryHtml, str_replace('tmp-qi-image.png', 'wrong.png', $galleryHtml)] as $badHtml) {
    wprism_check_throws(static fn() => QiNativeControlsEvidence::images($badHtml, $galleryTarget), RuntimeException::class,
        'gallery frontend admission rejects absent, duplicated or incorrectly selected images');
}
foreach (['signature' => 'qodef-m-signature', 'horizontal pattern' => 'data-pattern=', 'vertical pattern' => 'data-pattern='] as $label => $marker) {
    $badHtml = $label === 'vertical pattern'
        ? substr_replace($galleryHtml, 'data-unproved=', strrpos($galleryHtml, $marker), strlen($marker))
        : preg_replace('/' . preg_quote($marker, '/') . '/', 'unproved=', $galleryHtml, 1);
    wprism_check_throws(static fn() => QiNativeControlsEvidence::images($badHtml, $galleryTarget), RuntimeException::class,
        'picker frontend admission rejects a lost ' . $label);
}
$controls = file_get_contents($capsule . '/fixtures/native-controls/blocks.html');
$pickerBlocks = array_values(array_filter(parse_blocks($controls), static fn(array $block): bool => $block['blockName'] !== null));
foreach ($pickerBlocks as $index => $picker) {
    $incomplete = $pickerBlocks; unset($incomplete[$index]);
    wprism_check_throws(static fn() => QiNativeControlsCorpus::saved($saved, serialize_blocks(array_values($incomplete))), RuntimeException::class,
        'picker corpus rejects a missing retained writer ' . $picker['blockName']);
    wprism_check_throws(static fn() => QiNativeControlsCorpus::saved($saved, serialize_blocks([...$pickerBlocks, $picker])), RuntimeException::class,
        'picker corpus rejects an ambiguous retained writer ' . $picker['blockName']);
}
wprism_check_throws(static fn() => QiNativeControlsCorpus::saved($saved, $controls . '<!-- wp:qi-blocks/unreviewed /-->'), RuntimeException::class,
    'picker corpus rejects an unreviewed native writer');
if (wprism_check_failed() > 0) exit(1);
echo 'PASS: Qi native Apply evidence (' . wprism_check_stats()['passed'] . " assertions)\n";
