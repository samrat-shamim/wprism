<?php
/**
 * Offline regression for StateHandoffVerifier (issue #3350 slice 7: the
 * before/after canonical options snapshot comparison collaborator
 * extracted from Deploy). Deliberately narrow, the same wiring/shape idiom
 * the earlier slices in this issue established: this file does not
 * re-implement or re-assert snapshot/comparison behavior -- doing so from a
 * hand-copied twin of the logic would only add a second copy that could
 * silently drift from the real one, and
 * sandbox/tests/offline/code-half/regress_lifecycle_state_handoff.php /
 * sandbox/tests/offline/code-half/regress_lifecycle_options_snapshot.php already exercise the
 * real behavior deeply, unchanged, through Deploy's own kept facades (both
 * reflect into Deploy::class specifically). This file proves the extraction
 * itself: Deploy no longer inlines the moved bodies, only thin facades
 * remain, and StateHandoffVerifier is a directly reachable, correctly-shaped
 * standalone collaborator.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$deploySource = file_get_contents($root . '/agent/src/Promotion/Deploy.php');
$verifierSource = file_get_contents($root . '/agent/src/Promotion/StateHandoffVerifier.php');
if (!is_string($deploySource) || !is_string($verifierSource)) {
    fwrite(STDERR, "FAIL: could not read Deploy/StateHandoffVerifier sources\n");
    exit(1);
}

$checks = 0;
function check(bool $ok, string $message): void {
    global $checks;
    $checks++;
    if (!$ok) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    fwrite(STDOUT, "ok: $message\n");
}

check(str_contains($deploySource, "require_once __DIR__ . '/StateHandoffVerifier.php';"), 'Deploy loads the extracted state-handoff verifier');
check(str_contains($verifierSource, 'final class StateHandoffVerifier'), 'StateHandoffVerifier is a dedicated collaborator');

// === Prove the extraction itself. All three moved methods keep a thin
// Deploy facade -- two because run() still calls them directly at its own
// established sites (options_snapshot(), unexpected_lifecycle_state_changes()),
// and all three because reflection-based tests reach at least one of them
// against Deploy::class specifically (bind_lifecycle_missing_options() has
// NO production caller left on Deploy at all once options_snapshot()'s own
// internal call moved with it -- its facade survives solely for
// regress_lifecycle_state_handoff.php's reflection-based test).
check(
    str_contains($deploySource, 'return StateHandoffVerifier::options_snapshot($repo, $policy, $compiled, $forceUnresolvedRefs);'),
    'Deploy::options_snapshot() is a thin facade delegating to StateHandoffVerifier'
);
check(
    str_contains($deploySource, 'return StateHandoffVerifier::bind_lifecycle_missing_options($observedDocument, $desiredDocument);'),
    'Deploy::bind_lifecycle_missing_options() is a thin facade delegating to StateHandoffVerifier'
);
check(
    str_contains($deploySource, 'return StateHandoffVerifier::unexpected_lifecycle_state_changes($beforeDocument, $afterDocument, $desiredDocument);'),
    'Deploy::unexpected_lifecycle_state_changes() is a thin facade delegating to StateHandoffVerifier'
);
check(
    !preg_match('/private static function options_snapshot\(\s*string \$repo,\s*Policy \$policy,\s*CompiledRepository \$compiled,\s*bool \$forceUnresolvedRefs\s*\): array \{\s*\$snapshot = Capture::snapshot_options_core/', $deploySource),
    'Deploy.php no longer inlines options_snapshot()\'s own body (only the facade remains)'
);
check(
    !str_contains($deploySource, '$observed = OptionState::records($observedDocument);'),
    'Deploy.php no longer inlines bind_lifecycle_missing_options()\'s own body (only the facade remains)'
);
check(
    !str_contains($deploySource, "\$managed = array_fill_keys(['active_plugins', 'template', 'stylesheet'], true);"),
    'Deploy.php no longer inlines unexpected_lifecycle_state_changes()\'s own body (only the facade remains)'
);

// The three entry points keep exactly their original parameter shapes, all
// now public on the new class so Deploy's private facades can call them.
require_once $root . '/agent/src/Promotion/StateHandoffVerifier.php';
require_once $root . '/agent/src/Promotion/Deploy.php';
$verifier = new ReflectionClass(\WPrism\StateHandoffVerifier::class);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $verifier->getMethod('options_snapshot')->getParameters())
        === ['repo', 'policy', 'compiled', 'forceUnresolvedRefs'],
    'options_snapshot() keeps its original four parameters'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $verifier->getMethod('bind_lifecycle_missing_options')->getParameters())
        === ['observedDocument', 'desiredDocument'],
    'bind_lifecycle_missing_options() keeps its original two parameters'
);
check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $verifier->getMethod('unexpected_lifecycle_state_changes')->getParameters())
        === ['beforeDocument', 'afterDocument', 'desiredDocument'],
    'unexpected_lifecycle_state_changes() keeps its original three parameters'
);
foreach (['options_snapshot', 'bind_lifecycle_missing_options', 'unexpected_lifecycle_state_changes'] as $public) {
    check($verifier->getMethod($public)->isPublic(), "$public() is public on StateHandoffVerifier");
}
foreach (['options_snapshot', 'bind_lifecycle_missing_options', 'unexpected_lifecycle_state_changes'] as $stillPrivateOnDeploy) {
    check(
        (new ReflectionClass(\WPrism\Deploy::class))->getMethod($stillPrivateOnDeploy)->isPrivate(),
        "Deploy::$stillPrivateOnDeploy() facade stays private, matching the moved method's original visibility"
    );
}

if ($checks < 1) {
    fwrite(STDERR, "FAIL: no checks ran\n");
    exit(1);
}
printf("StateHandoffVerifier regression: %d checks passed\n", $checks);
