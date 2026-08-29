<?php
/**
 * Render `wprism rehearse`'s preview human view and its `--format=json`
 * document, so `regress_mup_leak_audit.sh` can hold the two against each
 * other the same way it does for assess/release/verify/recover.
 *
 * ## Why this verb is rendered rather than driven end to end
 *
 * The other views in the audit come out of a real `php cli/wprism` run against a
 * fake site, and so does half of this one: the audit drives `wprism rehearse
 * <env> --reap`, which prints MUP §2.2's containment disclosure BEFORE any
 * provider is contacted and then refuses, because a branch materialization
 * needs machine-local `environment_provider` configuration and a provider
 * process to negotiate with. Standing one of those up here would mean forking
 * `sandbox/tests/fixtures/rehearse/env-command-checks.php`'s fake provider,
 * and a second copy of that provider is exactly the kind of fixture that
 * drifts apart from the first.
 *
 * So this script renders the half that comes after convergence —
 * `RehearsalPlanPreview`, the "what a release would touch" report — from the
 * T3 rehearsal fixture generator's own `plan.json` + `surfaces.json`. Both
 * halves are the shipped classes with the shipped arguments; only the
 * `EnvironmentCommand` materialization in between is absent, and its output
 * belongs to `wprism env materialize` and predates MUP.
 *
 * The build call below mirrors `RehearseCommand::preview()` field for field
 * (branch, env, generated_at, operation, source_env) and the render mirrors
 * `RehearseCommand::run()`'s shape — `RehearsalDisclosure::lines()` first,
 * then the preview lines rendered WITHOUT the disclosure (the command prints
 * the banner once, at the top, before the provider runs; T5) — so what lands
 * in `rehearse-human.txt` is what an operator reads.
 *
 * usage: php rehearse-view.php <out-dir>
 *
 * Writes, into <out-dir>:
 *   fixture/plan.json, fixture/surfaces.json  the T3 generator's own output
 *   rehearse-human.txt                        the human view
 *   rehearse.json                             `wprism rehearse --format=json`
 *
 * Offline: no docker, no WordPress, no network, no provider, no target.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/cli/src/Rehearse/RehearsalPlanPreview.php';

use WPrism\Orchestrator\RehearsalDisclosure;
use WPrism\Orchestrator\RehearsalPlanPreview;

$argvList = $_SERVER['argv'] ?? [];
array_shift($argvList);
$out = (string) ($argvList[0] ?? '');
if ($out === '') {
    fwrite(STDERR, "usage: rehearse-view.php <out-dir>\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0700, true) && !is_dir($out)) {
    fwrite(STDERR, "rehearse-view: could not create '$out'\n");
    exit(1);
}

// REUSE, never fork: the surface rows carry `ProjectionVocabulary`'s own
// annotation constants and the category summary satisfies the arithmetic
// `PlanContract` enforces, which is precisely why that generator exists.
$generator = dirname(__DIR__) . '/rehearse/make-fixture.php';
$fixtures = $out . '/fixture';
$output = [];
$status = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($generator) . ' ' . escapeshellarg($fixtures) . ' 2>&1',
    $output,
    $status
);
if ($status !== 0) {
    fwrite(STDERR, "rehearse-view: the T3 rehearsal fixture generator failed:\n" . implode("\n", $output) . "\n");
    exit(1);
}

/** @return array<mixed> */
function mup_leak_read(string $path): array {
    if (!is_file($path)) {
        fwrite(STDERR, "rehearse-view: missing fixture input '$path'\n");
        exit(1);
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        fwrite(STDERR, "rehearse-view: '$path' is not a JSON document\n");
        exit(1);
    }

    return $decoded;
}

$preview = RehearsalPlanPreview::build(
    mup_leak_read($fixtures . '/plan.json'),
    mup_leak_read($fixtures . '/surfaces.json'),
    [
        'branch' => 'main',
        'env' => 'preview',
        'generated_at' => '2026-08-17T09:14:02Z',
        'operation' => 'release',
        'source_env' => 'production',
    ]
);

$lines = RehearsalDisclosure::lines();
foreach (RehearsalPlanPreview::render($preview, 50, false) as $line) {
    $lines[] = $line;
}

file_put_contents($out . '/rehearse-human.txt', implode("\n", $lines) . "\n");
file_put_contents($out . '/rehearse.json', RehearsalPlanPreview::encode($preview));

echo "ok: rendered the rehearsal human view and its JSON document from the T3 fixture\n";
