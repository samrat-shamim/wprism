<?php
declare(strict_types=1);

require __DIR__ . '/../../../../agent/src/Capture/CaptureSafetyGates.php';

use WPrism\CaptureSafetyGates;
use WPrism\CommandRefusalException;
use WPrism\Tokens;

function check(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: $message\n");
        exit(1);
    }
    echo "ok: $message\n";
}

function refusal(callable $operation): CommandRefusalException {
    try {
        $operation();
    } catch (CommandRefusalException $failure) {
        return $failure;
    }
    throw new RuntimeException('expected a capture safety refusal');
}

$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$gates = new CaptureSafetyGates('/private/repository/');

check(class_exists(CaptureSafetyGates::class, false), 'safety gates load as a direct boundary');
check(!class_exists(WPrism\Capture::class, false), 'safety gates do not load Capture');

$unclassified = refusal(static fn() => $gates->assertOptions(['options:unknown'], [], [], $tokens));
check($unclassified->reasonCode === 'incomplete_state_discovery', 'unclassified state keeps its stable reason');
check(($unclassified->diagnostics[0]['surface'] ?? null) === 'options:unknown', 'unclassified state keeps exact surface evidence');

$optionRef = refusal(static fn() => $gates->assertOptions([], [[
    'option' => 'target',
    'kind' => 'post',
    'id' => 17,
    'target_type' => 'landing',
]], [], $tokens));
check($optionRef->reasonCode === 'unresolved_reference_scope', 'option reference keeps its stable reason');
check(($optionRef->diagnostics[0]['code'] ?? null) === 'unresolved_option_reference_scope', 'option reference keeps its diagnostic code');

$scope = refusal(static fn() => $gates->assertScopeGaps(['post_type:landing' => ['entities' => 2]]));
check($scope->reasonCode === 'incomplete_policy_scope', 'scope gaps keep their stable reason');
check(($scope->diagnostics[0]['entity_count'] ?? null) === 2, 'scope gaps keep entity evidence');

$tokens->unscopedBlockRefs = [[
    'post' => "page 'private'",
    'block' => 'core/image',
    'attr' => 'id',
    'kind' => 'post',
    'id' => 23,
    'target_type' => 'landing',
]];
$contentRef = refusal(static fn() => $gates->assertContentReferences($tokens));
check(($contentRef->diagnostics[0]['code'] ?? null) === 'unresolved_block_reference_scope', 'content references keep their diagnostic code');

$secret = refusal(static fn() => $gates->guardSecret(
    'options',
    'gateway',
    ['key' => 'sk_live_DIRECTBOUNDARY123456'],
    []
));
check($secret->reasonCode === 'secret_state_refused', 'deep secret refusal is owned by the safety boundary');
check(str_contains($secret->getMessage(), '--repo=/private/repository'), 'secret remediation uses the normalized repository path');
check(!str_contains((string) json_encode($secret->payload()), 'sk_live_'), 'secret bytes are absent from public diagnostics');
// issue #3510: the printed remedy must be the `=` form -- wp-cli parses a
// space-separated --set value as a bare boolean flag and silently drops the
// intended value (measured against this exact command, Cli.php:2335-2343).
// The 'options' section additionally needs autoload=preserve or the pasted
// command trades one refusal (OptionGrammar.php:89-93) for another.
check(
    str_contains($secret->getMessage(), "--set='options:gateway=authored,autoload=preserve' --allow-secret"),
    'secret remediation for an options surface uses the working = form with autoload=preserve'
);
check(!str_contains($secret->getMessage(), "--set 'options:gateway=authored'"), 'secret remediation no longer prints the broken space form');

$secretPostMeta = refusal(static fn() => $gates->guardSecret(
    'post_meta',
    '_payment_config',
    ['token' => 'sk_live_DIRECTBOUNDARY123456'],
    []
));
check(
    str_contains($secretPostMeta->getMessage(), "--set='post_meta:_payment_config=authored' --allow-secret"),
    'secret remediation for a non-options surface uses the = form without an autoload suffix'
);

$pii = refusal(static fn() => $gates->guardPersonalData(
    'post_meta',
    'contact_email',
    'person@example.test',
    [],
    " on post 'fixture'"
));
check($pii->reasonCode === 'personal_data_refused', 'PII refusal is owned by the safety boundary');
check(($pii->diagnostics[0]['personal_data_shape'] ?? null) === 'email address', 'PII refusal keeps the conservative shape label');
check(
    str_contains($pii->getMessage(), "--set='post_meta:contact_email=authored' --allow-pii"),
    'PII refusal prints the exact reviewed-exception command'
);

$gates->guardSecret('options', 'gateway', ['key' => 'sk_live_DIRECTBOUNDARY123456'], ['allow_secret' => true]);
$gates->guardPersonalData('options', 'contact_email', 'person@example.test', ['allow_pii' => true]);
check(true, 'explicit reviewed security exceptions still short-circuit');

$gates->guardPersonalData('post_meta', '_regular_price', '2147484004.123456', []);
check(true, 'arbitrary-precision decimal prices do not false-positive as phone numbers');

$gates->guardPersonalData('options', 'email_type', 'html', []);
$gates->guardPersonalData('options', 'mail_settings', ['email_type' => 'multipart'], []);
$gates->guardPersonalData('options', 'woocommerce_email_footer_text', 'Thanks for shopping', []);
$gates->guardPersonalData('options', 'woocommerce_checkout_phone_field', 'optional', []);
$gates->guardPersonalData('options', 'woocommerce_shipping_cost_requires_address', 'yes', []);
check(true, 'email rendering and checkout address/phone controls do not false-positive as personal data');

$nestedAddress = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'pickup_locations',
    [['address_1' => '100 Agency Way']],
    []
));
check(
    ($nestedAddress->diagnostics[0]['personal_data_shape'] ?? null) === 'postal address',
    'terminal nested address fields remain protected after technical-key exclusions'
);

$numericPhoneField = refusal(static fn() => $gates->guardPersonalData(
    'post_meta',
    'billing_phone',
    '2147484004.123456',
    []
));
check(
    ($numericPhoneField->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
    'phone-key classification still takes precedence over numeric value grammar'
);

$suspicious = refusal(static fn() => $gates->guardSecret(
    'options',
    'private_api_token',
    'GeneratedValue-2026-Agency',
    []
));
check($suspicious->reasonCode === 'secret_state_refused', 'credential-shaped authored values now block instead of only flagging pending');

$nestedSuspicious = refusal(static fn() => $gates->guardSecret(
    'options',
    'gateway_settings',
    ['nested' => ['private_api_token' => 'GeneratedValue-2026-Nested']],
    []
));
check($nestedSuspicious->reasonCode === 'secret_state_refused', 'nested credential keys participate in blocking clearance');

foreach (['Authorization', 'credential', 'license_key'] as $credentialKey) {
    $keyShape = refusal(static fn() => $gates->guardSecret(
        'options',
        'integration_settings',
        [$credentialKey => 'GeneratedValue-2026-Blocked'],
        []
    ));
    check(
        $keyShape->reasonCode === 'secret_state_refused',
        "$credentialKey is an explicit credential-key shape in recursive clearance"
    );
}

foreach (['smtp_pass', 'smtpPass', 'pass'] as $credentialKey) {
    $aliasShape = refusal(static fn() => $gates->guardSecret(
        'options',
        'integration_settings',
        [$credentialKey => 'GeneratedValue-2026-Blocked'],
        []
    ));
    check(
        $aliasShape->reasonCode === 'secret_state_refused',
        "$credentialKey is an exact terminal credential alias"
    );
}

$gates->guardSecret('options', 'integration_settings', [
    'compass' => 'GeneratedValue-2026-Allowed',
    'bypass' => 'GeneratedValue-2026-Allowed',
    'pass_label' => 'GeneratedValue-2026-Allowed',
    'pass_endpoint' => 'https://login.example.test/password/reset',
    'password' => 'disabled',
], []);
check(true, 'pass aliases are terminal tokens and a bare alias is not itself a credential value');

foreach ([
    'firstName' => 'personal name',
    'replyToEmail' => 'email address',
    'postalCode' => 'postal address',
    'phoneNumber' => 'phone number',
    'billing[firstName]' => 'personal name',
    'billingState' => 'postal address',
    'business_state' => 'postal address',
    'customerState' => 'postal address',
    'taxState' => 'postal address',
    '_VenueState' => 'postal address',
    'address[state]' => 'postal address',
] as $key => $shape) {
    $camelCasePii = refusal(static fn() => $gates->guardPersonalData(
        'options',
        'structured_settings',
        [$key => 'Configured Value'],
        []
    ));
    check(
        ($camelCasePii->diagnostics[0]['personal_data_shape'] ?? null) === $shape,
        "$key receives the same terminal personal-data classification as its snake_case spelling"
    );
}
$gates->guardPersonalData('options', 'technical_settings', [
    'emailType' => 'multipart',
    'checkoutPhoneField' => 'optional',
    'defaultCustomerAddress' => 'base',
    'editorState' => ['dirty' => false],
    'workflowState' => 'draft',
    'uiState' => 'open',
    'checkoutState' => 'ready',
    'store_settings' => ['uiState' => 'open'],
    'tax_settings' => ['workflow' => ['state' => 'draft']],
    'shipping' => ['checkoutState' => 'ready'],
    'email' => '{admin_email}',
    'displayName' => '{all_fields}',
    'replyToEmail' => '{field_id="2"}',
], []);
check(
    true,
    'UI/workflow states stay technical even below remote store, tax, and shipping ancestors'
);

$nestedAddressState = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'regional_settings',
    ['billing' => ['state' => 'CA']],
    []
));
check(
    ($nestedAddressState->diagnostics[0]['personal_data_shape'] ?? null) === 'postal address',
    'a nested state leaf inherits the closed billing-address context'
);

$nestedVenueState = refusal(static fn() => $gates->guardPersonalData(
    'post_meta',
    '_VenueDetails',
    ['venue' => ['state' => 'CA']],
    []
));
check(
    ($nestedVenueState->diagnostics[0]['personal_data_shape'] ?? null) === 'postal address',
    'a bare state leaf inherits only its direct venue-address subject'
);

$secretMapKey = refusal(static fn() => $gates->guardSecret(
    'options',
    'integration_settings',
    ['sk_live_ASSOCIATIVEKEY1234567890' => 'enabled'],
    []
));
check($secretMapKey->reasonCode === 'secret_state_refused', 'a hard secret in an associative key cannot bypass capture');

$piiMapKey = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'audience_map',
    ['alice@example.test' => 'enabled'],
    []
));
check($piiMapKey->reasonCode === 'personal_data_refused', 'an email in an associative key cannot bypass capture');

foreach ([
    'firstName' => '{Alice Smith}',
    'businessAddress' => '{123 Main Street}',
    'customerFirstName' => '{{alice_smith}}',
] as $literalKey => $literalValue) {
    $braceLiteral = refusal(static fn() => $gates->guardPersonalData(
        'options',
        'structured_settings',
        [$literalKey => $literalValue],
        []
    ));
    check(
        $braceLiteral->reasonCode === 'personal_data_refused',
        "$literalValue is literal personal data, not a smart-tag clearance exemption"
    );
}

$gates->guardSecret('options', 'integration_settings', [
    'authorization_endpoint' => 'https://login.example.test/oauth2/authorize',
    'credential_label' => 'Enter the credential on the settings page',
], []);
check(true, 'authorization endpoints and credential help labels are not credential fields');

$bodySecret = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture',
        'title' => 'Clearance',
        'excerpt' => '',
        'author' => null,
    ], 'Deployment note: api_key=MixedCredential-2026-Value'),
]]));
check($bodySecret->reasonCode === 'secret_state_refused', 'labelled credential-shaped prose blocks before publication');

$bodyPii = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture',
        'title' => 'Clearance',
        'excerpt' => '',
        'author' => null,
    ], 'Private contact: person@example.test'),
]]));
check($bodyPii->reasonCode === 'personal_data_refused', 'PII embedded in prose blocks before publication');

$longBodySecret = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--long-clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture-long',
        'title' => 'Long clearance',
        'excerpt' => '',
        'author' => null,
    ], str_repeat('ordinary text ', 6000) . ' token=LongCredential-2026-Blocked'),
]]));
check($longBodySecret->reasonCode === 'secret_state_refused', 'long canonical prose is windowed instead of bypassing clearance');

$ipv6Pii = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--ipv6-clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture-ipv6',
        'title' => 'IPv6 clearance',
        'excerpt' => '',
        'author' => null,
    ], 'Private client address: 2001:db8:85a3::8a2e:370:7334'),
]]));
check($ipv6Pii->reasonCode === 'personal_data_refused', 'IPv6 embedded in prose participates in PII clearance');

$gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--ordinary-date.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture-date',
        'title' => 'Release 2026-08-30',
        'excerpt' => '',
        'author' => null,
    ], 'Published on 2026-08-30 with build 1234567.'),
]]);
check(true, 'ordinary dates and numeric build ids do not false-positive as phone numbers');

foreach ([
    'Completed at 2026-02-03 04:05:06 UTC',
    'Catalog identifier ISBN 978-1-4028-9462-6',
    'Compatible with release 10.2.3.4567',
] as $technicalNumber) {
    $gates->guardPersonalData('options', 'release_metadata', $technicalNumber, []);
}
check(true, 'timestamps, ISBNs, and dotted release versions do not false-positive as phone numbers');

$actualPhone = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'support_copy',
    'Call our private contact at +1 (415) 555-2671.',
    []
));
check(
    ($actualPhone->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
    'a punctuated international telephone number remains protected'
);

foreach (['+1.415.555.2671', '+44.20.7946.0958'] as $dottedPhone) {
    $dottedPhoneRefusal = refusal(static fn() => $gates->guardPersonalData(
        'options',
        'support_copy',
        $dottedPhone,
        []
    ));
    check(
        ($dottedPhoneRefusal->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
        "$dottedPhone is a telephone number rather than a release-version exemption"
    );
}

echo "REGRESS_CAPTURE_SAFETY_GATES PASSED\n";
