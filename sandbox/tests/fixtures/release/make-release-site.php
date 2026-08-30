<?php
declare(strict_types=1);

/**
 * Build the offline fixture site the round-3 release/verify/recover suites
 * drive `php cli/wprism` against.
 *
 * It extends `sandbox/tests/fixtures/assess/make-fixture.php` rather than
 * duplicating it: the assess fixture already ships the `local` environment
 * registry, the site repository, and a fake `wp` on PATH that answers every
 * call `Doctor::run()`, `Init::proposal()` and `AssessCommand::assess()`
 * make. Release composes that same assessment, so re-inventing those answers
 * here would make the two fixtures able to disagree about the same site.
 *
 * On top of it this adds, all read out of `$WPRISM_FIXTURES`:
 *
 *   - a complete `wp wprism plan --format=json` envelope with a valid
 *     `category_summary`, in several variants selected by `$WPRISM_PLAN`;
 *   - a `wp wprism compile --format=json` summary carrying a content address and
 *     NO code descriptor by default, so content-only promotion runs no
 *     lifecycle hooks; `WPRISM_CODE_ENABLED=1` selects a code-bearing summary;
 *   - answers for promotion's own phases, each with an injectable exit code;
 *   - two real commits in the site repository, so `--from` has a matching ref
 *     (`HEAD`) and a deliberately non-matching one (`other`).
 *
 * Environment variables the generated `wp` honours, on top of the assess
 * fixture's own three:
 *
 *   WPRISM_PLAN=<name>          which plan fixture `wprism plan` returns
 *                            (plan, plan-converged, plan-incomplete-lifecycle,
 *                             plan-incomplete-apply, plan-drift, plan-deletes)
 *   WPRISM_PLAN_AFTER=<name>    the plan returned from the Nth call onward,
 *                            which is how a suite makes the target's state
 *                            differ AFTER a failed promotion
 *   WPRISM_PLAN_AFTER_CALL=<n>  the 1-based call index WPRISM_PLAN_AFTER starts at
 *   WPRISM_BEGIN_EXIT=<n>       `wprism promotion-begin` exit code (default 0)
 *   WPRISM_LIFECYCLE_EXIT=<n>   `wprism deploy --lifecycle-phase=...` exit code
 *   WPRISM_APPLY_EXIT=<n>       `wprism apply` exit code
 *   WPRISM_COMPILE_EXIT=<n>     `wprism compile` exit code
 *   WPRISM_CODE_ENABLED=1       select the code-bearing compile summary
 *   WPRISM_COMPILE_RACE_REPO=<p> commit and then dirty this exact --repo
 *   WPRISM_COMPILE_RACE_EVIDENCE=<p> record the raced HEAD once
 *   WPRISM_ENFORCE_RELEASE_BINDING=1 run the shipped target repository binder
 *   WPRISM_RELEASE_PRODUCT_ROOT=<p> checkout containing that shipped binder
 *   WPRISM_BEGIN_BINDING_EVIDENCE=<p> prove the binder held its flock at lease
 *   WPRISM_BEGIN_REPOSITORY_RACE_REPO=<p> race this repo just before target bind
 *   WPRISM_BEGIN_REPOSITORY_RACE_EVIDENCE=<p> record that raced HEAD once
 *
 * Usage: make-release-site.php <dir>
 */

$root = dirname(__DIR__, 4);
require_once $root . '/cli/src/Plan/PlanContract.php';

use WPrism\Orchestrator\PlanContract;

$dir = $argv[1] ?? null;
if (!is_string($dir) || $dir === '') {
    fwrite(STDERR, "usage: make-release-site.php <dir>\n");
    exit(2);
}

$base = escapeshellarg($root . '/sandbox/tests/fixtures/assess/make-fixture.php');
exec('php ' . $base . ' ' . escapeshellarg($dir) . ' 2>&1', $output, $status);
if ($status !== 0) {
    fwrite(STDERR, "make-release-site: base assess fixture failed:\n" . implode("\n", $output) . "\n");
    exit(2);
}

/**
 * One `wprism-plan-category-summary/v1` projection.
 *
 * The numbers are the agent's own arithmetic contract — `PlanContract`
 * validates them — so the helper takes the whole picture and the caller
 * patches the facets it is making a point about.
 *
 * @param array<string,array<string,array<string,int>>> $overrides
 * @return array<string,mixed>
 */
function release_summary(array $overrides = []): array {
    $categories = [
        ['id' => 'code', 'metrics' => [
            'count' => 0, 'compatibility_mismatch' => 0, 'lifecycle_mismatch' => 0,
            'revision_stale' => 0, 'drift' => 0, 'other_mismatch' => 0, 'unsupported_code' => 0,
        ], 'entity_actions' => [], 'contained_entities' => ['plugin' => 0, 'theme' => 0, 'other' => 0]],
        ['id' => 'lifecycle', 'metrics' => [
            'count' => 0, 'code_lifecycle_mismatch' => 0, 'incomplete_lifecycle' => 0,
        ], 'entity_actions' => [], 'contained_entities' => []],
        ['id' => 'authored_state', 'metrics' => ['count' => 3], 'entity_actions' => [
            'create' => 1, 'update' => 2, 'adopt' => 0, 'unchanged' => 0,
            'drift' => 0, 'conflict' => 0, 'collision' => 0,
        ], 'contained_entities' => [
            'post' => 3, 'attachment' => 0, 'term' => 0, 'menu' => 0, 'sidebar' => 0,
            'options' => 0, 'user_meta' => 0, 'typed_table' => 0,
        ]],
        ['id' => 'generated_effects', 'metrics' => [
            'count' => 0, 'declared_effects' => 0, 'declared_lifecycle_effects' => 0,
            'declared_rebuild_effects' => 0, 'declared_regenerator_effects' => 0,
            'selected_native_actions' => 0, 'selected_provider_actions' => 0,
            'regen_pending' => 0, 'incomplete_apply' => 0,
        ], 'entity_actions' => [], 'contained_entities' => []],
        ['id' => 'media', 'metrics' => [
            'count' => 0, 'attachment_entities' => 0, 'upload_inventory_entries' => 0,
        ], 'entity_actions' => [
            'create' => 0, 'update' => 0, 'adopt' => 0, 'unchanged' => 0, 'drift' => 0,
            'conflict' => 0, 'collision' => 0, 'delete' => 0, 'delete_conflict' => 0, 'deleted' => 0,
        ], 'contained_entities' => ['attachment' => 0]],
        ['id' => 'secrets', 'metrics' => [], 'entity_actions' => [],
            'contained_entities' => [], 'visibility' => 'redacted'],
        ['id' => 'environment_state', 'metrics' => [
            'count' => 0, 'state_drift' => 0, 'code_drift' => 0, 'required_env_missing' => 0,
            'optional_env_missing' => 0, 'missing_user' => 0, 'skipped_user_meta' => 0,
            'incomplete_lifecycle' => 0, 'incomplete_apply' => 0, 'regen_pending' => 0,
        ], 'entity_actions' => [], 'contained_entities' => []],
        ['id' => 'capabilities', 'metrics' => [
            'count' => 0, 'certification_source_blockers' => 0, 'selected_provider_blockers' => 0,
            'declared_unselected_provider_problems' => 0,
        ], 'entity_actions' => [], 'contained_entities' => []],
        ['id' => 'deletions', 'metrics' => [
            'count' => 0, 'blocked' => 0, 'nested_menu_item_delete_candidates' => 0,
            'nested_widget_delete_candidates' => 0, 'nested_option_delete_candidates' => 0,
        ], 'entity_actions' => ['delete' => 0, 'delete_conflict' => 0, 'deleted' => 0],
            'contained_entities' => [
                'post' => 0, 'attachment' => 0, 'term' => 0, 'menu' => 0, 'sidebar' => 0,
                'options' => 0, 'user_meta' => 0, 'typed_table' => 0,
            ]],
    ];
    foreach ($overrides as $id => $patch) {
        foreach ($categories as $index => $category) {
            if ($category['id'] !== $id) {
                continue;
            }
            foreach ($patch as $facet => $values) {
                $categories[$index][$facet] = array_replace((array) $categories[$index][$facet], $values);
            }
        }
    }

    return [
        'format' => 'wprism-plan-category-summary/v1',
        'redaction' => 'values_omitted',
        'facets' => 'overlapping',
        'vocabulary' => ['generated_effects' => ['public_label' => 'generated', 'wire_class' => 'derived']],
        'categories' => $categories,
    ];
}

/**
 * A complete plan envelope: every required bucket present, empty unless the
 * caller populated it.
 *
 * @param array<string,list<mixed>> $buckets
 * @param array<string,mixed> $summary
 * @return array<string,mixed>
 */
function release_plan(array $buckets, array $summary): array {
    $plan = [];
    foreach (PlanContract::requiredBuckets() as $bucket) {
        $plan[$bucket] = [];
    }
    foreach ($buckets as $bucket => $rows) {
        $plan[$bucket] = $rows;
    }
    $plan['category_summary'] = $summary;
    ksort($plan, SORT_STRING);

    return $plan;
}

$create = [['uuid' => 'aaaa1111', 'type' => 'post', 'path' => 'state/posts/page/aaaa1111--about.md', 'title' => 'About']];
$update = [
    ['uuid' => 'bbbb2222', 'type' => 'post', 'path' => 'state/posts/page/bbbb2222--home.md', 'title' => 'Home'],
    ['uuid' => 'cccc3333', 'type' => 'post', 'path' => 'state/posts/page/cccc3333--terms.md', 'title' => 'Terms'],
];

$plans = [];

// The releasable plan: three authored-state rows, nothing unsafe, no code.
$plans['plan'] = release_plan(['create' => $create, 'update' => $update], release_summary());

// The converged target `wprism verify` reads after a release.
$plans['plan-converged'] = release_plan(
    ['unchanged' => array_merge($create, $update)],
    release_summary([
        'authored_state' => [
            'metrics' => ['count' => 3],
            'entity_actions' => ['create' => 0, 'update' => 0, 'unchanged' => 3],
        ],
    ])
);

// The three post-failure states `ReleaseCommand` classifies from.
$plans['plan-incomplete-lifecycle'] = release_plan([
    'create' => $create,
    'update' => $update,
    'incomplete_lifecycle' => [['phase' => 'activate', 'reason' => 'the lifecycle window was interrupted']],
], release_summary([
    'lifecycle' => ['metrics' => ['count' => 1, 'incomplete_lifecycle' => 1]],
    'environment_state' => ['metrics' => ['count' => 1, 'incomplete_lifecycle' => 1]],
]));

$plans['plan-incomplete-apply'] = release_plan([
    'create' => $create,
    'update' => $update,
    'incomplete_apply' => [['reason' => 'the authored transaction did not reach its terminal receipt']],
], release_summary([
    'generated_effects' => ['metrics' => ['count' => 1, 'incomplete_apply' => 1]],
    'environment_state' => ['metrics' => ['count' => 1, 'incomplete_apply' => 1]],
]));

$plans['plan-drift'] = release_plan([
    'update' => $update,
    'drift' => [['uuid' => 'dddd4444', 'type' => 'post', 'path' => 'state/posts/page/dddd4444--news.md']],
], release_summary([
    'authored_state' => [
        'metrics' => ['count' => 3],
        'entity_actions' => ['create' => 0, 'update' => 2, 'drift' => 1],
    ],
    'environment_state' => ['metrics' => ['count' => 1, 'state_drift' => 1]],
]));

// A plan that deletes, for the --with-deletes authorization gate.
$plans['plan-deletes'] = release_plan([
    'create' => $create,
    'update' => $update,
    'delete' => [[
        'uuid' => 'eeee5555', 'type' => 'post', 'deletion_kind' => 'post',
        'deletion_type' => 'page', 'path' => 'state/deletions/eeee5555.json',
    ]],
], release_summary([
    'deletions' => [
        'metrics' => ['count' => 1],
        'entity_actions' => ['delete' => 1],
        'contained_entities' => ['post' => 1],
    ],
]));

foreach ($plans as $name => $plan) {
    // Fail here rather than three suites later: a fixture the trust boundary
    // refuses would make every assertion below vacuous.
    PlanContract::requireComplete($plan, "release fixture $name");
    if (!PlanContract::validCategorySummary($plan['category_summary'])) {
        fwrite(STDERR, "make-release-site: fixture plan '$name' carries an invalid category summary\n");
        exit(2);
    }
    file_put_contents(
        "$dir/fixtures/$name.json",
        json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
    );
}

// The compile summary. No `code` key, so `CodeDeploy::enabled()` is false and
// promotion takes its documented pre-code path.
file_put_contents("$dir/fixtures/compile.json", json_encode([
    'artifact_hash' => str_repeat('a1', 32),
    'manifests' => str_repeat('b2', 32),
    'resolved_adapters' => [],
    'revision' => str_repeat('c3', 32),
], JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents("$dir/fixtures/compile-code.json", json_encode([
    'artifact_hash' => str_repeat('a1', 32),
    'code' => [
        'code_revision' => str_repeat('d4', 32),
        'format' => 1,
    ],
    'manifests' => str_repeat('b2', 32),
    'resolved_adapters' => [],
    'revision' => str_repeat('c3', 32),
], JSON_UNESCAPED_SLASHES) . "\n");

// Two real commits: `HEAD` is what the target is on, `other` is a ref that
// resolves locally and deliberately does not match it.
$repo = "$dir/repo";
$git = static function (string $command) use ($repo): void {
    exec('git -C ' . escapeshellarg($repo) . ' ' . $command . ' 2>/dev/null');
};
file_put_contents("$repo/README.md", "release fixture\n");
$git('add -A');
$git('-c user.email=fixture@example.invalid -c user.name=fixture commit -q -m baseline');
$git('branch -f other');
file_put_contents("$repo/README.md", "release fixture, moved\n");
$git('add -A');
$git('-c user.email=fixture@example.invalid -c user.name=fixture commit -q -m moved');

// Extend the fake `wp` with the release-path answers. The base script's own
// cases are preserved verbatim; these are inserted ahead of them so a suite
// can never accidentally exercise a base answer for a release call.
$wpPath = "$dir/bin/wp";
$wp = (string) file_get_contents($wpPath);
$anchor = 'case " $* " in';
if (!str_contains($wp, $anchor)) {
    fwrite(STDERR, "make-release-site: the base fake wp dispatch moved\n");
    exit(2);
}
$release = <<<'SH'
case " $* " in
  *" wprism plan "*)
      plan="${WPRISM_PLAN:-plan}"
      if [ -n "${WPRISM_PLAN_AFTER:-}" ]; then
        seen=$(cat "$WPRISM_FIXTURES/plan-calls" 2>/dev/null || echo 0)
        seen=$((seen + 1))
        printf '%s' "$seen" > "$WPRISM_FIXTURES/plan-calls"
        if [ "$seen" -ge "${WPRISM_PLAN_AFTER_CALL:-2}" ]; then plan="$WPRISM_PLAN_AFTER"; fi
      fi
      cat "$WPRISM_FIXTURES/$plan.json"; exit 0 ;;
  *" wprism compile "*)
      [ "${WPRISM_COMPILE_EXIT:-0}" = 0 ] || { echo 'compile refused' >&2; exit "${WPRISM_COMPILE_EXIT}"; }
      out=""
      compile_repo=""
      for a in "$@"; do case "$a" in
        --out=*) out="${a#--out=}" ;;
        --repo=*) compile_repo="${a#--repo=}" ;;
      esac; done
      compile="$WPRISM_FIXTURES/compile.json"
      [ "${WPRISM_CODE_ENABLED:-0}" = 1 ] && compile="$WPRISM_FIXTURES/compile-code.json"
      if [ -n "${WPRISM_COMPILE_RACE_REPO:-}" ] \
          && [ "$compile_repo" = "$WPRISM_COMPILE_RACE_REPO" ] \
          && [ ! -e "${WPRISM_COMPILE_RACE_EVIDENCE:-}" ]; then
        printf 'operator committed compile-race\n' > "$compile_repo/operator-compile-race.txt"
        git -C "$compile_repo" add -- operator-compile-race.txt
        git -C "$compile_repo" -c user.email=fixture@example.invalid -c user.name=fixture \
          commit -q -m 'operator compile race'
        git -C "$compile_repo" rev-parse HEAD > "$WPRISM_COMPILE_RACE_EVIDENCE"
        printf 'operator uncommitted compile-race\n' > "$compile_repo/operator-compile-race.txt"
      fi
      [ -n "$out" ] && cp "$compile" "$out"
      cat "$compile"; exit 0 ;;
  *" wprism pending "*)
      # issue #3521: the review queue, so a suite can drive `wprism pending`'s
      # bounded human view against a queue it chose the size of.
      # `WPRISM_PENDING` names a JSON file; without it the queue is empty,
      # which is what every suite that does not set it saw before.
      if [ -n "${WPRISM_PENDING:-}" ]; then cat "$WPRISM_PENDING"; else printf '[]\n'; fi
      exit 0 ;;
  *" wprism promotion-begin "*)
      if [ "${WPRISM_ENFORCE_RELEASE_BINDING:-0}" = 1 ]; then
        begin_repo=""; begin_operation=""; begin_commit=""; begin_tree=""; begin_owner=""
        for a in "$@"; do case "$a" in
          --repo=*) begin_repo="${a#--repo=}" ;;
          --release-operation-id=*) begin_operation="${a#--release-operation-id=}" ;;
          --expected-source-commit=*) begin_commit="${a#--expected-source-commit=}" ;;
          --expected-source-tree=*) begin_tree="${a#--expected-source-tree=}" ;;
          --promotion-owner=*) begin_owner="${a#--promotion-owner=}" ;;
        esac; done
        [ -n "$begin_repo" ] && [ -n "$begin_operation" ] && [ -n "$begin_commit" ] \
          && [ -n "$begin_tree" ] && [ -n "$begin_owner" ] || exit 74
        if [ -n "${WPRISM_BEGIN_REPOSITORY_RACE_REPO:-}" ] \
            && [ "$begin_repo" = "$WPRISM_BEGIN_REPOSITORY_RACE_REPO" ] \
            && [ ! -e "${WPRISM_BEGIN_REPOSITORY_RACE_EVIDENCE:-}" ]; then
          printf 'operator committed promotion-begin-race\n' > "$begin_repo/operator-begin-race.txt"
          git -C "$begin_repo" add -- operator-begin-race.txt
          git -C "$begin_repo" -c user.email=fixture@example.invalid -c user.name=fixture \
            commit -q -m 'operator promotion-begin race'
          git -C "$begin_repo" rev-parse HEAD > "$WPRISM_BEGIN_REPOSITORY_RACE_EVIDENCE"
          printf 'operator uncommitted promotion-begin-race\n' > "$begin_repo/operator-begin-race.txt"
        fi
        php -r '
require $argv[1] . "/agent/src/Promotion/AuthorizedReleaseRepository.php";
try {
    $binding = \WPrism\AuthorizedReleaseRepository::acquire(
        $argv[2], $argv[3], $argv[4], $argv[5], $argv[6]
    );
    $git = trim((string) shell_exec("git -C " . escapeshellarg($argv[2]) . " rev-parse --absolute-git-dir"));
    $lock = $git . "/wprism-control/repository.lock";
    $probe = proc_open(
        [PHP_BINARY, "-r", '\''$h=fopen($argv[1], "rb"); exit(is_resource($h) && flock($h, LOCK_EX | LOCK_NB) ? 1 : 0);'\'', $lock],
        [1 => ["pipe", "w"], 2 => ["pipe", "w"]],
        $pipes
    );
    if (!is_resource($probe)) exit(75);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($probe) !== 0) exit(75);
    if ($argv[7] !== "") file_put_contents($argv[7], "lease-under-repository-lock\n");
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(73);
}
' "$WPRISM_RELEASE_PRODUCT_ROOT" "$begin_repo" "$begin_operation" "$begin_commit" \
          "$begin_tree" "$begin_owner" "${WPRISM_BEGIN_BINDING_EVIDENCE:-}" || exit $?
      fi
      exit "${WPRISM_BEGIN_EXIT:-0}" ;;
  *" wprism promotion-abort "*) exit "${WPRISM_ABORT_EXIT:-0}" ;;
  *" wprism code-preflight "*)
      printf '%s\n' '{"format":"wprism-code-runtime/v1","enabled":true,"change_required":true,"compatible":true,"code_revision":"d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4d4","target":{"php":"8.3.0","wordpress":"6.8.2","source":"target-control-plane"},"requirements":[],"diagnostics":[]}'
      exit 0 ;;
  *" wprism code-stage "*) exit 0 ;;
  *" wprism deploy "*) exit "${WPRISM_LIFECYCLE_EXIT:-0}" ;;
  *" wprism lifecycle-settle "*) exit 0 ;;
  *" wprism code-finalize "*) exit 0 ;;
  *" wprism apply "*) exit "${WPRISM_APPLY_EXIT:-0}" ;;
  *" wprism checkpoint-seal "*)
      out=""
      for a in "$@"; do case "$a" in --output=*) out="${a#--output=}" ;; esac; done
      [ -n "$out" ] || exit 2
      cat > "$out"
      exit "${WPRISM_SEAL_EXIT:-0}" ;;
  *" db export "*)
      for a in "$@"; do
        case "$a" in
          -) printf 'fixture checkpoint\n' ;;
          /*) printf 'fixture checkpoint\n' > "$a" ;;
        esac
      done
      exit "${WPRISM_EXPORT_EXIT:-0}" ;;
  *" db import "*) exit "${WPRISM_IMPORT_EXIT:-0}" ;;
SH;
$wp = str_replace($anchor, trim($release), $wp);
file_put_contents($wpPath, $wp);
chmod($wpPath, 0700);

echo "release fixture ready: $dir\n";
