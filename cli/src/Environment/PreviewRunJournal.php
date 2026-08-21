<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentLifecycle.php';

/**
 * Durable controller evidence for one origin-demand-to-preview composition.
 *
 * OriginAuthority and EnvironmentLifecycle remain the remote authorities. This
 * journal closes only the gap between those independently replayable state
 * machines: the operation id is durable before demand creation, and every poll
 * sequence is durable before its signed request. No export bytes or key
 * material is stored here.
 */
final class PreviewRunJournal {
    public const RUN_FORMAT = 'duo-cloud-preview-run/v1';
    public const EVENT_FORMAT = 'duo-cloud-preview-event/v1';
    private const TARGET_INDEX_FORMAT = 'duo-cloud-preview-target-index/v1';
    private const MAX_IMMUTABLE_BYTES = 16777216;
    private const MAX_TARGET_INDEX_BYTES = 1048576;

    public function __construct(private string $base) {
        $this->base = rtrim($base, '/');
        foreach ([
            $this->base,
            $this->base . '/runs',
            $this->base . '/targets',
            $this->base . '/tombstones',
            $this->base . '/worktrees',
        ] as $path) {
            $this->ensurePrivateDirectory($path);
        }
    }

    public function synchronizedTarget(string $targetEnvironment, callable $callback): mixed {
        self::environment($targetEnvironment, 'preview target environment');
        $lockPath = $this->base . '/targets/' . hash('sha256', $targetEnvironment) . '.lock';
        $lock = $this->openPrivateLock($lockPath, "preview target '$targetEnvironment'");
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Resume the one unreaped target run only when its complete intent is byte
     * identical. A changed branch, production ref, driver pin, or TTL must be
     * preceded by exact reap rather than silently stealing the site slot.
     *
     * @param array<string,mixed> $intent
     * @return array{events:list<array<string,mixed>>,operation_id:string,resumed:bool,run:array<string,mixed>}
     */
    public function resumeOrStart(string $targetEnvironment, array $intent): array {
        self::assertIntent($intent, $targetEnvironment);
        $intentSha256 = hash('sha256', EnvironmentLifecycleCanon::encode($intent));
        $latest = $this->latestForTarget($targetEnvironment);
        if ($latest !== null && self::eventData($latest['events'], 'reaped') === null) {
            if (!hash_equals((string) $latest['run']['intent_sha256'], $intentSha256)
                || EnvironmentLifecycleCanon::encode($latest['run']['intent'])
                    !== EnvironmentLifecycleCanon::encode($intent)) {
                throw new \RuntimeException(
                    "target '$targetEnvironment' already has an unreaped cloud preview; reap it before changing intent"
                );
            }
            return $latest + ['resumed' => true];
        }

        $operationId = self::operationId();
        $run = [
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'format' => self::RUN_FORMAT,
            'intent' => $intent,
            'intent_sha256' => $intentSha256,
            'operation_id' => $operationId,
            'target_environment' => $targetEnvironment,
        ];
        $previousReaped = $latest === null ? null : $latest['operation_id'];
        $index = $this->targetIndex(
            $targetEnvironment,
            $operationId,
            'installing',
            $previousReaped,
            $run
        );
        $this->writeTargetIndex($index);
        $this->installIndexedRun($index);
        $this->writeTargetIndex($this->withIndexPhase($index, 'active'));
        return [
            'events' => $this->events($operationId),
            'operation_id' => $operationId,
            'resumed' => false,
            'run' => $run,
        ];
    }

    /** @param array<string,mixed> $data */
    public function append(string $operationId, string $event, array $data): void {
        self::operationId($operationId);
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $event) !== 1
            || (array_is_list($data) && $data !== [])) {
            throw new \RuntimeException('invalid preview journal event');
        }
        $dir = $this->runDir($operationId);
        $this->ensurePrivateDirectory($dir);
        $this->ensurePrivateDirectory($dir . '/events');
        $run = null;
        if ($event === 'reaped') {
            $run = $this->readRun($operationId);
            $index = $this->readTargetIndex((string) $run['target_environment']);
            if ($index === null || $index['operation_id'] !== $operationId
                || !in_array($index['phase'], ['active', 'reaping'], true)) {
                throw new \RuntimeException(
                    "preview operation '$operationId' is not the active target run"
                );
            }
        }
        $lock = $this->openPrivateLock($dir . '/.lock', "preview operation '$operationId'");
        try {
            $events = $this->events($operationId);
            $sequence = count($events) + 1;
            $previous = $events === []
                ? str_repeat('0', 64)
                : hash(
                    'sha256',
                    EnvironmentLifecycleCanon::encode($events[count($events) - 1]) . "\n"
                );
            $record = [
                'data' => $data,
                'event' => $event,
                'format' => self::EVENT_FORMAT,
                'operation_id' => $operationId,
                'previous_event_sha256' => $previous,
                'sequence' => $sequence,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $this->writeImmutable(
                sprintf('%s/events/%04d-%s.json', $dir, $sequence, $event),
                $record
            );
            if ($event === 'reaped') {
                if (!is_array($run)) {
                    throw new \RuntimeException("preview operation '$operationId' lost its run identity");
                }
                $this->finishReapedIndex($run);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array<string,mixed>> */
    public function events(string $operationId): array {
        self::operationId($operationId);
        $this->ensurePrivateDirectory($this->runDir($operationId));
        $this->ensurePrivateDirectory($this->runDir($operationId) . '/events');
        $paths = glob($this->runDir($operationId) . '/events/*.json') ?: [];
        sort($paths, SORT_STRING);
        $events = [];
        $previous = str_repeat('0', 64);
        foreach ($paths as $index => $path) {
            $this->reconcileTemporary($path);
            $event = $this->readCanonical($path, 'preview event');
            self::assertEvent($event, $operationId, $index + 1, $previous);
            $previous = hash(
                'sha256',
                EnvironmentLifecycleCanon::encode($event) . "\n"
            );
            $events[] = $event;
        }
        return $events;
    }

    /** @return ?array{events:list<array<string,mixed>>,operation_id:string,run:array<string,mixed>} */
    public function latestForTarget(string $targetEnvironment): ?array {
        self::environment($targetEnvironment, 'preview target environment');
        $index = $this->readTargetIndex($targetEnvironment);
        if ($index === null) {
            return null;
        }
        if ($index['phase'] === 'installing') {
            $this->installIndexedRun($index);
            $index = $this->withIndexPhase($index, 'active');
            $this->writeTargetIndex($index);
        }
        $operationId = $index['operation_id'];
        $run = $this->readRun($operationId);
        if (EnvironmentLifecycleCanon::encode($run)
            !== EnvironmentLifecycleCanon::encode($index['run'])) {
            throw new \RuntimeException("preview target '$targetEnvironment' index changed its run identity");
        }
        $events = $this->events($operationId);
        $reaped = self::eventData($events, 'reaped');
        if ($reaped === null && $index['phase'] === 'reaped') {
            // The target summary may reach stable storage while the controller's
            // final event does not survive the same crash. Replay from active;
            // the lifecycle authority returns its immutable prior reap receipt.
            $index = $this->withIndexPhase($index, 'active');
            $this->writeTargetIndex($index);
        }
        if ($reaped !== null && in_array($index['phase'], ['active', 'reaping'], true)) {
            $this->finishReapedIndex($run);
            $index = $this->readTargetIndex($targetEnvironment);
            if ($index === null) {
                throw new \RuntimeException("preview target '$targetEnvironment' lost its reap index");
            }
        }
        if (($index['phase'] === 'reaped') !== ($reaped !== null)
            || ($index['phase'] === 'reaped' && $index['previous_reaped_operation_id'] !== null)) {
            throw new \RuntimeException("preview target '$targetEnvironment' index contradicts its event journal");
        }
        return [
            'events' => $events,
            'operation_id' => $operationId,
            'run' => $run,
        ];
    }

    /** @param list<array<string,mixed>> $events @return ?array<string,mixed> */
    public static function eventData(array $events, string $event): ?array {
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) === $event) {
                $data = $events[$index]['data'] ?? null;
                if (!is_array($data) || array_is_list($data)) {
                    throw new \RuntimeException("preview event '$event' has malformed data");
                }
                return $data;
            }
        }
        return null;
    }

    public function worktreePath(string $operationId): string {
        self::operationId($operationId);
        return $this->base . '/worktrees/' . $operationId;
    }

    public function runDir(string $operationId): string {
        self::operationId($operationId);
        return $this->base . '/runs/' . $operationId;
    }

    /** @return array<string,mixed> */
    private function readRun(string $operationId): array {
        $path = $this->runDir($operationId) . '/run.json';
        $this->reconcileTemporary($path);
        $run = $this->readCanonical($path, 'preview run');
        self::assertRun($run, $operationId);
        return $run;
    }

    /** @param array<string,mixed> $run @return array<string,mixed> */
    private function targetIndex(
        string $targetEnvironment,
        string $operationId,
        string $phase,
        ?string $previousReapedOperationId,
        array $run
    ): array {
        $index = [
            'format' => self::TARGET_INDEX_FORMAT,
            'operation_id' => $operationId,
            'phase' => $phase,
            'previous_reaped_operation_id' => $previousReapedOperationId,
            'run' => $run,
            'target_environment' => $targetEnvironment,
        ];
        self::assertTargetIndex($index, $targetEnvironment);
        return $index;
    }

    /** @param array<string,mixed> $index @return array<string,mixed> */
    private function withIndexPhase(array $index, string $phase): array {
        $index['phase'] = $phase;
        if ($phase === 'reaped') {
            $index['previous_reaped_operation_id'] = null;
        }
        self::assertTargetIndex($index, (string) ($index['target_environment'] ?? ''));
        return $index;
    }

    private function targetIndexPath(string $targetEnvironment): string {
        self::environment($targetEnvironment, 'preview target environment');
        return $this->base . '/targets/' . hash('sha256', $targetEnvironment) . '.json';
    }

    /** @return ?array<string,mixed> */
    private function readTargetIndex(string $targetEnvironment): ?array {
        $path = $this->targetIndexPath($targetEnvironment);
        $this->reconcileMutableTemporary($path);
        $stat = $this->freshLstat($path);
        if ($stat === false) {
            return null;
        }
        if ((int) ($stat['size'] ?? -1) < 2
            || (int) ($stat['size'] ?? -1) > self::MAX_TARGET_INDEX_BYTES) {
            throw new \RuntimeException("preview target '$targetEnvironment' index has an invalid byte size");
        }
        $index = $this->readCanonical($path, 'preview target index');
        self::assertTargetIndex($index, $targetEnvironment);
        return $index;
    }

    /** @param array<string,mixed> $index */
    private function writeTargetIndex(array $index): void {
        $targetEnvironment = $index['target_environment'] ?? null;
        if (!is_string($targetEnvironment)) {
            throw new \RuntimeException('preview target index has no target environment');
        }
        self::assertTargetIndex($index, $targetEnvironment);
        $bytes = EnvironmentLifecycleCanon::encode($index) . "\n";
        if (strlen($bytes) > self::MAX_TARGET_INDEX_BYTES) {
            throw new \RuntimeException("preview target '$targetEnvironment' index exceeds its byte limit");
        }
        $this->writeMutable($this->targetIndexPath($targetEnvironment), $bytes);
    }

    /** @param array<string,mixed> $index */
    private function installIndexedRun(array $index): void {
        $targetEnvironment = (string) ($index['target_environment'] ?? '');
        self::assertTargetIndex($index, $targetEnvironment);
        if ($index['phase'] !== 'installing') {
            throw new \RuntimeException("preview target '$targetEnvironment' is not installing a run");
        }
        $operationId = $index['operation_id'];
        $dir = $this->runDir($operationId);
        $stat = $this->freshLstat($dir);
        if ($stat === false) {
            if (!@mkdir($dir, 0700) || !@chmod($dir, 0700)) {
                throw new \RuntimeException("could not create preview operation '$operationId'");
            }
            $this->syncDirectory(dirname($dir));
        }
        $this->ensurePrivateDirectory($dir);
        $this->ensurePrivateDirectory($dir . '/events');
        $this->writeImmutable($dir . '/run.json', $index['run']);
        $events = $this->events($operationId);
        if ($events === []) {
            $this->append($operationId, 'prepared', [
                'intent_sha256' => $index['run']['intent_sha256'],
            ]);
            $events = $this->events($operationId);
        }
        $prepared = self::eventData($events, 'prepared');
        if ($prepared !== ['intent_sha256' => $index['run']['intent_sha256']]) {
            throw new \RuntimeException("preview operation '$operationId' has no exact prepared event");
        }
    }

    /** @param array<string,mixed> $run */
    private function finishReapedIndex(array $run): void {
        $operationId = $run['operation_id'] ?? null;
        $targetEnvironment = $run['target_environment'] ?? null;
        if (!is_string($operationId) || !is_string($targetEnvironment)) {
            throw new \RuntimeException('preview reap lost its run identity');
        }
        self::assertRun($run, $operationId);
        $index = $this->readTargetIndex($targetEnvironment);
        if ($index === null || $index['operation_id'] !== $operationId
            || EnvironmentLifecycleCanon::encode($index['run'])
                !== EnvironmentLifecycleCanon::encode($run)) {
            throw new \RuntimeException("preview operation '$operationId' is not bound to its target index");
        }
        if (self::eventData($this->events($operationId), 'reaped') === null) {
            throw new \RuntimeException("preview operation '$operationId' has no durable reap event");
        }
        if ($index['phase'] === 'reaped') {
            if ($index['previous_reaped_operation_id'] !== null) {
                throw new \RuntimeException("preview operation '$operationId' retained an invalid reap predecessor");
            }
            return;
        }
        if (!in_array($index['phase'], ['active', 'reaping'], true)) {
            throw new \RuntimeException("preview operation '$operationId' has an invalid reap phase");
        }
        if ($index['phase'] === 'active') {
            $index = $this->withIndexPhase($index, 'reaping');
            $this->writeTargetIndex($index);
        }
        $previous = $index['previous_reaped_operation_id'];
        if (is_string($previous)) {
            $this->deleteReapedRun($targetEnvironment, $operationId, $previous);
        }
        $this->writeTargetIndex($this->withIndexPhase($index, 'reaped'));
    }

    private function deleteReapedRun(
        string $targetEnvironment,
        string $currentOperationId,
        string $previousOperationId
    ): void {
        self::operationId($currentOperationId);
        self::operationId($previousOperationId);
        if ($previousOperationId === $currentOperationId) {
            throw new \RuntimeException('preview target reap predecessor aliases its current operation');
        }
        $tombstone = $this->tombstonePath($targetEnvironment, $previousOperationId);
        if ($this->freshLstat($tombstone) !== false) {
            $this->deletePrivateTree($tombstone);
        }
        $previousDir = $this->runDir($previousOperationId);
        if ($this->freshLstat($previousDir) === false) {
            return;
        }
        $previousRun = $this->readRun($previousOperationId);
        if (($previousRun['target_environment'] ?? null) !== $targetEnvironment
            || self::eventData($this->events($previousOperationId), 'reaped') === null) {
            throw new \RuntimeException(
                "preview operation '$previousOperationId' is not a fully reaped predecessor"
            );
        }
        $this->assertPrivateDirectory($previousDir, 'preview run selected for reap pruning');
        if (!@rename($previousDir, $tombstone)) {
            throw new \RuntimeException(
                "preview operation '$previousOperationId' could not enter its deletion tombstone"
            );
        }
        $this->syncDirectory(dirname($previousDir));
        $this->syncDirectory(dirname($tombstone));
        $this->deletePrivateTree($tombstone);
    }

    private function tombstonePath(string $targetEnvironment, string $operationId): string {
        self::environment($targetEnvironment, 'preview target environment');
        self::operationId($operationId);
        return $this->base . '/tombstones/'
            . hash('sha256', $targetEnvironment) . '.' . $operationId;
    }

    private function deletePrivateTree(string $path): void {
        $this->assertPrivateDirectory($path, 'preview deletion tombstone');
        $entries = scandir($path);
        if (!is_array($entries)) {
            throw new \RuntimeException("preview deletion tombstone '$path' could not be listed");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            $stat = $this->freshLstat($child);
            if (!is_array($stat) || is_link($child)) {
                throw new \RuntimeException("preview deletion tombstone contains an unsafe node at '$child'");
            }
            if ((((int) ($stat['mode'] ?? 0)) & 0170000) === 0040000) {
                $this->deletePrivateTree($child);
                continue;
            }
            if (!$this->privateRegularFile($stat, 1)
                || (int) ($stat['size'] ?? -1) < 0
                || (int) ($stat['size'] ?? -1) > self::MAX_IMMUTABLE_BYTES
                || !@unlink($child)
                || $this->freshLstat($child) !== false) {
                throw new \RuntimeException("preview deletion tombstone contains an unsafe file at '$child'");
            }
            $this->syncDirectory($path);
        }
        if (!@rmdir($path) || $this->freshLstat($path) !== false) {
            throw new \RuntimeException("preview deletion tombstone '$path' could not be removed");
        }
        $this->syncDirectory(dirname($path));
    }

    private function assertPrivateDirectory(string $path, string $label): void {
        $stat = $this->freshLstat($path);
        if (!is_array($stat) || is_link($path)
            || (((int) ($stat['mode'] ?? 0)) & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && (((int) ($stat['mode'] ?? 0)) & 0777) !== 0700)
            || (function_exists('posix_geteuid')
                && (int) ($stat['uid'] ?? -1) !== posix_geteuid())) {
            throw new \RuntimeException("$label '$path' is not private and controller-owned");
        }
    }

    /** @param array<string,mixed> $intent */
    private static function assertIntent(array $intent, string $targetEnvironment): void {
        self::exactKeys($intent, [
            'candidate_branch', 'origin_environment', 'production_commit', 'production_ref',
            'source_branch', 'source_commit', 'target_driver_id', 'target_environment',
            'ttl_seconds',
        ], 'preview intent');
        foreach (['origin_environment', 'target_environment'] as $field) {
            self::environment($intent[$field] ?? null, "preview intent $field");
        }
        foreach (['candidate_branch', 'source_branch'] as $field) {
            if (!is_string($intent[$field] ?? null) || $intent[$field] === ''
                || strlen($intent[$field]) > 1024 || str_starts_with($intent[$field], '-')
                || str_contains($intent[$field], "\0")) {
                throw new \RuntimeException("preview intent $field is invalid");
            }
        }
        if ($intent['target_environment'] !== $targetEnvironment) {
            throw new \RuntimeException('preview intent target does not match its journal lock');
        }
        foreach (['production_commit', 'source_commit'] as $field) {
            if (!is_string($intent[$field] ?? null)
                || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $intent[$field]) !== 1) {
                throw new \RuntimeException("preview intent $field is not a Git object id");
            }
        }
        if (!is_string($intent['production_ref']) || $intent['production_ref'] === ''
            || strlen($intent['production_ref']) > 1024 || str_starts_with($intent['production_ref'], '-')
            || str_contains($intent['production_ref'], "\0")) {
            throw new \RuntimeException('preview intent production_ref is invalid');
        }
        if (!is_string($intent['target_driver_id']) || $intent['target_driver_id'] === ''
            || strlen($intent['target_driver_id']) > 512 || str_contains($intent['target_driver_id'], "\0")) {
            throw new \RuntimeException('preview intent target_driver_id is invalid');
        }
        if (!is_int($intent['ttl_seconds'])
            || $intent['ttl_seconds'] < 60 || $intent['ttl_seconds'] > 2592000) {
            throw new \RuntimeException('preview intent TTL is outside the supported range');
        }
    }

    /** @param array<string,mixed> $run */
    private static function assertRun(array $run, string $operationId): void {
        self::exactKeys($run, [
            'created_at', 'format', 'intent', 'intent_sha256', 'operation_id',
            'target_environment',
        ], 'preview run');
        self::operationId($operationId);
        self::environment($run['target_environment'] ?? null, 'preview run target');
        if ($run['format'] !== self::RUN_FORMAT || $run['operation_id'] !== $operationId
            || !is_array($run['intent']) || array_is_list($run['intent'])) {
            throw new \RuntimeException('preview run is malformed');
        }
        self::assertIntent($run['intent'], $run['target_environment']);
        $expected = hash('sha256', EnvironmentLifecycleCanon::encode($run['intent']));
        if (!is_string($run['intent_sha256']) || !hash_equals($expected, $run['intent_sha256'])
            || !self::utc($run['created_at'] ?? null)) {
            throw new \RuntimeException('preview run does not verify');
        }
    }

    /** @param array<string,mixed> $index */
    private static function assertTargetIndex(array $index, string $targetEnvironment): void {
        self::exactKeys($index, [
            'format', 'operation_id', 'phase', 'previous_reaped_operation_id',
            'run', 'target_environment',
        ], 'preview target index');
        self::environment($targetEnvironment, 'preview target environment');
        $operationId = $index['operation_id'] ?? null;
        $previous = $index['previous_reaped_operation_id'] ?? null;
        if ($index['format'] !== self::TARGET_INDEX_FORMAT
            || !is_string($operationId)
            || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $operationId) !== 1
            || !in_array($index['phase'], ['active', 'installing', 'reaped', 'reaping'], true)
            || $index['target_environment'] !== $targetEnvironment
            || !is_array($index['run'])
            || array_is_list($index['run'])
            || ($previous !== null
                && (!is_string($previous)
                    || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $previous) !== 1
                    || $previous === $operationId))
            || ($index['phase'] === 'reaped' && $previous !== null)) {
            throw new \RuntimeException("preview target '$targetEnvironment' index is malformed");
        }
        self::assertRun($index['run'], $operationId);
        if ($index['run']['target_environment'] !== $targetEnvironment) {
            throw new \RuntimeException("preview target '$targetEnvironment' index has a foreign run");
        }
    }

    /** @param array<string,mixed> $event */
    private static function assertEvent(
        array $event,
        string $operationId,
        int $sequence,
        string $previous
    ): void {
        self::exactKeys($event, [
            'data', 'event', 'format', 'operation_id', 'previous_event_sha256',
            'sequence', 'timestamp',
        ], 'preview event');
        if ($event['format'] !== self::EVENT_FORMAT
            || $event['operation_id'] !== $operationId
            || $event['sequence'] !== $sequence
            || $event['previous_event_sha256'] !== $previous
            || !is_string($event['event'])
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $event['event']) !== 1
            || !is_array($event['data'])
            || (array_is_list($event['data']) && $event['data'] !== [])
            || !self::utc($event['timestamp'] ?? null)) {
            throw new \RuntimeException("preview operation '$operationId' has a malformed event chain");
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has unknown or missing fields");
        }
    }

    private function writeMutable(string $path, string $bytes): void {
        if ($bytes === '' || strlen($bytes) > self::MAX_TARGET_INDEX_BYTES) {
            throw new \RuntimeException("preview target index has invalid bytes at '$path'");
        }
        $this->reconcileMutableTemporary($path);
        $destination = $this->freshLstat($path);
        if (is_array($destination)
            && (!$this->privateRegularFile($destination, 1)
                || is_link($path)
                || (int) ($destination['size'] ?? -1) < 1
                || (int) ($destination['size'] ?? -1) > self::MAX_TARGET_INDEX_BYTES)) {
            throw new \RuntimeException("preview target index is unsafe at '$path'");
        }
        $temporary = $path . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle) || !@chmod($temporary, 0600)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException("could not create preview target index at '$path'");
        }
        $published = false;
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException("could not write preview target index at '$path'");
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !@fsync($handle))) {
                throw new \RuntimeException("could not durably flush preview target index at '$path'");
            }
            $opened = fstat($handle);
            $named = $this->freshLstat($temporary);
            if (!is_array($opened) || !is_array($named)
                || !$this->samePrivateRegularFile($opened, $named, 1)
                || (int) ($opened['size'] ?? -1) !== strlen($bytes)
                || (int) ($named['size'] ?? -1) !== strlen($bytes)) {
                throw new \RuntimeException("temporary preview target index is unsafe at '$path'");
            }
            fclose($handle);
            $handle = null;
            if (!@rename($temporary, $path)) {
                throw new \RuntimeException("could not publish preview target index at '$path'");
            }
            $published = true;
            $final = $this->freshLstat($path);
            if (!is_array($final) || !$this->samePrivateRegularFile($named, $final, 1)
                || (int) ($final['size'] ?? -1) !== strlen($bytes)) {
                throw new \RuntimeException("published preview target index is unsafe at '$path'");
            }
            $this->syncDirectory(dirname($path));
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published) {
                $this->reconcileMutableTemporary($path);
            }
        }
    }

    /**
     * Mutable target indexes publish by same-directory rename, so any valid
     * pre-publication residue has exactly one link. The target lock excludes
     * cooperating writers while pathname identity is revalidated for cleanup.
     */
    private function reconcileMutableTemporary(string $path): void {
        $temporary = $path . '.tmp';
        $before = $this->freshLstat($temporary);
        if ($before === false) {
            return;
        }
        if (!$this->privateRegularFile($before, 1) || is_link($temporary)
            || (int) ($before['size'] ?? -1) < 0
            || (int) ($before['size'] ?? -1) > self::MAX_TARGET_INDEX_BYTES) {
            throw new \RuntimeException("temporary preview target index is unsafe at '$path'");
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("temporary preview target index could not be opened at '$path'");
        }
        try {
            $opened = fstat($handle);
            $after = $this->freshLstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !$this->samePrivateRegularFile($before, $opened, 1)
                || !$this->samePrivateRegularFile($opened, $after, 1)
                || (int) ($before['size'] ?? -1) !== (int) ($opened['size'] ?? -2)
                || (int) ($after['size'] ?? -1) !== (int) ($opened['size'] ?? -2)) {
                throw new \RuntimeException("temporary preview target index changed during cleanup at '$path'");
            }
        } finally {
            fclose($handle);
        }
        if (!@unlink($temporary) || $this->freshLstat($temporary) !== false) {
            throw new \RuntimeException("temporary preview target index could not be removed at '$path'");
        }
        $this->syncDirectory(dirname($path));
    }

    private function writeImmutable(string $path, array $value): void {
        $bytes = EnvironmentLifecycleCanon::encode($value) . "\n";
        if (strlen($bytes) > self::MAX_IMMUTABLE_BYTES) {
            throw new \RuntimeException("preview journal exceeds its byte limit at '$path'");
        }
        $this->reconcileTemporary($path);
        if (is_file($path)) {
            if ($this->readPrivateFile($path, 'immutable preview journal') === $bytes) {
                return;
            }
            throw new \RuntimeException("immutable preview journal differs at '$path'");
        }
        $temporary = $path . '.tmp';
        $handle = @fopen($temporary, 'x+b');
        if (!is_resource($handle) || !chmod($temporary, 0600)) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException("could not create preview journal '$path'");
        }
        try {
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $written = fwrite($handle, substr($bytes, $offset));
                if (!is_int($written) || $written < 1) {
                    throw new \RuntimeException("could not write preview journal '$path'");
                }
                $offset += $written;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new \RuntimeException("could not durably flush preview journal '$path'");
            }
            $opened = fstat($handle);
            $named = $this->freshLstat($temporary);
            if (!is_array($opened) || !is_array($named)
                || !$this->samePrivateRegularFile($opened, $named, 1)
                || (int) ($opened['size'] ?? -1) !== strlen($bytes)
                || (int) ($named['size'] ?? -1) !== strlen($bytes)) {
                throw new \RuntimeException("temporary preview journal is unsafe at '$path'");
            }
        } catch (\Throwable $failure) {
            fclose($handle);
            $this->reconcileTemporary($path);
            throw $failure;
        }
        fclose($handle);
        if (@link($temporary, $path)) {
            $this->syncDirectory(dirname($path));
            $this->reconcileTemporary($path);
            return;
        }
        try {
            $same = is_file($path)
                && $this->readPrivateFile($path, 'immutable preview journal') === $bytes;
        } finally {
            $this->reconcileTemporary($path);
        }
        if (!$same) {
            throw new \RuntimeException("could not publish preview journal '$path'");
        }
    }

    /**
     * Every immutable destination owns one temporary pathname. A link count of
     * two is accepted only when the second name is the verified destination:
     * that is the exact crash point after create-only publication and before
     * removal of the temporary name. Any unrelated hard link remains unsafe.
     */
    private function reconcileTemporary(string $path): void {
        $temporary = $path . '.tmp';
        $before = $this->freshLstat($temporary);
        if ($before === false) {
            return;
        }
        $links = (int) ($before['nlink'] ?? 0);
        if (!$this->privateRegularFile($before, $links)
            || ($links !== 1 && $links !== 2)
            || (int) ($before['size'] ?? -1) < 0
            || (int) ($before['size'] ?? -1) > self::MAX_IMMUTABLE_BYTES) {
            throw new \RuntimeException("temporary preview journal is unsafe at '$path'");
        }
        if ($links === 2) {
            $published = $this->freshLstat($path);
            if (!is_array($published)
                || !$this->samePrivateRegularFile($before, $published, 2)
                || (int) ($published['size'] ?? -1) !== (int) ($before['size'] ?? -2)) {
                throw new \RuntimeException("temporary preview journal is unsafe at '$path'");
            }
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("temporary preview journal could not be opened at '$path'");
        }
        try {
            $opened = fstat($handle);
            $after = $this->freshLstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !$this->samePrivateRegularFile($before, $opened, $links)
                || !$this->samePrivateRegularFile($opened, $after, $links)
                || (int) ($before['size'] ?? -1) !== (int) ($opened['size'] ?? -2)
                || (int) ($after['size'] ?? -1) !== (int) ($opened['size'] ?? -2)) {
                throw new \RuntimeException("temporary preview journal changed during cleanup at '$path'");
            }
            if ($links === 2) {
                $publishedAfter = $this->freshLstat($path);
                if (!is_array($publishedAfter)
                    || !$this->samePrivateRegularFile($opened, $publishedAfter, 2)
                    || (int) ($publishedAfter['size'] ?? -1) !== (int) ($opened['size'] ?? -2)) {
                    throw new \RuntimeException("temporary preview journal changed during cleanup at '$path'");
                }
            }
        } finally {
            fclose($handle);
        }
        if (!@unlink($temporary) || $this->freshLstat($temporary) !== false) {
            throw new \RuntimeException("temporary preview journal could not be removed at '$path'");
        }
        $this->syncDirectory(dirname($path));
    }

    /** @param array<string|int,mixed> $stat */
    private function privateRegularFile(array $stat, int $links): bool {
        return (((int) ($stat['mode'] ?? 0)) & 0170000) === 0100000
            && (DIRECTORY_SEPARATOR !== '/' || (((int) ($stat['mode'] ?? 0)) & 0777) === 0600)
            && (int) ($stat['nlink'] ?? 0) === $links
            && (!function_exists('posix_geteuid') || (int) ($stat['uid'] ?? -1) === posix_geteuid());
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private function samePrivateRegularFile(array $left, array $right, int $links): bool {
        return $this->privateRegularFile($left, $links)
            && $this->privateRegularFile($right, $links)
            && (string) ($left['dev'] ?? '') === (string) ($right['dev'] ?? '')
            && (string) ($left['ino'] ?? '') === (string) ($right['ino'] ?? '');
    }

    /** @return array<string|int,mixed>|false */
    private function freshLstat(string $path): array|false {
        clearstatcache(true, $path);
        return @lstat($path);
    }

    /** @return array<string,mixed> */
    private function readCanonical(string $path, string $label): array {
        $bytes = $this->readPrivateFile($path, $label);
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new \RuntimeException("$label is malformed JSON");
        }
        if (!is_array($value) || array_is_list($value)
            || EnvironmentLifecycleCanon::encode($value) . "\n" !== $bytes) {
            throw new \RuntimeException("$label is not canonical JSON");
        }
        return $value;
    }

    private function ensurePrivateDirectory(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            $parent = dirname($path);
            if ($path !== $this->base && str_starts_with($path, $this->base . '/')) {
                $this->ensurePrivateDirectory($parent);
            }
            if (!mkdir($path, 0700) || !chmod($path, 0700)) {
                throw new \RuntimeException("could not create private preview directory '$path'");
            }
            $this->syncDirectory($parent);
        }
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0700)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new \RuntimeException("preview journal directory '$path' is not private and controller-owned");
        }
    }

    /** @return resource */
    private function openPrivateLock(string $path, string $label) {
        $this->ensurePrivateDirectory(dirname($path));
        $before = @lstat($path);
        if ($before === false) {
            $handle = @fopen($path, 'x+b');
            if (!is_resource($handle) || !chmod($path, 0600)) {
                if (is_resource($handle)) fclose($handle);
                throw new \RuntimeException("could not create lock for $label");
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                fclose($handle);
                throw new \RuntimeException("could not durably create lock for $label");
            }
            $this->syncDirectory(dirname($path));
            $before = @lstat($path);
        } else {
            if (is_link($path) || ($before['mode'] & 0170000) !== 0100000
                || ($before['mode'] & 0777) !== 0600
                || (int) ($before['nlink'] ?? 0) !== 1
                || (function_exists('posix_geteuid') && (int) $before['uid'] !== posix_geteuid())) {
                throw new \RuntimeException("lock for $label is not a stable private regular file");
            }
            $handle = @fopen($path, 'r+b');
        }
        $opened = is_resource($handle) ? fstat($handle) : false;
        $after = @lstat($path);
        if (!is_resource($handle) || !is_array($before) || !is_array($opened) || !is_array($after)
            || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600
            || (int) ($before['nlink'] ?? 0) !== 1
            || (int) $before['dev'] !== (int) $opened['dev']
            || (int) $before['ino'] !== (int) $opened['ino']
            || (int) $after['dev'] !== (int) $opened['dev']
            || (int) $after['ino'] !== (int) $opened['ino']
            || (function_exists('posix_geteuid') && (int) $opened['uid'] !== posix_geteuid())) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException("lock for $label is not a stable private regular file");
        }
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException("could not lock $label");
        }
        return $handle;
    }

    private function readPrivateFile(string $path, string $label): string {
        $this->ensurePrivateDirectory(dirname($path));
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600
            || (int) ($before['nlink'] ?? 0) !== 1
            || (function_exists('posix_geteuid') && (int) $before['uid'] !== posix_geteuid())) {
            throw new \RuntimeException("$label is not a stable private regular file");
        }
        $handle = @fopen($path, 'rb');
        $opened = is_resource($handle) ? fstat($handle) : false;
        if (!is_resource($handle) || !is_array($opened)
            || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0777) !== 0600
            || (int) ($before['nlink'] ?? 0) !== 1
            || (int) $before['dev'] !== (int) $opened['dev']
            || (int) $before['ino'] !== (int) $opened['ino']
            || (int) $before['size'] !== (int) $opened['size']
            || (function_exists('posix_geteuid') && (int) $opened['uid'] !== posix_geteuid())) {
            if (is_resource($handle)) fclose($handle);
            throw new \RuntimeException("$label is not a stable private regular file");
        }
        if ((int) $opened['size'] < 1 || (int) $opened['size'] > self::MAX_IMMUTABLE_BYTES) {
            fclose($handle);
            throw new \RuntimeException("$label has an invalid byte size");
        }
        $bytes = stream_get_contents($handle, self::MAX_IMMUTABLE_BYTES + 1);
        $closed = fclose($handle);
        $after = @lstat($path);
        if (!is_string($bytes) || !$closed || strlen($bytes) > self::MAX_IMMUTABLE_BYTES
            || !is_array($after) || is_link($path)
            || (int) $after['dev'] !== (int) $opened['dev']
            || (int) $after['ino'] !== (int) $opened['ino']
            || (int) $after['size'] !== (int) $opened['size']
            || (int) $after['mode'] !== (int) $opened['mode']) {
            throw new \RuntimeException("$label changed while it was read");
        }
        return $bytes;
    }

    private function syncDirectory(string $path): void {
        if (!function_exists('fsync') || !is_dir($path) || is_link($path)) {
            return;
        }
        $handle = @fopen($path, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException("preview journal directory '$path' could not be opened for fsync");
        }
        try {
            if (!@fsync($handle)) {
                throw new \RuntimeException("preview journal directory '$path' could not be synchronized");
            }
        } finally {
            fclose($handle);
        }
    }

    private static function environment(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new \RuntimeException("$label is invalid");
        }
        return $value;
    }

    private static function utc(mixed $value): bool {
        if (!is_string($value)) {
            return false;
        }
        $time = \DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new \DateTimeZone('UTC')
        );
        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private static int $lastOperationMicros = 0;

    private static function operationId(?string $value = null): string {
        if ($value !== null) {
            if (preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $value) !== 1) {
                throw new \RuntimeException('invalid preview operation id');
            }
            return $value;
        }
        $micros = (int) floor(microtime(true) * 1000000);
        if ($micros <= self::$lastOperationMicros) {
            $micros = self::$lastOperationMicros + 1;
        }
        self::$lastOperationMicros = $micros;
        return gmdate('Ymd-His', intdiv($micros, 1000000))
            . '-' . sprintf('%016x', $micros) . bin2hex(random_bytes(4));
    }
}
