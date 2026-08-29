<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Publication/Publish.php';
require_once __DIR__ . '/InitFaults.php';
require_once __DIR__ . '/InitOwnedArtifacts.php';
require_once __DIR__ . '/InitProtocol.php';

/** Typed view of the existing duo-init-attempt/v1 envelope. */
final class InitAttemptRecord {
    private const PHASES = [
        'preparing', 'locked', 'git-planned', 'git-reserved',
        'gitattributes-planned', 'gitattributes-ready',
        'git-lfs-planned', 'git-lfs-ready',
        'git-ready',
        'gitignore-planned', 'gitignore-ready', 'code-stage-planned',
        'code-staging', 'code-staged', 'code-root-planned',
        'code-root-reserved', 'code-publish-planned',
        // DUO-3499: the code lock is published inside the already-reserved
        // code root, between the verified payload rename and `code-ready`, so
        // the identity recorded at `code-ready` covers the lock too.
        'code-lock-planned', 'code-lock-written', 'code-ready',
        'config-planned', 'config-ready', 'media-planned', 'state-planned',
        'capture-ready', 'capture-payload-ready',
    ];

    /** @param array<string,mixed> $record */
    private function __construct(private array $record) {}

    /** @param array<string,mixed> $record */
    public static function fromArray(array $record, string $label): self {
        $payload = $record;
        unset($payload['record_sha256']);
        $expectedKeys = ['format', 'owned', 'phase', 'proposal', 'repository', 'repository_identity'];
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($keys !== $expectedKeys
            || ($record['format'] ?? null) !== InitProtocol::ATTEMPT_FORMAT
            || !is_string($record['phase'] ?? null)
            || !is_array($record['owned'] ?? null)
            || !is_array($record['proposal'] ?? null)
            || !is_string($record['repository'] ?? null)
            || !is_string($record['repository_identity'] ?? null)) {
            throw new \RuntimeException("duo: interrupted init $label is malformed or unsealed");
        }
        return new self($record);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->record; }

    public function assertForwardTo(self $next): void {
        $current = $this->record;
        $candidate = $next->record;
        $currentRank = array_search($current['phase'] ?? null, self::PHASES, true);
        $nextRank = array_search($candidate['phase'] ?? null, self::PHASES, true);
        $payloadRefresh = ($current['phase'] ?? null) === 'capture-payload-ready'
            && ($candidate['phase'] ?? null) === 'capture-payload-ready';
        if (!is_int($currentRank) || !is_int($nextRank)
            || (!$payloadRefresh && $nextRank <= $currentRank)
            || ($current['format'] ?? null) !== ($candidate['format'] ?? null)
            || ($current['repository'] ?? null) !== ($candidate['repository'] ?? null)
            || ($current['repository_identity'] ?? null) !== ($candidate['repository_identity'] ?? null)
            || Canon::encode($current['proposal'] ?? null) !== Canon::encode($candidate['proposal'] ?? null)) {
            throw new \RuntimeException(
                'duo: interrupted init next-record is not a forward transition of its canonical sealed attempt'
            );
        }
    }
}

/** Sealed fixed-slot journal for first-init phase and ownership evidence. */
final class InitAttemptJournal {
    public const FILE = InitProtocol::ATTEMPT_FILE;
    public const NEXT_FILE = InitProtocol::ATTEMPT_NEXT_FILE;

    /** @return ?array<string,mixed> */
    public static function read(string $repo): ?array {
        $path = rtrim($repo, '/') . '/' . self::FILE;
        $nextPath = rtrim($repo, '/') . '/' . self::NEXT_FILE;
        $hasAttempt = file_exists($path) || is_link($path);
        $hasNext = file_exists($nextPath) || is_link($nextPath);
        if (!$hasAttempt && $hasNext) {
            throw new \RuntimeException(
                'duo: interrupted init next-record exists without its canonical sealed attempt; retained it'
            );
        }
        if (!$hasAttempt) {
            return null;
        }
        $record = self::read_file($path, self::FILE);
        if ($hasNext) {
            $next = self::read_file($nextPath, self::NEXT_FILE);
            self::assert_transition($record, $next);
        }
        return $record;
    }

    /** @return array<string,mixed> */
    public static function read_file(string $path, string $label): array {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException("duo: interrupted init $label is not an ordinary regular file");
        }
        $raw = Canon::read_file($path);
        $record = Canon::decode($raw);
        if (!is_array($record)) {
            throw new \RuntimeException("duo: interrupted init $label is not an object");
        }
        $seal = $record['record_sha256'] ?? null;
        // Validate against the exact payload bytes. Decoding and re-encoding
        // cannot preserve the distinction between empty JSON objects/arrays.
        $payloadBytes = preg_replace(
            '/^    "record_sha256": "[a-f0-9]{64}",\n/m',
            '',
            $raw,
            1,
            $sealMatches
        );
        $payloadHash = is_string($payloadBytes) && $sealMatches === 1
            ? hash('sha256', $payloadBytes)
            : '';
        if (!is_string($seal) || preg_match('/^[a-f0-9]{64}$/D', $seal) !== 1
            || !hash_equals($seal, $payloadHash)) {
            throw new \RuntimeException("duo: interrupted init $label is malformed or unsealed");
        }
        return InitAttemptRecord::fromArray($record, $label)->toArray();
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $next */
    public static function assert_transition(array $current, array $next): void {
        InitAttemptRecord::fromArray($current, self::FILE)->assertForwardTo(
            InitAttemptRecord::fromArray($next, self::NEXT_FILE)
        );
    }

    /** @param array<string,mixed> $attempt @return array{previous:?string,published:string} */
    public static function write(string $repo, array $attempt, string $expectedIdentity): array {
        unset($attempt['record_sha256']);
        InitAttemptRecord::fromArray($attempt, self::FILE);
        $attempt['record_sha256'] = hash('sha256', Canon::encode($attempt));
        $path = rtrim($repo, '/') . '/' . self::FILE;
        $bytes = Canon::encode($attempt);
        $parent = dirname($path);
        if ($expectedIdentity === 'absent') {
            Publish::write_file_fresh(
                $path,
                $bytes,
                self::FILE,
                Publish::directory_ownership_identity($parent)
            );
            return ['previous' => null, 'published' => InitOwnedArtifacts::regular_file_identity($path, self::FILE)];
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/D', $expectedIdentity) !== 1
            || !hash_equals($expectedIdentity, InitOwnedArtifacts::regular_file_identity($path, self::FILE))) {
            throw new \RuntimeException('duo: interrupted init record changed before its durable phase transition');
        }
        $tmp = $parent . '/' . self::NEXT_FILE;
        $tmpIdentity = Publish::write_file_fresh(
            $tmp,
            $bytes,
            self::FILE . ' transition',
            Publish::directory_ownership_identity($parent)
        );
        try {
            InitFaults::checkpoint('attempt-transition-pre-rename');
            InitFaults::checkpoint('attempt-transition-pre-rename-' . (string) $attempt['phase']);
            if (!@rename($tmp, $path)) {
                throw new \RuntimeException('duo: interrupted init record phase transition could not be published');
            }
            Publish::sync_parent($path);
        } finally {
            if (file_exists($tmp) || is_link($tmp)) {
                Publish::remove_owned_file($tmp, $tmpIdentity, self::FILE . ' transition');
            }
        }
        return ['previous' => null, 'published' => InitOwnedArtifacts::regular_file_identity($path, self::FILE)];
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array{previous:?string,published:string} $publication
     * @return array{0:array<string,mixed>,1:array{previous:?string,published:string}}
     */
    public static function resolve(string $repo, array $attempt, array $publication): array {
        $path = rtrim($repo, '/') . '/' . self::FILE;
        $nextPath = rtrim($repo, '/') . '/' . self::NEXT_FILE;
        if (!file_exists($nextPath) && !is_link($nextPath)) {
            return [$attempt, $publication];
        }
        $next = self::read_file($nextPath, self::NEXT_FILE);
        self::assert_transition($attempt, $next);
        if (!hash_equals(
            (string) $publication['published'],
            InitOwnedArtifacts::regular_file_identity($path, self::FILE)
        )) {
            throw new \RuntimeException('duo: interrupted init canonical journal changed before transition recovery');
        }
        if (!@rename($nextPath, $path)) {
            throw new \RuntimeException('duo: interrupted init could not publish its sealed next journal phase');
        }
        Publish::sync_parent($path);
        return [
            $next,
            ['previous' => null, 'published' => InitOwnedArtifacts::regular_file_identity($path, self::FILE)],
        ];
    }

    /**
     * @param array<string,mixed> $attempt
     * @param array{previous:?string,published:string} $attemptPublication
     */
    public static function remove_records(
        string $repo,
        string $logicalRepo,
        array $attempt,
        array $attemptPublication,
        bool $completed = false
    ): void {
        $nextAttempt = rtrim($repo, '/') . '/' . self::NEXT_FILE;
        if (file_exists($nextAttempt) || is_link($nextAttempt)) {
            $next = self::read_file($nextAttempt, self::NEXT_FILE);
            self::assert_transition($attempt, $next);
            InitOwnedArtifacts::remove_exact_owned_file(
                $nextAttempt,
                InitOwnedArtifacts::regular_file_identity($nextAttempt, self::NEXT_FILE),
                self::NEXT_FILE
            );
        }
        InitOwnedArtifacts::remove_exact_owned_file(
            rtrim($repo, '/') . '/' . self::FILE,
            (string) $attemptPublication['published'],
            self::FILE,
            $completed
        );
    }
}
