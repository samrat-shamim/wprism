#!/usr/bin/env bash
# Regression — DUO-3208: canonical state is an offline-compiled program, not
# a mutable bag of files trusted independently by plan/apply.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)

php -d display_errors=1 -- "$ROOT" <<'PHP'
<?php
$root = $argv[1];
define('DUO_SPEC_VERSION', 0);
require_once "$root/agent/src/Uuid.php";
require_once "$root/agent/src/Canon.php";
require_once "$root/agent/src/Policy.php";
require_once "$root/agent/src/Snapshot.php";
require_once "$root/agent/src/RepositoryAuthorization.php";
require_once "$root/agent/src/RepositoryCompiler.php";

// These are the first target-reading primitives Tokens would reach. A valid
// or invalid compile touching either one is a test failure, proving the gate
// is genuinely offline rather than merely "before writes".
function get_option($name) { throw new RuntimeException("TARGET CONTACT: get_option($name)"); }
function wp_upload_dir(...$args) { throw new RuntimeException('TARGET CONTACT: wp_upload_dir'); }

use Duo\Canon;
use Duo\CompiledRepository;
use Duo\Policy;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;

$tmp = sys_get_temp_dir() . '/duo-3208-' . bin2hex(random_bytes(6));
mkdir($tmp, 0777, true);
register_shutdown_function(static function () use ($tmp): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    @rmdir($tmp);
});

function ok(string $message): void { fwrite(STDOUT, "ok: $message\n"); }
function fail(string $message): never { throw new RuntimeException("FAIL: $message"); }
function put(string $path, string $bytes): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, $bytes);
}
function uuid(int $n): string { return sprintf('00000000-0000-4000-8000-%012d', $n); }

function post_front(string $id, string $type, string $slug): array {
    return [
        'author' => 'user:admin', 'comment_status' => 'open',
        'date' => '2026-08-06 00:00:00', 'date_gmt' => '2026-08-06 00:00:00',
        'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified_gmt' => '2026-08-06 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => $slug, 'status' => 'publish',
        'terms' => (object) [], 'title' => ucfirst(str_replace('-', ' ', $slug)),
        'type' => $type, 'uuid' => $id,
    ];
}

function build_valid(string $repo, array $manifests = ['core']): array {
    $term = uuid(1); $page = uuid(2); $attachment = uuid(3); $menu = uuid(4); $item = uuid(5);
    put("$repo/site.duo.json", Canon::encode([
        'manifests' => $manifests,
        'policy' => [
            'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
            'post_types' => ['post','page','attachment','acf-field','acf-field-group'],
            'taxonomies' => ['category','post_tag'],
        ],
        'spec_version' => 0,
    ]));
    put("$repo/state/terms/category/$term--news.json", Canon::encode([
        'description' => '', 'name' => 'News', 'parent' => null,
        'relationships' => (object) [], 'slug' => 'news', 'taxonomy' => 'category', 'uuid' => $term,
    ]));
    $front = post_front($page, 'page', 'about');
    $front['terms'] = (object) ['category' => [$term]];
    put("$repo/state/posts/page/$page--about.md", Canon::post_file($front, '<!-- wp:paragraph --><p>About</p><!-- /wp:paragraph -->'));
    $mediaBytes = "duo-compiler-media\n";
    $mediaHash = hash('sha256', $mediaBytes);
    put("$repo/media/$mediaHash.txt", $mediaBytes);
    $front = post_front($attachment, 'attachment', 'photo');
    // Upload-root files are legitimate (for example WooCommerce's
    // woocommerce-placeholder.webp); safety is normalization, not Y/m.
    $front += ['alt' => 'Photo', 'file' => 'photo.txt', 'media' => "$mediaHash.txt", 'mime' => 'text/plain'];
    put("$repo/state/posts/attachment/$attachment--photo.md", Canon::post_file($front, ''));
    put("$repo/state/menus/main.json", Canon::encode([
        'items' => [[
            'attr_title' => '', 'classes' => [], 'object' => 'page', 'parent' => null,
            'position' => 1, 'ref' => "{{post:$page}}", 'target' => '', 'title' => 'About',
            'type' => 'post_type', 'uuid' => $item, 'xfn' => '',
        ]],
        'locations' => [], 'name' => 'Main', 'slug' => 'main', 'uuid' => $menu,
    ]));
    put("$repo/state/options/core.json", Canon::encode([
        'blogname' => 'Duo', 'default_category' => "{{term:$term}}", 'page_on_front' => "{{post:$page}}",
        'show_on_front' => 'page',
    ]));
    return compact('term','page','attachment','menu','item','mediaHash');
}

function compile_repo(string $repo): CompiledRepository {
    $policy = Policy::load($repo);
    return RepositoryCompiler::compile($repo, $policy);
}

function failure(string $repo): array {
    try { compile_repo($repo); }
    catch (RepositoryCompilationException $e) { return $e->payload(); }
    fail("$repo compiled successfully but failure was required");
}

function codes(array $payload): array { return array_column($payload['diagnostics'], 'code'); }
function needs(array $payload, string $code): void {
    if (!in_array($code, codes($payload), true)) fail("missing diagnostic $code: " . json_encode($payload));
}

$a = "$tmp/a"; $ids = build_valid($a);
$one = compile_repo($a);
$two = compile_repo($a);
if ($one->artifact_hash() !== $two->artifact_hash()) fail('same revision compiled to different artifact hashes');
$artifactPath = "$tmp/{$one->artifact_hash()}.json";
$one->write($artifactPath);
$read = RepositoryCompiler::read_artifact($artifactPath, Policy::load($a));
if (Canon::encode($read->export()) !== Canon::encode($one->export())) fail('serialized artifact did not round-trip exactly');
ok('valid revision compiles offline to one self-verifying content-addressed artifact');

$tampered = $one->export(); $tampered['revision_hash'] = str_repeat('0', 64);
put("$tmp/tampered.json", Canon::encode($tampered));
try { RepositoryCompiler::read_artifact("$tmp/tampered.json", Policy::load($a)); fail('tampered artifact was accepted'); }
catch (RepositoryCompilationException $e) { needs($e->payload(), 'compiled_artifact_invalid'); }
$siteBytes = file_get_contents("$a/site.duo.json");
$site = Canon::decode($siteBytes);
$site['policy']['options']['blogname'] = ['class'=>'runtime'];
put("$a/site.duo.json", Canon::encode($site));
try { RepositoryCompiler::read_artifact($artifactPath, Policy::load($a)); fail('artifact under changed policy was accepted'); }
catch (RepositoryCompilationException $e) { needs($e->payload(), 'compiled_artifact_policy_mismatch'); }
put("$a/site.duo.json", $siteBytes);
ok('artifact tampering and active-policy mismatch fail with structured compiler diagnostics');

$b = "$tmp/b"; build_valid($b);
if (compile_repo($b)->artifact_hash() !== $one->artifact_hash()) fail('identical inputs at another absolute path changed the artifact');
ok('identical repository+manifest inputs produce the identical artifact on another machine path');

$bad = "$tmp/batched"; $x = build_valid($bad);
$front = post_front($x['page'], 'page', 'other');
put("$bad/state/posts/page/{$x['page']}--other.md", Canon::post_file($front, 'duplicate uuid'));
$front = post_front(uuid(20), 'page', 'about');
put("$bad/state/posts/page/" . uuid(20) . "--about.md", Canon::post_file($front, "<<<<<<< ours\nbody\n=======\nother\n>>>>>>> theirs"));
put("$bad/state/widgets/unknown.json", Canon::encode(['uuid' => uuid(21)]));
$payload = failure($bad);
needs($payload, 'duplicate_uuid'); needs($payload, 'duplicate_natural_identity');
needs($payload, 'conflict_marker'); needs($payload, 'invalid_entity_kind');
if (count($payload['diagnostics']) < 4) fail('compiler did not batch independent diagnostics');
ok('duplicate UUID/natural identity, body conflict, and invalid kind batch deterministically');

$bad2 = "$tmp/batched-copy"; $x2 = build_valid($bad2);
$front = post_front($x2['page'], 'page', 'other');
put("$bad2/state/posts/page/{$x2['page']}--other.md", Canon::post_file($front, 'duplicate uuid'));
$front = post_front(uuid(20), 'page', 'about');
put("$bad2/state/posts/page/" . uuid(20) . "--about.md", Canon::post_file($front, "<<<<<<< ours\nbody\n=======\nother\n>>>>>>> theirs"));
put("$bad2/state/widgets/unknown.json", Canon::encode(['uuid' => uuid(21)]));
if (failure($bad2) !== $payload) fail('equivalent invalid revisions produced different diagnostics');
ok('diagnostic codes, source locations, messages, and ordering are machine-independent');

$refs = "$tmp/refs"; $r = build_valid($refs);
unlink("$refs/state/terms/category/{$r['term']}--news.json");
$p = failure($refs); needs($p, 'semantic_delete_reference');
ok('delete-versus-reference is a blocking semantic merge conflict before target contact');

$cycle = "$tmp/cycle"; $cy = build_valid($cycle); $other = uuid(22);
$firstPath = "$cycle/state/terms/category/{$cy['term']}--news.json";
$first = Canon::decode(file_get_contents($firstPath)); $first['parent'] = $other;
put($firstPath, Canon::encode($first));
put("$cycle/state/terms/category/$other--other.json", Canon::encode([
    'description'=>'', 'name'=>'Other', 'parent'=>$cy['term'], 'relationships'=>(object) [],
    'slug'=>'other', 'taxonomy'=>'category', 'uuid'=>$other,
]));
$p = failure($cycle); needs($p, 'reference_cycle');
ok('closed but cyclic parent graphs are rejected as semantically impossible');

$rawId = "$tmp/raw-id"; build_valid($rawId);
$optionsPath = "$rawId/state/options/core.json";
$options = Canon::decode(file_get_contents($optionsPath)); $options['default_category'] = 1;
put($optionsPath, Canon::encode($options));
$p = failure($rawId); needs($p, 'nonportable_reference');
ok('declared ref fields reject raw environment ids even when JSON is otherwise valid');

$media = "$tmp/media"; $m = build_valid($media);
$front = post_front(uuid(30), 'attachment', 'second-photo');
$front += ['alt'=>'Second', 'file'=>'photo.txt', 'media'=>"{$m['mediaHash']}.txt", 'mime'=>'text/plain'];
put("$media/state/posts/attachment/" . uuid(30) . "--second-photo.md", Canon::post_file($front, ''));
$p = failure($media); needs($p, 'duplicate_upload_path');
ok('two attachment entities cannot claim one upload path');

$unsafeMedia = "$tmp/unsafe-media"; $u = build_valid($unsafeMedia);
$attachmentPath = "$unsafeMedia/state/posts/attachment/{$u['attachment']}--photo.md";
[$attachmentFront,$attachmentBody] = Canon::parse_post_file(file_get_contents($attachmentPath));
$attachmentFront['file'] = '../photo.txt';
put($attachmentPath, Canon::post_file($attachmentFront, $attachmentBody));
$p = failure($unsafeMedia); needs($p, 'unsafe_media_path');
ok('upload-root files are valid while traversal remains blocked');

$schema = "$tmp/schema"; $s = build_valid($schema);
$front = post_front(uuid(40), 'page', 'wrong-directory');
put("$schema/state/posts/post/" . uuid(40) . "--wrong-directory.md", Canon::post_file($front, ''));
put("$schema/state/terms/category/" . uuid(41) . "--broken.json", "{not-json\n");
$p = failure($schema); needs($p, 'schema_content_mismatch'); needs($p, 'malformed_entity');
ok('schema/content incompatibility and malformed metadata fail in the same offline pass');

$acf = "$tmp/acf"; $c = build_valid($acf, ['core','acf']);
$field = uuid(50);
$def = post_front($field, 'acf-field', 'field_duo_relation');
put("$acf/state/posts/acf-field/$field--field_duo_relation.md", Canon::post_file($def, serialize(['type'=>'relationship'])));
$pagePath = "$acf/state/posts/page/{$c['page']}--about.md";
[$pageFront,$pageBody] = Canon::parse_post_file(file_get_contents($pagePath));
$pageFront['meta'] = (object) [
    '_duo_relation' => 'field_duo_relation',
    // relationship is list-shaped; this clean JSON merge supplies a scalar.
    'duo_relation' => "{{post:{$c['attachment']}}}",
];
put($pagePath, Canon::post_file($pageFront, $pageBody));
$p = failure($acf); needs($p, 'adapter_schema_content_mismatch');
ok('pinned ACF interpreter rejects field-schema/content mismatch without plugin code');

$mutable = "$tmp/mutable"; $m = build_valid($mutable);
$compiled = compile_repo($mutable);
$path = "$tmp/frozen.json"; $compiled->write($path);
$pagePath = "$mutable/state/posts/page/{$m['page']}--about.md";
file_put_contents($pagePath, file_get_contents($pagePath) . "<<<<<<< mutation after compile\n");
file_put_contents("$mutable/media/{$m['mediaHash']}.txt", "mutated media\n");
$frozen = RepositoryCompiler::read_artifact($path, Policy::load($mutable));
if ($frozen->artifact_hash() !== $compiled->artifact_hash()) fail('loading compiled input reread mutable state files');
if ($frozen->media_content("{$m['mediaHash']}.txt") !== "duo-compiler-media\n") fail('compiled input reread mutable media');
needs(failure($mutable), 'conflict_marker');
ok('compiled input is immutable: later state/media edits affect recompilation, never the artifact consumer');

fwrite(STDOUT, "ok: DUO-3208 regression: offline typed IR + stable batched semantic diagnostics + content-addressed immutable apply input\n");
PHP
