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
 * ref-kind walk (Policy::collect_ref_kind_claims) cannot collect it — and the same
 * fragment with ONE trigger key un-renamed must then FAIL with the closed-vocabulary
 * refusal, proving the rename is what makes the sidecar inert.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and the
 * script exits 1.
 */

$repo = dirname(__DIR__, 2);

if (preg_match("/define\('DUO_SPEC_VERSION', ([0-9]+)\)/", (string) file_get_contents($repo . '/agent/duo.php'), $m) !== 1) {
    fwrite(STDERR, "could not resolve DUO_SPEC_VERSION from agent/duo.php\n");
    exit(1);
}
define('SPEC', (int) $m[1]);

// The engine, in-process, for the ONE structural check that is a direct call
// rather than a subprocess: AdapterSources::assert_out_of_tree_contract() is the
// exact refusal a draft-turned-site-adapter must pass, so test 8 calls it. (Every
// other check runs the real CLI as a subprocess.) Requiring Policy pulls
// NativeActions/AdapterSources/ReferenceRules; none reaches WordPress at load.
require $repo . '/agent/src/Canon.php';
require $repo . '/agent/src/OptionState.php';
require $repo . '/agent/src/Policy.php';

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
        'spec_version' => SPEC,
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
    wr("$t/repo/state/posts/page/p.md", "---\n" . json_encode(['type' => 'page', 'meta' => ['related_id' => 99]]) . "\n---\n<!-- wp:core/gallery {\"ids\":[3,4]} -->\n<!-- /wp:core/gallery -->\n[gallery ids=\"5,6\"]\n");
    $draft = gen_draft("$t/repo", 'p-draft', $lib);
    $byTarget = [];
    foreach (($draft['_draft']['proposals'] ?? []) as $bucket) {
        foreach ($bucket as $c) {
            $byTarget[$c['target']] = $c;
        }
    }

    $tbl = $byTarget['tables.rooms'] ?? null;
    check($tbl !== null && $tbl['status'] === 'proposal' && $tbl['confidence'] > 0 && $tbl['confidence'] <= 1, 'typed-table candidate is a proposal with a bounded confidence');
    check($tbl && ($tbl['evidence'][0]['locator'] ?? '') === 'columns', 'typed-table evidence names the columns locator');
    check($tbl && H::json_has($tbl['questions'], 'SHOW COLUMNS'), 'typed-table defers column TYPES/PK to a live question');
    check($tbl && ($tbl['candidate']['identity']['mode'] ?? '') === 'natural_key', 'natural-key proposer detected the distinct-value column as an identity candidate');
    check($tbl && H::json_has($tbl['questions'], 'natural-key uniqueness'), 'natural-key uniqueness is deferred to a live question');

    $blk = $byTarget['block_paths.core/gallery'] ?? null;
    check($blk && str_starts_with($blk['evidence'][0]['locator'] ?? '', 'blocks.core/gallery'), 'block-path evidence names the block.attrs locator');
    check($blk && ($blk['candidate']['type'] ?? '') === 'int[]', 'block-path list attribute is typed int[]');
    check($blk && H::json_has($blk['questions'], 'ref kind'), 'block-path ref KIND / live resolution is a question');

    $sc = $byTarget['shortcode_paths.gallery'] ?? null;
    check($sc && str_starts_with($sc['evidence'][0]['locator'] ?? '', 'shortcodes.gallery'), 'shortcode-path evidence names the shortcode locator');

    $ref = $byTarget['post_meta.related_id'] ?? null;
    check($ref && ($ref['evidence'][0]['locator'] ?? '') === 'meta.related_id', 'reference candidate names the meta locator');
    check($ref && H::json_has($ref['questions'], 'live id resolution'), 'reference resolution is deferred to a live question');
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
    $delSrc = (string) file_get_contents($repo . '/agent/src/Deletion.php');
    check(str_contains($delSrc, "'post' => ['postmeta', 'post_revisions', 'term_relationships']"), 'Deletion.php still declares the post arm this proposer mirrors (drift guard)');
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
    $after = glob($lib . '/*');
    check($before === $after, '--check-proposals writes NOTHING live (the manifest library is unchanged)');
    check(!is_dir("$t/repo/adapters"), '--check-proposals writes nothing into the site repo either');
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
