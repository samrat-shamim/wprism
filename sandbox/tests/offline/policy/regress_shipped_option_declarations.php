<?php
declare(strict_types=1);

/**
 * Offline (no docker, no WordPress bootstrap) regression harness for
 * DUO-3509: the option names a fresh `duo init` on WP 7.0.3 + WooCommerce
 * 11.0.1 + Yoast SEO 28.3 + Contact Form 7 6.1.7 demanded a decision for,
 * and the classifications this repository now ships for them.
 *
 * Nothing here is a fixture. Every check loads the REAL shipped
 * manifests/{core,woocommerce,yoast,contact-form-7}.json into a real
 * Policy and asks the same question the product path asks —
 * Policy::option_rule() / option_rule_details() — because the defect this
 * file pins was precisely that those two returned null for these names, so
 * a fixture manifest declaring them would prove nothing about the library
 * a site actually pins. Run against the pre-DUO-3509 manifests every
 * class assertion below fails with "unclassified".
 *
 * Three groups deserve their own note:
 *
 *   - The DELIBERATE-OMISSION group asserts that `wp_user_roles` still
 *     resolves to NULL. That is the one check here that fails if someone
 *     ADDS a declaration, and it is deliberate: core.json's own DUO-3509
 *     note records why an exact rule for that name cannot be right (the
 *     option name embeds $table_prefix — WP_Roles::_init(),
 *     wp-includes/class-wp-roles.php:342 — and the value is a merge that
 *     every plugin activation mutates, with no hook-free apply path). A
 *     future contributor who classifies it must delete this check, which
 *     means reading that note first.
 *   - The PATTERN group proves `schema-ActionScheduler_*` resolves through
 *     woocommerce.json's option_patterns rather than by exact name,
 *     because the name is computed as `'schema-' . static::class`
 *     (ActionScheduler_Abstract_Schema.php:92/:124) and an exact pair of
 *     rules would silently stop covering a third schema subclass.
 *   - The SUB-KEY group checks wpseo_llmstxt's shape, not just its class.
 *     `other_included_pages` is a LIST of post ids, which json_refs cannot
 *     address at all (StructuredReferenceCodec skips a path that resolves
 *     to a container, Kernel/StructuredReferenceCodec.php:38), so the
 *     `ref: post[]` sub-key rule is the correctness claim — a future
 *     "simplification" to a whole-value json_refs declaration would drop
 *     those ids silently and is exactly what this group refuses.
 *
 * The NOTE group is not decoration either: DUO-3509's deliverable for
 * several of these names is the recorded reasoning, so each declaration is
 * required to be named in its own manifest's notes. A rule added later
 * without a note fails here.
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..."
 * and the script exits 1.
 */

define('DUO_SPEC_VERSION', 2);

require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';

use Duo\Policy;

$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

$root = dirname(__DIR__, 4);

/** @var array<string,array<string,mixed>> $manifests */
$manifests = [];
foreach (['core', 'woocommerce', 'yoast', 'contact-form-7'] as $name) {
    $manifests[$name] = json_decode(
        (string) file_get_contents($root . "/manifests/$name.json"),
        true,
        flags: JSON_THROW_ON_ERROR
    );
}

// The pinned set DUO-3509's reproduction used, in the order a site.duo.json
// lists them (core first — PolicyRuleResolver's core-yields-to-plugin
// precedence is what makes that order safe, and asserting `source` below is
// what proves no plugin manifest silently shadowed a core declaration).
$policy = Policy::from_snapshot([
    'dispositions' => null,
    'format' => 'duo-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'duo-adapter-sources/v2', 'out_of_tree' => []],
    'manifests' => array_values($manifests),
    'site' => [
        'manifests' => array_keys($manifests),
        'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
        'spec_version' => DUO_SPEC_VERSION,
    ],
]);

/** Assert one option name's effective class AND the manifest that declared it. */
function declares(Policy $policy, string $option, string $class, string $source, string $why): void
{
    $details = $policy->option_rule_details($option);
    $actual = $details['rule']['class'] ?? 'unclassified';
    check(
        $actual === $class && $details['source'] === $source,
        "options.$option resolves $class from '$source' ($why) — got "
        . $actual . " from '" . ($details['source'] ?? 'nothing') . "'"
    );
}

echo "-- core (WordPress 7.0.3) --\n";
declares($policy, 'permalink_structure', 'authored', 'core', "the site's URL grammar");
declares($policy, 'blog_public', 'authored', 'core', 'Settings -> Reading search-engine visibility');
declares($policy, 'fresh_site', 'runtime', 'core', 'per-install first-run flag');
declares($policy, 'theme_switched', 'runtime', 'core', 'one-shot post-switch baton');
declares($policy, 'current_theme', 'runtime', 'core', 'display-name residue of switch_theme()');
declares($policy, 'uninstall_plugins', 'runtime', 'core', 'activation-time uninstall-callback map');
declares($policy, 'user_count', 'runtime', 'core', "cache of the target's own wp_users count");

// blog_public's value is the string '1', which Lint's shallow bare_id scan
// would otherwise resolve to post 1 ('Hello world!' on every fresh install).
// lint_ok is therefore load-bearing, not cosmetic — see core.json's note.
check(
    ($policy->option_rule('blog_public')['lint_ok'] ?? false) === true,
    'options.blog_public is audited lint_ok so the literal \'1\' is not reported as a reference to post 1'
);
check(
    ($policy->option_rule('permalink_structure')['ref'] ?? null) === null,
    'options.permalink_structure carries no ref — a permalink structure string is not an entity pointer'
);

echo "\n-- core: the deliberate omission --\n";
foreach (['wp_user_roles', 'xy_user_roles'] as $rolesOption) {
    check(
        $policy->option_rule($rolesOption) === null,
        "options.$rolesOption stays UNDECLARED so `duo pending` keeps demanding a per-site decision "
        . '(core.json records why: the name embeds $table_prefix and the value is a plugin-mutated merge)'
    );
}

echo "\n-- woocommerce (11.0.1) --\n";
declares($policy, 'default_product_cat', 'authored', 'woocommerce', 'the fallback product category a human picks');
declares($policy, 'product_cat_children', 'derived', 'woocommerce', "core's per-taxonomy hierarchy cache");
declares($policy, 'wc_installing', 'runtime', 'woocommerce', "WC_Install's raw-SQL install mutex row");
check(
    ($policy->option_rule('default_product_cat')['ref'] ?? null) === 'term',
    'options.default_product_cat resolves through the term ledger rather than carrying a source-local id'
);

echo "\n-- woocommerce: Action Scheduler schema rows resolve by PATTERN --\n";
foreach (['ActionScheduler_StoreSchema', 'ActionScheduler_LoggerSchema', 'ActionScheduler_FutureSchema'] as $schemaClass) {
    declares(
        $policy,
        "schema-$schemaClass",
        'runtime',
        'woocommerce',
        "'schema-' . static::class carries a per-environment migration timestamp"
    );
}
check(
    !array_key_exists('schema-ActionScheduler_StoreSchema', (array) ($manifests['woocommerce']['options'] ?? [])),
    'the Action Scheduler schema rows are declared by pattern only, never pinned to the two exact class names'
);

echo "\n-- yoast (28.3) --\n";
declares($policy, 'wpseo_tracking_only', 'runtime', 'yoast', 'three write-once telemetry timestamps');
declares($policy, 'yoast_migrations_free', 'runtime', 'yoast', "Yoast's migration runner state, lock and error included");
declares($policy, 'wpseo_llmstxt', 'env', 'yoast', 'llms.txt settings, carved to named sub-keys');

echo "\n-- yoast: wpseo_llmstxt sub-key shape --\n";
$llmstxt = $policy->option_rule('wpseo_llmstxt') ?? [];
check(
    ($llmstxt['required'] ?? null) === false,
    'options.wpseo_llmstxt declares required:false — the row self-populates and is not an operator provisioning slot'
);
$subKeys = (array) ($llmstxt['sub_keys'] ?? []);
// The six keys WPSEO_Option_Llmstxt::$defaults defines, and nothing else:
// WPSEO_Option::validate() rebuilds $clean from get_defaults() on every save
// (inc/options/class-wpseo-option.php:494), so the blob has no other key.
$expected = [
    'about_us_page' => 'post',
    'contact_page' => 'post',
    'llms_txt_selection_mode' => null,
    'other_included_pages' => 'post[]',
    'privacy_policy_page' => 'post',
    'shop_page' => 'post',
    'terms_page' => 'post',
];
check(
    array_keys($subKeys) === array_keys($expected),
    'wpseo_llmstxt declares exactly the keys WPSEO_Option_Llmstxt::$defaults defines — got '
    . implode(', ', array_keys($subKeys))
);
foreach ($expected as $subKey => $ref) {
    $rule = (array) ($subKeys[$subKey] ?? []);
    check(
        ($rule['class'] ?? null) === 'authored' && ($rule['ref'] ?? null) === $ref,
        "wpseo_llmstxt.$subKey is authored with ref=" . var_export($ref, true)
        . ' — got ' . var_export($rule['class'] ?? null, true) . '/' . var_export($rule['ref'] ?? null, true)
    );
}
// The claim this file exists to protect: a list of post ids is unreachable
// through json_refs, so the whole-value shape must never come back.
check(
    !array_key_exists('json_refs', $llmstxt) && ($llmstxt['class'] ?? null) !== 'authored',
    'wpseo_llmstxt is NOT a whole-value authored+json_refs declaration — json_refs cannot address '
    . 'other_included_pages, a list of scalars, so those page ids would be dropped silently'
);

echo "\n-- contact-form-7 (6.1.7) --\n";
declares($policy, 'wpcf7', 'env', 'contact-form-7', 'one blob mixing an upgrade gate with live API credentials');
check(
    ($policy->option_rule('wpcf7')['required'] ?? null) === false
        && ($policy->option_rule('wpcf7')['sub_keys'] ?? null) === null,
    'options.wpcf7 is required:false with no sub-key carve-out — nothing in it was verified authored-and-portable'
);

echo "\n-- every DUO-3509 declaration is named in its own manifest's notes --\n";
// THE DEFERRAL, WRITTEN AT THE SITE (WP-6.4). The grep below is load-bearing
// regression coverage implemented as `str_contains()` over free prose, and it
// has a typed replacement as of WP-6.4: the `declaration_evidence` section
// (spec/repo-format.md § v3.13) carries {source, locator, observation} rows
// under a TARGET whose head must be a top-level key the manifest declares, so
// the link is checked rather than approximated — a record for a declaration
// that was deleted refuses at load, which no grep over prose can notice.
//
// It is not converted here, and the reason is AGENTS.md rule 2 rather than
// effort. Adopting the section in these four manifests edits four manifests'
// bytes, `ArtifactPolicyIdentity::manifest_rows()` folds those bytes into each
// adapter's `digest`, and every `site.duo.json` content pin and every
// certificate binding one stops matching — a fleet-visible change bought for a
// documentation improvement. So the schema check applies to fixtures and
// out-of-tree adapters (`regress_structured_evidence.php`), this grep stays for
// the shipped library, and it converts PER ADAPTER, when one is next opened for
// a product reason and is paying the digest move anyway.
//
// `regress_structured_evidence.php` PART 4 asserts both halves of that
// sentence, including that this grep is still here — so the deferral cannot
// quietly become permanent-by-forgetting, and cannot be half-removed either.
$documented = [
    'core' => ['permalink_structure', 'blog_public', 'fresh_site', 'theme_switched', 'current_theme',
        'uninstall_plugins', 'user_count', 'wp_user_roles'],
    'woocommerce' => ['default_product_cat', 'product_cat_children', 'wc_installing', 'schema-ActionScheduler_'],
    'yoast' => ['wpseo_llmstxt', 'wpseo_tracking_only', 'yoast_migrations_free'],
    'contact-form-7' => ['wpcf7'],
];
foreach ($documented as $manifestName => $names) {
    // core/yoast/contact-form-7 keep notes as a list, woocommerce as a
    // title => text map; flatten both rather than assuming one shape.
    $notes = (array) ($manifests[$manifestName]['notes'] ?? []);
    $text = implode("\n", array_map(
        static fn($k, $v): string => is_string($v) ? "$k\n$v" : '',
        array_keys($notes),
        array_values($notes)
    ));
    check(str_contains($text, 'DUO-3509'), "manifests/$manifestName.json carries a DUO-3509 note");
    foreach ($names as $optionName) {
        check(
            str_contains($text, $optionName),
            "manifests/$manifestName.json's notes name $optionName"
        );
    }
}

echo "\n";
if ($failures > 0) {
    echo "FAILED: $failures check(s)\n";
    exit(1);
}
echo "ALL PASSED\n";
