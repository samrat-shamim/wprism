<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\CompiledRepository;
use WPrism\OptionState;
use WPrism\RepositoryCompiler;
use WPrismTest\FrozenPolicy;
use WPrismTest\RepositoryConvergence;

function get_option(string $key): never { throw new RuntimeException('convergence attempted target contact'); }
function wp_upload_dir(): never { throw new RuntimeException('convergence attempted target contact'); }

$manifest = ['name' => 'convergence-fixture', 'spec_version' => WPRISM_SPEC_VERSION, 'option_autoload' => 'preserve',
    'post_types' => [
        'fixture_entry' => ['class' => 'authored', 'fields' => ['title' => ['class' => 'derived'], 'modified' => ['class' => 'derived'], 'modified_gmt' => ['class' => 'derived']]],
        'page' => ['class' => 'authored'], 'attachment' => ['class' => 'authored'],
    ], 'post_meta' => ['fixture_color' => ['class' => 'authored'], '_wp_attached_file' => ['class' => 'managed'],
        '_wp_attachment_image_alt' => ['class' => 'managed']],
    'options' => ['fixture_setting' => ['class' => 'authored']]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['fixture_entry', 'page', 'attachment'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$manifest], $site);
$temporary = sys_get_temp_dir() . '/wprism-convergence-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($temporary));
$put = static function (string $path, string $bytes): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $bytes);
};
$uuid = static fn(int $id): string => sprintf('00000000-0000-4000-8000-%012d', $id);
$front = static fn(int $id, string $type, string $slug): array => [
    'author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-06 00:00:00', 'date_gmt' => '2026-09-06 00:00:00',
    'excerpt' => '', 'menu_order' => 0, 'meta' => (object) ['fixture_color' => 'blue'],
    'modified' => '2026-09-06 00:00:00', 'modified_gmt' => '2026-09-06 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => $slug, 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Portable ' . $slug, 'type' => $type, 'uuid' => $uuid($id),
];
$media = "exact compiler media\n";
$mediaName = hash('sha256', $media) . '.txt';
$sourceRoot = $temporary . '/source';
$targetRoot = $temporary . '/target';
$sourceFront = $front(1, 'fixture_entry', 'one');
$pageFront = $front(2, 'page', 'two');
$attachment = $front(3, 'attachment', 'media') + ['alt' => 'Media', 'file' => 'media.txt', 'media' => $mediaName, 'mime' => 'text/plain'];
$postPath = 'posts/fixture_entry/' . $uuid(1) . '--one.md';
$pagePath = 'posts/page/' . $uuid(2) . '--two.md';
$files = [$postPath => Canon::post_file($sourceFront, 'Complete body 東京'), $pagePath => Canon::post_file($pageFront, 'Authored dates'),
    'posts/attachment/' . $uuid(3) . '--media.md' => Canon::post_file($attachment, ''),
    'options/core.json' => Canon::encode(OptionState::document(['fixture_setting' => OptionState::present('exact', 'yes')]))];
$build = static function (string $repo, string $state) use ($put, $files, $mediaName, $media, $site): void {
    foreach ($files as $path => $bytes) $put("$repo/$state/$path", $bytes);
    $put("$repo/media/$mediaName", $media);
    $put("$repo/site.wprism.json", Canon::encode($site));
};
$build($sourceRoot, 'state');
$build($targetRoot, 'recapture');
$source = RepositoryCompiler::compile_staged($sourceRoot . '/state', $sourceRoot, $policy);
$compileTarget = static fn(): CompiledRepository => RepositoryCompiler::compile_staged($targetRoot . '/recapture', $targetRoot, $policy);
$accepts = static function (Closure $action): bool {
    try { $action(); return true; } catch (Throwable) { return false; }
};
wprism_check($accepts(static fn() => RepositoryConvergence::assertSame($source, $compileTarget())),
    'actual compiler compares identical staged repositories with their separate real media roots without target contact');
$derived = $sourceFront;
$derived['title'] = 'Native computed title';
$derived['modified'] = $derived['modified_gmt'] = '2026-09-07 11:22:33';
$put("$targetRoot/recapture/$postPath", Canon::post_file($derived, 'Complete body 東京'));
$target = $compileTarget();
wprism_check($source->revision_hash() !== $target->revision_hash()
    && $source->tree()[$uuid(1)]['source_hash'] !== $target->tree()[$uuid(1)]['source_hash']
    && $accepts(static fn() => RepositoryConvergence::assertSame($source, $target)),
    'compiler-derived title and clocks may differ in raw revision/source bytes while all semantic identities converge');
foreach (['body', 'authored-meta', 'date', 'status', 'missing', 'invalid-front', 'unresolved-parent', 'foreign-path', 'unknown-file', 'media-bytes', 'media-missing', 'extra-media'] as $fault) {
    $remove($targetRoot);
    $build($targetRoot, 'recapture');
    $mutated = $derived;
    $body = 'Complete body 東京';
    if ($fault === 'body') $body .= ' changed';
    if ($fault === 'authored-meta') $mutated['meta'] = (object) ['fixture_color' => 'red'];
    if ($fault === 'date') $mutated['date'] = '2026-09-07 00:00:00';
    if ($fault === 'status') $mutated['status'] = 'draft';
    if ($fault === 'invalid-front') unset($mutated['modified_gmt']);
    if ($fault === 'unresolved-parent') $mutated['parent'] = '{{post:' . $uuid(99) . '}}';
    $put("$targetRoot/recapture/$postPath", Canon::post_file($mutated, $body));
    if ($fault === 'missing') unlink("$targetRoot/recapture/$postPath");
    if ($fault === 'foreign-path') rename("$targetRoot/recapture/$postPath", "$targetRoot/recapture/posts/fixture_entry/" . $uuid(1) . '--different.md');
    if ($fault === 'unknown-file') $put("$targetRoot/recapture/unknown.json", '{}');
    if ($fault === 'media-bytes') $put("$targetRoot/media/$mediaName", 'corrupt media');
    if ($fault === 'media-missing') unlink("$targetRoot/media/$mediaName");
    if ($fault === 'extra-media') $put("$targetRoot/media/" . hash('sha256', 'extra') . '.txt', 'extra');
    wprism_check(!$accepts(static fn() => RepositoryConvergence::assertSame($source, $compileTarget())),
        "actual compilation/convergence refuses $fault despite admissible derived differences");
}
$remove($targetRoot);
$build($targetRoot, 'recapture');
$authoredClock = $pageFront;
$authoredClock['modified_gmt'] = '2026-09-07 11:22:33';
$put("$targetRoot/recapture/$pagePath", Canon::post_file($authoredClock, 'Authored dates'));
wprism_check(!$accepts(static fn() => RepositoryConvergence::assertSame($source, $compileTarget())),
    'the same timestamp field remains authored when its post type declares no derived class');
$put("$targetRoot/recapture/$pagePath", $files[$pagePath]);
$extraPath = 'posts/fixture_entry/' . $uuid(4) . '--neighbor.md';
$put("$targetRoot/recapture/$extraPath", Canon::post_file($front(4, 'fixture_entry', 'neighbor'), 'Target-only authored body'));
$withExtra = $compileTarget();
$extraSignature = RepositoryConvergence::signatures($withExtra)[$uuid(4)];
wprism_check(!$accepts(static fn() => RepositoryConvergence::assertSame($source, $withExtra)), 'a valid but unproved target-only entity refuses by default');
wprism_check($accepts(static fn() => RepositoryConvergence::assertSame($source, $withExtra, [$uuid(4) => $extraSignature])),
    'a separate exact target-only identity/type/path/hash proof can accompany complete managed convergence');
foreach (['key', 'type', 'path', 'hash', 'missing-field', 'extra-field', 'overlap', 'unused-proof'] as $fault) {
    $proof = [$uuid(4) => $extraSignature];
    if (in_array($fault, ['type', 'path', 'hash'], true)) $proof[$uuid(4)][$fault] = $fault === 'hash' ? str_repeat('f', 64) : 'wrong';
    if ($fault === 'key') $proof = [$uuid(5) => $extraSignature];
    if ($fault === 'missing-field') unset($proof[$uuid(4)]['hash']);
    if ($fault === 'extra-field') $proof[$uuid(4)]['ignored'] = true;
    if ($fault === 'overlap') $proof[$uuid(1)] = RepositoryConvergence::signatures($source)[$uuid(1)];
    if ($fault === 'unused-proof') $proof[$uuid(5)] = $extraSignature;
    wprism_check(!$accepts(static fn() => RepositoryConvergence::assertSame($source, $withExtra, $proof)), "target-only $fault cannot weaken complete equality");
}
wprism_check_summary('compiler-owned repository convergence');
