<?php
declare(strict_types=1);

/** Closed offline matrix for CF7 6.x property, identity, and residue shapes. */

$repoRoot = dirname(__DIR__, 4);
// This suite used to carry `define('WPRISM_SPEC_VERSION', 2)`. That literal was
// free while CF7's own manifest was spec 2; declaring `derived-post-body/v1`
// makes it spec 3, and a private copy of the number would then refuse this
// capsule's own manifest for being NEWER than the engine the suite pretended to
// be. agent_version.php exists for exactly this rot (WP-4.12) — read the source
// of record instead of retyping it.
require_once $repoRoot . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require_once $repoRoot . '/sandbox/tests/lib/check.php';
require_once $repoRoot . '/sandbox/tests/lib/wp_stubs.php';
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Policy/Policy.php';
require_once $repoRoot . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $repoRoot . '/agent/src/Capture/EntityMetaCapture.php';

use WPrism\CaptureSafetyGates;
use WPrism\EntityMetaCapture;
use WPrism\Interpreters\ContactForm7;
use WPrism\Policy;
use WPrism\Tokens;

/** @return array<string,mixed> */
function cf7_readiness_mail(bool $secondary = false): array {
    $mail = [
        'subject' => '[WPrism] [your-subject]',
        'sender' => 'WPrism <ops@example.test>',
        'body' => "Long UTF-8 mail — مرحبا — こんにちは\nhttps://source.example.test/contact",
        'recipient' => 'ops@example.test',
        'additional_headers' => 'Reply-To: [your-email]',
        'attachments' => '',
        'use_html' => 0,
        'exclude_blank' => false,
    ];
    if ($secondary) {
        $mail = ['active' => true, ...$mail];
    }
    return $mail;
}

/** @return array<string,mixed> */
function cf7_readiness_meta(bool $legacy = false, string $hashSeed = 'a', int $hashLength = 64): array {
    $properties = [
        'form' => "<label>名前 [text* your-name]</label>\n[text broken-tag",
        'mail' => cf7_readiness_mail(),
        'mail_2' => cf7_readiness_mail(true),
        'messages' => ['mail_sent_ok' => '送信しました', 'validation_error' => '入力を確認してください'],
        'additional_settings' => "demo_mode: on\nacceptance_as_validation: on",
    ];
    $meta = [
        '_hash' => str_repeat($hashSeed, $hashLength),
        '_locale' => 'en_US',
        '_old_cf7_unit_id' => '3199001',
    ];
    foreach ($properties as $name => $value) {
        $meta[($legacy ? '' : '_') . $name] = $value;
    }
    return $meta;
}

/** @return array<string,mixed> */
function cf7_readiness_entity(array $meta, string $uuid = '11111111-1111-4111-8111-111111111111'): array {
    return [
        'type' => 'post',
        'path' => "state/posts/wpcf7_contact_form/$uuid--form.md",
        'data' => [
            'type' => 'wpcf7_contact_form',
            'uuid' => $uuid,
            'slug' => 'readiness-form',
            'meta' => $meta,
        ],
        'body' => 'plugin-derived search projection',
    ];
}

/** @return list<array<string,mixed>> */
function cf7_readiness_diagnostics(ContactForm7 $interpreter, array $meta): array {
    return $interpreter->repository_diagnostics([cf7_readiness_entity($meta)]);
}

/** @return list<string> */
function cf7_readiness_messages(array $diagnostics): array {
    return array_map(static fn(array $diagnostic): string => (string) $diagnostic['message'], $diagnostics);
}

$policy = Policy::load(
    null,
    ['contact-form-7'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'contact-form-7')
);
$interpreter = $policy->interpreters()['contact-form-7'];
wprism_check($interpreter instanceof ContactForm7, 'the shipped manifest resolves its digest-bound CF7 interpreter');

$current = cf7_readiness_meta();
wprism_check_same([], cf7_readiness_diagnostics($interpreter, $current), 'the exact current CF7 property shape is clean');
wprism_check_same(
    [],
    cf7_readiness_diagnostics($interpreter, cf7_readiness_meta(false, 'b', 40)),
    'the exact CF7 6.0 SHA-1 identity shape is clean'
);
wprism_check_same([], cf7_readiness_diagnostics($interpreter, cf7_readiness_meta(true)), 'the still-readable legacy CF7 property shape is clean');
wprism_check_same(
    ['class' => 'authored', 'plain_data' => true, 'allow_pii' => true],
    $policy->meta_rule_for_post('mail', cf7_readiness_meta(true)),
    'legacy nested mail is authored through the recursive plain-data codec'
);
wprism_check_same(
    ['class' => 'authored'],
    $policy->meta_rule_for_post('form', cf7_readiness_meta(true)),
    'legacy form markup is authored text'
);
wprism_check_same(null, $policy->meta_rule_for_post('mail', ['mail' => ['not' => 'a CF7 owner']]), 'legacy generic meta is not claimed outside a CF7 form');

$cf7Gates = new CaptureSafetyGates('/fixture/repository');
$cf7Capture = new EntityMetaCapture(
    $policy,
    new Tokens('https://source.example.test', 'https://source.example.test/uploads'),
    static function (string $section, string $key, mixed $value, array $rule, string $context) use ($cf7Gates): void {
        $cf7Gates->guardSecret($section, $key, $value, $rule, $context);
        $cf7Gates->guardPersonalData($section, $key, $value, $rule, $context);
    },
    static function (): void {},
    static function (string $_finding): void {}
);
foreach (['_mail' => $current, 'mail' => cf7_readiness_meta(true)] as $mailKey => $ownerMeta) {
    [$storeMail, $capturedMail] = $cf7Capture->classifyValue(
        $mailKey,
        [serialize(cf7_readiness_mail())],
        $ownerMeta,
        'CF7 conformance form',
        'post_meta'
    );
    wprism_check($storeMail
        && ($capturedMail['recipient'] ?? null) === 'ops@example.test'
        && ($capturedMail['sender'] ?? null) === 'WPrism <ops@example.test>',
        "$mailKey crosses the generic entity-meta PII gate only through its exact reviewed mail-object rule");
}

foreach ([
    '_config_errors' => 'runtime',
    '_config_validation' => 'runtime',
    '_constant_contact' => 'env',
    '_flamingo' => 'runtime',
    '_sendinblue' => 'env',
] as $key => $class) {
    $meta = $current;
    $meta[$key] = ['target' => 17];
    wprism_check_same(
        ['class' => $class],
        $policy->meta_rule_for_post($key, $meta),
        "$key is explicitly $class rather than an unclassified capture blocker"
    );
}

// Dual storage is a repository-shape fault, refused by the compiler — which
// capture runs over its staged candidate before publishing — rather than by
// classification. Apply classifies a union no row holds: its locked context
// keeps the rows it is about to delete, so converging a target to the other
// generation meets both spellings once, and a classification refusal there
// was the CF7 conformance apply's failure
// (regress_contact_form_7_storage_generation_apply.php).
$dual = cf7_readiness_meta();
$dual['form'] = $dual['_form'];
wprism_check_same(
    ['class' => 'authored'],
    $policy->meta_rule_for_post('form', $dual),
    'dual current/legacy storage still classifies: the shape refusal belongs to repository compilation'
);
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $dual))), 'both current'),
    'dual current/legacy storage is rejected by repository compilation, which capture runs before publishing'
);

foreach (['form', 'mail', 'mail_2', 'messages', 'additional_settings'] as $name) {
    $missing = $current;
    unset($missing['_' . $name]);
    wprism_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $missing))), 'missing both'),
        "missing $name refuses repository compilation"
    );
}

foreach ([
    '' => '40- or 64-byte lowercase',
    str_repeat('A', 40) => '40- or 64-byte lowercase',
    str_repeat('a', 39) => '40- or 64-byte lowercase',
    str_repeat('a', 41) => '40- or 64-byte lowercase',
    str_repeat('a', 63) => '40- or 64-byte lowercase',
    str_repeat('a', 65) => '40- or 64-byte lowercase',
] as $hash => $expected) {
    $bad = $current;
    $bad['_hash'] = $hash;
    wprism_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), $expected),
        'malformed CF7 hash identity refuses'
    );
}

$duplicateHashTree = [
    cf7_readiness_entity($current),
    cf7_readiness_entity(cf7_readiness_meta(false, 'a', 40), '22222222-2222-4222-8222-222222222222'),
];
wprism_check(
    str_contains(
        implode(' | ', cf7_readiness_messages($interpreter->repository_diagnostics($duplicateHashTree))),
        'native lookup is ambiguous'
    ),
    'duplicate seven-byte hash prefixes refuse even when no page currently embeds the second form'
);

foreach (['EN_us', 'en-US', '', 'e_US'] as $locale) {
    $bad = $current;
    $bad['_locale'] = $locale;
    wprism_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), 'locale grammar'),
        "invalid locale '$locale' refuses"
    );
}

foreach (['_form', '_additional_settings'] as $key) {
    $bad = $current;
    $bad[$key] = ['not' => 'text'];
    wprism_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), 'must be a string'),
        "$key rejects a structured value"
    );
}

$mailList = $current;
$mailList['_mail'] = ['not-an-object'];
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailList))), 'must be an object'),
    'list-shaped mail refuses'
);
$mailMissing = $current;
unset($mailMissing['_mail']['recipient']);
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailMissing))), 'recipient must be a string'),
    'mail missing its recipient refuses'
);
$mailUnknown = $current;
$mailUnknown['_mail']['credential'] = 'not portable';
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailUnknown))), 'unknown field'),
    'unknown mail schema fields refuse'
);
$mailBoolean = $current;
$mailBoolean['_mail']['use_html'] = 2;
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailBoolean))), 'boolean or integer 0/1'),
    'out-of-domain mail flags refuse'
);

$messagesList = $current;
$messagesList['_messages'] = ['one', 'two'];
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $messagesList))), 'object of named strings'),
    'list-shaped messages refuse'
);
$messagesValue = $current;
$messagesValue['_messages']['mail_sent_ok'] = ['nested'];
wprism_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $messagesValue))), 'string values'),
    'nested message values refuse'
);

foreach (['0', '01', '10000000000', 19, ['19']] as $oldId) {
    $bad = $current;
    $bad['_old_cf7_unit_id'] = $oldId;
    wprism_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), 'canonical positive decimal'),
        'malformed legacy alternate identity refuses'
    );
}

$withoutOldId = $current;
unset($withoutOldId['_old_cf7_unit_id']);
wprism_check_same([], cf7_readiness_diagnostics($interpreter, $withoutOldId), 'forms without a legacy alternate remain valid');

$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'), true);
wprism_check_same(['min' => '6.0', 'max' => '6.2.0'], $manifest['version_range'], 'CF7 admits the official 6.0 header and only the audited 6.0.x/6.1.x release lines');
wprism_check_same(true, $manifest['post_meta']['_mail']['plain_data'], 'current mail uses recursive string-leaf rebinding');
wprism_check_same(true, $manifest['post_meta']['_mail_2']['plain_data'], 'secondary mail uses recursive string-leaf rebinding');
wprism_check_same(true, $manifest['post_meta']['_mail']['allow_pii'], 'current mail carries one exact reviewed PII exception');
wprism_check_same(true, $manifest['post_meta']['_mail_2']['allow_pii'], 'secondary mail carries one exact reviewed PII exception');
wprism_check_same(true, $manifest['post_meta']['_messages']['plain_data'], 'messages use recursive string-leaf rebinding');
wprism_check_same('hex-prefix', $manifest['shortcode_attrs']['contact-form-7'][0]['lookup']['codec'], 'modern CF7 shortcode declares its real hash-prefix identity');
wprism_check_same([40, 64], $manifest['shortcode_attrs']['contact-form-7'][0]['lookup']['stored_lengths'], 'modern CF7 shortcode admits exactly the native SHA-1 and SHA-256 storage widths');
wprism_check_same(true, $manifest['shortcode_attrs']['contact-form-7'][0]['required'], 'modern CF7 shortcode refuses mutable title-only fallback');
$versionMatrix = (string) file_get_contents(dirname(__DIR__) . '/certify/version-matrix.sh');
wprism_check(
    str_contains($versionMatrix, 'version_matrix_reset_after_delete()')
        && str_contains($versionMatrix, "option_name = 'wpcf7'"),
    'exact version-matrix resets delete the CF7 activation marker before each source and target case'
);
wprism_check(
    str_contains($versionMatrix, 'require_observed_nonempty "CF7 6.1.7 upgraded native form" "$UPGRADE_NATIVE"'),
    'exact in-place upgrade evidence refuses an empty native CF7 observation through a defined harness assertion'
);

wprism_check_summary('Contact Form 7 production readiness');
