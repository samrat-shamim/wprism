<?php
/**
 * Direct offline certification for DUO-3349's extracted user-meta capturer.
 *
 * Executes the shipped SQL/classification/canonicalization boundary without
 * loading Capture, Policy, Tokens, or WordPress. The fixture pins exact-login
 * ordering, carry-forward removal documents, sibling-meta interpreter context,
 * scalar/structured/ref/order-preserving dispatch, security callback order,
 * transient read refusal, and multi-value rejection.
 */
declare(strict_types=1);

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

require_once __DIR__ . '/../../agent/src/UserMetaCapture.php';

use Duo\UserMetaCapture;
use Duo\UserMetaState;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};
$throws = static function (callable $run, string $fragment, string $message) use ($check): void {
    try {
        $run();
        $check(false, $message);
    } catch (\Throwable $failure) {
        $check(str_contains($failure->getMessage(), $fragment), $message);
    }
};

$check(class_exists(UserMetaCapture::class, false), 'UserMetaCapture loads as a direct offline boundary');
foreach (['Duo\\Capture', 'Duo\\Policy', 'Duo\\Tokens'] as $runtimeClass) {
    $check(!class_exists($runtimeClass, false), "UserMetaCapture does not load $runtimeClass");
}

final class UserMetaCapturePolicyFixture {
    /** @var array<int,array{key:string,flat:array}> */
    public array $calls = [];

    public function meta_rule_for_user(string $key, array $flat): ?array {
        $this->calls[] = ['key' => $key, 'flat' => $flat];
        return match ($key) {
            'plain' => ['class' => 'authored'],
            'owner' => ['class' => 'authored', 'ref' => 'user'],
            'dangling' => ['class' => 'authored', 'ref' => 'user'],
            'structured' => ['class' => 'authored', 'json_refs' => [['path' => 'owner', 'kind' => 'user']]],
            'ordered' => ['class' => 'authored', 'order_preserving' => true],
            'secret_value' => ['class' => 'authored'],
            'contact_email' => ['class' => 'authored'],
            'duplicate' => ['class' => 'authored'],
            'context_marker' => ['class' => 'runtime'],
            'runtime_marker' => ['class' => 'runtime'],
            default => null,
        };
    }
}

final class UserMetaCaptureTokensFixture {
    /** @var string[] */
    public array $calls = [];
    private ?\Closure $record;

    public function __construct(?\Closure $record = null) {
        $this->record = $record;
    }

    private function record(string $event): void {
        $this->calls[] = $event;
        if ($this->record !== null) {
            ($this->record)("codec:$event");
        }
    }

    public function tokenize_text(string $value): string {
        $this->record("text:$value");
        return "tokenized:$value";
    }

    public function meta_value_to_tokens($value, array $_rule) {
        $this->record('ref:' . (string) $value);
        return (string) $value === '404' ? null : 'user:target-' . (string) $value;
    }

    public function struct_capture($value, array $_jsonRefs, ?array $_keyRefs) {
        $this->record('structured');
        return ['captured' => $value];
    }
}

final class UserMetaCaptureWpdbFixture {
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $sql = '';
    /** @var array<int,array<string,mixed>> */
    public array $rows;

    public function __construct(array $rows) {
        $this->rows = $rows;
    }

    public function get_results(string $sql, mixed $mode): array {
        if ($mode !== ARRAY_A) {
            throw new \RuntimeException('fixture expected ARRAY_A');
        }
        $this->sql = $sql;
        return $this->rows;
    }
}

$rows = [
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'context_marker', 'meta_value' => 'first-context'],
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'context_marker', 'meta_value' => 'second-context'],
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'plain', 'meta_value' => 'hello'],
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'owner', 'meta_value' => '17'],
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'dangling', 'meta_value' => '404'],
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'structured', 'meta_value' => 'a:1:{s:5:"owner";i:17;}'],
    ['user_id' => '9', 'user_login' => 'zeta', 'meta_key' => 'ordered', 'meta_value' => 'a:2:{s:1:"z";i:1;s:1:"a";i:2;}'],
    ['user_id' => '2', 'user_login' => 'alpha', 'meta_key' => null, 'meta_value' => null],
    ['user_id' => '4', 'user_login' => 'editor', 'meta_key' => 'plain', 'meta_value' => 'profile'],
    ['user_id' => '4', 'user_login' => 'editor', 'meta_key' => 'runtime_marker', 'meta_value' => 'local'],
    ['user_id' => '7', 'user_login' => 'runtime-only', 'meta_key' => 'runtime_marker', 'meta_value' => 'local'],
];
$policy = new UserMetaCapturePolicyFixture();
$eventTrace = [];
$tokens = new UserMetaCaptureTokensFixture(
    static function (string $event) use (&$eventTrace): void {
        $eventTrace[] = $event;
    }
);
$wpdb = new UserMetaCaptureWpdbFixture($rows);
$GLOBALS['wpdb'] = $wpdb;
$security = [];
$dbChecks = [];
$capture = new UserMetaCapture(
    $policy,
    $tokens,
    static function (string $section, string $key, $value, array $_rule, string $context) use (&$security, &$eventTrace): void {
        $security[] = "secret:$section:$key:$context:" . get_debug_type($value);
        $eventTrace[] = "secret:$key";
    },
    static function (string $key, $value, array $_rule, string $login) use (&$security, &$eventTrace): void {
        $security[] = "pii:$login:$key:" . get_debug_type($value);
        $eventTrace[] = "pii:$key";
    },
    static function (string $where) use (&$dbChecks): void {
        $dbChecks[] = $where;
    }
);

$entities = $capture->capture(['alpha', '', 'alpha']);
$normalizeSql = static fn(string $sql): string => preg_replace('/\s+/', ' ', trim($sql)) ?? '';
$check(
    $normalizeSql($wpdb->sql) === 'SELECT u.ID AS user_id, u.user_login, um.meta_key, um.meta_value FROM wp_users u LEFT JOIN wp_usermeta um ON um.user_id = u.ID ORDER BY u.ID ASC, um.umeta_id ASC',
    'one read preserves the exact user ownership join and deterministic SQL order'
);
$check($dbChecks === ['Capture::user_meta_maps()'], 'the transient DB checkpoint runs immediately after the user-meta read');
$check(
    array_map(static fn(array $entity): string => json_decode($entity['content'], true)['login'], $entities)
        === ['alpha', 'editor', 'zeta'],
    'entities sort by exact login and omit an uncarried runtime-only user'
);
$check(
    json_decode($entities[0]['content'], true) === ['login' => 'alpha', 'meta' => []],
    'a carried login emits an empty document for removal of its final authored key'
);
$editor = json_decode($entities[1]['content'], true);
$zeta = json_decode($entities[2]['content'], true);
$check($editor['meta'] === ['plain' => 'tokenized:profile'], 'plain authored user meta uses the shared text tokenizer');
$check(
    $zeta['meta']['owner'] === 'user:target-17'
        && !array_key_exists('dangling', $zeta['meta']),
    'portable refs are tokenized and dangling refs are omitted'
);
$check(
    $zeta['meta']['structured'] === ['captured' => ['owner' => 17]],
    'structured user meta is safely decoded before reference capture'
);
$check(
    strpos($entities[2]['content'], '"z": 1') < strpos($entities[2]['content'], '"a": 2'),
    'order-preserving authored values retain insertion order in canonical bytes'
);
$check(
    $entities[2]['uuid'] === UserMetaState::key('zeta')
        && $entities[2]['path'] === UserMetaState::path('zeta')
        && $entities[2]['type'] === 'user-meta',
    'entity identity remains login-keyed and never becomes a user UUID'
);
$zetaContext = array_values(array_filter(
    $policy->calls,
    static fn(array $call): bool => $call['key'] === 'plain'
        && ($call['flat']['context_marker'] ?? null) === 'first-context'
        && ($call['flat']['owner'] ?? null) === '17'
));
$check(count($zetaContext) === 1, 'every policy lookup receives the first-value whole-user sibling-meta context');
$check(
    count(array_filter($security, static fn(string $event): bool => str_starts_with($event, 'secret:'))) === 6
        && count(array_filter($security, static fn(string $event): bool => str_starts_with($event, 'pii:'))) === 6,
    'both security gates run for every authored value before portable encoding, including arrays'
);
$check(
    $eventTrace === [
        'secret:plain', 'pii:plain', 'codec:text:profile',
        'secret:plain', 'pii:plain', 'codec:text:hello',
        'secret:owner', 'pii:owner', 'codec:ref:17',
        'secret:dangling', 'pii:dangling', 'codec:ref:404',
        'secret:structured', 'pii:structured', 'codec:structured',
        'secret:ordered', 'pii:ordered',
    ],
    'security gates run secret then PII before every reachable codec operation'
);

$securityRows = static function (string $key, string $value): array {
    return [['user_id' => '1', 'user_login' => 'guarded', 'meta_key' => $key, 'meta_value' => $value]];
};
$GLOBALS['wpdb'] = new UserMetaCaptureWpdbFixture($securityRows('secret_value', 'secret'));
$secretTokens = new UserMetaCaptureTokensFixture();
$secretCapture = new UserMetaCapture(
    new UserMetaCapturePolicyFixture(),
    $secretTokens,
    static function (): void { throw new \RuntimeException('fixture secret refusal'); },
    static function (): void { throw new \RuntimeException('PII ran after secret refusal'); },
    static function (): void {}
);
$throws(static fn() => $secretCapture->capture([]), 'fixture secret refusal', 'secret refusal stops before personal-data and encoding work');
$check($secretTokens->calls === [], 'secret refusal performs no codec work');

$GLOBALS['wpdb'] = new UserMetaCaptureWpdbFixture($securityRows('contact_email', 'person@example.test'));
$piiTokens = new UserMetaCaptureTokensFixture();
$piiCapture = new UserMetaCapture(
    new UserMetaCapturePolicyFixture(),
    $piiTokens,
    static function (): void {},
    static function (): void { throw new \RuntimeException('fixture PII refusal'); },
    static function (): void {}
);
$throws(static fn() => $piiCapture->capture([]), 'fixture PII refusal', 'personal-data refusal stops authored user-meta publication');
$check($piiTokens->calls === [], 'personal-data refusal performs no codec work');

$GLOBALS['wpdb'] = new UserMetaCaptureWpdbFixture([
    ['user_id' => '1', 'user_login' => 'duplicate-user', 'meta_key' => 'duplicate', 'meta_value' => 'first'],
    ['user_id' => '1', 'user_login' => 'duplicate-user', 'meta_key' => 'duplicate', 'meta_value' => 'second'],
]);
$duplicateCapture = new UserMetaCapture(
    new UserMetaCapturePolicyFixture(),
    new UserMetaCaptureTokensFixture(),
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
$throws(
    static fn() => $duplicateCapture->capture([]),
    "multi-value authored user meta 'duplicate' on exact login 'duplicate-user'",
    'multi-value authored user meta still refuses instead of choosing a row'
);

$GLOBALS['wpdb'] = new UserMetaCaptureWpdbFixture($rows);
$readFailure = new UserMetaCapture(
    new UserMetaCapturePolicyFixture(),
    new UserMetaCaptureTokensFixture(),
    static function (): void {},
    static function (): void {},
    static function (): void { throw new \RuntimeException('fixture transient read refusal'); }
);
$throws(
    static fn() => $readFailure->capture([]),
    'fixture transient read refusal',
    'a transient DB checkpoint aborts before classifying any returned row'
);

$captureSource = file_get_contents(__DIR__ . '/../../agent/src/Capture.php');
$candidateSource = file_get_contents(__DIR__ . '/../../agent/src/CaptureCandidateBuilder.php');
$check(
    is_string($candidateSource) && str_contains($candidateSource, "require_once __DIR__ . '/UserMetaCapture.php';"),
    'candidate builder explicitly requires its extracted user-meta collaborator'
);
$check(
    is_string($candidateSource)
        && str_contains($candidateSource, 'new UserMetaCapture(')
        && str_contains($candidateSource, '$this->safetyGates->guardSecret($section, $key, $value, $rule, $context);')
        && str_contains($candidateSource, '$this->safetyGates->guardPersonalData($key, $value, $rule, $login);')
        && str_contains($candidateSource, 'CaptureTransaction::check_transient_db_error($where);')
        && str_contains($candidateSource, '$this->userMetaCapture->capture($carriedUserLogins)'),
    'candidate builder binds exact secret, personal-data, and transient-read callbacks'
);
$check(
    is_string($captureSource)
        && !str_contains($captureSource, 'private function user_meta_maps()')
        && !str_contains($captureSource, 'private function classify_user_meta_value('),
    'Capture no longer owns duplicate user SQL or user-meta classification implementations'
);

if ($failures !== []) {
    fwrite(STDERR, "\nREGRESS_USER_META_CAPTURE FAILED: " . count($failures) . " check(s)\n");
    exit(1);
}
fwrite(STDOUT, "\nREGRESS_USER_META_CAPTURE PASSED\n");
