#!/usr/bin/env bash
# Regression — DUO-3208: canonical state is an offline-compiled program, not
# a mutable bag of files trusted independently by plan/apply.
#
# DUO-3236 addendum (bottom of the inline PHP below): RepositoryCompiler::
# compile_staged() lets a caller validate an arbitrary stateDir against an
# independently-specified media root — the new entry point Capture.php uses
# to gate a staged candidate (state.capture-staging) before it is ever
# promoted to state/, resolving its media references against the real
# repo/media directory (Publish.php never stages media at all — see its own
# docblock). compile() itself is unchanged: it is just this method's
# $stateDir=$repo/state, $mediaRoot=$repo special case.
set -euo pipefail
ROOT=$(cd "$(dirname "$0")/../.." && pwd)

php -d display_errors=1 /dev/stdin "$ROOT" <<'PHP'
<?php
$root = $argv[1];
define('DUO_SPEC_VERSION', 2);
require_once "$root/agent/src/Kernel/Uuid.php";
require_once "$root/agent/src/Kernel/Canon.php";
require_once "$root/agent/src/Code/Code.php";
require_once "$root/agent/src/Code/CodeStateContract.php";
require_once "$root/agent/src/Kernel/OptionState.php";
require_once "$root/agent/src/Kernel/UserMetaState.php";
require_once "$root/agent/src/Kernel/Secrets.php";
require_once "$root/agent/src/Kernel/PersonalData.php";
require_once "$root/agent/src/Kernel/Db.php";
require_once "$root/agent/src/Policy/Policy.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Repository/Snapshot.php";
require_once "$root/agent/src/Delete/Deletion.php";
require_once "$root/agent/src/Repository/RepositoryAuthorization.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Repository/SidebarState.php";
require_once "$root/agent/src/Capture/Capture.php";

// These are the first target-reading primitives Tokens would reach. A valid
// or invalid compile touching either one is a test failure, proving the gate
// is genuinely offline rather than merely "before writes".
function get_option($name) { throw new RuntimeException("TARGET CONTACT: get_option($name)"); }
function wp_upload_dir(...$args) { throw new RuntimeException('TARGET CONTACT: wp_upload_dir'); }

use Duo\Canon;
use Duo\OptionState;
use Duo\CompiledRepository;
use Duo\Capture;
use Duo\Policy;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;
use Duo\RepositoryAuthorizationException;
use Duo\UserMetaState;

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
    $blockWidget = uuid(6); $textWidget = uuid(7); $menuWidget = uuid(8);
    put("$repo/site.duo.json", Canon::encode([
        'manifests' => $manifests,
        'policy' => [
            'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
            'post_types' => ['post','page','attachment','acf-field','acf-field-group'],
            'taxonomies' => ['category','post_tag'],
        ],
        'spec_version' => 2,
    ]));
    put("$repo/state/terms/category/$term--news.json", Canon::encode([
        'description' => '', 'meta' => (object) [], 'name' => 'News', 'parent' => null,
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
    put("$repo/state/sidebars/sidebar-1.json", Canon::encode([
        'widgets' => [
            ['uuid' => $blockWidget, 'type' => 'block', 'settings' => (object) [
                'content' => '<!-- wp:paragraph --><p>Sidebar</p><!-- /wp:paragraph -->',
            ]],
            ['uuid' => $textWidget, 'type' => 'text', 'settings' => (object) [
                'filter' => false, 'text' => 'Portable text', 'title' => 'About', 'visual' => true,
            ]],
            ['uuid' => $menuWidget, 'type' => 'nav_menu', 'settings' => (object) [
                'nav_menu' => "{{term:$term}}", 'title' => 'Menu',
            ]],
        ],
    ]));
    $optionRecords = [];
    foreach ([
        'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
        'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
        'template', 'wp_page_for_privacy_policy',
    ] as $name) {
        $optionRecords[$name] = OptionState::absent();
    }
    $optionRecords = array_replace($optionRecords, [
        'blogname' => OptionState::present('Duo', 'yes'),
        'default_category' => OptionState::present("{{term:$term}}", 'yes'),
        'page_on_front' => OptionState::present("{{post:$page}}", 'yes'),
        'show_on_front' => OptionState::present('page', 'yes'),
    ]);
    put("$repo/state/options/core.json", Canon::encode(OptionState::document($optionRecords)));
    return compact('term','page','attachment','menu','item','blockWidget','textWidget','menuWidget','mediaHash');
}

function compile_repo(string $repo): CompiledRepository {
    $policy = Policy::load($repo);
    return RepositoryCompiler::compile($repo, $policy);
}

// DUO-3287: the historical-comparison counterpart — see
// RepositoryCompiler.php's $completenessOptional docblock. Same tree and
// policy, but current-action-only completeness/lifecycle checks do not make
// an older revision unreadable.
function compile_repo_for_diff(string $repo): CompiledRepository {
    $policy = Policy::load($repo);
    return RepositoryCompiler::compile_for_diff($repo, $policy);
}

function failure(string $repo): array {
    try { compile_repo($repo); }
    catch (RepositoryCompilationException $e) { return $e->payload(); }
    fail("$repo compiled successfully but failure was required");
}

function authorization_failure(string $repo): array {
    try { compile_repo($repo); }
    catch (RepositoryAuthorizationException $e) { return $e->payload(); }
    fail("$repo compiled successfully but authorization failure was required");
}

function codes(array $payload): array { return array_column($payload['diagnostics'], 'code'); }
function needs(array $payload, string $code): void {
    if (!in_array($code, codes($payload), true)) fail("missing diagnostic $code: " . json_encode($payload));
}

$a = "$tmp/a"; $ids = build_valid($a);
\Duo\SidebarState::assert_width_budget();
$one = compile_repo($a);
$two = compile_repo($a);
if ($one->artifact_hash() !== $two->artifact_hash()) fail('same revision compiled to different artifact hashes');
$uploads = $one->uploads_inventory();
if ($uploads !== [[
    'attachment_uuid' => $ids['attachment'],
    'derivative_basename_prefix' => 'photo-',
    'derivative_directory' => '',
    'media_blob' => $ids['mediaHash'] . '.txt',
    'original_path' => 'photo.txt',
    'original_sha256' => $ids['mediaHash'],
]]) {
    fail('compiled artifact did not expose the exact original and bounded derivative mutation root');
}
ok('compiled artifact exposes complete immutable upload originals and derivative roots before target contact');
$artifactPath = "$tmp/{$one->artifact_hash()}.json";
$one->write($artifactPath);
$read = RepositoryCompiler::read_artifact($artifactPath, Policy::load($a));
if (Canon::encode($read->export()) !== Canon::encode($one->export())) fail('serialized artifact did not round-trip exactly');
if (($one->tree()['sidebar/sidebar-1']['type'] ?? '') !== 'sidebar') fail('sidebar entity missing from compiled tree');
ok('valid revision (including block/text/nav-menu widgets) compiles offline and id_kind width budget holds');

// Typed-snapshot capture now emits --record when no authored slug_column is
// declared, but repositories captured by earlier Duo versions used the
// source environment's numeric primary key as this cosmetic suffix. Readers
// must keep accepting both shapes; identity remains the UUID prefix.
$tableCompat = "$tmp/table-path-compat";
build_valid($tableCompat);
$tableSite = Canon::decode(file_get_contents("$tableCompat/site.duo.json"));
$tableSite['policy']['tables']['portable_rows'] = [
    'class' => 'authored_snapshot',
    'id_kind' => 'portable_row',
    'pk' => 'id',
    'refs' => [],
    'columns' => ['name' => ['class' => 'authored']],
];
put("$tableCompat/site.duo.json", Canon::encode($tableSite));
$tableUuid = uuid(9);
$tableContent = Canon::encode([
    'columns' => (object) ['name' => 'Portable row'],
    'meta' => (object) [],
    'table' => 'portable_rows',
    'uuid' => $tableUuid,
]);
$legacyTablePath = "$tableCompat/state/tables/portable_rows/$tableUuid--7.json";
$portableTablePath = "$tableCompat/state/tables/portable_rows/$tableUuid--record.json";
put($legacyTablePath, $tableContent);
$legacyTableArtifact = compile_repo($tableCompat);
if (($legacyTableArtifact->tree()[$tableUuid]['path'] ?? '') !== "tables/portable_rows/$tableUuid--7.json") {
    fail('legacy numeric typed-snapshot suffix did not compile as a readable row path');
}
rename($legacyTablePath, $portableTablePath);
$portableTableArtifact = compile_repo($tableCompat);
if (($portableTableArtifact->tree()[$tableUuid]['path'] ?? '') !== "tables/portable_rows/$tableUuid--record.json") {
    fail('portable typed-snapshot suffix did not compile as a readable row path');
}
if (($legacyTableArtifact->tree()[$tableUuid]['hash'] ?? null) !== ($portableTableArtifact->tree()[$tableUuid]['hash'] ?? null)) {
    fail('legacy and portable typed-snapshot path forms changed the row semantic hash');
}
ok('legacy numeric and portable --record typed-snapshot paths both compile to the same UUID/content semantics');

$tampered = $one->export(); $tampered['revision_hash'] = str_repeat('0', 64);
put("$tmp/tampered.json", Canon::encode($tampered));
try { RepositoryCompiler::read_artifact("$tmp/tampered.json", Policy::load($a)); fail('tampered artifact was accepted'); }
catch (RepositoryCompilationException $e) { needs($e->payload(), 'compiled_artifact_invalid'); }
$mismatchedUploads = $one->export();
$mismatchedUploads['uploads_inventory'][0]['original_path'] = 'foreign.txt';
unset($mismatchedUploads['artifact_hash']);
$mismatchedUploads['artifact_hash'] = hash('sha256', Canon::encode($mismatchedUploads));
put("$tmp/mismatched-uploads.json", Canon::encode($mismatchedUploads));
try { RepositoryCompiler::read_artifact("$tmp/mismatched-uploads.json", Policy::load($a)); fail('self-hashed artifact with a foreign upload inventory was accepted'); }
catch (RepositoryCompilationException $e) { needs($e->payload(), 'compiled_artifact_invalid'); }
$mismatchedEffects = $one->export();
$mismatchedEffects['effects_inventory'][0]['effect']['selector']['value'] = 'foreign-runtime-effect';
unset($mismatchedEffects['artifact_hash']);
$mismatchedEffects['artifact_hash'] = hash('sha256', Canon::encode($mismatchedEffects));
put("$tmp/mismatched-effects.json", Canon::encode($mismatchedEffects));
try { RepositoryCompiler::read_artifact("$tmp/mismatched-effects.json", Policy::load($a)); fail('self-hashed artifact with a foreign effect inventory was accepted'); }
catch (RepositoryCompilationException $e) { needs($e->payload(), 'compiled_artifact_invalid'); }
$siteBytes = file_get_contents("$a/site.duo.json");
$site = Canon::decode($siteBytes);
$site['policy']['options']['blogname'] = ['class'=>'runtime'];
put("$a/site.duo.json", Canon::encode($site));
try { RepositoryCompiler::read_artifact($artifactPath, Policy::load($a)); fail('artifact under changed policy was accepted'); }
catch (RepositoryCompilationException $e) { needs($e->payload(), 'compiled_artifact_policy_mismatch'); }
put("$a/site.duo.json", $siteBytes);
ok('artifact tampering and active-policy mismatch fail with structured compiler diagnostics');

$codeRepo = "$tmp/code-enabled"; build_valid($codeRepo);
$codeSite = Canon::decode(file_get_contents("$codeRepo/site.duo.json"));
$codeSite['code'] = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
put("$codeRepo/site.duo.json", Canon::encode($codeSite));
put(
    "$codeRepo/code/wp-content/plugins/example/example.php",
    "<?php\n/*\nPlugin Name: Example\n*/\n"
);
put("$codeRepo/code/wp-content/themes/example/style.css", "/*\nTheme Name: Example\n*/\n");
$codeOptionsPath = "$codeRepo/state/options/core.json";
$codeOptions = Canon::decode(file_get_contents($codeOptionsPath));
$codeOptions['records']['active_plugins'] = OptionState::present(['example/example.php'], 'yes');
$codeOptions['records']['template'] = OptionState::present('example', 'yes');
$codeOptions['records']['stylesheet'] = OptionState::present('example', 'yes');
put($codeOptionsPath, Canon::encode($codeOptions));
$codePolicy = Policy::load($codeRepo);
$codeArtifact = RepositoryCompiler::compile($codeRepo, $codePolicy);
$oldPayload = $codeArtifact->export();
unset($oldPayload['artifact_hash'], $oldPayload['code']);
$descriptorless = CompiledRepository::create($oldPayload);
$descriptorlessPath = "$tmp/descriptorless-code-policy.json";
$descriptorless->write($descriptorlessPath);
try {
    RepositoryCompiler::read_artifact($descriptorlessPath, Policy::load($codeRepo));
    fail('descriptorless artifact was accepted under a code-enabled policy');
} catch (RepositoryCompilationException $e) {
    needs($e->payload(), 'compiled_artifact_code_mismatch');
}
ok('code-enabled policy rejects a self-verifying pre-code artifact before target contact');

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

$refs = "$tmp/refs"; $r = build_valid($refs); $refsBefore = compile_repo($refs);
$termEntity = $refsBefore->tree()[$r['term']];
unlink("$refs/state/terms/category/{$r['term']}--news.json");
put("$refs/state/deletions/{$r['term']}.json", Canon::encode([
    'expected_hash' => $termEntity['hash'], 'expected_revision' => $refsBefore->revision_hash(),
    'format' => \Duo\Deletion::FORMAT, 'kind' => 'term', 'source_path' => $termEntity['path'],
    'type' => 'category', 'uuid' => $r['term'],
]));
$p = failure($refs); needs($p, 'semantic_delete_reference');
$semantic = array_values(array_filter($p['diagnostics'], fn($d) => $d['code'] === 'semantic_delete_reference'));
if (!$semantic || !isset($semantic[0]['related_path'])) fail('delete reference diagnostic omitted the tombstone path');
ok('delete-versus-reference is a blocking semantic merge conflict before target contact');

$absence = "$tmp/absence"; $gone = build_valid($absence);
$before = compile_repo($absence);
$attachment = $before->tree()[$gone['attachment']];
$liveWithoutAttachment = [];
foreach ($before->tree() as $uuid => $_entity) {
    if ($uuid !== $gone['attachment'] && $uuid !== 'options/core') $liveWithoutAttachment[] = ['uuid' => $uuid];
}
$capturedTombstones = \Duo\Deletion::capture_tombstones($before, $liveWithoutAttachment, Policy::load($absence));
if (count($capturedTombstones) !== 1 || $capturedTombstones[0]['uuid'] !== $gone['attachment']) {
    fail('capture did not convert exactly the disappeared prior entity into a tombstone');
}
unlink("$absence/state/posts/attachment/{$gone['attachment']}--photo.md");
$withoutIntent = compile_repo($absence);
if ($withoutIntent->deletions() !== []) fail('file absence was incorrectly compiled as deletion intent');
ok('repository absence alone is not deletion authority');

$tombstonePath = "$absence/state/deletions/{$gone['attachment']}.json";
put($tombstonePath, Canon::encode([
    'expected_hash' => $attachment['hash'],
    'expected_revision' => $before->revision_hash(),
    'format' => \Duo\Deletion::FORMAT,
    'kind' => 'post',
    'source_path' => $attachment['path'],
    'type' => 'attachment',
    'uuid' => $gone['attachment'],
]));
$withIntent = compile_repo($absence);
if (!isset($withIntent->deletions()[$gone['attachment']])) fail('valid tombstone missing from compiled artifact');
$preserved = \Duo\Deletion::capture_tombstones($withIntent, $liveWithoutAttachment, Policy::load($absence));
if (count($preserved) !== 1 || $preserved[0]['content'] !== file_get_contents($tombstonePath)) {
    fail('subsequent capture did not preserve an absent tombstone byte-for-byte');
}
ok('versioned tombstone with expected base compiles into explicit deletion IR');

$front = post_front($gone['attachment'], 'attachment', 'photo');
$front += ['alt' => 'Photo', 'file' => 'photo.txt', 'media' => "{$gone['mediaHash']}.txt", 'mime' => 'text/plain'];
put("$absence/state/posts/attachment/{$gone['attachment']}--photo.md", Canon::post_file($front, ''));
$p = failure($absence); needs($p, 'delete_live_conflict');
unlink("$absence/state/posts/attachment/{$gone['attachment']}--photo.md");
$badTombstone = Canon::decode(file_get_contents($tombstonePath));
$badTombstone['type'] = 'acf-field';
$badTombstone['source_path'] = "posts/acf-field/{$gone['attachment']}--photo.md";
put($tombstonePath, Canon::encode($badTombstone));
$p = failure($absence); needs($p, 'unsupported_deletion');
ok('live+tombstone and undeclared adapter deletion both fail closed');

$cycle = "$tmp/cycle"; $cy = build_valid($cycle); $other = uuid(22);
$firstPath = "$cycle/state/terms/category/{$cy['term']}--news.json";
$first = Canon::decode(file_get_contents($firstPath)); $first['parent'] = $other;
put($firstPath, Canon::encode($first));
put("$cycle/state/terms/category/$other--other.json", Canon::encode([
    'description'=>'', 'meta'=>(object) [], 'name'=>'Other', 'parent'=>$cy['term'], 'relationships'=>(object) [],
    'slug'=>'other', 'taxonomy'=>'category', 'uuid'=>$other,
]));
$p = failure($cycle); needs($p, 'reference_cycle');
ok('closed but cyclic parent graphs are rejected as semantically impossible');

$rawId = "$tmp/raw-id"; build_valid($rawId);
$optionsPath = "$rawId/state/options/core.json";
$options = Canon::decode(file_get_contents($optionsPath)); $options['records']['default_category']['value'] = 1;
put($optionsPath, Canon::encode($options));
$p = failure($rawId); needs($p, 'nonportable_reference');
ok('declared ref fields reject raw environment ids even when JSON is otherwise valid');

$widgetRaw = "$tmp/widget-raw-id"; build_valid($widgetRaw);
$sidebarPath = "$widgetRaw/state/sidebars/sidebar-1.json";
$sidebar = Canon::decode(file_get_contents($sidebarPath));
$sidebar['widgets'][2]['settings']['nav_menu'] = 2;
put($sidebarPath, Canon::encode($sidebar));
$p = failure($widgetRaw); needs($p, 'nonportable_reference');
$sidebar['widgets'][2]['settings']['nav_menu'] = '{{term:' . uuid(1) . '}}';
$sidebar['widgets'][1]['type'] = 'undeclared_widget';
put($sidebarPath, Canon::encode($sidebar));
$p = failure($widgetRaw); needs($p, 'schema_content_mismatch');
ok('sidebar compiler rejects raw widget refs and undeclared widget types offline');

$termRef = "$tmp/term-meta-ref"; $tr = build_valid($termRef);
$sitePath = "$termRef/site.duo.json";
$site = Canon::decode(file_get_contents($sitePath));
$site['policy']['term_meta']['thumbnail_id'] = ['class'=>'authored', 'ref'=>'post'];
put($sitePath, Canon::encode($site));
$termPath = "$termRef/state/terms/category/{$tr['term']}--news.json";
$term = Canon::decode(file_get_contents($termPath));
$term['meta'] = (object) ['thumbnail_id' => 3];
put($termPath, Canon::encode($term));
$p = failure($termRef); needs($p, 'nonportable_reference');
$term['meta'] = (object) ['thumbnail_id' => "{{post:{$tr['attachment']}}}"];
put($termPath, Canon::encode($term));
compile_repo($termRef);
ok('termmeta ref declarations reject raw ids and accept canonical tokens offline');

$termRuntime = "$tmp/term-meta-runtime"; $rt = build_valid($termRuntime);
$sitePath = "$termRuntime/site.duo.json";
$site = Canon::decode(file_get_contents($sitePath));
$site['policy']['term_meta']['runtime_counter'] = ['class'=>'runtime'];
put($sitePath, Canon::encode($site));
$termPath = "$termRuntime/state/terms/category/{$rt['term']}--news.json";
$term = Canon::decode(file_get_contents($termPath));
$term['meta'] = (object) ['runtime_counter' => 'must-not-apply'];
put($termPath, Canon::encode($term));
$p = authorization_failure($termRuntime); needs($p, 'repository_field_not_authored');
ok('term files cannot smuggle runtime or undeclared meta past repository authorization');

$termSchema = "$tmp/term-meta-required"; $ts = build_valid($termSchema);
$termPath = "$termSchema/state/terms/category/{$ts['term']}--news.json";
$term = Canon::decode(file_get_contents($termPath));
unset($term['meta']);
put($termPath, Canon::encode($term));
$p = failure($termSchema); needs($p, 'schema_content_mismatch');
ok('spec-v2 term files require an explicit meta object, including when empty');

$missingOption = "$tmp/missing-option-record"; build_valid($missingOption);
$optionsPath = "$missingOption/state/options/core.json";
$options = Canon::decode(file_get_contents($optionsPath));
unset($options['records']['blogdescription']);
put($optionsPath, Canon::encode($options));
$p = failure($missingOption); needs($p, 'schema_content_mismatch');
$missingRows = array_values(array_filter(
    $p['diagnostics'], fn($d) => ($d['locator'] ?? '') === 'records.blogdescription'
));
if (!$missingRows) fail('missing exact authored option record did not identify records.blogdescription');
ok('removing an exact authored record is invalid, never implicit deletion intent');

// DUO-3287: the SAME missing-record fixture, compiled via
// compile_for_diff() instead of compile() -- must NOT raise
// schema_content_mismatch for the missing name. This is the exact
// distinction the fix draws: compile() (Apply/Deploy/Cli's verify
// command/RepositoryAuthorization/IdentityBackup -- about to ACT on this
// tree under the current policy) still refuses; compile_for_diff()
// (Capture::run()'s previous-revision read, Capture::snapshot()'s
// drift-check fallback -- reading a historical/comparison revision that
// may predate a manifest being added to site.duo.json) does not. Live-
// reproduced: `wp duo capture`/`wp duo plan` both refused with 13-17
// schema_content_mismatch diagnostics the moment a manifest was added to
// an already-captured site, deterministically, until this fix.
$compiledLenient = compile_repo_for_diff($missingOption);
$lenientHasMissingRecord = false;
// compile_for_diff() throws nothing on success, so there is no diagnostics
// payload to inspect the way failure()/needs() do above -- a clean, typed
// CompiledRepository means the check passed. If it threw, this next line
// never runs and the uncaught RepositoryCompilationException fails the
// whole script exactly like any other unexpected exception here would.
if (!($compiledLenient instanceof CompiledRepository)) {
    fail('compile_for_diff() did not return a CompiledRepository for a tree missing only a required-record entry');
}
ok('compile_for_diff() tolerates a missing required-exact-option record (a revision captured under an older, narrower policy) -- exactly the DUO-3287 fix, and compile() above still refuses the identical tree, so the distinction is real, not a global weakening');

// Genuine corruption must still refuse under compile_for_diff() too --
// comparison mode relaxes only current-action checks, not "anything goes."
// Reuse the malformed-entity fixture from the schema-v2 term-file case
// above's sibling pattern: corrupt the SAME options file's JSON itself.
$corruptOptions = "$tmp/corrupt-options-lenient"; build_valid($corruptOptions);
put("$corruptOptions/state/options/core.json", '{not valid json');
try {
    RepositoryCompiler::compile_for_diff($corruptOptions, Policy::load($corruptOptions));
    fail('compile_for_diff() must still refuse a genuinely malformed options file');
} catch (RepositoryCompilationException $e) {
    needs($e->payload(), 'malformed_entity');
}
ok('compile_for_diff() still refuses genuine corruption (malformed JSON) -- historical leniency is not a bypass of this class');

// Code policy can be added after a repository already has state/. That
// historical revision has no actionable lifecycle intent for the newly added
// payload: two records below are explicitly absent and template predates the
// managed lifecycle record altogether. compile_for_diff() is only reading it
// for Capture's comparison/deletion basis, so it must still compile the code
// descriptor but must not apply CodeStateContract's action-only bridge.
$codeOptInHistory = "$tmp/code-policy-opt-in-history"; build_valid($codeOptInHistory);
$codeOptInSitePath = "$codeOptInHistory/site.duo.json";
$codeOptInSite = Canon::decode(file_get_contents($codeOptInSitePath));
$codeOptInSite['code'] = ['format' => 1, 'layout' => 'wp-content', 'source' => 'code/wp-content'];
put($codeOptInSitePath, Canon::encode($codeOptInSite));
put(
    "$codeOptInHistory/code/wp-content/plugins/example/example.php",
    "<?php\n/*\nPlugin Name: Example\n*/\n"
);
put("$codeOptInHistory/code/wp-content/themes/example/style.css", "/*\nTheme Name: Example\n*/\n");
$codeOptInOptionsPath = "$codeOptInHistory/state/options/core.json";
$codeOptInOptions = Canon::decode(file_get_contents($codeOptInOptionsPath));
unset($codeOptInOptions['records']['template']);
put($codeOptInOptionsPath, Canon::encode($codeOptInOptions));
$codeOptInHistorical = compile_repo_for_diff($codeOptInHistory);
$codeOptInDescriptor = $codeOptInHistorical->code_descriptor();
if (!is_array($codeOptInDescriptor) || !is_string($codeOptInDescriptor['code_revision'] ?? null)) {
    fail('compile_for_diff() did not retain current code descriptor compilation for historical code-policy opt-in');
}
ok('compile_for_diff() reads a pre-code historical tree with absent/missing lifecycle records while still compiling the current code descriptor');

// The same repository is not action-ready. Ordinary compile must retain the
// cross-half refusal rather than inheriting comparison mode's historical
// leniency.
$p = failure($codeOptInHistory); needs($p, 'code_state_mismatch');
ok('compile() still refuses the identical code-enabled historical tree until lifecycle records express explicit intent');

// Capture validates a newly built candidate through compile_staged() before
// publishing it. A staging directory containing the same ambiguous records
// must remain strict too; otherwise Capture could publish an actionable state
// tree which ordinary compile would reject.
$codeOptInStaging = "$tmp/code-policy-opt-in-history.capture-staging";
if (!rename("$codeOptInHistory/state", $codeOptInStaging)) {
    fail('could not create isolated staged historical code-policy fixture');
}
try {
    RepositoryCompiler::compile_staged($codeOptInStaging, $codeOptInHistory, Policy::load($codeOptInHistory));
    fail('compile_staged() accepted ambiguous lifecycle records for a code-enabled candidate');
} catch (RepositoryCompilationException $e) {
    needs($e->payload(), 'code_state_mismatch');
}
ok('compile_staged() remains strict for a newly captured code-enabled candidate with absent/missing lifecycle records');

$media = "$tmp/media"; $m = build_valid($media);
$front = post_front(uuid(30), 'attachment', 'second-photo');
$front += ['alt'=>'Second', 'file'=>'photo.txt', 'media'=>"{$m['mediaHash']}.txt", 'mime'=>'text/plain'];
put("$media/state/posts/attachment/" . uuid(30) . "--second-photo.md", Canon::post_file($front, ''));
$p = failure($media); needs($p, 'duplicate_upload_path');
ok('two attachment entities cannot claim one upload path');

$derivativeCollision = "$tmp/derivative-collision"; $dc = build_valid($derivativeCollision);
$front = post_front(uuid(31), 'attachment', 'second-photo-extension');
$front += ['alt'=>'Second', 'file'=>'photo.jpg', 'media'=>"{$dc['mediaHash']}.txt", 'mime'=>'image/jpeg'];
put("$derivativeCollision/state/posts/attachment/" . uuid(31) . "--second-photo-extension.md", Canon::post_file($front, ''));
$p = failure($derivativeCollision); needs($p, 'duplicate_media_derivative_root');
ok('attachments cannot share a derivative basename root even when original extensions differ');

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
$policySnapshot = Policy::load($mutable)->export_snapshot();
$siteBytes = file_get_contents("$mutable/site.duo.json");
file_put_contents("$mutable/site.duo.json", "{invalid after policy freeze\n");
$snapshotPolicy = Policy::from_snapshot($policySnapshot);
$snapshotArtifact = RepositoryCompiler::read_artifact($path, $snapshotPolicy);
if ($snapshotArtifact->artifact_hash() !== $compiled->artifact_hash()) {
    fail('frozen policy snapshot did not reconstruct the compiled artifact association');
}
try {
    Policy::load($mutable);
    fail('invalid mutable site policy was accepted after policy freeze');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'invalid JSON')) throw $e;
}
file_put_contents("$mutable/site.duo.json", $siteBytes);
$tamperedPolicySnapshot = $policySnapshot;
$tamperedPolicySnapshot['site']['policy']['options']['blogname']['class'] = 'runtime';
try {
    RepositoryCompiler::read_artifact($path, Policy::from_snapshot($tamperedPolicySnapshot));
    fail('tampered frozen policy snapshot was accepted for the compiled artifact');
} catch (RepositoryCompilationException $e) {
    needs($e->payload(), 'compiled_artifact_policy_mismatch');
}
$pagePath = "$mutable/state/posts/page/{$m['page']}--about.md";
file_put_contents($pagePath, file_get_contents($pagePath) . "<<<<<<< mutation after compile\n");
file_put_contents("$mutable/media/{$m['mediaHash']}.txt", "mutated media\n");
$frozen = RepositoryCompiler::read_artifact($path, Policy::load($mutable));
if ($frozen->artifact_hash() !== $compiled->artifact_hash()) fail('loading compiled input reread mutable state files');
if ($frozen->media_content("{$m['mediaHash']}.txt") !== "duo-compiler-media\n") fail('compiled input reread mutable media');
$snapshotOptions = new ReflectionMethod(Capture::class, 'repository_options');
$frozenOptions = $snapshotOptions->invoke(null, $mutable, Policy::load($mutable), $frozen);
if (!is_array($frozenOptions) || (OptionState::values($frozenOptions)['blogname'] ?? null) !== 'Duo') {
    fail('apply snapshot option preflight reopened mutable state instead of using its compiled artifact');
}
needs(failure($mutable), 'conflict_marker');
ok('compiled input and policy are immutable: later repo edits never change apply/snapshot consumers');

$userMeta = "$tmp/user-meta"; build_valid($userMeta);
$sitePath = "$userMeta/site.duo.json";
$site = Canon::decode(file_get_contents($sitePath));
$site['policy']['user_meta']['profile_link'] = [
    'class' => 'authored', 'ref' => 'post', 'missing_user' => 'block',
];
put($sitePath, Canon::encode($site));
$login = 'Exact.Editor+Agency';
$userMetaPath = "$userMeta/state/" . UserMetaState::path($login);
put($userMetaPath, Canon::encode(UserMetaState::document($login, [
    'profile_link' => '{{post:' . uuid(2) . '}}',
])));
$compiledUserMeta = compile_repo($userMeta);
$userMetaKey = UserMetaState::key($login);
if (($compiledUserMeta->tree()[$userMetaKey]['type'] ?? null) !== 'user-meta') {
    fail('login-keyed user-meta sidecar did not compile under its non-UUID canonical state key');
}
ok('login-keyed user-meta sidecar compiles without minting a user UUID');

$document = Canon::decode(file_get_contents($userMetaPath));
$document['meta']['profile_link'] = 2;
put($userMetaPath, Canon::encode($document));
$p = failure($userMeta); needs($p, 'nonportable_reference');
ok('user-meta declared refs reject raw target ids offline');

$document['meta']['profile_link'] = '{{post:' . uuid(2) . '}}';
$document['login'] = 'Case-Diverged';
put($userMetaPath, Canon::encode($document));
$p = failure($userMeta); needs($p, 'schema_content_mismatch');
ok('user-meta filename is bound to the exact login and case');

$pii = "$tmp/user-meta-pii"; build_valid($pii);
$sitePath = "$pii/site.duo.json";
$site = Canon::decode(file_get_contents($sitePath));
$site['policy']['user_meta']['contact_email'] = ['class' => 'authored'];
put($sitePath, Canon::encode($site));
put("$pii/state/" . UserMetaState::path('editor'), Canon::encode(
    UserMetaState::document('editor', ['contact_email' => 'editor@example.test'])
));
$auth = authorization_failure($pii);
if (!in_array('repository_user_meta_pii_not_allowed', array_column($auth['diagnostics'], 'code'), true)) {
    fail('hand-authored PII bypassed user-meta repository authorization');
}
ok('repository authorization independently rejects unapproved PII in user-meta sidecars');

$ctrl = "$tmp/decouple-control"; build_valid($ctrl);
$viaCompile = compile_repo($ctrl);

$moved = "$tmp/decouple-moved"; build_valid($moved);
$elsewhere = "$tmp/decouple-elsewhere-state"; // deliberately NOT a sibling of $moved at all
rename("$moved/state", $elsewhere);
$viaStaged = RepositoryCompiler::compile_staged($elsewhere, $moved, Policy::load($moved));
if ($viaStaged->artifact_hash() !== $viaCompile->artifact_hash()) {
    fail('compile_staged() with a decoupled stateDir produced a different artifact than compile() on the equivalent co-located tree');
}
ok('DUO-3236: compile_staged() validates an arbitrary stateDir against an independently-specified media root, producing the identical artifact to the ordinary co-located compile()');

$missingBlob = "$tmp/decouple-missing-media"; $mb = build_valid($missingBlob);
$elsewhere2 = "$tmp/decouple-missing-media-state";
rename("$missingBlob/state", $elsewhere2);
unlink("$missingBlob/media/{$mb['mediaHash']}.txt");
try {
    RepositoryCompiler::compile_staged($elsewhere2, $missingBlob, Policy::load($missingBlob));
    fail('compile_staged() accepted a candidate whose media blob is missing from the separate media root');
} catch (RepositoryCompilationException $e) {
    needs($e->payload(), 'missing_media_blob');
}
ok('DUO-3236: compile_staged() still enforces media presence against the independently-specified root — decoupling stateDir never accidentally skips media validation');

fwrite(STDOUT, "ok: DUO-3208 regression: offline typed IR + stable batched semantic diagnostics + content-addressed immutable apply input\n");
PHP
