<?php
declare(strict_types=1);

require __DIR__ . '/../../../../agent/src/Capture/CaptureSafetyGates.php';

use Duo\CaptureSafetyGates;
use Duo\CommandRefusalException;
use Duo\Tokens;

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
check(!class_exists(Duo\Capture::class, false), 'safety gates do not load Capture');

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
// DUO-3510: the printed remedy must be the `=` form -- wp-cli parses a
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

$pii = refusal(static fn() => $gates->guardPersonalData('contact_email', 'person@example.test', [], 'operator'));
check($pii->reasonCode === 'personal_data_refused', 'PII refusal is owned by the safety boundary');
check(($pii->diagnostics[0]['personal_data_shape'] ?? null) === 'email address', 'PII refusal keeps the conservative shape label');

$gates->guardSecret('options', 'gateway', ['key' => 'sk_live_DIRECTBOUNDARY123456'], ['allow_secret' => true]);
$gates->guardPersonalData('contact_email', 'person@example.test', ['allow_pii' => true], 'operator');
check(true, 'explicit reviewed security exceptions still short-circuit');

echo "REGRESS_CAPTURE_SAFETY_GATES PASSED\n";
