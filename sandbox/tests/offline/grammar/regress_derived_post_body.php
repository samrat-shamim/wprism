<?php
declare(strict_types=1);

// The `derived` post-type body mode (engine feature `derived-post-body/v1`).
//
// The case it ships for: Contact Form 7 derives post_content as
// implode("\n", wpcf7_array_flatten($props)) inside save() — measured on 6.1.7,
// its only three occurrences by name, all in that function — while writing the
// same properties to `_<prop>` post meta. Carrying that body published a second,
// undeclared copy of state already carried as meta, including the SOURCE site's
// admin address, which the default form's sender holds and which only the
// reviewed `_mail` allow_pii rule is meant to carry. (CF7 does read the body
// implicitly, through WP_Query search; DerivedBodyGrammar's docblock states what
// that costs a target with no rebuild action.)
//
// Each assertion below fails against the behaviour before this mode existed.
$root = dirname(__DIR__, 4);
require_once __DIR__ . '/../../lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $root . '/agent/src/Grammar/DerivedBodyGrammar.php';
require_once $root . '/agent/src/Grammar/PostTypeGrammar.php';
require_once $root . '/agent/src/Adapter/AdapterContractGrammar.php';

use WPrism\AdapterContractGrammar;
use WPrism\BodyRefGrammar;
use WPrism\DerivedBodyGrammar;
use WPrism\PostTypeGrammar;

// ---- the mode is gated, not a fourth base member -------------------------
wprism_check_same(
    ['blocks', 'verbatim', 'serialized'],
    PostTypeGrammar::bodyModes(),
    'the base body vocabulary is still exactly three: a fourth member here would be legal for a spec_version 2 manifest'
);
wprism_check(
    !in_array(DerivedBodyGrammar::BODY_MODE, PostTypeGrammar::bodyModes(), true),
    'derived is NOT in the base vocabulary'
);
wprism_check_same(
    [DerivedBodyGrammar::BODY_MODE],
    PostTypeGrammar::featureGatedBodyModes()[DerivedBodyGrammar::FEATURE] ?? [],
    'derived is published beside the base set, keyed by the feature that admits it'
);
wprism_check(
    in_array(DerivedBodyGrammar::FEATURE, AdapterContractGrammar::implemented_features(), true),
    'this engine publishes the feature it implements, so an engine without it refuses BY FEATURE NAME'
);

// ---- the gate refuses the mode declared without its feature ---------------
$declared = ['name' => 'probe', 'post_types' => ['wpcf7_contact_form' => ['body' => DerivedBodyGrammar::BODY_MODE]]];
$refusal = null;
try {
    DerivedBodyGrammar::assert_body_mode_gate($declared, "manifest 'probe'");
} catch (RuntimeException $e) {
    $refusal = $e->getMessage();
}
wprism_check(
    is_string($refusal)
        && str_contains($refusal, DerivedBodyGrammar::FEATURE)
        && str_contains($refusal, 'engine_features')
        && str_contains($refusal, 'spec_version 3'),
    'the mode without its feature refuses by feature name, and names the channel that admits it'
);
// PostTypeGrammar deliberately RECOGNISES the gated value rather than calling it
// a typo, so § v3.2/§ v3.3's verdicts stay distinct. That deferral is only safe
// because the gate above actually refuses — assert both halves, or a future
// reader could delete one and still see green.
$recognised = true;
try {
    PostTypeGrammar::validate_post_type_contracts($declared);
} catch (RuntimeException) {
    $recognised = false;
}
wprism_check($recognised, 'the contract validator defers the gated value instead of pre-empting the three verdicts');

wprism_check_same(
    [],
    (function (): array {
        try {
            DerivedBodyGrammar::assert_body_mode_gate(
                ['name' => 'probe', 'engine_features' => [DerivedBodyGrammar::FEATURE],
                    'post_types' => ['x' => ['body' => DerivedBodyGrammar::BODY_MODE]]],
                "manifest 'probe'"
            );
            return [];
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }
    })(),
    'the same declaration WITH the feature is admitted'
);
wprism_check_same(
    [],
    (function (): array {
        try {
            DerivedBodyGrammar::assert_body_mode_gate(
                ['name' => 'probe', 'post_types' => ['x' => ['body' => 'blocks']]],
                "manifest 'probe'"
            );
            return [];
        } catch (RuntimeException $e) {
            return [$e->getMessage()];
        }
    })(),
    'a manifest that does not spend the mode is untouched by its gate'
);

// ---- the canonical body of a derived post type ---------------------------
wprism_check_same('', DerivedBodyGrammar::BODY, 'the canonical body a derived post type carries is empty');

// ---- the shipped adapter that spends it ----------------------------------
$cf7 = json_decode(
    (string) file_get_contents($root . '/adapter-packages/contact-form-7/package/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
wprism_check_same(
    DerivedBodyGrammar::BODY_MODE,
    $cf7['post_types']['wpcf7_contact_form']['body'] ?? null,
    'CF7 declares its contact form body derived: the plugin derives it from properties the adapter carries as meta'
);
wprism_check(
    in_array(DerivedBodyGrammar::FEATURE, (array) ($cf7['engine_features'] ?? []), true)
        && in_array('spec-window/v1', (array) ($cf7['engine_features'] ?? []), true)
        && (int) ($cf7['spec_version'] ?? 0) === 3,
    'CF7 spends the mode through the declaration channel: spec_version 3, the feature, and spec-window/v1 which claims engine_features'
);
// The properties the body duplicated are still carried, or this mode would be
// dropping state rather than declining to duplicate it.
foreach (['_form', '_mail', '_mail_2', '_messages', '_additional_settings'] as $property) {
    wprism_check(
        isset($cf7['post_meta'][$property]),
        "the property the derived body flattened is still carried authoritatively as meta: $property"
    );
}
// And no exception route reviews the copy in. A plain-text body has no PII
// exception at all ("unruled prose has no blanket exception", spec § clearance);
// the only body-level one is pii_paths inside a structured body_refs record,
// which needs body-pii-paths/v1 — so pin that the capsule reaches for neither.
wprism_check(
    !in_array(BodyRefGrammar::PII_FEATURE, (array) ($cf7['engine_features'] ?? []), true)
        && !array_key_exists(BodyRefGrammar::SECTION, $cf7),
    'CF7 declares neither body-pii-paths/v1 nor a body_refs section: the undeclared copy is removed, not reviewed in'
);

wprism_check_summary('derived post body');
