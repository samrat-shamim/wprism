<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Code/CodeClassifier.php';
require_once __DIR__ . '/AssessCommand.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeDescriptorCompiler.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeSourceLock.php';

use Duo\Canon;
use Duo\CodeDescriptorCompiler;
use Duo\CodeSourceLock;

/**
 * `duo code-classify <env>` — (re)declare an initialized repository's code
 * half: which components Git does not carry (locked), which it carries by
 * declaration (first-party), and the refusal for anything else.
 *
 * WHY THE MIGRATION IS FREE, AND WHY THAT IS THE WHOLE POINT. The descriptor
 * hashes the bytes on disk and the lock only declares provenance
 * (agent/src/Code/CodeDescriptorCompiler.php:72-191), so splitting an existing
 * repository is a pure Git operation: the bytes never leave the working tree,
 * only Git stops tracking them. The next compile therefore produces the
 * IDENTICAL `code_revision` and the identical `artifact_hash` — no re-pin, no
 * deploy, no `compiled_artifact_manifest_mismatch`, nothing fleet-visible.
 * This command asserts that equality itself, before and after it writes, and
 * refuses rather than leaving a repository whose next compile would differ.
 *
 * It runs on format-1 repositories (the pre-DUO-3499 fully vendored shape) and
 * on format-2 ones alike — the second case is how a repository gains a newly
 * imported archive's lock entry, a first-party declaration, or a
 * `duo-code-lock/v2` after a v1 — and it refuses the whole run when any
 * component classifies UNSOURCED, because there is no third state for Git to
 * hold a third-party component in.
 *
 * It runs from inside the site repository, exactly as `duo assess` and
 * `duo contract` do, and writes only into that local checkout. `<env>` is used
 * for one thing: asking the target for its own component inventory
 * (`wp duo code-inventory`) so a checkout that disagrees with the target it
 * deploys to is refused BEFORE `git rm --cached` runs on anything.
 */
final class CodeClassifyCommand {
    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $transport, array $extra): int {
        $dryRun = false;
        $offline = false;
        $cacheDir = null;
        $firstPartyValues = [];
        foreach ($extra as $arg) {
            if ($arg === '--dry-run') {
                $dryRun = true;
                continue;
            }
            if ($arg === '--offline') {
                $offline = true;
                continue;
            }
            if (str_starts_with($arg, CodeClassifier::FIRST_PARTY_FLAG)) {
                $firstPartyValues[] = substr($arg, strlen(CodeClassifier::FIRST_PARTY_FLAG));
                continue;
            }
            if (str_starts_with($arg, '--cache-dir=')) {
                $cacheDir = substr($arg, strlen('--cache-dir='));
                if ($cacheDir === '' || !str_starts_with($cacheDir, '/')) {
                    fwrite(STDERR, "duo: code-classify --cache-dir requires an absolute path\n");
                    return 1;
                }
                continue;
            }
            fwrite(
                STDERR,
                'duo: code-classify accepts only --dry-run, ' . CodeClassifier::FIRST_PARTY_FLAG
                . "<root>/<slug>, --offline and --cache-dir=<path>; unsupported argument '$arg'\n"
            );
            return 1;
        }

        try {
            $firstParty = CodeClassifier::parseFirstParty($firstPartyValues);
            $repo = AssessCommand::siteRepo(getcwd() ?: '.');
            return self::classify($transport, $repo, $dryRun, $offline, $cacheDir, $firstParty);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'duo: code-classify: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<string> $firstParty */
    private static function classify(
        EnvironmentDriver $transport,
        string $repo,
        bool $dryRun,
        bool $offline,
        ?string $cacheDir,
        array $firstParty
    ): int {
        $sitePath = $repo . '/site.duo.json';
        $raw = (string) @file_get_contents($sitePath);
        $document = json_decode($raw, false);
        if (!is_object($document)) {
            throw new \RuntimeException("$sitePath is not a JSON object");
        }
        // Canonical in, canonical out. This command rewrites one key of a
        // reviewed document; if the file on disk is not already exactly what
        // Canon produces, rewriting it would move bytes nobody asked to move.
        if (Canon::encode($document) !== $raw) {
            throw new \RuntimeException(
                'site.duo.json is not canonical, so rewriting one key would move bytes nobody reviewed; '
                . 'restore the canonical file first'
            );
        }
        $code = $document->code ?? null;
        if (!is_object($code)) {
            throw new \RuntimeException(
                'this repository has no code half to classify (site.duo.json declares no code); '
                . 'it is a state-only repository and nothing needs to change'
            );
        }
        $format = $code->format ?? null;
        if ($format !== 1 && $format !== 2) {
            throw new \RuntimeException('site.duo.json declares an unsupported code format');
        }

        // Read-only facts first, the Git precondition second: a repository
        // whose declaration is already wrong should hear about the declaration,
        // not about its working tree.
        $source = $repo . '/' . CodeDescriptorCompiler::SOURCE;
        $before = CodeDescriptorCompiler::descriptor_from_source($source);
        $inventory = CodeDescriptorCompiler::component_inventory($source);
        if ($inventory === []) {
            if ($firstParty !== []) {
                throw new \RuntimeException(
                    '--first-party names ' . implode(', ', $firstParty) . ', but this repository carries no lockable '
                    . 'plugin or theme component'
                );
            }
            echo "duo: this repository carries no lockable plugin or theme component; nothing to classify.\n";
            return 0;
        }

        // A format-2 repository already declares a lock. Its locked components
        // must be ON DISK for this run to classify them again (a fresh clone
        // without `duo code-resolve` would otherwise drop them from the lock
        // by omission), and its first-party declarations carry forward so the
        // operator states each one once, not on every run.
        $existingLocked = [];
        $existingFirstParty = [];
        if ($format === 2) {
            $lockPath = $repo . '/' . CodeSourceLock::PATH;
            if (is_link($lockPath) || !is_file($lockPath)) {
                throw new \RuntimeException(
                    'site.duo.json declares code format 2 but ' . CodeSourceLock::PATH . ' is not a regular file here'
                );
            }
            $existing = CodeSourceLock::parse((string) @file_get_contents($lockPath));
            $existingLocked = CodeSourceLock::index($existing);
            $existingFirstParty = array_keys(CodeSourceLock::first_party($existing));
            $present = [];
            foreach ($inventory as $row) {
                $present[$row['root'] . '/' . $row['component']] = true;
            }
            $absent = array_values(array_filter(
                array_keys($existingLocked),
                static fn(string $key): bool => !isset($present[$key])
            ));
            if ($absent !== []) {
                throw new \RuntimeException(
                    'the lock declares ' . implode(', ', $absent) . ' but the component is not on disk; run '
                    . '`duo code-resolve <env>` first so this run classifies every component the lock names'
                );
            }
            $relocked = array_values(array_filter(
                $firstParty,
                static fn(string $identity): bool => isset($existingLocked[$identity])
            ));
            if ($relocked !== []) {
                throw new \RuntimeException(
                    implode(', ', $relocked) . ' is a locked component in the current lock; a component Git does '
                    . 'not carry cannot become first-party in the same run — remove its lock entry and `git add` its '
                    . 'tree deliberately first'
                );
            }
            $firstParty = CodeSourceLock::sort_first_party(array_merge(
                array_values(array_filter($existingFirstParty, static fn(string $id): bool => isset($present[$id]))),
                $firstParty
            ));
        }

        $candidates = array_map(
            static fn(array $row): array => [
                'root' => $row['root'],
                'component' => $row['component'],
                'version' => $row['version'],
                'tree_sha256' => $row['tree_sha256'],
            ],
            $inventory
        );
        CodeClassifier::assertFirstPartyKnown($candidates, $firstParty);
        self::assertCleanCodeTree($repo);
        self::assertTargetAgrees($transport, $inventory);

        $plan = CodeClassifier::make($cacheDir, $offline)->classify($candidates, $firstParty);
        foreach ($plan as $row) {
            echo '  ' . CodeClassifier::renderRow($row) . "\n";
        }
        $unsourced = CodeClassifier::unsourced($plan);
        if ($unsourced !== []) {
            fwrite(
                STDERR,
                'duo: code-classify: ' . count($unsourced) . ' component(s) could not be sourced and Git must not carry '
                . 'third-party code; nothing was written. For each: import its release archive on this host with '
                . '`duo code-import <archive.zip>` and rerun, or declare it the site\'s own code with '
                . CodeClassifier::FIRST_PARTY_FLAG . "<root>/<slug>.\n"
            );
            return 1;
        }

        $lockRows = CodeClassifier::lockRows($plan);
        $firstPartyIdentities = CodeClassifier::firstPartyIdentities($plan);
        $lockBytes = CodeSourceLock::encode($lockRows, $firstPartyIdentities);
        $newlyLocked = array_values(array_filter(
            $lockRows,
            static fn(array $row): bool => !isset($existingLocked[$row['root'] . '/' . $row['component']])
        ));
        $ignoreLines = array_map(
            static fn(array $row): string => CodeSourceLock::gitignore_line($row['root'], $row['component']),
            CodeSourceLock::sort_components($lockRows)
        );
        if ($dryRun) {
            echo "duo: --dry-run: nothing was written. The declaration would be\n";
            echo '  ' . CodeSourceLock::PATH . ' (' . count($lockRows) . ' locked, ' . count($firstPartyIdentities)
                . ' first-party; ' . count($newlyLocked) . " newly locked tree(s) untracked)\n";
            foreach ($ignoreLines as $line) {
                echo "  .gitignore $line\n";
            }
            echo "  site.duo.json code format 2\n";
            return 0;
        }

        Canon::write_file($repo . '/' . CodeSourceLock::PATH, $lockBytes);
        self::appendIgnoreLines($repo, $ignoreLines);
        $code->format = 2;
        $code->lock = CodeSourceLock::PATH;
        Canon::write_file($sitePath, Canon::encode($document));

        // The bytes never moved, so the descriptor must not have either. This
        // is the migration's whole claim, asserted rather than assumed.
        $after = CodeDescriptorCompiler::descriptor_from_source($source);
        if (Canon::encode($before) !== Canon::encode($after)) {
            throw new \RuntimeException(
                'the code descriptor changed while the declaration was written; the repository was left as-is for '
                . 'review and no tree was untracked'
            );
        }
        // Only now, with the descriptor proven unchanged and the lock on disk,
        // does Git stop tracking the newly locked trees.
        self::untrack($repo, $newlyLocked);

        echo 'duo: declared ' . count($lockRows) . ' locked and ' . count($firstPartyIdentities) . ' first-party component(s) in '
            . CodeSourceLock::PATH . '; code_revision is unchanged at ' . $after['code_revision'] . ".\n";
        echo 'duo: the bytes are still on disk and still compile; Git no longer tracks the ' . count($newlyLocked)
            . " newly locked tree(s). Review and commit:\n";
        echo '  git -C ' . escapeshellarg($repo) . ' add .gitignore site.duo.json ' . CodeSourceLock::PATH . "\n";
        echo '  git -C ' . escapeshellarg($repo) . " commit -m 'duo: declare the code half in duo-code.lock.json'\n";
        if ($lockRows !== []) {
            echo 'duo: a fresh clone of this repository needs `duo code-resolve` before it can compile; an imported-archive '
                . "component resolves only on a host where `duo code-import` stored its archive.\n";
        }
        return 0;
    }

    /**
     * Refuse on any pending change under `code/`.
     *
     * `git rm --cached` on a tree with staged or unstaged edits would put the
     * only copy of those edits outside Git's reach in the same breath that
     * stops tracking them. The check mirrors sandbox/bin/pair.sh:355, which
     * refuses its own mutation for the same reason.
     */
    private static function assertCleanCodeTree(string $repo): void {
        $status = self::git($repo, ['status', '--porcelain=v1', '--', 'code']);
        if ($status['exit'] !== 0) {
            throw new \RuntimeException(
                'could not read Git status for code/ (' . trim($status['stderr']) . '); '
                . 'run this command inside a Git worktree with git available'
            );
        }
        if (trim($status['stdout']) !== '') {
            throw new \RuntimeException(
                "code/ has uncommitted changes:\n" . rtrim($status['stdout'])
                . "\ncommit or discard them first: this command stops Git from tracking whole component trees, "
                . 'and an edit inside one would become the only copy'
            );
        }
    }

    /**
     * The target must describe the same components as this checkout.
     *
     * The classification is computed from LOCAL bytes but the repository it
     * rewrites is the one that target compiles, so a disagreement means the
     * checkout and the target are at different revisions — and untracking a
     * tree on that basis would be untracking the wrong bytes.
     *
     * @param list<array<string,mixed>> $inventory
     */
    private static function assertTargetAgrees(EnvironmentDriver $transport, array $inventory): void {
        $result = $transport->captureWp([
            'duo', 'code-inventory', '--repo=' . $transport->repoPath(), '--format=json',
        ]);
        if ($result['exit'] !== 0) {
            throw new \RuntimeException(
                "the target could not report its code inventory (exit {$result['exit']}): "
                . trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout'])
            );
        }
        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 'duo-code-inventory/v1'
            || !is_array($decoded['components'] ?? null)) {
            throw new \RuntimeException('the target returned an unrecognized code inventory');
        }
        $remote = [];
        foreach ($decoded['components'] as $row) {
            if (is_array($row) && is_string($row['root'] ?? null) && is_string($row['component'] ?? null)) {
                $remote[$row['root'] . '/' . $row['component']] = (string) ($row['tree_sha256'] ?? '');
            }
        }
        $local = [];
        foreach ($inventory as $row) {
            $local[$row['root'] . '/' . $row['component']] = (string) $row['tree_sha256'];
        }
        ksort($remote, SORT_STRING);
        ksort($local, SORT_STRING);
        if ($remote !== $local) {
            throw new \RuntimeException(
                'this checkout and the target repository describe different code components; '
                . 'bring them to the same revision before classifying, so the trees this command untracks '
                . 'are the trees that target compiles'
            );
        }
    }

    /** @param list<string> $lines */
    private static function appendIgnoreLines(string $repo, array $lines): void {
        $path = $repo . '/.gitignore';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $known = array_fill_keys(preg_split('/\r?\n/', $existing) ?: [], true);
        $missing = array_values(array_filter($lines, static fn(string $line): bool => !isset($known[$line])));
        if ($missing === []) {
            return;
        }
        $next = $existing;
        if ($next !== '' && !str_ends_with($next, "\n")) {
            $next .= "\n";
        }
        if ($next !== '') {
            $next .= "\n";
        }
        // The same block heading InitRepositoryBoundary::ensure_gitignore()
        // writes, so an initialized repository and a migrated one are
        // indistinguishable afterwards.
        $next .= '# Duo code lock: these components are declared in ' . CodeSourceLock::PATH
            . ", not carried in Git\n" . implode("\n", $missing) . "\n";
        if (file_put_contents($path, $next) === false) {
            throw new \RuntimeException("could not write $path");
        }
    }

    /** @param list<array<string,mixed>> $lockRows */
    private static function untrack(string $repo, array $lockRows): void {
        foreach (CodeSourceLock::sort_components($lockRows) as $row) {
            $path = CodeDescriptorCompiler::SOURCE . '/' . $row['root'] . '/' . $row['component'];
            $result = self::git($repo, ['rm', '-r', '--cached', '--quiet', '--', $path]);
            if ($result['exit'] !== 0) {
                throw new \RuntimeException(
                    "could not untrack $path (" . trim($result['stderr']) . '); the lock and the ignore lines are '
                    . 'already written, so re-run this command after resolving it'
                );
            }
        }
    }

    /**
     * @param list<string> $args
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function git(string $repo, array $args): array {
        if (!function_exists('proc_open')) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'proc_open is disabled on this host'];
        }
        $process = @proc_open(
            ['git', '-C', $repo, ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'git could not be started'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
