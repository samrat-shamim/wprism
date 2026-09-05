<?php
/**
 * Security regression for target-generated PHP consumed as data.
 *
 * The engine must parse the exact var_export() subset without `require`, bind
 * the read to a prior digest, reject executable syntax, and remain finite and
 * race-aware while the named file changes.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Kernel/PhpLiteralData.php';

$scratch = sys_get_temp_dir() . '/wprism-php-literal-' . bin2hex(random_bytes(8));
if (!mkdir($scratch, 0700, true) && !is_dir($scratch)) {
    throw new RuntimeException('could not create PHP literal fixture root');
}

/** @return string exact fixture path */
function php_literal_fixture(string $scratch, string $name, string $source): string {
    $path = $scratch . '/' . $name;
    if (file_put_contents($path, $source) !== strlen($source)) {
        throw new RuntimeException("could not write PHP literal fixture $name");
    }
    return $path;
}

/** @return mixed */
function php_literal_read(string $path): mixed {
    return \WPrism\PhpLiteralData::read($path, hash_file('sha256', $path));
}

try {
    $rows = [
        12 => [
            'id' => 12,
            'code' => "quote ' slash \\ newline\n東京 🚀",
            'scope' => 'content',
            'priority' => -17,
            'condition_id' => 0,
            'active' => true,
            'optional' => null,
        ],
    ];
    $valid = php_literal_fixture(
        $scratch,
        'valid.php',
        "<?php\n\nif ( ! defined( 'ABSPATH' ) ) { return; }\n\nreturn "
            . var_export($rows, true) . ";\n"
    );
    wprism_check_same(
        $rows,
        php_literal_read($valid),
        'the closed reader round-trips the exact nested var_export array subset used by plugin indexes'
    );

    foreach ([
        'call.php' => "<?php return ['value' => strlen('x')];\n",
        'concat.php' => "<?php return ['a' . 'b'];\n",
        'double.php' => "<?php return [\"a\\qb\"];\n",
        'binary.php' => "<?php return [b'bytes'];\n",
        'float.php' => "<?php return [1.25];\n",
        'positive.php' => "<?php return [+1];\n",
        'duplicate.php' => "<?php return ['key' => 1, 'key' => 2];\n",
        'coercing-key.php' => "<?php return ['1' => 'ambiguous'];\n",
        'trailing.php' => "<?php return []; file_put_contents('/tmp/never', 'x');\n",
        'interpolation.php' => "<?php return [\"{$rows[12]['scope']}\"];\n",
        'widened-preamble.php' => "<?php if (!defined('ABSPATH')) { file_put_contents('/tmp/never', 'x'); return; } return [];\n",
    ] as $name => $source) {
        $path = php_literal_fixture($scratch, $name, $source);
        wprism_check_throws(
            static fn() => php_literal_read($path),
            RuntimeException::class,
            "the data-only parser refuses $name instead of widening its literal grammar",
            'PHP literal data'
        );
    }

    $sentinel = $scratch . '/side-effect';
    $sideEffect = php_literal_fixture(
        $scratch,
        'side-effect.php',
        '<?php file_put_contents(' . var_export($sentinel, true) . ", 'executed'); return [];\n"
    );
    wprism_check_throws(
        static fn() => php_literal_read($sideEffect),
        RuntimeException::class,
        'target-generated PHP containing a side effect is rejected as data',
        'PHP literal data'
    );
    wprism_check(!file_exists($sentinel), 'refusing target PHP never executes its side effect');

    wprism_check_throws(
        static fn() => \WPrism\PhpLiteralData::read($valid, str_repeat('0', 64)),
        RuntimeException::class,
        'a valid literal file cannot cross a stale or invented digest witness',
        'disagrees with its observed SHA-256'
    );

    $link = $scratch . '/linked.php';
    if (!symlink($valid, $link)) {
        throw new RuntimeException('could not create PHP literal symlink fixture');
    }
    wprism_check_throws(
        static fn() => \WPrism\PhpLiteralData::read($link, hash_file('sha256', $valid)),
        RuntimeException::class,
        'a symlinked literal source is rejected rather than followed',
        'symlinked'
    );

    $reflection = new ReflectionClass(\WPrism\PhpLiteralData::class);
    wprism_check_same(
        16777216,
        $reflection->getReflectionConstant('MAX_BYTES')->getValue(),
        'the literal reader owns an exact 16 MiB source-byte bound'
    );
    wprism_check_same(
        64,
        $reflection->getReflectionConstant('MAX_DEPTH')->getValue(),
        'the literal reader owns an exact 64-level value-depth bound'
    );
    wprism_check_same(
        100000,
        $reflection->getReflectionConstant('MAX_NODES')->getValue(),
        'the literal reader owns an exact 100000-node value bound'
    );

    $tooDeep = php_literal_fixture(
        $scratch,
        'too-deep.php',
        '<?php return ' . str_repeat('[', 66) . 'null' . str_repeat(']', 66) . ";\n"
    );
    wprism_check_throws(
        static fn() => php_literal_read($tooDeep),
        RuntimeException::class,
        'a literal array beyond the exact recursion budget is refused',
        'depth bound'
    );

    $tooMany = php_literal_fixture(
        $scratch,
        'too-many.php',
        '<?php return [' . implode(',', array_fill(0, 100000, 'null')) . "];\n"
    );
    wprism_check_throws(
        static fn() => php_literal_read($tooMany),
        RuntimeException::class,
        'a literal array beyond the exact node budget is refused',
        'node bound'
    );

    $tooLarge = php_literal_fixture(
        $scratch,
        'too-large.php',
        '<?php return [];' . str_repeat(' ', 16777216)
    );
    wprism_check_throws(
        static fn() => php_literal_read($tooLarge),
        RuntimeException::class,
        'a literal source beyond the exact byte budget is refused before parsing',
        'byte bound'
    );

    $hook = $reflection->getProperty('testFileObservationHook');
    $replaced = php_literal_fixture($scratch, 'replaced.php', "<?php return ['old'];\n");
    $replacement = php_literal_fixture($scratch, 'replacement.php', "<?php return ['new'];\n");
    $replacedHash = hash_file('sha256', $replaced);
    $hook->setValue(null, static function (string $phase, string $path) use ($replaced, $replacement): void {
        if ($phase === 'after-file-stat' && $path === $replaced) {
            rename($replacement, $replaced);
        }
    });
    wprism_check_throws(
        static fn() => \WPrism\PhpLiteralData::read($replaced, $replacedHash),
        RuntimeException::class,
        'same-size path replacement between stat and open is refused by inode identity',
        'changed while being inspected'
    );

    $grown = php_literal_fixture($scratch, 'grown.php', "<?php return ['old'];\n");
    $grownHash = hash_file('sha256', $grown);
    $hook->setValue(null, static function (string $phase, string $path) use ($grown): void {
        if ($phase === 'before-file-revalidation' && $path === $grown) {
            file_put_contents($grown, ' ', FILE_APPEND);
        }
    });
    wprism_check_throws(
        static fn() => \WPrism\PhpLiteralData::read($grown, $grownHash),
        RuntimeException::class,
        'a literal source that grows after its exact read is refused on revalidation',
        'changed while being read'
    );

    $rewritten = php_literal_fixture($scratch, 'rewritten.php', "<?php return ['old'];\n");
    $rewrittenHash = hash_file('sha256', $rewritten);
    $hook->setValue(null, static function (string $phase, string $path) use ($rewritten): void {
        if ($phase === 'before-file-revalidation' && $path === $rewritten) {
            file_put_contents($rewritten, "<?php return ['new'];\n");
        }
    });
    wprism_check_throws(
        static fn() => \WPrism\PhpLiteralData::read($rewritten, $rewrittenHash),
        RuntimeException::class,
        'a same-size same-inode rewrite is refused even when PHP exposes only whole-second timestamps',
        'changed while being read'
    );
    $hook->setValue(null, null);
} finally {
    if (isset($hook) && $hook instanceof ReflectionProperty) {
        $hook->setValue(null, null);
    }
    foreach (scandir($scratch) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            @unlink($scratch . '/' . $entry);
        }
    }
    @rmdir($scratch);
}

wprism_check_summary('PHP return-literal data is digest-bound, finite, race-checked, and never executed');
