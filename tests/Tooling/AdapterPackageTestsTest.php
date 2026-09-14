<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\AdapterPackageTestDiscovery;
use WPrism\Tooling\AdapterPackageTestRunner;
use WPrism\Tooling\AdapterPackageTestsCommand;
use WPrism\Tooling\AdapterPackageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestDiscovery.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestRunner.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestsCommand.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageValidator.php';

final class AdapterPackageTestsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/wprism-adapter-package-tests-' . bin2hex(random_bytes(8));
        self::makeDirectory($this->root . '/adapter-packages');
        $canonical = realpath($this->root);
        self::assertNotFalse($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testDiscoveryIsRecursiveDeterministicAndConfinedToTestsClass(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/zeta/regress_z.sh', "#!/usr/bin/env bash\n");
        self::write($package . '/tests/offline/regress_b.php', '<?php');
        self::write($package . '/tests/offline/alpha/regress_a.sh', "#!/usr/bin/env bash\n");
        self::write($package . '/tests/live/regress_live.php', '<?php');
        self::write($package . '/tests/certify/version-matrix.sh', "seed_acf_content() { :; }\n");
        self::write($package . '/tests/conformance/entry.json', "{}\n");
        self::write($package . '/tests/conformance/seed.sh', "seed_acf_content\n");
        self::write($package . '/fixtures/regress_fixture.php', '<?php throw new Exception;');
        self::write($package . '/evidence/regress_evidence.sh', 'exit 99');
        self::write($package . '/package/regress_payload.php', '<?php exit(99);');

        $found = AdapterPackageTestDiscovery::discover($this->root, 'acf');

        self::assertSame('offline', $found['class']);
        self::assertSame([
            'adapter-packages/acf/tests/offline/alpha/regress_a.sh',
            'adapter-packages/acf/tests/offline/regress_b.php',
            'adapter-packages/acf/tests/offline/zeta/regress_z.sh',
        ], array_column($found['tests'], 'path'));
        self::assertSame(['bash', 'php', 'bash'], array_column($found['tests'], 'runtime'));
    }

    public function testCurrentAcfCapsuleValidatesWithoutReadingSiblingPackages(): void
    {
        $result = AdapterPackageValidator::validate(dirname(__DIR__, 2), 'acf');

        self::assertSame(AdapterPackageValidator::FORMAT, $result['format']);
        self::assertSame('acf', $result['adapter']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['digest']);
        self::assertSame(['conformance-acf', 'exact-artifact-version-matrix'], $result['evidence_tests']);
        self::assertContains('closed-library', $result['checks']);
        self::assertContains('adapter-identity', $result['checks']);
        self::assertNotEmpty(array_filter(
            $result['checks'],
            static fn(string $check): bool => str_starts_with($check, 'dependency-boundary:')
        ));
        self::assertContains('runtime-sdk:' . AdapterPackageValidator::RUNTIME_SDK_FORMAT, $result['checks']);
        self::assertContains('artifact-evidence', $result['checks']);
        self::assertContains('production-readiness', $result['checks']);
        self::assertContains('premise-evidence:2', $result['checks']);
        self::assertContains('version-matrix-premises', $result['checks']);
        self::assertContains('evidence-wiring:2', $result['checks']);
    }

    public function testValidatorRejectsConformanceHelpersOutsideTheFixtureBoundary(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/tests/conformance/native.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown adapter test file for 'acf' in tests/conformance");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAdmitsFixtureHelpersWithoutExecutingThem(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/fixtures/capture-plan/native.php',
            '<?php throw new RuntimeException("fixture helpers must not execute during validation");'
        );

        $result = AdapterPackageValidator::validate($root, 'acf');
        $found = AdapterPackageTestDiscovery::discover($root, 'acf');

        self::assertContains('test-discovery:' . count($found['tests']), $result['checks']);
        self::assertNotContains('native', $result['evidence_tests']);
    }

    public function testEvidenceCatalogDiscoversLocalAndParticipantOwnedGates(): void
    {
        $tests = AdapterPackageValidator::discoverableEvidence(dirname(__DIR__, 2), 'rank-math');

        self::assertSame($tests, array_values(array_unique($tests)));
        self::assertContains('conformance-rank-math', $tests);
        self::assertContains('exact-artifact-version-matrix', $tests);
        self::assertContains('regress-rank-math-provider', $tests);
        self::assertContains('regress-rank-math-commerce-multilingual', $tests);
        self::assertContains('regress-rank-math-commerce-multilingual-ssh-deletion', $tests);
    }

    public function testEvidenceCatalogCannotCertifyANonExecutableFixture(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/fixtures/regress_claim.json',
            "{}\n"
        );

        self::assertNotContains(
            'regress-claim',
            AdapterPackageValidator::discoverableEvidence($root, 'acf'),
            'Evidence ids come only from executable class-named PHP/shell suites, not filename-shaped fixtures.'
        );
    }

    public function testRuntimeSdkIsVersionedAndInventoriesEveryCurrentLegitimateDependency(): void
    {
        self::assertSame([
            'format' => 'wprism-adapter-runtime-sdk/v2',
            'symbols' => [
                'WPrism\\CacheInvalidationTransaction',
                'WPrism\\Canon',
                'WPrism\\IdentityTokenCodec',
                'WPrism\\Ledger',
                'WPrism\\ManifestProviderRuntime',
                'WPrism\\NativeActions',
                'WPrism\\NativeRewriteEffects',
                'WPrism\\PlainData',
                'WPrism\\Policy',
                'WPrism\\ProviderSdk',
                'WPrism\\Providers',
                'WPrism\\Secrets',
                'WPrism\\SidebarState',
                'WPrism\\Tokens',
            ],
        ], AdapterPackageValidator::runtimeSdk());
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function forbiddenRuntimeExecutionMachinery(): iterable
    {
        foreach ([
            'BEGIN', 'BEGIN WORK', 'SAVEPOINT adapter_save', 'RELEASE SAVEPOINT adapter_save',
            'ROLLBACK TO adapter_save', 'ROLLBACK WORK TO SAVEPOINT adapter_save',
            'SET autocommit=0', 'SET SESSION autocommit = OFF', 'SET @@SESSION.autocommit=1',
            'LOCK TABLES wp_rows WRITE', 'UNLOCK TABLES', "XA START 'adapter'",
        ] as $statement) {
            yield 'transaction grammar ' . $statement => [
                "\n\$transport(" . var_export($statement, true) . ");\n",
                'transaction-control',
            ];
        }
        foreach ([
            '(\\WP_CLI)::runcommand("plugin list");',
            '(\\WP_CLI::get_runner())->run_command(["plugin", "list"]);',
            '((\\WP_CLI::get_runner()))?->run_command(["plugin", "list"]);',
        ] as $statement) {
            yield 'grouped process ' . $statement => ["\n" . $statement . "\n", 'direct-process'];
        }
        yield 'grouped PDO static call' => [
            "\n(\\PDO)::getAvailableDrivers();\n",
            'raw-database-transport',
        ];
        foreach ([
            '($wpdb)->query("SELECT 1")',
            '(($GLOBALS["wpdb"]))?->get_var("SELECT 1")',
        ] as $statement) {
            yield 'encoded grouped receiver ' . $statement => [
                "\n\$code = " . var_export($statement, true) . ";\n",
                'raw-database-transport',
            ];
        }
        foreach (['include', 'include_once', 'require', 'require_once'] as $include) {
            yield 'target-generated PHP ' . $include => [
                "\n\$rows = $include \$indexPath;\n",
                'direct-include',
            ];
        }
        yield 'direct engine child process' => [
            "\n\\WPrism\\WpCliChildProcess::capture('plugin command');\n",
            'wp-cli-child-process',
        ];
        yield 'direct operating-system process' => [
            "\nproc_open('php', [], \$pipes);\n",
            'direct-process',
        ];
        yield 'raw wpdb query transport' => [
            "\nglobal \$wpdb;\n\$wpdb->query(\$sql);\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb typed mutation transport' => [
            "\nglobal \$wpdb;\n\$wpdb->update('wp_rows', ['value' => 1], ['id' => 1]);\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb selected-schema transport' => [
            "\nglobal \$wpdb;\n\$wpdb->select('another_schema');\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb SQL-mode transport' => [
            "\nglobal \$wpdb;\n\$wpdb->set_sql_mode(['ANSI_QUOTES']);\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb charset transport' => [
            "\nglobal \$wpdb;\n\$wpdb->set_charset(\$wpdb->dbh, 'gbk');\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb mysqli handle access' => [
            "\nglobal \$wpdb;\n\$handle = \$wpdb->dbh;\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb result-set transport' => [
            "\nglobal \$wpdb;\n\$rows = \$wpdb->get_results('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'raw wpdb scalar transport' => [
            "\nglobal \$wpdb;\n\$value = \$wpdb->get_var('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'raw global wpdb query transport' => [
            "\n\$GLOBALS['wpdb']->query('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'raw global wpdb result-set transport' => [
            "\n\$GLOBALS[\"wpdb\"]->get_results('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'nullsafe wpdb transport' => [
            "\nglobal \$wpdb;\n\$wpdb?->query('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'nullsafe global wpdb transport' => [
            "\n\$GLOBALS['wpdb']?->get_var('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'parenthesized wpdb transport' => [
            "\nglobal \$wpdb;\n(\$wpdb)->query('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'nested parenthesized wpdb transport' => [
            "\nglobal \$wpdb;\n((\$wpdb))->get_results('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'parenthesized global wpdb transport' => [
            "\n(\$GLOBALS['wpdb'])->get_results('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'direct mysqli query transport' => [
            "\nmysqli_query(\$handle, 'SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'direct mysqli selected-schema transport' => [
            "\nmysqli_select_db(\$handle, 'another_schema');\n",
            'raw-database-transport',
        ];
        yield 'direct mysqli execute-query transport' => [
            "\nmysqli_execute_query(\$handle, 'SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'direct mysqli change-user transport' => [
            "\nmysqli_change_user(\$handle, 'user', 'pass', 'db');\n",
            'raw-database-transport',
        ];
        yield 'direct mysqli construction' => [
            "\n\$db = new \\mysqli('localhost');\n",
            'raw-database-transport',
        ];
        yield 'inline mysqli construction and query' => [
            "\n(new \\mysqli('localhost'))->query('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'direct wpdb construction' => [
            "\n\$db = new \\wpdb('user', 'pass', 'db', 'localhost');\n",
            'raw-database-transport',
        ];
        yield 'direct PDO transport' => [
            "\n\$pdo = new \\PDO('mysql:host=localhost');\n\$pdo->query('SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'aliased PDO class import' => [
            "\nuse PDO as Connection;\n\$db = new Connection('mysql:host=localhost');\n",
            'raw-database-transport',
        ];
        yield 'aliased mysqli class import' => [
            "\nuse mysqli as Connection;\n\$db = new Connection('localhost');\n",
            'raw-database-transport',
        ];
        yield 'aliased mysqli function import' => [
            "\nuse function mysqli_query as db_query;\ndb_query(\$handle, 'SELECT 1');\n",
            'raw-database-transport',
        ];
        yield 'absolute aliased mysqli function import' => [
            "\nuse function \\mysqli_query as db_query;\n",
            'raw-database-transport',
        ];
        yield 'multi class import with raw database aliases' => [
            "\nuse DateTime, PDO as Connection, mysqli as NativeConnection;\n",
            'raw-database-transport',
        ];
        yield 'multi function import with raw database alias' => [
            "\nuse function strlen as text_length, mysqli_query as db_query;\n",
            'raw-database-transport',
        ];
        yield 'absolute multi function import with raw database alias' => [
            "\nuse function \\strlen as text_length, \\mysqli_query as db_query;\n",
            'raw-database-transport',
        ];
        yield 'comment-separated raw database class alias' => [
            "\nuse PDO/**/as Connection;\n",
            'raw-database-transport',
        ];
        yield 'comment-separated raw database function alias' => [
            "\nuse function/**/mysqli_query/**/as db_query;\n",
            'raw-database-transport',
        ];
        yield 'raw database parent class' => [
            "\nclass AdapterDatabase extends \\PDO {}\n",
            'raw-database-transport',
        ];
        yield 'raw DML behind an adapter wrapper' => [
            "\n\$transport('DELETE FROM `wp_rows`');\n",
            'raw-database-transport',
        ];
        yield 'encoded nullsafe wpdb transport' => [
            "\n\$code = '\$wpdb?->query(\"SELECT 1\")';\n",
            'raw-database-transport',
        ];
        yield 'transaction control behind an adapter wrapper' => [
            "\n\$transport('START TRANSACTION');\n",
            'transaction-control',
        ];
        yield 'direct current-file include' => [
            "\nrequire_once __FILE__;\n",
            'direct-self-include',
        ];
        yield 'current-file include encoded for a child' => [
            "\n\$code = 'require_once ' . var_export(__FILE__, true);\n",
            'direct-self-include',
        ];
        yield 'aliased operating-system process' => [
            "\nuse function proc_open as launch;\nlaunch('php', [], \$pipes);\n",
            'direct-process',
        ];
        yield 'absolute aliased operating-system process' => [
            "\nuse function \\proc_open as launch;\n",
            'direct-process',
        ];
        yield 'comment-separated operating-system process alias' => [
            "\nuse function/**/proc_open/**/as launch;\n",
            'direct-process',
        ];
        yield 'forked operating-system process' => [
            "\npcntl_fork();\n",
            'direct-process',
        ];
        yield 'multi function import with process alias' => [
            "\nuse function strlen as text_length, proc_open as launch;\n",
            'direct-process',
        ];
        yield 'WP-CLI command process' => [
            "\n\\WP_CLI::runcommand('plugin list');\n",
            'direct-process',
        ];
        yield 'aliased WP-CLI command process' => [
            "\nuse WP_CLI as Console;\nConsole::runcommand('plugin list');\n",
            'direct-process',
        ];
        yield 'comment-separated WP-CLI command alias' => [
            "\nuse WP_CLI/**/as Console;\n",
            'direct-process',
        ];
        yield 'multi class import with WP-CLI alias' => [
            "\nuse DateTime, WP_CLI as Console;\n",
            'direct-process',
        ];
        yield 'WP-CLI runner command process' => [
            "\n\\WP_CLI::get_runner()->run_command(['plugin', 'list']);\n",
            'direct-process',
        ];
        yield 'PHP shell execution operator' => [
            "\n\$result = `wp plugin list`;\n",
            'direct-process',
        ];
    }

    #[DataProvider('forbiddenRuntimeExecutionMachinery')]
    public function testValidatorRejectsNewAdapterOwnedExecutionMachinery(string $mutation, string $finding): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write($path, (string) file_get_contents($path) . $mutation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("introduces engine-owned runtime machinery '$finding'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testRuntimeExecutionGuardIgnoresCommentsAndDiagnosticProse(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path)
                . "\n// WpCliChildProcess, proc_open(), and \$wpdb->query('COMMIT') are inert prose.\n"
                . "function wprismRuntimeBoundaryDiagnostic(mixed \$provider): string {\n"
                . "    global \$wpdb;\n"
                . "    \$wpdb->prepare('SELECT %s', 'value');\n"
                . "    \$wpdb->esc_like('literal_%');\n"
                . "    \$wpdb->get_blog_prefix(1);\n"
                . "    \$provider->query('not a wpdb receiver');\n"
                . "    return 'COMMIT outcome was unknown';\n"
                . "}\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertContains('runtime-execution-boundary:1', $result['checks']);
    }

    /** @return iterable<string,array{string}> */
    public static function permittedRuntimeSymbolLookalikes(): iterable
    {
        foreach ([
            '\\Vendor\\proc_open();',
            'Vendor\\proc_open();',
            '\\Vendor\\mysqli_query();',
            'Vendor\\mysqli_query();',
            'use function Vendor\\proc_open; proc_open();',
            'use function Vendor\\proc_open as launch; launch();',
            'use function Vendor\\mysqli_query; mysqli_query();',
            'use function Vendor\\mysqli_query as read; read();',
            'new \\Vendor\\PDO();',
            'new Vendor\\PDO();',
            'use Vendor\\PDO; new PDO();',
            '\\Vendor\\WP_CLI::runcommand();',
            'use Vendor\\WP_CLI; WP_CLI::runcommand();',
            'namespace Vendor; new PDO();',
            'namespace Vendor; WP_CLI::runcommand();',
            'trait PDO {} class UsesPdoTrait { use PDO; }',
            'trait WP_CLI {} class UsesConsoleTrait { use WP_CLI; }',
            '$constant = \\PDO::ATTR_TIMEOUT;',
            '$constant = (\\PDO)::ATTR_TIMEOUT;',
            '$closure = function () use ($transport) { return $transport; };',
            '$message = "BEGIN work before exporting";',
        ] as $statement) {
            yield $statement => ["\n" . $statement . "\n"];
        }
    }

    #[DataProvider('permittedRuntimeSymbolLookalikes')]
    public function testRuntimeExecutionGuardResolvesExactSymbols(string $mutation): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write($path, (string) file_get_contents($path) . $mutation);
        $result = AdapterPackageValidator::validate($root, 'acf');
        self::assertContains('runtime-execution-boundary:1', $result['checks']);
    }

    public function testReviewedLegacyRuntimeDebtMatchesEveryCurrentPathAndSourceDigest(): void
    {
        $reflection = new \ReflectionClass(AdapterPackageValidator::class);
        $registry = $reflection->getReflectionConstant('LEGACY_RUNTIME_EXECUTION_DEBT');
        self::assertNotFalse($registry);
        $rows = $registry->getValue();
        self::assertIsArray($rows);
        self::assertCount(12, $rows);
        $guard = $reflection->getMethod('assertRuntimeExecutionBoundary');
        $repo = dirname(__DIR__, 2);

        foreach ($rows as $relative => $_row) {
            self::assertIsString($relative);
            self::assertMatchesRegularExpression(
                '~^adapter-packages/(?<slug>[a-z][a-z0-9]*(?:-[a-z0-9]+)*)/~D',
                $relative
            );
            preg_match('~^adapter-packages/(?<slug>[a-z][a-z0-9]*(?:-[a-z0-9]+)*)/~D', $relative, $match);
            $slug = $match['slug'];
            $capsule = $repo . '/adapter-packages/' . $slug;
            $path = $repo . '/' . $relative;
            $source = file_get_contents($path);
            self::assertIsString($source);
            self::assertSame($relative, $guard->invoke(null, $capsule, $path, $source, $slug));
        }
    }

    public function testReviewedLegacyRuntimeDebtRejectsSourceDriftInsteadOfRefreshingItsHash(): void
    {
        $repo = dirname(__DIR__, 2);
        $slug = 'elementor';
        $capsule = $repo . '/adapter-packages/' . $slug;
        $path = $capsule . '/package/runtime/providers/elementor-css.php';
        $source = (string) file_get_contents($path) . "\n";
        $guard = (new \ReflectionClass(AdapterPackageValidator::class))
            ->getMethod('assertRuntimeExecutionBoundary');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed frozen legacy runtime debt');
        $guard->invoke(null, $capsule, $path, $source, $slug);
    }

    public function testReviewedLegacyRuntimeDebtRequiresEveryRegisteredPathToBeVisited(): void
    {
        $reflection = new \ReflectionClass(AdapterPackageValidator::class);
        $registry = $reflection->getReflectionConstant('LEGACY_RUNTIME_EXECUTION_DEBT');
        self::assertNotFalse($registry);
        $rows = $registry->getValue();
        self::assertIsArray($rows);
        $visited = [];
        foreach (array_keys($rows) as $path) {
            if (str_starts_with($path, 'adapter-packages/woocommerce/')) {
                $visited[$path] = true;
            }
        }
        self::assertCount(5, $visited);
        unset($visited[array_key_first($visited)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not consume its frozen legacy runtime debt row');
        $reflection->getMethod('assertCompleteLegacyRuntimeDebt')->invoke(null, 'woocommerce', $visited);
    }

    public function testGrandfatheredRuntimeStillValidatesWithoutAdvertisingItsEngineInternal(): void
    {
        $result = AdapterPackageValidator::validate(dirname(__DIR__, 2), 'elementor');

        self::assertContains('runtime-sdk:wprism-adapter-runtime-sdk/v2', $result['checks']);
        self::assertContains('runtime-execution-boundary:1', $result['checks']);
        self::assertNotContains('WPrism\\WpCliChildProcess', AdapterPackageValidator::runtimeSdk()['symbols']);
    }

    public function testValidatorRejectsRuntimeDependencyOutsideTheVersionedSdk(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        $source = (string) file_get_contents($path);
        self::write(
            $path,
            str_replace(
                "namespace WPrism\\Providers;\n",
                "namespace WPrism\\Providers;\n\nuse WPrism\\RepositoryCompiler;\n",
                $source
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "depends on non-SDK WPrism symbol 'WPrism\\RepositoryCompiler' at package/runtime/providers/validator-probe.php"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testRuntimeClassConstantsAreMembersNotNamespaceDependencies(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write($path, (string) file_get_contents($path) . <<<'PHP'

class LocalConstantReader {
    private const META_KEY = 'owned';
    public static function read(string $key): array {
        $rows = [];
        if ($key === self::META_KEY) $rows[] = $key;
        return $rows;
    }
}
PHP);
        $result = AdapterPackageValidator::validate($root, 'acf');
        self::assertContains('runtime-sdk:wprism-adapter-runtime-sdk/v2', $result['checks']);

        self::write($path, (string) file_get_contents($path) . "\n\\WPrism\\RepositoryCompiler::META_KEY;\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("depends on non-SDK WPrism symbol 'WPrism\\RepositoryCompiler'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function hiddenRuntimeSdkDependencies(): iterable
    {
        yield 'root namespace unqualified name' => [
            "\nnamespace WPrism;\nRepositoryCompiler::compile();\n",
        ];
        yield 'root namespace constructor' => [
            "\nnamespace WPrism;\nfunction hiddenSdkConstructor(): void { new RepositoryCompiler(); }\n",
        ];
        yield 'root namespace return type' => [
            "\nnamespace WPrism;\nfunction hiddenSdkReturnType(): RepositoryCompiler {}\n",
        ];
        yield 'namespace-relative name' => [
            "\nnamespace WPrism;\nnamespace\\RepositoryCompiler::compile();\n",
        ];
        yield 'deeper WPrism namespace unqualified name' => [
            "\nnamespace WPrism\\Repository;\nCompiledArtifactReader::load();\n",
        ];
        yield 'dynamic class string' => [
            "\n\$class = 'WPrism\\\\RepositoryCompiler';\n\$class::compile();\n",
        ];
        yield 'concatenated dynamic class string' => [
            "\n\$class = 'WPrism' . '\\\\RepositoryCompiler';\n\$class::compile();\n",
        ];
        yield 'variable-separated dynamic class string' => [
            "\n\$root = 'WPrism';\n\$internal = 'RepositoryCompiler';\n"
                . "\$class = \$root . '\\\\' . \$internal;\n\$class::compile();\n",
        ];
        yield 'computed namespace separator in dynamic class string' => [
            "\n\$class = 'WPrism' . chr(92) . 'RepositoryCompiler';\n\$class::compile();\n",
        ];
        yield 'case-variant fully-qualified name' => [
            "\n\\wprism\\RepositoryCompiler::compile();\n",
        ];
    }

    #[DataProvider('hiddenRuntimeSdkDependencies')]
    public function testValidatorRejectsSdkDependenciesHiddenByPhpNameForms(string $mutation): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write($path, (string) file_get_contents($path) . $mutation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('depends on non-SDK WPrism symbol');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function unresolvedDynamicClassDispatches(): iterable
    {
        yield 'static dispatch' => ["\n\$class = chr(68) . 'uo\\\\RepositoryCompiler';\n\$class::compile();\n"];
        yield 'new dispatch' => ["\n\$class = chr(68) . 'uo\\\\RepositoryCompiler';\nnew \$class();\n"];
        yield 'reflection dispatch' => [
            "\n\$class = chr(68) . 'uo\\\\RepositoryCompiler';\nnew \\ReflectionClass(\$class);\n",
        ];
        yield 'named-function parameter' => [
            "\nfunction unresolvedNamedParameter(\$class): object { return new \$class(); }\n",
        ];
        yield 'attributed named-function parameter' => [
            "\nfunction unresolvedAttributedParameter(#[\\SensitiveParameter] \$class): object {"
                . " return new \$class(); }\n",
        ];
        yield 'closure parameter' => [
            "\n\$factory = static function (\$class): object { return new \$class(); };\n",
        ];
        yield 'arrow-function parameter' => [
            "\n\$factory = static fn (\$class): mixed => \$class::compile();\n",
        ];
        yield 'reflection closure parameter' => [
            "\n\$reflect = static function (\$class): \\ReflectionClass {"
                . " return new \\ReflectionClass(\$class); };\n",
        ];
        yield 'widened literal allowlist' => [
            "\nfunction widenedAllowlist(\$class, \$fallback): object {"
                . " if (in_array(\$class, ['Plugin_Service'], true) || \$fallback) { return new \$class(); }"
                . " return new \\stdClass(); }\n",
        ];
        yield 'non-dominating object guard' => [
            "\nfunction nestedObjectGuard(\$service, \$maybe): \\ReflectionClass {"
                . " if (\$maybe) { if (!is_object(\$service)) { throw new \\RuntimeException('object'); } }"
                . " return new \\ReflectionClass(\$service); }\n",
        ];
    }

    #[DataProvider('unresolvedDynamicClassDispatches')]
    public function testValidatorRejectsUnresolvedDynamicClassDispatch(string $mutation): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write($path, (string) file_get_contents($path) . $mutation);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses unresolved dynamic class dispatch');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAllowsAnUnresolvedDynamicStringThatIsNotUsedAsAClass(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path) . "\n\$label = chr(68) . 'uo runtime label';\necho \$label;\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRestoresAResolvedOuterDynamicClassAfterANamedFunctionScope(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path)
                . "\n\$class = 'stdClass';\n"
                . "function mutateOnlyLocalClass(\$value): void { \$class = \$value; echo \$class; }\n"
                . "new \$class();\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorCarriesResolvedClassStateIntoClosureAndArrowCaptures(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path)
                . "\n\$class = 'stdClass';\n"
                . "\$closure = static function () use (\$class): object { return new \$class(); };\n"
                . "\$arrow = static fn (): object => new \$class();\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorAcceptsObjectInspectionAndLiteralPluginClassNarrowing(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path)
                . "\nfunction inspectTypedObject(object \$service): \\ReflectionClass {"
                . " return new \\ReflectionClass(\$service); }\n"
                . 'function inspectGuardedObject(mixed $service): \\ReflectionClass {'
                . " if (!is_object(\$service)) { throw new \\RuntimeException('object required'); }"
                . " return new \\ReflectionClass(\$service); }\n"
                . 'function invokeAllowedPluginClass(string $class): object {'
                . " if (in_array(\$class, ['Plugin_Service'], true)) { return new \$class(); }"
                . " return new \\stdClass(); }\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRejectsCapsuleClassDeclaredInAnEngineInternalNamespace(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            str_replace(
                'namespace WPrism\\Providers;',
                'namespace WPrism\\Repository;',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("outside owned namespace 'WPrism\\Providers'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsDynamicReferencesToSdkAndCapsuleOwnedClasses(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path)
                . "\nis_callable(['\\\\WPrism\\\\Policy', 'load']);\n"
                . "is_callable(['\\\\WPrism\\\\Interpreters\\\\Acf', 'tokens']);\n"
                . "namespace WPrism;\nuse WPrism\\Policy as SdkPolicy;\n"
                . "function sdkReturnType(): SdkPolicy {}\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    /** @return iterable<string,array{0:string}> */
    public static function unresolvedWPrismImports(): iterable
    {
        yield 'grouped import' => ['use WPrism\\{Policy, RepositoryCompiler};'];
        yield 'root alias' => ['use WPrism as Engine;'];
    }

    #[DataProvider('unresolvedWPrismImports')]
    public function testValidatorRejectsSdkImportSyntaxThatCouldHideAnExactSymbol(string $import): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        $source = (string) file_get_contents($path);
        self::write(
            $path,
            str_replace("namespace WPrism\\Providers;\n", "namespace WPrism\\Providers;\n\n$import\n", $source)
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses an unresolved grouped or root WPrism import');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testSdkScannerIgnoresDependencyShapedCommentsAndRuntimeLineCounts(): void
    {
        $root = $this->validatorFixture();
        $path = $this->runtimeProbe($root);
        self::write(
            $path,
            (string) file_get_contents($path) . "\n// use WPrism\\{RepositoryCompiler}; is documentation, not an import.\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertFileDoesNotExist($root . '/tools/adapter-executable-inventory.json');
    }

    public function testValidatorRejectsMalformedPackageArtifactEvidence(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/artifacts.lock.json', "{}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Artifact fragment must contain exactly plugins and themes');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsArtifactSubjectsNotOwnedByTheManifest(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/artifacts.lock.json';
        $artifacts = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($artifacts);
        $artifacts['plugins']['woocommerce'] = $artifacts['plugins']['advanced-custom-fields'];
        self::write(
            $path,
            json_encode($artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("must own exactly its manifest plugin 'advanced-custom-fields'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testCertifiedPackageCannotOmitItsVersionMatrix(): void
    {
        $root = $this->validatorFixture();
        self::assertTrue(unlink($root . '/adapter-packages/acf/tests/certify/version-matrix.sh'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must own tests/certify/version-matrix.sh');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testArtifactRolesMustAgreeWithTheDeclaredVersionRange(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/artifacts.lock.json';
        $artifacts = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($artifacts);
        $artifacts['plugins']['advanced-custom-fields']['5.12.6']['role'] = 'certified-boundary';
        self::write(
            $path,
            json_encode($artifacts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('pins certified boundary 5.12.6 outside its declared version_range');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testEveryArtifactPinMustAppearInActiveMatrixSource(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write($path, str_replace('5.12.6', '5.12.5', (string) file_get_contents($path)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact pin 5.12.6 is absent from active certified workflow source');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testArtifactPinMentionedOnlyInAPhpCommentIsNotExecutableEvidence(): void
    {
        $root = $this->validatorFixture();
        $matrix = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write($matrix, str_replace('5.12.6', '5.12.5', (string) file_get_contents($matrix)));
        $evidence = $root . '/adapter-packages/acf/tests/offline/regress_acf_meta_interpreter.php';
        self::write($evidence, (string) file_get_contents($evidence) . "\n// Refusal artifact 5.12.6.\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact pin 5.12.6 is absent from active certified workflow source');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsIncompletePackageReadinessSemantics(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/production-readiness.json';
        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        unset($record['not_applicable']['derived-state']);
        self::write($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not account for every scenario family');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function unclosedReadinessBuckets(): iterable
    {
        yield 'missing evidence' => ['gaps'];
        yield 'missing primitive' => ['blocked'];
    }

    #[DataProvider('unclosedReadinessBuckets')]
    public function testCertifiedPackageCannotRetainUnclosedReadiness(string $bucket): void
    {
        $root = $this->validatorFixture();
        self::openReadinessFamily($root, $bucket);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Certified adapter package 'acf' must have ready production-readiness evidence");
        AdapterPackageValidator::validate($root, 'acf');
    }

    #[DataProvider('unclosedReadinessBuckets')]
    public function testExperimentalPackageCanRetainExplicitUnclosedReadiness(string $bucket): void
    {
        $root = $this->validatorFixture();
        self::openReadinessFamily($root, $bucket);
        $path = $root . '/adapter-packages/acf/package/disposition.json';
        $disposition = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($disposition);
        $disposition['status'] = 'experimental';
        self::write($path, json_encode($disposition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('production-readiness', $result['checks']);
    }

    public function testValidatorRejectsReadinessEvidenceFromASiblingCapsule(): void
    {
        $root = $this->validatorFixture();
        $evidence = 'adapter-packages/woocommerce/tests/offline/regress_sibling.php';
        self::write($root . '/' . $evidence, "<?php\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence cites a sibling adapter capsule');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsReadinessEvidenceFromAnUndeclaredIntegrationScenario(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture(
            $root,
            'polylang-tec-rewrite-coinstall',
            ['polylang', 'the-events-calendar']
        );
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("cites undeclared integration scenario 'polylang-tec-rewrite-coinstall'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsReadinessEvidenceFromADeclaredIntegrationScenario(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('production-readiness', $result['checks']);
    }

    public function testDeclaredScenarioDoesNotMakePackageValidationReadAParticipantSibling(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );
        self::write($root . '/adapter-packages/woocommerce/package/manifest.json', "{not-json\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('production-readiness', $result['checks']);
    }

    public function testValidatorRejectsExternalEvidenceFromAnUndeclaredIntegrationScenario(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture(
            $root,
            'polylang-tec-rewrite-coinstall',
            ['polylang', 'the-events-calendar']
        );
        self::addExternalEvidence($root, 'regress-polylang-tec-rewrite-coinstall', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("cites undeclared integration scenario 'polylang-tec-rewrite-coinstall'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testDeclaredExternalScenarioDoesNotReadAParticipantSiblingManifest(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::addExternalEvidence($root, 'regress-acf-woocommerce-coinstall', $evidence);
        self::write($root . '/adapter-packages/woocommerce/package/manifest.json', "{not-json\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
        self::assertContains('regress-acf-woocommerce-coinstall', $result['evidence_tests']);
    }

    public function testExternalScenarioCannotAliasReservedLocalEvidence(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::addExternalEvidence($root, 'exact-artifact-version-matrix', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must equal its scenario gate basename');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testExternalScenarioEvidenceKeyIsDerivedFromItsGateBasename(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::addExternalEvidence($root, 'regress-invented-alias', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "key 'regress-invented-alias' must equal its scenario gate basename 'regress-acf-woocommerce-coinstall'"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testExternalScenarioCannotCollideWithAPackageLocalSuite(): void
    {
        $root = $this->validatorFixture();
        $evidence = self::scenarioFixture($root, 'acf-woocommerce-coinstall', ['acf', 'woocommerce']);
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_acf_woocommerce_coinstall.php',
            "<?php\n"
        );
        self::addExternalEvidence($root, 'regress-acf-woocommerce-coinstall', $evidence);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("external evidence collides with local test 'regress-acf-woocommerce-coinstall'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function sharedCaptureAndPolicyEvidence(): iterable
    {
        yield 'capture atomicity' => ['sandbox/tests/offline/capture/regress_capture_atomicity.php'];
        yield 'platform compatibility' => ['sandbox/tests/offline/policy/regress_platform_compatibility.php'];
    }

    #[DataProvider('sharedCaptureAndPolicyEvidence')]
    public function testValidatorAcceptsSharedCaptureAndPolicyReadinessEvidence(string $evidence): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/' . $evidence, "<?php\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertContains('production-readiness', $result['checks']);
    }

    public function testValidatorRejectsReadinessEvidenceOutsideClosedSharedRoots(): void
    {
        $root = $this->validatorFixture();
        $evidence = 'docs/adapter-evidence.php';
        self::write($root . '/' . $evidence, "<?php\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence is outside the closed shared evidence roots');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsReadinessEvidenceBorrowedFromAnUnrelatedAdapterSuite(): void
    {
        $root = $this->validatorFixture();
        $evidence = 'sandbox/tests/offline/adapter/regress_rank_math_adapter.php';
        self::write($root . '/' . $evidence, "<?php\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence is outside the closed shared evidence roots');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsReadinessCoverageSatisfiedByThePackageReadme(): void
    {
        $root = $this->validatorFixture();
        $evidence = 'adapter-packages/acf/README.md';
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence is not a recognized owned evidence asset');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidOwnedReadinessAssets(): iterable
    {
        yield 'test documentation' => ['tests/offline/README.md'];
        yield 'fixture documentation' => ['fixtures/README.md'];
    }

    #[DataProvider('invalidOwnedReadinessAssets')]
    public function testValidatorRejectsReadinessCoverageUsingAnUnvalidatedOwnedAsset(string $relative): void
    {
        $root = $this->validatorFixture();
        $evidence = 'adapter-packages/acf/' . $relative;
        self::write($root . '/' . $evidence, "not executable evidence\n");
        self::replaceReadinessEvidence(
            $root,
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
            $evidence
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readiness evidence is not a recognized owned evidence asset');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function processGlobalLibrarySelections(): iterable
    {
        yield 'runtime getenv' => [
            'package/runtime/interpreters/acf.php',
            "\ngetenv('WPRISM_MANIFESTS_DIR');\n",
        ];
        yield 'test putenv' => [
            'tests/offline/regress_global_library.php',
            "<?php putenv('WPRISM_MANIFESTS_DIR=/tmp/legacy');\n",
        ];
        yield 'shell assignment' => [
            'tests/live/regress_global_library.sh',
            "#!/usr/bin/env bash\nWPRISM_MANIFESTS_DIR=/tmp/legacy\n",
        ];
        yield 'test text outside a negative assertion' => [
            'tests/offline/regress_global_library_text.php',
            "<?php \$legacySelector = 'WPRISM_MANIFESTS_DIR';\n",
        ];
        yield 'selection beside a negative assertion' => [
            'tests/offline/regress_global_library_mixed.php',
            "<?php putenv('WPRISM_MANIFESTS_DIR=/tmp/legacy'); assert(!str_contains('', 'WPRISM_MANIFESTS_DIR'));\n",
        ];
        yield 'concatenated selector spelling' => [
            'tests/offline/regress_global_library_concatenated.php',
            "<?php getenv('WPRISM_' . 'MANIFESTS_DIR');\n",
        ];
    }

    #[DataProvider('processGlobalLibrarySelections')]
    public function testValidatorRejectsProcessGlobalLibrarySelection(string $relative, string $bytes): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/' . $relative;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path) . $bytes;
        }
        self::write($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("selects a manifest library through WPRISM_MANIFESTS_DIR at $relative");
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function staleRelativeAgentDependencies(): iterable
    {
        yield 'runtime PHP' => [
            'package/runtime/interpreters/acf.php',
            "\nrequire_once __DIR__ . '/../../agent/src/Kernel/Canon.php';\n",
        ];
        yield 'offline PHP' => [
            'tests/offline/regress_stale_agent_path.php',
            "<?php require_once __DIR__ . '/../../agent/src/Kernel/Canon.php';\n",
        ];
        yield 'live shell' => [
            'tests/live/regress_stale_agent_path.sh',
            "#!/usr/bin/env bash\nSOURCE=../../agent/src/Kernel/Canon.php\n",
        ];
    }

    #[DataProvider('staleRelativeAgentDependencies')]
    public function testValidatorRejectsStaleRelativeAgentDependency(string $relative, string $bytes): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/' . $relative;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path) . $bytes;
        }
        self::write($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("has a legacy relative agent dependency at $relative");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAllowsNegativeAssertionsAndCorrectCapsuleRelativeTestDependencies(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_dependency_boundary.php',
            <<<'PHP'
<?php
$source = '';
assert(!str_contains($source, 'WPRISM_MANIFESTS_DIR'));
assert(!str_contains($source, '../../agent/src'));
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
PHP
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertNotEmpty(array_filter(
            $result['checks'],
            static fn(string $check): bool => str_starts_with($check, 'dependency-boundary:')
        ));
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function siblingCapsuleSourceReferences(): iterable
    {
        yield 'runtime PHP string' => [
            'package/runtime/interpreters/acf.php',
            "\nfile_get_contents(__DIR__ . '/../../../../adapter-packages/woocommerce/package/manifest.json');\n",
        ];
        yield 'runtime cwd-relative sibling' => [
            'package/runtime/interpreters/acf.php',
            "\nfile_get_contents('../woocommerce/package/manifest.json');\n",
        ];
        yield 'offline PHP string' => [
            'tests/offline/regress_sibling_package.php',
            "<?php file_get_contents(__DIR__ . '/../../../../adapter-packages/woocommerce/package/manifest.json');\n",
        ];
        yield 'offline cwd-relative sibling' => [
            'tests/offline/regress_relative_sibling.php',
            "<?php file_get_contents('../woocommerce/package/manifest.json');\n",
        ];
        yield 'offline concatenated cwd-relative sibling' => [
            'tests/offline/regress_concatenated_relative_sibling.php',
            "<?php file_get_contents('..' . '/woocommerce/package/manifest.json');\n",
        ];
        yield 'offline file-relative sibling' => [
            'tests/offline/regress_file_relative_sibling.php',
            "<?php file_get_contents(__DIR__ . '/../../../woocommerce/package/manifest.json');\n",
        ];
        yield 'live shell command' => [
            'tests/live/regress_sibling_package.sh',
            "#!/usr/bin/env bash\nphp adapter-packages/woocommerce/tests/offline/regress_woocommerce_contract.php\n",
        ];
        yield 'fixture PHP string' => [
            'fixtures/sibling_package.php',
            "<?php file_get_contents(__DIR__ . '/../../../adapter-packages/woocommerce/package/manifest.json');\n",
        ];
        yield 'fixture cwd-relative sibling' => [
            'fixtures/cwd_relative_sibling.php',
            "<?php file_get_contents('../woocommerce/package/manifest.json');\n",
        ];
        yield 'fixture file-relative sibling' => [
            'fixtures/relative_sibling.php',
            "<?php file_get_contents(__DIR__ . '/../../woocommerce/package/manifest.json');\n",
        ];
    }

    #[DataProvider('siblingCapsuleSourceReferences')]
    public function testValidatorRejectsSiblingCapsuleReferencesInPackageSources(
        string $relative,
        string $bytes
    ): void {
        $root = $this->validatorFixture();
        self::makeDirectory($root . '/adapter-packages/woocommerce');
        $path = $root . '/adapter-packages/acf/' . $relative;
        if (is_file($path)) {
            $bytes = (string) file_get_contents($path) . $bytes;
        }
        self::write($path, $bytes);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "references sibling adapter package 'woocommerce' at $relative"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsExecutableFixtureWithAnUnrecognizedExtension(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/fixtures/sibling.inc',
            "<?php file_get_contents('..' . '/woocommerce/package/manifest.json');\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'has executable source with an unsupported extension at fixtures/sibling.inc'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorScansExecutableEvidenceHelpersForSiblingDependencies(): void
    {
        $root = $this->validatorFixture();
        self::makeDirectory($root . '/adapter-packages/woocommerce');
        self::write(
            $root . '/adapter-packages/acf/evidence/helper.php',
            "<?php file_get_contents('../../woocommerce/package/manifest.json');\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "references sibling adapter package 'woocommerce' at evidence/helper.php"
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAShebanglessDataFileSourcedByAPackageTest(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/evidence/helper.txt',
            "file_get_contents ../../woocommerce/package/manifest.json\n"
        );
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_sourced_data.sh',
            "#!/usr/bin/env bash\n. ../../evidence/helper.txt\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'sources a dependency that is not an explicit recognized .sh file at '
                . 'tests/offline/regress_sourced_data.sh'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsABareShebanglessDataFileSourcedByAPackageTest(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/tests/offline/helper.txt', "echo bypass\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_sourced_bare_data.sh',
            "#!/usr/bin/env bash\nsource helper.txt\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file at tests/offline/regress_sourced_bare_data.sh');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function staticallySpelledShellSourceCommands(): iterable
    {
        yield 'assignment-prefixed dot' => ['MODE=x . ../../evidence/helper.txt'];
        yield 'assignment-prefixed source' => ['MODE=x source ../../evidence/helper.txt'];
        yield 'assignment-prefixed source after control word' => [
            'if MODE=x source ../../evidence/helper.txt; then :; fi',
        ];
        yield 'empty-quoted command fragment' => ["sou''rce ../../evidence/helper.txt"];
        yield 'escaped command character' => ['sour\ce ../../evidence/helper.txt'];
        yield 'assignment-prefixed empty-quoted command fragment' => [
            "MODE=x sou''rce ../../evidence/helper.txt",
        ];
    }

    #[DataProvider('staticallySpelledShellSourceCommands')]
    public function testValidatorRejectsStaticallySpelledShellSourceCommands(string $command): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/helper.txt', "echo bypass\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_static_source.sh',
            "#!/usr/bin/env bash\n$command\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'not an explicit recognized .sh file at tests/offline/regress_static_source.sh'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function assignmentPrefixedNonSourceCommands(): iterable
    {
        yield 'source is an argument' => ["MODE=x printf '%s\\n' source"];
        yield 'source is only a command-name prefix' => ['MODE=x source_map=unchanged'];
        yield 'dot is an argument' => ["MODE=x printf '%s\\n' ."];
        yield 'double-quoted backslash is literal before an ordinary byte' => ['MODE=x "sour\ce"'];
        yield 'single-quoted expansion syntax is literal' => ["MODE=x sou'\$x'rce"];
    }

    #[DataProvider('assignmentPrefixedNonSourceCommands')]
    public function testValidatorAllowsAssignmentPrefixedNonSourceCommands(string $command): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_non_source_command.sh',
            "#!/usr/bin/env bash\n$command\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRejectsAnUnresolvedVariableOnlySourceTarget(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/helper.txt', "echo bypass\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_dynamic_source.sh',
            "#!/usr/bin/env bash\nHELPER=../../evidence/helper.txt\n. \"\$HELPER\"\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'not an explicit recognized .sh file at tests/offline/regress_dynamic_source.sh'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAConditionalSourceCommand(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/helper.txt', "echo bypass\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_conditional_source.sh',
            "#!/usr/bin/env bash\nif source ../../evidence/helper.txt; then :; fi\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsASourceCommandInsideACommandGroup(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/helper.txt', "echo bypass\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_grouped_source.sh',
            "#!/usr/bin/env bash\n{ source ../../evidence/helper.txt; }\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAnAbsoluteOutsideShellSource(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_absolute_source.sh',
            "#!/usr/bin/env bash\n. /tmp/outside.sh\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsATokenSplicedSourceCommand(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/evidence/helper.txt', "echo bypass\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_spliced_source.sh',
            "#!/usr/bin/env bash\nsou\\\nrce ../../evidence/helper.txt\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsACanonicalPackageRelativeShellSource(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/fixtures/helper.sh', "#!/usr/bin/env bash\n:\n");
        self::write(
            $root . '/adapter-packages/acf/tests/conformance/regress_source.sh',
            <<<'SH'
#!/usr/bin/env bash
. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/helper.sh"
SH
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    /** @return array<string,array{string}> */
    public static function reviewedPrivateEvidenceSources(): array
    {
        return [
            'private capture' => ['tests/lib/private_command_capture.sh'],
            'disposable pair ownership' => ['tests/lib/pair_live_ownership.sh'],
            'native conformance diagnostics' => ['tests/lib/conformance_private_command.sh'],
            'native cron window' => ['tests/lib/wordpress_cron_window.sh'],
        ];
    }

    #[DataProvider('reviewedPrivateEvidenceSources')]
    public function testValidatorAcceptsTheReviewedPrivateCommandCaptureSource(string $source): void
    {
        $root = $this->validatorFixture();
        $shared = 'sandbox/' . $source;
        self::write($root . '/' . $shared, (string) file_get_contents(dirname(__DIR__, 2) . '/' . $shared));
        self::write(
            $root . '/adapter-packages/acf/fixtures/private-capture.sh',
            "#!/usr/bin/env bash\n. $source\n"
        );
        self::assertSame('acf', AdapterPackageValidator::validate($root, 'acf')['adapter']);
    }

    #[DataProvider('reviewedPrivateEvidenceSources')]
    public function testValidatorRefusesAnAbsentReviewedPrivateCommandCaptureSource(string $source): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/fixtures/private-capture.sh',
            "#!/usr/bin/env bash\n. $source\n"
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testReviewedPrivateCaptureSourceDoesNotAdmitItsNeighbor(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/sandbox/tests/lib/private_neighbor.sh', "#!/usr/bin/env bash\n:\n");
        self::write(
            $root . '/adapter-packages/acf/fixtures/private-capture.sh',
            "#!/usr/bin/env bash\n. tests/lib/private_neighbor.sh\n"
        );
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an explicit recognized .sh file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsAnAssignmentPrefixedCanonicalPackageRelativeShellSource(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/fixtures/helper.sh', "#!/usr/bin/env bash\n:\n");
        self::write(
            $root . '/adapter-packages/acf/tests/conformance/regress_prefixed_source.sh',
            <<<'SH'
#!/usr/bin/env bash
MODE=x . "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/helper.sh"
SH
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRejectsExecutablePhpHiddenInTheCapsuleReadme(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/README.md',
            "# ACF\n\n<?php require __DIR__ . '/../woocommerce/package/manifest.json';\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'has executable source outside recognized package code roots at README.md'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsARootHelperRequiredByAPackageTest(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/helper.php', "<?php\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_root_helper.php',
            "<?php require __DIR__ . '/../../helper.php';\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'has executable source outside recognized package code roots at helper.php'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsANestedHelperOutsideRecognizedCodeRoots(): void
    {
        $root = $this->validatorFixture();
        self::write($root . '/adapter-packages/acf/notes/helper.php', "<?php\n");
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_nested_helper.php',
            "<?php require __DIR__ . '/../../notes/helper.php';\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'has executable source outside recognized package code roots at notes/helper.php'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsSymlinkedCapsuleDependencyNodes(): void
    {
        $root = $this->validatorFixture();
        self::assertTrue(symlink(
            $root . '/adapter-packages/acf/package/manifest.json',
            $root . '/adapter-packages/acf/fixtures/linked-helper.php'
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains a symlink or non-ordinary node at fixtures/linked-helper.php');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsSpecialCapsuleDependencyNodes(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is unavailable');
        }
        $root = $this->validatorFixture();
        self::assertTrue(posix_mkfifo($root . '/adapter-packages/acf/evidence/helper.php', 0600));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains a symlink or non-ordinary node at evidence/helper.php');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAComputedAdapterPackagesRoot(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_computed_package.php',
            "<?php file_get_contents(__DIR__ . '/../../../../adapter-packages/' . 'woocommerce/package/manifest.json');\n"
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has an unresolved adapter-packages path');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorIgnoresSiblingPackageReferencesInComments(): void
    {
        $root = $this->validatorFixture();
        self::write(
            $root . '/adapter-packages/acf/tests/offline/regress_package_comment.php',
            "<?php\n// adapter-packages/woocommerce is historical context, not a dependency.\n"
        );
        self::write(
            $root . '/adapter-packages/acf/tests/live/regress_package_comment.sh',
            "#!/usr/bin/env bash\n# adapter-packages/woocommerce is historical context, not a dependency.\n"
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRequiresAPackagePremiseContractWhenItsTestsUsePremiseHelpers(): void
    {
        $root = $this->validatorFixture();
        self::assertTrue(unlink(
            $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv'
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('uses target-observation premise helpers but owns no target-observation-premises.tsv');
        AdapterPackageValidator::validate($root, 'acf');
    }

    #[DataProvider('jsonCapturePremiseHelpers')]
    public function testValidatorTreatsJsonCaptureHelpersAsPremiseHelpers(string $helper, string $directory): void
    {
        $package = $this->package('probe', false);
        self::makeDirectory($package . '/' . $directory);
        self::write(
            $package . '/' . $directory . '/check.sh',
            "#!/usr/bin/env bash\n$helper OUT 'probe answered' fake_wprism\n"
        );
        $checks = [];
        $arguments = [$this->root, $package, 'probe', [], &$checks];
        $premiseEvidence = new \ReflectionMethod(AdapterPackageValidator::class, 'premiseEvidence');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            "Adapter package 'probe' uses target-observation premise helpers but owns no target-observation-premises.tsv"
        );
        $premiseEvidence->invokeArgs(null, $arguments);
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function jsonCapturePremiseHelpers(): iterable
    {
        foreach (['tests/conformance', 'fixtures'] as $directory) {
            yield $directory . ' successful JSON answer' => ['capture_wprism_json_success', $directory];
            yield $directory . ' JSON refusal' => ['capture_wprism_json_refusal', $directory];
        }
    }

    public function testValidatorAcceptsPremisesMovedIntoReusableCapsuleFixtures(): void
    {
        $root = $this->validatorFixture();
        $capsule = $root . '/adapter-packages/acf';
        $contract = $capsule . '/evidence/target-observation-premises.tsv';
        $bytes = (string) file_get_contents($contract);
        foreach (['seed', 'check'] as $hook) {
            self::write($capsule . '/fixtures/' . $hook . '.sh', (string) file_get_contents($capsule . '/tests/conformance/' . $hook . '.sh'));
            self::write($capsule . '/tests/conformance/' . $hook . '.sh',
                '#!/usr/bin/env bash' . "\n" . '. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/' . $hook . '.sh"' . "\n");
            $bytes = str_replace('tests/conformance/' . $hook . '.sh', 'fixtures/' . $hook . '.sh', $bytes);
        }
        self::write($contract, $bytes);
        $result = AdapterPackageValidator::validate($root, 'acf');
        self::assertContains('premise-evidence:2', $result['checks']);

        self::write($capsule . '/fixtures/check.sh', "#!/usr/bin/env bash\n# Removed active assertion\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing active premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAStalePackagePremiseAssertion(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        self::write(
            $path,
            str_replace(
                'require_observed_nonempty "conf2 ACF runtime observation"',
                'require_observed_nonempty "stale ACF runtime observation"',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing active premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsADeletedPackagePremiseRow(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        self::write(
            $path,
            str_replace(
                "observation\ttests/conformance/seed.sh\trequire_observed_nonempty \"conf1 ACF seed output\"\n",
                '',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expected-count mismatch (2/0 declared, 1/0 found)');
        AdapterPackageValidator::validate($root, 'acf');
    }

    #[DataProvider('invalidPremisePaths')]
    public function testValidatorRejectsAPackagePremisePathEscape(string $invalid): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        self::write(
            $path,
            str_replace(
                'tests/conformance/check.sh',
                $invalid,
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("names invalid premise source '$invalid'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidPremisePaths(): iterable
    {
        yield 'outside capsule' => ['../outside.sh'];
        yield 'fixture traversal' => ['fixtures/../tests/conformance/check.sh'];
        yield 'non-shell fixture' => ['fixtures/check.txt'];
    }

    public function testValidatorRejectsACertificationPremiseOwnedByAnotherParticipant(): void
    {
        $root = $this->validatorFixture();
        $relative = '@repo/sandbox/tests/certify/certify_deletion_matrix.sh';
        self::write(
            $root . '/' . substr($relative, strlen('@repo/')),
            <<<'SH'
#!/usr/bin/env bash
WPRISM_CERTIFICATION_MANIFESTS_JSON='["core","woocommerce"]'
cat >site.wprism.json <<'JSON'
{"manifests":["core","woocommerce"]}
JSON
require_observed_nonempty "target WooCommerce order-product lookup count" "$LOOKUP_ROWS"
SH
        );
        self::addPremiseRow(
            $root,
            'observation',
            $relative,
            'require_observed_nonempty "target WooCommerce order-product lookup count"'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without an active canonical manifest participant declaration');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorAcceptsACertificationPremiseForADeclaredParticipant(): void
    {
        $root = $this->validatorFixture();
        $relative = '@repo/sandbox/tests/certify/certify_acf_contract.sh';
        self::write(
            $root . '/' . substr($relative, strlen('@repo/')),
            <<<'SH'
#!/usr/bin/env bash
WPRISM_CERTIFICATION_MANIFESTS_JSON='["acf","core"]'
cat >site.wprism.json <<'JSON'
{"manifests":["acf","core"]}
JSON
require_observed_nonempty "target ACF contract" "$ACF_CONTRACT" # wprism-premise-owner: acf
SH
        );
        self::addPremiseRow(
            $root,
            'observation',
            $relative,
            'require_observed_nonempty "target ACF contract"'
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertContains('premise-evidence:3', $result['checks']);
    }

    public function testValidatorRejectsACertificationPremiseBoundToAnotherDeclaredParticipant(): void
    {
        $root = $this->validatorFixture();
        $relative = '@repo/sandbox/tests/certify/certify_acf_woocommerce_contract.sh';
        self::write(
            $root . '/' . substr($relative, strlen('@repo/')),
            <<<'SH'
#!/usr/bin/env bash
WPRISM_CERTIFICATION_MANIFESTS_JSON='["acf","core","woocommerce"]'
cat >site.wprism.json <<'JSON'
{"manifests":["acf","core","woocommerce"]}
JSON
require_observed_nonempty "target WooCommerce contract" "$WOO_CONTRACT" # wprism-premise-owner: woocommerce
SH
        );
        self::addPremiseRow(
            $root,
            'observation',
            $relative,
            'require_observed_nonempty "target WooCommerce contract"'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("bound to 'woocommerce'");
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsACertificationParticipantDeclaredOnlyInAnInlineComment(): void
    {
        $root = $this->validatorFixture();
        $relative = '@repo/sandbox/tests/certify/certify_acf_contract.sh';
        self::write(
            $root . '/' . substr($relative, strlen('@repo/')),
            <<<'SH'
#!/usr/bin/env bash
: # WPRISM_CERTIFICATION_MANIFESTS_JSON='["acf"]'
require_observed_nonempty "target ACF contract" "$ACF_CONTRACT" # wprism-premise-owner: acf
SH
        );
        self::addPremiseRow($root, 'observation', $relative, 'require_observed_nonempty "target ACF contract"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without an active canonical manifest participant declaration');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsACertificationParticipantDeclaredOnlyInAHeredoc(): void
    {
        $root = $this->validatorFixture();
        $relative = '@repo/sandbox/tests/certify/certify_acf_contract.sh';
        self::write(
            $root . '/' . substr($relative, strlen('@repo/')),
            <<<'SH'
#!/usr/bin/env bash
cat <<'INERT' >/dev/null
WPRISM_CERTIFICATION_MANIFESTS_JSON='["acf"]'
INERT
require_observed_nonempty "target ACF contract" "$ACF_CONTRACT" # wprism-premise-owner: acf
SH
        );
        self::addPremiseRow($root, 'observation', $relative, 'require_observed_nonempty "target ACF contract"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without an active canonical manifest participant declaration');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsACommentedOutPremiseAssertion(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                '    : # require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing active premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAPremiseAssertionPresentOnlyInAHeredoc(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    cat <<'INERT_ACF_PREMISE' >/dev/null\n"
                    . "require_observed_nonempty \"conf2 ACF runtime observation\" \"\$out\"\n"
                    . 'INERT_ACF_PREMISE',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing active premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAnEscapedHeredocThatCouldHideAPremiseAssertion(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    cat <<\\INERT_ACF_PREMISE >/dev/null\n"
                    . "require_observed_nonempty \"conf2 ACF runtime observation\" \"\$out\"\n"
                    . 'INERT_ACF_PREMISE',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported escaped heredoc delimiter');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAPremiseHelperUsedAsAContinuedCommandArgument(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    printf '%s' \\\n"
                    . 'require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing active premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAPremiseAssertionInsideAMultilineQuotedArgument(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    jq -e '\n"
                    . "require_observed_nonempty \"conf2 ACF runtime observation\" \"\$out\"\n"
                    . "' >/dev/null",
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source is missing active premise');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRecognizesAPremiseExecutedInsideAMultilineCommandSubstitution(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    captured=\"\$(\n"
                    . "require_observed_nonempty \"conf2 ACF runtime observation\" \"\$out\"\n"
                    . '    )"',
                (string) file_get_contents($path)
            )
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRetainsNestedMultilineCommandSubstitutionState(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    captured=\"\$(\n"
                    . "        printf '%s' \"\$(\n"
                    . "require_observed_nonempty \"conf2 ACF runtime observation\" \"\$out\"\n"
                    . "        )\"\n"
                    . '    )"',
                (string) file_get_contents($path)
            )
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRejectsUnsupportedCaseGrammarInsideCommandSubstitution(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    captured=\"\$(\n"
                    . "        case x in\n"
                    . "            x) : ;;\n"
                    . "        esac\n"
                    . "        : \"require_observed_nonempty probe\"\n"
                    . '    )"',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported case grammar inside command substitution');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsLegacyBacktickCommandSubstitutionAroundAPremise(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    captured=\"`\n"
                    . "require_observed_nonempty \"conf2 ACF runtime observation\" \"\$out\"\n"
                    . '    `"',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported legacy backtick command substitution');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorKeepsAPremiseActiveAfterArithmeticLeftShift(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/conformance/check.sh';
        self::write(
            $path,
            str_replace(
                '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                "    flags=1\n"
                    . "    flags=\$((flags << 1))\n"
                    . '    require_observed_nonempty "conf2 ACF runtime observation" "$out"',
                (string) file_get_contents($path)
            )
        );

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame('acf', $result['adapter']);
    }

    public function testValidatorRejectsAnUnguardedVersionMatrixObservation(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write(
            $path,
            str_replace(
                'require_fixture_values INSTALLED_2',
                '# removed fixture premise',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INSTALLED_2 assignment/premise mismatch (1/0)');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAVersionMatrixWithoutTheCanonicalWorkflow(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write(
            $path,
            str_replace(
                'version_matrix_workflow() {',
                'renamed_workflow() {',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exactly one canonical version_matrix_workflow()');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsADuplicateCanonicalVersionMatrixWorkflow(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write($path, (string) file_get_contents($path) . "\nversion_matrix_workflow() { :; }\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exactly one canonical version_matrix_workflow()');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorBindsTheVersionMatrixPluginSlugToTheManifest(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write(
            $path,
            str_replace(
                'VMATRIX_PLUGIN_SLUG=advanced-custom-fields',
                'VMATRIX_PLUGIN_SLUG=woocommerce',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'must declare exactly one canonical VMATRIX_PLUGIN_SLUG=advanced-custom-fields'
        );
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAVersionMatrixSlugPresentOnlyInAHeredoc(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write(
            $path,
            str_replace(
                'VMATRIX_PLUGIN_SLUG=advanced-custom-fields',
                "cat <<'INERT_VMATRIX_SLUG' >/dev/null\n"
                    . "VMATRIX_PLUGIN_SLUG=advanced-custom-fields\n"
                    . 'INERT_VMATRIX_SLUG',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exactly one canonical VMATRIX_PLUGIN_SLUG');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testValidatorRejectsAVersionMatrixWorkflowPresentOnlyInAHeredoc(): void
    {
        $root = $this->validatorFixture();
        $path = $root . '/adapter-packages/acf/tests/certify/version-matrix.sh';
        self::write(
            $path,
            str_replace(
                'version_matrix_workflow() {',
                "cat <<'INERT_VMATRIX_WORKFLOW' >/dev/null\n"
                    . "version_matrix_workflow() {\n"
                    . "INERT_VMATRIX_WORKFLOW\n"
                    . 'renamed_workflow() {',
                (string) file_get_contents($path)
            )
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare exactly one canonical version_matrix_workflow()');
        AdapterPackageValidator::validate($root, 'acf');
    }

    public function testCurrentWooVersionMatrixUsesItsPolicyLibraryObject(): void
    {
        $matrix = (string) file_get_contents(
            dirname(__DIR__, 2) . '/adapter-packages/woocommerce/tests/certify/version-matrix.sh'
        );

        self::assertStringContainsString('$sources->file($name, $policy->adapter_library())', $matrix);
        self::assertStringNotContainsString('Policy::manifests_dir()', $matrix);
    }

    public function testValidatorDoesNotInspectSiblingCapsules(): void
    {
        $root = $this->validatorFixture();
        $baseline = AdapterPackageValidator::validate($root, 'acf');
        self::write(
            $root . '/adapter-packages/broken/package/runtime/providers/broken.php',
            "<?php getenv('WPRISM_MANIFESTS_DIR'); this is not PHP;\n"
        );
        self::write($root . '/adapter-packages/broken/package/manifest.json', "{not-json\n");

        $result = AdapterPackageValidator::validate($root, 'acf');

        self::assertSame($baseline, $result);
    }

    public function testValidatorStillInspectsTheSharedPlatformContract(): void
    {
        $root = $this->validatorFixture();
        self::assertTrue(unlink($root . '/platform/adapter-library/capabilities/adapter-authorities.json'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('adapter authorities is not a readable regular file');
        AdapterPackageValidator::validate($root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['ACF'];
        yield 'underscore' => ['bad_slug'];
        yield 'traversal' => ['../acf'];
        yield 'slash' => ['vendor/acf'];
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugFailsClosed(string $slug): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug is not canonical');
        AdapterPackageTestDiscovery::discover($this->root, $slug);
    }

    public function testMissingPackageFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("package 'acf' is missing");
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testMissingOfflineDirectoryFailsClosed(): void
    {
        $package = $this->package('acf', false);
        self::write($package . '/tests/live/regress_live.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no tests/offline directory');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testEmptyOfflineDirectoryFailsClosed(): void
    {
        $this->package('acf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no class-named *.php/.sh suites');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testUnknownTestClassEntryFailsClosed(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/fixtures/data.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test class entry');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function unknownTestFiles(): iterable
    {
        yield 'ordinary fixture' => ['data.json'];
        yield 'wrong prefix' => ['test_product.php'];
        yield 'wrong extension' => ['regress_product.txt'];
        yield 'hidden file' => ['.regress_hidden.php'];
        yield 'empty regression name' => ['regress_.php'];
    }

    #[DataProvider('unknownTestFiles')]
    public function testUnknownFileInAClassFailsClosed(string $filename): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/offline/' . $filename, 'unknown');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test file');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testUnknownFileInAnotherClassStillFailsThePackageBoundary(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/live/notes.md', 'not a suite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test file');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testSymlinkedTestFileFailsClosed(): void
    {
        $package = $this->package('acf');
        self::assertTrue(symlink('/etc/hosts', $package . '/tests/offline/regress_escape.php'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testSymlinkedPackageCannotEscapeTheRepository(): void
    {
        $outside = $this->root . '-outside';
        self::makeDirectory($outside . '/tests/offline');
        self::write($outside . '/tests/offline/regress_escape.php', '<?php');
        self::assertTrue(symlink($outside, $this->root . '/adapter-packages/acf'));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not an ordinary directory');
            AdapterPackageTestDiscovery::discover($this->root, 'acf');
        } finally {
            self::removeTree($outside);
        }
    }

    public function testSpecialTestNodeFailsClosed(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is unavailable');
        }
        $package = $this->package('acf');
        self::assertTrue(posix_mkfifo($package . '/tests/offline/regress_fifo.php', 0600));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an ordinary file or directory');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testRunnerExecutesEveryOfflineTestAndAggregatesOutputAndExits(): void
    {
        [$root] = $this->runnerFixture([
            'regress_01_pass.php' => '<?php fwrite(STDOUT, "php-out\\n"); fwrite(STDERR, "php-err\\n");',
            'regress_02_fail.sh' => "#!/usr/bin/env bash\nprintf 'bash-out\\n'\nprintf 'bash-err\\n' >&2\nexit 3\n",
            'nested/regress_03_after.php' => '<?php fwrite(STDOUT, "after-out\\n");',
        ]);

        $run = AdapterPackageTestRunner::run($root, 'acf');

        self::assertSame(AdapterPackageTestRunner::FORMAT, $run['format']);
        self::assertSame('failed', $run['status']);
        self::assertSame(1, $run['exit_code']);
        self::assertSame([
            'adapter-packages/acf/tests/offline/nested/regress_03_after.php',
            'adapter-packages/acf/tests/offline/regress_01_pass.php',
            'adapter-packages/acf/tests/offline/regress_02_fail.sh',
        ], array_column($run['tests'], 'path'));
        self::assertSame([0, 0, 3], array_column($run['tests'], 'exit_code'));
        self::assertSame(["after-out\n", "php-out\n", "bash-out\n"], array_column($run['tests'], 'stdout'));
        self::assertSame(['', "php-err\n", "bash-err\n"], array_column($run['tests'], 'stderr'));
    }

    public function testListAndJsonAreDryRuns(): void
    {
        $package = $this->package('acf');
        $marker = $this->root . '/executed';
        self::write(
            $package . '/tests/offline/regress_marker.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', "ran");'
        );

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--list',
        ]);
        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('php adapter-packages/acf/tests/offline/regress_marker.php', $listed['stdout']);
        self::assertFileDoesNotExist($marker);

        $json = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--json',
        ]);
        self::assertSame(0, $json['status'], $json['stderr']);
        $plan = json_decode($json['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertSame(AdapterPackageTestsCommand::PLAN_FORMAT, $plan['format']);
        self::assertSame('acf', $plan['adapter']);
        self::assertSame([
            'path' => 'adapter-packages/acf/tests/offline/regress_marker.php',
            'runtime' => 'php',
            'command' => ['php', 'adapter-packages/acf/tests/offline/regress_marker.php'],
        ], $plan['tests'][0]);
        self::assertFileDoesNotExist($marker);
    }

    public function testCliExecutionReturnsAggregateFailureAfterRunningLaterTests(): void
    {
        [$root] = $this->runnerFixture([
            'regress_01_fail.sh' => "exit 9\n",
            'regress_02_after.php' => '<?php echo "after\\n";',
        ]);

        $result = self::invoke(['--repo=' . $root, '--adapter=acf']);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('after', $result['stdout']);
        self::assertStringContainsString('1 passed, 1 failed', $result['stdout']);
        self::assertStringContainsString('exited 9', $result['stderr']);
    }

    public function testRunnerCannotReplacePackageValidationWithOneGreenTest(): void
    {
        $root = $this->validatorFixture();
        $package = $root . '/adapter-packages/acf';
        self::removeTree($package . '/tests/offline');
        self::write($package . '/tests/offline/regress_green.php', '<?php exit(0);');
        self::assertTrue(unlink($package . '/package/manifest.json'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('manifest.json');
        AdapterPackageTestRunner::run($root, 'acf');
    }

    public function testRunnerValidatesArtifactAndReadinessEvidenceBeforeExecutingTests(): void
    {
        $marker = $this->root . '/runner-evidence-marker';
        [$root, $package] = $this->runnerFixture([
            'regress_green.php' => '<?php file_put_contents(' . var_export($marker, true) . ', "ran");',
        ]);
        self::write($package . '/evidence/artifacts.lock.json', "{}\n");

        try {
            AdapterPackageTestRunner::run($root, 'acf');
            self::fail('malformed artifact evidence reached a green package test');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('Artifact fragment must contain exactly plugins and themes', $failure->getMessage());
        }
        self::assertFileDoesNotExist($marker);
    }

    public function testOtherClassesCanBeListedButNotExecuted(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_offline.php', '<?php');
        self::write($package . '/tests/conformance/regress_contract.sh', "exit 0\n");

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=conformance',
            '--list',
        ]);
        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('conformance (1)', $listed['stdout']);

        $run = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=conformance',
        ]);
        self::assertSame(2, $run['status']);
        self::assertStringContainsString('execution is available only for --class=offline', $run['stderr']);
    }

    public function testSpikeUsesItsClassPrefixAndCanBeListed(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_offline.php', '<?php');
        self::write($package . '/tests/spike/spike_acf_probe.sh', "exit 0\n");

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=spike',
            '--list',
        ]);

        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('acf spike (1)', $listed['stdout']);
        self::assertStringContainsString('bash adapter-packages/acf/tests/spike/spike_acf_probe.sh', $listed['stdout']);
    }

    public function testDiscoveryFailureIsNeverRenderedAsAnEmptyGreenList(): void
    {
        $result = self::invoke([
            '--repo=' . $this->root,
            '--adapter=missing',
            '--json',
        ]);

        self::assertSame(1, $result['status']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString("package 'missing' is missing", $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $arguments): array
    {
        $repo = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $repo . '/tools/adapter-package-tests.php', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function package(string $slug, bool $offline = true): string
    {
        $package = $this->root . '/adapter-packages/' . $slug;
        self::makeDirectory($package . '/tests');
        if ($offline) {
            self::makeDirectory($package . '/tests/offline');
        }
        return $package;
    }

    private function validatorFixture(): string
    {
        $repo = dirname(__DIR__, 2);
        $root = $this->root . '/validator';
        self::copyTree($repo . '/adapter-packages/acf', $root . '/adapter-packages/acf');
        self::copyTree($repo . '/platform/adapter-library', $root . '/platform/adapter-library');
        self::makeDirectory($root . '/agent/src');
        self::write($root . '/agent/wprism.php', (string) file_get_contents($repo . '/agent/wprism.php'));
        foreach ([
            'agent/src/Kernel/PlainData.php',
            'sandbox/lib/pair_db.sh',
            'sandbox/tests/live/regress_capture_concurrency.sh',
            'sandbox/tests/live/regress_multisite_refusal.sh',
            'sandbox/tests/offline/apply/regress_fatal_mutations.php',
        ] as $evidence) {
            self::write($root . '/' . $evidence, (string) file_get_contents($repo . '/' . $evidence));
        }
        return $root;
    }

    private function runtimeProbe(string $root): string
    {
        $manifestPath = $root . '/adapter-packages/acf/package/manifest.json';
        $manifest = json_decode(
            (string) file_get_contents($manifestPath),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertIsArray($manifest);
        self::assertIsString($manifest['plugin'] ?? null);
        $manifest['providers'] = [[
            'capabilities' => ['validator_probe'],
            'id' => 'validator-probe',
            'plugin' => $manifest['plugin'],
            'source' => 'manifest',
            'version' => '1.0.0',
        ]];
        self::write(
            $manifestPath,
            (string) json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n"
        );

        $path = $root . '/adapter-packages/acf/package/runtime/providers/validator-probe.php';
        self::write($path, "<?php\n\nnamespace WPrism\\Providers;\n\nfinal class ValidatorProbe {}\n");
        return $path;
    }

    /**
     * @param array<string,string> $tests relative path below tests/offline => bytes
     * @return array{0:string,1:string} fixture root and capsule root
     */
    private function runnerFixture(array $tests): array
    {
        $root = $this->validatorFixture();
        $package = $root . '/adapter-packages/acf';
        self::removeTree($package . '/tests/offline');
        foreach ($tests as $relative => $bytes) {
            self::write($package . '/tests/offline/' . $relative, $bytes);
        }

        $first = array_key_first($tests);
        self::assertIsString($first);
        $readinessPath = $package . '/evidence/production-readiness.json';
        $readiness = json_decode(
            (string) file_get_contents($readinessPath),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        self::assertIsArray($readiness);
        foreach ($readiness['covered'] as &$paths) {
            foreach ($paths as &$path) {
                if (is_string($path) && str_starts_with($path, 'adapter-packages/acf/tests/offline/')) {
                    $path = 'adapter-packages/acf/tests/offline/' . $first;
                }
            }
            unset($path);
            $paths = array_values(array_unique($paths));
        }
        unset($paths);
        self::write(
            $readinessPath,
            json_encode($readiness, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );

        return [$root, $package];
    }

    private static function openReadinessFamily(string $root, string $bucket): void
    {
        $path = $root . '/adapter-packages/acf/evidence/production-readiness.json';
        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        self::assertArrayHasKey('clean-target', $record['covered']);
        unset($record['covered']['clean-target']);
        $record[$bucket]['clean-target'] = 'Deliberately unclosed for the certification-boundary control.';
        $record['readiness'] = 'unready';
        self::write($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }

    private static function replaceReadinessEvidence(string $root, string $from, string $to): void
    {
        $path = $root . '/adapter-packages/acf/evidence/production-readiness.json';
        $record = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $replaced = 0;
        foreach ($record['covered'] as &$paths) {
            foreach ($paths as &$evidence) {
                if ($evidence === $from) {
                    $evidence = $to;
                    $replaced++;
                }
            }
            unset($evidence);
        }
        unset($paths);
        self::assertGreaterThan(0, $replaced);
        self::write(
            $path,
            json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    private static function addPremiseRow(
        string $root,
        string $kind,
        string $relative,
        string $needle
    ): void {
        $path = $root . '/adapter-packages/acf/evidence/target-observation-premises.tsv';
        $bytes = (string) file_get_contents($path);
        $updated = preg_replace_callback(
            '/^# expected observations=([0-9]+) fixtures=([0-9]+)$/m',
            static function (array $match) use ($kind): string {
                $observations = (int) $match[1] + ($kind === 'observation' ? 1 : 0);
                $fixtures = (int) $match[2] + ($kind === 'fixture' ? 1 : 0);
                return "# expected observations=$observations fixtures=$fixtures";
            },
            $bytes,
            1,
            $replacements
        );
        self::assertIsString($updated);
        self::assertSame(1, $replacements);
        self::write($path, rtrim($updated, "\n") . "\n$kind\t$relative\t$needle\n");
    }

    private static function addExternalEvidence(string $root, string $test, string $evidence): void
    {
        $package = $root . '/adapter-packages/acf';
        self::write(
            $package . '/evidence/external-tests.json',
            json_encode([
                'format' => 'wprism-adapter-external-evidence/v1',
                'tests' => [$test => $evidence],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $path = $package . '/package/disposition.json';
        $disposition = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($disposition);
        if (!in_array($test, $disposition['evidence']['tests'], true)) {
            $disposition['evidence']['tests'][] = $test;
        }
        self::write(
            $path,
            json_encode($disposition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
    }

    /** @param list<string> $participants */
    private static function scenarioFixture(string $root, string $scenario, array $participants): string
    {
        foreach ($participants as $participant) {
            $manifest = $root . '/adapter-packages/' . $participant . '/package/manifest.json';
            if (!is_file($manifest)) {
                self::write(
                    $manifest,
                    json_encode(['name' => $participant], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"
                );
            }
        }
        $directory = $root . '/integration-scenarios/' . $scenario;
        self::write(
            $directory . '/scenario.json',
            json_encode([
                'format' => 'wprism-adapter-integration-scenario/v1',
                'participants' => $participants,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
        );
        $gate = 'regress_' . str_replace('-', '_', $scenario) . '.php';
        $evidence = 'integration-scenarios/' . $scenario . '/tests/offline/' . $gate;
        self::write($root . '/' . $evidence, "<?php\n");
        return $evidence;
    }

    private static function copyTree(string $source, string $destination): void
    {
        self::makeDirectory($destination);
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $from = $source . '/' . $entry;
            $to = $destination . '/' . $entry;
            if (is_dir($from) && !is_link($from)) {
                self::copyTree($from, $to);
                continue;
            }
            self::assertFalse(is_link($from));
            self::write($to, (string) file_get_contents($from));
        }
    }

    private static function write(string $path, string $bytes): void
    {
        self::makeDirectory(dirname($path));
        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            self::assertTrue(mkdir($path, 0777, true));
        }
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        $entries = scandir($path);
        if ($entries !== false) {
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
