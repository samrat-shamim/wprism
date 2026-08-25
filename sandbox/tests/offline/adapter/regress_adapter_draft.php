<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for DUO-3325:
 * `duo adapter-draft`, the safe adapter-DRAFT generator (offline slice).
 *
 * Nothing is faked. Every check runs the REAL host CLI as a subprocess —
 * `php cli/duo adapter-draft …` and `php cli/duo manifest-validate …` — against
 * REAL fixture site-repos this test writes to a scratch directory, reading the
 * real exit code and stdout/stderr. That is the whole point: the command is
 * WordPress-free by construction (it boots the pure half of Policy::load(), the
 * same half regress_manifest_validate.php and regress_export_manifest_roundtrip.php
 * already exercise), so the honest way to test it is to run it.
 *
 * Covers the design's suite plan 1–8. The load-bearing one is #2 (INERTNESS): a
 * `_draft.proposals.tables[…]` referencing an UNDECLARED id_kind must still
 * `manifest-validate` `ok`, because the trigger keys are RENAMED so the blind
 * ref-kind walk (ReferenceKindGrammar::collect_ref_kind_claims) cannot collect it — and the same
 * fragment with ONE trigger key un-renamed must then FAIL with the closed-vocabulary
 * refusal, proving the rename is what makes the sidecar inert.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and the
 * script exits 1.
 */

$repo = dirname(__DIR__, 4);

if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('SPEC', (int) $m[1]);
/**
 * WP-4.12: the version a document CARRYING `_draft` may declare.
 *
 * § v3.3 closes the top-level key set at spec_version 3 and refuses `_draft`
 * there by name, so after the flip a draft stamped at SPEC is a document the
 * engine's own validator rejects — which is exactly what `duo adapter-draft`
 * started doing on the bumped tree until `AdapterDraft::draft_spec_version()`
 * began choosing the stamp from the rule. The hand-built fixtures below carry
 * the sidecar without going through that method, so they follow the same rule
 * here; anything that does NOT carry `_draft` stays at SPEC.
 */
define('DRAFT_SPEC', SPEC - 1);

// The engine, in-process, for the ONE structural check that is a direct call
// rather than a subprocess: AdapterSources::assert_out_of_tree_contract() is the
// exact refusal a draft-turned-site-adapter must pass, so test 8 calls it. (Every
// other check runs the real CLI as a subprocess.) Requiring Policy pulls
// NativeActions/AdapterSources/ReferenceRules; none reaches WordPress at load.
require $repo . '/agent/src/Kernel/Canon.php';
require $repo . '/agent/src/Kernel/OptionState.php';
require $repo . '/agent/src/Policy/Policy.php';

$root = sys_get_temp_dir() . '/duo_regress_adapter_draft_' . bin2hex(random_bytes(4));
mkdir($root, 0777, true);
register_shutdown_function(function () use ($root) {
    if (!is_dir($root)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($root);
});

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function wr(string $path, string $content): void {
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
}
function wrj(string $path, array $data): void {
    wr($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Run the real host CLI as a subprocess with an explicit manifest library.
 * @return array{exit:int, stdout:string, stderr:string}
 */
function duo(array $args, ?string $lib = null): array {
    global $repo;
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/cli/duo');
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    $env = getenv();
    if ($lib !== null) {
        $env['DUO_MANIFESTS_DIR'] = $lib;
    } else {
        unset($env['DUO_MANIFESTS_DIR']);
    }
    $pipes = [];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new \RuntimeException("could not run: $cmd");
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** A minimal core-only manifest library every site.duo.json pins. */
function make_lib(string $dir): string {
    wrj($dir . '/core.json', ['name' => 'core', 'spec_version' => SPEC, 'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) []]);
    return $dir;
}

/** A site.duo.json with the given policy sections. */
function make_site(string $repoDir, array $policy = []): void {
    wrj($repoDir . '/site.duo.json', [
        'manifests' => ['core'],
        'spec_version' => SPEC,
        'policy' => $policy + ['options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [], 'post_types' => (object) [], 'taxonomies' => (object) []],
    ]);
}

/** Decode a generated draft artifact (--format=json). */
function gen_draft(string $repoDir, string $name, string $lib, array $extra = []): array {
    $r = duo(array_merge(['adapter-draft', $repoDir, '--name=' . $name, '--format=json'], $extra), $lib);
    if ($r['exit'] !== 0) {
        throw new \RuntimeException("adapter-draft failed ({$r['exit']}): {$r['stderr']}");
    }
    return json_decode($r['stdout'], true);
}

/** Compare decoded JSON values with the engine's canonical semantic encoding. */
function same_canon(mixed $left, mixed $right): bool {
    return \Duo\Canon::encode($left) === \Duo\Canon::encode($right);
}

/** @return array<string,array<string,mixed>> generated unsupported candidates by structural evidence locator. */
function unsupported_by_locator(array $draft): array {
    $out = [];
    foreach ((array) ($draft['_draft']['unsupported'] ?? []) as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }
        $locator = $candidate['evidence'][0]['locator'] ?? null;
        if (is_string($locator)) {
            $out[$locator] = $candidate;
        }
    }
    return $out;
}

/** @return array<string,array<string,mixed>> unsupported candidates by their identity target. */
function unsupported_by_target(array $draft): array {
    $out = [];
    foreach ((array) ($draft['_draft']['unsupported'] ?? []) as $candidate) {
        if (is_array($candidate) && is_string($candidate['target'] ?? null)) {
            $out[$candidate['target']] = $candidate;
        }
    }
    return $out;
}

echo "\n== 1. envelope + validate acceptance ==\n";
{
    $t = "$root/t1";
    $lib = make_lib("$t/lib");
    make_site("$t/repo", ['options' => ['my_hero_id' => ['class' => 'authored', 'autoload' => 'preserve']]]);
    wrj("$t/repo/state/tables/nf3_forms/r1.json", ['table' => 'nf3_forms', 'columns' => ['id' => 1, 'slug' => 'contact', 'title' => 'Contact']]);
    wrj("$t/repo/state/tables/nf3_forms/r2.json", ['table' => 'nf3_forms', 'columns' => ['id' => 2, 'slug' => 'signup', 'title' => 'Signup']]);
    wr("$t/repo/state/posts/page/home.md", "---\n" . json_encode(['type' => 'page', 'meta' => ['hero_id' => 42]]) . "\n---\n<!-- wp:core/image {\"id\":42} -->\n<figure></figure>\n<!-- /wp:core/image -->\n");

    $draft = gen_draft("$t/repo", 'nf-draft', $lib);
    check(($draft['options']['my_hero_id']['class'] ?? null) === 'authored', 'classified option is a FACT in the real options section');
    check(!str_contains(json_encode($draft['_draft']), 'my_hero_id'), 'facts are NOT duplicated into _draft (facts live only in real sections)');
    check(isset($draft['_draft']), 'the draft carries a top-level _draft sidecar');
    check(($draft['_draft']['format'] ?? '') === 'duo-adapter-draft/v1', '_draft declares the duo-adapter-draft/v1 format');
    $tables = $draft['_draft']['proposals']['tables'] ?? [];
    check(count($tables) === 1 && $tables[0]['target'] === 'tables.nf3_forms', 'observed table is a PROPOSAL under _draft, not a fact');
    // facts sections carry no `tables` key (facts core is options/post_meta/…)
    check(!isset($draft['tables']), 'no live top-level tables section mirrors the proposal (inert by construction)');

    // Feed the draft to the REAL manifest-validate.
    $md = "$t/md";
    make_lib($md);
    wr($md . '/nf-draft.json', json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $v = duo(['manifest-validate', $md, '--manifest=nf-draft', '--pins=nf-draft,core']);
    check($v['exit'] === 0, 'manifest-validate exits 0 on the draft (facts valid, sidecar inert)');
    check(str_contains($v['stdout'], 'draft:') && str_contains($v['stdout'], 'facts validated'), 'manifest-validate prints the _draft annotation distinguishing facts / proposals / unsupported');
    check(str_contains($v['stdout'], "run 'duo adapter-draft --check-proposals'"), 'the annotation points the author at --check-proposals');

    $invalidName = duo(['adapter-draft', "$t/repo", '--name=../invalid', '--format=json'], $lib);
    check($invalidName['exit'] === 2
        && str_contains($invalidName['stderr'], 'canonical lowercase adapter-name grammar')
        && !str_contains($invalidName['stdout'] . $invalidName['stderr'], '../invalid'),
        'an invalid adapter name is refused through the shared grammar before any prior-artifact path access');
}

echo "\n== 2. INERTNESS (load-bearing): undeclared id_kind under _draft stays ok; un-rename fails ==\n";
{
    $t = "$root/t2";
    $md = "$t/md";
    make_lib($md);
    // A hand-crafted draft whose table proposal references an id_kind no live
    // `tables` section declares. With the trigger key RENAMED (proposed_refs) the
    // blind walk cannot reach it; the manifest must validate ok.
    $tableFrag = [
        'class' => 'authored_snapshot',
        'pk' => 'id',
        'proposed_refs' => [['column' => 'owner_id', 'kind' => 'undeclared_kind_zzz']],
        'columns' => (object) [],
        'identity' => ['mode' => 'mapped'],
    ];
    $draft = [
        'name' => 'inert-draft',
        'spec_version' => DRAFT_SPEC,
        'options' => (object) [], 'post_meta' => (object) [], 'term_meta' => (object) [], 'user_meta' => (object) [],
        '_draft' => [
            'format' => 'duo-adapter-draft/v1',
            'proposals' => ['tables' => [[
                'target' => 'tables.widgets', 'candidate' => $tableFrag,
                'status' => 'proposal', 'confidence' => 0.3, 'evidence' => [], 'questions' => [],
            ]]],
            'unsupported' => [],
            '_meta' => (object) [],
        ],
    ];
    wr($md . '/inert-draft.json', json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $v = duo(['manifest-validate', $md, '--manifest=inert-draft', '--pins=inert-draft,core']);
    check($v['exit'] === 0, 'a draft whose _draft table proposal names an UNDECLARED id_kind still validates ok (sidecar is inert)');
    check(!str_contains($v['stdout'], 'kind vocabulary is closed'), 'the closed-vocabulary refusal is NOT tripped by the renamed proposal');

    // MUTATION: un-rename ONE trigger key (proposed_refs -> refs). The blind walk
    // now reaches the undeclared kind and the closed-vocabulary refusal MUST fire.
    $mutated = $draft;
    $frag = $mutated['_draft']['proposals']['tables'][0]['candidate'];
    $frag['refs'] = $frag['proposed_refs'];
    unset($frag['proposed_refs']);
    $mutated['_draft']['proposals']['tables'][0]['candidate'] = $frag;
    $mutated['name'] = 'mutant-draft';
    wr($md . '/mutant-draft.json', json_encode($mutated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $vm = duo(['manifest-validate', $md, '--manifest=mutant-draft', '--pins=mutant-draft,core']);
    check($vm['exit'] !== 0, 'MUTATION PROOF: un-renaming the trigger key makes manifest-validate FAIL — the rename is load-bearing');
    check(str_contains($vm['stdout'] . $vm['stderr'], 'kind vocabulary is closed'), 'the un-renamed proposal trips exactly the closed-vocabulary refusal the rename prevents');
}

echo "\n== 3. per-proposer evidence / confidence / questions ==\n";
{
    $t = "$root/t3";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    wrj("$t/repo/state/tables/rooms/r1.json", ['table' => 'rooms', 'columns' => ['id' => 1, 'code' => 'A1']]);
    wrj("$t/repo/state/tables/rooms/r2.json", ['table' => 'rooms', 'columns' => ['id' => 2, 'code' => 'B2']]);
    $entityOne = '44444444-4444-4444-8444-444444444444';
    $entityTwo = '55555555-5555-4555-8555-555555555555';
    wr("$t/repo/state/posts/page/{$entityOne}--local.md", "---\n" . json_encode([
        'type' => 'page', 'meta' => ['related_id' => 99, 'mixed_ref' => 55],
    ]) . "\n---\n<!-- wp:core/gallery {\"ids\":[3,4],\"parent_id\":7,\"mixed\":7} -->\n<!-- /wp:core/gallery -->\n[gallery ids=\"5,6\" parent_id=\"7\" mixed=\"7\"]\n");
    wr("$t/repo/state/posts/page/{$entityTwo}--local.md", "---\n" . json_encode([
        'type' => 'page', 'meta' => ['related_id' => 101, 'mixed_ref' => [55, 56]],
    ]) . "\n---\n<!-- wp:core/gallery {\"parent_id\":10,\"ids\":[8,9],\"mixed\":[10,11]} -->\n<!-- /wp:core/gallery -->\n[gallery parent_id=\"10\" ids=\"8,9\" mixed=\"10,11\"]\n");
    $draft = gen_draft("$t/repo", 'p-draft', $lib);
    $byTarget = [];
    $byLocator = [];
    foreach (($draft['_draft']['proposals'] ?? []) as $bucket) {
        foreach ($bucket as $c) {
            $byTarget[$c['target']] = $c;
            $locator = $c['evidence'][0]['locator'] ?? null;
            if (is_string($locator)) {
                $byLocator[$locator] = $c;
            }
        }
    }

    $tbl = $byTarget['tables.rooms'] ?? null;
    check($tbl !== null && $tbl['status'] === 'proposal' && $tbl['confidence'] > 0 && $tbl['confidence'] <= 1, 'typed-table candidate is a proposal with a bounded confidence');
    check($tbl && ($tbl['evidence'][0]['locator'] ?? '') === 'columns', 'typed-table evidence names the columns locator');
    check($tbl && H::json_has($tbl['questions'], 'SHOW COLUMNS'), 'typed-table defers column TYPES/PK to a live question');
    check($tbl && ($tbl['candidate']['identity']['mode'] ?? '') === 'natural_key', 'natural-key proposer detected the distinct-value column as an identity candidate');
    check($tbl && H::json_has($tbl['questions'], 'natural-key uniqueness'), 'natural-key uniqueness is deferred to a live question');

    $blk = $byLocator['blocks.core/gallery.attrs.ids'] ?? null;
    check($blk && str_starts_with($blk['evidence'][0]['locator'] ?? '', 'blocks.core/gallery'), 'block-path evidence names the block.attrs locator');
    check($blk && ($blk['candidate']['type'] ?? '') === 'int[]', 'block-path list attribute is typed int[]');
    check($blk && H::json_has($blk['questions'], 'ref kind'), 'block-path ref KIND / live resolution is a question');
    $blkParent = $byLocator['blocks.core/gallery.attrs.parent_id'] ?? null;
    check($blkParent && $blkParent['target'] !== $blk['target']
        && str_starts_with($blkParent['target'], 'block_paths.core/gallery.attrs.'),
        'each block attribute has its own stable structural target instead of last-wins replacement');
    check(($blk['evidence'][0]['count'] ?? null) === 2
        && ($blk['evidence'][0]['source'] ?? null) === 'state/posts'
        && str_contains($blk['evidence'][0]['observation'] ?? '', 'shape=int[]; values omitted'),
        'block evidence aggregates redacted count/shape only, with no entity filename or local id');

    $sc = $byLocator['shortcodes.gallery.ids'] ?? null;
    check($sc && str_starts_with($sc['evidence'][0]['locator'] ?? '', 'shortcodes.gallery'), 'shortcode-path evidence names the shortcode locator');
    $scParent = $byLocator['shortcodes.gallery.parent_id'] ?? null;
    check($scParent && $scParent['target'] !== $sc['target']
        && str_starts_with($scParent['target'], 'shortcode_paths.gallery.attrs.'),
        'each shortcode attribute has its own stable structural target instead of last-wins replacement');
    check(($sc['evidence'][0]['count'] ?? null) === 2
        && ($sc['evidence'][0]['source'] ?? null) === 'state/posts'
        && str_contains($sc['evidence'][0]['observation'] ?? '', 'shape=int[]; values omitted'),
        'shortcode evidence aggregates redacted count/shape only');

    $ref = $byTarget['post_meta.related_id'] ?? null;
    check($ref && ($ref['evidence'][0]['locator'] ?? '') === 'meta.related_id', 'reference candidate names the meta locator');
    check($ref && H::json_has($ref['questions'], 'live id resolution'), 'reference resolution is deferred to a live question');
    check(($ref['evidence'][0]['count'] ?? null) === 2
        && ($ref['evidence'][0]['source'] ?? null) === 'state/post_meta'
        && str_contains($ref['evidence'][0]['observation'] ?? '', 'shape=int; value omitted'),
        'reference evidence aggregates redacted count/shape only');
    foreach ([
        'blocks.core/gallery.attrs.mixed',
        'shortcodes.gallery.mixed',
        'meta.mixed_ref',
    ] as $mixedLocator) {
        $mixed = $byLocator[$mixedLocator] ?? null;
        check($mixed !== null
            && ($mixed['candidate'] ?? null) === []
            && (float) ($mixed['confidence'] ?? -1) === 0.0
            && ($mixed['evidence'][0]['shapes'] ?? null) === ['int', 'int[]']
            && str_contains($mixed['evidence'][0]['observation'] ?? '', 'ambiguous')
            && H::json_has($mixed['questions'] ?? [], 'both scalar and list'),
            "$mixedLocator refuses to choose the first observed scalar/list shape and emits an explicit ambiguity");
    }
    $draftWire = json_encode($draft, JSON_UNESCAPED_SLASHES);
    check(!str_contains($draftWire, $entityOne) && !str_contains($draftWire, $entityTwo)
        && !str_contains($draftWire, 'id-shaped meta value 99')
        && !str_contains($draftWire, 'id-shaped attribute value 3'),
        'fresh proposal evidence contains no entity filename, UUID, or observed local id/value');
}

echo "\n== 4. facts vs proposals vs unsupported ==\n";
{
    $t = "$root/t4";
    $lib = make_lib("$t/lib");
    make_site("$t/repo", ['options' => ['brand_logo' => ['class' => 'authored', 'autoload' => 'preserve']]]);
    wrj("$t/repo/state/tables/orders/r1.json", ['table' => 'orders', 'columns' => ['id' => 1, 'total' => '10']]);
    wr("$t/repo/state/posts/page/g.md", "---\n" . json_encode(['type' => 'page', 'meta' => ['elementor_data' => 'a:2:{s:2:"id";i:7;s:4:"blob";s:5:"hello";}']]) . "\n---\nbody\n");
    $draft = gen_draft("$t/repo", 'fpu-draft', $lib);
    check(($draft['options']['brand_logo']['class'] ?? '') === 'authored', 'a classified option is a FACT in the real options section');
    check(isset($draft['_draft']['proposals']['tables']), 'an observed new table is a PROPOSAL under _draft');
    $uns = $draft['_draft']['unsupported'] ?? [];
    check(count($uns) === 1 && $uns[0]['status'] === 'unsupported', 'a generated/serialized surface is UNSUPPORTED');
    $cand = $uns[0]['candidate'] ?? [];
    check(($cand['source'] ?? '') === 'plugin' && isset($cand['capabilities']), 'unsupported carries a provider capability DECLARATION (source=plugin, capabilities[])');
    $blob = json_encode($cand);
    check(!str_contains($blob, '<?php') && !isset($cand['interpreter']) && !isset($cand['regenerator']) && !isset($cand['regen_dependency']), 'the capability declaration is DATA, never a PHP interpreter/regenerator stub');
}

echo "\n== 5. deletion candidates: required-cascade set matches Deletion::capability, inert + question ==\n";
{
    $t = "$root/t5";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    wr("$t/repo/state/posts/page/a.md", "---\n" . json_encode(['type' => 'page', 'meta' => []]) . "\n---\nx\n");
    wrj("$t/repo/state/terms/category/c1.json", ['taxonomy' => 'category']);
    wrj("$t/repo/state/menus/main.json", ['name' => 'main']);
    wrj("$t/repo/state/tables/widgets/r1.json", ['table' => 'widgets', 'columns' => ['id' => 1]]);
    $draft = gen_draft("$t/repo", 'del-draft', $lib);
    $del = [];
    foreach (($draft['_draft']['proposals']['deletions'] ?? []) as $c) {
        $del[$c['target']] = $c;
    }
    check(($del['deletions.post:page']['candidate']['cascades'] ?? []) === ['postmeta', 'post_revisions', 'term_relationships'], 'post:page required cascades match Deletion::capability post arm');
    check(($del['deletions.term:category']['candidate']['cascades'] ?? []) === ['termmeta', 'term_taxonomy', 'term_relationships'], 'term:category required cascades match Deletion::capability term arm');
    check(($del['deletions.menu:nav_menu']['candidate']['cascades'] ?? []) === ['termmeta', 'term_taxonomy', 'term_relationships', 'menu_items'], 'menu:nav_menu required cascades match Deletion::capability menu arm');
    check(($del['deletions.post:page']['status'] ?? '') === 'proposal' && H::json_has($del['deletions.post:page']['questions'], 'never inferred'), 'a deletion candidate is an inert proposal with a "never inferred" authority question');
    // Drift guard: the static set must still be what Deletion.php declares.
    global $repo;
    $delSrc = (string) file_get_contents($repo . '/agent/src/Delete/Deletion.php');
    check(str_contains($delSrc, "'post' => ['postmeta', 'post_revisions', 'term_relationships']"), 'Deletion.php still declares the post arm this proposer mirrors (drift guard)');
    check(str_contains($delSrc, "'term' => ['termmeta', 'term_taxonomy', 'term_relationships']"), 'Deletion.php still declares the term arm this proposer mirrors (drift guard)');
    check(str_contains($delSrc, "'menu' => ['termmeta', 'term_taxonomy', 'term_relationships', 'menu_items']"), 'Deletion.php still declares the menu arm this proposer mirrors (drift guard)');
}

echo "\n== 6. human-edit preservation across re-observation ==\n";
{
    $t = "$root/t6";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    wrj("$t/repo/state/tables/rooms/r1.json", ['table' => 'rooms', 'columns' => ['id' => 1, 'code' => 'A1']]);
    wrj("$t/repo/state/tables/rooms/r2.json", ['table' => 'rooms', 'columns' => ['id' => 2, 'code' => 'B2']]);

    // Generate and "save" to the site adapter overlay (the prior artifact).
    $g1 = gen_draft("$t/repo", 'room-draft', $lib);
    $adapter = "$t/repo/adapters/room-draft.json";
    wr($adapter, json_encode($g1, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // (a) regenerate with NO edit -> refreshed in place, edited:false.
    $g2 = gen_draft("$t/repo", 'room-draft', $lib);
    check(($g2['_draft']['_meta']['tables.rooms']['edited'] ?? null) === false, 'an unedited candidate is refreshed in place with edited:false');
    check(!empty($g2['_draft']['_meta']['tables.rooms']['generated_hash']), '_meta records the generator content hash');

    // (b) hand-edit the saved candidate WITHOUT touching its stored hash -> preserved.
    $saved = json_decode(file_get_contents($adapter), true);
    $saved['_draft']['proposals']['tables'][0]['candidate']['columns']['code']['class'] = 'derived'; // human change
    wr($adapter, json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $g3 = gen_draft("$t/repo", 'room-draft', $lib);
    $t3 = null;
    foreach ($g3['_draft']['proposals']['tables'] as $c) {
        if ($c['target'] === 'tables.rooms') {
            $t3 = $c;
        }
    }
    check(($t3['candidate']['columns']['code']['class'] ?? '') === 'derived', 'a human edit is PRESERVED across regeneration, never overwritten');
    check(($g3['_draft']['_meta']['tables.rooms']['edited'] ?? null) === true, 'the preserved candidate is marked edited:true');
    check(H::json_has(array_column($t3['evidence'], 'source'), 'regeneration-drift'), 'the fresh observation is recorded as drift under evidence');

    // (c) mark a candidate ratified -> generator never touches it.
    $saved2 = json_decode(file_get_contents($adapter), true);
    $saved2['_draft']['_meta']['tables.rooms']['ratified'] = true;
    $saved2['_draft']['proposals']['tables'][0]['candidate']['columns']['code']['class'] = 'env'; // sentinel
    wr($adapter, json_encode($saved2, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $g4 = gen_draft("$t/repo", 'room-draft', $lib);
    $t4 = null;
    foreach ($g4['_draft']['proposals']['tables'] as $c) {
        if ($c['target'] === 'tables.rooms') {
            $t4 = $c;
        }
    }
    check(($t4['candidate']['columns']['code']['class'] ?? '') === 'env', 'a ratified:true candidate is left untouched by the generator');
    check(($g4['_draft']['_meta']['tables.rooms']['ratified'] ?? null) === true, 'the ratified marker is preserved');
}

echo "\n== 6b. generated-surface identity is structural, redacted, aggregate, and stable across reordering ==\n";
{
    $t = "$root/t6b";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    $post = static function (array $meta): string {
        return "---\n" . json_encode(['type' => 'page', 'meta' => $meta]) . "\n---\nbody\n";
    };
    $uuidA = '11111111-1111-4111-8111-111111111111';
    $uuidB = '22222222-2222-4222-8222-222222222222';
    wr("$t/repo/state/posts/page/{$uuidA}--first.md", $post([
        'alpha_cache' => 'a:1:{s:1:"x";s:1:"a";}',
    ]));
    wr("$t/repo/state/posts/page/{$uuidB}--second.md", $post([
        'alpha_cache' => 'a:1:{s:1:"y";s:1:"b";}',
        'beta_cache' => 'a:1:{s:1:"z";s:1:"c";}',
    ]));

    $g1 = gen_draft("$t/repo", 'structural-draft', $lib);
    $byLocator = unsupported_by_locator($g1);
    $alpha = $byLocator['post_meta.alpha_cache'] ?? null;
    $beta = $byLocator['post_meta.beta_cache'] ?? null;
    check($alpha !== null && $beta !== null, 'two generated meta surfaces are proposed independently by structural locator');
    $alphaTarget = (string) ($alpha['target'] ?? '');
    $betaTarget = (string) ($beta['target'] ?? '');
    check(preg_match('/^unsupported\\.generated\\.[a-f0-9]{64}$/D', $alphaTarget) === 1,
        'generated-surface target is a deterministic full structural digest, not an ordinal index');
    check(!isset(unsupported_by_target($g1)['unsupported.0']), 'a new generated draft names no unsupported.0 ordinal target');
    check(($alpha['evidence'][0]['source'] ?? '') === 'state/post_meta'
        && ($alpha['evidence'][0]['locator'] ?? '') === 'post_meta.alpha_cache',
        'generated evidence uses the surface key, never an entity file path');
    check(str_starts_with((string) ($alpha['evidence'][0]['observation'] ?? ''), '2 redacted observation(s):'),
        'two observations of one structural surface aggregate instead of creating/order-overwriting candidates');
    check(!str_contains(json_encode($g1), $uuidA) && !str_contains(json_encode($g1), $uuidB),
        'generated output contains neither observed entity UUID/file name');

    // Preserve one edited candidate and one ratified candidate, then insert a
    // lexically earlier observation. An ordinal target would have cross-wired
    // either sentinel to the new surface.
    $saved = $g1;
    foreach ($saved['_draft']['unsupported'] as &$candidate) {
        if (($candidate['target'] ?? null) === $alphaTarget) {
            $candidate['candidate']['capabilities'] = ['human_alpha'];
        }
        if (($candidate['target'] ?? null) === $betaTarget) {
            $candidate['candidate']['id'] = 'human-beta';
            $saved['_draft']['_meta'][$betaTarget]['ratified'] = true;
        }
    }
    unset($candidate);
    wr("$t/repo/adapters/structural-draft.json", json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    wr("$t/repo/state/posts/page/00000000-0000-4000-8000-000000000000--inserted.md", $post([
        'aardvark_cache' => 'a:1:{s:1:"n";s:1:"d";}',
    ]));

    $g2 = gen_draft("$t/repo", 'structural-draft', $lib);
    $after = unsupported_by_locator($g2);
    check(($after['post_meta.alpha_cache']['target'] ?? '') === $alphaTarget
        && ($after['post_meta.alpha_cache']['candidate']['capabilities'] ?? []) === ['human_alpha'],
        'an inserted earlier surface cannot attach to or overwrite the alpha human edit');
    check(($after['post_meta.beta_cache']['target'] ?? '') === $betaTarget
        && ($after['post_meta.beta_cache']['candidate']['id'] ?? '') === 'human-beta'
        && ($g2['_draft']['_meta'][$betaTarget]['ratified'] ?? null) === true,
        'an inserted earlier surface cannot attach to or overwrite the beta ratification');
    check(isset($after['post_meta.aardvark_cache'])
        && ($after['post_meta.aardvark_cache']['candidate']['id'] ?? '') !== 'human-beta',
        'the newly inserted structural surface receives its own identity rather than a prior human marker');
}

echo "\n== 6c. legacy ordinal drafts are retained inert, never position-mapped to a fresh surface ==\n";
{
    $t = "$root/t6c";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    wr("$t/repo/state/posts/page/legacy.md", "---\n" . json_encode([
        'type' => 'page', 'meta' => ['legacy_cache' => 'a:1:{s:1:"x";s:1:"y";}'],
    ]) . "\n---\nbody\n");
    $g1 = gen_draft("$t/repo", 'legacy-draft', $lib);
    $legacy = $g1;
    $oldTarget = $legacy['_draft']['unsupported'][0]['target'];
    $legacy['_draft']['unsupported'][0]['target'] = 'unsupported.0';
    $legacy['_draft']['unsupported'][0]['candidate']['id'] = 'legacy-human';
    $legacyMeta = $legacy['_draft']['_meta'][$oldTarget];
    unset($legacy['_draft']['_meta'][$oldTarget]);
    $legacyMeta['ratified'] = true;
    $legacy['_draft']['_meta']['unsupported.0'] = $legacyMeta;
    wr("$t/repo/adapters/legacy-draft.json", json_encode($legacy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $g2 = gen_draft("$t/repo", 'legacy-draft', $lib);
    $targets = unsupported_by_target($g2);
    check(($targets['unsupported.0']['candidate']['id'] ?? '') === 'legacy-human',
        'a legacy ordinal candidate is preserved exactly as an inert candidate');
    check(H::json_has((array) ($targets['unsupported.0']['questions'] ?? []), 'legacy ordinal'),
        'the retained legacy candidate carries an explicit manual-reconciliation question');
    check(($targets['unsupported.0']['evidence'][0]['source'] ?? '') === 'prior-artifact-redacted',
        'legacy ordinal evidence is replaced by a bounded redacted reconciliation row, never replayed raw');
    check(isset(unsupported_by_locator($g2)['post_meta.legacy_cache']),
        'the fresh structural candidate is emitted separately rather than being position-matched to unsupported.0');
}

echo "\n== 6d. prior manifest intent and graduated facts survive a policy export conservatively ==\n";
{
    $t = "$root/t6d";
    $lib = make_lib("$t/lib");
    make_site("$t/repo", [
        'options' => [
            'equal_option' => ['class' => 'authored', 'autoload' => 'preserve'],
            'changed_option' => ['class' => 'authored', 'autoload' => 'preserve'],
            'fresh_option' => ['class' => 'runtime'],
        ],
        'post_meta' => [
            'changed_post_meta' => ['class' => 'authored'],
            'fresh_post_meta' => ['class' => 'runtime'],
        ],
        'term_meta' => [
            'changed_term_meta' => ['class' => 'authored'],
            'fresh_term_meta' => ['class' => 'runtime'],
        ],
        'user_meta' => [
            'changed_user_meta' => ['class' => 'authored'],
            'fresh_user_meta' => ['class' => 'runtime'],
        ],
    ]);
    $prior = [
        'name' => 'intent-draft',
        'spec_version' => DRAFT_SPEC,
        'options' => [
            'equal_option' => ['class' => 'authored', 'autoload' => 'preserve'],
            'changed_option' => ['class' => 'runtime'],
            'prior_only_option' => ['class' => 'runtime'],
        ],
        'post_meta' => [
            'changed_post_meta' => ['class' => 'runtime'],
            'prior_only_post_meta' => ['class' => 'runtime'],
        ],
        'term_meta' => [
            'changed_term_meta' => ['class' => 'runtime'],
            'prior_only_term_meta' => ['class' => 'runtime'],
        ],
        'user_meta' => [
            'changed_user_meta' => ['class' => 'runtime'],
            'prior_only_user_meta' => ['class' => 'runtime'],
        ],
        'plugin' => 'hand-plugin/hand-plugin.php',
        'version_range' => ['min' => '1.0.0', 'max' => '1.9.9'],
        'actions' => [[
            'kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'hand_cache'],
        ]],
        'providers' => [[
            'id' => 'hand-provider', 'plugin' => 'hand-plugin/hand-plugin.php', 'source' => 'plugin',
            'version' => '1.0.0', 'capabilities' => ['rebuild_hand'],
        ]],
        'notes' => ['human' => 'retain this exact editorial intent'],
        'custom_declaration' => ['nested' => ['retain' => true]],
        '_draft' => ['format' => 'duo-adapter-draft/v1', 'proposals' => (object) [], 'unsupported' => [], '_meta' => (object) []],
    ];
    wr("$t/repo/adapters/intent-draft.json", json_encode($prior, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $out = gen_draft("$t/repo", 'intent-draft', $lib);

    foreach (['plugin', 'version_range', 'actions', 'providers', 'notes', 'custom_declaration'] as $key) {
        check(array_key_exists($key, $out) && same_canon($out[$key], $prior[$key]),
            "hand-authored top-level '$key' is preserved as a whole declaration");
    }
    check(same_canon($out['options']['equal_option'], $prior['options']['equal_option'])
        && same_canon($out['options']['fresh_option'], ['class' => 'runtime']),
        'equal prior option stays equal and a fresh absent option is added');
    foreach ([
        ['options', 'changed_option', 'prior_only_option'],
        ['post_meta', 'changed_post_meta', 'prior_only_post_meta'],
        ['term_meta', 'changed_term_meta', 'prior_only_term_meta'],
        ['user_meta', 'changed_user_meta', 'prior_only_user_meta'],
    ] as [$section, $changed, $priorOnly]) {
        check(same_canon($out[$section][$changed], $prior[$section][$changed])
            && same_canon($out[$section][$priorOnly], $prior[$section][$priorOnly]),
            "$section preserves a conflicting graduated rule and a rule no longer exported by site policy");
    }
    $conflictSurfaces = array_column((array) ($out['_draft']['classification_conflicts'] ?? []), 'surface');
    foreach (['options.changed_option', 'post_meta.changed_post_meta', 'term_meta.changed_term_meta', 'user_meta.changed_user_meta'] as $surface) {
        check(in_array($surface, $conflictSurfaces, true),
            "the conflicting $surface export is recorded as one stable inert conflict");
    }
    $conflictsJson = json_encode($out['_draft']['classification_conflicts'] ?? []);
    check(!str_contains($conflictsJson, '"candidate"') && !str_contains($conflictsJson, '"class"'),
        'classification conflict records contain only surface/hash/question coordinates, never a live rule fragment');

    $md = "$t/md";
    make_lib($md);
    wr("$md/intent-draft.json", json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $v = duo(['manifest-validate', $md, '--manifest=intent-draft', '--pins=intent-draft,core']);
    check($v['exit'] === 0, 'the preserved intent and inert fact-conflict sidecar still pass the real manifest validator');
}

echo "\n== 6e. prior draft preservation redacts old evidence and refuses unsafe/malformed authority ==\n";
{
    $t = "$root/t6e";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    $uuid = '33333333-3333-4333-8333-333333333333';
    $token = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    wr("$t/repo/state/posts/page/safe.md", "---\n" . json_encode([
        'type' => 'page', 'meta' => ['safe_cache' => 'a:1:{s:1:"x";s:1:"y";}'],
    ]) . "\n---\nbody\n");
    $g1 = gen_draft("$t/repo", 'safe-prior-draft', $lib);
    $target = $g1['_draft']['unsupported'][0]['target'];
    $saved = $g1;
    $saved['_draft']['unsupported'][0]['candidate']['id'] = 'human-safe';
    $saved['_draft']['unsupported'][0]['candidate']['ref'] = 'post'; // must be re-inerted on preservation
    $saved['_draft']['unsupported'][0]['evidence'] = [[
        'source' => "state/posts/page/{$uuid}--unsafe.md", 'locator' => $uuid, 'observation' => $token,
    ]];
    $saved['_draft']['unsupported'][0]['questions'] = ["raw prior UUID $uuid and token $token"];
    $saved['_draft']['unsupported'][0]['legacy_machine_trace'] = $uuid;
    $saved['_draft']['_meta'][$target]['ratified'] = true;
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $r = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($r['exit'] === 0, 'a prior candidate with unsafe machine evidence is safely regenerated by redaction');
    check($r['stderr'] === '', 'safe prior-candidate regeneration emits no PHP warnings or stderr diagnostics');
    check(!str_contains($r['stdout'], $uuid) && !str_contains($r['stdout'], $token),
        'prior entity filename/UUID and secret-looking evidence are never replayed into regenerated output');
    $safeOut = json_decode($r['stdout'], true);
    $safeCandidate = unsupported_by_target($safeOut)[$target] ?? [];
    check(($safeCandidate['candidate']['id'] ?? '') === 'human-safe'
        && ($safeCandidate['evidence'][0]['source'] ?? '') === 'prior-artifact-redacted'
        && !array_key_exists('legacy_machine_trace', $safeCandidate),
        'the human semantic fragment survives while evidence and unknown machine envelope fields are redacted');
    check(isset($safeCandidate['candidate']['proposed_ref']) && !isset($safeCandidate['candidate']['ref']),
        'a literal trigger key from prior _draft is re-renamed before preservation so it remains inert');

    $unsafe = $saved;
    $unsafe['_draft']['unsupported'][0]['candidate']['api_key'] = $token;
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($unsafe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $secretRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($secretRefusal['exit'] === 2 && str_contains($secretRefusal['stderr'], 'possible secret')
        && !str_contains($secretRefusal['stderr'] . $secretRefusal['stdout'], $token),
        'a secret in the prior semantic fragment fails closed without echoing the secret');

    $secretNotes = $g1;
    $secretNotes['notes'] = ['operator_note' => $token];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($secretNotes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $notesRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($notesRefusal['exit'] === 2 && str_contains($notesRefusal['stderr'], 'regenerated artifact contains a possible secret')
        && !str_contains($notesRefusal['stderr'] . $notesRefusal['stdout'], $token),
        'a secret in preserved top-level notes refuses rather than being replayed or silently redacted');

    $coordinateNotes = $g1;
    $capturedPath = "state/posts/page/{$uuid}--local.md";
    $coordinateNotes['notes'] = ['captured_path' => $capturedPath];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($coordinateNotes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $coordinateNotesRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($coordinateNotesRefusal['exit'] === 2
        && str_contains($coordinateNotesRefusal['stderr'], 'entity-local UUID/path')
        && !str_contains($coordinateNotesRefusal['stderr'] . $coordinateNotesRefusal['stdout'], $uuid)
        && !str_contains($coordinateNotesRefusal['stderr'] . $coordinateNotesRefusal['stdout'], $capturedPath),
        'an entity-local path in preserved top-level intent refuses without replaying its UUID/path');

    $executableNotes = $g1;
    $phpStub = '<?= "not-safe" ?>';
    $executableNotes['notes'] = ['example' => $phpStub];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($executableNotes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $executableNotesRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($executableNotesRefusal['exit'] === 2
        && str_contains($executableNotesRefusal['stderr'], 'executable/interpreter semantics')
        && !str_contains($executableNotesRefusal['stderr'] . $executableNotesRefusal['stdout'], $phpStub),
        'an executable PHP opening token in preserved top-level intent refuses without replaying the bytes');

    $unsafeCoordinate = $g1;
    $unsafeCoordinate['_draft']['unsupported'][0]['candidate']['legacy_locator'] = "state/posts/page/{$uuid}--unsafe.md";
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($unsafeCoordinate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $coordinateRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($coordinateRefusal['exit'] === 2 && str_contains($coordinateRefusal['stderr'], 'entity-local UUID/path')
        && !str_contains($coordinateRefusal['stderr'] . $coordinateRefusal['stdout'], $uuid),
        'an entity-local path in a prior semantic fragment fails closed without replaying its UUID');

    $untracked = $g1;
    $untracked['_draft']['unsupported'][0]['candidate']['id'] = 'legacy-untracked-human';
    $untracked['_draft']['_meta'][$target] = ['edited' => false]; // valid shape, but no trustworthy content hash
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($untracked, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $untrackedOut = gen_draft("$t/repo", 'safe-prior-draft', $lib);
    $untrackedCandidate = unsupported_by_target($untrackedOut)[$target] ?? [];
    check(($untrackedCandidate['candidate']['id'] ?? '') === 'legacy-untracked-human'
        && ($untrackedOut['_draft']['_meta'][$target]['legacy_untracked'] ?? null) === true,
        'a prior candidate missing generated_hash is retained as legacy/untracked rather than refreshed over');

    $malformed = $g1;
    $malformed['_draft']['_meta'][$target]['ratified'] = 'yes';
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($malformed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $markerRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($markerRefusal['exit'] === 2 && str_contains($markerRefusal['stderr'], '_meta.ratified must be a boolean'),
        'a malformed ratified authority marker fails closed instead of reading as unratified');

    foreach ([
        'generated_hash' => 'not-a-sha256',
        'edited' => 'no',
        'legacy_untracked' => 1,
    ] as $marker => $badValue) {
        $malformed = $g1;
        $malformed['_draft']['_meta'][$target][$marker] = $badValue;
        wr("$t/repo/adapters/safe-prior-draft.json", json_encode($malformed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $markerRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
        check($markerRefusal['exit'] === 2 && str_contains($markerRefusal['stderr'], '_meta.' . $marker),
            "a malformed $marker authority marker fails closed rather than becoming fresh/untracked");
    }

    $unknownMeta = $g1;
    $unknownMeta['_draft']['_meta'][$target]['old_authority'] = true;
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($unknownMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $unknownMetaRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($unknownMetaRefusal['exit'] === 2 && str_contains($unknownMetaRefusal['stderr'], 'unrecognized authority'),
        'an unrecognized prior _meta authority field fails closed instead of being ignored');

    $wrongDraftFormat = $g1;
    $wrongDraftFormat['_draft']['format'] = 'duo-adapter-draft/v999';
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($wrongDraftFormat, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $formatRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($formatRefusal['exit'] === 2 && str_contains($formatRefusal['stderr'], 'prior _draft.format must be duo-adapter-draft/v1'),
        'an unknown prior draft contract is refused rather than interpreted as v1');

    $unknownRoot = $g1;
    $unknownRoot['_draft']['future_authority'] = true;
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($unknownRoot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $unknownRootRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($unknownRootRefusal['exit'] === 2 && str_contains($unknownRootRefusal['stderr'], 'unrecognized v1 field'),
        'an unknown prior _draft root field is refused rather than silently discarded');

    $unknownBucket = $g1;
    $unknownBucket['_draft']['proposals']['future_bucket'] = [];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($unknownBucket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $unknownBucketRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($unknownBucketRefusal['exit'] === 2 && str_contains($unknownBucketRefusal['stderr'], 'unknown v1 bucket'),
        'an unknown prior proposal bucket is refused rather than re-bucketed');

    $wrongBucket = $g1;
    $wrongBucket['_draft']['proposals']['tables'][] = [
        'target' => 'post_meta.safe_cache', 'candidate' => ['proposed_ref' => 'post'],
        'status' => 'proposal', 'confidence' => 0.5, 'evidence' => [], 'questions' => [],
    ];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($wrongBucket, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $wrongBucketRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($wrongBucketRefusal['exit'] === 2 && str_contains($wrongBucketRefusal['stderr'], 'different v1 proposal bucket'),
        'a prior candidate whose target family disagrees with its bucket is refused rather than moved');

    foreach ([
        ['status', 'proposal', 'status must be'],
        ['confidence', 'unknown', 'confidence must be'],
    ] as [$field, $badValue, $message]) {
        $malformedCandidate = $g1;
        $malformedCandidate['_draft']['unsupported'][0][$field] = $badValue;
        wr("$t/repo/adapters/safe-prior-draft.json", json_encode($malformedCandidate, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $candidateRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
        check($candidateRefusal['exit'] === 2 && str_contains($candidateRefusal['stderr'], $message),
            "a malformed prior candidate $field is refused rather than silently normalized");
    }

    foreach ([
        ['interpreter', '<php-stub>'],
        ['rebuilders', '<php-stub>'],
        ['handler', '<?= "executable-short-echo" ?>'],
    ] as [$executableKey, $executableValue]) {
        $executablePrior = $g1;
        $executablePrior['_draft']['unsupported'][0]['candidate'][$executableKey] = $executableValue;
        wr("$t/repo/adapters/safe-prior-draft.json", json_encode($executablePrior, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $executableRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
        check($executableRefusal['exit'] === 2
            && str_contains($executableRefusal['stderr'], 'executable/interpreter semantics')
            && !str_contains($executableRefusal['stdout'] . $executableRefusal['stderr'], $executableValue),
            "a prior $executableKey stub is refused without echoing or preserving executable semantics");
    }

    foreach ([
        ['ref' => 'post', 'proposed_ref' => 'term'],
        ['proposed_ref' => 'term', 'ref' => 'post'],
    ] as $collidingFragment) {
        $colliding = $g1;
        $colliding['_draft']['unsupported'][0]['candidate'] = $collidingFragment;
        wr("$t/repo/adapters/safe-prior-draft.json", json_encode($colliding, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $collisionRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
        check($collisionRefusal['exit'] === 2 && str_contains($collisionRefusal['stderr'], 'colliding live/inert trigger keys'),
            'both insertion orders of a live/inert trigger collision refuse instead of silently dropping intent');
    }

    $wrongName = $g1;
    $wrongName['name'] = 'other-draft';
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($wrongName, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $nameRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($nameRefusal['exit'] === 2 && str_contains($nameRefusal['stderr'], 'must declare name'),
        'a name-mismatched prior artifact is refused rather than merged into this adapter');

    wr("$t/repo/adapters/safe-prior-draft.json", '{ invalid json');
    $jsonRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($jsonRefusal['exit'] === 2 && str_contains($jsonRefusal['stderr'], 'invalid JSON'),
        'a malformed prior artifact is refused rather than silently ignored');

    // The saved file is not necessarily already pinned. Its grammar still has
    // to be checked through the real Policy::load() context before merge.
    $malformedActions = $g1;
    $malformedActions['actions'] = ['not-an-action-object'];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($malformedActions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $actionsRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($actionsRefusal['exit'] === 2 && str_contains($actionsRefusal['stderr'], 'actions[0] must be an object'),
        'an unpinned prior artifact with malformed actions is refused by the real Policy grammar before merge');

    $malformedFact = $g1;
    $malformedFact['user_meta'] = ['bad_prior_meta' => ['class' => 'not-a-class']];
    wr("$t/repo/adapters/safe-prior-draft.json", json_encode($malformedFact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $factRefusal = duo(['adapter-draft', "$t/repo", '--name=safe-prior-draft', '--format=json'], $lib);
    check($factRefusal['exit'] === 2 && str_contains($factRefusal['stderr'], 'user_meta.bad_prior_meta has an invalid or missing class'),
        'an unpinned prior artifact with a malformed fact rule is refused by the real Policy grammar before merge');
}

echo "\n== 7. --check-proposals: grammar-valid reports liftable; malformed reports the engine's refusal ==\n";
{
    $t = "$root/t7";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    // A liftable table (has an id column -> a structural pk) and a not-liftable one
    // (only a non-distinct string column -> no pk inferable offline).
    wrj("$t/repo/state/tables/liftable/r1.json", ['table' => 'liftable', 'columns' => ['id' => 1, 'title' => 'x']]);
    wrj("$t/repo/state/tables/nopk/r1.json", ['table' => 'nopk', 'columns' => ['label' => 'same']]);
    wrj("$t/repo/state/tables/nopk/r2.json", ['table' => 'nopk', 'columns' => ['label' => 'same']]);

    // Snapshot the library file set to prove nothing is written live.
    $before = glob($lib . '/*');
    $r = duo(['adapter-draft', "$t/repo", '--name=chk-draft', '--check-proposals', '--format=json'], $lib);
    check($r['exit'] === 0, '--check-proposals runs and reports (exit 0)');
    $rep = json_decode($r['stdout'], true);
    $res = [];
    foreach (($rep['results'] ?? []) as $row) {
        $res[$row['target']] = $row;
    }
    check(($res['tables.liftable']['liftable'] ?? null) === true, 'a grammar-valid proposal reports liftable');
    check(($res['tables.nopk']['liftable'] ?? null) === false && str_contains(strtolower($res['tables.nopk']['message'] ?? ''), 'pk'), 'a proposal the live loader refuses reports the engine\'s real refusal (naming pk)');
    check(($res['deletions.table:liftable']['liftable'] ?? null) === false
        && str_contains($res['deletions.table:liftable']['message'] ?? '', 'live-policy conditional')
        && !str_contains($res['deletions.table:liftable']['message'] ?? '', 'Class "Duo\\Snapshot" not found'),
        'a table deletion proposal is explicitly deferred because attached-meta cascade ownership is not available offline');
    $after = glob($lib . '/*');
    check($before === $after, '--check-proposals writes NOTHING live (the manifest library is unchanged)');
    check(!is_dir("$t/repo/adapters"), '--check-proposals writes nothing into the site repo either');

    // A prior sidecar can carry human-authored structured proposals that the
    // offline proposers do not currently generate. They still must pass the
    // real destination/source/deletion contracts, not a shipped-only subset.
    $prior = gen_draft("$t/repo", 'contract-check', $lib);
    $prior['_draft']['proposals']['actions'][] = [
        'target' => 'actions[0]',
        'candidate' => ['kind' => 'native', 'action' => 'transient.delete', 'args' => ['name' => 'draft_check']],
        'status' => 'proposal', 'confidence' => 0.5, 'evidence' => [], 'questions' => [],
    ];
    $prior['_draft']['proposals']['providers'][] = [
        'target' => 'providers.plugin',
        'candidate' => [
            'id' => 'plugin-check', 'plugin' => 'probe/probe.php', 'source' => 'plugin',
            'version' => '1.0.0', 'capabilities' => ['flush'],
        ],
        'status' => 'proposal', 'confidence' => 0.5, 'evidence' => [], 'questions' => [],
    ];
    $prior['_draft']['proposals']['providers'][] = [
        'target' => 'providers.manifest',
        'candidate' => [
            'id' => 'manifest-check', 'plugin' => 'probe/probe.php', 'source' => 'manifest',
            'version' => '1.0.0', 'capabilities' => ['flush'],
        ],
        'status' => 'proposal', 'confidence' => 0.5, 'evidence' => [], 'questions' => [],
    ];
    $prior['_draft']['proposals']['deletions'][] = [
        'target' => 'deletions.post:page',
        'candidate' => ['cascades' => [], 'guards' => []],
        'status' => 'proposal', 'confidence' => 0.5, 'evidence' => [], 'questions' => [],
    ];
    wr("$t/repo/adapters/contract-check.json", json_encode($prior, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $contractCheck = duo(['adapter-draft', "$t/repo", '--name=contract-check', '--check-proposals', '--format=json'], $lib);
    check($contractCheck['exit'] === 0, 'structured prior proposals produce a bounded check report');
    $contractRows = [];
    foreach ((array) (json_decode($contractCheck['stdout'], true)['results'] ?? []) as $row) {
        $contractRows[$row['target']] = $row;
    }
    check(($contractRows['actions[0]']['liftable'] ?? null) === true,
        'a structured native action is lifted through the real action grammar');
    check(($contractRows['providers.plugin']['liftable'] ?? null) === true,
        'a plugin-owned provider is liftable for an out-of-tree site adapter');
    check(($contractRows['providers.manifest']['liftable'] ?? null) === false
        && str_contains($contractRows['providers.manifest']['message'] ?? '', 'source "manifest"'),
        'a manifest-sourced provider is refused by the real out-of-tree source contract');
    check(($contractRows['deletions.post:page']['liftable'] ?? null) === false
        && str_contains($contractRows['deletions.post:page']['message'] ?? '', 'omits required cascade'),
        'a deletion proposal missing required cascades is refused by Deletion::capability rather than called liftable');

    // --gap-report (WP-0.4): the engine-gap ledger's tool-fed half. It reads the
    // SAME lift as --check-proposals and emits only the shapes the real grammar
    // refused, as draft rows for tools/engine-gaps.json. The `tables.nopk`
    // fixture above is a real observed shape with no expressible identity, so it
    // is the row; `tables.liftable` is expressible and must NOT be one.
    $gap = duo(['adapter-draft', "$t/repo", '--name=chk-draft', '--gap-report', '--format=json'], $lib);
    check($gap['exit'] === 0, '--gap-report runs and reports (exit 0)');
    $gapReport = json_decode($gap['stdout'], true);
    check(($gapReport['format'] ?? '') === 'duo-adapter-draft-gap-report/v1', '--gap-report declares its own envelope');
    $gapRows = [];
    foreach (($gapReport['rows'] ?? []) as $row) {
        $gapRows[$row['coordinate']] = $row;
    }
    check(isset($gapRows['tables.nopk']) && str_contains(strtolower($gapRows['tables.nopk']['cannot_represent'] ?? ''), 'pk'),
        'a shape the real grammar refuses becomes a ledger row carrying the engine\'s own refusal as cannot_represent');
    check(!isset($gapRows['tables.liftable']),
        'an expressible shape is NOT reported as a gap (a gap report listing non-gaps would have to be read before it could be used)');
    // Deliberately unnamed: classifying the demand against the ledger's closed
    // primitive vocabulary is a review judgement, and inventing one per report is
    // exactly how duplicate demand would stop being countable.
    // `??` cannot express this: it treats a present null exactly like an absent
    // key, and "the key is present and null" is the whole assertion.
    $nopkRow = $gapRows['tables.nopk'] ?? [];
    check(array_key_exists('primitive_required', $nopkRow)
        && $nopkRow['primitive_required'] === null
        && str_contains((string) ($nopkRow['question'] ?? ''), 'closed'),
        'the emitted row names NO primitive and carries the question that sends it to the ledger vocabulary');
    check(($gapReport['summary']['gaps'] ?? -1) === count($gapRows)
        && ($gapReport['summary']['checked'] ?? 0) > count($gapRows),
        'the summary counts gaps against every proposal checked, not only the refused ones');
    $lastLib = glob($lib . '/*');
    check($before === $lastLib, '--gap-report writes NOTHING live either');

    $both = duo(['adapter-draft', "$t/repo", '--name=chk-draft', '--check-proposals', '--gap-report', '--format=json'], $lib);
    check($both['exit'] === 2 && str_contains($both['stderr'], 'two reports over one lift'),
        'asking for both reports at once is refused rather than silently answering one of the two questions');
}

echo "\n== 8. guardrails: no PHP stubs; secret dropped to a question; no reserved top-level key ==\n";
{
    $t = "$root/t8";
    $lib = make_lib("$t/lib");
    make_site("$t/repo", ['options' => ['ok_opt' => ['class' => 'authored', 'autoload' => 'preserve']]]);
    // A secret-shaped column value must be DROPPED to a question, never authored.
    wrj("$t/repo/state/tables/creds/r1.json", ['table' => 'creds', 'columns' => ['id' => 1, 'apikey' => 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789']]);
    wrj("$t/repo/state/tables/creds/r2.json", ['table' => 'creds', 'columns' => ['id' => 2, 'apikey' => 'ghp_ZYXWVUTSRQPONMLKJIHGFEDCBA9876543210']]);
    wr("$t/repo/state/posts/page/g.md", "---\n" . json_encode(['type' => 'page', 'meta' => ['elementor_data' => 'a:1:{s:1:"x";i:1;}']]) . "\n---\nbody\n");

    $r = duo(['adapter-draft', "$t/repo", '--name=guard-draft', '--format=json'], $lib);
    check($r['exit'] === 0, 'generation succeeds');
    $raw = $r['stdout'];
    check(!str_contains($raw, 'ghp_ABCDEFG') && !str_contains($raw, 'ghp_ZYXWV'), 'a secret-shaped value NEVER appears in the draft output');
    check(str_contains($raw, 'screened as a possible secret'), 'the screened candidate is DROPPED to a question naming the label only');
    check(!str_contains($raw, '<?php') && !str_contains($raw, '"interpreter"') && !str_contains($raw, '"regenerator"') && !str_contains($raw, 'regen_dependency'), 'no PHP regenerator/interpreter stub is ever emitted');

    // No reserved top-level key -> installs as a site adapter without tripping
    // AdapterSources::assert_out_of_tree_contract(). Prove it by loading the draft
    // through the site adapter overlay, which runs that exact contract.
    $draft = json_decode($raw, true);
    foreach (['adapter_certificate', 'authority', 'certification', 'disposition', 'evidence', 'signature', 'interpreter'] as $reserved) {
        check(!array_key_exists($reserved, $draft), "the draft names no reserved top-level key '$reserved'");
    }
    // The exact refusal a draft-turned-site-adapter must pass, called directly.
    try {
        \Duo\AdapterSources::assert_out_of_tree_contract($draft, 'guard-draft', 'adapters/guard-draft.json');
        check(true, 'installed as a site adapter, the draft passes AdapterSources::assert_out_of_tree_contract()');
    } catch (\Throwable $e) {
        check(false, 'assert_out_of_tree_contract() rejected the draft: ' . $e->getMessage());
    }

    @mkdir("$t/repo/adapters", 0777, true);
    wr("$t/repo/adapters/guard-draft.json", $raw);
    $second = duo(['adapter-draft', "$t/repo", '--name=guard-draft', '--format=json'], $lib);
    $secondDraft = json_decode($second['stdout'], true);
    $redactedTable = null;
    foreach ((array) ($secondDraft['_draft']['proposals']['tables'] ?? []) as $candidate) {
        if (($candidate['target'] ?? null) === 'tables.creds') {
            $redactedTable = $candidate;
        }
    }
    check($second['exit'] === 0 && $second['stderr'] === ''
        && ($secondDraft['_draft']['_meta']['tables.creds']['edited'] ?? null) === false
        && ($redactedTable['candidate'] ?? null) === [],
        'a screened empty-object candidate remains unedited and warning-free across save/regenerate');
}

echo "\n== 8b. secret screen: heuristic (suspicious) tier fires on the REAL key; generated bytes are never pasted into evidence ==\n";
{
    $t = "$root/t8b";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    // (fix 1) A credential-NAMED column whose value is suspicious-positive but NOT a
    // hard_match (24 hex chars). This can only be caught by Secrets::suspicious(),
    // which keys on the NAME — so it fires ONLY if the real column name reaches it.
    // The earlier bug passed a positional list index, making this tier inert.
    wrj("$t/repo/state/tables/apicreds/r1.json", ['table' => 'apicreds', 'columns' => ['id' => 1, 'api_key' => 'aaaa1111bbbb2222cccc3333']]);
    wrj("$t/repo/state/tables/apicreds/r2.json", ['table' => 'apicreds', 'columns' => ['id' => 2, 'api_key' => 'eeee7777ffff8888aaaa9999']]);
    // (fix 2) A generated (serialized) blob embedding a secret under a NON-credential
    // meta key, so suspicious cannot fire on the outer key: the only defense is that
    // the blob's BYTES are never pasted into evidence (shape descriptor only).
    wr("$t/repo/state/posts/page/g.md", "---\n" . json_encode(['type' => 'page', 'meta' => ['widget_data' => 'a:1:{s:7:"api_key";s:24:"dddd4444eeee5555ffff6666";}']]) . "\n---\nbody\n");
    $hardToken = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    wr("$t/repo/state/posts/page/h.md", "---\n" . json_encode(['type' => 'page', 'meta' => [
        'hard_blob' => 'a:1:{s:5:"token";s:40:"' . $hardToken . '";}',
    ]]) . "\n---\nbody\n");

    $r = duo(['adapter-draft', "$t/repo", '--name=heur-draft', '--format=json'], $lib);
    check($r['exit'] === 0, 'generation succeeds');
    $raw = $r['stdout'];
    // BITE for fix 1: only fires if suspicious() got the real key 'api_key'.
    check(str_contains($raw, 'suspicious credential-shaped value'), 'a suspicious-but-not-hard_match value under a credential-named key is screened by the heuristic tier (fires on the REAL key)');
    check(!str_contains($raw, 'aaaa1111bbbb2222cccc3333') && !str_contains($raw, 'eeee7777ffff8888aaaa9999'), 'the suspicious column value NEVER appears in the emitted draft JSON');
    $draft = json_decode($raw, true);
    $apicreds = null;
    foreach (($draft['_draft']['proposals']['tables'] ?? []) as $c) {
        if ($c['target'] === 'tables.apicreds') {
            $apicreds = $c;
        }
    }
    check($apicreds !== null && empty($apicreds['candidate']), 'the candidate carrying the suspicious value is DROPPED (empty fragment), not authored');
    // BITE for fix 2: the blob's embedded secret must never be pasted verbatim.
    check(!str_contains($raw, 'dddd4444eeee5555ffff6666'), 'a secret EMBEDDED in a generated blob never appears verbatim in evidence (bytes withheld)');
    check(preg_match('/opaque ~\d+-char [A-Za-z\/-]+ blob/', $raw) === 1, 'generated-surface evidence describes the SHAPE (opaque ~N-char blob), never the bytes');
    $hardTarget = 'unsupported.generated.' . hash('sha256', \Duo\Canon::encode(['kind' => 'post_meta', 'key' => 'hard_blob']));
    $hardCandidate = unsupported_by_target($draft)[$hardTarget] ?? null;
    check($hardCandidate !== null
        && ($hardCandidate['status'] ?? null) === 'unsupported'
        && ($hardCandidate['candidate'] ?? null) === []
        && H::json_has($hardCandidate['questions'] ?? [], 'possible secret')
        && !str_contains($raw, $hardToken),
        'a hard secret inside generated state stays an inert unsupported question instead of becoming an unroutable proposal');
}

// ---------------------------------------------------------------------------
// T6 §3.5: --seed honours --match. An author drafting one plugin's adapter
// wants that plugin's family — not the ~90 core-option prefixes coverage
// cannot attribute either (the adapter walk read a wpforms draft proposing
// `admin`, `blog`, `avatar`… beside `wpforms`). Unscoped drafts keep
// everything, as before; a scoped one keeps the prefixes, tables and scope
// post types the operator's pattern matches, spelled as an option name would
// be (`<prefix>_`).
{
    $t = "$root/t9-seed-scope";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    $seed = "$t/coverage.json";
    wrj($seed, [
        'format' => 'duo-coverage-report/v1',
        'options' => [
            'total' => 5, 'captured' => 1, 'pending' => 0, 'invisible_total' => 4,
            'invisible_transient' => 0, 'invisible_other' => 4,
            'invisible_groups' => [
                ['prefix' => 'wpforms', 'count' => 2, 'probable_owner' => 'wpforms-lite'],
                ['prefix' => 'wpforms_transient', 'count' => 1, 'probable_owner' => null],
                ['prefix' => 'admin', 'count' => 1, 'probable_owner' => null],
            ],
        ],
        'tables' => [
            'undeclared_total' => 2,
            'undeclared' => [
                ['table' => 'wp_wpforms_tasks_meta', 'logical_name' => 'wpforms_tasks_meta', 'row_count' => 3, 'probable_owner' => 'wpforms-lite'],
                ['table' => 'wp_acme_index', 'logical_name' => 'acme_index', 'row_count' => 1, 'probable_owner' => null],
            ],
        ],
    ]);
    $scoped = gen_draft("$t/repo", 'wpforms', $lib, ['--seed=' . $seed, '--match=^_?wpforms_']);
    $targets = static function (array $draft, string $section): array {
        return array_map(static fn (array $c): string => (string) $c['target'], $draft['_draft']['proposals'][$section] ?? []);
    };
    check(
        $targets($scoped, 'option_namespaces') === ['option_namespaces[wpforms]', 'option_namespaces[wpforms_transient]'],
        'a --match-scoped seed proposes only the matching option prefixes: ' . json_encode($targets($scoped, 'option_namespaces'))
    );
    check(
        $targets($scoped, 'tables') === ['tables.wpforms_tasks_meta'],
        'a --match-scoped seed proposes only the matching undeclared tables: ' . json_encode($targets($scoped, 'tables'))
    );
    $unscoped = gen_draft("$t/repo", 'all', $lib, ['--seed=' . $seed]);
    check(
        count($targets($unscoped, 'option_namespaces')) === 3 && count($targets($unscoped, 'tables')) === 2,
        'an unscoped seed still proposes every invisible prefix and undeclared table'
    );

    // T6 §3.5: --out refuses to overwrite without --force, and the refusal is
    // TYPED (`[draft_output_exists]`) so a script — the walk, an operator's
    // own — keys on the code rather than on the sentence.
    @mkdir("$t/repo/adapters", 0777, true);
    $out = "$t/repo/adapters/wpforms.json";
    $first = duo(['adapter-draft', "$t/repo", '--name=wpforms', '--seed=' . $seed, '--out=' . $out], $lib);
    check($first['exit'] === 0 && is_file($out), '--out writes the draft (exit ' . $first['exit'] . ')');
    $again = duo(['adapter-draft', "$t/repo", '--name=wpforms', '--seed=' . $seed, '--out=' . $out], $lib);
    check(
        $again['exit'] === 2 && str_contains($again['stderr'], '[draft_output_exists]')
            && str_contains($again['stderr'], 'never replaces a reviewed draft silently'),
        'a second --out to the same path refuses under its typed reason code (got: ' . trim($again['stderr']) . ')'
    );
    $forced = duo(['adapter-draft', "$t/repo", '--name=wpforms', '--seed=' . $seed, '--out=' . $out, '--force'], $lib);
    check($forced['exit'] === 0, '--force regenerates over it (exit ' . $forced['exit'] . ': ' . substr($forced['stderr'], 0, 200) . ')');
}

echo "\n== 10. --evidence: a duo-adapter-probe/v1 document answers the NAMED questions and promotes nothing ==\n";
// ---------------------------------------------------------------------------
// WP-2.1. `--evidence=` used to be accepted and explicitly ignored. It now
// takes the live half's own document (`wp duo adapter-probe --format=json`),
// and the property under test is the one the seam exists to protect: live
// facts land as `evidence[]` rows at confidence 1.0 answering the questions
// the offline proposer NAMED, and NOTHING else moves. The probe is built here
// through the REAL emitter's hash function, so a draft that accepts this
// document accepts what the target actually writes.
require_once $repo . '/agent/src/Adapter/AdapterProbe.php';
{
    $t = "$root/t10-evidence";
    $lib = make_lib("$t/lib");
    make_site("$t/repo");
    wrj("$t/repo/state/tables/rooms/r1.json", ['table' => 'rooms', 'columns' => ['id' => 1, 'code' => 'A1']]);
    wrj("$t/repo/state/tables/rooms/r2.json", ['table' => 'rooms', 'columns' => ['id' => 2, 'code' => 'B2']]);

    $bare = gen_draft("$t/repo", 'rooms-draft', $lib);
    $bareCandidate = ($bare['_draft']['proposals']['tables'] ?? [])[0] ?? [];
    check(
        H::json_has($bareCandidate['questions'] ?? [], '[table_schema]')
            && H::json_has($bareCandidate['questions'] ?? [], '[natural_key_uniqueness]')
            && H::json_has($bareCandidate['questions'] ?? [], '[lock_index]')
            && H::json_has($bareCandidate['questions'] ?? [], '[foreign_keys]')
            && H::json_has($bareCandidate['questions'] ?? [], '[eav_twin]'),
        'without --evidence every live deferral is NAMED, so an answer can attach to it'
    );
    check(
        str_contains((string) ($bare['_draft']['evidence_seam'] ?? ''), 'no --evidence given'),
        'the seam says plainly that no live document was consumed'
    );

    // The live target disagrees with the offline structural guess: the real
    // PRIMARY KEY is `code`, not the `id` the proposer inferred from the
    // sampled row files.
    $probe = [
        'authority' => false,
        'deferred' => ['a probe proposes no class, identity, deletion authority or capability'],
        'format' => 'duo-adapter-probe/v1',
        'redaction' => 'values_omitted',
        'tables' => [
            'rooms' => [
                'columns' => [
                    'code' => ['nullable' => false, 'type' => 'varchar(191)'],
                    'id' => ['nullable' => false, 'type' => 'bigint(20) unsigned'],
                ],
                'eav_twin' => [
                    'key_column' => 'meta_key',
                    'parent_column' => 'room_id',
                    'table' => 'roomsmeta',
                    'value_column' => 'meta_value',
                ],
                'foreign_keys' => ['venue_id' => 'wp_venues'],
                'index_coverage' => [
                    'code' => ['index' => 'code_unique', 'prefix' => null],
                    'id' => ['index' => 'PRIMARY', 'prefix' => null],
                ],
                'natural_key' => ['column' => 'code', 'distinct' => 12, 'rows' => 12, 'unique' => true],
                'present' => true,
                'primary_key' => ['code'],
                'unique_keys' => ['code_unique' => ['code']],
            ],
            'never_proposed' => ['present' => false],
        ],
        'target' => ['agent_version' => '0.0.0-test', 'spec_version' => SPEC],
    ];
    $probe['probe_hash'] = \Duo\AdapterProbe::hash_document($probe);
    $probePath = "$t/probe.json";
    wr($probePath, \Duo\Canon::encode($probe));

    $answered = gen_draft("$t/repo", 'rooms-draft', $lib, ['--evidence=' . $probePath]);
    $candidate = ($answered['_draft']['proposals']['tables'] ?? [])[0] ?? [];
    $rows = [];
    foreach (($candidate['evidence'] ?? []) as $row) {
        if (($row['source'] ?? '') === 'duo-adapter-probe/v1') {
            $rows[$row['locator']] = $row;
        }
    }
    check(count($rows) === 7, 'every live fact family landed as its own evidence row (' . count($rows) . ')');
    check(
        array_values(array_unique(array_map(static fn(array $r) => $r['confidence'], $rows))) === [1],
        'every probe row carries confidence 1.0 — an observed fact is certain in a way its proposal is not'
    );

    // Answered BY NAME: each row names a question the candidate actually asks.
    $asked = [];
    foreach (($candidate['questions'] ?? []) as $question) {
        if (preg_match('/^\[([a-z_]+)\]/', (string) $question, $m) === 1) {
            $asked[$m[1]] = true;
        }
    }
    $unanswerable = [];
    foreach ($rows as $locator => $row) {
        if (!isset($asked[(string) ($row['question'] ?? '')])) {
            $unanswerable[] = $locator . ' => ' . ($row['question'] ?? '(none)');
        }
    }
    check($unanswerable === [], 'every probe row answers a question the candidate NAMED: ' . json_encode($unanswerable));
    check(
        ($rows['tables.rooms.pk']['question'] ?? '') === 'table_schema'
            && str_contains((string) ($rows['tables.rooms.pk']['observation'] ?? ''), 'the live PRIMARY KEY is (code)'),
        'the real PRIMARY KEY is reported against the table_schema question'
    );
    check(
        ($rows['tables.rooms.index_coverage']['question'] ?? '') === 'lock_index'
            && ($rows['tables.rooms.foreign_keys']['question'] ?? '') === 'foreign_keys'
            && ($rows['tables.rooms.eav_twin']['question'] ?? '') === 'eav_twin'
            && ($rows['tables.rooms.natural_key.code']['question'] ?? '') === 'natural_key_uniqueness',
        'index coverage, foreign keys, the EAV twin and the keyspace count each answer their own named question'
    );

    // PROMOTES NOTHING. The fragment is byte-identical to the unanswered run:
    // the live PK disagrees with the guess and the guess still stands, the
    // per-column class hints stay `runtime`, and identity stays a proposal.
    check(
        same_canon($bareCandidate['candidate'], $candidate['candidate']),
        'the candidate FRAGMENT is unchanged by the probe — a live fact is evidence, never a promotion'
    );
    check(
        ($candidate['candidate']['pk'] ?? null) === 'id',
        "the disagreeing offline pk guess STANDS at 'id' and is only contradicted in evidence"
    );
    check(
        ($candidate['candidate']['columns']['code']['class'] ?? null) === 'authored'
            && ($candidate['candidate']['identity']['mode'] ?? null) === 'natural_key',
        'the offline classification and identity proposal are exactly what they were'
    );
    check(
        ($candidate['status'] ?? '') === 'proposal' && ($candidate['confidence'] ?? null) === ($bareCandidate['confidence'] ?? null),
        'status stays `proposal` and the CANDIDATE confidence stays the guess it was'
    );
    check(
        !isset($answered['deletions']) && !isset($answered['tables']),
        'no live section was written: a probe never advertises a table or a deletion'
    );
    check(
        str_contains((string) ($answered['_draft']['evidence_seam'] ?? ''), 'duo-adapter-probe/v1 consumed')
            && str_contains((string) ($answered['_draft']['evidence_seam'] ?? ''), $probe['probe_hash'])
            && str_contains((string) ($answered['_draft']['evidence_seam'] ?? ''), 'Probed but not proposed here: never_proposed'),
        'the seam records the consumed document by hash and names the probed table nothing proposed'
    );

    // Inertness is preserved: evidence rows carry no blind-walk trigger key.
    $md = "$t/md";
    make_lib($md);
    wr($md . '/rooms-draft.json', json_encode($answered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $validated = duo(['manifest-validate', $md, '--manifest=rooms-draft', '--pins=rooms-draft,core']);
    check(
        $validated['exit'] === 0,
        'a draft carrying probe evidence still validates (exit ' . $validated['exit'] . ': ' . trim($validated['stderr']) . ')'
    );

    // The load-bearing refusal: a document that tries to classify.
    $forged = $probe;
    $forged['tables']['rooms']['class'] = 'authored_snapshot';
    $forged['probe_hash'] = \Duo\AdapterProbe::hash_document($forged);
    wr("$t/forged.json", \Duo\Canon::encode($forged));
    $refused = duo(['adapter-draft', "$t/repo", '--name=rooms-draft', '--format=json', '--evidence=' . "$t/forged.json"], $lib);
    check(
        $refused['exit'] === 2 && str_contains($refused['stderr'], 'outside the closed probe vocabulary'),
        'a probe document carrying a `class` refuses the whole run (got: ' . trim($refused['stderr']) . ')'
    );

    $tampered = $probe;
    $tampered['tables']['rooms']['primary_key'] = ['id'];
    wr("$t/tampered.json", \Duo\Canon::encode($tampered));
    $stale = duo(['adapter-draft', "$t/repo", '--name=rooms-draft', '--format=json', '--evidence=' . "$t/tampered.json"], $lib);
    check(
        $stale['exit'] === 2 && str_contains($stale['stderr'], 'probe_hash does not describe the document'),
        'a hand-edited fact is refused rather than attached at confidence 1.0'
    );

    wr("$t/not-a-probe.json", json_encode(['format' => 'duo-coverage-report/v1']));
    $wrongFormat = duo(['adapter-draft', "$t/repo", '--name=rooms-draft', '--format=json', '--evidence=' . "$t/not-a-probe.json"], $lib);
    check(
        $wrongFormat['exit'] === 2 && str_contains($wrongFormat['stderr'], 'duo-adapter-probe/v1'),
        'a document of another format is refused by name'
    );
}

echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);

/** Tiny helper: does any element of $haystack contain $needle? */
final class H {
    public static function json_has(array $haystack, string $needle): bool {
        foreach ($haystack as $s) {
            if (is_string($s) && str_contains($s, $needle)) {
                return true;
            }
        }
        return false;
    }
}
