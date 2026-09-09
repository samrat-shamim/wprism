<?php
declare(strict_types=1);

// Namespace interposition exercises the real public primitive against native
// files. Only named I/O failures/race boundaries are injected; no shipped test
// callbacks, fake filesystem, or second consumer implementation is involved.
namespace WPrismTest {
    final class PrivateBytesFaults {
        public static array $events = [];
        public static ?\Closure $hook = null;
        public static ?int $uid = null;
        public static ?string $failure = null;
        public static ?string $metadataPath = null;
        public static array $metadata = [];

        public static function reset(): void {
            self::$events = []; self::$hook = null; self::$uid = null;
            self::$failure = null; self::$metadataPath = null; self::$metadata = [];
        }

        public static function event(string $phase, string $path, mixed $handle = null): void {
            self::$events[] = [$phase, $path];
            if (self::$hook !== null) (self::$hook)($phase, $path, $handle);
        }

        public static function path(mixed $handle): string {
            return stream_get_meta_data($handle)['uri'];
        }
    }
}

namespace WPrism {
    use WPrismTest\PrivateBytesFaults as Fault;

    function posix_geteuid(): int { return Fault::$uid ?? \posix_geteuid(); }
    function fopen(string $path, string $mode) {
        Fault::event('before-open:' . $mode, $path);
        $handle = \fopen($path, $mode);
        Fault::event('after-open:' . $mode, $path, $handle);
        return $handle;
    }
    function lstat(string $path): array|false {
        Fault::event('before-lstat', $path);
        $stat = \lstat($path);
        if (is_array($stat) && $path === Fault::$metadataPath) $stat = array_replace($stat, Fault::$metadata);
        return $stat;
    }
    function fread($handle, int $size): string|false {
        Fault::event('before-read:' . $size, Fault::path($handle), $handle);
        if (Fault::$failure === 'read') return false;
        $bytes = \fread($handle, $size);
        Fault::event('after-read:' . $size, Fault::path($handle), $handle);
        return $bytes;
    }
    function fwrite($handle, string $bytes): int|false {
        Fault::event('before-write', Fault::path($handle), $handle);
        if (Fault::$failure === 'write-zero') return 0;
        if (Fault::$failure === 'write-false') return false;
        if (Fault::$failure === 'partial') $bytes = substr($bytes, 0, 3);
        return \fwrite($handle, $bytes);
    }
    function fflush($handle): bool {
        return Fault::$failure !== 'flush' && \fflush($handle);
    }
    function fsync($handle): bool {
        $directory = (\fstat($handle)['mode'] & 0170000) === 0040000;
        Fault::event('before-sync', Fault::path($handle), $handle);
        if (Fault::$failure === ($directory ? 'directory-sync' : 'file-sync')) return false;
        $result = \fsync($handle);
        Fault::event('after-sync', Fault::path($handle), $handle);
        return $result;
    }
    function fclose($handle): bool {
        $result = \fclose($handle);
        return Fault::$failure === 'close' ? false : $result;
    }
}

namespace {
    require_once __DIR__ . '/../../lib/check.php';
    require_once __DIR__ . '/../../../../agent/src/Kernel/PrivateFileBytes.php';

    use WPrism\PrivateFileBytes;
    use WPrismTest\PrivateBytesFaults as Fault;

    // An actual disabled PHP function, not a test stub claiming it is absent.
    if (($argv[1] ?? '') === '--feature-probe') {
        try {
            if ($argv[2] === 'bootstrap') {
                define('ABSPATH', $argv[3] . '/unused-wordpress/');
                require __DIR__ . '/../../../../agent/wprism.php';
            } elseif ($argv[2] === 'create') PrivateFileBytes::create($argv[3], 'disabled.bin', 'bytes');
            else PrivateFileBytes::read($argv[3], 'binary.bin', 128);
            echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        } catch (RuntimeException $failure) {
            echo json_encode(['refusal' => $failure->getMessage()], JSON_THROW_ON_ERROR);
        }
        exit(0);
    }

    $scratch = realpath(sys_get_temp_dir()) . '/wprism-private-bytes-' . bin2hex(random_bytes(8));
    mkdir($scratch, 0700);
    $remove = static function (string $path) use (&$remove): void {
        if (is_dir($path) && !is_link($path)) {
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) unlink($path);
    };
    register_shutdown_function(static fn() => $remove($scratch));
    $refuses = static fn(callable $call, string $label, ?string $message = null) =>
        wprism_check_throws($call, RuntimeException::class, $label, $message);
    $events = static fn(string $prefix, ?string $path = null): array => array_values(array_filter(
        Fault::$events,
        static fn(array $event): bool => str_starts_with($event[0], $prefix) && ($path === null || $event[1] === $path)
    ));

    $binary = "private\0\xff\r\nno final newline";
    $mask = umask(0027);
    try {
        foreach (['empty.bin' => '', 'binary.bin' => $binary, 'bound.bin' => str_repeat('x', PrivateFileBytes::MAX_BYTES)] as $name => $bytes) {
            Fault::reset();
            wprism_check_same(hash('sha256', $bytes), PrivateFileBytes::create($scratch, $name, $bytes), "$name create returns exact byte hash");
            wprism_check_same(0027, umask(), 'exclusive creation restores caller umask');
            $stat = lstat("$scratch/$name");
            wprism_check_same([0600, posix_geteuid(), 1], [$stat['mode'] & 07777, $stat['uid'], $stat['nlink']], 'native new leaf is euid-owned 0600 single-link');
            wprism_check_same([['before-sync', "$scratch/$name"], ['before-sync', $scratch]], $events('before-sync'), 'created inode and already-opened parent are durably synced in order');
            wprism_check_same(1, count($events('before-open:x+b', "$scratch/$name")), 'create opens the leaf exactly once without replacement');
            wprism_check_same(1, count($events('before-open:rb', $scratch)), 'create opens its parent once, not a reopened durability authority');
            Fault::reset();
            wprism_check_same($bytes, PrivateFileBytes::read($scratch, $name, strlen($bytes)), "$name bounded read preserves exact binary/empty bytes");
            wprism_check_same(1, count($events('before-open:rb', "$scratch/$name")), 'read consumes one opened file only');
            wprism_check_same([], $events('before-write'), 'read never writes');
        }
        $refuses(static fn() => PrivateFileBytes::create($scratch, 'binary.bin', 'replacement'), 'occupied request cannot be overwritten', 'exclusive creation');
        wprism_check_same($binary, file_get_contents("$scratch/binary.bin"), 'exclusive failure preserves preexisting bytes');
        wprism_check_same(0027, umask(), 'exclusive failure restores caller umask too');
    } finally { umask($mask); }

    foreach (['', '.', '..', '.hidden', '/absolute', '../escape', 'dir/name', 'back\\slash', "newline\n", "nul\0", 'é', str_repeat('x', 129)] as $name) {
        $refuses(static fn() => PrivateFileBytes::create($scratch, $name, 'x'), 'unsafe basename refuses before creation');
        $refuses(static fn() => PrivateFileBytes::read($scratch, $name, 16), 'unsafe basename refuses before reading');
    }
    foreach (['', '/', 'relative', 'php://memory', "$scratch/", "$scratch//child", "$scratch/./child", "$scratch/../child", "$scratch/ba\\ck", "$scratch/new\nline", "$scratch/nul\0", '/' . str_repeat('x', 4096)] as $parent) {
        $refuses(static fn() => PrivateFileBytes::create($parent, 'invalid.bin', 'x'), 'noncanonical parent refuses before creation');
    }
    $refuses(static fn() => PrivateFileBytes::create($scratch, 'oversize.bin', str_repeat('x', PrivateFileBytes::MAX_BYTES + 1)), 'oversized creation refuses before opening');
    wprism_check(!file_exists("$scratch/oversize.bin"), 'oversized creation leaves no partial leaf');
    foreach ([-1, PrivateFileBytes::MAX_BYTES + 1] as $bound) {
        $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', $bound), 'invalid caller bound cannot enlarge the hard bound');
    }
    Fault::reset();
    $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', strlen($binary) - 1), 'native file size refuses before byte allocation', 'byte bound');
    wprism_check_same([], $events('before-read'), 'over-budget input consumes no bytes');

    mkdir("$scratch/public", 0755);
    $refuses(static fn() => PrivateFileBytes::create("$scratch/public", 'new.bin', 'x'), 'immediate parent must be exactly 0700');
    wprism_check_same(0755, fileperms("$scratch/public") & 07777, 'parent refusal performs no chmod repair');
    mkdir("$scratch/writable", 0700); chmod("$scratch/writable", 0777);
    mkdir("$scratch/writable/private", 0700);
    $refuses(static fn() => PrivateFileBytes::create("$scratch/writable/private", 'new.bin', 'x'), 'private immediate parent cannot hide non-sticky writable ancestor');
    chmod("$scratch/writable", 01777);
    wprism_check_same(hash('sha256', 'x'), PrivateFileBytes::create("$scratch/writable/private", 'new.bin', 'x'), 'sticky writable ancestor protects its owned child');
    Fault::$metadataPath = "$scratch/writable/private"; Fault::$metadata = ['uid' => posix_geteuid() + 1];
    $refuses(static fn() => PrivateFileBytes::read("$scratch/writable/private", 'new.bin', 1), 'sticky bit cannot admit a foreign-owned child');
    Fault::reset();

    symlink($scratch, "$scratch/alias");
    $refuses(static fn() => PrivateFileBytes::read("$scratch/alias", 'binary.bin', 128), 'symlinked immediate parent is not canonical');
    $refuses(static fn() => PrivateFileBytes::create("$scratch/alias/writable/private", 'linked.bin', 'x'), 'symlinked ancestor is not canonical');
    symlink("$scratch/binary.bin", "$scratch/link.bin");
    symlink("$scratch/absent.bin", "$scratch/dangling.bin");
    mkdir("$scratch/directory.bin", 0700);
    if (!posix_mkfifo("$scratch/fifo.bin", 0600)) throw new RuntimeException('native FIFO fixture allocation failed');
    link("$scratch/binary.bin", "$scratch/hard.bin");
    foreach (['link.bin', 'dangling.bin', 'directory.bin', 'fifo.bin', 'hard.bin', 'binary.bin'] as $name) {
        Fault::reset();
        $refuses(static fn() => PrivateFileBytes::read($scratch, $name, 128), "$name refuses nonregular or multi-link input before open");
        wprism_check_same([], $events('before-open'), 'unsafe leaf is refused without opening it');
    }
    unlink("$scratch/hard.bin");
    foreach ([0640, 0666, 0400, 04600] as $mode) {
        chmod("$scratch/binary.bin", $mode);
        $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', 128), 'wrong private file mode refuses without repair');
        wprism_check_same($mode, fileperms("$scratch/binary.bin") & 07777, 'refused mode remains untouched');
    }
    chmod("$scratch/binary.bin", 0600);
    foreach ([['uid' => posix_geteuid() + 1], ['ino' => '1'], ['size' => -1], ['size' => PrivateFileBytes::MAX_BYTES + 1]] as $metadata) {
        Fault::reset(); Fault::$metadataPath = "$scratch/binary.bin"; Fault::$metadata = $metadata;
        $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', 128), 'malformed/foreign/oversized named metadata refuses');
        wprism_check_same([], $events('before-read'), 'metadata refusal occurs before consuming bytes');
    }
    Fault::reset(); Fault::$uid = -1;
    $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', 128), 'unavailable effective UID is not inferred from file owner');
    Fault::reset(); Fault::$uid = posix_geteuid() + 1;
    $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', 128), 'different effective UID cannot read the prior private parent');
    Fault::reset();

    foreach (['write-zero', 'write-false', 'flush', 'file-sync', 'directory-sync', 'read', 'close'] as $failure) {
        Fault::reset(); Fault::$failure = $failure;
        $refuses(static fn() => PrivateFileBytes::create($scratch, "$failure.bin", 'retained bytes'), "$failure cannot publish a success hash");
        wprism_check(file_exists("$scratch/$failure.bin"), 'failed creation preserves its allocated private file');
        wprism_check_same(0600, fileperms("$scratch/$failure.bin") & 07777, 'failed file remains private without repair');
    }
    Fault::reset(); Fault::$failure = 'partial';
    wprism_check_same(hash('sha256', 'several short writes'), PrivateFileBytes::create($scratch, 'partial.bin', 'several short writes'), 'positive short writes are completed rather than truncated');
    wprism_check(count($events('before-write')) > 1, 'partial write control actually performs multiple writes');
    Fault::reset();

    foreach (['before-open:rb', 'after-open:rb', 'after-read:' . strlen($binary)] as $phase) {
        $name = 'swap-' . count(scandir($scratch)) . '.bin';
        PrivateFileBytes::create($scratch, $name, $binary);
        $fired = false;
        Fault::$hook = static function (string $event, string $path) use ($phase, $scratch, $name, $binary, &$fired): void {
            if ($fired || $path !== "$scratch/$name" || $event !== $phase) return;
            $fired = true;
            rename($path, "$path.saved");
            $mask = umask(0077);
            try { file_put_contents($path, str_repeat('z', strlen($binary))); } finally { umask($mask); }
        };
        $refuses(static fn() => PrivateFileBytes::read($scratch, $name, 128), "inode replacement at $phase is not accepted");
        wprism_check($fired, 'replacement control reached the intended I/O boundary');
        Fault::reset();
    }
    // Append after the last requested bytes: the separate EOF sentinel must
    // reject even before the post-read metadata comparison can do so.
    PrivateFileBytes::create($scratch, 'append.bin', 'abc');
    $fired = false;
    Fault::$hook = static function (string $event, string $path) use ($scratch, &$fired): void {
        if (!$fired && $event === 'after-read:3' && $path === "$scratch/append.bin") {
            $fired = true; file_put_contents($path, 'extra', FILE_APPEND);
        }
    };
    $refuses(static fn() => PrivateFileBytes::read($scratch, 'append.bin', 32), 'append after observed size cannot hide past EOF', 'unobserved bytes');
    wprism_check($fired, 'append control reached the real opened-file read');
    Fault::reset();
    mkdir("$scratch/drift", 0700);
    Fault::$hook = static function (string $event, string $path) use ($scratch): void {
        if ($event === 'after-open:x+b' && $path === "$scratch/drift/request.bin") chmod("$scratch/drift", 0755);
    };
    $refuses(static fn() => PrivateFileBytes::create("$scratch/drift", 'request.bin', 'secret'), 'parent permission drift refuses before writing private bytes');
    wprism_check_same('', file_get_contents("$scratch/drift/request.bin"), 'parent drift leaves only the empty allocated file');
    chmod("$scratch/drift", 0700); Fault::reset();

    foreach (['after-open:x+b', 'after-sync'] as $phase) {
        $parent = $scratch . '/renamed-' . str_replace(':', '-', $phase);
        mkdir($parent, 0700);
        $fired = false;
        Fault::$hook = static function (string $event, string $path) use ($phase, $parent, &$fired): void {
            if (!$fired && $event === $phase && $path === "$parent/request.bin") {
                $fired = true; rename($parent, "$parent.saved"); mkdir($parent, 0700);
            }
        };
        $refuses(static fn() => PrivateFileBytes::create($parent, 'request.bin', 'secret'), 'opened parent replacement never publishes a success hash');
        wprism_check($fired && file_exists("$parent.saved/request.bin") && !file_exists("$parent/request.bin"), 'parent-swap refusal preserves the originally created inode');
        Fault::reset();
    }
    $fired = false;
    Fault::$hook = static function (string $event, string $path) use ($scratch, &$fired): void {
        if (!$fired && $event === 'after-open:rb' && $path === "$scratch/binary.bin") {
            $fired = true; Fault::$uid = posix_geteuid() + 1;
        }
    };
    $refuses(static fn() => PrivateFileBytes::read($scratch, 'binary.bin', 128), 'effective UID drift after opening cannot consume bytes', 'effective UID changed');
    wprism_check_same([], $events('before-read'), 'UID continuity precedes the first byte read');
    Fault::reset();

    foreach ([['posix_geteuid', 'create', 'requires posix_geteuid'], ['posix_geteuid', 'read', 'requires posix_geteuid'], ['fsync', 'create', 'requires fsync'], ['fsync', 'read', null], ['posix_geteuid,fsync', 'bootstrap', null]] as [$disabled, $operation, $reason]) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', "disable_functions=$disabled", __FILE__, '--feature-probe', $operation, $scratch],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('feature probe could not start');
        fclose($pipes[0]); $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]); $exit = proc_close($process);
        wprism_check_same([0, ''], [$exit, $stderr], 'disabled native feature produces a clean bounded result');
        $record = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        wprism_check($reason === null ? ($record['ok'] ?? false) === true : str_contains($record['refusal'] ?? '', $reason), "$disabled gate is local to $operation's actual requirement");
    }
    wprism_check(!file_exists("$scratch/disabled.bin"), 'missing native creation prerequisites produce no file');
    wprism_check_same($binary, PrivateFileBytes::read($scratch, 'binary.bin', 128), 'restored controls leave ordinary once-read bytes working');
    wprism_check_summary('private file bytes');
}
