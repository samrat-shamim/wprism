<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require_once __DIR__ . '/../../../../agent/src/Apply/MediaDerivativeWorkset.php';
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\RepositoryCompiler;
use WPrismTest\FrozenPolicy;

$attachment = '11111111-1111-4111-8111-111111111111';
$page = '22222222-2222-4222-8222-222222222222';
$declaration = ['attachment' => '$.image.id', 'url' => '$.image.url', 'width' => '$.imageCustomWidth',
    'height' => '$.imageCustomHeight', 'crop' => true, 'filename' => 'requested-dimensions',
    'dimension_cast' => 'truncate', 'when' => ['key' => 'imageSize', 'equals' => 'custom']];
$manifest = ['name' => 'media-recipe-fixture', 'spec_version' => 3,
    'engine_features' => ['block-attribute-values/v1', 'block-media-derivatives/v1', 'spec-window/v1'],
    'option_autoload' => 'preserve', 'post_types' => ['page' => ['class' => 'authored'], 'attachment' => ['class' => 'authored']],
    'post_meta' => ['_wp_attached_file' => ['class' => 'managed'], '_wp_attachment_image_alt' => ['class' => 'managed']],
    'block_values' => ['fixture/image' => ['image' => ['class' => 'authored', 'json_refs' => [['path' => '$.id', 'kind' => 'post']]]]],
    'block_media_derivatives' => ['fixture/image' => [$declaration]]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page', 'attachment'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
$scratch = sys_get_temp_dir() . '/wprism-media-recipes-' . bin2hex(random_bytes(8));
foreach (['state/posts/page', 'state/posts/attachment', 'media'] as $dir) mkdir($scratch . '/' . $dir, 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgAAIAAAUAAWJVMogAAAAASUVORK5CYII=', true);
$media = hash('sha256', $png) . '.png';
file_put_contents($scratch . '/media/' . $media, $png);
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
    'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'image', 'status' => 'inherit', 'terms' => (object) [],
    'title' => 'Image', 'type' => 'attachment', 'uuid' => $attachment, 'file' => '2026/09/image.png',
    'media' => $media, 'mime' => 'image/png', 'alt' => ''];
Canon::write_file($scratch . '/state/posts/attachment/' . $attachment . '--image.md', Canon::post_file($front, ''));
$pageFront = array_replace($front, ['type' => 'page', 'uuid' => $page, 'slug' => 'page', 'title' => 'Page', 'status' => 'publish']);
foreach (['file', 'media', 'mime', 'alt'] as $key) unset($pageFront[$key]);
$attrs = ['image' => ['id' => '{{post:' . $attachment . '}}', 'url' => '{{uploads}}/2026/09/image-333x211.png'],
    'imageSize' => 'custom', 'imageCustomWidth' => 333, 'imageCustomHeight' => 211];
$write = static function (array $value) use ($scratch, $page, $pageFront): void {
    $body = '<!-- wp:fixture/image ' . json_encode($value === [] ? (object) [] : $value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ' /-->';
    Canon::write_file($scratch . '/state/posts/page/' . $page . '--page.md', Canon::post_file($pageFront, $body));
};
$write($attrs);
$compiled = RepositoryCompiler::compile($scratch, $policy);
$rows = $compiled->media_derivatives();
wprism_check_same(1, count($rows), 'full immutable compiler derives one selected custom image recipe');
wprism_check_same('2026/09/image-333x211.png', $rows[0]['target_path'] ?? null, 'requested dimensions bind the derivative filename');
wprism_check_same([$page], $rows[0]['consumers'] ?? null, 'recipe records its actual content consumer');
wprism_check(!function_exists('parse_blocks') && !isset($GLOBALS['wpdb']), 'recipe compilation requires no WordPress or database');
$baseline = $compiled->export();
$artifactPath = $scratch . '/compiled.json';
Canon::write_file($artifactPath, Canon::encode($baseline));
wprism_check_same($rows, WPrism\CompiledArtifactReader::read_artifact($artifactPath, $policy)->media_derivatives(),
    'persisted artifact reader re-derives the same content-bound recipes');
foreach (['missing', 'different-crop', 'extra-consumer', 'bad-url', 'wrong-source', 'duplicate', 'unbound-id'] as $fault) {
    $bad = $baseline;
    if ($fault === 'missing') unset($bad['media_derivatives']);
    if ($fault === 'different-crop') {
        $bad['media_derivatives'][0]['crop'] = false;
        $bad['media_derivatives'][0]['recipe_id'] = WPrism\MediaDerivativeRecipe::identity($bad['media_derivatives'][0]);
    }
    if ($fault === 'extra-consumer') $bad['media_derivatives'][0]['consumers'][] = 'absent-consumer';
    if ($fault === 'bad-url') $bad['media_derivatives'][0]['target_path'] = '2026/09/other.png';
    if ($fault === 'wrong-source') $bad['media_derivatives'][0]['media_blob'] = str_repeat('a', 64) . '.png';
    if ($fault === 'duplicate') $bad['media_derivatives'][] = $bad['media_derivatives'][0];
    if ($fault === 'unbound-id') $bad['media_derivatives'][0]['recipe_id'] = str_repeat('0', 64);
    unset($bad['artifact_hash']);
    $bad['artifact_hash'] = hash('sha256', Canon::encode($bad));
    Canon::write_file($artifactPath, Canon::encode($bad));
    wprism_check_throws(static fn() => WPrism\CompiledArtifactReader::read_artifact($artifactPath, $policy), RuntimeException::class,
        "coherently rehashed $fault recipe cannot acquire persisted artifact authority");
}
foreach (['negative', 'string', 'null', 'bool', 'oversized', 'pixel-bound', 'stale-url', 'different-file', 'raw-id', 'page-id'] as $fault) {
    $bad = $attrs;
    if ($fault === 'negative') $bad['imageCustomWidth'] = -1;
    if ($fault === 'string') $bad['imageCustomWidth'] = '333';
    if ($fault === 'null') $bad['imageCustomWidth'] = null;
    if ($fault === 'bool') $bad['imageCustomWidth'] = true;
    if ($fault === 'oversized') $bad['imageCustomWidth'] = 16385;
    if ($fault === 'pixel-bound') { $bad['imageCustomWidth'] = 16384; $bad['imageCustomHeight'] = 16384; $bad['image']['url'] = '{{uploads}}/2026/09/image-16384x16384.png'; }
    if ($fault === 'stale-url') $bad['imageCustomWidth'] = 334;
    if ($fault === 'different-file') $bad['image']['url'] = '{{uploads}}/2026/09/another-333x211.png';
    if ($fault === 'raw-id') $bad['image']['id'] = 8;
    if ($fault === 'page-id') $bad['image']['id'] = '{{post:' . $page . '}}';
    $write($bad);
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "full compiler refuses $fault before any target contact");
}
foreach (['custom', 'fraction', 'zero', 'oversized-native', 'original-pending', 'full', 'absent'] as $case) {
    $value = $attrs;
    if ($case === 'fraction') { $value['imageCustomWidth'] = 333.9; $value['imageCustomHeight'] = 211.8; }
    if ($case === 'zero') { $value['imageCustomWidth'] = 0; $value['image']['url'] = '{{uploads}}/2026/09/image-0x211.png'; }
    if ($case === 'oversized-native') { $value['imageCustomWidth'] = 2000; $value['imageCustomHeight'] = 1300; $value['image']['url'] = '{{uploads}}/2026/09/image-2000x1300.png'; }
    if ($case === 'original-pending') { $value['image']['url'] = '{{uploads}}/2026/09/image.png'; unset($value['imageCustomWidth'], $value['imageCustomHeight']); }
    if ($case === 'full') $value['imageSize'] = 'full';
    if ($case === 'absent') $value = [];
    $write($value);
    $actual = RepositoryCompiler::compile($scratch, $policy)->media_derivatives();
    wprism_check_same(in_array($case, ['original-pending', 'full', 'absent'], true) ? 0 : 1, count($actual),
        "saved $case image selection derives only its actual selected file work");
    if ($case === 'fraction') wprism_check_same([333, 211], [$actual[0]['width'], $actual[0]['height']], 'explicit native truncate cast preserves the requested integer filename');
}
$load = static function (array $m): WPrism\Policy {
    return FrozenPolicy::policy([$m], FrozenPolicy::site([$m], WPRISM_SPEC_VERSION));
};
foreach (['feature', 'v2', 'key', 'path', 'null-path', 'selector', 'crop', 'cast', 'filename', 'unowned-ref', 'duplicate', 'when', 'bounds'] as $fault) {
    $m = $manifest;
    if ($fault === 'feature') $m['engine_features'] = ['block-attribute-values/v1', 'spec-window/v1'];
    if ($fault === 'v2') { $m['spec_version'] = 2; unset($m['engine_features']); }
    if ($fault === 'key') $m['block_media_derivatives']['fixture/image'][0]['execute'] = 'plugin_callback';
    if ($fault === 'path') $m['block_media_derivatives']['fixture/image'][0]['width'] = '$..width';
    if ($fault === 'null-path') $m['block_media_derivatives']['fixture/image'][0]['path'] = null;
    if ($fault === 'selector') $m['block_media_derivatives']['fixture/image'][0]['path'] = '$.images[0]';
    if ($fault === 'crop') $m['block_media_derivatives']['fixture/image'][0]['crop'] = 'true';
    if ($fault === 'cast') $m['block_media_derivatives']['fixture/image'][0]['dimension_cast'] = 'plugin';
    if ($fault === 'filename') $m['block_media_derivatives']['fixture/image'][0]['filename'] = '../arbitrary';
    if ($fault === 'unowned-ref') $m['block_values']['fixture/image']['image']['json_refs'][0]['kind'] = 'term';
    if ($fault === 'duplicate') $m['block_media_derivatives']['fixture/image'][] = array_reverse($declaration, true);
    if ($fault === 'when') $m['block_media_derivatives']['fixture/image'][0]['when']['script'] = 'callback';
    if ($fault === 'bounds') $m['block_media_derivatives']['fixture/image'] = array_fill(0, 129, $declaration);
    wprism_check_throws(static fn() => $load($m), RuntimeException::class, "manifest validation refuses $fault recipe declaration");
}
$siteOverride = $site;
$siteOverride['policy']['block_media_derivatives'] = $manifest['block_media_derivatives'];
wprism_check_throws(static fn() => FrozenPolicy::policy([$manifest], $siteOverride), RuntimeException::class,
    'site configuration cannot mint file-generation declarations');
$other = $manifest;
$other['name'] = 'other-media-recipe-owner';
wprism_check_throws(static fn() => FrozenPolicy::policy([$manifest, $other], FrozenPolicy::site([$manifest, $other], WPRISM_SPEC_VERSION)), RuntimeException::class,
    'cross-manifest ownership cannot select two derivative owners for one block');
$write($attrs);
// The content walker is shared across post types and declared widget settings.
$unionManifest = $manifest;
$unionManifest['post_types']['wp_template'] = ['class' => 'authored'];
$unionManifest['widgets'] = ['block' => ['settings' => ['content' => ['class' => 'authored', 'codec' => 'blocks']]]];
$unionSite = $site;
$unionSite['policy']['post_types'][] = 'wp_template';
$unionPolicy = FrozenPolicy::policy([$unionManifest], $unionSite);
$template = '33333333-3333-4333-8333-333333333333';
$templateFront = array_replace($pageFront, ['type' => 'wp_template', 'uuid' => $template, 'slug' => 'custom-header']);
$body = '<!-- wp:fixture/image ' . json_encode($attrs, JSON_UNESCAPED_SLASHES) . ' /-->';
mkdir($scratch . '/state/posts/wp_template', 0700, true);
Canon::write_file($scratch . '/state/posts/wp_template/' . $template . '--custom-header.md', Canon::post_file($templateFront, $body));
mkdir($scratch . '/state/sidebars', 0700, true);
$sidebar = ['widgets' => [['uuid' => '44444444-4444-4444-8444-444444444444', 'type' => 'block', 'settings' => ['content' => $body]]]];
Canon::write_file($scratch . '/state/sidebars/main.json', Canon::encode($sidebar));
$union = RepositoryCompiler::compile($scratch, $unionPolicy);
$consumers = [$page, $template, WPrism\SidebarState::key('main')];
sort($consumers, SORT_STRING);
wprism_check_same(1, count($union->media_derivatives()), 'identical page, template and widget recipes share one generated file');
wprism_check_same($consumers, $union->media_derivatives()[0]['consumers'], 'shared recipe retains every exact consumer in deterministic order');
$capturedTree = $union->tree();
foreach ($capturedTree as &$entity) {
    $entity['content'] = $entity['type'] === 'post' ? Canon::post_file($entity['data'], $entity['body']) : Canon::encode($entity['data']);
    unset($entity['data'], $entity['body']);
}
unset($entity);
WPrism\RepositoryValueValidation::assert_native_tree($capturedTree, $unionPolicy);
wprism_check_same($union->media_derivatives(), WPrism\RepositoryMediaDerivatives::derive($capturedTree, $unionPolicy),
    'native capture candidate shape reaches the same recipe proof before publication');
$badCapture = $capturedTree;
$badCapture[$page]['content'] = str_replace('image-333x211.png', 'image-334x211.png', $badCapture[$page]['content']);
wprism_check_throws(static fn() => WPrism\RepositoryValueValidation::assert_native_tree($badCapture, $unionPolicy), RuntimeException::class,
    'capture publication gate refuses a selected file inconsistent with the authored recipe');
$conflictManifest = $unionManifest;
$conflictManifest['block_media_derivatives']['fixture/image'][] = array_replace($declaration, ['crop' => false]);
$conflictPolicy = FrozenPolicy::policy([$conflictManifest], $unionSite);
wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $conflictPolicy), RuntimeException::class,
    'same destination with different transforms refuses even when original pixels might coincide');
$scalarManifest = $unionManifest;
$scalarManifest['block_media_derivatives']['fixture/image'][0]['dimension_cast'] = 'integer';
$scalarPolicy = FrozenPolicy::policy([$scalarManifest], $unionSite);
$write(array_replace($attrs, ['imageCustomWidth' => 333.9]));
wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $scalarPolicy), RuntimeException::class,
    'a declaration that requires integers cannot silently truncate a fractional control');
$write($attrs);
$nestedManifest = $unionManifest;
$nestedManifest['block_values']['fixture/image']['slides'] = ['class' => 'authored', 'json_refs' => [['path' => '$.image.id', 'kind' => 'post']]];
$nestedManifest['block_media_derivatives']['fixture/image'][] = array_replace($declaration, ['path' => '$.slides']);
$nestedPolicy = FrozenPolicy::policy([$nestedManifest], $unionSite);
$second = $attrs;
$second['imageCustomWidth'] = 444;
$second['image']['url'] = '{{uploads}}/2026/09/image-444x211.png';
$write(['slides' => [$attrs, $second]]);
$nested = RepositoryCompiler::compile($scratch, $nestedPolicy)->media_derivatives();
wprism_check_same(2, count($nested), 'the shared JSON path selects two correlated list-item recipes');
wprism_check_same([333, 444], array_column($nested, 'width'), 'list item dimensions remain correlated with their own selected image URL');
$write(['slides' => 'malformed selected context']);
wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $nestedPolicy), RuntimeException::class,
    'a selected recipe container cannot silently become scalar content');
$write($attrs);
unlink($scratch . '/state/posts/wp_template/' . $template . '--custom-header.md');
unlink($scratch . '/state/sidebars/main.json');
// Selecting one consumer must not drop another consumer's still-live crop.
$write($attrs);
$full = RepositoryCompiler::compile($scratch, $policy);
$nativeTree = $full->tree();
$local = '55555555-5555-4555-8555-555555555555';
$nativeTree[$local] = $nativeTree[$page];
$nativeTree[$local]['data']['uuid'] = $local;
$nativeTree[$local]['data']['slug'] = 'target-only';
$nextAttrs = $attrs;
$nextAttrs['imageCustomWidth'] = 444;
$nextAttrs['image']['url'] = '{{uploads}}/2026/09/image-444x211.png';
$write($nextAttrs);
$next = RepositoryCompiler::compile($scratch, $policy);
$work = WPrism\MediaDerivativeWorkset::select($next, $policy, $nativeTree, [['uuid' => $page]], []);
wprism_check_same([$attachment], $work->attachments, 'content-only change schedules the referenced unchanged attachment');
wprism_check_same([333, 444], array_column($work->recipes, 'width'), 'selected new crop and preserved target-local crop share the resulting workset');
wprism_check_same([[$local], [$page]], array_column($work->recipes, 'consumers'), 'work selection preserves ownership without importing target-local authored content');
$work->assert_artifact($next);
$work->assert_target($policy, $nativeTree);
wprism_check(true, 'workset retains exact artifact and target-observation bindings');
wprism_check_throws(static fn() => $work->assert_artifact($full), RuntimeException::class,
    'a workset cannot be carried into another immutable artifact');
$changedNative = $nativeTree;
$changedNative[$local]['body'] = str_replace(['333x211', '"imageCustomWidth":333'], ['777x211', '"imageCustomWidth":777'], $changedNative[$local]['body']);
wprism_check_throws(static fn() => $work->assert_target($policy, $changedNative), RuntimeException::class,
    'a changed preserved recipe invalidates the pre-mutation work observation');
$changedNative = $nativeTree;
$changedNative[$local]['data']['title'] = 'Target-local title remains local';
$work->assert_target($policy, $changedNative);
wprism_check(true, 'unrelated target-local title changes do not alter file recipe authority');
$none = WPrism\MediaDerivativeWorkset::select($next, $policy, $nativeTree, [], []);
wprism_check_same([[], []], [$none->attachments, $none->recipes], 'unselected repository changes cannot generate target files');
$removed = WPrism\MediaDerivativeWorkset::select($next, $policy, $nativeTree, [], [$page]);
wprism_check_same([$attachment], $removed->attachments, 'consumer deletion schedules attachment derivative reconciliation');
wprism_check_same([[$local]], array_column($removed->recipes, 'consumers'), 'consumer deletion retains a target-only consumer of the shared crop');
$lastTree = $full->tree();
$last = WPrism\MediaDerivativeWorkset::select($next, $policy, $lastTree, [], [$page]);
wprism_check_same([[$attachment], []], [$last->attachments, $last->recipes], 'last-consumer removal retains attachment work with an empty desired crop roster');
$deletedAttachment = WPrism\MediaDerivativeWorkset::select($next, $policy, $nativeTree, [], [$page, $attachment]);
wprism_check_same([[], []], [$deletedAttachment->attachments, $deletedAttachment->recipes], 'derivative selection never resurrects an attachment selected for deletion');
$drifted = $nativeTree;
$drifted[$attachment]['data']['media'] = str_repeat('a', 64) . '.png';
wprism_check_throws(static fn() => WPrism\MediaDerivativeWorkset::select($next, $policy, $drifted, [['uuid' => $page]], []), RuntimeException::class,
    'content-only work cannot silently replace a target-drifted attachment original');
$withImage = WPrism\MediaDerivativeWorkset::select($next, $policy, $drifted, [['uuid' => $page], ['uuid' => $attachment]], []);
wprism_check_same([$media], array_values(array_unique(array_column($withImage->recipes, 'media_blob'))),
    'explicit attachment work regenerates selected and preserved crops from the authorized new original');
$foreignPath = $nativeTree;
$foreignPath[$attachment]['data']['file'] = 'other/image.png';
foreach ([$page, $local] as $uuid) $foreignPath[$uuid]['body'] = str_replace('/2026/09/', '/other/', $foreignPath[$uuid]['body']);
wprism_check_throws(static fn() => WPrism\MediaDerivativeWorkset::select($next, $policy, $foreignPath, [['uuid' => $page], ['uuid' => $attachment]], []), RuntimeException::class,
    'attachment path changes cannot strand a preserved consumer on its old crop URL');
$write($attrs);
$legacy = $manifest;
unset($legacy['block_media_derivatives']);
$legacy['engine_features'] = ['block-attribute-values/v1', 'spec-window/v1'];
$legacyPolicy = FrozenPolicy::policy([$legacy], $site);
$old = RepositoryCompiler::compile($scratch, $legacyPolicy);
wprism_check(!array_key_exists('media_derivatives', $old->export()), 'unselected feature does not add an empty field to historical artifact bytes');
wprism_check_summary('media derivative recipes');
