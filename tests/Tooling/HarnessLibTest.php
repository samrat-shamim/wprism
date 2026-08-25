<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use DuoTest\FakeWpdb;
use DuoTest\WpStore;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/sandbox/tests/lib/check.php';
require_once DUO_REPO_ROOT . '/sandbox/tests/lib/wp_stubs.php';
require_once DUO_REPO_ROOT . '/sandbox/tests/lib/FakeWpdb.php';
require_once DUO_REPO_ROOT . '/agent/src/Kernel/CommandRefusal.php';

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
 * OUTPUT DISCIPLINE. duo_check() prints "ok:" to STDOUT and phpunit.xml.dist
 * sets beStrictAboutOutputDuringTests, so every helper call here is wrapped in
 * an output buffer. duo_check_summary() calls exit(), so its contract (stream
 * routing plus exit status) is verified in a real subprocess instead.
 */
#[CoversNothing]
final class HarnessLibTest extends TestCase
{
    protected function setUp(): void
    {
        duo_check_reset();
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

    public function testSelectCountAggregatesMatchedRows(): void
    {
        $db = $this->seededDb();

        self::assertSame('3', $db->get_var('SELECT COUNT(*) FROM wp_postmeta'));
        self::assertSame(
            '2',
            $db->get_var($db->prepare('SELECT COUNT(*) FROM wp_postmeta WHERE meta_key = %s', 'alpha'))
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

    public function testEnabledFullApplyExtensionsRefuseMalformedStatementsPerHandlerFamily(): void
    {
        $cases = [
            'attachment marker' => [
                'sql' => "SELECT k, OCTET_LENGTH(v) AS v_bytes, CASE WHEN v IS NOT NULL AND OCTET_LENGTH(v) <= 512 THEN v ELSE NULL END AS bounded_v, bogus FROM `wp_duo_kv` WHERE LOWER(LEFT(k, 14)) = 'attachment_fs:' ORDER BY BINARY k ASC LIMIT 2",
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
                'sql' => "INSERT INTO `wp_duo_kv` (k, v) VALUES ('promotion_lock', '{}') ON DUPLICATE KEY UPDATE v = IF((JSON_EXTRACT(v, '$.owner')), VALUES(v), v) AND 1=1",
                'write' => true,
            ],
            'promotion update' => [
                'sql' => "UPDATE `wp_duo_kv` SET v = '{}' WHERE k = 'promotion_lock' AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = 'owner' AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = 'artifact' AND 1=1",
                'write' => true,
            ],
            'prune delete' => [
                'sql' => 'DELETE m FROM wp_duo_map m LEFT JOIN wp_posts po ON po.ID = m.local_id LEFT JOIN wp_terms t ON t.term_id = m.local_id WHERE m.id_kind = \'post\' AND po.ID IS NULL',
                'write' => true,
            ],
        ];

        foreach ($cases as $label => $case) {
            $db = FakeWpdb::install()->enableFullApplySqlExtensions();
            $db->seedTable('wp_duo_kv', [])->setColumns('wp_duo_kv', ['k' => 'varchar(191)', 'v' => 'longtext']);
            $db->seedTable('wp_duo_map', [])->setColumns('wp_duo_map', ['local_id' => 'bigint', 'id_kind' => 'varchar(32)']);
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
     * fake models (duo_kv `k`, option_name, meta_key, packed composite ids
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

    public function testReadingAnUnseededTableThrowsRatherThanReturningNull(): void
    {
        $db = $this->seededDb();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("table 'wp_options' was never seeded");
        $db->get_var('SELECT option_value FROM wp_options WHERE option_id = 1');
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
     * fake handing back the fixture's int would make duo_check_same(19, ...)
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
        self::assertSame('fallback', get_option('duo_missing', 'fallback'));
        self::assertTrue(add_option('duo_thing', ['a' => 1]));
        self::assertFalse(add_option('duo_thing', ['a' => 2]), 'add_option refuses an existing option');
        self::assertSame(['a' => 1], get_option('duo_thing'));

        self::assertTrue(update_option('duo_thing', ['a' => 2]));
        self::assertSame(['a' => 2], get_option('duo_thing'));

        // The surprising-but-real one: WordPress skips the write and reports
        // false when nothing changed. Engine code must not read that as an
        // error, so the stub must not smooth it over.
        self::assertFalse(update_option('duo_thing', ['a' => 2]));

        self::assertTrue(delete_option('duo_thing'));
        self::assertFalse(delete_option('duo_thing'));
        self::assertFalse(get_option('duo_thing'));
    }

    public function testApplyFiltersRunsCallbacksInPriorityThenRegistrationOrder(): void
    {
        add_filter('duo_test_hook', static fn (string $v): string => $v . '-late', 20);
        add_filter('duo_test_hook', static fn (string $v): string => $v . '-early', 5);
        add_filter('duo_test_hook', static fn (string $v): string => $v . '-alsoEarly', 5);

        self::assertSame('seed-early-alsoEarly-late', apply_filters('duo_test_hook', 'seed'));
        self::assertTrue(has_filter('duo_test_hook'));

        // has_filter() returns the PRIORITY for a named callback, which can be
        // 0; callers must compare with !== false, so it stays an int.
        $named = static fn (string $v): string => $v;
        add_filter('duo_priority_zero', $named, 0);
        self::assertSame(0, has_filter('duo_priority_zero', $named));
        self::assertFalse(has_filter('duo_priority_zero', static fn (string $v): string => $v . '!'));
    }

    public function testApplyFiltersTruncatesArgumentsToAcceptedArgs(): void
    {
        add_filter(
            'duo_narrow_hook',
            static function (string $value): string {
                return $value . ':' . func_num_args();
            },
            10,
            1
        );

        self::assertSame('v:1', apply_filters('duo_narrow_hook', 'v', 'extra', 'more'));
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
     * way tools/offline.php does, so a fixed /tmp/duo-uploads would be shared
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
        $borrowed = sys_get_temp_dir() . '/duo-not-ours-' . getmypid();
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
            duo_check_json_equal(
                '{"b":1,"a":{"d":4,"c":3}}',
                '{"a":{"c":3,"d":4},"b":1}',
                'object key order is not part of the JSON data model'
            );
        });

        self::assertStringStartsWith('ok: ', $output);
        self::assertSame(0, duo_check_failed());

        // The negative runs in a subprocess so its deliberate STDERR line does
        // not land in this run's output and read as a real failure.
        $failing = $this->runSuite(
            "duo_check_json_equal('[1,2]', '[2,1]', 'array element order IS part of it');\n"
            . "duo_check_summary('json');\n"
        );
        self::assertSame(1, $failing['status']);
        self::assertStringContainsString('first difference at: 0', $failing['stderr']);
    }

    public function testCheckSameCountsAndLocatesTheFirstDifference(): void
    {
        $output = $this->capture(static function (): void {
            duo_check_same(['a' => ['b' => 1]], ['a' => ['b' => 1]], 'identical structures pass');
        });
        self::assertSame("ok: identical structures pass\n", $output);

        self::assertNull(duo_check_diff_path(['a' => 1], ['a' => 1]));
        self::assertSame('a.b', duo_check_diff_path(['a' => ['b' => 1]], ['a' => ['b' => 2]]));
        self::assertSame('a.c', duo_check_diff_path(['a' => ['b' => 1]], ['a' => ['b' => 1, 'c' => 3]]));

        $stats = duo_check_stats();
        self::assertSame(1, $stats['passed']);
        self::assertSame(0, $stats['failed']);
    }

    public function testCheckThrowsRequiresTheExactExceptionClass(): void
    {
        $output = $this->capture(static function (): void {
            duo_check_throws(
                static function (): void {
                    throw new \RuntimeException('boom: context');
                },
                \RuntimeException::class,
                'the typed failure surfaces',
                'context'
            );
        });

        self::assertStringStartsWith('ok: ', $output);
        self::assertSame(0, duo_check_failed());
    }

    public function testCheckRefusesMatchesARealCommandRefusalException(): void
    {
        $output = $this->capture(static function (): void {
            duo_check_refuses(
                static function (): void {
                    throw \Duo\CommandRefusalException::invalidArgument('plan', '--target');
                },
                'invalid_arguments',
                'a missing argument is a public, machine-readable refusal'
            );
        });

        self::assertStringStartsWith('ok: ', $output);
        self::assertSame(0, duo_check_failed());

        // The reason code, not the message, is the contract: a refusal with a
        // different code must fail even though the class matches. Run out of
        // process so the deliberate STDERR line stays out of this run.
        $failing = $this->runSuite(
            'require_once ' . var_export(DUO_REPO_ROOT . '/agent/src/Kernel/CommandRefusal.php', true) . ";\n"
            . "duo_check_refuses(\n"
            . "    static function (): void { throw \\Duo\\CommandRefusalException::applyRefused('nope', 'retry later'); },\n"
            . "    'invalid_arguments',\n"
            . "    'a different reason code is a failure'\n"
            . ");\n"
            . "duo_check_summary('refusal');\n"
        );
        self::assertSame(1, $failing['status']);
        self::assertStringContainsString(
            'expected reason code invalid_arguments, got apply_refused',
            $failing['stderr']
        );
    }

    public function testCheckClosureFeedsTheSharedCounter(): void
    {
        $check = duo_check_closure();

        $output = $this->capture(static function () use ($check): void {
            $check(true, 'the legacy closure shape still works');
        });

        self::assertSame("ok: the legacy closure shape still works\n", $output);
        self::assertSame(1, duo_check_stats()['passed']);
    }

    // ----------------------------------------------- summary/exit status

    /**
     * duo_check_summary() calls exit(), so its real contract -- exit status
     * plus which stream each line lands on -- is only observable from outside
     * the process.
     *
     * @return array{stdout:string,stderr:string,status:int}
     */
    private function runSuite(string $body): array
    {
        $file = tempnam(sys_get_temp_dir(), 'duo-harness-') . '.php';
        file_put_contents(
            $file,
            "<?php\nrequire_once " . var_export(DUO_REPO_ROOT . '/sandbox/tests/lib/check.php', true) . ";\n" . $body
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
        $result = $this->runSuite("duo_check(true, 'a');\nduo_check_summary('demo');\n");

        self::assertSame(0, $result['status']);
        self::assertSame("ok: a\nPASS: demo (1 assertion)\n", $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    public function testSummaryExitsOneAndRoutesFailuresToStderr(): void
    {
        $result = $this->runSuite("duo_check(true, 'a');\nduo_check(false, 'b');\nduo_check_summary('demo');\n");

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
        $result = $this->runSuite("duo_check_summary('demo');\n");

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('ran no assertions', $result['stderr']);
    }

    public function testSummaryOutputCannotBeMistakenForAPhpDiagnostic(): void
    {
        // sandbox/tests/offline_diagnostics_guard.sh fails any offline command
        // whose output matches PHP's diagnostic framing. The harness prints
        // "FAIL:" lines on the failure path, so they must never carry the
        // " in "/" on line " source suffix the guard keys on.
        $result = $this->runSuite("duo_check(false, 'b');\nduo_check_summary('demo');\n");

        self::assertSame(0, preg_match(self::DIAGNOSTIC_PATTERN, $result['stdout'] . $result['stderr']));
    }

    /**
     * The dangerous case is a MULTI-LINE payload, not the single-line message
     * above: duo_check_repr() used to hand var_export()'s raw newlines to
     * duo_check_detail(), which indented only the first line, so a captured
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
            'duo_check_same(' . var_export($payload, true) . ", 'other', 'multi-line evidence');\n"
            . 'duo_check_throws(static function (): void { throw new RuntimeException('
            . var_export($payload, true) . "); }, LogicException::class, 'wrong class');\n"
            . "duo_check_summary('multiline');\n"
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
