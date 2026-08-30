<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/PendingCommand.php';
require_once __DIR__ . '/../Onboarding/Triage.php';
require_once __DIR__ . '/../Onboarding/ClassificationBatch.php';
require_once __DIR__ . '/../Plan/HumanViewLimit.php';

/** Host command handler for the pending-queue classification workflow. */
final class ClassifyCommand {
    /**
     * Batches triage decisions into the `--set` args for `wp wprism classify`.
     *
     * CONFIRMED against the landed agent/src/Command/Cli.php::classify() docblock
     * (task #12): wp-cli's assoc-arg parser keeps only the *last* occurrence
     * of a repeated `--set=<spec>` flag, so every decision must travel in
     * ONE semicolon-joined --set value —
     * `--set 'post_meta:foo=runtime;options:bar=authored,ref=post'` is the
     * agent's own documented example. Left as a mode switch (rather than
     * hardcoding the join) purely so a future wp-cli/agent change is still a
     * one-line fix here.
     */
    private const SET_MODE = 'joined';

    public static function run(EnvironmentDriver $driver, array $extra, ?callable $renderRefusal = null): int {
        $acceptProposals = in_array('--accept-proposals', $extra, true);
        $exportBatch = null;
        $applyBatch = null;
        $unknown = [];
        // issue #3521: the two skipped-item listings below printed one line per
        // item with no ceiling, and on a first classification pass the
        // secret-flagged set can be the size of the queue. Same flag, same
        // grammar, same `N more (use --format=json)` tail as every other
        // human view; `--format=json` on `wprism pending` is the complete list
        // the tail points at.
        try {
            $limit = HumanViewLimit::parse(
                $extra,
                static fn(): \RuntimeException => new \RuntimeException(
                    'wprism: classify: --limit must be given once as --limit=N with N between 1 and '
                        . HumanViewLimit::MAX_LIMIT
                )
            );
        } catch (\RuntimeException $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
        foreach ($extra as $arg) {
            if ($arg === '--accept-proposals' || str_starts_with($arg, '--limit=')) {
                continue;
            }
            if (str_starts_with($arg, '--export-batch=')) {
                if ($exportBatch !== null) {
                    fwrite(STDERR, "wprism: classify: --export-batch may be provided only once\n");
                    return 1;
                }
                $exportBatch = substr($arg, strlen('--export-batch='));
                continue;
            }
            if (str_starts_with($arg, '--apply-batch=')) {
                if ($applyBatch !== null) {
                    fwrite(STDERR, "wprism: classify: --apply-batch may be provided only once\n");
                    return 1;
                }
                $applyBatch = substr($arg, strlen('--apply-batch='));
                continue;
            }
            $unknown[] = $arg;
        }
        if ($unknown) {
            fwrite(STDERR, 'wprism: classify: unknown flag(s): ' . implode(' ', $unknown) . "\n");
            return 1;
        }
        $modes = (int) $acceptProposals + (int) ($exportBatch !== null) + (int) ($applyBatch !== null);
        if ($modes > 1) {
            fwrite(STDERR, "wprism: classify: choose exactly one of --accept-proposals, --export-batch, or --apply-batch\n");
            return 1;
        }

        $res = PendingCommand::fetch($driver, $renderRefusal);
        if (!$res['ok']) {
            return $res['exit'];
        }
        // Applying a previously-exported artifact must validate even when the
        // live queue is now empty: "someone else cleared it" is still a queue
        // change, not permission to silently report this stale batch as applied.
        if ($applyBatch !== null) {
            return self::applyBatch($driver, $res['items'], $applyBatch, $renderRefusal);
        }
        if (!$res['items']) {
            echo "review queue is empty\n";
            return 0;
        }

        if ($exportBatch !== null) {
            return self::exportBatch($driver, $res['items'], $exportBatch);
        }
        return $acceptProposals
            ? self::acceptProposals($driver, $res['items'], $limit)
            : self::interactive($driver, $res['items']);
    }

    /** @param list<array<string,mixed>> $items */
    private static function exportBatch(EnvironmentDriver $driver, array $items, string $path): int {
        if ($path === '') {
            fwrite(STDERR, "wprism: classify: --export-batch requires a path\n");
            return 1;
        }
        try {
            $encoded = ClassificationBatch::encode(ClassificationBatch::template($driver->name(), $items));
            if ($path === '-') {
                echo $encoded;
                return 0;
            }
            if (file_exists($path) || is_link($path)) {
                throw new \RuntimeException("wprism: refusing to overwrite existing classification batch: $path");
            }
            $parent = dirname($path);
            if (!is_dir($parent)) {
                throw new \RuntimeException("wprism: classification batch directory does not exist: $parent");
            }
            if (file_put_contents($path, $encoded, LOCK_EX) === false) {
                throw new \RuntimeException("wprism: could not write classification batch: $path");
            }
            echo "classification batch exported: $path\n";
            echo 'queue sha256: ' . ClassificationBatch::queueHash($items) . "\n";
            // The options completions are named at export time because that is
            // when the reviewer has the file open; naming them only in the
            // apply-time refusal would cost a whole round trip (issue #3496).
            echo 'review every decisions[].class; an options row classed authored or managed also needs'
                . " autoload (preserve or a literal storage value), one classed env needs required (true|false)\n";
            echo "then run: wprism classify {$driver->name()} --apply-batch=$path\n";
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }

    /** @param list<array<string,mixed>> $items */
    private static function applyBatch(EnvironmentDriver $driver, array $items, string $path, ?callable $renderRefusal = null): int {
        if ($path === '') {
            fwrite(STDERR, "wprism: classify: --apply-batch requires a path\n");
            return 1;
        }
        try {
            $validated = ClassificationBatch::validate(
                ClassificationBatch::load($path),
                $driver->name(),
                $items
            );
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
        $args = self::buildArgs(
            $validated['decisions'],
            $validated['needAllowSecret'],
            $validated['needAllowPii']
        );
        $exit = $driver->streamWp(array_merge(['wprism', 'classify', '--repo=' . $driver->repoPath()], $args));
        if ($exit !== 0) {
            return $exit;
        }
        printf("%d reviewed classification(s) applied from %s.\n", count($validated['decisions']), $path);
        $remaining = PendingCommand::fetch($driver, $renderRefusal);
        if (!$remaining['ok']) {
            return $remaining['exit'];
        }
        if ($remaining['items']) {
            fwrite(STDERR, 'wprism: classification exposed or retained ' . count($remaining['items'])
                . " pending item(s); export and review the next queue before capture.\n");
            return 2;
        }
        echo "review queue is empty\n";
        return 0;
    }

    /** @param list<array<string,mixed>> $items */
    private static function interactive(EnvironmentDriver $driver, array $items): int {
        $result = Triage::run($items, STDIN, STDOUT);

        $exit = 0;
        if ($result['decisions']) {
            $args = self::buildArgs(
                $result['decisions'],
                $result['needAllowSecret'],
                $result['needAllowPii']
            );
            $exit = $driver->streamWp(array_merge(['wprism', 'classify', '--repo=' . $driver->repoPath()], $args));
        }
        printf("\n%d classified, %d skipped.\n", $result['classified'], $result['skipped']);
        return $exit;
    }

    /**
     * Non-interactive acceptance for CI/scripting. Triage::acceptProposals()
     * does the actual filtering (pure, unit-testable without a transport);
     * this just batches the result into one `wp wprism classify` call, streams
     * it, and turns a non-empty clearance-skipped set into a non-zero exit so a CI
     * pipeline can tell "nothing to do" apart from "a human needs to look at
     * this."
     *
     * @param list<array<string,mixed>> $items
     */
    private static function acceptProposals(
        EnvironmentDriver $driver,
        array $items,
        int $limit = HumanViewLimit::DEFAULT_LIMIT
    ): int {
        $result = Triage::acceptProposals($items);
        $decisions = $result['decisions'];
        $secretSkipped = $result['secretSkipped'];
        $piiSkipped = $result['piiSkipped'];
        $storageSkipped = $result['storageSkipped'];

        $exit = 0;
        if ($decisions) {
            $args = self::buildArgs($decisions, false, false);
            $exit = $driver->streamWp(array_merge(['wprism', 'classify', '--repo=' . $driver->repoPath()], $args));
        } else {
            echo "no proposals to accept\n";
        }

        $needsHuman = false;
        if ($secretSkipped) {
            fwrite(STDERR, 'wprism: classify --accept-proposals: skipped ' . count($secretSkipped)
                . " secret-flagged item(s) proposed authored -- these need a human (\`wprism classify {$driver->name()}\`):\n");
            self::writeBounded($secretSkipped, $limit);
            $needsHuman = true;
        }
        if ($piiSkipped) {
            fwrite(STDERR, 'wprism: classify --accept-proposals: skipped ' . count($piiSkipped)
                . " PII-flagged item(s) proposed authored -- these need a human (\`wprism classify {$driver->name()}\`):\n");
            self::writeBounded($piiSkipped, $limit);
            $needsHuman = true;
        }

        // Reported after the secret set and with the same exit contract: a
        // proposal cannot carry the storage or provisioning decision the site
        // grammar demands of these rows (issue #3496), so they stay pending
        // rather than being completed by this command's guess.
        if ($storageSkipped) {
            fwrite(STDERR, 'wprism: classify --accept-proposals: skipped ' . count($storageSkipped)
                . " option item(s) whose proposed class needs a decision no proposal carries -- these need a human (\`wprism classify {$driver->name()}\`):\n");
            self::writeBounded($storageSkipped, $limit);
            $needsHuman = true;
        }
        if ($needsHuman) {
            return $exit !== 0 ? $exit : 2;
        }

        if ($decisions) {
            printf("%d accepted.\n", count($decisions));
        }
        return $exit;
    }

    /**
     * One bounded skipped-item listing, with the shared tail line.
     *
     * The count in the sentence above each list is the TRUE total and stays
     * that way: what is bounded is the sample, never the number.
     *
     * @param list<string> $rows
     */
    private static function writeBounded(array $rows, int $limit): void {
        $shown = array_slice($rows, 0, max(1, $limit));
        foreach ($shown as $s) {
            fwrite(STDERR, "  - $s\n");
        }
        $cut = HumanViewLimit::cut(count($rows), count($shown));
        if ($cut !== null) {
            fwrite(STDERR, $cut . "\n");
        }
    }

    /** @param list<array{section:string,key:string,class:string,ref?:string,cast?:string,autoload?:string,required?:bool}> $decisions */
    private static function buildArgs(array $decisions, bool $needAllowSecret, bool $needAllowPii): array {
        $specs = array_map(function (array $d): string {
            $spec = "{$d['section']}:{$d['key']}={$d['class']}";
            if (isset($d['ref'])) {
                $spec .= ",ref={$d['ref']}";
            }
            if (isset($d['cast'])) {
                $spec .= ",cast={$d['cast']}";
            }
            // The two site-grammar completions (issue #3496). `required` is a
            // real boolean by the time it gets here and travels as the only
            // two spellings the agent's spec parser accepts, so the decision
            // survives the string transport without either end guessing.
            if (isset($d['autoload'])) {
                $spec .= ",autoload={$d['autoload']}";
            }
            if (isset($d['required'])) {
                $spec .= ',required=' . ($d['required'] ? 'true' : 'false');
            }
            return $spec;
        }, $decisions);

        $args = self::SET_MODE === 'joined'
            ? ['--set=' . implode(';', $specs)]
            : array_map(fn(string $s) => '--set=' . $s, $specs);

        if ($needAllowSecret) {
            $args[] = '--allow-secret';
        }
        if ($needAllowPii) {
            $args[] = '--allow-pii';
        }
        return $args;
    }
}
