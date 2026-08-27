<?php
declare(strict_types=1);

/** Closed offline matrix for CF7 6.x property, identity, and residue shapes. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/sandbox/tests/lib/check.php';
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 2) . '/package/runtime/interpreters/contact-form-7.php';

use Duo\Interpreters\ContactForm7;
use Duo\Policy;

/** @return array<string,mixed> */
function cf7_readiness_mail(bool $secondary = false): array {
    $mail = [
        'subject' => '[Duo] [your-subject]',
        'sender' => 'Duo <ops@example.test>',
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

$policy = Policy::load(null, ['contact-form-7']);
$interpreter = $policy->interpreters()['contact-form-7'];
duo_check($interpreter instanceof ContactForm7, 'the shipped manifest resolves its digest-bound CF7 interpreter');

$current = cf7_readiness_meta();
duo_check_same([], cf7_readiness_diagnostics($interpreter, $current), 'the exact current CF7 property shape is clean');
duo_check_same(
    [],
    cf7_readiness_diagnostics($interpreter, cf7_readiness_meta(false, 'b', 40)),
    'the exact CF7 6.0 SHA-1 identity shape is clean'
);
duo_check_same([], cf7_readiness_diagnostics($interpreter, cf7_readiness_meta(true)), 'the still-readable legacy CF7 property shape is clean');
duo_check_same(
    ['class' => 'authored', 'plain_data' => true],
    $policy->meta_rule_for_post('mail', cf7_readiness_meta(true)),
    'legacy nested mail is authored through the recursive plain-data codec'
);
duo_check_same(
    ['class' => 'authored'],
    $policy->meta_rule_for_post('form', cf7_readiness_meta(true)),
    'legacy form markup is authored text'
);
duo_check_same(null, $policy->meta_rule_for_post('mail', ['mail' => ['not' => 'a CF7 owner']]), 'legacy generic meta is not claimed outside a CF7 form');

foreach ([
    '_config_errors' => 'runtime',
    '_config_validation' => 'runtime',
    '_constant_contact' => 'env',
    '_flamingo' => 'runtime',
    '_sendinblue' => 'env',
] as $key => $class) {
    $meta = $current;
    $meta[$key] = ['target' => 17];
    duo_check_same(
        ['class' => $class],
        $policy->meta_rule_for_post($key, $meta),
        "$key is explicitly $class rather than an unclassified capture blocker"
    );
}

$dual = cf7_readiness_meta();
$dual['form'] = $dual['_form'];
duo_check_throws(
    static fn() => $policy->meta_rule_for_post('form', $dual),
    RuntimeException::class,
    'dual current/legacy storage refuses classification',
    'both'
);
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $dual))), 'both current'),
    'dual current/legacy storage is also rejected from a hand-edited repository'
);

foreach (['form', 'mail', 'mail_2', 'messages', 'additional_settings'] as $name) {
    $missing = $current;
    unset($missing['_' . $name]);
    duo_check(
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
    duo_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), $expected),
        'malformed CF7 hash identity refuses'
    );
}

$duplicateHashTree = [
    cf7_readiness_entity($current),
    cf7_readiness_entity(cf7_readiness_meta(false, 'a', 40), '22222222-2222-4222-8222-222222222222'),
];
duo_check(
    str_contains(
        implode(' | ', cf7_readiness_messages($interpreter->repository_diagnostics($duplicateHashTree))),
        'native lookup is ambiguous'
    ),
    'duplicate seven-byte hash prefixes refuse even when no page currently embeds the second form'
);

foreach (['EN_us', 'en-US', '', 'e_US'] as $locale) {
    $bad = $current;
    $bad['_locale'] = $locale;
    duo_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), 'locale grammar'),
        "invalid locale '$locale' refuses"
    );
}

foreach (['_form', '_additional_settings'] as $key) {
    $bad = $current;
    $bad[$key] = ['not' => 'text'];
    duo_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), 'must be a string'),
        "$key rejects a structured value"
    );
}

$mailList = $current;
$mailList['_mail'] = ['not-an-object'];
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailList))), 'must be an object'),
    'list-shaped mail refuses'
);
$mailMissing = $current;
unset($mailMissing['_mail']['recipient']);
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailMissing))), 'recipient must be a string'),
    'mail missing its recipient refuses'
);
$mailUnknown = $current;
$mailUnknown['_mail']['credential'] = 'not portable';
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailUnknown))), 'unknown field'),
    'unknown mail schema fields refuse'
);
$mailBoolean = $current;
$mailBoolean['_mail']['use_html'] = 2;
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $mailBoolean))), 'boolean or integer 0/1'),
    'out-of-domain mail flags refuse'
);

$messagesList = $current;
$messagesList['_messages'] = ['one', 'two'];
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $messagesList))), 'object of named strings'),
    'list-shaped messages refuse'
);
$messagesValue = $current;
$messagesValue['_messages']['mail_sent_ok'] = ['nested'];
duo_check(
    str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $messagesValue))), 'string values'),
    'nested message values refuse'
);

foreach (['0', '01', '10000000000', 19, ['19']] as $oldId) {
    $bad = $current;
    $bad['_old_cf7_unit_id'] = $oldId;
    duo_check(
        str_contains(implode(' | ', cf7_readiness_messages(cf7_readiness_diagnostics($interpreter, $bad))), 'canonical positive decimal'),
        'malformed legacy alternate identity refuses'
    );
}

$withoutOldId = $current;
unset($withoutOldId['_old_cf7_unit_id']);
duo_check_same([], cf7_readiness_diagnostics($interpreter, $withoutOldId), 'forms without a legacy alternate remain valid');

$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'), true);
duo_check_same(['min' => '6.0', 'max' => '6.2.0'], $manifest['version_range'], 'CF7 admits the official 6.0 header and only the audited 6.0.x/6.1.x release lines');
duo_check_same(true, $manifest['post_meta']['_mail']['plain_data'], 'current mail uses recursive string-leaf rebinding');
duo_check_same(true, $manifest['post_meta']['_mail_2']['plain_data'], 'secondary mail uses recursive string-leaf rebinding');
duo_check_same(true, $manifest['post_meta']['_messages']['plain_data'], 'messages use recursive string-leaf rebinding');
duo_check_same('hex-prefix', $manifest['shortcode_attrs']['contact-form-7'][0]['lookup']['codec'], 'modern CF7 shortcode declares its real hash-prefix identity');
duo_check_same([40, 64], $manifest['shortcode_attrs']['contact-form-7'][0]['lookup']['stored_lengths'], 'modern CF7 shortcode admits exactly the native SHA-1 and SHA-256 storage widths');
duo_check_same(true, $manifest['shortcode_attrs']['contact-form-7'][0]['required'], 'modern CF7 shortcode refuses mutable title-only fallback');
$versionMatrix = (string) file_get_contents($repoRoot . '/sandbox/tests/certify/certify_version_matrix.sh');
duo_check(
    str_contains($versionMatrix, "'wps-hide-login-target-runtime-probe',\n      'wpcf7'"),
    'exact version-matrix resets delete the CF7 activation marker before each source and target case'
);
duo_check(
    str_contains($versionMatrix, 'require_observed_nonempty "CF7 6.1.7 upgraded native form" "$UPGRADE_NATIVE"'),
    'exact in-place upgrade evidence refuses an empty native CF7 observation through a defined harness assertion'
);

duo_check_summary('Contact Form 7 production readiness');
