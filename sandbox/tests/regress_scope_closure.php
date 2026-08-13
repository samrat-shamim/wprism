<?php
/**
 * Offline harness for DUO-3344 — scope resolution over declared edges.
 *
 * Invoked by regress_scope_closure.sh, which lints every engine file this
 * touches first. Runs on real fixture repositories under sys_get_temp_dir()
 * with a scratch DUO_MANIFESTS_DIR, driving the REAL, unmodified
 * agent/src/{Policy,ReferenceGraph,RepositoryCompiler,ScopeClosure}.php. No
 * docker, no sandbox pair, no WordPress bootstrap — the compiler's own
 * docblock is explicit that the IR exists before Tokens, Ledger, or Capture
 * can be constructed, and scope resolution reads only that IR, so the whole
 * thing is a pure function of files plus pinned policy. get_option() and
 * wp_upload_dir() below are booby-trapped: any target contact is a failure,
 * proving this is genuinely offline rather than merely "before writes".
 */

$root = $argv[1];
define('DUO_SPEC_VERSION', 2);
foreach ([
    'Uuid', 'OrderPreserved', 'Canon', 'OptionState', 'UserMetaState', 'Db', 'Secrets',
    'PersonalData', 'ManifestDispositions', 'AdapterSources', 'CapabilityRegistry',
    'NativeActions', 'ReferenceRules', 'Policy', 'Providers', 'Ledger', 'Deletion',
    'JsonRefs', 'PlainData', 'StructuredValue', 'SidebarState', 'Snapshot',
    'RepositoryAuthorization', 'CodeCompatibility', 'Code', 'CodeStateContract',
    'ReferenceGraph', 'RepositoryCompiler', 'ScopeClosure', 'ScopeContract',
] as $file) {
    require_once "$root/agent/src/$file.php";
}

function get_option($name) { throw new RuntimeException("TARGET CONTACT: get_option($name)"); }
function wp_upload_dir(...$args) { throw new RuntimeException('TARGET CONTACT: wp_upload_dir'); }

use Duo\Canon;
use Duo\OptionState;
use Duo\Policy;
use Duo\ReferenceGraph;
use Duo\RepositoryCompilationException;
use Duo\RepositoryCompiler;
use Duo\ScopeClosure;
use Duo\ScopeContract;

$failures = [];
function check(bool $ok, string $message): void {
    global $failures;
    if ($ok) {
        echo "ok: $message\n";
        return;
    }
    $failures[] = $message;
    echo "FAIL: $message\n";
}

$tmp = sys_get_temp_dir() . '/duo-3344-' . bin2hex(random_bytes(6));
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

function put(string $path, string $bytes): void {
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
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

function option_records(array $overrides): string {
    $records = [];
    foreach ([
        'active_plugins', 'blogdescription', 'blogname', 'default_category', 'page_for_posts',
        'page_on_front', 'posts_per_page', 'show_on_front', 'sticky_posts', 'stylesheet',
        'template', 'wp_page_for_privacy_policy',
    ] as $name) {
        $records[$name] = OptionState::absent();
    }
    return Canon::encode(OptionState::document(array_replace($records, $overrides)));
}

// A scratch manifest directory: the real core.json plus one fixture adapter
// that declares a parent/child post-type relation in DUO-3315's grammar. The
// point of the fixture is that the engine has never heard of these types —
// if closure descends into duo_child it can only be because it read the
// declaration.
$manifestDir = "$tmp/manifests";
mkdir($manifestDir, 0777, true);
copy("$root/manifests/core.json", "$manifestDir/core.json");
// Both fixture adapters carry the name their file already carries: DUO-3371
// refuses a manifest whose declared name is not its file basename, and a
// nameless manifest is that refusal's degenerate case.
put("$manifestDir/duo-scope-fixture.json", Canon::encode([
    'name' => 'duo-scope-fixture',
    'post_types' => [
        'duo_parent' => ['class' => 'authored', 'children' => ['duo_child']],
        'duo_child' => ['class' => 'authored'],
    ],
    'spec_version' => 2,
]));
// A term-keyspace relationship taxonomy (DUO-3316) so term -> term
// `relationships` is a real closure edge here rather than an untested branch.
// Kept in its OWN manifest so the unpinning experiment below can drop the
// parent/child declaration while the tree it compiles stays byte-identical.
put("$manifestDir/duo-scope-taxonomy.json", Canon::encode([
    'name' => 'duo-scope-taxonomy',
    'taxonomies' => [
        'duo_link' => ['object_keyspace' => 'term'],
    ],
    'spec_version' => 2,
]));
putenv("DUO_MANIFESTS_DIR=$manifestDir");

$ids = [
    'topics' => uuid(1), 'news' => uuid(2), 'about' => uuid(3), 'photo' => uuid(4),
    'menu' => uuid(5), 'item' => uuid(6), 'contact' => uuid(7),
    'parentDoc' => uuid(8), 'childDoc' => uuid(9), 'widget' => uuid(10),
    'linked' => uuid(11),
];

$repo = "$tmp/repo";
put("$repo/site.duo.json", Canon::encode([
    'manifests' => ['core', 'duo-scope-taxonomy', 'duo-scope-fixture'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => ['post', 'page', 'attachment', 'duo_parent', 'duo_child'],
        'taxonomies' => ['category', 'post_tag', 'duo_link'],
    ],
    'spec_version' => 2,
]));

// topics <- news (term parent edge), so closure from a page must reach
// `topics` transitively through `news` rather than stopping one hop out.
put("$repo/state/terms/category/{$ids['topics']}--topics.json", Canon::encode([
    'description' => '', 'meta' => (object) [], 'name' => 'Topics', 'parent' => null,
    'relationships' => (object) [], 'slug' => 'topics', 'taxonomy' => 'category', 'uuid' => $ids['topics'],
]));
put("$repo/state/terms/category/{$ids['news']}--news.json", Canon::encode([
    'description' => '', 'meta' => (object) [], 'name' => 'News', 'parent' => $ids['topics'],
    'relationships' => (object) ['duo_link' => [$ids['linked']]],
    'slug' => 'news', 'taxonomy' => 'category', 'uuid' => $ids['news'],
]));
put("$repo/state/terms/category/{$ids['linked']}--linked.json", Canon::encode([
    'description' => '', 'meta' => (object) [], 'name' => 'Linked', 'parent' => null,
    'relationships' => (object) [], 'slug' => 'linked', 'taxonomy' => 'category', 'uuid' => $ids['linked'],
]));

$mediaBytes = "duo-scope-media\n";
$mediaHash = hash('sha256', $mediaBytes);
put("$repo/media/$mediaHash.txt", $mediaBytes);
$photo = post_front($ids['photo'], 'attachment', 'photo');
$photo += ['alt' => 'Photo', 'file' => 'photo.txt', 'media' => "$mediaHash.txt", 'mime' => 'text/plain'];
put("$repo/state/posts/attachment/{$ids['photo']}--photo.md", Canon::post_file($photo, ''));

// The page reaches its term through `terms` and its attachment through a
// body token — two structurally different edge shapes, one closure.
$about = post_front($ids['about'], 'page', 'about');
$about['terms'] = (object) ['category' => [$ids['news']]];
put("$repo/state/posts/page/{$ids['about']}--about.md", Canon::post_file(
    $about,
    '<!-- wp:image {"id":"{{post:' . $ids['photo'] . '}}"} --><figure></figure><!-- /wp:image -->'
));

put("$repo/state/posts/page/{$ids['contact']}--contact.md", Canon::post_file(
    post_front($ids['contact'], 'page', 'contact'),
    ''
));

$parentDoc = post_front($ids['parentDoc'], 'duo_parent', 'parent-doc');
put("$repo/state/posts/duo_parent/{$ids['parentDoc']}--parent-doc.md", Canon::post_file($parentDoc, ''));
$childDoc = post_front($ids['childDoc'], 'duo_child', 'child-doc');
$childDoc['parent'] = '{{post:' . $ids['parentDoc'] . '}}';
put("$repo/state/posts/duo_child/{$ids['childDoc']}--child-doc.md", Canon::post_file($childDoc, ''));

put("$repo/state/menus/main.json", Canon::encode([
    'items' => [[
        'attr_title' => '', 'classes' => [], 'object' => 'page', 'parent' => null,
        'position' => 1, 'ref' => '{{post:' . $ids['about'] . '}}', 'target' => '',
        'title' => 'About', 'type' => 'post_type', 'uuid' => $ids['item'], 'xfn' => '',
    ]],
    'locations' => [], 'name' => 'Main', 'slug' => 'main', 'uuid' => $ids['menu'],
]));
put("$repo/state/sidebars/sidebar-1.json", Canon::encode([
    'widgets' => [[
        'uuid' => $ids['widget'], 'type' => 'nav_menu',
        'settings' => (object) ['nav_menu' => '{{term:' . $ids['news'] . '}}', 'title' => 'Menu'],
    ]],
]));
put("$repo/state/options/core.json", option_records([
    'blogname' => OptionState::present('Duo', 'yes'),
    'default_category' => OptionState::present('{{term:' . $ids['news'] . '}}', 'yes'),
    'page_on_front' => OptionState::present('{{post:' . $ids['about'] . '}}', 'yes'),
    'show_on_front' => OptionState::present('page', 'yes'),
]));

$policy = Policy::load($repo);
$compiled = RepositoryCompiler::compile($repo, $policy);

// ---------------------------------------------------------------- closure

$report = ScopeClosure::resolve($compiled, $policy, ['post:' . $ids['about']]);
$includedPaths = array_column($report['included'], 'path');
$reasonByPath = array_column($report['included'], 'reason', 'path');
$fromByPath = array_column($report['included'], 'locator', 'path');

check($report['format'] === 'duo-scope/v1', 'report carries its own versioned format field');
check(
    in_array("posts/page/{$ids['about']}--about.md", $includedPaths, true)
    && $reasonByPath["posts/page/{$ids['about']}--about.md"] === 'root',
    'the requested root is in scope and labelled a root'
);
// The locator is also the regression for a second defect the single
// enumeration retired: "terms.$tax[$i]" interpolates as a string OFFSET into
// $tax, so every term-assignment locator — in scope provenance and in the
// compiler's own dangling-reference diagnostics — used to read "terms.c".
check(
    in_array("terms/category/{$ids['news']}--news.json", $includedPaths, true)
    && $reasonByPath["terms/category/{$ids['news']}--news.json"] === 'term'
    && $fromByPath["terms/category/{$ids['news']}--news.json"] === 'terms.category[0]',
    'a term assignment pulls the term in, and the row names the exact edge that did it'
);
check(
    in_array("terms/category/{$ids['topics']}--topics.json", $includedPaths, true)
    && $reasonByPath["terms/category/{$ids['topics']}--topics.json"] === 'parent',
    'closure is transitive: the term parent of an included term joins too'
);
check(
    in_array("terms/category/{$ids['linked']}--linked.json", $includedPaths, true)
    && $reasonByPath["terms/category/{$ids['linked']}--linked.json"] === 'relationship'
    && $fromByPath["terms/category/{$ids['linked']}--linked.json"] === 'relationships.duo_link[0]',
    'a declared term-keyspace relationship (DUO-3316) is a closure edge with its own provenance'
);
check(
    in_array("posts/attachment/{$ids['photo']}--photo.md", $includedPaths, true)
    && $reasonByPath["posts/attachment/{$ids['photo']}--photo.md"] === 'reference',
    'an attachment referenced only from a block attribute inside the post body is closed over'
);
check($report['media'] === ["$mediaHash.txt"], 'the media blob owned by an included attachment is named');
check(
    !in_array("posts/page/{$ids['contact']}--contact.md", $includedPaths, true),
    'unrelated state stays out of the scope'
);
check(
    !in_array('options/core.json', $includedPaths, true)
    && !in_array('menus/main.json', $includedPaths, true),
    'entities that merely POINT AT the scope are not dragged into it'
);

$inboundPairs = array_map(
    static fn(array $r): string => $r['path'] . ' ' . $r['locator'],
    $report['inbound']
);
check(
    in_array('menus/main.json $.items[0].ref', $inboundPairs, true)
    && in_array('sidebars/sidebar-1.json $.widgets[0].settings.nav_menu', $inboundPairs, true),
    'inbound referrers are reported with the exact locator that points into the scope'
);
check(
    count(array_filter($report['inbound'], static fn(array $r): bool => $r['path'] === 'options/core.json')) === 2,
    'both option references into the scope are reported, not collapsed to one row'
);
check(
    $report['excluded']['total'] === count($compiled->tree()) - count($report['included']),
    'excluded count and included count partition the revision exactly'
);
check(
    ($report['excluded']['by_type']['post'] ?? 0) === 3,
    'excluded state is broken down by entity type (contact + the two fixture docs)'
);

// ------------------------------------------- DUO-3315 declared child descent

$parentScope = ScopeClosure::resolve($compiled, $policy, ['post:' . $ids['parentDoc']]);
$parentPaths = array_column($parentScope['included'], 'path');
$parentReasons = array_column($parentScope['included'], 'reason', 'path');
check(
    in_array("posts/duo_child/{$ids['childDoc']}--child-doc.md", $parentPaths, true)
    && $parentReasons["posts/duo_child/{$ids['childDoc']}--child-doc.md"] === 'declared_child',
    'a declared child post type descends from its parent root (DUO-3315 grammar, no engine branch)'
);
$childScope = ScopeClosure::resolve($compiled, $policy, ['post:' . $ids['childDoc']]);
check(
    in_array("posts/duo_parent/{$ids['parentDoc']}--parent-doc.md", array_column($childScope['included'], 'path'), true),
    'a child root still carries its parent, which it references and cannot stand without'
);

// The engine must learn the relation only from the manifest. With the
// fixture adapter unpinned, the identical tree must NOT descend.
put("$repo/site.duo.json", Canon::encode([
    'manifests' => ['core', 'duo-scope-taxonomy'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => ['post', 'page', 'attachment', 'duo_parent', 'duo_child'],
        'taxonomies' => ['category', 'post_tag', 'duo_link'],
    ],
    'spec_version' => 2,
]));
$unpinnedPolicy = Policy::load($repo);
$unpinned = ScopeClosure::resolve(
    RepositoryCompiler::compile($repo, $unpinnedPolicy),
    $unpinnedPolicy,
    ['post:' . $ids['parentDoc']]
);
check(
    !in_array("posts/duo_child/{$ids['childDoc']}--child-doc.md", array_column($unpinned['included'], 'path'), true),
    'without the manifest declaration the same tree does not descend — the edge is declared data, never inferred'
);

// -------------------------------------------------------- root resolution

$refusals = [
    'post:' . $ids['news'] => 'a uuid of the wrong entity type is refused rather than silently retyped',
    'post:' . uuid(999) => 'a root naming no entity in the revision is refused',
    'post:' . $ids['item'] => 'a menu-item uuid is refused with the owning menu named',
    'menu:nope' => 'a menu slug that does not exist is refused',
    'path:posts/page/nope.md' => 'a path that names no entity is refused',
    'nonsense' => 'an unparseable selector is refused with the grammar restated',
];
foreach ($refusals as $selector => $message) {
    try {
        ScopeClosure::resolve($compiled, $policy, [$selector]);
        check(false, $message);
    } catch (RuntimeException $e) {
        check(true, $message . ' (' . $e->getMessage() . ')');
    }
}
try {
    ScopeClosure::resolve($compiled, $policy, []);
    check(false, 'an empty root set is refused');
} catch (RuntimeException $e) {
    check(true, 'an empty root set is refused rather than resolving to an empty scope');
}

$byPath = ScopeClosure::resolve($compiled, $policy, ['path:posts/page/' . $ids['about'] . '--about.md']);
check(
    array_column($byPath['included'], 'path') === $includedPaths,
    'a path selector and a uuid selector for the same entity resolve to the identical scope'
);

// ------------------------------------------------------- the all-roots scope

// One entity is one root however many selectors named it, or roots would
// exceed included and the summary line would read as nonsense.
$duped = ScopeClosure::resolve($compiled, $policy, [
    'post:' . $ids['about'],
    'path:posts/page/' . $ids['about'] . '--about.md',
]);
check(
    $duped['totals']['roots'] === 1
    && count($duped['roots']) === 1
    && $duped['totals']['roots'] <= $duped['totals']['included']
    && $duped['totals']['closure'] === $duped['totals']['included'] - 1,
    'two selectors naming one entity resolve to a single root, and the totals stay coherent'
);

$all = ScopeClosure::resolve($compiled, $policy, ['all']);
check(
    $all['excluded']['total'] === 0 && count($all['included']) === count($compiled->tree()),
    'full-site operation is the same model with an all-roots scope, not a second code path'
);
check($all['inbound'] === [], 'nothing is inbound to a scope that contains everything');

// ------------------------------------------------- DUO-3344: option:<name> roots

// default_category references the news term; page_on_front references the
// about page. Selecting one by name must close over exactly its OWN
// reference and nobody else's -- proving real per-option attribution, not
// "selecting any option conservatively includes the whole options surface".
$byDefaultCategory = ScopeClosure::resolve($compiled, $policy, ['option:default_category']);
$dcPaths = array_column($byDefaultCategory['included'], 'path');
$dcRowsByEntity = array_column($byDefaultCategory['included'], null, 'entity');
$dcRoot = $dcRowsByEntity['options/core#default_category'] ?? null;
check(
    $dcRoot !== null && $dcRoot['type'] === 'option' && $dcRoot['option'] === 'default_category'
    && $dcRoot['path'] === 'options/core.json' && $dcRoot['reason'] === 'root'
    && $dcRoot['selector'] === 'option:default_category',
    'an option root carries its own name, type, and file path in its included row'
);
check(
    in_array("terms/category/{$ids['news']}--news.json", $dcPaths, true)
    && in_array("terms/category/{$ids['topics']}--topics.json", $dcPaths, true),
    'option:default_category closes over the news term it references, transitively through its parent'
);
check(
    !in_array("posts/page/{$ids['about']}--about.md", $dcPaths, true)
    && !in_array("posts/attachment/{$ids['photo']}--photo.md", $dcPaths, true),
    'option:default_category does NOT pull in page_on_front\'s own target -- attribution is per-option, not per-surface'
);
check(
    $byDefaultCategory['roots'][0]['type'] === 'option' && $byDefaultCategory['roots'][0]['option'] === 'default_category',
    'the roots summary also carries the option name and type, not just the entity key'
);

$byPageOnFront = ScopeClosure::resolve($compiled, $policy, ['option:page_on_front']);
$pofPaths = array_column($byPageOnFront['included'], 'path');
check(
    in_array("posts/page/{$ids['about']}--about.md", $pofPaths, true)
    && in_array("posts/attachment/{$ids['photo']}--photo.md", $pofPaths, true),
    'option:page_on_front closes over the about page it references, transitively through its own attachment'
);
// NOT a disjointness check against default_category: about's OWN terms
// assignment (line ~173 above) legitimately pulls news/topics/linked in
// too, transitively, exactly as post:about's own closure already proves
// above -- that overlap is correct closure, not a leak from the option
// surface. The asymmetric direction (default_category does NOT pull in
// page_on_front's target, checked above) is the real precision proof: if
// attribution were merely "any selected option conservatively includes the
// whole options surface", that check would fail too.

$byBlogname = ScopeClosure::resolve($compiled, $policy, ['option:blogname']);
check(
    count($byBlogname['included']) === 1 && $byBlogname['totals']['closure'] === 0,
    'an option whose value holds no reference closes over nothing but itself'
);

// options/core must never be counted as wholly excluded while one of its
// own options is included -- that would report the same file as both
// included and excluded at once.
check(
    ($byDefaultCategory['excluded']['by_type']['options'] ?? 0) === 0,
    'options/core is never double-counted as excluded while an option root of its own is included'
);

try {
    ScopeClosure::resolve($compiled, $policy, ['option:not_a_real_option']);
    check(false, 'a root selector naming an unauthored option is refused');
} catch (RuntimeException $e) {
    check(true, 'a root selector naming an unauthored option is refused (' . $e->getMessage() . ')');
}

// A genuinely ambiguous locator: two authored options, "n" and "n.value",
// constructed so a token nested inside "n"'s OWN value and a token directly
// in "n.value"'s OWN value produce the byte-identical locator string
// "$.records.n.value.value" -- proving the attribution algorithm refuses
// rather than silently guessing via e.g. longest-prefix-wins, which is not
// always correct once a value can be arbitrarily nested (see
// ScopeClosure::attribute_option_edges()'s own docblock).
$ambiguousRepo = "$tmp/ambiguous-option-names";
$ambiguousManifestDir = "$ambiguousRepo/manifests";
mkdir($ambiguousManifestDir, 0777, true);
copy("$root/manifests/core.json", "$ambiguousManifestDir/core.json");
put("$ambiguousManifestDir/duo-ambiguous-fixture.json", Canon::encode([
    'name' => 'duo-ambiguous-fixture',
    'options' => [
        'n' => ['class' => 'authored', 'autoload' => 'yes'],
        'n.value' => ['class' => 'authored', 'autoload' => 'yes'],
        'unrelated_option' => ['class' => 'authored', 'autoload' => 'yes'],
    ],
    'spec_version' => 2,
]));
putenv("DUO_MANIFESTS_DIR=$ambiguousManifestDir");
put("$ambiguousRepo/site.duo.json", Canon::encode([
    'manifests' => ['core', 'duo-ambiguous-fixture'],
    'policy' => [
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
        'post_types' => ['post', 'page', 'attachment'], 'taxonomies' => ['category', 'post_tag'],
    ],
    'spec_version' => 2,
]));
$decoy = uuid(101);
put("$ambiguousRepo/state/terms/category/$decoy--decoy.json", Canon::encode([
    'description' => '', 'meta' => (object) [], 'name' => 'Decoy', 'parent' => null,
    'relationships' => (object) [], 'slug' => 'decoy', 'taxonomy' => 'category', 'uuid' => $decoy,
]));
put("$ambiguousRepo/state/options/core.json", option_records([
    'n' => OptionState::present(['value' => '{{term:' . $decoy . '}}'], 'yes'),
    'n.value' => OptionState::present('{{term:' . $decoy . '}}', 'yes'),
    'unrelated_option' => OptionState::present('plain string, no reference', 'yes'),
]));
$ambiguousPolicy = Policy::load($ambiguousRepo);
$ambiguousCompiled = RepositoryCompiler::compile($ambiguousRepo, $ambiguousPolicy);
putenv("DUO_MANIFESTS_DIR=$manifestDir");
try {
    ScopeClosure::resolve($ambiguousCompiled, $ambiguousPolicy, ['option:n']);
    check(false, 'a locator matching two authored option names at once is refused rather than guessed');
} catch (RuntimeException $e) {
    check(
        str_contains($e->getMessage(), "'n'") && str_contains($e->getMessage(), "'n.value'"),
        'a locator matching two authored option names at once is refused, naming both candidates ('
            . $e->getMessage() . ')'
    );
}
// The 'n'/'n.value' collision above must not leak into an UNRELATED
// option's own resolution -- attribution ambiguity is scoped to what was
// actually requested, not the whole document (see
// ScopeClosure::attribute_option_edges()'s own docblock on this exact
// point).
try {
    $unrelated = ScopeClosure::resolve($ambiguousCompiled, $ambiguousPolicy, ['option:unrelated_option']);
    check(
        count($unrelated['included']) === 1 && $unrelated['totals']['closure'] === 0,
        'a colliding pair of option names elsewhere in the SAME document does not block an unrelated option\'s own resolution'
    );
} catch (RuntimeException $e) {
    check(false, 'a colliding pair of option names elsewhere in the SAME document does not block an unrelated option\'s own resolution (' . $e->getMessage() . ')');
}

// A contract can bind the exact option record as immutable evidence. Capture
// and refresh own record-aware carrier overlays, but the generic guard remains
// mandatory for consumers that still own whole-document mutation mechanics.
$defaultCategoryContract = ScopeContract::resolve($compiled, $policy, ['option:default_category']);
$defaultCategoryRoot = $defaultCategoryContract['live']['roots'][0] ?? null;
check(
    is_array($defaultCategoryRoot)
        && ($defaultCategoryRoot['entity'] ?? null) === 'options/core#default_category'
        && ($defaultCategoryRoot['type'] ?? null) === 'option'
        && ($defaultCategoryRoot['option'] ?? null) === 'default_category'
        && in_array('option:default_category', (array) ($defaultCategoryContract['eligible_surfaces'] ?? []), true)
        && !in_array('option:page_on_front', (array) ($defaultCategoryContract['eligible_surfaces'] ?? []), true),
    'a scope contract binds one exact option record without publishing unrelated option authority'
);
ScopeContract::assert_associated($defaultCategoryContract, $compiled, $policy);
try {
    ScopeContract::assert_mutation_supported($defaultCategoryContract, 'scoped plan/apply');
    check(false, 'the option contract is refused before scoped mutation work');
} catch (RuntimeException $e) {
    check(str_contains($e->getMessage(), 'does not support per-option scoped mutation'),
        'the option contract remains explicitly refused for a whole-document mutation consumer');
}
check(
    ScopeContract::resolve($compiled, $policy, ['options'])['format'] === ScopeContract::FORMAT,
    'the whole-surface "options" selector is unaffected and still mints a contract normally'
);

// ------------------------------------------------- determinism and offline-ness

$again = ScopeClosure::resolve($compiled, $policy, ['post:' . $ids['about']]);
check($again === $report, 'resolution is deterministic for the same revision and roots');

// -------------------------- the parent graph is one enumeration, not two

// Regression for the defect this slice retired: RepositoryCompiler's inline
// graph walk reused the tree's loop variable as its terms loop variable, so
// a post carrying ANY term assignment filed its post_parent edge under the
// last term's uuid. A genuine parent cycle then compiled clean. The two
// repositories below differ by exactly one category assignment and must be
// refused identically.
function cycle_repo(string $repo, bool $withTerms): void {
    global $tmp;
    $term = uuid(1);
    $a = uuid(2);
    $b = uuid(3);
    put("$repo/site.duo.json", Canon::encode([
        'manifests' => ['core'],
        'policy' => [
            'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [],
            'post_types' => ['post', 'page', 'attachment'],
            'taxonomies' => ['category', 'post_tag'],
        ],
        'spec_version' => 2,
    ]));
    put("$repo/state/terms/category/$term--news.json", Canon::encode([
        'description' => '', 'meta' => (object) [], 'name' => 'News', 'parent' => null,
        'relationships' => (object) [], 'slug' => 'news', 'taxonomy' => 'category', 'uuid' => $term,
    ]));
    foreach ([[$a, 'alpha', $b], [$b, 'beta', $a]] as [$id, $slug, $parent]) {
        $front = post_front($id, 'page', $slug);
        $front['parent'] = '{{post:' . $parent . '}}';
        if ($withTerms) {
            $front['terms'] = (object) ['category' => [$term]];
        }
        put("$repo/state/posts/page/$id--$slug.md", Canon::post_file($front, ''));
    }
    put("$repo/state/options/core.json", option_records([]));
}

$cycleCodes = [];
foreach (['plain', 'categorized'] as $variant) {
    $cycleRepo = "$tmp/cycle-$variant";
    cycle_repo($cycleRepo, $variant === 'categorized');
    try {
        RepositoryCompiler::compile($cycleRepo, Policy::load($cycleRepo));
        $cycleCodes[$variant] = [];
    } catch (RepositoryCompilationException $e) {
        $cycleCodes[$variant] = array_values(array_unique(array_column($e->payload()['diagnostics'], 'code')));
    }
}
check(
    $cycleCodes['plain'] === ['reference_cycle'],
    'a parent cycle among uncategorized posts is refused (unchanged behavior)'
);
check(
    $cycleCodes['categorized'] === ['reference_cycle'],
    'the SAME parent cycle is still refused once the posts carry a term — the graph is keyed by the post, not by whatever the previous inline walk left in scope'
);

// ------------------------------------- the graph is generic over declarations

$edges = ReferenceGraph::edges($compiled->tree(), $policy);
$relations = array_values(array_unique(array_column($edges, 'relation')));
sort($relations, SORT_STRING);
check(
    $relations === ['parent', 'reference', 'relationship', 'term'],
    'one enumeration produces every declared relation kind present in the revision'
);
check(
    count(array_filter(
        $edges,
        static fn(array $e): bool => $e['from'] === 'options/core' && $e['relation'] === 'reference'
    )) === 2,
    'option references are enumerated from the options record like any other entity'
);
$aboutEdges = array_filter($edges, static fn(array $e): bool => $e['from'] === $ids['about']);
check(
    count(array_filter($aboutEdges, static fn(array $e): bool => str_starts_with($e['locator'], 'body'))) === 1,
    'a token inside a post body is enumerated with a body-rooted locator'
);

echo "\n";
if ($failures) {
    fwrite(STDERR, count($failures) . " check(s) failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "PASS: scope closure resolves declared edges, refuses unresolvable roots, and excludes unrelated state\n";
