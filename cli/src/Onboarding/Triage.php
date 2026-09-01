<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

// The per-class option completions this file prompts for are the same closed
// vocabulary the batch artifact validates against; one statement of it, read
// from there (issue #3496).
require_once __DIR__ . '/ClassificationBatch.php';

/**
 * Interactive triage engine behind `wprism classify <env>`. Pure decision
 * logic: reads prompts/responses through injected in/out streams (STDIN/
 * STDOUT in real use) rather than opening /dev/tty directly, so the whole
 * flow is pipe-testable — `printf 'r\n' | wprism classify e1` drives it exactly
 * like a real keypress would. Never talks to a Transport itself; `wprism
 * classify` batches the returned decisions into one `wp wprism classify
 * --set=...` invocation and streams that separately, so partial/aborted
 * triage never leaves a half-applied external call.
 *
 * Per-item flow (see cli/README.md for the full contract):
 *   Enter = accept the item's proposal (only offered when one exists)
 *   a/r/e/d/m = authored/runtime/env/derived/managed (explicit override)
 *   s = skip this item, q = quit — apply whatever was already decided
 * Choosing (or accepting a proposal of) "authored" on a secret- or
 * PII-flagged item never goes through silently: it prints a warning and
 * requires the human to type the literal word "allow" for each applicable
 * clearance before it's added to the batch. A
 * ref-hint on an authored decision offers a y/N follow-up to attach
 * `ref=<kind>` to that --set clause.
 *
 * An options row gets one more closed follow-up, because the site grammar
 * will not load the rule without it: authored/managed asks for `autoload`,
 * env asks for the boolean `required`. Both re-ask on anything unrecognized
 * and offer `s` to skip the item — neither may be defaulted.
 */
final class Triage {
    /** letter => policy class */
    public const CLASSES = [
        'a' => 'authored',
        'r' => 'runtime',
        'e' => 'env',
        'd' => 'derived',
        'm' => 'managed',
    ];

    /**
     * @param list<array<string, mixed>> $items
     * @param resource $in
     * @param resource $out
     * @return array{
     *   decisions: list<array{section:string, key:string, class:string, ref?:string, autoload?:string, required?:bool}>,
     *   classified: int, skipped: int, quit: bool,
     *   needAllowSecret: bool, needAllowPii: bool
     * }
     */
    public static function run(array $items, $in, $out): array {
        $decisions = [];
        $classified = 0;
        $skipped = 0;
        $needAllowSecret = false;
        $needAllowPii = false;
        $quit = false;
        $total = count($items);

        foreach ($items as $idx => $item) {
            $section = self::str($item['section'] ?? null, '?');
            $key = self::str($item['key'] ?? null, '?');
            $proposal = self::strOrNull($item['proposal'] ?? null);
            $secret = self::strOrNull($item['secret'] ?? null);
            $pii = self::strOrNull($item['pii'] ?? null);
            $refHint = is_array($item['ref_hint'] ?? null) ? $item['ref_hint'] : null;
            $evidence = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];

            fwrite($out, "\n[" . ($idx + 1) . "/$total] $section:$key\n");
            fwrite($out, '  evidence: ' . Pending::formatEvidence($evidence, $section) . "\n");
            fwrite($out, '  proposal: ' . ($proposal ?? '-') . "\n");
            if ($refHint !== null) {
                fwrite($out, '  ref-hint: ' . Pending::formatRefHint($refHint) . "\n");
            }
            if ($secret !== null) {
                fwrite($out, "  \033[1;31mSECRET: $secret\033[0m\n");
            }
            if ($pii !== null) {
                fwrite($out, "  \033[1;31mPII: $pii\033[0m\n");
            }

            $menu = $proposal !== null ? "Enter=accept ($proposal)  " : '';
            $menu .= 'a=authored r=runtime e=env d=derived m=managed s=skip q=quit';

            $class = null;
            while ($class === null) {
                fwrite($out, "$menu> ");
                $line = fgets($in);
                if ($line === false) {
                    // Stdin ran out mid-item: never guess — treat exactly like
                    // an explicit quit so nothing partially-decided leaks in.
                    $quit = true;
                    break 2;
                }
                $choice = strtolower(trim($line));
                if ($choice === '' && $proposal !== null) {
                    $class = $proposal;
                } elseif ($choice === 'q') {
                    $quit = true;
                    break 2;
                } elseif ($choice === 's') {
                    $skipped++;
                    fwrite($out, "  skipped\n");
                    continue 2;
                } elseif (isset(self::CLASSES[$choice])) {
                    $class = self::CLASSES[$choice];
                } else {
                    fwrite($out, "  (unrecognized: '$choice')\n");
                }
            }

            if ($class === 'authored' && $secret !== null) {
                fwrite($out, "\n\033[1;31mWARNING: this value looks like a secret ($secret). Authoring it commits it to git.\033[0m\n");
                fwrite($out, '  Type "allow" to author it anyway, or press Enter to skip this item: ');
                $line = fgets($in);
                $confirm = $line === false ? '' : strtolower(trim($line));
                if ($confirm !== 'allow') {
                    $skipped++;
                    fwrite($out, "  skipped (secret not confirmed)\n");
                    if ($line === false) {
                        $quit = true;
                        break;
                    }
                    continue;
                }
                $needAllowSecret = true;
            }
            if ($class === 'authored' && $pii !== null) {
                fwrite($out, "\n\033[1;31mWARNING: this value looks like personal data ($pii). Authoring it commits it to git.\033[0m\n");
                fwrite($out, '  Type "allow" to author it anyway, or press Enter to skip this item: ');
                $line = fgets($in);
                $confirm = $line === false ? '' : strtolower(trim($line));
                if ($confirm !== 'allow') {
                    $skipped++;
                    fwrite($out, "  skipped (PII not confirmed)\n");
                    if ($line === false) {
                        $quit = true;
                        break;
                    }
                    continue;
                }
                $needAllowPii = true;
            }

            $decision = ['section' => $section, 'key' => $key, 'class' => $class];
            $eof = false;

            // The site grammar completes an options rule before it will load
            // it (issue #3496): authored/managed needs a storage flag, env needs
            // the provisioning boolean. Asked here, in the same pass, because
            // the alternative is the target refusing the batched write
            // half-way through — `wp wprism classify` writes each --set spec in
            // order, so a refusal on spec 7 leaves 1-6 already written.
            if ($section === 'options'
                && in_array($class, ClassificationBatch::CLASSES_NEEDING_AUTOLOAD, true)) {
                $answer = self::askClosed(
                    $in,
                    $out,
                    '  autoload (preserve replays the source row\'s own flag; a literal value pins it): ['
                    . implode('|', self::autoloadChoices()) . '|s=skip] ',
                    self::autoloadChoices()
                );
                if ($answer === null) {
                    $quit = true;
                    break;
                }
                if ($answer === 's') {
                    $skipped++;
                    fwrite($out, "  skipped (no autoload decision)\n");
                    continue;
                }
                $decision['autoload'] = $answer;
            } elseif ($section === 'options' && $class === 'env') {
                $answer = self::askClosed(
                    $in,
                    $out,
                    '  required on a fresh environment (true: an operator provisions it; false: it self-populates): [true|false|s=skip] ',
                    ['true', 'false']
                );
                if ($answer === null) {
                    $quit = true;
                    break;
                }
                if ($answer === 's') {
                    $skipped++;
                    fwrite($out, "  skipped (no required decision)\n");
                    continue;
                }
                $decision['required'] = $answer === 'true';
            }

            if ($class === 'authored' && $refHint !== null) {
                $kind = self::str($refHint['kind'] ?? null, '');
                fwrite($out, '  attach ref=' . ($kind !== '' ? $kind : '?') . ' (' . Pending::formatRefHint($refHint) . ')? [y/N] ');
                $line = fgets($in);
                $eof = $line === false;
                $yn = $eof ? '' : strtolower(trim($line));
                if (($yn === 'y' || $yn === 'yes') && $kind !== '') {
                    $decision['ref'] = $kind;
                }
            }

            $decisions[] = $decision;
            $classified++;
            $echo = "  -> $section:$key = {$decision['class']}" . (isset($decision['ref']) ? ",ref={$decision['ref']}" : '');
            if (isset($decision['autoload'])) {
                $echo .= ",autoload={$decision['autoload']}";
            }
            if (isset($decision['required'])) {
                $echo .= ',required=' . ($decision['required'] ? 'true' : 'false');
            }
            fwrite($out, $echo . "\n");

            if ($eof) {
                $quit = true;
                break;
            }
        }

        return [
            'decisions' => $decisions,
            'classified' => $classified,
            'skipped' => $skipped,
            'quit' => $quit,
            'needAllowSecret' => $needAllowSecret,
            'needAllowPii' => $needAllowPii,
        ];
    }

    /**
     * Pure filtering for `wprism classify --accept-proposals` (non-interactive):
     * accept every item that has a proposal, EXCEPT a secret- or PII-flagged
     * item proposed "authored" — those always need a human, so they come back
     * separately rather than being silently folded into
     * decisions. No ref-hints are attached (see cli/README.md: attaching a
     * ref is the interactive y/N prompt's judgment call, not an automated
     * one). No I/O here — `wprism classify` does the batching/streaming/exit
     * code around this.
     *
     * @param list<array<string, mixed>> $items
     * @return array{decisions: list<array{section:string,key:string,class:string}>, secretSkipped: list<string>, piiSkipped: list<string>, storageSkipped: list<string>}
     */
    public static function acceptProposals(array $items): array {
        $decisions = [];
        $secretSkipped = [];
        $piiSkipped = [];
        $storageSkipped = [];
        foreach ($items as $item) {
            $section = self::str($item['section'] ?? null, '?');
            $key = self::str($item['key'] ?? null, '?');
            $proposal = self::strOrNull($item['proposal'] ?? null);
            if ($proposal === null) {
                continue; // nothing to accept -- stays pending for a human
            }
            $secret = self::strOrNull($item['secret'] ?? null);
            if ($secret !== null && $proposal === 'authored') {
                $secretSkipped[] = "$section:$key ($secret)";
                continue;
            }
            $pii = self::strOrNull($item['pii'] ?? null);
            if ($pii !== null && $proposal === 'authored') {
                $piiSkipped[] = "$section:$key ($pii)";
                continue;
            }
            // A journal proposal is a CLASS signal and only that: it reports
            // which capability wrote the value on which surface
            // (Journal::propose), which says nothing about how the option row
            // must be stored or whether an operator provisions it on a fresh
            // environment. Accepting one for an options row that the site
            // grammar will not load without that second field would mean
            // inventing the field — the silent default this command has never
            // taken for secrets either, reported the same way (issue #3496).
            if ($section === 'options'
                && (in_array($proposal, ClassificationBatch::CLASSES_NEEDING_AUTOLOAD, true) || $proposal === 'env')) {
                $storageSkipped[] = "$section:$key (proposed $proposal; needs "
                    . ($proposal === 'env' ? 'required' : 'autoload') . ')';
                continue;
            }
            $decisions[] = ['section' => $section, 'key' => $key, 'class' => $proposal];
        }
        return [
            'decisions' => $decisions,
            'secretSkipped' => $secretSkipped,
            'piiSkipped' => $piiSkipped,
            'storageSkipped' => $storageSkipped,
        ];
    }

    /** `preserve` plus the storage values, in the agent's own published order. */
    private static function autoloadChoices(): array {
        return array_merge(
            ClassificationBatch::OPTION_AUTOLOAD_SENTINELS,
            ClassificationBatch::OPTION_AUTOLOAD_VALUES
        );
    }

    /**
     * One closed-vocabulary follow-up prompt. Returns the chosen value, `s`
     * to skip the item, or null at EOF — and re-asks anything else rather
     * than defaulting, because the grammar these two prompts serve refuses a
     * guessed value by construction ("insertion may never guess", "no silent
     * default either way").
     *
     * @param resource $in
     * @param resource $out
     * @param list<string> $accepted
     */
    private static function askClosed($in, $out, string $prompt, array $accepted): ?string {
        while (true) {
            fwrite($out, $prompt);
            $line = fgets($in);
            if ($line === false) {
                return null;
            }
            $choice = strtolower(trim($line));
            if ($choice === 's' || in_array($choice, $accepted, true)) {
                return $choice;
            }
            fwrite($out, "  (unrecognized: '$choice')\n");
        }
    }

    private static function str(mixed $v, string $default): string {
        return (is_string($v) || is_int($v)) && $v !== '' ? (string) $v : $default;
    }

    private static function strOrNull(mixed $v): ?string {
        return (is_string($v) && $v !== '') ? $v : null;
    }
}
