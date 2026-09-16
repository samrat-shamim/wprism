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
$gates->guardPersonalData("table 'pmpro_membership_levelmeta' key", 'confirmation_in_email', '1', []);
$gates->guardPersonalData('options', 'require_name_email', '1', []);
$gates->guardPersonalData('options', 'woocommerce_default_customer_address', 'base', []);
$gates->guardPersonalData('options', 'rank-math-options-general', ['content_ai_country' => 'all'], []);
check(true, 'boolean and enum controls borrowing contact-field nouns do not false-positive as personal data');

foreach ([false, true] as $visibility) {
    $gates->guardPersonalData('options', 'editor_metadata', ['blockVisibility' => ['viewport' => ['mobile' => $visibility]]], []);
    check(true, 'a strict boolean under the immediate viewport subject is a screen visibility flag');
}
foreach ([
    ['viewport' => ['mobile' => 'false']], ['viewport' => ['mobile' => 0]],
    ['viewport' => ['mobile' => '+14155552671']], ['viewport' => ['mobile' => 14155552671]],
    ['viewport' => ['mobile' => ['value' => false]]], ['billing' => ['mobile' => false]],
    ['viewport' => ['contact' => ['mobile' => false]]],
] as $contact) {
    $failure = refusal(static fn() => $gates->guardPersonalData('options', 'editor_metadata', $contact, []));
    check(($failure->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
        'viewport role handling does not clear wrong types, containers, contacts or remote descendants');
}
$screenEmail = refusal(static fn() => $gates->guardPersonalData('options', 'editor_metadata',
    ['viewport' => ['mobile' => false, 'caption' => 'person@example.test']], []));
check(($screenEmail->diagnostics[0]['personal_data_shape'] ?? null) === 'email address', 'screen visibility does not clear neighboring personal text');
$screenSecret = refusal(static fn() => $gates->guardSecret('options', 'editor_metadata',
    ['viewport' => ['mobile' => false, 'token' => 'sk_live_DIRECTBOUNDARY123456']], []));
check($screenSecret->reasonCode === 'secret_state_refused', 'screen visibility never clears independent secret checks');

$concreteCountry = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'rank-math-options-general',
    ['content_ai_country' => 'BD'],
    []
));
check(
    ($concreteCountry->diagnostics[0]['personal_data_shape'] ?? null) === 'postal address',
    'the Rank Math all-countries sentinel does not exempt a concrete country value'
);

$deliveryControlAddress = refusal(static fn() => $gates->guardPersonalData(
    "table 'fixture' key",
    'confirmation_in_email',
    'person@example.test',
    []
));
check(
    ($deliveryControlAddress->diagnostics[0]['personal_data_shape'] ?? null) === 'email address',
    'an address stored under a delivery-mode control remains protected by value scanning'
);

$addressControlText = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'woocommerce_default_customer_address',
    '100 Personal Street',
    []
));
check(
    ($addressControlText->diagnostics[0]['personal_data_shape'] ?? null) === 'postal address',
    'a non-enum value under an address control remains protected by key-role scanning'
);

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

$crossWindowPass = str_repeat('a', 32768) . str_repeat('B', 32768) . '7';
$crossWindowScalar = refusal(static fn() => $gates->guardSecret(
    'options',
    'pass',
    $crossWindowPass,
    []
));
check(
    $crossWindowScalar->reasonCode === 'secret_state_refused',
    'bare pass aggregates generated-shape evidence across the 65,537-byte window boundary'
);
$crossWindowContainer = refusal(static fn() => $gates->guardSecret(
    'options',
    'integration_settings',
    ['pass' => ['primary' => $crossWindowPass]],
    []
));
check(
    $crossWindowContainer->reasonCode === 'secret_state_refused',
    'a pass container carries cross-window generated-shape evidence to its scalar leaf'
);
$crossWindowProse = str_repeat('a', 32768) . str_repeat('B', 32767) . ' 7';
$gates->guardSecret('options', 'pass', $crossWindowProse, []);
check(true, 'cross-window bare-pass prose remains safe when any window contributes whitespace');

$credentialContainers = [
    'smtp-pass-container' => ['smtp_pass' => ['primary' => 'GeneratedValue-2026-Blocked']],
    'password-container' => ['password' => ['primary' => 'GeneratedValue-2026-Blocked']],
    'password-pass-child' => ['password' => ['pass' => 'Validation passed successfully']],
    'token-map-key' => ['token' => ['GeneratedValue-2026-Blocked' => 'enabled']],
];
$longCredentialMapKey = 'GeneratedValue-2026-Blocked' . str_repeat('x', 70000);
$credentialContainers['token-long-map-key'] = ['token' => [$longCredentialMapKey => 'enabled']];
foreach ($credentialContainers as $label => $credentialContainer) {
    $containerShape = refusal(static fn() => $gates->guardSecret(
        'options',
        'integration_settings',
        $credentialContainer,
        []
    ));
    check(
        $containerShape->reasonCode === 'secret_state_refused',
        "$label retains its enclosing credential role through associative containers"
    );
}

$deepCredential = 'GeneratedValue-2026-Blocked';
for ($depth = 0; $depth < 192; $depth++) {
    $deepCredential = ['layer_' . $depth => $deepCredential];
}
$deepContainerShape = refusal(static fn() => $gates->guardSecret(
    'options',
    'integration_settings',
    ['password' => $deepCredential],
    []
));
check(
    $deepContainerShape->reasonCode === 'secret_state_refused',
    'credential ancestry survives a bounded deep plain-data walk without branch expansion'
);

$gates->guardSecret('options', 'integration_settings', [
    'compass' => 'GeneratedValue-2026-Allowed',
    'bypass' => 'GeneratedValue-2026-Allowed',
    'pass_label' => 'GeneratedValue-2026-Allowed',
    'pass_endpoint' => 'https://login.example.test/password/reset',
    'pass' => 'Validation passed successfully',
    'password' => 'disabled',
], []);
check(true, 'pass aliases are terminal tokens and ordinary pass prose or a disabled value stays safe');

foreach (['smtp_pass', 'smtpPass', 'pass', 'passwd', 'password'] as $credentialLabel) {
    $labelledAlias = refusal(static fn() => $gates->guardSecret(
        'post_field',
        'body',
        "$credentialLabel=GeneratedValue-2026-Blocked",
        []
    ));
    check(
        $labelledAlias->reasonCode === 'secret_state_refused',
        "$credentialLabel is recognized as a terminal credential label in unstructured content"
    );
}
$gates->guardSecret(
    'post_field',
    'body',
    'compass=GeneratedValue-2026-Allowed; bypass=GeneratedValue-2026-Allowed; '
        . 'pass_label=GeneratedValue-2026-Allowed; pass_endpoint=https://login.example.test/password/reset',
    []
);
check(true, 'terminal labelled-pass matching does not widen to safe compound aliases');

foreach ([
    'firstName' => 'personal name',
    'replyToEmail' => 'email address',
    'postalCode' => 'postal address',
    'phoneNumber' => 'phone number',
    'billing[firstName]' => 'personal name',
    'billingState' => 'postal address',
    'business_address_state' => 'postal address',
    'customerAddressState' => 'postal address',
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
    'customerState' => 'active-and-verified',
    'business_state' => 'operating',
    'customer' => ['state' => 'active'],
    'business' => ['state' => 'registered'],
    'email' => '{admin_email}',
    'displayName' => '{all_fields}',
    'replyToEmail' => '{field_id="2"}',
], []);
check(
    true,
    'UI/workflow states stay technical even below remote store, tax, and shipping ancestors'
);

foreach ([
    'customer' => ['customer' => ['address' => ['state' => 'CA']]],
    'business' => ['business' => ['address' => ['state' => 'NY']]],
] as $subject => $addressValue) {
    $nestedSubjectAddress = refusal(static fn() => $gates->guardPersonalData(
        'options',
        'regional_settings',
        $addressValue,
        []
    ));
    check(
        ($nestedSubjectAddress->diagnostics[0]['personal_data_shape'] ?? null) === 'postal address',
        "$subject.address.state retains direct postal-address semantics"
    );
}

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

$contentPolicy = new WPrism\Policy();
$bodySecret = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture',
        'type' => 'page',
        'title' => 'Clearance',
        'excerpt' => '',
        'author' => null,
    ], 'Deployment note: api_key=MixedCredential-2026-Value'),
]], $contentPolicy));
check($bodySecret->reasonCode === 'secret_state_refused', 'labelled credential-shaped prose blocks before publication');

$bodyPii = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture',
        'type' => 'page',
        'title' => 'Clearance',
        'excerpt' => '',
        'author' => null,
    ], 'Private contact: person@example.test'),
]], $contentPolicy));
check($bodyPii->reasonCode === 'personal_data_refused', 'PII embedded in prose blocks before publication');

$longBodySecret = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--long-clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture-long',
        'type' => 'page',
        'title' => 'Long clearance',
        'excerpt' => '',
        'author' => null,
    ], str_repeat('ordinary text ', 6000) . ' token=LongCredential-2026-Blocked'),
]], $contentPolicy));
check($longBodySecret->reasonCode === 'secret_state_refused', 'long canonical prose is windowed instead of bypassing clearance');

$ipv6Pii = refusal(static fn() => $gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--ipv6-clearance.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture-ipv6',
        'type' => 'page',
        'title' => 'IPv6 clearance',
        'excerpt' => '',
        'author' => null,
    ], 'Private client address: 2001:db8:85a3::8a2e:370:7334'),
]], $contentPolicy));
check($ipv6Pii->reasonCode === 'personal_data_refused', 'IPv6 embedded in prose participates in PII clearance');

$gates->assertCanonicalContent([[
    'type' => 'page',
    'path' => 'posts/page/fixture--ordinary-date.md',
    'content' => WPrism\Canon::post_file([
        'uuid' => 'fixture-date',
        'type' => 'page',
        'title' => 'Release 2026-08-30',
        'excerpt' => '',
        'author' => null,
    ], 'Published on 2026-08-30 with build 1234567.'),
]], $contentPolicy);
check(true, 'ordinary dates and numeric build ids do not false-positive as phone numbers');

// The final guard must use the post's policy, not its path, body contents or
// an earlier entity's exception. No plugin identity participates in this rule.
$contentPolicy->manifests = [[
    'name' => 'body-clearance-fixture',
    'post_types' => [
        'reviewed_form' => ['class' => 'authored', 'body' => 'json'],
        'unreviewed_form' => ['class' => 'authored', 'body' => 'json'],
    ],
    'body_refs' => [
        'reviewed_form' => ['json_refs' => [], 'pii_paths' => ['$.settings.notifications.*.email']],
        'unreviewed_form' => ['json_refs' => []],
    ],
]];
$jsonEntity = static fn(string $body, array $front = []): array => [
    'type' => 'post',
    'path' => 'posts/reviewed_form/fixture--clearance.md',
    'content' => WPrism\Canon::post_file(array_replace([
        'uuid' => 'fixture', 'type' => 'reviewed_form', 'title' => 'Public form',
        'excerpt' => '', 'author' => null,
    ], $front), $body),
];
$reviewed = ['settings' => ['notifications' => [1 => ['email' => 'operations@example.test']]]];
$reviewedEntity = $jsonEntity(json_encode($reviewed, JSON_THROW_ON_ERROR));
$beforeReviewed = $reviewedEntity;
$gates->assertCanonicalContent([$reviewedEntity], $contentPolicy);
check($reviewedEntity === $beforeReviewed, 'publication clearance honors reviewed JSON scalar paths without changing canonical bytes');

foreach ([
    'unreviewed value' => [array_replace($reviewed, ['copy' => 'private@example.test']), []],
    'unreviewed field role' => [array_replace($reviewed, ['contact_email' => 'Not a literal address']), []],
    'matched container' => [['settings' => ['notifications' => [1 => ['email' => ['value' => 'private@example.test']]]]], []],
    'private associative key' => [['settings' => ['notifications' => ['private@example.test' => ['email' => 'operations@example.test']]]], []],
    'another JSON post type' => [$reviewed, ['type' => 'unreviewed_form']],
    'path claiming another type' => [$reviewed, ['type' => 'page']],
    'front title' => [$reviewed, ['title' => 'private@example.test']],
    'front excerpt' => [$reviewed, ['excerpt' => 'private@example.test']],
    'front author' => [$reviewed, ['author' => 'private@example.test']],
    'front alt' => [$reviewed, ['alt' => 'private@example.test']],
] as $case => [$document, $front]) {
    $hostileEntity = $jsonEntity(json_encode($document, JSON_THROW_ON_ERROR), $front);
    $hostile = refusal(static fn() => $gates->assertCanonicalContent([$reviewedEntity, $hostileEntity], $contentPolicy));
    check($hostile->reasonCode === 'personal_data_refused', "$case retains the public PII refusal after a reviewed JSON entity");
    check(!str_contains(json_encode($hostile->payload()), 'private@example.test'), "$case never echoes private bytes in the public refusal");
}
$secretEntity = $jsonEntity(json_encode([
    'settings' => ['notifications' => [1 => ['email' => 'sk_live_CLEARANCE123456789012']]],
], JSON_THROW_ON_ERROR));
$reviewedSecret = refusal(static fn() => $gates->assertCanonicalContent([$secretEntity], $contentPolicy));
check($reviewedSecret->reasonCode === 'secret_state_refused', 'an exact PII exception never clears secrets in that field');
check(!str_contains(json_encode($reviewedSecret->payload()), 'sk_live_'), 'reviewed-field secret bytes stay out of public diagnostics');

foreach ([
    ['type' => 'term', 'path' => 'terms/category/fixture.json', 'content' => WPrism\Canon::encode(['name' => 'private@example.test'])],
    ['type' => 'menu', 'path' => 'menus/fixture.json', 'content' => WPrism\Canon::encode(['name' => 'private@example.test', 'items' => []])],
    ['type' => 'user-meta', 'path' => 'user-meta/fixture.json', 'content' => WPrism\Canon::encode(['login' => 'private@example.test'])],
] as $otherEntity) {
    $other = refusal(static fn() => $gates->assertCanonicalContent([$reviewedEntity, $otherEntity], $contentPolicy));
    check($other->reasonCode === 'personal_data_refused', "{$otherEntity['type']} fields never inherit a preceding post body's privacy paths");
}
$malformedBodyFailure = null;
try {
    $gates->assertCanonicalContent([$jsonEntity('{"settings":')], $contentPolicy);
} catch (RuntimeException $failure) {
    $malformedBodyFailure = $failure;
}
check($malformedBodyFailure !== null && str_contains($malformedBodyFailure->getMessage(), 'not a JSON document'),
    'malformed JSON cannot fall back to raw framing and bypass declaration-aware clearance');

foreach ([
    'Completed at 2026-02-03 04:05:06 UTC',
    'Dated permalink https://example.test/2026/08/30/story',
    'Tokenized dated permalink {{home}}/2026/08/30/story',
    'Published 08/30/2026',
    'Published 30/08/2026',
    'Published 30.08.2026',
    'Published 30-08-2026',
    'Catalog identifier ISBN 978-1-4028-9462-6',
    'Support ticket ABC-123-4567',
    'Catalog SKU 123-456-789',
    'Compatible with release 10.2.3.4567',
    'Release calendar version=2026.08.30',
    'Localized audience count 1 234 567',
] as $technicalNumber) {
    $gates->guardPersonalData('options', 'release_metadata', $technicalNumber, []);
}
check(true, 'dated links, timestamps, identifiers, versions, and grouped counts do not false-positive as phone numbers');

foreach (['block-65138488', 'array_index_2-99999999', 'other_namespace-block-82128164', '.block-65138488 { opacity: 1; }'] as $integerState) {
    $gates->guardPersonalData('options', 'technical_state', $integerState, []);
    check(true, 'complete alphabetic identifier with one numeric suffix is ordinary state: ' . $integerState);
}
foreach (['-65138488', '+14155552671', '-415-555-2671', 'block-415-555-2671', 'billing-phone-14155552671',
    'telephone-14155552671', 'passport-123456789', 'block-65138488 Call +1 (415) 555-2671'] as $adjacentPhone) {
    $phoneFailure = refusal(static fn() => $gates->guardPersonalData('options', 'support_copy', $adjacentPhone, []));
    check(($phoneFailure->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
        'numeric identity handling preserves actual telephone punctuation: ' . $adjacentPhone);
}
$signedPhoneField = refusal(static fn() => $gates->guardPersonalData('options', 'phone_number', 'block-65138488', []));
check(($signedPhoneField->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
    'an identifier in a named phone field still requires reviewed privacy authority');

$svgEntity = static fn(string $body): array => ['type' => 'post', 'path' => 'posts/page/viewport.md',
    'content' => WPrism\Canon::post_file(['type' => 'page', 'title' => 'Vector drawing'], $body)];
foreach ([
    '<svg viewBox="0 0 16.2 15.2"></svg>',
    "<SVG VIEWBOX='-1, -2, 190.5, 148'></SVG>",
    '<svg viewBox="0 0 1.905e2 1.48e2"></svg>',
    '<svg viewBox="0 0 0 0"><svg viewBox="0 0 34.2 32.3"></svg></svg>',
    str_repeat('ordinary text ', 6000) . '<svg viewBox="0 0 34.2 32.3"></svg>',
] as $svg) {
    $gates->assertCanonicalContent([$svgEntity($svg)], $contentPolicy);
    check(true, 'final publication clearance recognizes only native SVG viewport coordinates as numeric state');
}
foreach ([
    'comment' => '<!-- <svg viewBox="0 0 16.2 15.2"> -->',
    'script text' => '<script>const example = `<svg viewBox="0 0 16.2 15.2">`;</script>',
    'textarea text' => '<textarea><svg viewBox="0 0 16.2 15.2"></textarea>',
    'other tag' => '<div viewBox="0 0 16.2 15.2"></div>',
    'MathML namespace' => '<math><mrow><svg viewBox="0 0 16.2 15.2"></svg></mrow></math>',
    'duplicate attribute' => '<svg viewBox="0 0 1 1" viewBox="0 0 16.2 15.2"></svg>',
    'incomplete tag' => '<svg viewBox="0 0 16.2 15.2"',
    'invalid width' => '<svg viewBox="0 0 -16.2 15.2"></svg>',
    'invalid coordinates' => '<svg viewBox="0 0 16.2 15.2 +14155552671"></svg>',
    'sibling attribute' => '<svg viewBox="0 0 16.2 15.2" data-contact="+14155552671"></svg>',
    'child prose' => '<svg viewBox="0 0 16.2 15.2"><text>Call +1 (415) 555-2671</text></svg>',
    'child email' => '<svg viewBox="0 0 16.2 15.2"><desc>private@example.test</desc></svg>',
] as $label => $svg) {
    $svgFailure = refusal(static fn() => $gates->assertCanonicalContent([$svgEntity($svg)], $contentPolicy));
    check($svgFailure->reasonCode === 'personal_data_refused', "$label gains no SVG numeric authority");
}
$svgSecret = refusal(static fn() => $gates->assertCanonicalContent([
    $svgEntity('<svg viewBox="0 0 16.2 15.2">api_key=MixedCredential-2026-Value</svg>'),
], $contentPolicy));
check($svgSecret->reasonCode === 'secret_state_refused', 'viewport recognition never bypasses the independent secret guard');

$boundaryUuid = '11111111-1111-4111-8111-000000000108';
foreach ([32750, 65510, 98300] as $boundaryOffset) {
    $boundaryBody = str_repeat('x', $boundaryOffset) . ' {{post:' . $boundaryUuid . '}}';
    $gates->assertCanonicalContent([$svgEntity($boundaryBody)], $contentPolicy);
    check(true, "a complete canonical identity stays numeric-safe across privacy window boundary $boundaryOffset");
}
$boundaryPhone = refusal(static fn() => $gates->assertCanonicalContent([
    $svgEntity($boundaryBody . ' Call +1 (415) 555-2671'),
], $contentPolicy));
check($boundaryPhone->reasonCode === 'personal_data_refused', 'window-spanning identity normalization never clears adjacent phone prose');
$uuidEmail = refusal(static fn() => $gates->guardPersonalData('options', 'public_copy', $boundaryUuid . '@example.test', []));
check(($uuidEmail->diagnostics[0]['personal_data_shape'] ?? null) === 'email address',
    'a UUID-shaped mailbox remains an email before technical phone normalization');

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

$ticketProsePhone = refusal(static fn() => $gates->guardPersonalData(
    'options',
    'support_copy',
    'Ticket support 415-555-2671',
    []
));
check(
    ($ticketProsePhone->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
    'ordinary prose after the word ticket cannot hide a real phone number'
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

foreach (['415/555/2671', '+1/415/555/2671'] as $slashPhoneValue) {
    $slashPhone = refusal(static fn() => $gates->guardPersonalData(
        'options',
        'support_copy',
        $slashPhoneValue,
        []
    ));
    check(
        ($slashPhone->diagnostics[0]['personal_data_shape'] ?? null) === 'phone number',
        "$slashPhoneValue remains a phone number after slash-date exemptions"
    );
}

echo "REGRESS_CAPTURE_SAFETY_GATES PASSED\n";
