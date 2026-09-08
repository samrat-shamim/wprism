<?php
declare(strict_types=1);

// Mechanism fixtures, not native AJAX evidence. Lite 2.0.1.1 Tags.php:255-270
// writes both taxonomy relationships and literal settings.form_tags labels.
// Their identities must diverge independently; a label is never a term ID.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/lib/RepositoryConvergence.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/agent/src/Capture/PostCapture.php';
require_once $root . '/agent/src/Capture/TermCapture.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Apply/RelationshipMaterializer.php';

use WPrism\AdapterLibrary;
use WPrism\ApplyFieldMaterializer;
use WPrism\BodyRefGrammar;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\Db;
use WPrism\DatabaseMutationException;
use WPrism\EntityMetaCapture;
use WPrism\MediaCapture;
use WPrism\NativeDatabaseProfile;
use WPrism\Policy;
use WPrism\PostCapture;
use WPrism\RelationshipMaterializer;
use WPrism\RepositoryCompiler;
use WPrism\TermCapture;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\RepositoryConvergence;
use WPrismTest\WpStore;

wprism_test_define_agent_versions();
WpStore::reset();
$db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions()->enableJoinedCaptureSql();
$library = AdapterLibrary::fromSourcePackage($root, 'wpforms-lite');
$policy = Policy::load(null, ['core', 'wpforms-lite'], adapterLibrary: $library);
$taxonomy = 'wpforms_form_tag';
$uuid = static fn(int $id): string => '019200dd-0000-7000-8000-' . sprintf('%012d', $id);
$labels = ['Intake Ω', '701'];
$term = static fn(int $id, int $tt, string $name, string $slug): object => (object) [
    'term_id' => $id, 'term_taxonomy_id' => $tt, 'taxonomy' => 'wpforms_form_tag',
    'name' => $name, 'slug' => $slug, 'description' => '', 'parent' => 0, 'term_group' => 0,
];
$post = static fn(int $id, string $body): object => (object) [
    'ID' => $id, 'post_type' => 'wpforms', 'post_password' => '', 'post_parent' => 0,
    'post_author' => 0, 'post_name' => 'wprism-tagged-form', 'post_title' => 'Tagged form', 'post_status' => 'publish',
    'post_date' => '2026-09-08 00:00:00', 'post_date_gmt' => '2026-09-08 00:00:00',
    'post_modified' => '2026-09-08 00:00:00', 'post_modified_gmt' => '2026-09-08 00:00:00',
    'menu_order' => 0, 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_excerpt' => '',
    'post_mime_type' => '', 'post_content' => $body,
];
$sourceBody = json_encode(['id' => 11, 'fields' => [], 'settings' => ['form_tags' => $labels]], JSON_THROW_ON_ERROR);
$sourceTerms = [$term(21, 121, $labels[0], 'intake'), $term(22, 122, $labels[1], '701')];
$seed = static function (int $postId, array $terms) use ($db, $uuid): void {
    $map = [['id' => 1, 'uuid' => $uuid(11), 'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => $postId]];
    $termRows = $taxRows = [];
    foreach ($terms as $index => $value) {
        $termRows[] = ['term_id' => $value->term_id, 'name' => $value->name, 'slug' => $value->slug, 'term_group' => 0];
        $taxRows[] = ['term_taxonomy_id' => $value->term_taxonomy_id, 'term_id' => $value->term_id,
            'taxonomy' => $value->taxonomy, 'description' => '', 'parent' => 0, 'count' => 0];
        foreach (['term' => $value->term_id, 'term_taxonomy' => $value->term_taxonomy_id] as $kind => $id) {
            $map[] = ['id' => count($map) + 1, 'uuid' => $uuid(21 + $index), 'entity_type' => 'term', 'id_kind' => $kind, 'local_id' => $id];
        }
    }
    $db->seedTable('wp_terms', $termRows)->seedTable('wp_term_taxonomy', $taxRows)
        ->seedTable('wp_wprism_map', $map)->seedTable('wp_postmeta', [])->seedTable('wp_termmeta', []);
};
$capture = static function (object $form, array $terms) use ($policy, $uuid, $taxonomy): array {
    $tokens = new Tokens('https://tags.example.test', 'https://tags.example.test/wp-content/uploads');
    $tokens->policy = $policy;
    $noMetadata = static function (): never { throw new LogicException('tag fixture has no authored metadata'); };
    $meta = new EntityMetaCapture($policy, $tokens, $noMetadata, static function (): void {}, $noMetadata);
    $entities = [(new PostCapture($policy, $tokens, $meta, new MediaCapture()))
        ->capture($form, $uuid(11), ['wpforms' => [$taxonomy]])['entity']];
    foreach ($terms as $index => $value) {
        $entities[] = (new TermCapture($policy, $tokens, $meta))->capture($value, $uuid(21 + $index), []);
    }
    wprism_check_same([], $tokens->warnings, 'tag and form capture has no warning fallback');
    return $entities;
};
$seed(11, $sourceTerms);
$db->seedTable('wp_term_relationships', [
    ['object_id' => 11, 'term_taxonomy_id' => 122, 'term_order' => 0],
    ['object_id' => 11, 'term_taxonomy_id' => 121, 'term_order' => 0],
]);
$source = $capture($post(11, $sourceBody), $sourceTerms);
[$front, $canonicalBody] = Canon::parse_post_file($source[0]['content']);
wprism_check_same([$taxonomy => [$uuid(21), $uuid(22)]], $front['terms'], 'real PostCapture binds both tag relationships to term UUIDs');
wprism_check_same($labels, json_decode($canonicalBody, true, 32, JSON_THROW_ON_ERROR)['settings']['form_tags'],
    'numeric-looking and UTF-8 tag labels stay literal, not identity tokens');
wprism_check_same([$taxonomy => [$uuid(21) => 0, $uuid(22) => 0]], $front['term_orders'], 'native unordered tags retain explicit zero order');

$targetTerms = [$term(221, 1221, $labels[0], 'intake'), $term(222, 1222, $labels[1], '701')];
$seed(111, $targetTerms);
$localTerm = ['term_id' => 223, 'name' => 'Unrelated local tag', 'slug' => 'local-tag', 'term_group' => 0];
$localTax = ['term_taxonomy_id' => 1223, 'term_id' => 223, 'taxonomy' => $taxonomy, 'description' => 'Keep Ω', 'parent' => 0, 'count' => 1];
$foreignTax = ['term_taxonomy_id' => 121, 'term_id' => 21, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 1];
$db->seedTable('wp_terms', [...$db->rows('wp_terms'), $localTerm,
    ['term_id' => 21, 'name' => 'Foreign colliding ID', 'slug' => 'foreign', 'term_group' => 0]])
    ->seedTable('wp_term_taxonomy', [...$db->rows('wp_term_taxonomy'), $localTax, $foreignTax]);
$foreignRelationship = ['object_id' => 111, 'term_taxonomy_id' => 121, 'term_order' => 7];
$localRelationship = ['object_id' => 112, 'term_taxonomy_id' => 1223, 'term_order' => 0];
$targetRelationships = [$foreignRelationship, $localRelationship,
    ['object_id' => 111, 'term_taxonomy_id' => 1223, 'term_order' => 0]];
$db->seedTable('wp_term_relationships', $targetRelationships);
$targetBefore = [$db->rows('wp_terms'), $db->rows('wp_term_taxonomy'), $db->rows('wp_wprism_map')];
$db->setTableEngine('wp_term_taxonomy', 'InnoDB')->setTableEngine('wp_wprism_map', 'InnoDB');
$db->setTableEngine('wp_term_relationships', 'InnoDB')->setColumns('wp_term_relationships', [
    'object_id' => 'bigint unsigned', 'term_taxonomy_id' => 'bigint unsigned', 'term_order' => 'int',
])->setUniqueKey('wp_term_relationships', ['object_id', 'term_taxonomy_id'])->setIndexes('wp_term_relationships', [
    ['Key_name' => 'PRIMARY', 'Column_name' => 'object_id', 'Seq_in_index' => 1, 'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE'],
    ['Key_name' => 'PRIMARY', 'Column_name' => 'term_taxonomy_id', 'Seq_in_index' => 2, 'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE'],
]);
$targetTokens = new Tokens('https://tags.example.test', 'https://tags.example.test/wp-content/uploads');
$targetTokens->policy = $policy;
$field = new ApplyFieldMaterializer($policy, $targetTokens);
$writer = new RelationshipMaterializer($policy, $field);
$apply = static function (array $terms) use ($field, $writer, $front, $taxonomy): void {
    Db::start_repeatable_read('WPForms tag relationship fixture',
        new NativeDatabaseProfile(['wp_term_relationships', 'wp_term_taxonomy', 'wp_wprism_map'], ['wp_term_relationships']));
    $field->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $writer->reconcile_relationships(111, 'wpforms', $terms, $front['term_orders'], [$taxonomy]);
        Db::commit('WPForms tag relationship fixture commit');
        CacheInvalidationTransaction::finish();
    } catch (Throwable $failure) {
        Db::rollback('WPForms tag relationship fixture rollback');
        throw $failure;
    } finally {
        $field->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$apply($front['terms']);
$expectedRows = [$foreignRelationship, $localRelationship,
    ['object_id' => '111', 'term_taxonomy_id' => '1221', 'term_order' => '0'],
    ['object_id' => '111', 'term_taxonomy_id' => '1222', 'term_order' => '0']];
wprism_check_same($expectedRows, $db->rows('wp_term_relationships'), 'relationship materialization uses target TT IDs and preserves foreign taxonomy/owner rows');
wprism_check_same($targetBefore, [$db->rows('wp_terms'), $db->rows('wp_term_taxonomy'), $db->rows('wp_wprism_map')],
    'relationship replacement does not delete or rewrite the target-only tag, taxonomy rows or mappings');
$db->resetLog();
$apply($front['terms']);
wprism_check_same($expectedRows, $db->rows('wp_term_relationships'), 'repeated tag relationship materialization is a physical fixed point');
wprism_check_same([], array_values(array_filter($db->queries(), static fn(string $sql): bool => preg_match('/^(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1)),
    'fixed-point relationship replay issues zero DML statements');
wprism_check_throws(static fn() => $apply([$taxonomy => [$uuid(21), $uuid(99)]]), RuntimeException::class,
    'unmapped tag refuses through the actual materializer', 'unresolvable term');
wprism_check_same($expectedRows, $db->rows('wp_term_relationships'), 'unmapped tag refusal preserves every relationship');

// The first real DELETE succeeds before this INSERT fails. The shared Db
// transaction must restore the removed stale relationship, not merely keep
// the two never-written new relationships absent.
$db->seedTable('wp_term_relationships', $targetRelationships)->resetLog()
    ->failNextQuery('injected tag relationship insert', 'INSERT INTO `wp_term_relationships`');
wprism_check_throws(static fn() => $apply($front['terms']), DatabaseMutationException::class,
    'injected relationship insertion failure reaches the typed value-free boundary', 'database mutation failed: apply insert post relationship');
wprism_check_same(1, count(array_filter($db->queryLog(), static fn(array $entry): bool =>
    str_starts_with($entry['sql'], 'INSERT INTO `wp_term_relationships`') && $entry['error'] === 'injected tag relationship insert')),
    'private driver log identifies the exact injected INSERT failure');
wprism_check_same(1, count(array_filter($db->queryLog(), static fn(array $entry): bool =>
    str_starts_with($entry['sql'], 'DELETE FROM `wp_term_relationships`') && $entry['error'] === '')),
    'fault control executes one successful stale-relationship removal before refusal');
wprism_check_same($targetRelationships, $db->rows('wp_term_relationships'), 'rollback restores all pre-failure relationship rows exactly');
wprism_check_same($targetBefore, [$db->rows('wp_terms'), $db->rows('wp_term_taxonomy'), $db->rows('wp_wprism_map')],
    'failed relationship transaction preserves complete terms, taxonomy rows and mappings');
$apply($front['terms']);
wprism_check_same($expectedRows, $db->rows('wp_term_relationships'), 'retry after the injected failure converges through the same materializer');

$targetBody = BodyRefGrammar::apply($canonicalBody, $policy->body_ref_rule('wpforms'),
    $targetTokens->token_to_id(...), 'tagged form', $targetTokens->detokenize_text(...));
$targetDocument = json_decode($targetBody, true, 32, JSON_THROW_ON_ERROR);
wprism_check_same(111, $targetDocument['id'], 'shared body codec rewrites the form self-ID independently of tag identity');
wprism_check_same($labels, $targetDocument['settings']['form_tags'], 'body replay leaves both label values and types unchanged');
$target = $capture($post(111, $targetBody), $targetTerms);
wprism_check_same($source, $target, 'complete form and both managed tag entities recapture byte-identically');

$compiled = [];
foreach (['source' => $source, 'target' => $target] as $side => $entities) {
    $repository = FrozenPolicy::library() . '/form-tags-' . $side;
    mkdir($repository . '/state', 0700, true);
    Canon::write_file($repository . '/site.wprism.json', Canon::encode([
        'spec_version' => WPRISM_SPEC_VERSION, 'manifests' => ['core', 'wpforms-lite'],
        'policy' => ['post_types' => ['wpforms'], 'taxonomies' => [$taxonomy]],
    ]));
    foreach ($entities as $entity) {
        if (!is_dir(dirname($repository . '/state/' . $entity['path']))) mkdir(dirname($repository . '/state/' . $entity['path']), 0700, true);
        Canon::write_file($repository . '/state/' . $entity['path'], $entity['content']);
    }
    $compiled[$side] = RepositoryCompiler::compile_staged($repository . '/state', $repository,
        Policy::load($repository, ['core', 'wpforms-lite'], adapterLibrary: $library));
}
RepositoryConvergence::assertSame($compiled['source'], $compiled['target']);
wprism_check_same(3, count($compiled['source']->tree()), 'real compiler binds one tagged form and both tag entities');
wprism_check_same(RepositoryConvergence::signatures($compiled['source']), RepositoryConvergence::signatures($compiled['target']),
    'the compiler independently admits exact managed form/tag convergence');
wprism_check_same([], $targetTokens->warnings, 'body materialization uses no warning fallback');
wprism_check_summary('regress_wpforms_lite_form_tags');
