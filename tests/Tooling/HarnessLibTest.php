<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once WPRISM_REPO_ROOT . '/sandbox/tests/lib/check.php';
require_once WPRISM_REPO_ROOT . '/sandbox/tests/lib/wp_stubs.php';
require_once WPRISM_REPO_ROOT . '/sandbox/tests/lib/FakeWpdb.php';
require_once WPRISM_REPO_ROOT . '/agent/src/Kernel/CommandRefusal.php';

/**
 * Self-tests for sandbox/tests/lib/ -- the harness the offline regress_*.php
 * corpus is meant to standardise on.
 *
 * WHY THESE EXIST AT ALL. The offline corpus is the evidence behind nine
 * certifications. Once suites stop hand-rolling their own fake $wpdb and start
 * sharing one SQL interpreter, a bug in THAT interpreter is a silent,
 * corpus-wide false green: every migrated suite would agree with each other
 * and with nothing real. A hand-rolled fake fails loudly and locally; a shared
 * one has to be tested. So the assertions below pin the properties the suites
 * will rely on -- placeholder rendering, the interpreter over seeded rows, the
 * failure seams that agent/src/Kernel/Db.php reads, and the stubs' WordPress-faithful
 * (not convenient) semantics.
 *
 * These are tooling tests: they may not add a Makefile target, and they do not
 * replace `make regress-offline-all`.
 *
 * OUTPUT DISCIPLINE. wprism_check() prints "ok:" to STDOUT and phpunit.xml.dist
 * sets beStrictAboutOutputDuringTests, so every helper call here is wrapped in
 * an output buffer. wprism_check_summary() calls exit(), so its contract (stream
 * routing plus exit status) is verified in a real subprocess instead.
 */
#[CoversNothing]
final class HarnessLibTest extends TestCase
{
    protected function setUp(): void
    {
        wprism_check_reset();
        WpStore::reset();
    }

    /** Run a lib helper with STDOUT captured, returning what it printed. */
    private function capture(callable $fn): string
    {
        ob_start();

        try {
            $fn();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function seededDb(): FakeWpdb
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_postmeta', [
            ['meta_id' => 1, 'post_id' => 7, 'meta_key' => 'alpha', 'meta_value' => 'one'],
            ['meta_id' => 2, 'post_id' => 7, 'meta_key' => 'beta', 'meta_value' => 'two'],
            ['meta_id' => 3, 'post_id' => 9, 'meta_key' => 'alpha', 'meta_value' => 'three'],
        ]);

        return $db;
    }

    public function testNestedConditionGroupsRemainDistinctFromArithmeticOperands(): void
    {
        $db = $this->seededDb();
        foreach ([
            '((meta_id = 2))' => ['2'],
            '(((meta_id <=> 2)))' => ['2'],
            'post_id = 7 AND ((meta_id = 1 AND OCTET_LENGTH(meta_value) = 3))' => ['1'],
            'post_id = 7 AND ((meta_id = 1) OR ((meta_id = 3)))' => ['1'],
            'NOT (((meta_id = 2)))' => ['1', '3'],
            '((meta_id + 1)) = 3' => ['2'],
            '(meta_id + (1)) = 3' => ['2'],
        ] as $condition => $expected) {
            self::assertSame($expected, $db->get_col("SELECT meta_id FROM wp_postmeta WHERE $condition ORDER BY meta_id"));
        }
    }

    // ------------------------------------------------------ path constants

    /**
     * ABSPATH must be unique per process, and it must not be a path a
     * previous run could have created.
     *
     * The defect this pins: ABSPATH was a fixed
     * `sys_get_temp_dir() . '/wprism-root/'`. wp_stubs.php's contract is that
     * the path is defined and never created, but seven suites DO mkdir under
     * WP_PLUGIN_DIR, so a failed run left the tree on disk -- and
     * AdapterSources::plugin_source() branches on `is_dir(WP_PLUGIN_DIR)`
     * (agent/src/Adapter/AdapterSources.php:1629) to decide whether to call
     * get_option('active_plugins'). Once created, every later suite that
     * called Policy::load() before installing its $wpdb fataled on that host
     * and only that host. A per-pid root makes the inheritance impossible
     * rather than merely discouraged.
     */
    public function testPathConstantsCannotBeInheritedFromAnEarlierRun(): void
    {
        $root = rtrim((string) ABSPATH, '/');

        self::assertMatchesRegularExpression(
            '#/wprism-root-' . getmypid() . '-[0-9a-f]{8}$#D',
            $root,
            'ABSPATH must carry this process id and a random suffix, not a fixed shared name'
        );
        self::assertStringStartsWith(
            rtrim(sys_get_temp_dir(), '/'),
            $root,
            'ABSPATH must stay under the temp dir the shutdown cleanup is scoped to'
        );
        self::assertDirectoryDoesNotExist(
            $root,
            'wp_stubs.php defines the path and must never create it at include time'
        );
        self::assertSame(
            $root . '/wp-content/plugins',
            (string) WP_PLUGIN_DIR,
            'WP_PLUGIN_DIR must hang off the unique root, or the branch above is reachable again'
        );
    }

    /**
     * Including wp_stubs.php must never delete a caller-defined ABSPATH tree.
     *
     * The file currently registers no cleanup at all, so this passes trivially
     * today — it is here because the obvious way to tidy the per-pid root is a
     * shutdown hook, and the obvious way to write that hook is wrong.
     * sandbox/tests/offline/apply/regress_full_apply_attachment_recovery.php:14
     * defines ABSPATH as the CHECKOUT ROOT and then includes this file at :87,
     * so a hook registered at file scope arms a recursive delete against the
     * repository, and tools/adapter-kit.php ships the same file to adapter
     * authors. A guard that tests whether the path LOOKS minted is not enough:
     * the victim below is named exactly like a minted root, using the child's
     * own pid, and a shape-only guard deletes it.
     */
    public function testIncludingTheStubsNeverDeletesACallerDefinedAbspath(): void
    {
        // The victim is named the way a MINTED root is named, using the
        // child's own pid, so the pre-fix shape test
        // (`/wprism-root-<pid>-[0-9a-f]{8}$`) matches it exactly. That is the
        // whole point: shape is not ownership, and a test whose victim fails
        // the regex would pass against the defect it is meant to catch.
        $stubs = dirname(__DIR__, 2) . '/sandbox/tests/lib/wp_stubs.php';
        $child = <<<'PHP'
            $victim = sys_get_temp_dir() . '/wprism-root-' . getmypid() . '-deadbeef';
            mkdir($victim . '/wp-content/plugins', 0777, true);
            file_put_contents($victim . '/keep.txt', 'must survive');
            echo $victim, "\n";
            define('ABSPATH', $victim . '/');
            require %s;
            PHP;
        exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg(sprintf($child, var_export($stubs, true))) . ' 2>&1',
            $output,
            $status
        );

        self::assertSame(0, $status, 'child failed: ' . implode("\n", $output));
        $victim = (string) ($output[0] ?? '');
        self::assertNotSame('', $victim, 'child did not report its victim path');
        self::assertFileExists(
            $victim . '/keep.txt',
            'wp_stubs.php deleted a tree under an ABSPATH a suite defined for itself'
        );
        self::assertDirectoryExists($victim . '/wp-content/plugins');

        // Only reached when the assertions above hold, so a failure leaves the
        // evidence on disk rather than tidying it away.
        array_map('unlink', [$victim . '/keep.txt']);
        array_map('rmdir', [$victim . '/wp-content/plugins', $victim . '/wp-content', $victim]);
    }

    // --------------------------------------------------------- prepare()

    public function testPrepareRendersEveryWordPressPlaceholderType(): void
    {
        $db = new FakeWpdb();

        self::assertSame(
            "SELECT * FROM `wp_x` WHERE a = 'it''s' AND b = 12 AND c = 1.5",
            // addslashes() is what wpdb::_real_escape() reduces to for these
            // payloads, so the quote arrives backslash-escaped, not doubled.
            str_replace("\\'", "''", $db->prepare(
                'SELECT * FROM %i WHERE a = %s AND b = %d AND c = %f',
                'wp_x',
                "it's",
                '12abc',
                1.5
            ))
        );
    }

    public function testPrepareUnpacksASingleArrayArgumentLikeWpdb(): void
    {
        $db = new FakeWpdb();

        self::assertSame(
            "SELECT 'a', 'b', 'c'",
            $db->prepare('SELECT %s, %s, %s', ['a', 'b', 'c'])
        );
    }

    public function testPrepareHonoursPositionalPlaceholders(): void
    {
        $db = new FakeWpdb();

        self::assertSame("SELECT 'x', 'x'", $db->prepare('SELECT %1$s, %1$s', 'x'));
    }

    public function testPrepareProtectsAPercentInsideAValue(): void
    {
        $db = new FakeWpdb();
        $prepared = $db->prepare('SELECT %s', 'a%b');

        // The raw prepared string carries wpdb's placeholder escape so the
        // value's percent cannot be re-read as a placeholder; it becomes a
        // literal percent again only when the statement is executed.
        self::assertStringNotContainsString('a%b', $prepared);
        self::assertSame("SELECT 'a%b'", $db->remove_placeholder_escape($prepared));
    }

    public function testPrepareRefusesAPlaceholderArgumentMismatch(): void
    {
        $db = new FakeWpdb();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no argument #2');
        $db->prepare('SELECT %s, %d', 'only-one');
    }

    // ------------------------------------------------- SQL interpreter

    public function testSelectFiltersOrdersAndLimitsSeededRows(): void
    {
        $db = $this->seededDb();

        $rows = $db->get_results(
            $db->prepare(
                'SELECT meta_id, meta_key FROM wp_postmeta WHERE post_id = %d ORDER BY meta_id DESC',
                7
            ),
            ARRAY_A
        );

        // Values come back as STRINGS even though the fixture seeded ints:
        // wpdb reads mysqli's text protocol. See
        // testResultValuesAreStringsWhileTheStoreKeepsFixtureTypes().
        self::assertSame(
            [
                ['meta_id' => '2', 'meta_key' => 'beta'],
                ['meta_id' => '1', 'meta_key' => 'alpha'],
            ],
            $rows
        );
        self::assertSame(
            'one',
            $db->get_var($db->prepare(
                'SELECT meta_value FROM wp_postmeta WHERE post_id = %d AND meta_key = %s LIMIT 1',
                7,
                'alpha'
            ))
        );
    }

    public function testSelectOrderPositionsResolveProjectionBeforeLimiting(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_probe', [
            ['id' => 2, 'score' => 5, 'label' => 'Zulu'],
            ['id' => 3, 'score' => 9, 'label' => 'Alpha'],
            ['id' => 1, 'score' => 5, 'label' => 'Beta'],
        ])->setColumns('wp_probe', ['label' => 'text', 'id' => 'bigint', 'score' => 'int']);

        // SELECT * follows recorded column order, not the first fixture row
        // or an assumption that position 1 means the primary key.
        self::assertSame(['Alpha', 'Beta', 'Zulu'], $db->get_col('SELECT * FROM wp_probe ORDER BY 1'));
        self::assertSame(
            [['measure' => '9', 'id' => '3'], ['measure' => '5', 'id' => '1']],
            $db->get_results('SELECT score AS measure,id FROM wp_probe ORDER BY 1 DESC,2 ASC LIMIT 2', ARRAY_A)
        );
        self::assertSame(['3', '1', '2'], $db->get_col('SELECT id,label FROM wp_probe ORDER BY 2'));
        self::assertSame(['9', '5'], $db->get_col('SELECT DISTINCT score FROM wp_probe ORDER BY 1 DESC'));
        $db->seedTable('wp_probe', []);
        self::assertSame([], $db->get_results('SELECT * FROM wp_probe ORDER BY 1', ARRAY_A));
    }

    #[DataProvider('unsupportedOrderPositionProvider')]
    public function testOrderPositionsRefuseUnmodeledOrInvalidScopes(string $sql): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_probe', [])->setColumns('wp_probe', ['id' => 'int']);
        $db->seedTable('wp_lookup', [])->setColumns('wp_lookup', ['id' => 'int']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('FakeWpdb: unsupported SQL');
        $db->query($sql);
    }

    public static function unsupportedOrderPositionProvider(): array
    {
        return [
            ['SELECT id FROM wp_probe ORDER BY 0'],
            ['SELECT id FROM wp_probe ORDER BY -1'],
            ['SELECT id FROM wp_probe ORDER BY 1.0'],
            ['SELECT id FROM wp_probe ORDER BY 2'],
            ['SELECT * FROM wp_probe ORDER BY 2'],
            ['SELECT foreign_table.* FROM wp_probe ORDER BY 1'],
            ['SELECT id FROM wp_probe ORDER BY BINARY 1'],
            ['SELECT BINARY id FROM wp_probe ORDER BY 1'],
            ['SELECT LENGTH(id) AS width FROM wp_probe ORDER BY 1'],
            ['SELECT id,1 AS constant FROM wp_probe ORDER BY 1'],
            ['SELECT COUNT(*) FROM wp_probe ORDER BY 1'],
            ['SELECT 1 ORDER BY 1'],
            ['SELECT p.id FROM wp_probe p LEFT JOIN wp_lookup l ON p.id = l.id ORDER BY 1'],
            ['UPDATE wp_probe SET id = 2 ORDER BY 1'],
            ['DELETE FROM wp_probe ORDER BY 1'],
        ];
    }

    public function testSelectCountAggregatesMatchedRows(): void
    {
        $db = $this->seededDb();

        self::assertSame('3', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));
        self::assertSame(
            '2',
            $db->get_var($db->prepare('SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = %s', 'alpha'))
        );
    }

    // The "remains closed without opt-in" companion this test once had is
    // deliberately gone: the single-equality LEFT JOIN is now the ONE join
    // form the interpreter accepts generally (its wider-shape refusals each
    // have their own case below), so the ownership query needs no enable call.
    public function testRelationshipOwnershipJoinProjectsRawOwnerRowsAndMissingTaxonomy(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_term_relationships', [
            ['object_id' => 7, 'term_taxonomy_id' => 22, 'term_order' => 0],
            ['object_id' => 7, 'term_taxonomy_id' => 20, 'term_order' => 2],
            ['object_id' => 8, 'term_taxonomy_id' => 21, 'term_order' => 0],
        ])->seedTable('wp_term_taxonomy', [
            ['term_taxonomy_id' => 20, 'taxonomy' => 'nav_menu'],
            ['term_taxonomy_id' => 21, 'taxonomy' => 'category'],
        ]);

        self::assertSame(
            [
                ['term_taxonomy_id' => '20', 'term_order' => '2', 'taxonomy' => 'nav_menu'],
                ['term_taxonomy_id' => '22', 'term_order' => '0', 'taxonomy' => null],
            ],
            $db->get_results(
                'SELECT tr.term_taxonomy_id, tr.term_order, tt.taxonomy '
                . 'FROM wp_term_relationships tr LEFT JOIN wp_term_taxonomy tt '
                . 'ON tt.term_taxonomy_id = tr.term_taxonomy_id '
                . 'WHERE tr.object_id = 7 ORDER BY tr.term_taxonomy_id ASC LIMIT 4',
                ARRAY_A
            )
        );
    }

    public function testInformationSchemaRemainsClosedUnlessExplicitlyEnabled(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_options', [])->setColumns('wp_options', ['option_name' => 'varchar(191)']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('schema-qualified table names');
        $db->get_results('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES');
    }

    public function testOptInInformationSchemaProjectsSeededTablesColumnsAndCharacterLength(): void
    {
        $db = FakeWpdb::install()->enableInformationSchema();
        $db->seedTable('wp_options', [])->setColumns(
            'wp_options',
            ['option_name' => 'varchar(191)', 'option_value' => 'longtext']
        )->setTableEngine('wp_options', 'InnoDB');
        $db->seedTable('wp_users', [])->setColumns('wp_users', ['ID' => 'bigint(20)'])->setTableEngine('wp_users', 'MyISAM');

        self::assertSame(
            [['TABLE_NAME' => 'wp_options', 'ENGINE' => 'InnoDB']],
            $db->get_results(
                "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('wp_options') ORDER BY TABLE_NAME ASC",
                ARRAY_A
            )
        );
        self::assertSame(
            [
                ['TABLE_NAME' => 'wp_options', 'COLUMN_NAME' => 'option_name'],
                ['TABLE_NAME' => 'wp_options', 'COLUMN_NAME' => 'option_value'],
            ],
            $db->get_results(
                "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_options' ORDER BY TABLE_NAME, ORDINAL_POSITION",
                ARRAY_A
            )
        );
        self::assertSame(
            '191',
            $db->get_var(
                "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_options' AND COLUMN_NAME = 'option_name'"
            )
        );
    }

    public function testOptInInformationSchemaRefusesUnsupportedOrMalformedShapes(): void
    {
        $db = FakeWpdb::install()->enableInformationSchema();
        $db->seedTable('wp_options', [])->setColumns('wp_options', ['option_name' => 'varchar(191)']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unsupported opt-in information_schema shape');
        $db->get_results('SELECT * FROM information_schema.STATISTICS WHERE TABLE_NAME = \'wp_options\'');
    }

    /** @return array<string,array{string}> */
    public static function metadataProjectionQueries(): array
    {
        return [
            'tables' => ["SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('wp_options') ORDER BY TABLE_NAME ASC"],
            'columns' => ["SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_options' ORDER BY TABLE_NAME, ORDINAL_POSITION"],
            'column length' => ["SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wp_options' AND COLUMN_NAME = 'option_name'"],
            'trigger count' => ["SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_TABLE = 'wp_options'"],
            'trigger privileges' => ['SELECT scope_type, table_name FROM information_schema.USER_PRIVILEGES WHERE direct_trigger_grants = 1'],
            'primary keys' => ["SHOW KEYS FROM `wp_options` WHERE Key_name = 'PRIMARY'"],
        ];
    }

    public function testShowIndexFiltersOnlyRecordedMetadata(): void
    {
        $db = FakeWpdb::install()->seedTable('wp_comments', []);
        $primary = ['Key_name' => 'PRIMARY', 'Column_name' => 'comment_ID', 'Seq_in_index' => 1];
        $secondary = ['Key_name' => 'comment_post_ID', 'Column_name' => 'comment_post_ID', 'Seq_in_index' => 1];
        $db->setIndexes('wp_comments', [$primary, $secondary]);
        foreach (['KEYS', 'INDEX', 'INDEXES'] as $variant) {
            self::assertSame([array_replace($primary, ['Seq_in_index' => '1'])],
                $db->get_results("SHOW $variant FROM `wp_comments` WHERE Key_name = 'PRIMARY'", ARRAY_A));
            self::assertSame([array_replace($secondary, ['Seq_in_index' => '1'])],
                $db->get_results("SHOW $variant FROM `wp_comments` WHERE Key_name <> 'PRIMARY' AND Seq_in_index = 1", ARRAY_A));
            self::assertSame([], $db->get_results("SHOW $variant FROM `wp_comments` WHERE Key_name = 'absent'", ARRAY_A));
        }
        $db->setIndexes('wp_comments', []);
        self::assertSame([], $db->get_results("SHOW KEYS FROM `wp_comments` WHERE Key_name = 'PRIMARY'", ARRAY_A));
        $db->seedTable('wp_posts', [['ID' => 1]])->setPrimaryKey('wp_posts', 'ID');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('without a recorded index fixture');
        $db->get_results("SHOW KEYS FROM `wp_posts` WHERE Key_name = 'PRIMARY'", ARRAY_A);
    }

    #[DataProvider('metadataProjectionQueries')]
    public function testMetadataProjectionUsesTheOrdinaryReadPipeline(string $sql): void
    {
        $db = FakeWpdb::install()->enableInformationSchema()
            ->seedTable('wp_options', [])
            ->setColumns('wp_options', ['option_name' => 'varchar(191)'])
            ->setIndexes('wp_options', [['Key_name' => 'PRIMARY', 'Column_name' => 'option_id', 'Seq_in_index' => 1]])
            ->setTableEngine('wp_options', 'InnoDB');
        $observed = [];
        $db->onQuery(static function (string $query, string $method) use (&$observed): null {
            $observed[] = [$query, $method];
            return null;
        });
        $db->last_error = 'stale metadata error';
        $rows = $db->get_results($sql, ARRAY_A);
        self::assertNotEmpty($rows, 'the positive metadata premise must contain actual projected evidence');
        self::assertSame('', $db->last_error);
        self::assertSame([[$sql, 'get_results']], $observed);
        self::assertSame([$sql], $db->queries());
        self::assertSame((string) reset($rows[0]), $db->get_var($sql));
        self::assertSame([[$sql, 'get_results'], [$sql, 'get_var']], $observed);

        $db->failNextQuery('metadata-read-canary', $sql);
        self::assertSame([], $db->get_results($sql, ARRAY_A));
        self::assertSame('metadata-read-canary', $db->last_error);
        $db->failNextQuery('metadata-scalar-canary', $sql);
        self::assertNull($db->get_var($sql));
        self::assertSame('metadata-scalar-canary', $db->last_error);
        $db->returnNextGetResultsAs(false, $sql);
        self::assertFalse($db->get_results($sql, ARRAY_A));
        self::assertSame('', $db->last_error);
        self::assertSame(5, count($observed), 'every read reaches driver interception exactly once');
    }

    #[DataProvider('metadataProjectionQueries')]
    public function testMetadataCannotBypassTheInstalledQueryGate(string $sql): void
    {
        $db = FakeWpdb::install()->enableInformationSchema()
            ->seedTable('wp_options', [])
            ->setIndexes('wp_options', [['Key_name' => 'PRIMARY', 'Column_name' => 'option_id', 'Seq_in_index' => 1]])
            ->setColumns('wp_options', ['option_name' => 'varchar(191)']);
        $driverReads = 0;
        $db->onQuery(static function () use (&$driverReads): null {
            ++$driverReads;
            return null;
        });
        $framesPresent = array_key_exists('wp_current_filter', $GLOBALS);
        $frames = $GLOBALS['wp_current_filter'] ?? null;
        $hooksPresent = array_key_exists('wp_filter', $GLOBALS);
        $hooks = $GLOBALS['wp_filter'] ?? null;
        // The fake delegates to engine-installed hook objects, not WpStore's
        // ordinary callback registry. Exercise that transport seam directly;
        // the Ledger/profile regression supplies the actual engine authority.
        $gate = new class() {
            public bool $refuse = false;
            public int $reads = 0;

            public function hook_name(): string
            {
                return 'query';
            }

            /** @param array{string} $arguments */
            public function apply_filters(string $query, array $arguments): string
            {
                ++$this->reads;
                if ($this->refuse) {
                    throw new \RuntimeException('metadata query gate refused');
                }
                return $query;
            }
        };
        $GLOBALS['wp_filter'] = ['query' => $gate];
        $GLOBALS['wp_current_filter'] = [];
        try {
            self::assertNotEmpty($db->get_results($sql));
            self::assertNotNull($db->get_var($sql));
            self::assertSame(2, $gate->reads, 'healthy metadata reaches the installed gate once per read');
            self::assertSame(2, $driverReads);
            $db->resetLog();
            $driverReads = 0;
            $gate->refuse = true;
            foreach (['get_results', 'get_var'] as $method) {
                $caught = null;
                try {
                    $db->$method($sql);
                } catch (\RuntimeException $failure) {
                    $caught = $failure;
                }
                self::assertNotNull($caught, 'metadata projection skipped its installed query gate');
                self::assertSame('metadata query gate refused', $caught->getMessage());
            }
            self::assertSame(4, $gate->reads);
        } finally {
            // This test owns no Db transaction to settle the WordPress stack.
            // Preserve the fake's real exceptional-dispatch behavior; fixture
            // teardown restores only the exact frames it observed at entry.
            if ($hooksPresent) {
                $GLOBALS['wp_filter'] = $hooks;
            } else {
                unset($GLOBALS['wp_filter']);
            }
            if ($framesPresent) {
                $GLOBALS['wp_current_filter'] = $frames;
            } else {
                unset($GLOBALS['wp_current_filter']);
            }
        }
        self::assertSame(0, $driverReads, 'query authority refuses before any schema answer is manufactured');
        self::assertSame([], $db->queries());
    }

    /** @return array<string,string> */
    private static function fullApplyProjectionPost(): array
    {
        return [
            'ID' => '7', 'post_author' => '3', 'post_date' => '', 'post_date_gmt' => '',
            'post_content' => 'native body', 'post_title' => 'Attachment title', 'post_excerpt' => '',
            'post_status' => 'inherit', 'comment_status' => 'closed', 'ping_status' => 'closed',
            'post_password' => '', 'post_name' => 'attachment-7', 'post_modified' => '',
            'post_modified_gmt' => '', 'post_parent' => '0', 'menu_order' => '0',
            'post_type' => 'attachment', 'post_mime_type' => 'image/png',
        ];
    }

    private function fullApplyProjectionDb(): FakeWpdb
    {
        return FakeWpdb::install()->enableFullApplySqlExtensions()
            ->seedTable('wp_posts', [self::fullApplyProjectionPost()])
            ->seedTable('wp_postmeta', [[
                'meta_id' => 1, 'post_id' => 7, 'meta_key' => '_wprism_uuid',
                'meta_value' => '11111111-1111-7111-8111-111111111111',
            ]])
            ->seedTable('wp_options', [['option_name' => 'authored-setting', 'option_value' => '9']])
            ->seedTable('wp_wprism_kv', [['k' => 'attachment_fs:7', 'v' => 'fixture']])
            ->seedTable('wp_terms', [])
            ->seedTable('wp_term_taxonomy', []);
    }

    /** @return array<string,array{string,string,list<array<string,?string>>}> */
    public static function fullApplyReadProjectionQueries(): array
    {
        $post = self::fullApplyProjectionPost();
        $bytesSql = 'OCTET_LENGTH(CAST(ID AS CHAR)) + OCTET_LENGTH(CAST(post_author AS CHAR)) '
            . "+ OCTET_LENGTH(COALESCE(post_date,'')) + OCTET_LENGTH(COALESCE(post_date_gmt,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_content,'')) + OCTET_LENGTH(COALESCE(post_title,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_excerpt,'')) + OCTET_LENGTH(COALESCE(post_status,'')) "
            . "+ OCTET_LENGTH(COALESCE(comment_status,'')) + OCTET_LENGTH(COALESCE(ping_status,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_password,'')) + OCTET_LENGTH(COALESCE(post_name,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_modified,'')) + OCTET_LENGTH(COALESCE(post_modified_gmt,'')) "
            . '+ OCTET_LENGTH(CAST(post_parent AS CHAR)) + OCTET_LENGTH(CAST(menu_order AS CHAR)) '
            . "+ OCTET_LENGTH(COALESCE(post_type,'')) + OCTET_LENGTH(COALESCE(post_mime_type,''))";
        $postBytes = (string) array_sum(array_map(strlen(...), $post));
        $nameBytes = strlen('authored-setting');
        return [
            'canonical post witness' => ['get_row',
                "SELECT p.ID, p.post_type, pm.meta_value AS wprism_uuid FROM wp_posts p LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID AND pm.meta_key = '_wprism_uuid' WHERE p.ID = 7 ORDER BY pm.meta_id ASC LIMIT 1",
                [['ID' => '7', 'post_type' => 'attachment', 'wprism_uuid' => '11111111-1111-7111-8111-111111111111']],
            ],
            'attachment marker' => ['get_results',
                "SELECT k, OCTET_LENGTH(v) AS v_bytes, CASE WHEN v IS NOT NULL AND OCTET_LENGTH(v) <= 512 THEN v ELSE NULL END AS bounded_v FROM `wp_wprism_kv` WHERE LOWER(LEFT(k, 14)) = 'attachment_fs:' ORDER BY BINARY k ASC LIMIT 2",
                [['k' => 'attachment_fs:7', 'v_bytes' => '7', 'bounded_v' => 'fixture']],
            ],
            'post stats' => ['get_results',
                'SELECT COUNT(*) AS row_count, COALESCE(SUM(' . $bytesSql . '), 0) AS total_bytes, COALESCE(MAX(' . $bytesSql . "), 0) AS max_row_bytes FROM wp_posts WHERE (post_type = 'attachment' AND post_status = 'inherit')",
                [['row_count' => '1', 'total_bytes' => $postBytes, 'max_row_bytes' => $postBytes]],
            ],
            'option stats' => ['get_results',
                'SELECT COUNT(*) AS row_count, COALESCE(SUM(OCTET_LENGTH(option_name) + OCTET_LENGTH(option_value)), 0) AS total_bytes, COALESCE(MAX(OCTET_LENGTH(option_name)), 0) AS max_name_bytes, COALESCE(MAX(CHAR_LENGTH(option_name)), 0) AS max_name_characters, COALESCE(MAX(OCTET_LENGTH(option_value)), 0) AS max_value_bytes FROM wp_options',
                [['row_count' => '1', 'total_bytes' => (string) ($nameBytes + 1),
                    'max_name_bytes' => (string) $nameBytes, 'max_name_characters' => (string) $nameBytes,
                    'max_value_bytes' => '1']],
            ],
            'post groups' => ['get_results',
                "SELECT post_type, COUNT(*) AS entities FROM wp_posts WHERE (post_status IN ('publish','draft','pending','private','future') OR (post_type = 'attachment' AND post_status = 'inherit')) GROUP BY post_type ORDER BY post_type ASC LIMIT 4097",
                [['post_type' => 'attachment', 'entities' => '1']],
            ],
            'post list' => ['get_results',
                "SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, post_modified, post_modified_gmt, post_parent, menu_order, post_type, post_mime_type FROM wp_posts WHERE (post_type = 'attachment' AND post_status = 'inherit') ORDER BY ID ASC LIMIT 1000001",
                [$post],
            ],
            'empty native menu inventory' => ['get_results',
                "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC",
                [],
            ],
        ];
    }

    /** @param list<array<string,?string>> $rows */
    #[DataProvider('fullApplyReadProjectionQueries')]
    public function testFullApplyReadProjectionUsesTheOrdinaryPipeline(string $method, string $sql, array $rows): void
    {
        $db = $this->fullApplyProjectionDb();
        $expected = $method === 'get_row' ? ($rows[0] ?? null) : $rows;
        $observed = [];
        $db->onQuery(static function (string $query, string $entrypoint) use (&$observed): null {
            $observed[] = [$query, $entrypoint];
            return null;
        });
        $db->last_error = 'stale full-read error';
        self::assertSame($expected, $db->$method($sql, ARRAY_A));
        self::assertSame('', $db->last_error);
        self::assertSame([[$sql, $method]], $observed);
        self::assertSame([$sql], $db->queries());
        self::assertSame($sql, $db->last_query);
        self::assertSame(count($rows), $db->num_rows);

        $db->failNextQuery('full-read-canary', $sql);
        self::assertSame($method === 'get_row' ? null : [], $db->$method($sql, ARRAY_A));
        self::assertSame('full-read-canary', $db->last_error);
        self::assertSame(0, $db->num_rows);
        self::assertSame($expected, $db->$method($sql, ARRAY_A));
        self::assertSame('', $db->last_error, 'the first healthy read clears the prior failure');

        $override = $method === 'get_row' ? ['compatible' => 'driver'] : [['compatible' => 'driver']];
        if ($method === 'get_row') {
            $db->returnNextGetRowAs($override, $sql);
        } else {
            $db->returnNextGetResultsAs($override, $sql);
        }
        self::assertSame($override, $db->$method($sql, ARRAY_A));
        self::assertSame($expected, $db->$method($sql, ARRAY_A), 'a driver return override is consumed exactly once');
        self::assertSame(array_fill(0, 5, [$sql, $method]), $observed);
        self::assertSame(array_fill(0, 5, $sql), $db->queries());
        foreach ($method === 'get_row' ? [null, (object) ['compatible' => 'driver']] : [null, false] as $abnormal) {
            if ($method === 'get_row') {
                $db->returnNextGetRowAs($abnormal, $sql);
            } else {
                $db->returnNextGetResultsAs($abnormal, $sql);
            }
            self::assertSame($abnormal, $db->$method($sql, ARRAY_A));
            self::assertSame(count($rows), $db->num_rows, 'return-shape mutation happens after the real read');
            self::assertSame('', $db->last_error);
            self::assertSame($expected, $db->$method($sql, ARRAY_A));
        }
        self::assertSame(array_fill(0, 9, [$sql, $method]), $observed);
        self::assertSame(array_fill(0, 9, $sql), $db->queries());
    }

    /** @param list<array<string,?string>> $rows */
    #[DataProvider('fullApplyReadProjectionQueries')]
    public function testFullApplyReadProjectionCannotSkipTheInstalledGate(string $method, string $sql, array $rows): void
    {
        $db = $this->fullApplyProjectionDb();
        $expected = $method === 'get_row' ? ($rows[0] ?? null) : $rows;
        $driverReads = 0;
        $db->onQuery(static function () use (&$driverReads): null {
            ++$driverReads;
            return null;
        });
        $framesPresent = array_key_exists('wp_current_filter', $GLOBALS);
        $frames = $GLOBALS['wp_current_filter'] ?? null;
        $hooksPresent = array_key_exists('wp_filter', $GLOBALS);
        $hooks = $GLOBALS['wp_filter'] ?? null;
        $gate = new class() {
            public bool $refuse = false;
            public ?string $replacement = null;
            public int $reads = 0;

            public function hook_name(): string
            {
                return 'query';
            }

            /** @param array{string} $arguments */
            public function apply_filters(string $query, array $arguments): string
            {
                ++$this->reads;
                if ($this->refuse) {
                    throw new \RuntimeException('full-read query gate refused');
                }
                return $this->replacement ?? $query;
            }
        };
        $GLOBALS['wp_filter'] = ['query' => $gate];
        $GLOBALS['wp_current_filter'] = [];
        try {
            self::assertSame($expected, $db->$method($sql, ARRAY_A));
            self::assertSame(1, $gate->reads, 'even the exact empty-menu projection must traverse a live gate');
            self::assertSame(1, $driverReads);
            $gate->replacement = 'SELECT option_name FROM wp_options';
            self::assertSame($method === 'get_row'
                ? ['option_name' => 'authored-setting'] : [['option_name' => 'authored-setting']],
                $db->$method($sql, ARRAY_A), 'the projected answer belongs to the filtered SQL, not the original shortcut');
            self::assertSame($gate->replacement, $db->last_query);
            self::assertSame(2, $gate->reads);
            self::assertSame(2, $driverReads);
            $db->resetLog();
            $driverReads = 0;
            $gate->refuse = true;
            $caught = null;
            try {
                $db->$method($sql, ARRAY_A);
            } catch (\RuntimeException $failure) {
                $caught = $failure;
            }
            self::assertNotNull($caught, 'a full read manufactured evidence before its installed authority');
            self::assertSame('full-read query gate refused', $caught->getMessage());
            self::assertSame(3, $gate->reads);
        } finally {
            if ($hooksPresent) {
                $GLOBALS['wp_filter'] = $hooks;
            } else {
                unset($GLOBALS['wp_filter']);
            }
            if ($framesPresent) {
                $GLOBALS['wp_current_filter'] = $frames;
            } else {
                unset($GLOBALS['wp_current_filter']);
            }
        }
        self::assertSame(0, $driverReads, 'authority must refuse before driver interception or projection');
        self::assertSame([], $db->queries());
    }

    public function testFullApplySqlExtensionsRefuseWithoutExplicitOptIn(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_posts', [
            ['ID' => 7, 'post_type' => 'attachment', 'post_status' => 'inherit'],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unknown column');
        $db->get_results(
            'SELECT COUNT(*) AS row_count, bogus AS total_bytes FROM wp_posts WHERE post_status = \'inherit\''
        );
    }

    public function testFullApplySqlExtensionsRefuseMalformedSelectProbesByDefault(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_posts', [
            ['ID' => 7, 'post_type' => 'attachment', 'post_status' => 'inherit'],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unknown column');
        $db->get_results(
            'SELECT post_type, COUNT(*) AS entities, bogus FROM wp_posts /* malformed full-apply probe */'
        );
    }

    /** @return iterable<string,array{string,string,string,string,bool,string}> */
    public static function fullApplyPruningCases(): iterable
    {
        foreach (['wp_', 'tenant_'] as $prefix) {
            foreach ([['post', 'posts', 'ID', 'po'], ['term', 'terms', 'term_id', 't'],
                ['term_taxonomy', 'term_taxonomy', 'term_taxonomy_id', 'tt']] as [$kind, $table, $column, $alias]) {
                foreach ([false, true] as $profiled) {
                    yield $prefix . $kind . ($profiled ? '-profiled' : '-legacy') => [$kind, $table, $column, $alias, $profiled, $prefix];
                }
            }
        }
    }

    /** @return array{FakeWpdb,list<array<string,mixed>>,string} */
    private function pruningFixture(string $kind, string $table, string $column, string $alias, bool $profiled, string $prefix): array
    {
        $map = $prefix . 'wprism_map';
        $physical = $prefix . $table;
        $rows = [
            ['uuid' => 'live', 'entity_type' => 'fixture', 'id_kind' => $kind, 'local_id' => 7],
            ['uuid' => 'orphan', 'entity_type' => 'fixture', 'id_kind' => $kind, 'local_id' => 8],
            ['uuid' => 'other-kind', 'entity_type' => 'fixture', 'id_kind' => 'widget_fixture', 'local_id' => 8],
            ['uuid' => 'another-live', 'entity_type' => 'fixture', 'id_kind' => $kind, 'local_id' => '9'],
        ];
        $db = FakeWpdb::install($prefix)->enableFullApplySqlExtensions()
            ->seedTable($map, $rows)->setColumns($map, ['uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint'])
            ->seedTable($physical, [[$column => '7', 'unrelated_id' => 8], [$column => 9, 'unrelated_id' => 8]])
            ->setColumns($physical, [$column => 'bigint', 'unrelated_id' => 'bigint']);
        $nonce = str_repeat('a', 64);
        $db->query("SET @wprism_tx_session = '$nonce'");
        $sql = $profiled
            ? "DELETE FROM `$map` WHERE CONNECTION_ID() = '1' AND BINARY @wprism_tx_session = BINARY '$nonce' AND (id_kind = '$kind' AND NOT EXISTS (SELECT 1 FROM `$physical` $alias WHERE $alias.`$column` = `$map`.`local_id`))"
            : "DELETE m FROM $map m LEFT JOIN $physical $alias ON $alias.$column = m.local_id WHERE m.id_kind = '$kind' AND $alias.$column IS NULL";

        return [$db, $rows, $sql];
    }

    #[DataProvider('fullApplyPruningCases')]
    public function testFullApplyPruningUsesExactSeededBackingAndRollsBack(string $kind, string $table, string $column, string $alias, bool $profiled, string $prefix): void
    {
        [$db, $before, $sql] = $this->pruningFixture($kind, $table, $column, $alias, $profiled, $prefix);
        $physicalBefore = $db->rows($prefix . $table);
        $db->query('START TRANSACTION');
        $db->query('SAVEPOINT `before_prune`');
        self::assertSame(1, $db->query($sql), 'the exact missing backing row must be removed, not acknowledged as a no-op');
        self::assertSame(1, $db->rows_affected);
        self::assertSame([$before[0], $before[2], $before[3]], $db->rows($prefix . 'wprism_map'));
        self::assertSame($physicalBefore, $db->rows($prefix . $table));
        self::assertSame($sql, $db->last_query);
        self::assertSame('', $db->last_error);
        self::assertSame(0, $db->query($sql), 'a repeated prune reaches a real fixed point');
        $db->query('ROLLBACK TO SAVEPOINT `before_prune`');
        self::assertSame($before, $db->rows($prefix . 'wprism_map'));
        self::assertSame(1, $db->query($sql));
        $db->query('ROLLBACK');
        self::assertSame($before, $db->rows($prefix . 'wprism_map'));
        $db->failNextQuery('prune transport failed', 'DELETE');
        self::assertFalse($db->query($sql));
        self::assertSame('prune transport failed', $db->last_error);
        self::assertSame($before, $db->rows($prefix . 'wprism_map'));
        self::assertSame(1, $db->query($sql));
        self::assertSame('', $db->last_error);
    }

    public function testFullApplyPruningHonorsConnectionAndExactSessionNonce(): void
    {
        [$db, $before, $sql] = $this->pruningFixture('term', 'terms', 'term_id', 't', true, 'wp_');
        $db->query('START TRANSACTION');
        self::assertSame(1, $db->query($sql));
        $db->setConnectionId(2);
        self::assertSame($before, $db->rows('wp_wprism_map'), 'disconnect rolls back the actual prune');
        self::assertSame(0, $db->query($sql), 'an old connection predicate cannot mutate the replacement');
        $db->setConnectionId(1);
        self::assertSame(0, $db->query($sql), 'a recycled connection id lacks the original nonce');
        $nonce = str_repeat('b', 64);
        $db->query("SET @wprism_tx_session = '$nonce'");
        self::assertSame(0, $db->query($sql), 'another generation is not the original exact nonce');
        self::assertSame($before, $db->rows('wp_wprism_map'));
    }

    public function testFullApplyPruningRequiresExplicitCompleteSchemaFixtures(): void
    {
        foreach (['missing-table', 'missing-primary-column', 'missing-map-column'] as $fault) {
            [$db, $before, $sql] = $this->pruningFixture('post', 'posts', 'ID', 'po', false, 'wp_');
            if ($fault === 'missing-table') {
                $db->query('DROP TABLE wp_posts');
            } elseif ($fault === 'missing-primary-column') {
                $db->seedTable('wp_posts', [['different_id' => 7]])->setColumns('wp_posts', ['different_id' => 'bigint']);
            } else {
                $db->seedTable('wp_wprism_map', [['id_kind' => 'post']])->setColumns('wp_wprism_map', ['id_kind' => 'varchar(64)']);
            }
            $before = $db->rows('wp_wprism_map');
            try {
                $db->query($sql);
                self::fail("pruning accepted $fault");
            } catch (LogicException $failure) {
                self::assertStringContainsString('FakeWpdb:', $failure->getMessage());
                if ($fault !== 'missing-table') {
                    self::assertStringContainsString($sql, $failure->getMessage(), 'closed-handler diagnostics must name the failing statement, not a previous SET');
                }
            }
            self::assertSame($before, $db->rows('wp_wprism_map'));
        }
    }

    public function testFullApplyPruningKeepsSqlNullAndKindComparisonSemantics(): void
    {
        [$db, $before, $sql] = $this->pruningFixture('post', 'posts', 'ID', 'po', true, 'wp_');
        $before[] = ['uuid' => 'case-folded', 'entity_type' => 'fixture', 'id_kind' => 'POST', 'local_id' => 11];
        $before[] = ['uuid' => 'null-kind', 'entity_type' => 'fixture', 'id_kind' => null, 'local_id' => 12];
        $before[] = ['uuid' => 'null-local', 'entity_type' => 'fixture', 'id_kind' => 'post', 'local_id' => null];
        $db->seedTable('wp_wprism_map', $before)->seedTable('wp_posts', [['ID' => null], ['ID' => '0007']]);
        self::assertSame(4, $db->query($sql));
        self::assertSame([$before[0], $before[2], $before[5]], $db->rows('wp_wprism_map'));
        $db->seedTable('wp_posts', []);
        self::assertSame(1, $db->query($sql), 'an explicitly empty physical table really prunes all matching rows');
        self::assertSame([$before[2], $before[5]], $db->rows('wp_wprism_map'));
    }

    public function testFullApplyPruningCannotSkipTheInstalledQueryGate(): void
    {
        [$db, $before, $sql] = $this->pruningFixture('post', 'posts', 'ID', 'po', true, 'wp_');
        $driverCalls = 0;
        $db->onQuery(static function () use (&$driverCalls): null {
            ++$driverCalls;
            return null;
        });
        $saved = [];
        foreach (['wp_filter', 'wp_current_filter'] as $key) {
            $saved[$key] = [array_key_exists($key, $GLOBALS), $GLOBALS[$key] ?? null];
        }
        $gate = new class() {
            public bool $refuse = false;
            public int $calls = 0;

            public function hook_name(): string
            {
                return 'query';
            }

            /** @param array{string} $arguments */
            public function apply_filters(string $query, array $arguments): string
            {
                ++$this->calls;
                if ($this->refuse) {
                    throw new \RuntimeException('prune query gate refused');
                }
                return 'SELECT local_id FROM wp_wprism_map';
            }
        };
        $GLOBALS['wp_filter'] = ['query' => $gate];
        $GLOBALS['wp_current_filter'] = [];
        try {
            $db->resetLog();
            self::assertSame(4, $db->query($sql), 'the filtered SELECT runs, not the original recognized DELETE');
            self::assertSame(1, $gate->calls);
            self::assertSame(1, $driverCalls);
            self::assertSame(['SELECT local_id FROM wp_wprism_map'], $db->queries());
            self::assertSame($before, $db->rows('wp_wprism_map'));
            $db->resetLog();
            $gate->refuse = true;
            try {
                $db->query($sql);
                self::fail('the recognized prune outran its installed query authority');
            } catch (\RuntimeException $failure) {
                self::assertSame('prune query gate refused', $failure->getMessage());
            }
            self::assertSame(2, $gate->calls);
            self::assertSame(1, $driverCalls, 'refusal happens before driver interception');
            self::assertSame([], $db->queries());
            self::assertSame($before, $db->rows('wp_wprism_map'));
        } finally {
            foreach ($saved as $key => [$present, $value]) {
                if ($present) {
                    $GLOBALS[$key] = $value;
                } else {
                    unset($GLOBALS[$key]);
                }
            }
        }
    }

    /** @return iterable<string,array{string,list<int>,list<int>}> */
    public static function typedPruningCases(): iterable
    {
        foreach (['wp_', 'tenant_'] as $prefix) {
            yield $prefix . 'unreferenced' => [$prefix, [], [0, 2, 3]];
            yield $prefix . 'one-preserved' => [$prefix, [8], [0, 1, 2, 3]];
            yield $prefix . 'both-preserved' => [$prefix, [8, 11], [0, 1, 2, 3, 4]];
        }
    }

    #[DataProvider('typedPruningCases')]
    public function testTypedPruningExecutesOnlyTheExactUnprotectedMissingRows(string $prefix, array $keep, array $remaining): void
    {
        [$db, $before, $sql] = $this->pruningFixture('fixture_row', 'fixture_rows', 'row_id', 'src', true, $prefix);
        $before[] = ['uuid' => 'another-orphan', 'entity_type' => 'fixture_rows', 'id_kind' => 'fixture_row', 'local_id' => 11];
        $db->seedTable($prefix . 'wprism_map', $before);
        if ($keep !== []) {
            $sql = str_replace("id_kind = 'fixture_row'", "id_kind = 'fixture_row' AND local_id NOT IN (" . implode(',', $keep) . ')', $sql);
        }
        $db->query('START TRANSACTION');
        $db->query('SAVEPOINT `typed_prune`');
        self::assertSame(count($before) - count($remaining), $db->query($sql));
        self::assertSame(array_map(static fn (int $index): array => $before[$index], $remaining), $db->rows($prefix . 'wprism_map'));
        self::assertSame($sql, $db->last_query);
        $db->query('ROLLBACK TO SAVEPOINT `typed_prune`');
        self::assertSame($before, $db->rows($prefix . 'wprism_map'));
        $db->failNextQuery('typed prune failed', 'DELETE');
        self::assertFalse($db->query($sql));
        self::assertSame($before, $db->rows($prefix . 'wprism_map'));
        self::assertSame(count($before) - count($remaining), $db->query($sql));
        $db->setConnectionId(2);
        self::assertSame($before, $db->rows($prefix . 'wprism_map'), 'replacement connection rolls back actual typed pruning');
        self::assertSame(0, $db->query($sql), 'stale connection and nonce cannot prune the replacement session');
        self::assertSame($before, $db->rows($prefix . 'wprism_map'));
    }

    public function testTypedPruningKeepsNullNotInUnknownInsteadOfDeletingIt(): void
    {
        [$db, $before, $sql] = $this->pruningFixture('fixture_row', 'fixture_rows', 'row_id', 'src', true, 'wp_');
        $before[] = ['uuid' => 'null-local', 'entity_type' => 'fixture_rows', 'id_kind' => 'fixture_row', 'local_id' => null];
        $db->seedTable('wp_wprism_map', $before);
        $protected = str_replace("id_kind = 'fixture_row'", "id_kind = 'fixture_row' AND local_id NOT IN (8)", $sql);
        self::assertSame(0, $db->query($protected), 'SQL NULL NOT IN has unknown truth and cannot authorize deletion');
        self::assertSame($before, $db->rows('wp_wprism_map'));
        self::assertSame(2, $db->query($sql), 'without NOT IN both unbacked matching rows are deleted');
        self::assertSame([$before[0], $before[2], $before[3]], $db->rows('wp_wprism_map'));
    }

    public function testTypedPruningRefusesBroadenedOrUnfencedPredicates(): void
    {
        foreach (['unfenced', 'extra-predicate', 'wrong-join', 'noncanonical-keep', 'foreign-prefix', 'no-opt-in'] as $fault) {
            [$db, $before, $sql] = $this->pruningFixture('fixture_row', 'fixture_rows', 'row_id', 'src', true, 'wp_');
            $sql = match ($fault) {
                'unfenced' => preg_replace("/CONNECTION_ID\(\) = '1' AND BINARY @wprism_tx_session = BINARY '[a-f0-9]{64}' AND /", '', $sql),
                'extra-predicate' => $sql . ' OR 1=1',
                'wrong-join' => str_replace('src.`row_id` = `wp_wprism_map`.`local_id`', 'src.`row_id` != `wp_wprism_map`.`local_id`', $sql),
                'noncanonical-keep' => str_replace("id_kind = 'fixture_row'", "id_kind = 'fixture_row' AND local_id NOT IN (08)", $sql),
                'foreign-prefix' => str_replace('`wp_fixture_rows`', '`other_fixture_rows`', $sql),
                default => $sql,
            };
            if ($fault === 'no-opt-in') {
                $db = FakeWpdb::install()->seedTable('wp_wprism_map', $before)
                    ->seedTable('wp_fixture_rows', [['row_id' => 7]]);
            }
            try {
                $db->query($sql);
                self::fail("typed prune accepted $fault");
            } catch (LogicException $failure) {
                self::assertStringContainsString('FakeWpdb:', $failure->getMessage());
            }
            self::assertSame($before, $db->rows('wp_wprism_map'));
        }
    }

    public function testEnabledFullApplyExtensionsRefuseMalformedStatementsPerHandlerFamily(): void
    {
        $cases = [
            'attachment marker' => [
                'sql' => "SELECT k, OCTET_LENGTH(v) AS v_bytes, CASE WHEN v IS NOT NULL AND OCTET_LENGTH(v) <= 512 THEN v ELSE NULL END AS bounded_v, bogus FROM `wp_wprism_kv` WHERE LOWER(LEFT(k, 14)) = 'attachment_fs:' ORDER BY BINARY k ASC LIMIT 2",
                'write' => false,
            ],
            'post stats' => [
                'sql' => "SELECT COUNT(*) AS row_count, bogus AS total_bytes FROM wp_posts WHERE (post_type = 'attachment' AND post_status = 'inherit')",
                'write' => false,
            ],
            'option stats' => [
                'sql' => 'SELECT COUNT(*) AS row_count, bogus AS total_bytes FROM wp_options',
                'write' => false,
            ],
            'post groups' => [
                'sql' => 'SELECT post_type, COUNT(*) AS entities FROM wp_posts UNION SELECT bogus FROM wp_posts',
                'write' => false,
            ],
            'post list' => [
                'sql' => 'SELECT ID, post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, post_modified, post_modified_gmt, post_parent, menu_order, post_type, post_mime_type FROM wp_posts UNION SELECT bogus FROM wp_posts',
                'write' => false,
            ],
            'term join' => [
                'sql' => "SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, tt.taxonomy, tt.description, tt.parent, bogus FROM wp_terms t JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC",
                'write' => false,
            ],
            'promotion insert' => [
                'sql' => "INSERT INTO `wp_wprism_kv` (k, v) VALUES ('promotion_lock', '{}') ON DUPLICATE KEY UPDATE v = IF((JSON_EXTRACT(v, '$.owner')), VALUES(v), v) AND 1=1",
                'write' => true,
            ],
            'promotion update' => [
                'sql' => "UPDATE `wp_wprism_kv` SET v = '{}' WHERE k = 'promotion_lock' AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = 'owner' AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = 'artifact' AND 1=1",
                'write' => true,
            ],
            'prune delete' => [
                'sql' => 'DELETE m FROM wp_wprism_map m LEFT JOIN wp_posts po ON po.ID = m.local_id LEFT JOIN wp_terms t ON t.term_id = m.local_id WHERE m.id_kind = \'post\' AND po.ID IS NULL',
                'write' => true,
            ],
        ];

        foreach ($cases as $label => $case) {
            $db = FakeWpdb::install()->enableFullApplySqlExtensions();
            $db->seedTable('wp_wprism_kv', [])->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext']);
            $db->seedTable('wp_wprism_map', [])->setColumns('wp_wprism_map', ['local_id' => 'bigint', 'id_kind' => 'varchar(32)']);
            $db->seedTable('wp_posts', [])->setColumns('wp_posts', ['ID' => 'bigint', 'post_type' => 'varchar(32)']);
            $db->seedTable('wp_options', [])->setColumns('wp_options', ['option_name' => 'varchar(191)', 'option_value' => 'longtext']);
            $db->seedTable('wp_terms', [])->setColumns('wp_terms', ['term_id' => 'bigint']);
            $db->seedTable('wp_term_taxonomy', [])->setColumns('wp_term_taxonomy', ['term_id' => 'bigint']);

            try {
                if ($case['write']) {
                    $db->query($case['sql']);
                } else {
                    $db->get_results($case['sql']);
                }
                self::fail("enabled full-apply handler accepted malformed {$label} SQL");
            } catch (LogicException $failure) {
                self::assertStringContainsString('FakeWpdb:', $failure->getMessage(), $label);
            }
        }
    }

    public function testWpStubsMirrorNativeHookRegistryForTopologyAudits(): void
    {
        if (!class_exists('WP_Hook')) {
            class_alias(\stdClass::class, 'WP_Hook');
        }

        $previous = $GLOBALS['wp_filter'] ?? null;
        $hook = new \WP_Hook();
        $hook->callbacks = [];
        $GLOBALS['wp_filter'] = ['wp_generate_attachment_metadata' => $hook];
        $callback = static fn (mixed $metadata, mixed $attachmentId): mixed => $metadata;

        try {
            self::assertTrue(add_filter('wp_generate_attachment_metadata', $callback, 10, 2));
            self::assertSame(
                [10 => [['function' => $callback, 'accepted_args' => 2]]],
                $GLOBALS['wp_filter']['wp_generate_attachment_metadata']->callbacks
            );
            self::assertTrue(remove_filter('wp_generate_attachment_metadata', $callback, 10));
            self::assertArrayNotHasKey('wp_generate_attachment_metadata', $GLOBALS['wp_filter']);
        } finally {
            $GLOBALS['wp_filter'] = $previous ?? [];
        }
    }

    public function testSelectLeftCanBindOneBoundedBinaryValueToItsByteLength(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_options', [
            ['option_id' => 1, 'option_name' => 'long', 'option_value' => 'abcdef'],
            ['option_id' => 2, 'option_name' => 'utf8', 'option_value' => 'éx'],
            ['option_id' => 3, 'option_name' => 'null', 'option_value' => null],
        ]);

        self::assertSame(
            [
                ['value_bytes' => '6', 'value_prefix' => 'ab'],
                ['value_bytes' => '3', 'value_prefix' => 'é'],
                ['value_bytes' => null, 'value_prefix' => null],
            ],
            $db->get_results(
                'SELECT LENGTH(option_value) AS value_bytes, '
                    . 'LEFT(BINARY option_value, 2) AS value_prefix FROM wp_options ORDER BY option_id',
                ARRAY_A
            )
        );
    }

    public function testSelectLeftCountsUtf8CharactersWithoutExpandingTheInput(): void
    {
        $db = FakeWpdb::install();
        $large = str_repeat('x', 8 * 1024 * 1024 + 1);
        $db->seedTable('wp_options', [
            ['option_id' => 1, 'option_name' => 'utf8', 'option_value' => 'é東京x'],
            ['option_id' => 2, 'option_name' => 'large', 'option_value' => $large],
        ]);

        self::assertSame(
            'é東京',
            $db->get_var('SELECT LEFT(option_value, 3) FROM wp_options WHERE option_id = 1')
        );
        self::assertSame(
            hash('sha256', $large),
            $db->get_var(
                'SELECT SHA2(LEFT(option_value, 8388609), 256) FROM wp_options WHERE option_id = 2'
            )
        );
    }

    public function testSelectLeftRefusesEveryMalformedUtf8Sequence(): void
    {
        $db = FakeWpdb::install();
        $invalid = [
            'continuation lead' => "\x80",
            'overlong encoding' => "\xc0\xaf",
            'truncated sequence' => "\xe2\x82",
            'surrogate' => "\xed\xa0\x80",
            'above Unicode maximum' => "\xf4\x90\x80\x80",
        ];

        foreach (array_values($invalid) as $index => $value) {
            $db->seedTable('wp_options', [[
                'option_id' => $index + 1,
                'option_name' => 'invalid-' . $index,
                'option_value' => $value,
            ]]);
            try {
                $db->get_var('SELECT LEFT(option_value, 1) FROM wp_options');
                self::fail('malformed UTF-8 sequence was accepted by LEFT()');
            } catch (LogicException $failure) {
                self::assertStringContainsString('LEFT() invalid UTF-8 input', $failure->getMessage());
            }
        }
    }

    public function testInsertAssignsTheNextAutoIncrementIdAndUpdateRewritesInPlace(): void
    {
        $db = $this->seededDb();

        self::assertSame(1, $db->query($db->prepare(
            'INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES (%d, %s, %s)',
            9,
            'gamma',
            'four'
        )));
        self::assertSame(4, $db->insert_id);

        self::assertSame(1, $db->query($db->prepare(
            'UPDATE wp_postmeta SET meta_value = %s WHERE meta_id = %d',
            'rewritten',
            4
        )));
        self::assertSame('rewritten', $db->rows('wp_postmeta')[3]['meta_value']);
    }

    public function testDeleteRemovesOnlyTheMatchedRows(): void
    {
        $db = $this->seededDb();

        self::assertSame(2, $db->query($db->prepare('DELETE FROM wp_postmeta WHERE post_id = %d', 7)));
        self::assertSame([3], array_column($db->rows('wp_postmeta'), 'meta_id'));
    }

    public function testArrayStyleWritersMatchTheSqlInterpreter(): void
    {
        $db = $this->seededDb();

        self::assertSame(1, $db->update('wp_postmeta', ['meta_value' => 'edited'], ['meta_id' => 1]));
        self::assertSame(1, $db->delete('wp_postmeta', ['meta_id' => 2]));
        self::assertSame(1, $db->insert('wp_postmeta', ['post_id' => 9, 'meta_key' => 'd', 'meta_value' => null]));

        self::assertSame('edited', $db->get_var('SELECT meta_value FROM wp_postmeta WHERE meta_id = 1'));
        self::assertSame('0', $db->get_var('SELECT COUNT(*) FROM wp_postmeta WHERE meta_id = 2'));
        self::assertNull($db->rows('wp_postmeta')[2]['meta_value']);
    }

    /**
     * wpdb::update()/::delete() special-case a null $where value into
     * `col IS NULL` -- they do NOT emit `col = NULL`. Before this was fixed
     * the fake rendered `= NULL` and matched nothing, so a suite following the
     * docs would pin a no-op branch the live gate never takes.
     */
    public function testArrayStyleWhereTreatsNullAsIsNullTheWayWpdbDoes(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_postmeta', [
            ['meta_id' => 1, 'post_id' => 7, 'meta_key' => 'a', 'meta_value' => null],
            ['meta_id' => 2, 'post_id' => 7, 'meta_key' => 'b', 'meta_value' => 'x'],
        ]);

        self::assertSame(1, $db->delete('wp_postmeta', ['meta_value' => null]));
        self::assertSame([2], array_column($db->rows('wp_postmeta'), 'meta_id'));
        self::assertStringContainsString('WHERE `meta_value` IS NULL', $db->queries()[0]);

        // The interpreter's own `= NULL` stays three-valued, as MySQL is.
        self::assertSame('0', $db->get_var('SELECT COUNT(*) FROM wp_postmeta WHERE meta_value = NULL'));
    }

    /**
     * MySQL returns the full column list with NULL for the columns a row does
     * not carry. Sparse seeded rows must not produce result rows of different
     * SHAPES: engine code reading $row['autoload'] would emit "Undefined array
     * key" (a suite failure via offline_diagnostics_guard.sh), and ARRAY_N
     * would put different columns at the same index in different rows.
     */
    public function testSelectStarProjectsEveryKnownColumnForSparseRows(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_options', [
            ['option_id' => 1, 'option_name' => 'home', 'option_value' => 'v', 'autoload' => 'yes'],
            ['option_id' => 2, 'option_name' => 'other', 'option_value' => 'x'],
        ]);

        self::assertSame(
            [
                ['option_id' => '1', 'option_name' => 'home', 'option_value' => 'v', 'autoload' => 'yes'],
                ['option_id' => '2', 'option_name' => 'other', 'option_value' => 'x', 'autoload' => null],
            ],
            $db->get_results('SELECT * FROM wp_options ORDER BY option_id', ARRAY_A)
        );

        $rows = $db->get_results('SELECT * FROM wp_options ORDER BY option_id', ARRAY_N);
        self::assertSame(4, count($rows[1]), 'ARRAY_N rows must all be the same width');

        $row = $db->get_row('SELECT * FROM wp_options WHERE option_id = 2');
        self::assertIsObject($row);
        self::assertTrue(property_exists($row, 'autoload'));
        self::assertNull($row->autoload);
    }

    /**
     * MySQL coerces numerically only when one operand IS a number; two strings
     * compare by collation. '7' = '007' is false there, and the columns this
     * fake models (wprism_kv `k`, option_name, meta_key, packed composite ids
     * rendered through %s) are exactly where a false match would let a suite
     * assert a lookup that finds a row the live target never returns.
     */
    public function testTwoStringsNeverCompareNumerically(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_options', [
            ['option_id' => 1, 'option_name' => 'padded', 'option_value' => '007'],
            ['option_id' => 2, 'option_name' => 'exp', 'option_value' => '1e2'],
            ['option_id' => 3, 'option_name' => 'plain', 'option_value' => '100'],
        ]);

        self::assertNull(
            $db->get_var($db->prepare('SELECT option_id FROM wp_options WHERE option_value = %s', '7')),
            "'7' must not match '007'"
        );
        self::assertSame(
            '3',
            $db->get_var($db->prepare('SELECT option_id FROM wp_options WHERE option_value = %s', '100')),
            "'1e2' must not match '100'; only the literal '100' row does"
        );

        // The case the numeric branch exists for: an int on either side still
        // coerces, so a %d-rendered literal finds a string-typed fixture value.
        self::assertSame(
            '2',
            $db->get_var($db->prepare('SELECT option_id FROM wp_options WHERE option_id = %d', 2))
        );
        $db->seedTable('wp_postmeta', [['meta_id' => 1, 'post_id' => '19', 'meta_key' => 'k']]);
        self::assertSame(
            '1',
            $db->get_var($db->prepare('SELECT meta_id FROM wp_postmeta WHERE post_id = %d', 19))
        );
    }

    public function testUninterpretableSqlThrowsAndNamesTheStatement(): void
    {
        $db = $this->seededDb();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SELECT p.ID FROM wp_postmeta p JOIN');
        $db->get_col('SELECT p.ID FROM wp_postmeta p JOIN wp_posts q ON q.ID = p.post_id');
    }

    /** The one join form the interpreter accepts, seeded the way the engine reads it. */
    private function relationshipDb(): FakeWpdb
    {
        $db = FakeWpdb::install();
        $db->setColumns('term_relationships', [
            'object_id' => 'bigint unsigned', 'term_taxonomy_id' => 'bigint unsigned', 'term_order' => 'int',
        ]);
        $db->setColumns('term_taxonomy', [
            'term_taxonomy_id' => 'bigint unsigned', 'term_id' => 'bigint unsigned', 'taxonomy' => 'varchar(32)',
        ]);
        $db->seedTable('term_relationships', [
            ['object_id' => 7, 'term_taxonomy_id' => 20, 'term_order' => 0],
            ['object_id' => 7, 'term_taxonomy_id' => 99, 'term_order' => 0],
        ]);
        $db->seedTable('term_taxonomy', [
            ['term_taxonomy_id' => 20, 'term_id' => 10, 'taxonomy' => 'wpforms_form_tag'],
        ]);

        return $db;
    }

    /**
     * The exact statement RelationshipMaterializer::lock_owner_relationships()
     * issues (agent/src/Apply/RelationshipMaterializer.php:287-295), which is
     * the only product reader of a join and the reason this form is
     * interpreted at all. The LEFT half is what carries the meaning: the
     * term_taxonomy_id 99 row has no term_taxonomy row, and the reader at
     * :310-318 turns that NULL taxonomy into a refusal. An INNER JOIN would
     * have dropped the row and hidden the very state it refuses on.
     */
    public function testSelectInterpretsTheOneSupportedLeftEquiJoin(): void
    {
        $db = $this->relationshipDb();

        self::assertSame(
            [
                ['term_taxonomy_id' => '20', 'term_order' => '0', 'taxonomy' => 'wpforms_form_tag'],
                ['term_taxonomy_id' => '99', 'term_order' => '0', 'taxonomy' => null],
            ],
            $db->get_results(
                'SELECT tr.term_taxonomy_id, tr.term_order, tt.taxonomy '
                . 'FROM wp_term_relationships tr FORCE INDEX (`object_id`) '
                . 'LEFT JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id '
                . 'WHERE tr.object_id = 7 ORDER BY tr.term_taxonomy_id ASC LIMIT 101 FOR UPDATE',
                ARRAY_A
            )
        );
    }

    /** ON is applied before WHERE, so a WHERE over the joined column sees the NULLs. */
    public function testLeftJoinAppliesItsOnConditionBeforeTheWhereClause(): void
    {
        $db = $this->relationshipDb();

        self::assertSame(
            [['term_taxonomy_id' => '99']],
            $db->get_results(
                'SELECT tr.term_taxonomy_id FROM wp_term_relationships tr '
                . 'LEFT JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id '
                . 'WHERE tt.taxonomy IS NULL',
                ARRAY_A
            )
        );
    }

    /**
     * A non-unique join key multiplies rows, because that is what the server
     * does. A fake that silently returned the first match would let a suite
     * assert a one-row result the real target never produces.
     */
    public function testLeftJoinEmitsOneRowPerMatchWhenTheJoinedKeyRepeats(): void
    {
        $db = $this->relationshipDb();
        $db->seedTable('term_taxonomy', [
            ['term_taxonomy_id' => 20, 'term_id' => 10, 'taxonomy' => 'wpforms_form_tag'],
            ['term_taxonomy_id' => 20, 'term_id' => 11, 'taxonomy' => 'category'],
        ]);

        self::assertSame(
            [['term_id' => '10'], ['term_id' => '11'], ['term_id' => null]],
            $db->get_results(
                'SELECT tt.term_id FROM wp_term_relationships tr '
                . 'LEFT JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id '
                . 'WHERE tr.object_id = 7',
                ARRAY_A
            )
        );
    }

    /**
     * Everything past that one form still refuses BY NAME. Each of these is a
     * shape the interpreter would have to model rather than look up, which is
     * the line the header draws: a suite that needs one is characterizing a
     * query whose behaviour belongs in the live certification.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function refusedJoinProvider(): array
    {
        $from = 'SELECT a.term_taxonomy_id FROM wp_term_relationships a ';

        return [
            'inner join' => [
                $from . 'INNER JOIN wp_term_taxonomy b ON b.term_taxonomy_id = a.term_taxonomy_id',
                'multi-table SELECT (JOIN/UNION)',
            ],
            'comma join' => [
                $from . ', wp_term_taxonomy b',
                'multi-table SELECT (JOIN/UNION)',
            ],
            'second join' => [
                $from . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id = a.term_taxonomy_id '
                    . 'LEFT JOIN wp_terms c ON c.term_id = b.term_id',
                'multi-table SELECT (JOIN/UNION)',
            ],
            'multi-condition on' => [
                $from . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id = a.term_taxonomy_id '
                    . "AND b.taxonomy = 'category'",
                'a multi-condition LEFT JOIN ... ON',
            ],
            'inequality on' => [
                $from . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id > a.term_taxonomy_id',
                'a LEFT JOIN ... ON that is not a single equality',
            ],
            'unqualified on' => [
                $from . 'LEFT JOIN wp_term_taxonomy b ON term_taxonomy_id = a.term_taxonomy_id',
                'a LEFT JOIN ... ON whose columns are not both table-qualified',
            ],
            'one-sided on' => [
                $from . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id = b.term_id',
                'a LEFT JOIN ... ON that does not name one column from each side',
            ],
            // MySQL's own "Not unique table/alias": with one name for two
            // sides, every qualified reference would resolve to whichever
            // side the resolver tested first.
            'colliding alias' => [
                $from . 'LEFT JOIN wp_term_taxonomy a ON a.term_taxonomy_id = a.term_taxonomy_id',
                'a LEFT JOIN whose table/alias name is not unique',
            ],
            'star projection' => [
                'SELECT * FROM wp_term_relationships a '
                    . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id = a.term_taxonomy_id',
                '`*` over a LEFT JOIN; name the columns',
            ],
            'aggregate' => [
                'SELECT COUNT(*) FROM wp_term_relationships a '
                    . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id = a.term_taxonomy_id',
                'COUNT(*)/GROUP BY over a LEFT JOIN',
            ],
        ];
    }

    #[DataProvider('refusedJoinProvider')]
    public function testEveryOtherJoinShapeStillRefusesByName(string $sql, string $reason): void
    {
        $db = $this->relationshipDb();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($reason);
        $db->get_results($sql, ARRAY_A);
    }

    /** A joined table nobody seeded refuses like any other unseeded read. */
    public function testLeftJoinAgainstAnUnseededTableThrows(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_term_relationships', [['object_id' => 7, 'term_taxonomy_id' => 20]]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("table 'wp_term_taxonomy' was never seeded");
        $db->get_results(
            'SELECT a.object_id, b.taxonomy FROM wp_term_relationships a '
            . 'LEFT JOIN wp_term_taxonomy b ON b.term_taxonomy_id = a.term_taxonomy_id',
            ARRAY_A
        );
    }

    public function testReadingAnUnseededTableThrowsRatherThanReturningNull(): void
    {
        $db = $this->seededDb();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("table 'wp_options' was never seeded");
        $db->get_var('SELECT option_value FROM wp_options WHERE option_id = 1');
    }

    public function testLikeExplicitEscapeUsesTheDeclaredCharacter(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_kv', [
            ['k' => 'a', 'v' => 'rank_math%'],
            ['k' => 'b', 'v' => 'rankXmath%'],
            ['k' => 'c', 'v' => 'rank\\math%'],
            ['k' => 'd', 'v' => 'rank_math9'],
            ['k' => 'e', 'v' => 'rank!math%'],
        ]);
        self::assertSame(['a'], $db->get_col($db->prepare(
            'SELECT k FROM wp_kv WHERE v LIKE %s ESCAPE %s', 'rank!_math!%', '!'
        )));
        self::assertSame(['a'], $db->get_col($db->prepare(
            'SELECT k FROM wp_kv WHERE v LIKE %s ESCAPE %s', 'rank\\_math\\%', '\\'
        )));
        self::assertSame(['c'], $db->get_col($db->prepare(
            'SELECT k FROM wp_kv WHERE v LIKE %s ESCAPE %s', 'rank\\math%', ''
        )));
        self::assertSame(['e'], $db->get_col($db->prepare(
            'SELECT k FROM wp_kv WHERE v LIKE %s ESCAPE %s', 'rank!!math!%', '!'
        )));
        self::assertSame(['b', 'c', 'd', 'e'], $db->get_col($db->prepare(
            'SELECT k FROM wp_kv WHERE v NOT LIKE %s ESCAPE %s ORDER BY k', 'rank!_math!%', '!'
        )));
    }

    #[DataProvider('unsupportedLikeEscapeProvider')]
    public function testLikeEscapeRefusesUnsupportedLiteralsEvenWithoutRows(string $escape): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_kv', [])->setColumns('wp_kv', ['v' => 'text']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('LIKE ESCAPE requires');
        $db->get_results("SELECT v FROM wp_kv WHERE v LIKE '%' ESCAPE " . $escape, ARRAY_A);
    }

    public static function unsupportedLikeEscapeProvider(): array
    {
        return [["'ab'"], ["'é'"], ['v'], ['NULL'], ['1']];
    }

    /**
     * PCRE's `$` also matches immediately before a final newline unless the
     * pattern is anchored with \z (or carries /D). Captured option and meta
     * payloads in this product routinely end with a newline, so an unanchored
     * LIKE would let a keyspace or transient-prefix assertion pass offline
     * against a value the live gate does not match.
     */
    public function testLikeIsAnchoredPastATrailingNewlineAndCountsCharacters(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_kv', [
            ['k' => 'a', 'v' => "payload\n"],
            ['k' => 'b', 'v' => 'payload'],
            ['k' => 'c', 'v' => 'é'],
            ['k' => 'd', 'v' => 'ab'],
        ]);

        self::assertSame(
            ['b'],
            $db->get_col($db->prepare('SELECT k FROM wp_kv WHERE v LIKE %s', 'payload'))
        );
        // `%` still spans the newline (it matches zero or more characters, so
        // both rows qualify) -- the fix is the anchor, not the wildcard.
        self::assertSame(
            ['a', 'b'],
            $db->get_col($db->prepare('SELECT k FROM wp_kv WHERE v LIKE %s', 'payload%'))
        );
        self::assertSame(
            ['a'],
            $db->get_col($db->prepare('SELECT k FROM wp_kv WHERE v LIKE %s', 'payload_'))
        );

        // MySQL's `_` is one CHARACTER, not one byte: the two-byte 'é' matches
        // a single underscore and the two-character 'ab' does not.
        self::assertSame(['c'], $db->get_col($db->prepare('SELECT k FROM wp_kv WHERE v LIKE %s', '_')));
    }

    /**
     * wpdb reads results over mysqli's TEXT protocol without
     * MYSQLI_OPT_INT_AND_FLOAT_NATIVE, so every non-NULL column value -- and
     * COUNT(*) -- reaches PHP as a string. check.php pushes strict ===, so a
     * fake handing back the fixture's int would make wprism_check_same(19, ...)
     * pass offline and fail live. rows() reads the store, not a result set,
     * and keeps the seeded types.
     */
    public function testResultValuesAreStringsWhileTheStoreKeepsFixtureTypes(): void
    {
        $db = FakeWpdb::install();
        $db->seedTable('wp_postmeta', [
            ['meta_id' => 1, 'post_id' => 19, 'meta_key' => 'k', 'meta_value' => null],
        ]);

        $row = $db->get_row('SELECT meta_id, post_id, meta_value FROM wp_postmeta', ARRAY_A);
        self::assertSame(['meta_id' => '1', 'post_id' => '19', 'meta_value' => null], $row);
        self::assertSame(['1'], $db->get_col('SELECT meta_id FROM wp_postmeta'));
        self::assertSame('1', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));

        $object = $db->get_row('SELECT post_id FROM wp_postmeta');
        self::assertIsObject($object);
        self::assertSame('19', $object->post_id);

        // The store is untouched, and the integer counters stay integers.
        self::assertSame(19, $db->rows('wp_postmeta')[0]['post_id']);
        self::assertSame(1, $db->num_rows);
        self::assertSame(1, $db->insert('wp_postmeta', ['post_id' => 20, 'meta_key' => 'j']));
        self::assertSame(2, $db->insert_id);
        self::assertSame(1, $db->rows_affected);
    }

    public function testServerVersionProbeSharesTheConfiguredWpdbBanner(): void
    {
        $db = FakeWpdb::install();
        self::assertSame('8.0.36', $db->get_var('SELECT VERSION()'));
        self::assertSame('8.0.36', $db->db_version());

        $banner = '11.8.8-MariaDB-1:11.8.8+maria~ubu2404';
        $db->setServerVersion($banner);
        self::assertSame($banner, $db->get_var('SELECT VERSION()'));
        self::assertSame('11.8.8', $db->db_version());
        self::assertSame(['SELECT VERSION()', 'SELECT VERSION()'], $db->queries());
    }

    /**
     * wpdb::flush() runs at the TOP of every query and clears rows_affected
     * and last_query as well as last_error/num_rows, so a read really does
     * reset the previous write's count on the live target.
     */
    public function testFlushClearsRowsAffectedAndLastQueryLikeWpdb(): void
    {
        $db = $this->seededDb();

        self::assertSame(1, $db->query($db->prepare(
            'UPDATE wp_postmeta SET meta_value = %s WHERE meta_id = %d',
            'x',
            1
        )));
        self::assertSame(1, $db->rows_affected);

        $db->get_results('SELECT * FROM wp_postmeta', ARRAY_A);
        self::assertSame(0, $db->rows_affected, 'a SELECT resets rows_affected');
        self::assertSame('SELECT * FROM wp_postmeta', $db->last_query);

        $db->flush();
        self::assertSame('', $db->last_query);
        self::assertSame(0, $db->num_rows);
    }

    // ---------------------------------------------------- failure seams

    public function testFailureHookSetsLastErrorAndReturnsTheWpdbFailureValue(): void
    {
        $db = $this->seededDb();
        $seen = [];

        $db->onQuery(static function (string $sql, string $method) use (&$seen): ?string {
            $seen[] = $method;

            return str_contains($sql, 'DELETE') ? 'injected delete failure' : null;
        });

        self::assertSame('3', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));
        self::assertSame('', $db->last_error, 'a successful statement clears last_error');

        self::assertFalse($db->query('DELETE FROM wp_postmeta WHERE meta_id = 1'));
        self::assertSame('injected delete failure', $db->last_error);
        self::assertSame(3, count($db->rows('wp_postmeta')), 'the vetoed statement never ran');
        self::assertSame(['get_var', 'query'], $seen);
    }

    public function testFailNextQueryFiresOnceAndOnlyForTheMatchingStatement(): void
    {
        $db = $this->seededDb();
        $db->failNextQuery('one-shot failure', 'UPDATE');

        self::assertSame('3', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));
        self::assertFalse($db->update('wp_postmeta', ['meta_value' => 'x'], ['meta_id' => 1]));
        self::assertSame('one-shot failure', $db->last_error);

        self::assertSame(1, $db->update('wp_postmeta', ['meta_value' => 'x'], ['meta_id' => 1]));
        self::assertSame('', $db->last_error);
    }

    public function testReadFailuresUseTheReturnValuesTheEngineChecksFor(): void
    {
        $db = $this->seededDb();
        $db->onQuery(static fn (): string => 'every read fails');

        // Only query() reports false. wpdb::get_col() builds its array
        // unconditionally and wpdb::get_results() returns last_result, which
        // wpdb::flush() emptied before the failing statement ran -- so both
        // hand back [] and the failure is observable ONLY through last_error.
        // A fake that returned false here would make the `!is_array($rows)`
        // arms in RegenerationContextStore::checked_get_col() and
        // ProviderSdk::checked_get_col() reachable, letting a suite pin a
        // branch the live gate can never take.
        self::assertSame([], $db->get_results('SELECT * FROM wp_postmeta', ARRAY_A));
        self::assertSame([], $db->get_col('SELECT meta_id FROM wp_postmeta'));
        self::assertNull($db->get_var('SELECT COUNT(*) FROM wp_postmeta'));
        self::assertNull($db->get_row('SELECT * FROM wp_postmeta', ARRAY_A));
        self::assertFalse($db->query('DELETE FROM wp_postmeta'));
        self::assertSame('every read fails', $db->last_error);
    }

    /**
     * The empty read-failure array must be distinguishable from a legitimate
     * empty result set, or the "[] not false" decision above would be a
     * silent data-loss seam rather than a faithful one.
     */
    public function testAnEmptyReadFailureIsDistinguishedFromAnEmptyResultSet(): void
    {
        $db = $this->seededDb();

        self::assertSame([], $db->get_col('SELECT meta_id FROM wp_postmeta WHERE post_id = 999'));
        self::assertSame('', $db->last_error, 'no rows is not an error');

        $db->failNextQuery('read blew up', 'SELECT');
        self::assertSame([], $db->get_col('SELECT meta_id FROM wp_postmeta WHERE post_id = 999'));
        self::assertSame('read blew up', $db->last_error);
    }

    public function testDeadlockSimulatorEmitsTheTextDbPhpMapsToTransientDbException(): void
    {
        // agent/src/Kernel/Db.php matches with stripos() on these two fragments; if
        // the literals here drift, every transient-contention suite would
        // silently start asserting the permanent-failure class instead.
        self::assertStringContainsString('Deadlock found', FakeWpdb::DEADLOCK_ERROR);
        self::assertStringContainsString('Lock wait timeout', FakeWpdb::LOCK_TIMEOUT_ERROR);

        $db = $this->seededDb();
        $db->simulateDeadlock();
        self::assertFalse($db->query('DELETE FROM wp_postmeta WHERE meta_id = 1'));
        self::assertSame(FakeWpdb::DEADLOCK_ERROR, $db->last_error);
    }

    public function testTransactionRollbackRestoresTheSnapshot(): void
    {
        $db = $this->seededDb();

        $db->query('START TRANSACTION');
        $db->query('DELETE FROM wp_postmeta WHERE post_id = 7');
        self::assertSame('1', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));

        $db->query('ROLLBACK');
        self::assertSame('3', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));
    }

    /**
     * InnoDB does not roll back AUTO_INCREMENT: an id consumed inside an
     * aborted transaction is burned. This matters precisely because
     * simulateDeadlock() exists to drive Db.php's TransientDbException retry,
     * and that retry IS rollback-then-reinsert -- a fake that recycled the id
     * would let a suite pin "the retry produced the same local_id", which is
     * false live, where Ledger::set() records a different one.
     */
    public function testRollbackBurnsTheAutoIncrementIdsItConsumed(): void
    {
        $db = $this->seededDb();

        $db->query('START TRANSACTION');
        $db->insert('wp_postmeta', ['post_id' => 9, 'meta_key' => 'attempt', 'meta_value' => '1']);
        self::assertSame(4, $db->insert_id);
        $db->query('ROLLBACK');

        self::assertSame(3, count($db->rows('wp_postmeta')), 'the row itself is rolled back');

        $db->insert('wp_postmeta', ['post_id' => 9, 'meta_key' => 'retry', 'meta_value' => '2']);
        self::assertSame(5, $db->insert_id, 'the rolled-back id 4 is burned, not handed out again');
    }

    // -------------------------------------------------------- WP stubs

    public function testOptionRoundTripKeepsWordPressReturnSemantics(): void
    {
        self::assertSame('fallback', get_option('wprism_missing', 'fallback'));
        self::assertTrue(add_option('wprism_thing', ['a' => 1]));
        self::assertFalse(add_option('wprism_thing', ['a' => 2]), 'add_option refuses an existing option');
        self::assertSame(['a' => 1], get_option('wprism_thing'));

        self::assertTrue(update_option('wprism_thing', ['a' => 2]));
        self::assertSame(['a' => 2], get_option('wprism_thing'));

        // The surprising-but-real one: WordPress skips the write and reports
        // false when nothing changed. Engine code must not read that as an
        // error, so the stub must not smooth it over.
        self::assertFalse(update_option('wprism_thing', ['a' => 2]));

        self::assertTrue(delete_option('wprism_thing'));
        self::assertFalse(delete_option('wprism_thing'));
        self::assertFalse(get_option('wprism_thing'));
    }

    public function testApplyFiltersRunsCallbacksInPriorityThenRegistrationOrder(): void
    {
        add_filter('wprism_test_hook', static fn (string $v): string => $v . '-late', 20);
        add_filter('wprism_test_hook', static fn (string $v): string => $v . '-early', 5);
        add_filter('wprism_test_hook', static fn (string $v): string => $v . '-alsoEarly', 5);

        self::assertSame('seed-early-alsoEarly-late', apply_filters('wprism_test_hook', 'seed'));
        self::assertTrue(has_filter('wprism_test_hook'));

        // has_filter() returns the PRIORITY for a named callback, which can be
        // 0; callers must compare with !== false, so it stays an int.
        $named = static fn (string $v): string => $v;
        add_filter('wprism_priority_zero', $named, 0);
        self::assertSame(0, has_filter('wprism_priority_zero', $named));
        self::assertFalse(has_filter('wprism_priority_zero', static fn (string $v): string => $v . '!'));
    }

    public function testApplyFiltersTruncatesArgumentsToAcceptedArgs(): void
    {
        add_filter(
            'wprism_narrow_hook',
            static function (string $value): string {
                return $value . ':' . func_num_args();
            },
            10,
            1
        );

        self::assertSame('v:1', apply_filters('wprism_narrow_hook', 'v', 'extra', 'more'));
    }

    public function testCacheEventsRecordMissesSoInvalidationOrderIsAssertable(): void
    {
        wp_cache_set('k', 'v', 'options');
        self::assertTrue(wp_cache_delete('k', 'options'));
        self::assertFalse(wp_cache_delete('alloptions', 'options'), 'a miss still returns false');

        self::assertSame(
            [
                ['op' => 'set', 'group' => 'options', 'key' => 'k'],
                ['op' => 'delete', 'group' => 'options', 'key' => 'k'],
                ['op' => 'delete', 'group' => 'options', 'key' => 'alloptions'],
            ],
            WpStore::instance()->cacheEvents
        );
    }

    /**
     * `make regress-offline-all` does NOT give each leaf its own TMPDIR the
     * way tools/offline.php does, so a fixed /tmp/wprism-uploads would be shared
     * by every suite running under `make -j8` at that moment: one suite's
     * fixture files visible to another, and "the upload dir holds exactly N
     * files" flaking on scheduling alone.
     */
    public function testUploadDirIsUniquePerStoreAndCleanedUpOnReset(): void
    {
        $first = WpStore::instance();
        self::assertStringContainsString((string) getmypid(), $first->uploadBaseDir);

        $dir = $first->ensureUploadDir();
        self::assertDirectoryExists($dir);
        file_put_contents($dir . '/fixture.txt', 'bytes');

        $second = WpStore::reset();
        self::assertNotSame($first->uploadBaseDir, $second->uploadBaseDir);
        self::assertDirectoryDoesNotExist($dir, 'reset() removes the directory it created');

        // A store that never created a directory has nothing to remove, and
        // reset() must not touch a path a suite repointed by hand.
        $borrowed = sys_get_temp_dir() . '/wprism-not-ours-' . getmypid();
        mkdir($borrowed);
        $second->uploadBaseDir = $borrowed;
        WpStore::reset();
        self::assertDirectoryExists($borrowed);
        rmdir($borrowed);
    }

    // ------------------------------------------------------ assertions

    public function testJsonEqualIgnoresObjectKeyOrderButNotArrayOrder(): void
    {
        $output = $this->capture(static function (): void {
            wprism_check_json_equal(
                '{"b":1,"a":{"d":4,"c":3}}',
                '{"a":{"c":3,"d":4},"b":1}',
                'object key order is not part of the JSON data model'
            );
        });

        self::assertStringStartsWith('ok: ', $output);
        self::assertSame(0, wprism_check_failed());

        // The negative runs in a subprocess so its deliberate STDERR line does
        // not land in this run's output and read as a real failure.
        $failing = $this->runSuite(
            "wprism_check_json_equal('[1,2]', '[2,1]', 'array element order IS part of it');\n"
            . "wprism_check_summary('json');\n"
        );
        self::assertSame(1, $failing['status']);
        self::assertStringContainsString('first difference at: 0', $failing['stderr']);
    }

    public function testCheckSameCountsAndLocatesTheFirstDifference(): void
    {
        $output = $this->capture(static function (): void {
            wprism_check_same(['a' => ['b' => 1]], ['a' => ['b' => 1]], 'identical structures pass');
        });
        self::assertSame("ok: identical structures pass\n", $output);

        self::assertNull(wprism_check_diff_path(['a' => 1], ['a' => 1]));
        self::assertSame('a.b', wprism_check_diff_path(['a' => ['b' => 1]], ['a' => ['b' => 2]]));
        self::assertSame('a.c', wprism_check_diff_path(['a' => ['b' => 1]], ['a' => ['b' => 1, 'c' => 3]]));

        $stats = wprism_check_stats();
        self::assertSame(1, $stats['passed']);
        self::assertSame(0, $stats['failed']);
    }

    public function testCheckThrowsRequiresTheExactExceptionClass(): void
    {
        $output = $this->capture(static function (): void {
            wprism_check_throws(
                static function (): void {
                    throw new \RuntimeException('boom: context');
                },
                \RuntimeException::class,
                'the typed failure surfaces',
                'context'
            );
        });

        self::assertStringStartsWith('ok: ', $output);
        self::assertSame(0, wprism_check_failed());
    }

    public function testCheckRefusesMatchesARealCommandRefusalException(): void
    {
        $output = $this->capture(static function (): void {
            wprism_check_refuses(
                static function (): void {
                    throw \WPrism\CommandRefusalException::invalidArgument('plan', '--target');
                },
                'invalid_arguments',
                'a missing argument is a public, machine-readable refusal'
            );
        });

        self::assertStringStartsWith('ok: ', $output);
        self::assertSame(0, wprism_check_failed());

        // The reason code, not the message, is the contract: a refusal with a
        // different code must fail even though the class matches. Run out of
        // process so the deliberate STDERR line stays out of this run.
        $failing = $this->runSuite(
            'require_once ' . var_export(WPRISM_REPO_ROOT . '/agent/src/Kernel/CommandRefusal.php', true) . ";\n"
            . "wprism_check_refuses(\n"
            . "    static function (): void { throw \\WPrism\\CommandRefusalException::applyRefused('nope', 'retry later'); },\n"
            . "    'invalid_arguments',\n"
            . "    'a different reason code is a failure'\n"
            . ");\n"
            . "wprism_check_summary('refusal');\n"
        );
        self::assertSame(1, $failing['status']);
        self::assertStringContainsString(
            'expected reason code invalid_arguments, got apply_refused',
            $failing['stderr']
        );
    }

    public function testCheckClosureFeedsTheSharedCounter(): void
    {
        $check = wprism_check_closure();

        $output = $this->capture(static function () use ($check): void {
            $check(true, 'the legacy closure shape still works');
        });

        self::assertSame("ok: the legacy closure shape still works\n", $output);
        self::assertSame(1, wprism_check_stats()['passed']);
    }

    // ----------------------------------------------- summary/exit status

    /**
     * wprism_check_summary() calls exit(), so its real contract -- exit status
     * plus which stream each line lands on -- is only observable from outside
     * the process.
     *
     * @return array{stdout:string,stderr:string,status:int}
     */
    private function runSuite(string $body): array
    {
        $file = tempnam(sys_get_temp_dir(), 'wprism-harness-') . '.php';
        file_put_contents(
            $file,
            "<?php\nrequire_once " . var_export(WPRISM_REPO_ROOT . '/sandbox/tests/lib/check.php', true) . ";\n" . $body
        );

        $process = proc_open(
            [PHP_BINARY, $file],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        unlink($file);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => $status];
    }

    public function testSummaryExitsZeroAndPrintsPassOnStdout(): void
    {
        $result = $this->runSuite("wprism_check(true, 'a');\nwprism_check_summary('demo');\n");

        self::assertSame(0, $result['status']);
        self::assertSame("ok: a\nPASS: demo (1 assertion)\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testSummaryExitsOneAndRoutesFailuresToStderr(): void
    {
        $result = $this->runSuite("wprism_check(true, 'a');\nwprism_check(false, 'b');\nwprism_check_summary('demo');\n");

        self::assertSame(1, $result['status']);
        self::assertSame("ok: a\n", $result['stdout'], 'failures must not pollute stdout');
        self::assertStringContainsString("FAIL: b\n", $result['stderr']);
        self::assertStringContainsString("FAIL: 1 assertion\n", $result['stderr']);
        self::assertStringContainsString(" - b\n", $result['stderr']);
    }

    public function testSummaryFailsASuiteThatAssertedNothing(): void
    {
        // A suite that silently stops asserting (an early return, a guard that
        // skipped the body) would otherwise exit 0 and hide behind the gate.
        $result = $this->runSuite("wprism_check_summary('demo');\n");

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('ran no assertions', $result['stderr']);
    }

    public function testSummaryOutputCannotBeMistakenForAPhpDiagnostic(): void
    {
        // sandbox/tests/offline_diagnostics_guard.sh fails any offline command
        // whose output matches PHP's diagnostic framing. The harness prints
        // "FAIL:" lines on the failure path, so they must never carry the
        // " in "/" on line " source suffix the guard keys on.
        $result = $this->runSuite("wprism_check(false, 'b');\nwprism_check_summary('demo');\n");

        self::assertSame(0, preg_match(self::DIAGNOSTIC_PATTERN, $result['stdout'] . $result['stderr']));
    }

    /**
     * The dangerous case is a MULTI-LINE payload, not the single-line message
     * above: wprism_check_repr() used to hand var_export()'s raw newlines to
     * wprism_check_detail(), which indented only the first line, so a captured
     * value whose second line read "Warning: ... in x.php on line 1" landed at
     * column 0 and matched the guard exactly. The suite is already failing at
     * that point, so nothing turns green->red; what it does is retitle a real
     * assertion failure as "offline command emitted PHP diagnostics" and send
     * the reader after a PHP error that does not exist.
     */
    public function testMultiLinePayloadsCannotBeMistakenForAPhpDiagnostic(): void
    {
        $payload = "line1\nWarning: something bad in Foo.php on line 12\nFatal error: x in y.php";
        $result = $this->runSuite(
            'wprism_check_same(' . var_export($payload, true) . ", 'other', 'multi-line evidence');\n"
            . 'wprism_check_throws(static function (): void { throw new RuntimeException('
            . var_export($payload, true) . "); }, LogicException::class, 'wrong class');\n"
            . "wprism_check_summary('multiline');\n"
        );

        self::assertSame(1, $result['status']);
        self::assertSame(0, preg_match(self::DIAGNOSTIC_PATTERN, $result['stdout'] . $result['stderr']));

        // Still readable: the payload is escaped onto one line, not dropped,
        // and the difference between "a\nb" and "a b" survives.
        self::assertStringContainsString('Warning: something bad', $result['stderr']);
        self::assertStringNotContainsString("\nWarning:", $result['stderr']);
    }

    /** The exact regex sandbox/tests/offline_diagnostics_guard.sh greps with. */
    private const DIAGNOSTIC_PATTERN = '/^(PHP (Deprecated|Warning|Fatal error|Parse error):'
        . '|((Deprecated|Warning|Fatal error|Parse error):.*( in | on line )))/m';
}
