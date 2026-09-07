<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/ShellProbe.php';
require_once $root . '/agent/src/Kernel/MetaRows.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/adapter-packages/acf/package/runtime/interpreters/acf.php';
require_once dirname(__DIR__, 2) . '/fixtures/RecaptureConvergence.php';
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\RepositoryCompiler;
use WPrismTest\FrozenPolicy;
use WPrismTest\RankMathCombination\RecaptureConvergence;

function get_option(string $key): never { throw new RuntimeException('offline convergence contacted WordPress'); }
function wp_upload_dir(): never { throw new RuntimeException('offline convergence contacted uploads'); }
class WP_Post { public int $ID = 21; }
function get_page_by_path(string $slug, string $output, string $type): ?WP_Post {
    if ($slug !== 'rmcombo-target-neighbor' || $output !== OBJECT || $type !== 'product') throw new RuntimeException('neighbor lookup changed');
    return new WP_Post();
}

$manifest = ['name'=>'combination-convergence-fixture', 'spec_version'=>WPRISM_SPEC_VERSION, 'option_autoload'=>'preserve',
    'post_types'=>['product'=>['class'=>'authored', 'fields'=>['modified'=>['class'=>'derived'], 'modified_gmt'=>['class'=>'derived']]], 'rmcombo_book'=>['class'=>'authored']],
    'post_meta'=>['_regular_price'=>['class'=>'authored'], 'rank_math_title'=>['class'=>'authored'], 'rmcombo_badge'=>['class'=>'authored'], 'rank_math_internal_links_processed'=>['class'=>'derived']],
    'taxonomies'=>['product_cat'=>['object_type'=>['product']], 'product_type'=>['object_type'=>['product']], 'language'=>['object_type'=>['product']],
        'post_translations'=>['object_type'=>['product'], 'description_refs'=>['kind'=>'post']],
        'term_language'=>['object_type'=>['term'], 'object_keyspace'=>'term'],
        'term_translations'=>['object_type'=>['term'], 'object_keyspace'=>'term', 'description_refs'=>['kind'=>'term']]]];
$site = FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['product', 'rmcombo_book'];
$site['policy']['taxonomies'] = array_keys($manifest['taxonomies']);
$policy = FrozenPolicy::policy([$manifest], $site);
// The actual ACF text-field interpreter returns plain_data, not the scalar
// shorthand this fixture originally invented. Native rmcombofinal01 reached
// successful recapture, then this missing declaration made its oracle refuse.
$acf = new WPrism\Interpreters\Acf($policy);
$acf->prime_repository([['type'=>'post', 'data'=>['type'=>'acf-field', 'slug'=>'field_rmcombo_badge'],
    'body'=>serialize(['type'=>'text'])]]);
$manifest['post_meta']['rmcombo_badge'] = $acf->post_meta_rule('rmcombo_badge',
    ['rmcombo_badge'=>'Native badge', '_rmcombo_badge'=>'field_rmcombo_badge']);
wprism_check_same(['class'=>'authored', 'plain_data'=>true], $manifest['post_meta']['rmcombo_badge'],
    'real ACF text-field classification, not a synthetic scalar rule, owns the neighbor witness');
$policy = FrozenPolicy::policy([$manifest], $site);
$tmp = sys_get_temp_dir() . '/wprism-combination-convergence-' . bin2hex(random_bytes(8));
mkdir($tmp, 0700);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($tmp));
$uuid = static fn(int $id): string => sprintf('00000000-0000-4000-8000-%012d', $id);
$token = static fn(string $kind, int $id): string => '{{' . $kind . ':' . $uuid($id) . '}}';
$front = static fn(int $id, string $slug, string $type = 'product'): array => [
    'uuid'=>$uuid($id), 'type'=>$type, 'slug'=>$slug, 'title'=>'Title ' . $slug, 'status'=>'publish',
    'date'=>'2026-09-06 00:00:00', 'date_gmt'=>'2026-09-06 00:00:00', 'modified'=>'2026-09-06 00:00:00', 'modified_gmt'=>'2026-09-06 00:00:00',
    'author'=>null, 'parent'=>null, 'menu_order'=>0, 'comment_status'=>'open', 'ping_status'=>'closed', 'excerpt'=>'', 'meta'=>(object)[], 'terms'=>(object)[]];
$native = ['taxonomy_graph'=>['terms'=>[], 'term_taxonomy'=>[], 'term_relationships'=>[]],
    'products'=>['en'=>['id'=>11], 'de'=>['id'=>12]], 'book'=>['id'=>13]];
$sourceFiles = $extraFiles = [];
foreach ([11=>'rmcombo-product-en', 12=>'rmcombo-product-de', 13=>'rmcombo-book'] as $id => $slug) {
    $type = $id === 13 ? 'rmcombo_book' : 'product';
    $sourceFiles['posts/' . $type . '/' . $uuid($id) . '--' . $slug . '.md'] = Canon::post_file($front($id, $slug, $type), 'Managed body');
}
$definitions = [101=>['product_cat','managed-en',''], 102=>['product_cat','managed-de',''], 103=>['language','en',''],
    104=>['term_language','pll_en',''], 105=>['product_type','simple',''], 201=>['product_cat','uncategorized-en',''],
    202=>['post_translations','old-posts',serialize(['en'=>11, 'de'=>12])],
    203=>['term_translations','old-pair',serialize(['en'=>101, 'de'=>102])],
    204=>['term_translations','old-single',serialize(['en'=>101])],
    205=>['term_translations','already-empty',serialize(['en'=>102])],
    206=>['term_translations','neighbor-group',serialize(['en'=>201])]];
$edge = static fn(int $owner, int $term): array => ['object_id'=>(string)$owner, 'term_taxonomy_id'=>(string)($term + 1000), 'term_order'=>'0'];
$edges = [$edge(21,103), $edge(21,105), $edge(21,201), $edge(201,104), $edge(201,206)];
$oldEdges = [...$edges, $edge(11,202), $edge(12,202), $edge(101,203), $edge(102,203), $edge(101,204)];
foreach ($definitions as $id => [$taxonomy, $slug, $description]) {
    $native['taxonomy_graph']['terms'][] = ['term_id'=>(string)$id, 'name'=>$slug, 'slug'=>$slug, 'term_group'=>'0'];
    $count = count(array_filter($edges, static fn(array $row): bool => $row['term_taxonomy_id'] === (string)($id + 1000)));
    $native['taxonomy_graph']['term_taxonomy'][] = ['term_taxonomy_id'=>(string)($id + 1000), 'term_id'=>(string)$id,
        'taxonomy'=>$taxonomy, 'description'=>$description, 'parent'=>'0', 'count'=>(string)$count];
    $canonicalDescription = $description;
    if (str_ends_with($taxonomy, '_translations')) {
        $refs = unserialize($description, ['allowed_classes'=>false]);
        foreach ($refs as &$value) $value = $token($taxonomy === 'post_translations' ? 'post' : 'term', $value);
        unset($value);
        $canonicalDescription = (object)$refs;
    }
    $term = ['uuid'=>$uuid($id), 'taxonomy'=>$taxonomy, 'name'=>$slug, 'slug'=>$slug, 'description'=>$canonicalDescription,
        'parent'=>null, 'meta'=>(object)[], 'relationships'=>$id === 201 ? (object)['term_language'=>[$uuid(104)], 'term_translations'=>[$uuid(206)]] : (object)[]];
    $path = 'terms/' . $taxonomy . '/' . $uuid($id) . '--' . $slug . '.json';
    if ($id < 200) $sourceFiles[$path] = Canon::encode($term);
    else $extraFiles[$path] = Canon::encode($term);
}
$native['taxonomy_graph']['term_relationships'] = $edges;
$hostile = $native;
$hostile['taxonomy_graph']['term_relationships'] = $oldEdges;
foreach ($hostile['taxonomy_graph']['term_taxonomy'] as &$row) {
    $row['count'] = (string)count(array_filter($oldEdges, static fn(array $edge): bool => $edge['term_taxonomy_id'] === $row['term_taxonomy_id']));
}
unset($row);
$neighbor = $front(21, 'rmcombo-target-neighbor');
$neighbor['meta'] = (object)['_regular_price'=>'97', 'rank_math_title'=>'Target-only SEO', 'rmcombo_badge'=>'Native badge'];
$neighbor['terms'] = (object)['language'=>[$uuid(103)], 'product_type'=>[$uuid(105)], 'product_cat'=>[$uuid(201)]];
$neighbor['term_orders'] = (object)['language'=>(object)[$uuid(103)=>0], 'product_type'=>(object)[$uuid(105)=>0], 'product_cat'=>(object)[$uuid(201)=>0]];
$neighborPath = 'posts/product/' . $uuid(21) . '--rmcombo-target-neighbor.md';
$extraFiles[$neighborPath] = Canon::post_file($neighbor, '');
$meta = static fn(int $id, string $key, string $value): array => ['meta_id'=>(string)$id, 'meta_key'=>$key, 'meta_value'=>$value];
$before = ['post'=>['ID'=>'21', 'post_author'=>'0', 'post_date'=>$neighbor['date'], 'post_date_gmt'=>$neighbor['date_gmt'],
    'post_content'=>'', 'post_title'=>$neighbor['title'], 'post_excerpt'=>'', 'post_status'=>'publish', 'comment_status'=>'open', 'ping_status'=>'closed',
    'post_password'=>'', 'post_name'=>$neighbor['slug'], 'to_ping'=>'', 'pinged'=>'', 'post_modified'=>$neighbor['modified'], 'post_modified_gmt'=>$neighbor['modified_gmt'],
    'post_content_filtered'=>'', 'post_parent'=>'0', 'guid'=>'https://target.invalid/?post_type=product&p=21', 'menu_order'=>'0', 'post_type'=>'product', 'post_mime_type'=>'', 'comment_count'=>'0'],
    'author_login'=>null, 'postmeta'=>[$meta(1,'_regular_price','97'), $meta(2,'rank_math_title','Target-only SEO'), $meta(3,'rmcombo_badge','Native badge')], 'termmeta'=>[]];
foreach ($definitions as $id => $_) $before['termmeta'][] = ['term_id'=>(string)$id, 'rows'=>$id < 200 ? [$meta($id, '_wprism_uuid', $uuid($id))] : []];
$mid = $before;
$mid['postmeta'][] = $meta(4, 'rank_math_internal_links_processed', '1');
$after = $mid;
$after['postmeta'][] = $meta(5, '_wprism_uuid', $uuid(21));
foreach ($after['termmeta'] as &$row) if ((int)$row['term_id'] > 200) $row['rows'][] = $meta((int)$row['term_id'], '_wprism_uuid', $uuid((int)$row['term_id']));
unset($row);
$put = static function (string $path, string $bytes): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0700, true);
    file_put_contents($path, $bytes);
};
foreach (['source'=>$sourceFiles, 'target'=>$sourceFiles + $extraFiles] as $side => $files) {
    mkdir("$tmp/$side",0700);
    $put("$tmp/$side/site.wprism.json", Canon::encode($site));
    foreach ($files as $path => $bytes) $put("$tmp/$side/state/$path", $bytes);
}
$source = RepositoryCompiler::compile_staged("$tmp/source/state", "$tmp/source", $policy);
$target = RepositoryCompiler::compile_staged("$tmp/target/state", "$tmp/target", $policy);
$proof = RecaptureConvergence::verify($source, $target, $policy, $hostile, $native, $before, $mid, $after);
wprism_check_same(['managed_entities'=>8, 'preserved_posts'=>1, 'preserved_terms'=>6], $proof,
    'actual compiler plus native preimage proves all seven preserved identities including detached groups and author zero');
foreach (['empty'=>'', 'zero'=>'0', 'unicode'=>'Native badge — 東京', 'integer'=>17, 'boolean'=>false,
    'array'=>['label'=>'not scalar'], 'url'=>'https://source.invalid/badge',
    'escaped-url'=>'https:\/\/source.invalid\/badge', 'token'=>$token('post',11)] as $case => $value) {
    $b=$before; $m=$mid; $a=$after; $canonical=$neighbor;
    foreach ([&$b,&$m,&$a] as &$observation) $observation['postmeta'][2]['meta_value']=is_string($value) ? $value : serialize($value);
    unset($observation);
    $canonical['meta']=(object)(array)$neighbor['meta'];
    $canonical['meta']->rmcombo_badge=$value;
    $put("$tmp/target/state/$neighborPath",Canon::post_file($canonical,''));
    $candidate=RepositoryCompiler::compile_staged("$tmp/target/state","$tmp/target",$policy);
    $accepted=false;
    try { RecaptureConvergence::verify($source,$candidate,$policy,$hostile,$native,$b,$m,$a); $accepted=true; } catch (RuntimeException) {}
    wprism_check($accepted === in_array($case,['empty','zero','unicode','integer','boolean'],true),
        "plain-data fixture admits only proved scalar identity values: $case");
}
$put("$tmp/target/state/$neighborPath",$extraFiles[$neighborPath]);
$oldMarker = $before;
$oldMarker['postmeta'][] = $meta(4, 'rank_math_internal_links_processed', '999');
$rebuilt = $mid;
$rebuilt['postmeta'][3]['meta_id'] = '10';
$minted = $after;
$minted['postmeta'] = $rebuilt['postmeta'];
$minted['postmeta'][] = $meta(11, '_wprism_uuid', $uuid(21));
wprism_check_same($proof, RecaptureConvergence::verify($source,$target,$policy,$hostile,$native,$oldMarker,$rebuilt,$minted),
    'declared native marker reset can replace its own row identity while preserving every non-marker metadata byte');
foreach (['post-row','author','postmeta','marker','uuid','uuid-duplicate','uuid-reused','termmeta','missing-termmeta','missing-term','term-name','term-description','term-count','group-member','neighbor-member',
    'canonical-neighbor','canonical-extra-meta','canonical-extra-description','canonical-managed','canonical-clock'] as $fault) {
    $h=$hostile; $f=$native; $b=$before; $m=$mid; $a=$after; $candidate=$target;
    if ($fault === 'post-row') $a['post']['guid'] .= 'changed';
    if ($fault === 'author') $a['author_login'] = 'wrong';
    if ($fault === 'postmeta') $m['postmeta'][0]['meta_value'] = '98';
    if ($fault === 'marker') $m['postmeta'][3]['meta_value'] = '0';
    if ($fault === 'uuid') $a['postmeta'][4]['meta_value'] = $uuid(99);
    if ($fault === 'uuid-duplicate') $a['postmeta'][] = $meta(6, '_wprism_uuid', $uuid(21));
    if ($fault === 'uuid-reused') $a['postmeta'][4]['meta_id'] = '1';
    if ($fault === 'termmeta') $a['termmeta'][5]['rows'][] = $meta(999,'foreign','changed');
    if ($fault === 'missing-termmeta') array_pop($m['termmeta']);
    if ($fault === 'missing-term') array_pop($f['taxonomy_graph']['terms']);
    if ($fault === 'term-name') $f['taxonomy_graph']['terms'][5]['name'] .= ' changed';
    if ($fault === 'term-description') $f['taxonomy_graph']['term_taxonomy'][6]['description'] = serialize(['en'=>13]);
    if ($fault === 'term-count') $f['taxonomy_graph']['term_taxonomy'][6]['count'] = '1';
    if ($fault === 'group-member') $f['taxonomy_graph']['term_relationships'][] = $edge(21,202);
    if ($fault === 'neighbor-member') $f['taxonomy_graph']['term_relationships'][0]['term_order'] = '1';
    $changed = null;
    if (str_starts_with($fault, 'canonical-')) {
        $changed = $neighborPath; $bytes = $extraFiles[$changed];
        if ($fault === 'canonical-neighbor') { $mutated=$neighbor; $mutated['title'].=' changed'; $bytes=Canon::post_file($mutated,''); }
        if ($fault === 'canonical-extra-meta') { $mutated=$neighbor; $mutated['meta']=(object)['_regular_price'=>'99']; $bytes=Canon::post_file($mutated,''); }
        if ($fault === 'canonical-extra-description') {
            $changed='terms/term_translations/'.$uuid(205).'--already-empty.json';
            $mutated=Canon::decode($extraFiles[$changed]); $mutated['description']=['en'=>$token('term',101)]; $bytes=Canon::encode($mutated);
        }
        if (in_array($fault,['canonical-managed','canonical-clock'],true)) {
            $changed='posts/product/'.$uuid(11).'--rmcombo-product-en.md';
            [$mutated,$body]=Canon::parse_post_file($sourceFiles[$changed]);
            if ($fault === 'canonical-clock') $mutated['modified']=$mutated['modified_gmt']='2026-09-07 00:00:00'; else $body.=' changed';
            $bytes=Canon::post_file($mutated,$body);
        }
        $put("$tmp/target/state/$changed",$bytes);
        $candidate=RepositoryCompiler::compile_staged("$tmp/target/state","$tmp/target",$policy);
    }
    $accepted=false;
    try { RecaptureConvergence::verify($source,$candidate,$policy,$h,$f,$b,$m,$a); $accepted=true; } catch (RuntimeException) {}
    wprism_check($accepted === ($fault === 'canonical-clock'), "complete native/semantic convergence classifies $fault");
    if ($changed !== null) $put("$tmp/target/state/$changed",($sourceFiles+$extraFiles)[$changed]);
}
// Submit the real native PHP through the real Bash wrapper, then execute it
// against the shared SQL interpreter. Only the WordPress post-object lookup
// above is a facade seam; metadata size/hash/order reads are the engine's.
$live = (string)file_get_contents(dirname(__DIR__) . '/live/regress_rank_math_commerce_multilingual.sh');
$start = strpos($live, 'rmcombo_native_preservation() {');
$end = strpos($live, "\nproduct_response() {", $start);
$shell = <<<'SH'
set -euo pipefail
fail() { exit 1; }
capture_rmcombo_native_json() { shift; "$@"; }
wp2() { [ "$#" = 2 ] && [ "$1" = eval ]; php -r 'echo base64_encode($argv[1]);' "$2"; }
SH;
[$status,$encoded,$stderr] = WPrismTest\ShellProbe::run($shell . "\n" . substr($live,$start,$end-$start) . "\nrmcombo_native_preservation wp2\n", [], $root);
$program = base64_decode($encoded,true);
wprism_check($status === 0 && $stderr === '' && is_string($program), 'native preservation observer crosses the actual Bash/PHP boundary');
$observe = static function (string $fault, string $prefix = 'wp_') use ($program,$before): array {
    $db = WPrismTest\FakeWpdb::install($prefix);
    $post = $before['post'];
    if ($fault === 'author-present') $post['post_author']='7';
    if ($fault === 'post-overflow') $post['post_content']=str_repeat('x',65537);
    $db->seedTable('posts',[$post]);
    $db->seedTable('users',[['ID'=>'7','user_login'=>'admin']]);
    $db->seedTable('postmeta',array_map(static fn(array $row): array => $row+['post_id'=>'21'],$before['postmeta']));
    $db->seedTable('terms',[['term_id'=>'201'],['term_id'=>'206']]);
    $db->seedTable('termmeta',[['meta_id'=>'91','term_id'=>'201','meta_key'=>'fixture','meta_value'=>serialize(['quoted'=>'東京 \\ "'])]]);
    if ($fault === 'term-overflow') $db->seedTable('terms',array_map(static fn(int $id): array => ['term_id'=>(string)$id],range(1,257)));
    if ($fault === 'receipt-overflow') $db->seedTable('termmeta',[['meta_id'=>'91','term_id'=>'201','meta_key'=>'fixture','meta_value'=>str_repeat('x',262144)]]);
    if ($fault === 'size-error') $db->failNextQuery('fixture read error','SELECT OCTET_LENGTH(post_content)');
    if ($fault === 'post-error') $db->failNextQuery('fixture read error','SELECT * FROM');
    if ($fault === 'post-incomplete') { unset($post['guid']); $db->returnNextGetRowAs($post,'SELECT * FROM'); }
    if ($fault === 'term-count-error') $db->failNextQuery('fixture read error','SELECT COUNT(*) FROM');
    if ($fault === 'term-ids-error') $db->failNextQuery('fixture read error','SELECT term_id FROM');
    if ($fault === 'meta-error') $db->failNextQuery('fixture read error','OCTET_LENGTH(meta_key)');
    $error=null;
    ob_start();
    try { eval($program); } catch (Throwable $caught) { $error=$caught; }
    $bytes=(string)ob_get_clean();
    return [$error,$bytes,$db->queries()];
};
foreach (['ready','author-present','post-overflow','term-overflow','receipt-overflow','size-error','post-error','post-incomplete','term-count-error','term-ids-error','meta-error'] as $fault) {
    [$error,$bytes,$queries]=$observe($fault);
    $healthy=in_array($fault,['ready','author-present'],true);
    wprism_check($healthy ? $error === null && is_array(json_decode($bytes,true)) : $error instanceof RuntimeException && $bytes === '', "actual native preservation program classifies $fault");
    if ($healthy && $error !== null) fwrite(STDERR,$error->getMessage()."\n");
    wprism_check(count(array_filter($queries,static fn(string $sql): bool => !str_starts_with($sql,'SELECT '))) === 0, "$fault preservation observer has no write query");
    if ($fault === 'ready' && $error === null) {
        $observed=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);
        wprism_check($observed['post'] === $before['post'] && $observed['author_login'] === null && $observed['postmeta'] === $before['postmeta']
            && $observed['termmeta'][0]['rows'][0]['meta_value'] === serialize(['quoted'=>'東京 \\ "']) && $observed['termmeta'][1]['rows'] === [], 'complete raw post, ordered metadata and empty term owner are preserved exactly');
    }
}
[$error,$bytes]=$observe('ready','foreign_site_');
wprism_check($error === null && json_decode($bytes,true)['post'] === $before['post'], 'native preservation uses the selected WordPress table prefix');

// Compiler/native semantics are exercised above. At the shell boundary only
// that command's transport is a seam: exact private-path argv, its complete
// output/status, and cleanup ordering are the actual live caller's contract.
$callerRoot = is_dir($argv[1] ?? '') ? $argv[1] : $root;
$caller = (string)file_get_contents($callerRoot . '/integration-scenarios/rank-math-commerce-multilingual/tests/live/regress_rank_math_commerce_multilingual.sh');
$start = strpos($live,'assert_rmcombo_semantic_recapture() {');
$end = strpos($live,'run_leg() {',$start);
$functions = substr($live,$start,$end-$start);
$start = strpos($caller,'capture_rmcombo_native_state TARGET_FINAL wp2 target-final');
$end = strpos($caller,'jq -en --argjson retried "$RETRY_RUNTIME" --argjson final "$TARGET_FINAL"',$start);
if ($start === false || $end === false) throw new RuntimeException('actual recapture acceptance caller is absent');
$window = substr($caller,$start,$end-$start);
$probe = <<<'SH'
set -euo pipefail
ROOT="$1" R1="$2/source" R2="$2/target" fault="$3" PAIR=rmcomboproof
RMCOMBO_CANONICAL_EVIDENCE=/private/canonical HOSTILE_NATIVE_EVIDENCE=/private/hostile
PRESERVATION_BEFORE_EVIDENCE=/private/before PRESERVATION_PRECAPTURE_EVIDENCE=/private/precapture
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
. "$ROOT/sandbox/conformance/asserts.sh"
capture_rmcombo_native_state() {
  [ "$fault" != native-refusal ] || return 7
  case "$*" in
    'TARGET_FINAL wp2 target-final') TARGET_FINAL_EVIDENCE=/private/final ;;
    'PRESERVATION_AFTER wp2 target-preservation-after rmcombo_native_preservation') PRESERVATION_AFTER_EVIDENCE=/private/after ;;
    *) return 61 ;;
  esac
}
php() {
  [ "$#" = 9 ] && [ "$1" = "$ROOT/integration-scenarios/rank-math-commerce-multilingual/fixtures/recapture-convergence.php" ] \
    && [ "$2" = "$ROOT" ] && [ "${*:3}" = 'rmcomboproof /private/canonical /private/hostile /private/final /private/before /private/precapture /private/after' ] || return 62
  case "$fault" in
    warning) printf 'PHP Warning: fixture transport in /fixture.php on line 1\n' >&2 ;;
    refusal) return 9 ;;
  esac
  case "$fault" in
    incomplete) printf '{"status":"ok","convergence":{"managed_entities":8,"preserved_posts":1,"preserved_terms":5}}\n' ;;
    *) printf '{"status":"ok","convergence":{"managed_entities":8,"preserved_posts":1,"preserved_terms":6}}\n' ;;
  esac
  [ "$fault" != nonzero ] || return 8
}
SH;
foreach (['ready','native-refusal','warning','refusal','incomplete','nonzero'] as $fault) {
    $staged="$tmp/target/.tmp-rmcombo-final";
    mkdir($staged,0700);
    $put($staged.'/sentinel','retained until complete acceptance');
    [$status,$stdout,$stderr]=WPrismTest\ShellProbe::run($probe."\n".$functions."\n".$window."\nprintf 'CONVERGENCE_READY\\n'\n",[$root,$tmp,$fault],$root);
    wprism_check($fault === 'ready' ? $status === 0 && str_contains($stdout,'CONVERGENCE_READY') && !is_dir($staged)
        : $status !== 0 && !str_contains($stdout,'CONVERGENCE_READY') && is_file($staged.'/sentinel'),
        "actual live caller binds private evidence and classifies $fault before cleanup (exit $status)");
    if ($fault === 'ready' && $status !== 0) fwrite(STDERR,substr($stderr,0,2048));
    $remove($staged);
}
$command = implode(' ', array_map(escapeshellarg(...), [PHP_BINARY,
    dirname(__DIR__, 2) . '/fixtures/recapture-convergence.php', 'invalid-private-fixture-input']));
[$status, $stdout, $stderr] = WPrismTest\ShellProbe::run($command, [], $root);
wprism_check_same(1, $status, 'actual host convergence command returns nonzero on refusal');
wprism_check_same('', $stderr, 'host refusal does not expose exception values on stderr');
$refusal = json_decode($stdout, true, 32, JSON_THROW_ON_ERROR);
wprism_check_same(['status' => 'error', 'phase' => 'invocation', 'error_class' => RuntimeException::class,
    'message_sha256' => hash('sha256', 'invalid convergence invocation')], $refusal,
    'actual host command has a closed value-free JSON failure receipt');
[$status, $stdout, $stderr] = WPrismTest\ShellProbe::run(
    'set -euo pipefail' . "\n" . 'fail() { printf "FAIL: %s\\n" "$*" >&2; exit 1; }' . "\n"
        . '. "$1/sandbox/conformance/asserts.sh"' . "\n"
        . 'capture_wprism_json_success observed "host convergence" ' . $command . "\n"
        . 'printf "WRONG_SUCCESS\\n"', [$root], $root);
wprism_check($status !== 0 && !str_contains($stdout, 'WRONG_SUCCESS')
    && str_contains($stderr, 'host convergence failed with exit 1') && !str_contains($stderr, 'infrastructure failure'),
    'real caller reports answered host refusal, never a Docker infrastructure diagnosis');
wprism_check_summary('combined semantic convergence with exact native preservation');
