<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Repository/Ledger.php";
require_once "$root/agent/src/Review/BlockReferenceScanner.php";
require_once "$root/agent/src/Kernel/PrivateRefusalEvidence.php";
wprism_test_define_agent_versions();

use WPrism\BlockAttributeReader;
use WPrism\Blocks;
use WPrism\Canon;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;

$capsule = dirname(__DIR__, 2);
$fixtures = $capsule . '/fixtures/queries';
require_once $fixtures . '/evidence.php';
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy'] = ['post_types' => ['page', 'post', 'portfolio'], 'taxonomies' => []];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$uuid = static fn(int $id): string => '11111111-1111-4111-8111-' . sprintf('%012d', $id);
$subjects = [6 => 'portfolio', 7 => 'portfolio', 8 => 'portfolio', 9 => 'portfolio', 12 => 'post', 13 => 'post', 14 => 'post', 15 => 'post', 16 => 'page'];
$database = static function (int $offset, ?int $missing = null) use ($uuid, $subjects): FakeWpdb {
    $map = [];
    foreach ($subjects as $id => $type) if ($id !== $missing) {
        $map[] = ['uuid' => $uuid($id), 'id_kind' => 'post', 'entity_type' => 'post:' . $type, 'local_id' => $id + $offset];
    }
    $map[] = ['uuid' => $uuid(2), 'id_kind' => 'term', 'entity_type' => 'term:portfolio_category', 'local_id' => 2 + $offset];
    return FakeWpdb::install()->seedTable('wp_wprism_map', $map);
};
$sourceHome = 'http://localhost:9196';
$targetHome = 'https://target.example.test/longer-prefix';
$tokens = static fn(string $home): Tokens => new Tokens($home, $home . '/wp-content/uploads');
$provenance = Canon::decode(Canon::read_file($fixtures . '/provenance.json'));
wprism_check_same(false, $provenance['qualification'], 'query UI discovery is not adapter qualification');
$scratch = sys_get_temp_dir() . '/wprism-vp-query-' . bin2hex(random_bytes(8));
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
$front = static fn(int $id, string $type): array => ['uuid' => $uuid($id), 'type' => $type, 'slug' => 'fixture-' . $id,
    'title' => 'Fixture ' . $id, 'author' => 'user:admin', 'parent' => null, 'menu_order' => 0, 'status' => 'publish',
    'comment_status' => 'closed', 'ping_status' => 'closed', 'date' => '2026-09-09 00:00:00', 'date_gmt' => '2026-09-09 00:00:00',
    'modified' => '2026-09-09 00:00:00', 'modified_gmt' => '2026-09-09 00:00:00', 'excerpt' => '', 'meta' => (object) [], 'terms' => (object) []];
foreach ($subjects as $id => $type) {
    if (!is_dir($scratch . '/state/posts/' . $type)) mkdir($scratch . '/state/posts/' . $type, 0700, true);
    Canon::write_file($scratch . '/state/posts/' . $type . '/' . $uuid($id) . '--fixture-' . $id . '.md', Canon::post_file($front($id, $type), 'Reference fixture'));
}
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$writeBody = static fn(string $body) => Canon::write_file($scratch . '/state/posts/page/' . $uuid(16) . '--fixture-16.md', Canon::post_file($front(16, 'page'), $body));
$loop = static fn(string $body): array => BlockAttributeReader::read($body, ['visual-portfolio/loop'])[0]['attrs'];
$canonical = [];
foreach ($provenance['cases'] as $case) {
    $name = $case['name'];
    $body = Canon::read_file($fixtures . '/' . $name . '.html');
    wprism_check_same($case['body'], ['bytes' => strlen($body), 'sha256' => hash('sha256', $body)], "$name retains exact native Save bytes");
    $db = $database(0, $name === 'missing' ? 15 : null);
    $beforeMap = $db->rows('wp_wprism_map');
    $source = $tokens($sourceHome);
    if (in_array($name, ['custom', 'hidden-custom', 'missing'], true)) {
        $expected = $name === 'missing' ? 'refuses an unmapped post reference' : 'requires a declared literal value';
        wprism_check_throws(static fn() => Blocks::capture_rewrite($body, $policy, $source), RuntimeException::class,
            "$name native Save cannot publish a lossy query", $expected);
        wprism_check_same($beforeMap, $db->rows('wp_wprism_map'), "$name refusal preserves the complete reference map");
        try { Blocks::capture_rewrite($body, $policy, $source); }
        catch (RuntimeException $failure) {
            $graph = WPrism\PrivateRefusalEvidence::graph($failure);
            WPrismTest\PrivateRefusalReceipt::assertGraph($graph, VisualPortfolioQueryEvidence::refusalProfile($name)['nodes']);
            wprism_check(true, "$name private evidence profile matches the actual complete codec failure");
            $other = $name === 'missing' ? 'custom' : 'missing';
            wprism_check_throws(static fn() => WPrismTest\PrivateRefusalReceipt::assertGraph($graph,
                VisualPortfolioQueryEvidence::refusalProfile($other)['nodes']), RuntimeException::class, 'a different private cause cannot satisfy the query refusal');
        }
        continue;
    }
    $canonical[$name] = Blocks::capture_rewrite($body, $policy, $source);
    wprism_check_same($beforeMap, $db->rows('wp_wprism_map'), "$name capture does not change identities");
    $database(800);
    $target = $tokens($targetHome);
    $applied = Blocks::apply_rewrite($canonical[$name], $policy, $target);
    $nativeQuery = $loop($body)['postsQuery'] ?? null;
    $targetQuery = $loop($applied)['postsQuery'] ?? null;
    if ($nativeQuery !== null) {
        $expected = $nativeQuery;
        foreach (['ids', 'excludeIds', 'taxonomies'] as $field) {
            $expected[$field] = array_map(static fn(string $id): string => (string) ((int) $id + 800), $expected[$field]);
        }
        wprism_check_same($expected, $targetQuery, "$name target query preserves every native field and rebinds only identities");
    } else {
        wprism_check_same(null, $targetQuery, 'implicit native defaults remain absent');
    }
    wprism_check_same($canonical[$name], Blocks::capture_rewrite($applied, $policy, $target), "$name reaches exact canonical recapture");
    wprism_check_same([], array_merge($source->warnings, $target->warnings), "$name has no missing-reference warnings");
    wprism_check_same([], WPrism\BlockReferenceScanner::scan(parse_blocks($canonical[$name]), $policy->block_attr_rules(), 'query.md', $sourceHome),
        "$name canonical query passes the real block linter");
    $writeBody($canonical[$name]);
    $db = $GLOBALS['wpdb'];
    $beforeQueries = $db->queries();
    wprism_check_same(count($subjects), count(RepositoryCompiler::compile($scratch, $policy)->tree()), "$name compiles with its complete canonical reference graph");
    wprism_check_same($beforeQueries, $db->queries(), "$name immutable compilation does not contact the database");
}
wprism_check_same($canonical['post-types'], $canonical['reset'], 'native filter reset restores exact canonical bytes');
foreach (['custom', 'hidden-custom'] as $name) {
    $body = Canon::read_file($fixtures . '/' . $name . '.html');
    $writeBody($body);
    wprism_check_throws(static fn() => RepositoryCompiler::compile($scratch, $policy), RuntimeException::class,
        "$name cannot bypass the boundary through an immutable Git edit", 'repository_block_value_invalid');
}
// Positive exclusion/taxonomy values are source-reviewed synthetic cases:
// the exact 3.8.1 UI has no Settings menu to expose those optional controls.
$query = $loop(Canon::read_file($fixtures . '/manual.html'))['postsQuery'];
$query['excludeIds'] = ['12'];
$query['taxonomies'] = ['2'];
$block = static fn(array $q): string => '<!-- wp:visual-portfolio/loop ' . serialize_block_attributes(['postsQuery' => $q]) . ' /-->';
$database(0);
$portable = Blocks::capture_rewrite($block($query), $policy, $tokens($sourceHome));
$database(800);
$restored = $loop(Blocks::apply_rewrite($portable, $policy, $tokens($targetHome)))['postsQuery'];
wprism_check_same(['812'], $restored['excludeIds'], 'excluded-post reference uses its declared native string list');
wprism_check_same(['802'], $restored['taxonomies'], 'taxonomy selectors use term identities');
foreach (['unknownField' => 'authored', 'source' => 'extension-query', 'customQuery' => 'p=12', 'ids' => [13], 'taxonomies' => ['0']] as $field => $value) {
    $bad = $query;
    $bad[$field] = $value;
    wprism_check_throws(static fn() => Blocks::capture_rewrite($block($bad), $policy, $tokens($sourceHome)), RuntimeException::class,
        "query $field shape or meaning cannot silently exceed its declaration");
}
$database(0);
foreach (['posts_ids', 'posts_excluded_ids', 'posts_taxonomies'] as $field) {
    $body = '<!-- wp:visual-portfolio/block ' . serialize_block_attributes([$field => ['404']]) . ' /-->';
    wprism_check_throws(static fn() => Blocks::capture_rewrite($body, $policy, $tokens($sourceHome)), RuntimeException::class,
        "$field legacy selectors cannot broaden through dropped identities", 'refuses an unmapped');
}
// Host evidence oracles must reject plausible mutations independently of the
// live runner. These synthetic witnesses are not native qualification.
$sourceWitness = ['pages' => [], 'tables' => ['posts' => [], 'terms' => [['term_id' => '2', 'slug' => 'garden']]]];
$targetWitness = ['pages' => [], 'tables' => ['posts' => [], 'terms' => [['term_id' => '802', 'slug' => 'garden']], 'postmeta' => []]];
$beforeWitness = ['bodies' => []];
foreach ($subjects as $id => $type) {
    $sourceWitness['tables']['posts'][] = ['ID' => (string) $id, 'post_name' => 'fixture-' . $id];
    $targetWitness['tables']['posts'][] = ['ID' => (string) ($id + 800), 'post_name' => 'fixture-' . $id];
}
foreach (VisualPortfolioQueryEvidence::CASES as $index => $case) {
    $nativeQuery = $loop(Canon::read_file($fixtures . '/' . ($case === 'taxonomy-exclusion' ? 'post-types' : $case) . '.html'))['postsQuery'] ?? null;
    if ($case === 'taxonomy-exclusion') { $nativeQuery['taxonomies'] = ['2']; $nativeQuery['excludeIds'] = ['15']; }
    $sourceWitness['pages'][$case] = ['id' => 100 + $index, 'query' => $nativeQuery, 'body' => 'native'];
    $targetQuery = $nativeQuery;
    if ($targetQuery !== null) foreach (['ids', 'excludeIds', 'taxonomies'] as $field) {
        $targetQuery[$field] = array_map(static fn(string $id): string => (string) ((int) $id + 800), $targetQuery[$field]);
    }
    $targetWitness['pages'][$case] = ['id' => 900 + $index, 'query' => $targetQuery, 'body' => 'native'];
    $targetWitness['tables']['postmeta'][] = ['post_id' => (string) (900 + $index), 'meta_key' => '_vp_views_count', 'meta_value' => '42'];
    $beforeWitness['bodies'][$case] = 'empty';
}
VisualPortfolioQueryEvidence::queries($sourceWitness, $targetWitness, $beforeWitness);
wprism_check(true, 'native query oracle admits complete divergent-ID witnesses');
foreach (['dropped-id', 'source-id', 'order', 'empty-page', 'counter', 'counter-owner', 'missing-case'] as $fault) {
    $bad = $targetWitness;
    if ($fault === 'dropped-id') $bad['pages']['manual']['query']['ids'] = [];
    if ($fault === 'source-id') $bad['pages']['manual']['query']['ids'] = ['13', '6'];
    if ($fault === 'order') $bad['pages']['manual']['query']['ids'] = array_reverse($bad['pages']['manual']['query']['ids']);
    if ($fault === 'empty-page') $bad['pages']['manual']['body'] = 'empty';
    if ($fault === 'counter') $bad['tables']['postmeta'][0]['meta_value'] = '0';
    if ($fault === 'counter-owner') $bad['tables']['postmeta'][0]['post_id'] = '999';
    if ($fault === 'missing-case') unset($bad['pages']['default']);
    wprism_check_throws(static fn() => VisualPortfolioQueryEvidence::queries($sourceWitness, $bad, $beforeWitness), RuntimeException::class,
        "native query oracle refuses $fault");
}
$html = '<!doctype html><html><body>' . str_repeat(' ', 4096)
    . '<h3 class="wp-block-visual-portfolio-item-title"><a href="https://target.test/example/">Example</a></h3></body></html>';
wprism_check_same([['title' => 'Example', 'path' => '/example/']], VisualPortfolioQueryEvidence::rendered($html, 'https://target.test'),
    'HTTP oracle reads native item title links with environment-relative identity');
foreach ([str_replace('item-title', 'unrelated', $html), str_replace('https://target.test/', 'https://source.test/', $html), ''] as $badHtml) {
    wprism_check_throws(static fn() => VisualPortfolioQueryEvidence::rendered($badHtml, 'https://target.test'), RuntimeException::class,
        'HTTP oracle refuses missing, foreign-host and empty render evidence');
}
$captureReceipt = ['warnings' => [], 'counts' => ['post' => 1, 'term' => 1]];
$applyReceipt = ['applied' => 2, 'canary' => 'clean', 'drift' => [], 'warnings' => [
    'adopted env post 806 as ' . $uuid(6) . ' (posts/portfolio/' . $uuid(6) . '--fixture-6.md)',
    'adopted env term 802 as ' . $uuid(2) . ' (terms/category/' . $uuid(2) . '--garden.json)',
    'provider capability fired: visual-portfolio-settings@1.0.0 reconcile_settings (1.25s, verified)',
], 'actions' => [['manifest' => 'visual-portfolio', 'kind' => 'provider', 'source' => 'provider:visual-portfolio-settings/reconcile_settings',
    'provider_version' => '1.0.0', 'verified' => true, 'duration_seconds' => 1.25]]];
$repeatReceipt = ['applied' => 0, 'canary' => 'clean', 'drift' => [], 'warnings' => [], 'actions' => []];
$outcomeTarget = ['tables' => ['posts' => [['ID' => '806', 'post_type' => 'portfolio', 'post_name' => 'fixture-6']],
    'terms' => [['term_id' => '802', 'slug' => 'garden']], 'term_taxonomy' => [['term_id' => '802', 'taxonomy' => 'category']]],
    'map' => [['local_id' => '806', 'uuid' => $uuid(6), 'id_kind' => 'post'], ['local_id' => '802', 'uuid' => $uuid(2), 'id_kind' => 'term']]];
VisualPortfolioQueryEvidence::outcomes($captureReceipt, $applyReceipt, $repeatReceipt, $captureReceipt, $outcomeTarget);
wprism_check(true, 'command oracle admits exact adoption and verified-action notices');
foreach (['extra-warning', 'missing-notice', 'wrong-identity', 'unverified-action', 'missing-write', 'dirty-canary'] as $fault) {
    $bad = $applyReceipt;
    if ($fault === 'extra-warning') $bad['warnings'][] = 'unmapped reference dropped';
    if ($fault === 'missing-notice') array_pop($bad['warnings']);
    if ($fault === 'wrong-identity') $bad['warnings'][0] = str_replace('post 806', 'post 999', $bad['warnings'][0]);
    if ($fault === 'unverified-action') $bad['actions'][0]['verified'] = false;
    if ($fault === 'missing-write') $bad['applied'] = 1;
    if ($fault === 'dirty-canary') $bad['canary'] = 'dirty';
    wprism_check_throws(static fn() => VisualPortfolioQueryEvidence::outcomes($captureReceipt, $bad, $repeatReceipt, $captureReceipt, $outcomeTarget),
        RuntimeException::class, "command oracle refuses $fault");
}
wprism_check_summary('Visual Portfolio native query contracts');
