<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/tools/src/AdapterPackageValidator.php';

use WPrism\AdapterLibrary;
use WPrism\Policy;
use WPrism\Tooling\AdapterPackageValidator;
use WPrism\Tooling\AdapterProductionReadiness;
use WPrism\Tooling\ArtifactLibrary;

$validated = AdapterPackageValidator::validate($root, 'block-visibility');
wprism_check_same('block-visibility', $validated['adapter'], 'the isolated capsule passes its complete package validator');

$package = dirname(__DIR__, 2);
$manifest = json_decode((string) file_get_contents($package . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$disposition = json_decode((string) file_get_contents($package . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
$artifacts = json_decode((string) file_get_contents($package . '/evidence/artifacts.lock.json'), true, 512, JSON_THROW_ON_ERROR);
$policy = Policy::load(null, ['core', 'block-visibility'], adapterLibrary: AdapterLibrary::fromSourcePackage($root, 'block-visibility'));

// The claim is experimental ON PURPOSE, and the assertions below are the
// boundary that word stands for — not a stage this capsule is passing through.
wprism_check_same('experimental', $disposition['status'], 'the reviewed claim is experimental, because the plugin\'s primary surface is withheld');
wprism_check_same(
    ['capture', 'compile', 'plan'],
    $disposition['capabilities']['operations'],
    'no deploy, apply, recapture or render-api is claimed anywhere'
);
wprism_check_same([], $disposition['capabilities']['lifecycle_phases'], 'and no lifecycle phase, because no deploy is claimed');

$readiness = AdapterProductionReadiness::record($root, 'block-visibility');
wprism_check_same('unready', $readiness['readiness'], 'the readiness record refuses to call this production-ready');
wprism_check(
    array_key_exists('identity-references', $readiness['blocked']),
    'identity-references is BLOCKED rather than merely a gap: no amount of capsule work can declare these references today'
);
wprism_check(
    str_contains($readiness['blocked']['identity-references'], 'any_block_structured_attribute_reference_paths'),
    'and it names the engine-gap primitive, so the blocker is countable rather than prose'
);

// The withheld surface, stated as data rather than as a promise in prose.
$blocks = array_keys($manifest['block_attrs']);
wprism_check_same(114, count($blocks), 'every block measured to carry the attribute declares it, less the one another adapter owns — 114 of the 115');
wprism_check(!in_array('core/post-comments', $blocks, true), 'and the one block that does NOT carry the attribute is absent, so the list is measured rather than copied');
wprism_check(
    !array_key_exists('core/legacy-widget', $manifest['block_attrs']),
    'core/legacy-widget is deliberately undeclared: The Events Calendar owns it with a whole-block codec, and a rule list here would clobber that codec whenever this capsule sorts later'
);

// THE LOAD-BEARING GUARD. block_attr_rules() replaces a block's whole rule
// LIST per block name, last pin wins (ContentAttributeRuleResolver.php:16-23) —
// it is NOT a per-path merge. So declaring only `blockVisibility` on a block
// the platform core manifest also declares would DELETE core's rules for it:
// core/image's post ref, core/gallery's ids, core/block's reusable-block ref,
// eighteen URL tokenizers. This capsule would then cause exactly the silent id
// leakage it exists to prevent. Every overlapping entry must therefore be a
// complete superset of core's, and a core rule added later must fail HERE
// rather than be dropped in silence.
$coreBlockAttrs = json_decode(
    (string) file_get_contents($root . '/platform/adapter-library/core/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
)['block_attrs'];
$shadowed = [];
$missingRules = [];
foreach ($coreBlockAttrs as $block => $coreRules) {
    if (!array_key_exists($block, $manifest['block_attrs'])) {
        continue;
    }
    $declared = $manifest['block_attrs'][$block];
    foreach ($coreRules as $coreRule) {
        if (!in_array($coreRule, $declared, true)) {
            $shadowed[] = $block;
            $missingRules[] = $block . ':' . (string) ($coreRule['path'] ?? '?');
        }
    }
}
wprism_check_same([], $missingRules, 'every core block rule this capsule overlaps is restated verbatim, so declaring the attribute deletes none of core\'s own refs, tokenizers or boundaries');
wprism_check_same(
    20,
    count(array_intersect(array_keys($coreBlockAttrs), $blocks)),
    'and the overlap it must keep whole is exactly the twenty core blocks that carry the attribute'
);

foreach ($blocks as $block) {
    $rules = $manifest['block_attrs'][$block];
    $own = array_values(array_filter(
        $rules,
        static fn(array $rule): bool => ($rule['path'] ?? null) === 'blockVisibility'
    ));
    if (count($own) !== 1 || !is_string($own[0]['unsupported'] ?? null)) {
        wprism_check(false, "block_attrs.$block declares exactly one unsupported blockVisibility rule");
        break;
    }
}
wprism_check(true, 'each block carries exactly one blockVisibility rule, as a reviewed unsupported boundary, beside whatever core already declared');
$reasons = [];
foreach ($manifest['block_attrs'] as $rules) {
    foreach ($rules as $rule) {
        if (($rule['path'] ?? null) === 'blockVisibility') {
            $reasons[] = (string) $rule['unsupported'];
        }
    }
}
$reasons = array_values(array_unique($reasons));
wprism_check_same(1, count($reasons), 'all 114 carry the SAME reviewed reason, so the boundary is one decision rather than 114');
wprism_check(strlen($reasons[0]) <= 512, 'and it fits the reviewed-reason budget the grammar enforces');
foreach (['blockVisibility', 'visibilityPresets', 'int[]'] as $needle) {
    wprism_check(str_contains($reasons[0], $needle), "the reason names $needle, so a reader learns the exact shape that cannot be declared");
}

// Nothing may quietly claim the refused surface through another section.
foreach (['attr_id_codecs', 'body_refs', 'shortcode_attrs', 'tables', 'taxonomies', 'user_meta', 'actions', 'providers', 'regenerators', 'deletions'] as $section) {
    wprism_check(!array_key_exists($section, $manifest), "the capsule declares no $section");
}
wprism_check_same(['visibility_preset'], array_keys($manifest['post_types']), 'exactly one post type is claimed');
wprism_check_same('verbatim', $manifest['post_types']['visibility_preset']['body'], 'whose body is verbatim: a preset supports title and custom-fields only, so its content is not block data');
wprism_check(!array_key_exists('post_meta', $manifest), 'and NO static post_meta: the preset keys are unprefixed, so claiming them statically would classify another plugin\'s rows');

// The interpreter is what makes those bare keys safe, and what refuses the
// reference-bearing control set.
wprism_check_same('block-visibility', $manifest['interpreter'], 'the capsule names its interpreter');
$interpreter = (string) file_get_contents($package . '/package/runtime/interpreters/block-visibility.php');
wprism_check(str_contains($interpreter, 'is_preset_meta'), 'which scopes the bare keys by a distinctive sibling rather than by name alone');
wprism_check(
    str_contains($interpreter, "'enable' => true") && str_contains($interpreter, "'layout' => false"),
    'and exempts only the two booleans register-presets.php declares as boolean from the bare_id heuristic, never the string enum beside them'
);
foreach (['postID', 'postTaxonomy', 'attributesAuthor'] as $field) {
    wprism_check(str_contains($interpreter, "'" . $field . "'"), "and enumerates the measured reference field $field from the plugin's own dispatch switch");
}

wprism_check_same(
    ['3.7.1'],
    array_keys(ArtifactLibrary::loadPackage($root, 'block-visibility')['plugins']['block-visibility']),
    'one artifact is pinned: the exercised release'
);
wprism_check_same('exercise-fixture', $artifacts['plugins']['block-visibility']['3.7.1']['role'], 'and it is an exercise fixture, not a certified boundary — this capsule ships no version matrix');
wprism_check(
    !in_array('exact-artifact-version-matrix', $disposition['evidence']['tests'], true),
    'so the reviewed claim does not cite a matrix that does not exist'
);
wprism_check_same(['min' => '3.7.1', 'max' => '3.7.2'], $manifest['version_range'], 'the manifest names the exact probed release window');
wprism_check_same('block-visibility/block-visibility.php', $manifest['plugin'], 'and the subject plugin file');

$surfaces = array_column($disposition['unsupported'], 'surface');
foreach ([
    'block_attrs.*.blockVisibility',
    'post_meta.control_sets entity references',
    'option:block_visibility_settings absent-row default completion',
    'deploy/apply',
    'multisite',
] as $surface) {
    wprism_check(in_array($surface, $surfaces, true), "the disposition records '$surface' as an explicit unsupported surface");
}

wprism_check_summary('regress_block_visibility_package_contract');
