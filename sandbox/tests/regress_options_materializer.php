<?php
/**
 * Offline regression for OptionsMaterializer (DUO-3347 slice 7: the options
 * entity materializer extracted from Apply.php). Deliberately narrow, the
 * same wiring/shape idiom TermMaterializer/UserMetaMaterializer/
 * MenuMaterializer's own regressions already established: this file does not
 * re-implement or re-assert reconciliation behavior -- doing so from a
 * hand-copied twin of the logic would only add a second copy that could
 * silently drift from the real one. It proves the two things genuinely new
 * here instead: OptionsMaterializer is a real, directly constructible,
 * standalone public API with a narrow (Policy, Tokens, ApplyFieldMaterializer)
 * contract, and its one non-narrow dependency -- Apply's own $warnings
 * collection -- is genuinely parameterized (by reference) rather than
 * silently reaching back into Apply or carrying a second copy. Full
 * behavioral coverage (plain/tokenized/managed/sub_keys option reconciliation)
 * already exists in regress_lifecycle_options_snapshot.php and every live
 * conformance manifest sweep, unchanged by this extraction.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'home' ? 'https://options-materializer.example.test' : $default;
}
function wp_upload_dir(mixed $time = null, bool $create = true, bool $refresh = false): array {
    return ['baseurl' => 'https://options-materializer.example.test/wp-content/uploads'];
}
function wp_cache_delete(...$args): bool { return true; }
function maybe_serialize(mixed $value): mixed {
    return is_array($value) || is_object($value) ? serialize($value) : $value;
}

require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/Db.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Tokens.php';
require_once __DIR__ . '/../../agent/src/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../agent/src/OptionsMaterializer.php';

use Duo\ApplyFieldMaterializer;
use Duo\OptionsMaterializer;
use Duo\Policy;
use Duo\Tokens;

final class OptionsMaterializerAcfFakeWpdb {
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var list<array{table:string,data:array,where?:array}> */
    public array $writes = [];

    public function prepare(string $query, mixed ...$args): array {
        return ['sql' => $query, 'args' => $args];
    }

    public function get_var(array|string $query): ?int {
        return null;
    }

    public function insert(string $table, array $data, mixed $format = null): int {
        $this->writes[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    public function update(string $table, array $data, array $where, mixed $format = null, mixed $whereFormat = null): int {
        $this->writes[] = ['table' => $table, 'data' => $data, 'where' => $where];
        return 1;
    }
}

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// newInstanceWithoutConstructor(): this is a wiring/shape test, not a
// behavioral one (see the file docblock) -- a real Tokens needs a live
// WordPress runtime (untrailingslashit(), site options) to construct, which
// this offline suite deliberately does not stand up.
$policy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$tokens = (new ReflectionClass(Tokens::class))->newInstanceWithoutConstructor();
$fieldMaterializer = new ApplyFieldMaterializer($policy, $tokens);
$optionsMaterializer = new OptionsMaterializer($policy, $tokens, $fieldMaterializer);

$check($optionsMaterializer instanceof OptionsMaterializer, 'OptionsMaterializer is directly constructible with (Policy, Tokens, ApplyFieldMaterializer)');

$check((new ReflectionMethod(OptionsMaterializer::class, 'apply_options'))->isPublic(), 'apply_options() is public on OptionsMaterializer');
foreach (['option_apply_target', 'dynamic_option_rule_for_name', 'dynamic_option_resolver_values', 'apply_value', 'apply_option_sub_keys'] as $method) {
    $check((new ReflectionMethod(OptionsMaterializer::class, $method))->isPrivate(), "$method() stays private on OptionsMaterializer -- apply_options() is the only external entry point");
}

// The constructor takes exactly these three collaborators, in this order --
// a narrow, explicit data contract rather than a whole Apply instance.
$constructorParams = (new ReflectionClass(OptionsMaterializer::class))->getConstructor()->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $constructorParams)
        === ['Duo\\Policy', 'Duo\\Tokens', 'Duo\\ApplyFieldMaterializer'],
    'constructor depends on exactly Policy, Tokens, and ApplyFieldMaterializer -- no Apply instance'
);

// The one dependency that does NOT fit that narrow contract -- Apply's own
// $warnings collection, appended to from dozens of call sites across the
// whole file -- was never smuggled in as a fourth constructor collaborator
// or a hidden Apply back-reference; it travels as an explicit by-reference
// method parameter instead, on both methods that append to it.
$check(
    !(new ReflectionClass(OptionsMaterializer::class))->hasProperty('warnings'),
    'OptionsMaterializer does not carry its own copy of Apply\'s $warnings collection'
);
$applyOptionsParams = (new ReflectionMethod(OptionsMaterializer::class, 'apply_options'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $applyOptionsParams)
        === ['document', 'withDeletes', 'warnings', 'classificationDocument']
        && $applyOptionsParams[2]->isPassedByReference()
        && $applyOptionsParams[3]->isOptional()
        && (string) $applyOptionsParams[3]->getType() === '?array',
    'apply_options() takes warnings by reference and an optional immutable classification-document input'
);
$applyOptionSubKeysParams = (new ReflectionMethod(OptionsMaterializer::class, 'apply_option_sub_keys'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $applyOptionSubKeysParams) === ['name', 'captured', 'subKeys', 'autoload', 'warnings']
        && $applyOptionSubKeysParams[4]->isPassedByReference(),
    'apply_option_sub_keys() takes the caller\'s warnings collection as an explicit by-reference fifth parameter'
);

// === Prove the extraction itself: Apply.php no longer inlines these bodies,
// and its one remaining call site is a thin facade.
$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
foreach (['option_apply_target', 'dynamic_option_rule_for_name', 'dynamic_option_resolver_values', 'apply_value', 'apply_option_sub_keys'] as $method) {
    $check(
        !str_contains($applySource, "private function $method("),
        "Apply.php no longer defines $method() itself (moved to OptionsMaterializer.php, no facade needed -- called only from within the extracted cluster)"
    );
}
$transactionSource = file_get_contents(__DIR__ . '/../../agent/src/AuthoredTransactionExecutor.php');
$check(!str_contains($applySource, 'function apply_options(')
    && str_contains($transactionSource, '$this->optionsMaterializer->apply_options('),
    'AuthoredTransactionExecutor calls OptionsMaterializer directly without an Apply facade');

// A record-scoped option write must retain the complete immutable carrier as
// classification context while passing only the selected record to the write
// loop. ACF's options-page convention is the concrete manifest-owned case:
// `options_<field>` cannot be classified without its excluded
// `_options_<field>` shadow pointer. The engine owns only the generic
// two-document boundary; Acf::option_rule() remains the manifest interpreter.
$acfPolicy = Policy::load(null, ['acf']);
$acfPolicy->prime_interpreters_from_repository([
    'field_scoped_tagline' => [
        'type' => 'post',
        'path' => 'state/posts/acf-field/field_scoped_tagline.md',
        'data' => ['type' => 'acf-field', 'slug' => 'field_scoped_tagline'],
        'body' => serialize(['key' => 'field_scoped_tagline', 'type' => 'text']),
    ],
]);
$acfTokens = new Tokens();
$acfMaterializer = new OptionsMaterializer(
    $acfPolicy,
    $acfTokens,
    new ApplyFieldMaterializer($acfPolicy, $acfTokens)
);
$acfFullDocument = \Duo\OptionState::document([
    'options_scoped_tagline' => \Duo\OptionState::present('Scoped ACF tagline', 'yes'),
    '_options_scoped_tagline' => \Duo\OptionState::present('field_scoped_tagline', 'yes'),
]);
$acfSelectedDocument = \Duo\OptionState::document([
    'options_scoped_tagline' => \Duo\OptionState::present('Scoped ACF tagline', 'yes'),
]);
$GLOBALS['wpdb'] = new OptionsMaterializerAcfFakeWpdb();
$acfWarnings = [];
$missingCompanionRefused = false;
try {
    $acfMaterializer->apply_options($acfSelectedDocument, false, $acfWarnings);
} catch (RuntimeException $failure) {
    $missingCompanionRefused = str_contains($failure->getMessage(), 'policy declares');
}
$acfMaterializer->apply_options($acfSelectedDocument, false, $acfWarnings, $acfFullDocument);
$acfWrites = $GLOBALS['wpdb']->writes;
$check(
    $missingCompanionRefused
        && count($acfWrites) === 1
        && ($acfWrites[0]['data']['option_name'] ?? null) === 'options_scoped_tagline'
        && ($acfWrites[0]['data']['option_value'] ?? null) === 'Scoped ACF tagline'
        && !str_contains((string) ($acfWrites[0]['data']['option_name'] ?? ''), '_options_'),
    'record-scoped materialization uses an excluded ACF shadow only as immutable classification context and writes only the selected option'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall OptionsMaterializer checks passed\n";
exit(0);
