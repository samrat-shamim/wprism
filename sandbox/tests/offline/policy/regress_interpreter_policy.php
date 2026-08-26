<?php
/**
 * Offline regression for DUO-3262's interpreter contract. Fake manifest-
 * shipped interpreters prove that post_meta_rule() remains required while
 * term_meta_rule()/user_meta_rule() are optional, context-aware hooks. The
 * test exercises the real Policy loader and dispatch; no WordPress bootstrap
 * or plugin code is needed because classification is the mechanism in scope.
 */

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';

use Duo\Canon;
use Duo\Policy;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 0);
}

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function check_throws(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        check(false, "$message (did not throw)");
    } catch (\Throwable $e) {
        check(str_contains($e->getMessage(), $needle), "$message (threw: {$e->getMessage()})");
    }
}

$root = sys_get_temp_dir() . '/duo_regress_interpreter_policy_' . bin2hex(random_bytes(4));
mkdir($root . '/interpreters', 0777, true);
register_shutdown_function(function () use ($root) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});
putenv("DUO_MANIFESTS_DIR=$root");

file_put_contents($root . '/interpreters/legacy-post-only.php', <<<'PHP'
<?php
namespace Duo\Interpreters;
final class LegacyPostOnly {
    public function __construct($policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'post_dynamic' && ($allMeta['_post_dynamic'] ?? null) === 'field_1'
            ? ['class' => 'authored']
            : null;
    }
}
PHP
);

file_put_contents($root . '/interpreters/all-meta-hooks.php', <<<'PHP'
<?php
namespace Duo\Interpreters;
final class AllMetaHooks {
    public function __construct($policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'post_dynamic' ? ['class' => 'authored'] : null;
    }
    public function term_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'term_dynamic' && ($allMeta['_term_dynamic'] ?? null) === 'field_2'
            ? ['class' => 'authored', 'ref' => 'term']
            : null;
    }
    public function user_meta_rule(string $key, array $allMeta): ?array {
        return $key === 'user_dynamic' && ($allMeta['_user_dynamic'] ?? null) === 'field_3'
            ? ['class' => 'authored', 'ref' => 'user']
            : null;
    }
}
PHP
);

file_put_contents($root . '/interpreters/missing-post-hook.php', <<<'PHP'
<?php
namespace Duo\Interpreters;
final class MissingPostHook {
    public function __construct($policy) {}
    public function term_meta_rule(string $key, array $allMeta): ?array { return null; }
}
PHP
);

function write_manifest(string $root, string $name, array $extra): void {
    Canon::write_file("$root/$name.json", Canon::encode(array_merge([
        'name' => $name,
        'spec_version' => DUO_SPEC_VERSION,
    ], $extra)));
}

write_manifest($root, 'legacy', [
    'interpreter' => 'legacy-post-only',
    'post_meta' => ['post_static' => ['class' => 'runtime']],
    'term_meta' => ['term_static' => ['class' => 'runtime']],
    'user_meta' => ['user_static' => ['class' => 'env']],
    'meta_patterns' => [['match' => '^pattern_', 'class' => 'derived']],
]);

echo "\n== post-only interpreter remains compatible ==\n";
$legacy = Policy::load(null, ['legacy']);
check(
    ($legacy->meta_rule_for_post('post_dynamic', ['_post_dynamic' => 'field_1'])['class'] ?? null) === 'authored',
    'required post hook still receives the owning post meta map'
);
check(
    ($legacy->meta_rule_for_term('term_static', [])['class'] ?? null) === 'runtime',
    'missing optional term hook defers to the static term_meta rule without error'
);
check(
    ($legacy->meta_rule_for_user('user_static', [])['class'] ?? null) === 'env',
    'missing optional user hook defers to the static user_meta rule without error'
);
check(
    $legacy->meta_rule_for_term('unclassified', ['shadow' => 'value']) === null,
    'no interpreter/static match stays null so Capture\'s loud unclassified gate remains armed'
);
check(
    ($legacy->meta_rule_for_term('pattern_versioned', [])['class'] ?? null) === 'derived',
    'existing meta_patterns fallback remains active for term meta'
);
check(
    $legacy->meta_rule_for_user('pattern_versioned', []) === null,
    'existing meta_patterns do not silently widen onto the new user_meta surface'
);
check(
    $legacy->user_meta_capture_blocker('user_static', []) === null,
    'non-authored static user_meta classifications remain usable without arming capture refusal'
);

write_manifest($root, 'full', [
    'interpreter' => 'all-meta-hooks',
    'post_meta' => ['post_static' => ['class' => 'runtime']],
    'term_meta' => [
        'term_dynamic' => ['class' => 'runtime'],
        'term_static' => ['class' => 'derived'],
    ],
    'user_meta' => [
        'user_dynamic' => ['class' => 'runtime'],
        'user_static' => ['class' => 'env'],
    ],
]);

echo "\n== optional hooks win, then defer exactly like post meta ==\n";
$full = Policy::load(null, ['full']);
$termRule = $full->meta_rule_for_term('term_dynamic', [
    '_term_dynamic' => 'field_2',
    'term_dynamic' => '17',
]);
check(
    ($termRule['class'] ?? null) === 'authored' && ($termRule['ref'] ?? null) === 'term',
    'term hook sees the full term meta map and wins over a conflicting static rule'
);
$userRule = $full->meta_rule_for_user('user_dynamic', [
    '_user_dynamic' => 'field_3',
    'user_dynamic' => '4',
]);
check(
    ($userRule['class'] ?? null) === 'authored' && ($userRule['ref'] ?? null) === 'user',
    'user hook sees the full user meta map and wins over a conflicting static rule'
);
check(
    $full->user_meta_capture_blocker('user_dynamic', [
        '_user_dynamic' => 'field_3',
        'user_dynamic' => '4',
    ]) === null,
    'interpreter-classified authored user meta is representable through the login-keyed sidecar'
);
check(
    ($full->meta_rule_for_term('term_static', [])['class'] ?? null) === 'derived',
    'null from term hook falls through to the static term_meta rule'
);
check(
    ($full->meta_rule_for_user('user_static', [])['class'] ?? null) === 'env',
    'null from user hook falls through to the static user_meta rule'
);
check(
    $full->meta_rule_for_user('unclassified', ['anything' => 'else']) === null,
    'neither user hook nor static policy silently invents a classification'
);

echo "\n== native mixed-option dispatch resolves ownership before hooks run ==\n";
$ownerState = (object) [
    'calls' => 0,
    'result' => true,
    'side_effects' => 0,
    'normalize_calls' => 0,
    'normalized' => null,
    'projection_calls' => 0,
    'projection_mode' => 'identity',
    'projection_value' => null,
    'projection_args' => null,
    'runtime_companions' => [],
];
$nonOwnerState = (object) ['calls' => 0, 'result' => true, 'side_effects' => 0];
$ownerInterpreter = new class ($ownerState) {
    public function __construct(private object $state) {}
    public function materialize_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        string $autoload,
        ?array $targetValue,
        \Closure $lockTargetOption,
        \Closure $finalizeStorage,
        \Closure $restoreStorage
    ) {
        ++$this->state->calls;
        ++$this->state->side_effects;
        return $this->state->result;
    }
    public function normalize_captured_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        array $rawOptionSnapshot
    ): array {
        ++$this->state->normalize_calls;
        return is_array($this->state->normalized) ? $this->state->normalized : $captured;
    }
    public function option_sub_key_materialization_runtime_companions(string $name) {
        return $this->state->runtime_companions;
    }
    public function project_materialized_option_sub_keys(
        string $name,
        array $rawAuthored,
        array $declaredSubKeys,
        array $desiredAuthoredKeys
    ) {
        ++$this->state->projection_calls;
        $this->state->projection_args = [$name, $rawAuthored, $declaredSubKeys, $desiredAuthoredKeys];
        return $this->state->projection_mode === 'identity'
            ? $rawAuthored
            : $this->state->projection_value;
    }
};
$nonOwnerInterpreter = new class ($nonOwnerState) {
    public function __construct(private object $state) {}
    public function materialize_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        string $autoload,
        ?array $targetValue,
        \Closure $lockTargetOption,
        \Closure $finalizeStorage,
        \Closure $restoreStorage
    ) {
        ++$this->state->calls;
        ++$this->state->side_effects;
        return $this->state->result;
    }
};
$nativePolicy = new Policy();
$nativeSubKeys = ['portable' => ['class' => 'authored']];
$nativeRule = ['class' => 'env', 'sub_keys' => $nativeSubKeys, 'closed_sub_keys' => true, 'autoload' => 'yes'];
$nativePolicy->manifests = [
    [
        'name' => 'native-owner',
        'interpreter' => 'native-owner',
        'options' => [
            'native_blob' => $nativeRule,
            'runtime_effect' => ['class' => 'runtime'],
        ],
    ],
    [
        'name' => 'hostile-non-owner',
        'interpreter' => 'hostile-non-owner',
        'options' => ['different_blob' => $nativeRule],
    ],
];
$interpreterInstances = new ReflectionProperty(Policy::class, 'interpreterInstances');
$interpreterInstances->setValue($nativePolicy, [
    'native-owner' => $ownerInterpreter,
    'hostile-non-owner' => $nonOwnerInterpreter,
]);
check(
    $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule,
        'native-owner',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    )
        && $ownerState->calls === 1
        && $nonOwnerState->calls === 0,
    'only the interpreter bound to the exact declaring manifest executes; a hostile non-owner has no side effects'
);
$ownerState->runtime_companions = ['runtime_effect'];
check(
    $nativePolicy->option_sub_key_materialization_runtime_companions(
        'native_blob',
        $nativeRule,
        'native-owner'
    ) === ['runtime_effect'],
    'runtime-companion authority resolves only through the exact closed mixed-option owner'
);
foreach ([
    'non-list' => ['runtime_effect' => true],
    'duplicate' => ['runtime_effect', 'runtime_effect'],
    'non-string' => [7],
] as $case => $runtimeRoster) {
    $ownerState->runtime_companions = $runtimeRoster;
    check_throws(
        fn() => $nativePolicy->option_sub_key_materialization_runtime_companions(
            'native_blob',
            $nativeRule,
            'native-owner'
        ),
        $case === 'non-list' ? 'must return a list' : 'malformed/duplicate',
        "native runtime-companion discovery refuses a $case roster"
    );
}
$ownerState->runtime_companions = ['runtime_effect'];
check_throws(
    fn() => $nativePolicy->option_sub_key_materialization_runtime_companions(
        'native_blob',
        $nativeRule,
        'site.duo.json'
    ),
    'full effective rule/provenance differs',
    'site policy cannot borrow a digest-bound runtime-companion writer roster'
);
$ownerState->normalized = ['portable' => 'canonical'];
check(
    $nativePolicy->normalize_captured_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'raw'],
        $nativeRule,
        'native-owner',
        ['native_blob' => serialize(['portable' => 'raw'])],
        false
    ) === ['portable' => 'canonical']
        && $ownerState->normalize_calls === 1,
    'exact native capture normalization may canonicalize an already-captured authored value'
);
$ownerState->normalized = [];
check_throws(
    fn() => $nativePolicy->normalize_captured_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'raw'],
        $nativeRule,
        'native-owner',
        ['native_blob' => serialize(['portable' => 'raw'])],
        false
    ),
    'dropped already-present authored option',
    'native capture normalization cannot turn authored presence into silent absence'
);
$ownerState->normalized = null;

$ownerState->projection_mode = 'value';
$ownerState->projection_value = ['portable' => 'canonical'];
check(
    $nativePolicy->project_materialized_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'native-storage'],
        $nativeRule,
        'native-owner',
        ['portable']
    ) === ['portable' => 'canonical']
        && $ownerState->projection_calls === 1
        && $ownerState->projection_args === [
            'native_blob',
            ['portable' => 'native-storage'],
            $nativeSubKeys,
            ['portable'],
        ],
    'the exact native owner may project only raw authored siblings through the declared sub-key roster'
);

$identityInterpreter = new class {
    public function materialize_option_sub_keys(
        string $name,
        array $captured,
        array $subKeys,
        string $autoload,
        ?array $targetValue,
        \Closure $lockTargetOption,
        \Closure $finalizeStorage,
        \Closure $restoreStorage
    ): bool {
        return true;
    }
};
$interpreterInstances->setValue($nativePolicy, [
    'native-owner' => $identityInterpreter,
    'hostile-non-owner' => $nonOwnerInterpreter,
]);
check(
    $nativePolicy->project_materialized_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'native-storage'],
        $nativeRule,
        'native-owner',
        ['portable']
    ) === ['portable' => 'native-storage'],
    'an exact native owner with no optional projection method uses the identity projection'
);
$interpreterInstances->setValue($nativePolicy, [
    'native-owner' => $ownerInterpreter,
    'hostile-non-owner' => $nonOwnerInterpreter,
]);

foreach ([
    'non-array' => [true, 'object-shaped array'],
    'list' => [['unexpected-list-value'], 'object-shaped array'],
    'added key' => [['portable' => 'native-storage', 'added' => true], 'must not add an authored key'],
    'non-plain value' => [['portable' => new stdClass()], 'PHP object'],
] as $case => [$projection, $message]) {
    $ownerState->projection_value = $projection;
    check_throws(
        fn() => $nativePolicy->project_materialized_option_sub_keys_via_interpreter(
            'native_blob',
            ['portable' => 'native-storage'],
            $nativeRule,
            'native-owner',
            ['portable']
        ),
        $message,
        "native materialization projection refuses a $case"
    );
}
$ownerState->projection_value = [];
check_throws(
    fn() => $nativePolicy->project_materialized_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'native-storage'],
        $nativeRule,
        'native-owner',
        ['portable']
    ),
    'must retain every desired authored key',
    'native projection cannot omit a raw carrier while that authored key is desired'
);
check(
    $nativePolicy->project_materialized_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => ['target-owned-residue' => true]],
        $nativeRule,
        'native-owner',
        []
    ) === [],
    'native projection may omit a raw carrier when the desired authored key is absent'
);
foreach ([
    'associative roster' => [['portable' => true], 'desired authored-key list'],
    'duplicate roster' => [['portable', 'portable'], 'malformed/non-authored desired key'],
    'non-authored roster' => [['runtime'], 'malformed/non-authored desired key'],
] as $case => [$desiredKeys, $message]) {
    check_throws(
        fn() => $nativePolicy->project_materialized_option_sub_keys_via_interpreter(
            'native_blob',
            ['portable' => 'native-storage'],
            $nativeRule,
            'native-owner',
            $desiredKeys
        ),
        $message,
        "native materialization projection refuses a $case"
    );
}
$ownerState->projection_mode = 'identity';
$ownerState->projection_value = null;

$ownerState->calls = 0;
$nonOwnerState->calls = 0;
$nativePolicy->manifests[1]['name'] = 'native-owner';
$nativePolicy->manifests[1]['options'] = ['native_blob' => $nativeRule];
check_throws(
    fn() => $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule,
        'native-owner',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ),
    'multiple exact manifest/interpreter owners',
    'dual exact claimants refuse before either hook can mutate'
);
check(
    $ownerState->calls === 0 && $nonOwnerState->calls === 0,
    'dual-claim refusal leaves both candidate hook counters untouched'
);

$nativePolicy->manifests = [[
    'name' => 'hostile-non-owner',
    'interpreter' => 'hostile-non-owner',
    'options' => ['different_blob' => $nativeRule],
]];
check(
    $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule,
        null,
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ) === false && $nonOwnerState->calls === 0,
    'zero exact candidates select the generic mixed-option path without probing non-owners'
);

$nativePolicy->manifests = [[
    'name' => 'native-owner',
    'interpreter' => 'native-owner',
    'options' => ['native_blob' => $nativeRule],
]];
check_throws(
    fn() => $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule + ['required' => true],
        'native-owner',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ),
    'full effective rule/provenance differs',
    'same subkeys with a different effective parent field cannot retarget a manifest-owned native hook'
);
check($ownerState->calls === 0, 'effective-policy mismatch refuses before the bound hook executes');

check_throws(
    fn() => $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule,
        'site.duo.json',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ),
    'full effective rule/provenance differs',
    'site provenance cannot borrow a digest-bound native hook even with a byte-identical rule'
);
check($ownerState->calls === 0, 'site-provenance mismatch refuses before the bound hook executes');

$openRule = $nativeRule;
$openRule['closed_sub_keys'] = false;
$nativePolicy->manifests[0]['options']['native_blob'] = $openRule;
check_throws(
    fn() => $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $openRule,
        'native-owner',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ),
    'is not a closed_sub_keys registry',
    'an exact open mixed-option rule cannot dispatch a native hook'
);
check($ownerState->calls === 0, 'open-rule refusal happens before the bound hook executes');
$nativePolicy->manifests[0]['options']['native_blob'] = $nativeRule;

$ownerState->result = false;
check_throws(
    fn() => $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule,
        'native-owner',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ),
    'returned false after dispatch; generic SQL fallback is forbidden',
    'an exact trusted hook returning false refuses after its side effect instead of falling through to SQL'
);
check(
    $ownerState->calls === 1 && $ownerState->side_effects === 2,
    'false-return coverage proves the trusted hook ran while the generic fallback remained unreachable'
);

$ownerState->calls = 0;
$ownerState->result = ['success-shaped'];
check_throws(
    fn() => $nativePolicy->materialize_option_sub_keys_via_interpreter(
        'native_blob',
        ['portable' => 'value'],
        $nativeRule,
        'native-owner',
        'yes',
        ['portable' => 'before'],
        static fn(string $targetName): ?array => null,
        static function (): void {},
        static function (): void {}
    ),
    'must return a boolean',
    'a non-boolean exact native result is never treated as success'
);

echo "\n== user_meta is a first-class policy section ==\n";
$siteRepo = $root . '/site';
mkdir($siteRepo);
Canon::write_file($siteRepo . '/site.duo.json', Canon::encode([
    'manifests' => ['legacy'],
    'policy' => new stdClass(),
]));
Policy::set_rule($siteRepo, 'user_meta', 'profile_owner', [
    'class' => 'authored',
    'ref' => 'user',
    'missing_user' => 'warn',
]);
$sitePolicy = Policy::load($siteRepo);
check(
    ($sitePolicy->meta_rule_for_user('profile_owner', [])['ref'] ?? null) === 'user',
    'wp duo classify write path accepts user_meta and Policy loads the site override'
);
check(
    $sitePolicy->user_meta_missing_behavior(['profile_owner' => 'user:editor']) === 'warn',
    'static authored user_meta policy carries explicit warn-and-skip missing-user behavior'
);
$export = Policy::export_manifest($siteRepo, '^profile_', 'profile-fixture');
$exportedUserMeta = (array) $export['user_meta'];
check(
    (($exportedUserMeta['profile_owner']['class'] ?? null) === 'authored')
        && is_object($export['term_meta']),
    'policy-to-manifest export carries user_meta while preserving empty-section objects'
);

write_manifest($root, 'broken', ['interpreter' => 'missing-post-hook']);
$broken = Policy::load(null, ['broken']);
check_throws(
    fn() => $broken->meta_rule_for_term('anything', []),
    'must define',
    'post_meta_rule remains mandatory even when an optional term hook exists'
);

echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
