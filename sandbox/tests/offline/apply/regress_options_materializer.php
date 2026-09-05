<?php
/**
 * Offline regression for OptionsMaterializer (issue #3347 slice 7: the options
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
 * conformance manifest sweep. The narrow CSV-ref assertion below is the one
 * exception: it exercises the public write path because PMPro exposed a wire-
 * shape regression inside this collaborator's private value dispatch.
 */
declare(strict_types=1);

if (!defined('WPRISM_SPEC_VERSION')) {
    define('WPRISM_SPEC_VERSION', 2);
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
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

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require_once __DIR__ . '/../../../../agent/src/Grammar/Tokens.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../../../../agent/src/Apply/OptionsMaterializer.php';

use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\OptionsMaterializer;
use WPrism\Policy;
use WPrism\Tokens;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrismTest\FakeWpdb;

final class OptionsMaterializerFakeWpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $last_error = '';
    public bool $savepointExists = false;
    /** @var list<array{table:string,data:array,where?:array}> */
    public array $writes = [];
    /** @var array<string,int> canonical "uuid:id_kind" => target-local id */
    public array $localIds = [];
    /** @var array<string,array{option_id:int,option_value:string,autoload:string}> */
    public array $optionRows = [];

    public function prepare(string $query, mixed ...$args): string {
        foreach ($args as $arg) {
            $replacement = is_int($arg)
                ? (string) $arg
                : "'" . str_replace("'", "''", (string) $arg) . "'";
            $query = preg_replace('/%[ds]/', $replacement, $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): mixed {
        if ($query === 'SELECT @@in_transaction') return '1';
        if ($query === 'SELECT 1 FROM `wp_options` LIMIT 1') return '1';
        if (str_contains($query, 'SELECT local_id FROM wp_wprism_map')) {
            preg_match("/uuid = '((?:''|[^'])*)'/", $query, $uuidMatch);
            preg_match("/id_kind = '((?:''|[^'])*)'/", $query, $kindMatch);
            $uuid = str_replace("''", "'", (string) ($uuidMatch[1] ?? ''));
            $kind = str_replace("''", "'", (string) ($kindMatch[1] ?? ''));
            return $this->localIds[$uuid . ':' . $kind] ?? null;
        }
        return null;
    }

    public function get_results(string $query, mixed $mode = null): array {
        if (str_contains($query, 'information_schema.TABLES')) {
            return [['TABLE_NAME' => 'wp_options', 'ENGINE' => 'InnoDB']];
        }
        if (str_starts_with($query, 'SHOW INDEX FROM `wp_options`')) {
            return [[
                'Key_name' => 'option_name',
                'Seq_in_index' => '1',
                'Column_name' => 'option_name',
                'Sub_part' => null,
                'Non_unique' => '0',
                'Index_type' => 'BTREE',
                'Visible' => 'YES',
            ]];
        }
        if (str_contains($query, 'FROM wp_options FORCE INDEX (`option_name`)')) {
            preg_match("/option_name = '((?:''|[^'])*)'/", $query, $match);
            $name = str_replace("''", "'", (string) ($match[1] ?? ''));
            $row = $this->optionRows[$name] ?? null;
            if ($row === null) return [];
            if (str_contains($query, 'OCTET_LENGTH(option_value)')) {
                return [[
                    'option_name' => $name,
                    'option_value_bytes' => (string) strlen($row['option_value']),
                    'autoload_bytes' => (string) strlen($row['autoload']),
                ]];
            }
            if (str_contains($query, 'SHA2(option_value, 256)')) {
                return [[
                    'option_name' => $name,
                    'option_value_sha256' => hash('sha256', $row['option_value']),
                    'autoload_sha256' => hash('sha256', $row['autoload']),
                ]];
            }
            return [[
                'option_name' => $name,
                'option_value' => $row['option_value'],
                'autoload' => $row['autoload'],
            ]];
        }
        return [];
    }

    public function query(string $query): int|false {
        if (str_starts_with($query, 'SAVEPOINT `')) {
            $this->savepointExists = true;
            return 0;
        }
        if (str_starts_with($query, 'RELEASE SAVEPOINT `')) {
            if (!$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 0;
        }
        return 0;
    }

    public function insert(string $table, array $data, mixed $format = null): int {
        $this->writes[] = ['table' => $table, 'data' => $data];
        if ($table === $this->options) {
            $this->optionRows[(string) $data['option_name']] = [
                'option_id' => count($this->optionRows) + 1,
                'option_value' => (string) $data['option_value'],
                'autoload' => (string) $data['autoload'],
            ];
        }
        return 1;
    }

    public function update(string $table, array $data, array $where, mixed $format = null, mixed $whereFormat = null): int {
        $this->writes[] = ['table' => $table, 'data' => $data, 'where' => $where];
        if ($table === $this->options && isset($this->optionRows[(string) ($where['option_name'] ?? '')])) {
            $name = (string) $where['option_name'];
            $this->optionRows[$name] = array_replace($this->optionRows[$name], $data);
        }
        return 1;
    }
}

/** @param list<array{uuid:string,entity_type:string,id_kind:string,local_id:int}> $mapRows */
function options_materializer_db(array $mapRows = []): FakeWpdb {
    return FakeWpdb::install()
        ->seedTable('wp_options', [])
        ->setColumns('wp_options', [
            'option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
            'option_value' => 'longtext', 'autoload' => 'varchar(20)',
        ])
        ->setAutoIncrement('wp_options', 1, 'option_id')
        ->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [[
            'Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE',
        ]])
        ->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', $mapRows)
        ->setColumns('wp_wprism_map', [
            'uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)',
            'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned',
        ])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
        ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB')
        ->enableInformationSchema();
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
        === ['WPrism\\Policy', 'WPrism\\Tokens', 'WPrism\\ApplyFieldMaterializer'],
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
        === ['document', 'withDeletes', 'warnings', 'classificationDocument', 'workAuthority']
        && $applyOptionsParams[2]->isPassedByReference()
        && $applyOptionsParams[3]->isOptional()
        && (string) $applyOptionsParams[3]->getType() === '?array'
        && $applyOptionsParams[4]->isOptional()
        && $applyOptionsParams[4]->getDefaultValue() === null
        && (string) $applyOptionsParams[4]->getType() === '?WPrism\\DatabaseWorkAuthority',
    'apply_options() takes shared warnings, immutable classification and optional engine work authority'
);
$applyOptionSubKeysParams = (new ReflectionMethod(OptionsMaterializer::class, 'apply_option_sub_keys'))->getParameters();
$check(
    array_map(static fn(ReflectionParameter $p): string => $p->getName(), $applyOptionSubKeysParams)
        === ['name', 'captured', 'rule', 'ruleSource', 'autoload', 'warnings']
        && $applyOptionSubKeysParams[5]->isPassedByReference(),
    'apply_option_sub_keys() carries the complete effective rule/provenance and explicit by-reference warnings collection'
);

// === Prove the extraction itself: Apply.php no longer inlines these bodies,
// and its one remaining call site is a thin facade.
$applySource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/Apply.php');
foreach (['option_apply_target', 'dynamic_option_rule_for_name', 'dynamic_option_resolver_values', 'apply_value', 'apply_option_sub_keys'] as $method) {
    $check(
        !str_contains($applySource, "private function $method("),
        "Apply.php no longer defines $method() itself (moved to OptionsMaterializer.php, no facade needed -- called only from within the extracted cluster)"
    );
}
$transactionSource = file_get_contents(__DIR__ . '/../../../../agent/src/Apply/AuthoredTransactionExecutor.php');
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
$acfFieldMaterializer = new ApplyFieldMaterializer($acfPolicy, $acfTokens);
$acfMaterializer = new OptionsMaterializer(
    $acfPolicy,
    $acfTokens,
    $acfFieldMaterializer
);
$acfFullDocument = \WPrism\OptionState::document([
    'options_scoped_tagline' => \WPrism\OptionState::present('Scoped ACF tagline', 'yes'),
    '_options_scoped_tagline' => \WPrism\OptionState::present('field_scoped_tagline', 'yes'),
]);
$acfSelectedDocument = \WPrism\OptionState::document([
    'options_scoped_tagline' => \WPrism\OptionState::present('Scoped ACF tagline', 'yes'),
]);
$GLOBALS['wpdb'] = options_materializer_db();
$acfWarnings = [];
Db::start_repeatable_read(
    'options materializer ACF fixture transaction start',
    new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options'])
);
$acfFieldMaterializer->begin_authored_transaction();
$acfMaterializer->begin_authored_transaction();
CacheInvalidationTransaction::begin();
$missingCompanionRefused = false;
try {
    $acfMaterializer->apply_options($acfSelectedDocument, false, $acfWarnings);
} catch (RuntimeException $failure) {
    $missingCompanionRefused = str_contains($failure->getMessage(), 'policy declares');
}
$acfMaterializer->apply_options($acfSelectedDocument, false, $acfWarnings, $acfFullDocument);
$acfRows = $GLOBALS['wpdb']->rows('wp_options');
Db::commit('options materializer ACF fixture transaction commit');
$acfMaterializer->commit_authored_transaction();
CacheInvalidationTransaction::finish();
$acfMaterializer->end_authored_transaction();
$acfFieldMaterializer->end_authored_transaction();
CacheInvalidationTransaction::end();
$check(
    $missingCompanionRefused
        && count($acfRows) === 1
        && ($acfRows[0]['option_name'] ?? null) === 'options_scoped_tagline'
        && ($acfRows[0]['option_value'] ?? null) === 'Scoped ACF tagline'
        && !str_contains((string) ($acfRows[0]['option_name'] ?? ''), '_options_'),
    'record-scoped materialization uses an excluded ACF shadow only as immutable classification context and writes only the selected option'
);

// PMPro passes pmpro_level_order straight to explode(), so its target wire
// type is part of the adapter contract rather than a cosmetic serialization
// choice. Exercise the public option write path with divergent target-local
// ids; this failed live when the ref-only codec wrote a serialized array.
$firstLevelUuid = '44444444-4444-7444-8444-444444444444';
$secondLevelUuid = '55555555-5555-7555-8555-555555555555';
$csvPolicy = new Policy();
$csvPolicy->site = ['policy' => ['options' => [
    'pmpro_level_order' => [
        'class' => 'authored',
        'ref' => 'pmpro_level[]',
        'cast' => 'csv',
        'autoload' => 'yes',
    ],
]]];
$csvTokens = new Tokens();
$csvFieldMaterializer = new ApplyFieldMaterializer($csvPolicy, $csvTokens);
$csvMaterializer = new OptionsMaterializer(
    $csvPolicy,
    $csvTokens,
    $csvFieldMaterializer
);
$csvDb = options_materializer_db([
    ['uuid' => $firstLevelUuid, 'entity_type' => 'pmpro_level', 'id_kind' => 'pmpro_level', 'local_id' => 701],
    ['uuid' => $secondLevelUuid, 'entity_type' => 'pmpro_level', 'id_kind' => 'pmpro_level', 'local_id' => 902],
]);
$GLOBALS['wpdb'] = $csvDb;
$csvWarnings = [];
Db::start_repeatable_read(
    'options materializer CSV fixture transaction start',
    new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options'])
);
$csvFieldMaterializer->begin_authored_transaction();
$csvMaterializer->begin_authored_transaction();
CacheInvalidationTransaction::begin();
$csvMaterializer->apply_options(\WPrism\OptionState::document([
    'pmpro_level_order' => \WPrism\OptionState::present([
        '{{pmpro_level:' . $firstLevelUuid . '}}',
        '{{pmpro_level:' . $secondLevelUuid . '}}',
    ], 'yes'),
]), false, $csvWarnings);
Db::commit('options materializer CSV fixture transaction commit');
$csvMaterializer->commit_authored_transaction();
CacheInvalidationTransaction::finish();
$csvMaterializer->end_authored_transaction();
$csvFieldMaterializer->end_authored_transaction();
CacheInvalidationTransaction::end();
$csvWrite = $csvDb->rows('wp_options')[0]['option_value'] ?? null;
$check(
    $csvWrite === '701,902' && explode(',', $csvWrite) === ['701', '902'],
    'CSV option refs apply as the plugin-native comma-delimited string with target-local ids'
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
