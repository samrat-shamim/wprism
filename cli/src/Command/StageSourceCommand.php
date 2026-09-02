<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Authority/TargetOperationStore.php';
require_once __DIR__ . '/../Release/SourceStageReceipt.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CodeResolveCommand.php';

use WPrism\CommandRefusalException;

/** `wprism stage-source` — persistent Git staging outside the canonical checkout. */
final class StageSourceCommand {
    private const ENVIRONMENT_VALUES_FILE = '.wprism-env-values.json';

    /** @param list<string> $extra */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        ?callable $clock = null
    ): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $flags = self::flags($extra);
            $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
            $receipt = self::stage(
                $driver,
                $siteRepo,
                $flags['from'],
                $flags['operation'],
                $clock
            );
            echo SourceStageReceipt::encode($receipt);

            return 0;
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'stage-source');
        } catch (\Throwable $error) {
            $refusal = new CommandRefusalException(
                'release_stage_failed',
                'the source revision could not be staged as an inert release input',
                'inspect target Git health and retry with the same operation id; use a new operation id only after '
                    . 'reconciling any existing stage',
                [],
                $error->getMessage(),
                $error
            );
            return AssessCommand::renderRefusal($refusal, $json, 'stage-source');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public static function stage(
        EnvironmentDriver $driver,
        string $siteRepo,
        string $advertisedRef,
        string $operationId,
        ?callable $clock = null
    ): array {
        SourceStageReceipt::assertOperationId($operationId);
        $sourceRef = self::localDeliveryRef($siteRepo, $advertisedRef);
        $sourceCommit = self::localObject($siteRepo, $advertisedRef . '^{commit}', 'release_stage_ref_unresolvable');
        $sourceTree = self::localObject($siteRepo, $sourceCommit . '^{tree}', 'release_stage_ref_unresolvable');
        $repo = $driver->repoPath();
        $stageRef = SourceStageReceipt::stageRef($operationId);
        // The same target identity later consumed by OperationAuthorization is
        // established during this staging write. Prepare only calls
        // readIdentity(), so a supposedly read-only authorization subject can
        // never mint or rotate the target it names.
        $targetId = TargetOperationStore::ensureIdentity($driver);

        $critical = 'repo=' . escapeshellarg($repo)
            . '; expected=' . escapeshellarg($sourceCommit)
            . '; source_ref=' . escapeshellarg($sourceRef)
            . '; operation=' . escapeshellarg($operationId)
            . '; target_id=' . escapeshellarg($targetId)
            . '; stage_ref=' . escapeshellarg($stageRef) . '; '
            . 'test -z "$(git -C "$repo" status --porcelain --untracked-files=all)" || exit 67; '
            . 'branch=$(git -C "$repo" symbolic-ref --quiet --short HEAD) || exit 68; '
            . 'test -n "$branch" || exit 68; '
            . 'base=$(git -C "$repo" rev-parse --verify HEAD) || exit 72; '
            . 'base_tree=$(git -C "$repo" rev-parse --verify "${base}^{tree}") || exit 72; '
            . 'git_dir=$(git -C "$repo" rev-parse --absolute-git-dir) || exit 73; '
            . 'root="$git_dir/wprism-release"; mkdir -p "$root/stages/$operation" || exit 73; '
            . 'stage_dir="$root/stages/$operation"; receipt="$stage_dir/receipt.json"; '
            . 'stage_repo="$stage_dir/repository"; '
            . 'if [ -f "$receipt" ]; then ' . self::syncExistingReceiptCommand()
            . '; printf "__EXISTING__\\n"; cat "$receipt"; exit 0; fi; '
            . 'if git -C "$repo" show-ref --verify --quiet "$stage_ref"; then '
            . 'actual=$(git -C "$repo" rev-parse --verify "${stage_ref}^{commit}") || exit 74; '
            . 'test "$actual" = "$expected" || exit 74; '
            . 'else git -c core.hooksPath=/dev/null -C "$repo" fetch --no-tags origin "$source_ref" '
            . '>/dev/null 2>&1 || exit 69; '
            . 'actual=$(git -C "$repo" rev-parse --verify FETCH_HEAD^{commit}) || exit 69; '
            . 'test "$actual" = "$expected" || exit 70; '
            . 'git -C "$repo" merge-base --is-ancestor "$base" "$expected" || exit 71; '
            . 'git -C "$repo" update-ref "$stage_ref" "$expected" || exit 74; fi; '
            . 'git -C "$repo" merge-base --is-ancestor "$base" "$expected" || exit 71; '
            . 'source_tree=$(git -C "$repo" rev-parse --verify "${expected}^{tree}") || exit 74; '
            . 'if [ -e "$stage_repo" ]; then '
            . 'stage_head=$(git -C "$stage_repo" rev-parse --verify HEAD) || exit 75; '
            . 'test "$stage_head" = "$expected" || exit 75; '
            . 'test -z "$(git -C "$stage_repo" status --porcelain --untracked-files=all)" || exit 75; '
            . 'else git -c core.hooksPath=/dev/null -C "$repo" worktree add --detach "$stage_repo" "$expected" '
            . '>/dev/null 2>&1 || exit 75; fi; '
            . 'stage_head=$(git -C "$stage_repo" rev-parse --verify HEAD) || exit 75; '
            . 'stage_tree=$(git -C "$stage_repo" rev-parse --verify HEAD^{tree}) || exit 75; '
            . 'test "$stage_head" = "$expected" && test "$stage_tree" = "$source_tree" || exit 75; '
            . 'stage_path_hex=$(printf %s "$stage_repo" | od -An -v -tx1 | tr -d " \\n"); '
            . 'printf "__NEW__\\ntarget_id=%s\\nbase_commit=%s\\nbase_tree=%s\\nsource_commit=%s\\n'
            . 'source_tree=%s\\nstage_path_hex=%s\\n" "$target_id" "$base" "$base_tree" "$stage_head" '
            . '"$stage_tree" "$stage_path_hex"';
        $script = self::lockedStageScript($repo, $critical);
        $result = $driver->captureRaw($script);
        if ((int) ($result['exit'] ?? 1) !== 0) {
            throw self::stageFailure((int) ($result['exit'] ?? 1), $result);
        }
        $stdout = (string) ($result['stdout'] ?? '');
        if (str_starts_with($stdout, "__EXISTING__\n")) {
            $existing = SourceStageReceipt::fromBytes(substr($stdout, strlen("__EXISTING__\n")));
            self::assertSameRequest(
                $existing,
                $driver,
                $advertisedRef,
                $sourceRef,
                $sourceCommit,
                $sourceTree,
                $operationId
            );
            self::syncEnvironmentValues($driver, $existing);
            self::verify($driver, $existing);

            return $existing;
        }
        $facts = self::facts($stdout);
        if (!hash_equals($sourceCommit, $facts['source_commit'])
            || !hash_equals($sourceTree, $facts['source_tree'])) {
            throw new CommandRefusalException(
                'release_stage_source_mismatch',
                'the target staged Git objects do not match the exact source commit and tree selected locally',
                'verify the target origin publishes the reviewed ref without rewriting it, then retry the operation'
            );
        }
        $receipt = SourceStageReceipt::build([
            'advertised_ref' => $advertisedRef,
            'advertised_source_ref' => $sourceRef,
            'base_commit' => $facts['base_commit'],
            'base_tree' => $facts['base_tree'],
            'created_at' => ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))(),
            'environment' => $driver->name(),
            'operation_id' => $operationId,
            'source_commit' => $sourceCommit,
            'source_tree' => $sourceTree,
            'stage_repository_path' => $facts['stage_repository_path'],
            'target_id' => $targetId,
            'target_repo_path' => $repo,
        ]);
        // Split repositories keep locked third-party bytes out of Git. Finish
        // that code half inside the source-stage mutation so the later
        // authorization preparation can remain completely read-only.
        CodeResolveCommand::materializeSourceStage($driver, $facts['stage_repository_path']);
        self::syncEnvironmentValues($driver, $receipt);

        $stored = self::persist($driver, $receipt);
        if ($stored !== null) {
            self::assertSameRequest(
                $stored,
                $driver,
                $advertisedRef,
                $sourceRef,
                $sourceCommit,
                $sourceTree,
                $operationId
            );
            $receipt = $stored;
        }
        self::verify($driver, $receipt);

        return $receipt;
    }

    /**
     * Read-only validation immediately before and after release preparation.
     *
     * @param array<string,mixed> $receipt
     * @return string exact target-visible staged repository path
     */
    public static function verify(EnvironmentDriver $driver, array $receipt): string {
        SourceStageReceipt::validate($receipt);
        if (($receipt['environment'] ?? null) !== $driver->name()
            || ($receipt['target']['repo_path'] ?? null) !== $driver->repoPath()) {
            throw new CommandRefusalException(
                'release_stage_target_mismatch',
                'the source stage receipt names another environment or target repository',
                'use the receipt emitted for this exact environment and target repository'
            );
        }
        $targetId = TargetOperationStore::readIdentity($driver);
        if (!hash_equals((string) $receipt['target']['id'], $targetId)) {
            throw new CommandRefusalException(
                'release_stage_target_mismatch',
                'the target stable identity no longer matches the source stage receipt',
                'do not authorize this preparation; stage the source again against the intended target identity'
            );
        }
        $operation = (string) $receipt['operation_id'];
        $repo = $driver->repoPath();
        $stagePath = (string) $receipt['stage']['repository_path'];
        $receiptBytesHash = hash('sha256', SourceStageReceipt::encode($receipt));
        $script = 'repo=' . escapeshellarg($repo)
            . '; operation=' . escapeshellarg($operation)
            . '; expected_stage=' . escapeshellarg($stagePath)
            . '; expected_target=' . escapeshellarg((string) $receipt['target']['id'])
            . '; expected_base=' . escapeshellarg((string) $receipt['base']['commit'])
            . '; expected_base_tree=' . escapeshellarg((string) $receipt['base']['tree'])
            . '; expected_source=' . escapeshellarg((string) $receipt['source']['commit'])
            . '; expected_tree=' . escapeshellarg((string) $receipt['source']['tree'])
            . '; expected_receipt_bytes=' . escapeshellarg($receiptBytesHash)
            . '; stage_ref=' . escapeshellarg((string) $receipt['stage']['ref']) . '; '
            . 'test -z "$(git -C "$repo" status --porcelain --untracked-files=all)" || exit 86; '
            . 'git -C "$repo" symbolic-ref --quiet --short HEAD >/dev/null 2>&1 || exit 86; '
            . 'base=$(git -C "$repo" rev-parse --verify HEAD) || exit 86; '
            . 'base_tree=$(git -C "$repo" rev-parse --verify HEAD^{tree}) || exit 86; '
            . 'test "$base" = "$expected_base" && test "$base_tree" = "$expected_base_tree" || exit 86; '
            . 'git_dir=$(git -C "$repo" rev-parse --absolute-git-dir) || exit 83; '
            . 'root="$git_dir/wprism-release"; '
            . 'derived_stage="$root/stages/$operation/repository"; test "$derived_stage" = "$expected_stage" || exit 82; '
            . 'receipt_path="$root/stages/$operation/receipt.json"; test -f "$receipt_path" || exit 85; '
            . 'stored_hash=$(php -r \'echo hash_file("sha256", $argv[1]);\' "$receipt_path") || exit 85; '
            . 'test "$stored_hash" = "$expected_receipt_bytes" || exit 85; '
            . 'ref_commit=$(git -C "$repo" rev-parse --verify "${stage_ref}^{commit}") || exit 87; '
            . 'stage_commit=$(git -C "$expected_stage" rev-parse --verify HEAD) || exit 87; '
            . 'stage_tree=$(git -C "$expected_stage" rev-parse --verify HEAD^{tree}) || exit 87; '
            . 'test "$ref_commit" = "$expected_source" && test "$stage_commit" = "$expected_source" '
            . '&& test "$stage_tree" = "$expected_tree" || exit 87; '
            . 'test -z "$(git -C "$expected_stage" status --porcelain --untracked-files=all)" || exit 87; '
            . 'source_values="$repo/' . self::ENVIRONMENT_VALUES_FILE . '"; '
            . 'stage_values="$expected_stage/' . self::ENVIRONMENT_VALUES_FILE . '"; '
            . 'test ! -L "$source_values" && test ! -L "$stage_values" || exit 89; '
            . 'if [ -e "$source_values" ]; then '
            . 'test -f "$source_values" && test -f "$stage_values" '
            . '&& cmp -s "$source_values" "$stage_values" || exit 89; '
            . 'else test ! -e "$stage_values" || exit 89; fi; '
            . 'printf __OK__';
        $result = $driver->captureRaw($script);
        if ((int) ($result['exit'] ?? 1) !== 0 || trim((string) ($result['stdout'] ?? '')) !== '__OK__') {
            $exit = (int) ($result['exit'] ?? 1);
            [$code, $message] = match ($exit) {
                84 => ['release_stage_target_mismatch', 'the target stable identity no longer matches the source stage receipt'],
                85 => ['release_stage_receipt_changed', 'the target-side source stage receipt is missing or no longer byte-identical'],
                86 => ['release_stage_base_changed', 'the canonical target checkout changed after source staging'],
                87 => ['release_stage_changed', 'the inert staged Git ref, commit, tree or worktree changed after staging'],
                89 => ['release_stage_environment_values_changed', 'the target environment bindings changed after source staging'],
                default => ['release_stage_changed', 'the source stage could not be revalidated without ambiguity'],
            };
            $remediation = $exit === 89
                ? 'rerun stage-source with the same operation id and exact inputs to refresh the inert binding mirror, then prepare again'
                : 'do not authorize this preparation; reconcile the target and create a new source stage operation';
            throw new CommandRefusalException(
                $code,
                $message,
                $remediation,
                [['code' => $code, 'phase' => 'stage_revalidation']],
                self::privateDetail($result)
            );
        }

        try {
            CodeResolveCommand::assertSourceStage($driver, $stagePath);
        } catch (CommandRefusalException $error) {
            throw new CommandRefusalException(
                'release_stage_code_changed',
                'the inert source stage no longer matches its committed code lock',
                'discard this source stage and retry the exact stage operation before preparing another release',
                [['code' => 'release_stage_code_changed', 'phase' => 'code_binding']],
                $error->getMessage(),
                $error
            );
        }

        return $stagePath;
    }

    /**
     * Refresh the inert stage's target-local intended-value authority without
     * putting secret bytes in Git, a receipt, argv, stdout or diagnostics.
     * Preparation then compares this mirror read-only on both sides of every
     * staged planning read, so an env-set after staging cannot authorize a
     * plan against values that will not be present at execution.
     *
     * @param array<string,mixed> $receipt
     */
    private static function syncEnvironmentValues(EnvironmentDriver $driver, array $receipt): void {
        SourceStageReceipt::validate($receipt);
        $repo = $driver->repoPath();
        $operation = (string) $receipt['operation_id'];
        $stagePath = (string) $receipt['stage']['repository_path'];
        $file = self::ENVIRONMENT_VALUES_FILE;
        $writer = <<<'PHP'
$repo = $argv[1] ?? '';
$stage = $argv[2] ?? '';
$file = $argv[3] ?? '';
if ($repo === '' || $stage === '' || $file !== '.wprism-env-values.json'
    || !is_dir($repo) || !is_dir($stage)) {
    fwrite(STDERR, "environment-values-input\n"); exit(89);
}
$source = $repo . '/' . $file;
$destination = $stage . '/' . $file;
$sourceExists = file_exists($source) || is_link($source);
$destinationExists = file_exists($destination) || is_link($destination);
if (is_link($source) || ($sourceExists && !is_file($source))
    || is_link($destination) || ($destinationExists && !is_file($destination))) {
    fwrite(STDERR, "environment-values-type\n"); exit(89);
}
if (!$sourceExists) {
    if ($destinationExists && !@unlink($destination)) {
        fwrite(STDERR, "environment-values-remove\n"); exit(89);
    }
    $directory = @fopen($stage, 'rb');
    if ($destinationExists
        && (!is_resource($directory) || !function_exists('fsync') || !@fsync($directory) || !@fclose($directory))) {
        if (is_resource($directory)) @fclose($directory);
        fwrite(STDERR, "environment-values-sync\n"); exit(89);
    }
    echo "__SYNCED__";
    exit(0);
}
$stat = @lstat($source);
$bytes = @file_get_contents($source);
if (!is_array($stat) || (($stat['mode'] ?? 0) & 0170000) !== 0100000
    || (($stat['mode'] ?? 0) & 0777) !== 0600 || !is_string($bytes)) {
    fwrite(STDERR, "environment-values-source\n"); exit(89);
}
$temporary = @tempnam($stage, '.wprism-env-values-stage-');
$handle = is_string($temporary) ? @fopen($temporary, 'r+b') : false;
if (!is_resource($handle)
    || @fwrite($handle, $bytes) !== strlen($bytes)
    || !@fflush($handle)
    || !function_exists('fsync')
    || !@fsync($handle)
    || !@fclose($handle)
    || !@chmod($temporary, 0600)
    || !@rename($temporary, $destination)) {
    if (is_resource($handle)) @fclose($handle);
    if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    fwrite(STDERR, "environment-values-publish\n"); exit(89);
}
$directory = @fopen($stage, 'rb');
$readback = @file_get_contents($destination);
if (!is_resource($directory) || !@fsync($directory) || !@fclose($directory)
    || !is_string($readback) || !hash_equals($bytes, $readback)) {
    if (is_resource($directory)) @fclose($directory);
    fwrite(STDERR, "environment-values-readback\n"); exit(89);
}
echo "__SYNCED__";
PHP;
        $critical = 'repo=' . escapeshellarg($repo)
            . '; operation=' . escapeshellarg($operation)
            . '; expected_stage=' . escapeshellarg($stagePath) . '; '
            . 'git_dir=$(git -C "$repo" rev-parse --absolute-git-dir) || exit 89; '
            . 'derived_stage="$git_dir/wprism-release/stages/$operation/repository"; '
            . 'test "$derived_stage" = "$expected_stage" || exit 89; '
            . 'php -r ' . escapeshellarg($writer) . ' -- "$repo" "$expected_stage" '
            . escapeshellarg($file);
        $result = $driver->captureRaw(self::lockedStageScript($repo, $critical));
        if ((int) ($result['exit'] ?? 1) !== 0 || trim((string) ($result['stdout'] ?? '')) !== '__SYNCED__') {
            throw new CommandRefusalException(
                'release_stage_environment_values_sync_failed',
                'the target-local environment bindings could not be mirrored into the inert source stage',
                'repair the target .wprism-env-values.json type and mode, then retry the same stage operation',
                [['code' => 'release_stage_environment_values_sync_failed', 'phase' => 'environment_bindings']],
                self::privateDetail($result)
            );
        }
    }

    /** @param array<string,mixed> $receipt @return ?array<string,mixed> */
    private static function persist(EnvironmentDriver $driver, array $receipt): ?array {
        $repo = $driver->repoPath();
        $operation = (string) $receipt['operation_id'];
        $stagePath = (string) $receipt['stage']['repository_path'];
        $bytes = SourceStageReceipt::encode($receipt);
        $critical = 'repo=' . escapeshellarg($repo)
            . '; operation=' . escapeshellarg($operation)
            . '; expected_stage=' . escapeshellarg($stagePath)
            . '; expected_base=' . escapeshellarg((string) $receipt['base']['commit'])
            . '; expected_source=' . escapeshellarg((string) $receipt['source']['commit'])
            . '; expected_tree=' . escapeshellarg((string) $receipt['source']['tree'])
            . '; stage_ref=' . escapeshellarg((string) $receipt['stage']['ref']) . '; '
            . 'git_dir=$(git -C "$repo" rev-parse --absolute-git-dir) || exit 73; '
            . 'root="$git_dir/wprism-release"; '
            . 'stage_dir="$root/stages/$operation"; receipt="$stage_dir/receipt.json"; '
            . 'if [ -f "$receipt" ]; then ' . self::syncExistingReceiptCommand()
            . '; printf "__EXISTING__\\n"; cat "$receipt"; exit 0; fi; '
            . 'derived_stage="$stage_dir/repository"; test "$derived_stage" = "$expected_stage" || exit 82; '
            . 'test -z "$(git -C "$repo" status --porcelain --untracked-files=all)" || exit 86; '
            . 'base=$(git -C "$repo" rev-parse --verify HEAD) || exit 86; test "$base" = "$expected_base" || exit 86; '
            . 'ref_commit=$(git -C "$repo" rev-parse --verify "${stage_ref}^{commit}") || exit 87; '
            . 'stage_commit=$(git -C "$expected_stage" rev-parse --verify HEAD) || exit 87; '
            . 'stage_tree=$(git -C "$expected_stage" rev-parse --verify HEAD^{tree}) || exit 87; '
            . 'test "$ref_commit" = "$expected_source" && test "$stage_commit" = "$expected_source" '
            . '&& test "$stage_tree" = "$expected_tree" || exit 87; '
            . 'test -z "$(git -C "$expected_stage" status --porcelain --untracked-files=all)" || exit 87; '
            . self::durableReceiptCommand($bytes);
        $script = self::lockedStageScript($repo, $critical);
        $result = $driver->captureRaw($script);
        if ((int) ($result['exit'] ?? 1) !== 0) {
            throw self::stageFailure((int) ($result['exit'] ?? 1), $result);
        }
        $stdout = (string) ($result['stdout'] ?? '');
        if (str_starts_with($stdout, "__EXISTING__\n")) {
            return SourceStageReceipt::fromBytes(substr($stdout, strlen("__EXISTING__\n")));
        }
        if (trim($stdout) !== '__STORED__') {
            throw new CommandRefusalException(
                'release_stage_receipt_write_failed',
                'the target did not confirm the durable source stage receipt write',
                'inspect target Git storage and retry with the same operation id'
            );
        }

        return null;
    }

    /**
     * Run one staging critical section under a kernel-released target lock.
     *
     * A directory lock survives controller death and permanently wedges every
     * later operation. PHP's flock is released by the kernel when this helper
     * exits, including abnormal process death; retained ref/worktree state is
     * then reconciled by the same-operation checks in the critical section.
     */
    private static function lockedStageScript(string $repo, string $critical): string {
        $runner = <<<'PHP'
$path = $argv[1] ?? '';
$encoded = $argv[2] ?? '';
$script = base64_decode($encoded, true);
if ($path === '' || !is_string($script)) { fwrite(STDERR, "lock-input\n"); exit(78); }
$lock = @fopen($path, 'c');
if (!is_resource($lock)) { fwrite(STDERR, "lock-open\n"); exit(78); }
if (!@flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "lock-busy\n"); exit(79); }
$process = @proc_open(
    ['/bin/sh', '-c', $script],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR, 3 => $lock],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($process)) { fwrite(STDERR, "lock-child\n"); exit(78); }
exit((int) proc_close($process));
PHP;

        return 'repo=' . escapeshellarg($repo) . '; '
            . 'git_dir=$(git -C "$repo" rev-parse --absolute-git-dir) || exit 73; '
            . 'root="$git_dir/wprism-release"; mkdir -p "$root" || exit 73; '
            . 'php -r ' . escapeshellarg($runner) . ' -- "$root/stage.lock" '
            . escapeshellarg(base64_encode($critical));
    }

    /** File fsync, atomic rename, parent fsync and exact readback for the authority receipt. */
    private static function durableReceiptCommand(string $bytes): string {
        $writer = <<<'PHP'
$directory = $argv[1] ?? '';
$path = $argv[2] ?? '';
$expected = base64_decode($argv[3] ?? '', true);
if ($directory === '' || $path === '' || dirname($path) !== $directory
    || !is_dir($directory) || !is_string($expected) || $expected === '') {
    fwrite(STDERR, "receipt-input\n"); exit(88);
}
$temporary = @tempnam($directory, '.receipt-');
$handle = is_string($temporary) ? @fopen($temporary, 'r+b') : false;
if (!is_resource($handle)
    || @fwrite($handle, $expected) !== strlen($expected)
    || !@fflush($handle)
    || !function_exists('fsync')
    || !@fsync($handle)
    || !@fclose($handle)
    || !@chmod($temporary, 0600)
    || !@rename($temporary, $path)) {
    if (is_resource($handle)) @fclose($handle);
    if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    fwrite(STDERR, "receipt-publish\n"); exit(88);
}
$syncDirectories = [$directory, dirname($directory), dirname(dirname($directory)), dirname(dirname(dirname($directory)))];
foreach ($syncDirectories as $syncDirectory) {
    $directoryHandle = @fopen($syncDirectory, 'rb');
    if (!is_resource($directoryHandle) || !@fsync($directoryHandle) || !@fclose($directoryHandle)) {
        if (is_resource($directoryHandle)) @fclose($directoryHandle);
        fwrite(STDERR, "receipt-sync\n"); exit(88);
    }
}
$readback = @file_get_contents($path);
if (!is_string($readback) || !hash_equals($expected, $readback)) {
    fwrite(STDERR, "receipt-readback\n"); exit(88);
}
echo "__STORED__";
PHP;

        return 'php -r ' . escapeshellarg($writer) . ' -- "$stage_dir" "$receipt" '
            . escapeshellarg(base64_encode($bytes)) . ' || exit 88';
    }

    /** Complete a prior uncertain publication before returning it as an exact retry. */
    private static function syncExistingReceiptCommand(): string {
        $sync = <<<'PHP'
$path = $argv[1] ?? '';
$directory = dirname($path);
$handle = @fopen($path, 'rb');
if (!is_resource($handle) || !function_exists('fsync') || !@fsync($handle) || !@fclose($handle)) {
    if (is_resource($handle)) @fclose($handle);
    fwrite(STDERR, "receipt-sync\n"); exit(88);
}
foreach ([$directory, dirname($directory), dirname(dirname($directory)), dirname(dirname(dirname($directory)))] as $syncDirectory) {
    $directoryHandle = @fopen($syncDirectory, 'rb');
    if (!is_resource($directoryHandle) || !@fsync($directoryHandle) || !@fclose($directoryHandle)) {
        if (is_resource($directoryHandle)) @fclose($directoryHandle);
        fwrite(STDERR, "receipt-sync\n"); exit(88);
    }
}
PHP;

        return 'php -r ' . escapeshellarg($sync) . ' -- "$receipt" || exit 88';
    }

    /** @return array{from:string,operation:string} */
    private static function flags(array $extra): array {
        $out = ['from' => null, 'operation' => null];
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidArguments('stage-source received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            if ($name === '--from') {
                if ($out['from'] !== null || $value === null || $value === '' || str_starts_with($value, '-')) {
                    throw self::invalidArguments('--from takes exactly one --from=<branch-or-tag> value');
                }
                $out['from'] = $value;
            } elseif ($name === '--operation') {
                if ($out['operation'] !== null || $value === null || $value === '') {
                    throw self::invalidArguments('--operation takes exactly one --operation=<id> value');
                }
                SourceStageReceipt::assertOperationId($value);
                $out['operation'] = $value;
            } elseif ($name !== '--format' && $name !== '--json') {
                throw self::invalidArguments("stage-source received an option it does not define: '$name'");
            } elseif ($name === '--format' && $value !== 'json') {
                throw self::invalidArguments('stage-source supports only --format=json');
            }
        }
        if (!is_string($out['from']) || !is_string($out['operation'])) {
            throw self::invalidArguments('stage-source requires --from=<branch-or-tag> and --operation=<id>');
        }

        return ['from' => $out['from'], 'operation' => $out['operation']];
    }

    private static function localDeliveryRef(string $siteRepo, string $ref): string {
        $resolved = trim(self::localGit($siteRepo, [
            'rev-parse', '--symbolic-full-name', '--verify', '--end-of-options', $ref,
        ])['stdout']);
        if (preg_match('#^refs/(?:heads|tags)/[A-Za-z0-9][A-Za-z0-9._/-]{0,240}$#D', $resolved) !== 1
            || str_contains($resolved, '..') || str_contains($resolved, '//')) {
            throw new CommandRefusalException(
                'release_stage_ref_not_deliverable',
                'the requested source is not an advertised local branch or tag',
                'name and publish a local branch or tag, then run stage-source with that exact ref'
            );
        }

        return $resolved;
    }

    private static function localObject(string $siteRepo, string $object, string $reasonCode): string {
        $result = self::localGit($siteRepo, ['rev-parse', '--verify', '--end-of-options', $object]);
        $resolved = trim($result['stdout']);
        if ($result['exit'] !== 0 || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $resolved) !== 1) {
            throw new CommandRefusalException(
                $reasonCode,
                'the requested source ref does not resolve to a Git commit and tree in the local site repository',
                'fetch or create the advertised ref locally, publish it, then retry stage-source'
            );
        }

        return $resolved;
    }

    /** @return array{exit:int,stdout:string} */
    private static function localGit(string $siteRepo, array $args): array {
        $process = @proc_open(
            array_merge(['git', '-C', $siteRepo], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => ''];
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => is_string($stdout) ? $stdout : ''];
    }

    /** @return array{target_id:string,base_commit:string,base_tree:string,source_commit:string,source_tree:string,stage_repository_path:string} */
    private static function facts(string $stdout): array {
        if (!str_starts_with($stdout, "__NEW__\n")) {
            throw new CommandRefusalException(
                'release_stage_protocol_invalid',
                'the target returned an invalid source staging response',
                'inspect the target transport and Git installation, then retry with the same operation id'
            );
        }
        $rows = [];
        foreach (array_slice(explode("\n", trim($stdout)), 1) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $rows[$key] = $value;
        }
        $required = ['target_id', 'base_commit', 'base_tree', 'source_commit', 'source_tree', 'stage_path_hex'];
        foreach ($required as $key) {
            if (!is_string($rows[$key] ?? null) || $rows[$key] === '') {
                throw new CommandRefusalException(
                    'release_stage_protocol_invalid',
                    'the target source staging response omitted a required identity',
                    'inspect the target transport and Git installation, then retry with the same operation id'
                );
            }
        }
        $path = ctype_xdigit($rows['stage_path_hex']) ? hex2bin($rows['stage_path_hex']) : false;
        if (!is_string($path) || $path === '' || preg_match('/[\x00-\x1f\x7f]/D', $path) === 1
            || preg_match('/^wprism-target:[a-f0-9]{64}$/D', $rows['target_id']) !== 1) {
            throw new CommandRefusalException(
                'release_stage_protocol_invalid',
                'the target source staging response carried an invalid stable identity or repository path',
                'inspect the target transport and Git installation, then retry with the same operation id'
            );
        }
        foreach (['base_commit', 'base_tree', 'source_commit', 'source_tree'] as $key) {
            if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $rows[$key]) !== 1) {
                throw new CommandRefusalException(
                    'release_stage_protocol_invalid',
                    'the target source staging response carried an invalid Git object id',
                    'inspect the target Git repository, then retry with the same operation id'
                );
            }
        }

        return [
            'target_id' => $rows['target_id'],
            'base_commit' => $rows['base_commit'],
            'base_tree' => $rows['base_tree'],
            'source_commit' => $rows['source_commit'],
            'source_tree' => $rows['source_tree'],
            'stage_repository_path' => $path,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private static function assertSameRequest(
        array $receipt,
        EnvironmentDriver $driver,
        string $advertisedRef,
        string $sourceRef,
        string $sourceCommit,
        string $sourceTree,
        string $operationId
    ): void {
        $same = ($receipt['environment'] ?? null) === $driver->name()
            && ($receipt['operation_id'] ?? null) === $operationId
            && ($receipt['target']['repo_path'] ?? null) === $driver->repoPath()
            && ($receipt['request']['advertised_ref'] ?? null) === $advertisedRef
            && ($receipt['request']['advertised_source_ref'] ?? null) === $sourceRef
            && is_string($receipt['source']['commit'] ?? null)
            && hash_equals($sourceCommit, $receipt['source']['commit'])
            && is_string($receipt['source']['tree'] ?? null)
            && hash_equals($sourceTree, $receipt['source']['tree']);
        if (!$same) {
            throw new CommandRefusalException(
                'release_stage_operation_conflict',
                'the operation id already belongs to different source staging inputs',
                'reuse the original exact request, or choose a new operation id for the new source lineage'
            );
        }
    }

    /** @param array<string,mixed> $result */
    private static function stageFailure(int $exit, array $result): CommandRefusalException {
        [$code, $message, $remediation, $phase] = match ($exit) {
            67 => [
                'release_stage_target_dirty',
                'the canonical target repository has tracked or untracked work',
                'review and commit or remove the target worktree changes, then retry the same stage operation',
                'cleanliness',
            ],
            68 => [
                'release_stage_target_detached',
                'the canonical target repository is detached and has no named release base',
                'switch the target to the intended named branch, then retry the same stage operation',
                'branch',
            ],
            69 => [
                'release_stage_fetch_failed',
                'the target could not fetch the advertised source ref from its configured origin',
                'publish the reviewed ref and repair target fetch credentials, then retry the same operation',
                'fetch',
            ],
            70 => [
                'release_stage_source_mismatch',
                'the target origin ref does not resolve to the exact commit selected locally',
                'publish the reviewed ref without rewriting it and retry the same operation',
                'source_identity',
            ],
            71 => [
                'release_stage_not_fast_forward',
                'the requested source is not a fast-forward of the canonical target base',
                'reconcile target history explicitly; source staging will not authorize divergent history',
                'ancestry',
            ],
            74 => [
                'release_stage_operation_conflict',
                'the operation stage ref already names different Git objects',
                'reconcile the retained stage and use a new operation id for different source inputs',
                'stage_ref',
            ],
            75, 87 => [
                'release_stage_changed',
                'the persistent inert stage could not be materialized or no longer matches its Git ref',
                'reconcile the retained stage and retry with the same inputs or a new operation id',
                'stage_worktree',
            ],
            78 => [
                'release_stage_lock_unavailable',
                'the target could not open its kernel-released source staging lock',
                'repair target Git-control storage and PHP CLI file locking, then retry the same operation',
                'lock',
            ],
            79 => [
                'release_stage_busy',
                'another source staging operation holds the target staging lock',
                'wait for that operation to finish, then retry with the same operation id',
                'lock',
            ],
            82, 84, 85, 86 => [
                'release_stage_changed',
                'the target or retained source stage changed before its receipt could be committed',
                'do not use this stage; reconcile the target and create a new source staging operation',
                'revalidation',
            ],
            88 => [
                'release_stage_receipt_write_failed',
                'the target could not atomically publish the canonical source stage receipt',
                'inspect target Git-control storage and retry with the same operation id',
                'receipt',
            ],
            default => [
                'release_stage_failed',
                'the target could not persist and verify the inert source stage',
                'inspect target Git and storage health, then retry with the same operation id',
                'storage',
            ],
        };

        return new CommandRefusalException(
            $code,
            $message,
            $remediation,
            [['code' => $code, 'phase' => $phase]],
            self::privateDetail($result)
        );
    }

    /** @param array<string,mixed> $result */
    private static function privateDetail(array $result): string {
        $stderr = trim((string) ($result['stderr'] ?? ''));
        $stdout = trim((string) ($result['stdout'] ?? ''));

        return 'source staging target exit ' . (int) ($result['exit'] ?? 1) . ': '
            . ($stderr !== '' ? $stderr : $stdout);
    }

    private static function invalidArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'use wprism stage-source <env> --from=<branch-or-tag> --operation=<id> [--format=json]'
        );
    }
}
