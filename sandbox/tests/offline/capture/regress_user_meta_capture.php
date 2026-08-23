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

require_once __DIR__ . '/../../../../agent/src/Capture/UserMetaCapture.php';

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
    public string $last_error = '';
    public string $sql = '';
    /** @var list<string> */
    public array $queries = [];
    public mixed $forcedResult = null;
    public ?string $forcedPattern = null;
    public bool $forcedError = false;
    public bool $hasOrphan = false;
    /** @var array<int,array{user_id:string,user_login:mixed}> */
    private array $userRows = [];
    /** @var list<array{meta_id:string,user_id:string,meta_key:mixed,meta_value:mixed}> */
    private array $metaRows = [];

    public function __construct(array $rows) {
        $metaId = 0;
        foreach ($rows as $row) {
            $id = (string) ($row['user_id'] ?? '');
            $this->userRows[$id] = ['user_id' => $id, 'user_login' => $row['user_login'] ?? null];
            if (($row['meta_key'] ?? null) === null) continue;
            $this->metaRows[] = [
                'meta_id' => (string) (++$metaId),
                'user_id' => $id,
                'meta_key' => $row['meta_key'],
                'meta_value' => $row['meta_value'] ?? null,
            ];
        }
    }

    public function prepare(string $sql, ...$args): string {
        foreach ($args as $arg) {
            $sql = preg_replace('/%d/', (string) $arg, $sql, 1);
        }
        return $sql;
    }

    public function get_results(string $sql, mixed $mode): mixed {
        if ($mode !== ARRAY_A) {
            throw new \RuntimeException('fixture expected ARRAY_A');
        }
        $this->sql = $sql;
        $this->queries[] = $sql;
        if ($this->forcedPattern !== null && str_contains($sql, $this->forcedPattern)) {
            if ($this->forcedError) $this->last_error = 'simulated user-meta read error';
            return $this->forcedResult;
        }
        if (str_contains($sql, 'WHERE u.ID IS NULL')) {
            return $this->hasOrphan ? [['meta_id' => '999']] : [];
        }
        if (str_contains($sql, 'INNER JOIN wp_users b')) {
            $rows = array_values($this->userRows);
            foreach ($rows as $left) {
                foreach ($rows as $right) {
                    if ((int) $right['user_id'] > (int) $left['user_id']
                        && strcasecmp((string) $right['user_login'], (string) $left['user_login']) === 0) {
                        return [['left_id' => $left['user_id'], 'right_id' => $right['user_id']]];
                    }
                }
            }
            return [];
        }
        if (str_contains($sql, 'OCTET_LENGTH(user_login)')) {
            preg_match('/WHERE ID > ([0-9]+)/', $sql, $match);
            $after = (int) ($match[1] ?? 0);
            $rows = array_values(array_filter($this->userRows,
                static fn(array $row): bool => (int) $row['user_id'] > $after));
            usort($rows, static fn(array $a, array $b): int => (int) $a['user_id'] <=> (int) $b['user_id']);
            return array_map(static fn(array $row): array => [
                'user_id' => $row['user_id'],
                'user_login_bytes' => is_string($row['user_login'])
                    ? (string) strlen($row['user_login'])
                    : null,
                'user_login_sha256' => is_string($row['user_login'])
                    ? hash('sha256', $row['user_login'])
                    : null,
            ], array_slice($rows, 0, 501));
        }
        $ids = $this->ids($sql);
        if (str_contains($sql, 'SELECT ID AS user_id, user_login')) {
            $rows = array_values(array_filter($this->userRows,
                static fn(array $row): bool => in_array((int) $row['user_id'], $ids, true)));
            usort($rows, static fn(array $a, array $b): int => (int) $a['user_id'] <=> (int) $b['user_id']);
            return $rows;
        }
        $rows = array_values(array_filter($this->metaRows,
            static fn(array $row): bool => in_array((int) $row['user_id'], $ids, true)));
        usort($rows, static fn(array $a, array $b): int =>
            [(int) $a['user_id'], (int) $a['meta_id']] <=> [(int) $b['user_id'], (int) $b['meta_id']]);
        if (str_contains($sql, 'OCTET_LENGTH(meta_key)')) {
            return array_map(static fn(array $row): array => [
                'meta_id' => $row['meta_id'],
                'user_id' => $row['user_id'],
                'meta_key_bytes' => is_string($row['meta_key']) ? (string) strlen($row['meta_key']) : null,
                'meta_value_bytes' => is_string($row['meta_value']) ? (string) strlen($row['meta_value']) : null,
                'meta_key_sha256' => is_string($row['meta_key']) ? hash('sha256', $row['meta_key']) : null,
                'meta_value_sha256' => is_string($row['meta_value']) ? hash('sha256', $row['meta_value']) : null,
            ], $rows);
        }
        return $rows;
    }

    /** @return list<int> */
    private function ids(string $sql): array {
        if (preg_match('/WHERE (?:ID|user_id) IN \(([^)]+)\)/', $sql, $match) !== 1) return [];
        return array_map('intval', explode(',', $match[1]));
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
$check(
    count($wpdb->queries) === 6
        && str_contains($wpdb->queries[0], 'WHERE u.ID IS NULL')
        && str_contains($wpdb->queries[1], 'INNER JOIN wp_users b')
        && str_contains($wpdb->queries[2], 'OCTET_LENGTH(user_login)')
        && str_contains($wpdb->queries[4], 'OCTET_LENGTH(meta_key)')
        && str_contains($wpdb->queries[5], 'meta_key, meta_value'),
    'capture streams one bounded user chunk through compact user/meta preflights before full values'
);
$check($dbChecks === [
    'Capture::user_meta_orphan_check()',
    'Capture::user_meta_login_ambiguity_check()',
    'Capture::user_meta_users_size_preflight()',
    'Capture::user_meta_users_value_read()',
    'Capture::user_meta_size_preflight()',
    'Capture::user_meta_value_read()',
], 'the transient DB checkpoint runs immediately after every bounded user-meta read');
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

$captureForWpdb = static function (UserMetaCaptureWpdbFixture $fixture): UserMetaCapture {
    $GLOBALS['wpdb'] = $fixture;
    return new UserMetaCapture(
        new UserMetaCapturePolicyFixture(),
        new UserMetaCaptureTokensFixture(),
        static function (): void {},
        static function (): void {},
        static function (): void {}
    );
};

$orphanWpdb = new UserMetaCaptureWpdbFixture($rows);
$orphanWpdb->hasOrphan = true;
$throws(
    static fn() => $captureForWpdb($orphanWpdb)->capture([]),
    'metadata without an exact user owner',
    'orphan usermeta refuses instead of disappearing from a login-keyed source projection'
);

$aliasWpdb = new UserMetaCaptureWpdbFixture([
    ['user_id' => '1', 'user_login' => 'Editor', 'meta_key' => 'plain', 'meta_value' => 'one'],
    ['user_id' => '2', 'user_login' => 'editor', 'meta_key' => 'plain', 'meta_value' => 'two'],
]);
$throws(
    static fn() => $captureForWpdb($aliasWpdb)->capture([]),
    'collation-equal duplicate login identities',
    'source collation aliases refuse before one exact login can overwrite another'
);

$falseReadWpdb = new UserMetaCaptureWpdbFixture($rows);
$falseReadWpdb->forcedPattern = 'WHERE u.ID IS NULL';
$falseReadWpdb->forcedResult = false;
$throws(
    static fn() => $captureForWpdb($falseReadWpdb)->capture([]),
    'failed or returned a malformed row list',
    'false DB results never become an empty user-meta source'
);

$malformedUserWpdb = new UserMetaCaptureWpdbFixture($rows);
$malformedUserWpdb->forcedPattern = 'OCTET_LENGTH(user_login)';
$malformedUserWpdb->forcedResult = [['user_id' => '01', 'user_login_bytes' => '5']];
$throws(
    static fn() => $captureForWpdb($malformedUserWpdb)->capture([]),
    'user size preflight returned a malformed row',
    'noncanonical source user identities refuse before login bytes are transferred'
);

$oversizedMetaWpdb = new UserMetaCaptureWpdbFixture([
    ['user_id' => '1', 'user_login' => 'bounded', 'meta_key' => 'plain', 'meta_value' => 'small'],
]);
$oversizedMetaWpdb->forcedPattern = 'OCTET_LENGTH(meta_key)';
$oversizedMetaWpdb->forcedResult = [[
    'meta_id' => '1',
    'user_id' => '1',
    'meta_key_bytes' => '5',
    'meta_value_bytes' => (string) (\Duo\MetaRows::MAX_META_VALUE_BYTES + 1),
]];
$throws(
    static fn() => $captureForWpdb($oversizedMetaWpdb)->capture([]),
    'malformed/oversized row',
    'oversized user-meta LONGTEXT refuses at the compact preflight'
);
$check(
    count(array_filter(
        $oversizedMetaWpdb->queries,
        static fn(string $sql): bool => str_contains($sql, 'meta_key, meta_value')
    )) === 0,
    'oversized user-meta refuses before any full-value query'
);

$nullValueWpdb = new UserMetaCaptureWpdbFixture([
    ['user_id' => '1', 'user_login' => 'nullable', 'meta_key' => 'plain', 'meta_value' => null],
]);
$nullEntities = $captureForWpdb($nullValueWpdb)->capture([]);
$nullDocument = json_decode((string) ($nullEntities[0]['content'] ?? ''), true);
$check(
    is_array($nullDocument) && ($nullDocument['meta']['plain'] ?? null) === 'tokenized:',
    'valid nullable wp_usermeta values preserve the historical empty-string capture semantics'
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

$captureSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/Capture.php');
$candidateSource = file_get_contents(__DIR__ . '/../../../../agent/src/Capture/CaptureCandidateBuilder.php');
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
