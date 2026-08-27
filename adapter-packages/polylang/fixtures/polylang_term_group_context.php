<?php
declare(strict_types=1);

/**
 * Child-process fixture for Polylang's native language ordering field.
 *
 * Drives the real policy, taxonomy grammar, term capture, schema validator,
 * ledger lookup and term materializer against the shared row-backed FakeWpdb.
 */

$root = dirname(__DIR__, 3);
require_once $root . '/sandbox/tests/lib/agent_version.php';
duo_test_define_agent_versions();
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Kernel/Uuid.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/agent/src/Capture/EntityMetaCapture.php';
require_once $root . '/agent/src/Capture/TermCapture.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/TermMaterializer.php';
require_once $root . '/agent/src/Repository/RepositorySchemaValidator.php';

/**
 * The shared row store intentionally does not invent information_schema or
 * transaction-variable answers. This narrow proxy supplies the exact server
 * facts the production owner-range lock now requires while delegating every
 * data read/write to that shared store.
 */
final class PolylangTermGroupWpdb {
    public string $prefix;
    public string $base_prefix;
    public string $terms;
    public string $term_taxonomy;
    public string $termmeta;
    public string $options;
    public string $last_error = '';
    public int $insert_id = 0;
    public int $rows_affected = 0;
    private bool $activeTransaction = false;
    private bool $nextRepeatableRead = false;
    private bool $savepointExists = false;

    public function __construct(private readonly \DuoTest\FakeWpdb $inner) {
        foreach (['prefix', 'base_prefix', 'terms', 'term_taxonomy', 'termmeta', 'options'] as $property) {
            $this->$property = $inner->$property;
        }
    }

    public function __get(string $name): mixed { return $this->inner->$name; }

    public function __call(string $name, array $arguments): mixed {
        $this->syncIn();
        $result = $this->inner->$name(...$arguments);
        $this->syncOut();
        return $result === $this->inner ? $this : $result;
    }

    public function get_var(string $sql, int $x = 0, int $y = 0): mixed {
        $this->last_error = '';
        if ($sql === 'SELECT @@in_transaction') {
            return $this->activeTransaction ? '1' : '0';
        }
        if ($sql === 'SELECT @@transaction_isolation') {
            return 'REPEATABLE-READ';
        }
        return $this->forward('get_var', [$this->stripLockSyntax($sql), $x, $y]);
    }

    public function get_results(string $sql, string $output = OBJECT): mixed {
        $this->last_error = '';
        if (str_contains($sql, 'information_schema.TABLES')) {
            return [
                ['TABLE_NAME' => $this->options, 'ENGINE' => 'InnoDB'],
                ['TABLE_NAME' => $this->termmeta, 'ENGINE' => 'InnoDB'],
            ];
        }
        if ($sql === "SHOW INDEX FROM `{$this->termmeta}`") {
            return [[
                'Key_name' => 'term_id',
                'Seq_in_index' => '1',
                'Column_name' => 'term_id',
                'Sub_part' => null,
                'Non_unique' => '1',
                'Index_type' => 'BTREE',
            ]];
        }
        if ($sql === "SHOW INDEX FROM `{$this->options}`") {
            return [[
                'Key_name' => 'option_name',
                'Seq_in_index' => '1',
                'Column_name' => 'option_name',
                'Sub_part' => null,
                'Non_unique' => '0',
                'Index_type' => 'BTREE',
            ]];
        }
        return $this->forward('get_results', [$this->stripLockSyntax($sql), $output]);
    }

    public function get_row(string $sql, string $output = OBJECT, int $y = 0): mixed {
        return $this->forward('get_row', [$this->stripLockSyntax($sql), $output, $y]);
    }

    public function get_col(string $sql, int $x = 0): mixed {
        return $this->forward('get_col', [$this->stripLockSyntax($sql), $x]);
    }

    public function query(string $sql): mixed {
        if ($sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
            if ($this->activeTransaction) return false;
            $this->nextRepeatableRead = true;
            return 1;
        }
        if (preg_match('/^SAVEPOINT `duo_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->activeTransaction) return false;
            $this->savepointExists = true;
            return 1;
        }
        if (preg_match('/^RELEASE SAVEPOINT `duo_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->activeTransaction || !$this->savepointExists) return false;
            $this->savepointExists = false;
            return 1;
        }
        $result = $this->forward('query', [$sql]);
        if ($result !== false) {
            if (in_array(strtoupper(trim($sql)), ['START TRANSACTION', 'BEGIN'], true)) {
                if (!$this->nextRepeatableRead) return false;
                $this->nextRepeatableRead = false;
                $this->activeTransaction = true;
            } elseif (in_array(strtoupper(trim($sql)), ['COMMIT', 'ROLLBACK'], true)) {
                $this->activeTransaction = false;
                $this->savepointExists = false;
            }
        }
        return $result;
    }

    private function forward(string $method, array $arguments): mixed {
        $this->syncIn();
        $result = $this->inner->$method(...$arguments);
        $this->syncOut();
        return $result;
    }

    private function syncIn(): void { $this->inner->last_error = $this->last_error; }

    private function syncOut(): void {
        $this->last_error = $this->inner->last_error;
        $this->insert_id = $this->inner->insert_id;
        $this->rows_affected = $this->inner->rows_affected;
    }

    private function stripLockSyntax(string $sql): string {
        $sql = (string) preg_replace('/ FORCE INDEX \(`[^`]+`\)/', '', $sql);
        return (string) preg_replace('/ FOR UPDATE\s*$/D', '', $sql);
    }
}

$wpdb = new PolylangTermGroupWpdb(new \DuoTest\FakeWpdb());
$GLOBALS['wpdb'] = $wpdb;
$wpdb->setColumns('termmeta', [
    'meta_id' => 'bigint unsigned',
    'term_id' => 'bigint unsigned',
    'meta_key' => 'varchar(255)',
    'meta_value' => 'longtext',
]);
$wpdb->seedTable('termmeta', []);
$wpdb->setColumns('options', [
    'option_id' => 'bigint unsigned',
    'option_name' => 'varchar(191)',
    'option_value' => 'longtext',
    'autoload' => 'varchar(20)',
]);
$wpdb->seedTable('options', []);
$wpdb->setUniqueKey('options', ['option_name']);

$policy = \Duo\Policy::load(
    null,
    ['core', 'polylang'],
    adapterLibrary: \Duo\AdapterLibrary::fromSourceTree($root)
);
$tokens = new \Duo\Tokens('http://source.test', 'http://source.test/wp-content/uploads');
$meta = new \Duo\EntityMetaCapture(
    $policy,
    $tokens,
    static function (): void {},
    static function (): void {},
    static function (): void {}
);
$capture = new \Duo\TermCapture($policy, $tokens, $meta);
$uuid = '00000000-0000-4000-8000-000000000201';
$languageDescription = serialize(['locale' => 'ar', 'rtl' => 1, 'flag_code' => 'sa']);
$language = $capture->capture((object) [
    'term_id' => 19,
    'taxonomy' => 'language',
    'name' => 'العربية',
    'slug' => 'ar',
    'description' => $languageDescription,
    'parent' => 0,
    'term_group' => 2,
], $uuid, []);
$languageFront = \Duo\Canon::decode($language['content']);
$category = $capture->capture((object) [
    'term_id' => 20,
    'taxonomy' => 'category',
    'name' => 'Ordinary',
    'slug' => 'ordinary',
    'description' => '',
    'parent' => 0,
    'term_group' => 77,
], '00000000-0000-4000-8000-000000000202', []);
$categoryFront = \Duo\Canon::decode($category['content']);
$malformedDescriptionRefusal = '';
try {
    $capture->capture((object) [
        'term_id' => 21,
        'taxonomy' => 'language',
        'name' => 'Broken',
        'slug' => 'broken',
        'description' => 'a:3:{broken',
        'parent' => 0,
        'term_group' => 3,
    ], '00000000-0000-4000-8000-000000000203', []);
} catch (\Throwable $failure) {
    $malformedDescriptionRefusal = $failure->getMessage();
}
$termGroupRefusals = [];
foreach ([
    'negative-int' => -1,
    'negative-string' => '-1',
    'leading-zero' => '02',
    'overflow' => '999999999999999999999999999999999999',
    'float' => 2.5,
    'scientific' => '2e0',
    'junk' => '2junk',
    'array' => [2],
    'object' => (object) ['value' => 2],
    'null' => null,
    'boolean' => false,
] as $label => $termGroupValue) {
    try {
        $capture->capture((object) [
            'term_id' => 100,
            'taxonomy' => 'language',
            'name' => 'Boundary',
            'slug' => 'boundary',
            'description' => $languageDescription,
            'parent' => 0,
            'term_group' => $termGroupValue,
        ], '00000000-0000-4000-8000-000000000299', []);
        $termGroupRefusals[$label] = '';
    } catch (\Throwable $failure) {
        $termGroupRefusals[$label] = $failure->getMessage();
    }
}
$stringTermGroup = $capture->capture((object) [
    'term_id' => 101,
    'taxonomy' => 'language',
    'name' => 'String boundary',
    'slug' => 'string-boundary',
    'description' => $languageDescription,
    'parent' => 0,
    'term_group' => '2',
], '00000000-0000-4000-8000-000000000298', []);
$stringTermGroupFront = \Duo\Canon::decode($stringTermGroup['content']);

$findings = [];
$validator = new \Duo\RepositorySchemaValidator(
    $policy,
    'sidebar',
    static function (string $code, string $path, string $locator, string $message) use (&$findings): void {
        $findings[] = compact('code', 'path', 'locator', 'message');
    }
);
$validator->validate('term', $language['path'], $languageFront, null);
$validFindingCount = count($findings);
$missing = $languageFront;
unset($missing['term_group']);
$validator->validate('term', 'terms/language/missing.json', $missing, null);
$stringly = $languageFront;
$stringly['term_group'] = '2';
$validator->validate('term', 'terms/language/stringly.json', $stringly, null);
$foreign = $categoryFront;
$foreign['term_group'] = 77;
$validator->validate('term', 'terms/category/foreign.json', $foreign, null);

$wpdb->setColumns('duo_map', [
    'uuid' => 'char(36)',
    'entity_type' => 'varchar(32)',
    'id_kind' => 'varchar(32)',
    'local_id' => 'bigint unsigned',
]);
$wpdb->seedTable('duo_map', [[
    'uuid' => $uuid,
    'entity_type' => 'term',
    'id_kind' => \Duo\Ledger::KIND_TERM,
    'local_id' => 29,
]]);
$wpdb->setUniqueKey('duo_map', ['uuid', 'id_kind']);
$wpdb->seedTable('terms', [[
    'term_id' => 29,
    'name' => 'Target Arabic',
    'slug' => 'ar',
    'term_group' => 0,
]]);
$wpdb->seedTable('term_taxonomy', [[
    'term_taxonomy_id' => 39,
    'term_id' => 29,
    'taxonomy' => 'language',
    'description' => '',
    'parent' => 0,
    'count' => 0,
]]);
$fieldMaterializer = new \Duo\ApplyFieldMaterializer($policy, $tokens);
$materializer = new \Duo\TermMaterializer(
    $policy,
    $tokens,
    $fieldMaterializer
);
\Duo\Db::start_repeatable_read('Polylang term fixture transaction start');
$fieldMaterializer->begin_authored_transaction();
$materializer->begin_authored_transaction();
\Duo\CacheInvalidationTransaction::begin();
\Duo\CacheInvalidationTransaction::prepare_term_hierarchy_options(['language' => false]);
$materializer->finalize_term($languageFront, []);
\Duo\Db::commit('Polylang term fixture transaction commit');
\Duo\CacheInvalidationTransaction::finish();
\Duo\CacheInvalidationTransaction::end();
$materializer->end_authored_transaction();
$fieldMaterializer->end_authored_transaction();
$materializedRows = $wpdb->rows('terms');
$materializedTaxonomyRows = $wpdb->rows('term_taxonomy');

$invalidExact = '';
try {
    \Duo\TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'bad-exact',
        'taxonomies' => ['language' => ['term_group' => 'derived']],
    ]);
} catch (\Throwable $failure) {
    $invalidExact = $failure->getMessage();
}
$invalidPattern = '';
try {
    \Duo\TaxonomyGrammar::validate_taxonomy_object_keyspace_declarations([
        'name' => 'bad-pattern',
        'taxonomy_patterns' => [['match' => '^lang_', 'term_group' => 'authored']],
    ]);
} catch (\Throwable $failure) {
    $invalidPattern = $failure->getMessage();
}

echo \Duo\Canon::encode([
    'captured_category_has_term_group' => array_key_exists('term_group', $categoryFront),
    'captured_language_term_group' => $languageFront['term_group'] ?? null,
    'invalid_exact' => $invalidExact,
    'invalid_pattern' => $invalidPattern,
    'language_description' => $languageFront['description'] ?? null,
    'malformed_description_refusal' => $malformedDescriptionRefusal,
    'materialized_description' => $materializedTaxonomyRows[0]['description'] ?? null,
    'materialized_term_group' => $materializedRows[0]['term_group'] ?? null,
    'schema_findings' => array_slice($findings, $validFindingCount),
    'string_term_group' => $stringTermGroupFront['term_group'] ?? null,
    'term_group_refusals' => $termGroupRefusals,
    'valid_schema_findings' => $validFindingCount,
]);
