<?php
declare(strict_types=1);

// Real compiler authority, synthetic observations: this tests admission, not
// native Apply. The live lane separately retains all source/target trees.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/apply-evidence.php';
wprism_test_define_agent_versions();
$repo = WPrismTest\FrozenPolicy::library() . '/source';
mkdir($repo . '/state', 0700, true);
WPrism\Canon::write_file($repo . '/site.wprism.json', WPrism\Canon::encode([
    'manifests' => ['core', 'wpforms-lite'], 'spec_version' => 3,
    'policy' => ['post_types' => ['post', 'page', 'wpforms', 'wpforms-template'], 'taxonomies' => [],
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) []],
]));
$roles = ['integer' => 'wpforms', 'string' => 'wpforms', 'template' => 'wpforms-template',
    'destination' => 'page', 'embed' => 'page', 'core-default' => 'post'];
$uuids = [];
foreach (array_keys($roles) as $i => $role) $uuids[$role] = sprintf('10000000-0000-4000-8000-%012d', $i + 1);
$source = ['home' => 'http://source.invalid', 'posts' => []];
$target = ['home' => 'http://target.invalid', 'posts' => [], 'content_roster' => []];
$plan = array_fill_keys(['create', 'update', 'unchanged', 'adopt', 'conflict', 'collision', 'drift', 'delete', 'delete_conflict'], []);
foreach ($roles as $role => $type) {
    $uuid = $uuids[$role];
    $slug = $role === 'core-default' ? 'hello-world' : 'wprism-wpf-' . $role;
    $path = 'posts/' . $type . '/' . $uuid . '--' . $slug . '.md';
    $front = ['author' => 'user:admin', 'comment_status' => 'open', 'date' => '2026-09-01 00:00:00',
        'date_gmt' => '2026-09-01 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified_gmt' => '2026-09-01 00:00:00', 'parent' => null, 'ping_status' => 'closed',
        'slug' => $slug, 'status' => 'publish', 'terms' => (object) [], 'title' => 'Fixture ' . $role,
        'type' => $type, 'uuid' => $uuid];
    $canonical = $role === 'template' ? '{"settings":{"form_title":"WPrism WPForms template"}}' : 'Native fixture body.';
    $index = array_search($role, array_keys($roles), true);
    foreach (['source', 'target'] as $side) {
        $id = ($side === 'source' ? 10 : 100) + $index;
        $destination = ($side === 'source' ? 10 : 100) + 3;
        $body = $canonical;
        if (in_array($role, ['integer', 'string'], true)) {
            $doc = ['id' => $role === 'integer' ? $id : (string) $id, 'settings' => ['confirmations' => [
                2 => ['type' => 'page', 'page' => (string) $destination],
                3 => ['type' => 'page', 'page' => 'previous_page'],
                4 => ['type' => 'redirect', 'redirect' => ${$side}['home'] . '/wprism-wpf-destination/?page_id=' . $destination],
            ]]];
            $body = json_encode($doc, JSON_THROW_ON_ERROR);
            $doc['id'] = ['format' => 'wprism-typed-reference/v1', 'type' => $role === 'integer' ? 'int' : 'string', 'ref' => '{{post:' . $uuid . '}}'];
            $doc['settings']['confirmations'][2]['page'] = '{{post:' . $uuids['destination'] . '}}';
            $doc['settings']['confirmations'][4]['redirect'] = '{{home}}/wprism-wpf-destination/?page_id={{post:' . $uuids['destination'] . '}}';
            $canonical = json_encode($doc, JSON_THROW_ON_ERROR);
        }
        if ($role !== 'core-default') ${$side}['posts'][$role] = ['id' => $id, 'uuid' => $uuid, 'type' => $type, 'slug' => $slug, 'body' => $body];
    }
    if (!is_dir(dirname($repo . '/state/' . $path))) mkdir(dirname($repo . '/state/' . $path), 0700, true);
    WPrism\Canon::write_file($repo . '/state/' . $path, WPrism\Canon::post_file($front, $canonical));
    $row = ['uuid' => $uuid, 'type' => 'post', 'path' => $path, 'title' => $front['title']];
    if ($role === 'core-default') $plan['adopt'][] = $row + ['env_id' => 1];
    else {
        $plan['create'][] = $row;
        $post = $target['posts'][$role];
        $target['content_roster'][] = ['ID' => (string) $post['id'], 'post_type' => $type, 'post_name' => $slug,
            'post_parent' => '0', 'post_content' => $post['body']];
    }
}
$policy = WPrism\Policy::load($repo, adapterLibrary: WPrism\AdapterLibrary::fromSourcePackage($root, 'wpforms-lite'));
$compiled = WPrism\RepositoryCompiler::compile($repo, $policy);
$plan['artifact_hash'] = $compiled->artifact_hash();
WPFormsApplyEvidence::created($compiled, $source, $plan, $target);
wprism_check(true, 'compiled five-entity creation proof permits an independently planned core default adoption');
$reordered = $plan;
$reordered['create'] = array_reverse($reordered['create']);
WPFormsApplyEvidence::created($compiled, $source, $reordered, $target);
wprism_check(true, 'creation inventory is an exact set, not a fabricated native execution order');
foreach (['missing create' => static function (&$s, &$p, &$a): void { array_pop($p['create']); },
    'duplicate create' => static function (&$s, &$p, &$a): void { $p['create'][] = $p['create'][0]; },
    'wrong UUID' => static function (&$s, &$p, &$a): void { $p['create'][0]['uuid'] = '10000000-0000-4000-8000-000000000099'; },
    'wrong path' => static function (&$s, &$p, &$a): void { $p['create'][0]['path'] .= '.other'; },
    'wrong type' => static function (&$s, &$p, &$a): void { $p['create'][0]['type'] = 'term'; },
    'creation carries existing ID' => static function (&$s, &$p, &$a): void { $p['create'][0]['env_id'] = 100; },
    'wrong artifact' => static function (&$s, &$p, &$a): void { $p['artifact_hash'] = str_repeat('0', 64); },
    'coordinated observation UUID' => static function (&$s, &$p, &$a): void { $s['posts']['integer']['uuid'] = $a['posts']['integer']['uuid'] = '10000000-0000-4000-8000-000000000099'; },
    'coordinated observation type' => static function (&$s, &$p, &$a): void { $s['posts']['integer']['type'] = $a['posts']['integer']['type'] = 'page'; },
    'coordinated observation slug' => static function (&$s, &$p, &$a): void { $s['posts']['integer']['slug'] = $a['posts']['integer']['slug'] = 'other'; },
    'missing physical row' => static function (&$s, &$p, &$a): void { array_pop($a['content_roster']); },
    'extra physical form' => static function (&$s, &$p, &$a): void { $a['content_roster'][] = $a['content_roster'][0]; },
    'duplicate hides missing physical row' => static function (&$s, &$p, &$a): void { $a['content_roster'][1] = $a['content_roster'][0]; },
    'wrong physical ID' => static function (&$s, &$p, &$a): void { $a['content_roster'][0]['ID'] = '999'; },
    'coercible physical ID' => static function (&$s, &$p, &$a): void { $a['content_roster'][0]['ID'] .= 'x'; },
    'physical child' => static function (&$s, &$p, &$a): void { $a['content_roster'][0]['post_parent'] = '1'; },
    'physical body mismatch' => static function (&$s, &$p, &$a): void { $a['content_roster'][0]['post_content'] = '{}'; }] as $label => $mutate) {
    [$s, $p, $a] = [$source, $plan, $target];
    $mutate($s, $p, $a);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::created($compiled, $s, $p, $a), RuntimeException::class,
        'source-derived creation refuses ' . $label, 'WPForms Apply evidence:');
}
foreach (['update', 'unchanged', 'adopt', 'conflict', 'collision', 'drift', 'delete', 'delete_conflict'] as $bucket) {
    $p = $plan;
    $p[$bucket][] = array_shift($p['create']);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::created($compiled, $source, $p, $target), RuntimeException::class,
        'fixture cannot masquerade as global ' . $bucket, 'WPForms Apply evidence:');
}
foreach (['integer self-ID type' => static function (&$doc): void { $doc['id'] = (string) $doc['id']; },
    'source self-ID' => static function (&$doc): void { $doc['id'] = 10; },
    'source confirmation ID' => static function (&$doc): void { $doc['settings']['confirmations'][2]['page'] = '13'; },
    'confirmation type' => static function (&$doc): void { $doc['settings']['confirmations'][2]['page'] = 103; },
    'sentinel lost' => static function (&$doc): void { $doc['settings']['confirmations'][3]['page'] = '103'; },
    'source redirect' => static function (&$doc): void { $doc['settings']['confirmations'][4]['redirect'] = 'http://source.invalid/wprism-wpf-destination/?page_id=13'; }] as $label => $mutate) {
    $a = $target;
    $doc = json_decode($a['posts']['integer']['body'], true, 32, JSON_THROW_ON_ERROR);
    $mutate($doc);
    $a['posts']['integer']['body'] = $a['content_roster'][0]['post_content'] = json_encode($doc, JSON_THROW_ON_ERROR);
    wprism_check_throws(static fn() => WPFormsApplyEvidence::created($compiled, $source, $plan, $a), RuntimeException::class,
        'native creation semantics refuse coordinated ' . $label, 'WPForms Apply evidence:');
}
wprism_check_summary('regress_wpforms_location_creation_evidence');
