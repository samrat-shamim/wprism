<?php
/**
 * Offline characterization for DUO-3348 slice 12: the post-type body/phase
 * grammar now lives in PostTypeGrammar rather than Policy.php.
 *
 * The broad manifest-validation suites already exercise these refusals via
 * Policy::load(). This focused proof adds the extracted collaborator's own
 * direct behavior, checks that Policy still exposes the same lookup defaults,
 * and guards the no-facade wiring boundary so the two validators cannot drift
 * back into the monolith or become an untested duplicate.
 */

$repo = dirname(__DIR__, 2);
if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
require_once $repo . '/agent/src/OptionState.php';
require_once $repo . '/agent/src/Policy.php';

use Duo\Policy;
use Duo\PostTypeGrammar;

$failures = 0;
function post_type_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

function post_type_refusal(array $manifest): string {
    try {
        PostTypeGrammar::validate_post_type_contracts($manifest);
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '';
}

post_type_check(
    PostTypeGrammar::bodyModes() === ['blocks', 'verbatim'],
    'the body vocabulary is published in its original order'
);
post_type_check(
    PostTypeGrammar::postTypePhases() === ['normal', 'early'],
    'the phase vocabulary is published in its original order'
);
post_type_check(
    PostTypeGrammar::defaultBodyMode() === 'blocks'
        && PostTypeGrammar::defaultPostTypePhase() === 'normal',
    'the extracted defaults preserve the Policy lookup contract'
);

PostTypeGrammar::validate_post_type_contracts([
    'name' => 'fixture',
    'post_types' => [
        'acme_definition' => ['body' => 'verbatim', 'phase' => 'early'],
        'acme_normal' => [],
    ],
]);
post_type_check(true, 'valid body and phase declarations remain accepted');

$bodyMessage = post_type_refusal([
    'name' => 'fixture',
    'post_types' => ['acme_definition' => ['body' => 'verbatm']],
]);
post_type_check(
    str_contains($bodyMessage, "post_types.acme_definition.body='verbatm'")
        && str_contains($bodyMessage, 'blocks, verbatim'),
    'an invalid body mode refuses with its exact path and vocabulary'
);

$phaseMessage = post_type_refusal([
    'name' => 'fixture',
    'post_types' => ['acme_definition' => ['phase' => 'earliest']],
]);
post_type_check(
    str_contains($phaseMessage, "post_types.acme_definition.phase='earliest'")
        && str_contains($phaseMessage, 'normal, early'),
    'an invalid phase refuses with its exact path and vocabulary'
);

$policy = new Policy();
$policy->manifests = [[
    'name' => 'fixture',
    'post_types' => ['acme_definition' => ['body' => 'verbatim', 'phase' => 'early']],
]];
post_type_check(
    $policy->body_mode('acme_definition') === 'verbatim'
        && $policy->post_type_phase('acme_definition') === 'early'
        && $policy->body_mode('unrelated') === 'blocks'
        && $policy->post_type_phase('unrelated') === 'normal',
    'Policy runtime lookup still honors declared values and safe defaults'
);

$vocabularies = Policy::closed_vocabularies();
post_type_check(
    $vocabularies['post_type_body_modes'] === PostTypeGrammar::bodyModes()
        && $vocabularies['post_type_phases'] === PostTypeGrammar::postTypePhases(),
    'Policy publishes the extracted vocabularies without a second spelling'
);

$policySource = (string) file_get_contents($repo . '/agent/src/Policy.php');
post_type_check(
    substr_count($policySource, 'PostTypeGrammar::validate_post_type_contracts($manifest)') === 2
        && !str_contains($policySource, 'private static function validate_post_type_contracts'),
    'both Policy loader paths call the collaborator and no private duplicate remains'
);

if ($failures !== 0) {
    fwrite(STDERR, "regress_post_type_grammar: $failures failure(s)\n");
    exit(1);
}

echo "ALL PASSED\n";
