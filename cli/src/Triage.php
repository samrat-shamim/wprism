<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Interactive triage engine behind `duo classify <env>`. Pure decision
 * logic: reads prompts/responses through injected in/out streams (STDIN/
 * STDOUT in real use) rather than opening /dev/tty directly, so the whole
 * flow is pipe-testable — `printf 'r\n' | duo classify e1` drives it exactly
 * like a real keypress would. Never talks to a Transport itself; `duo
 * classify` batches the returned decisions into one `wp duo classify
 * --set=...` invocation and streams that separately, so partial/aborted
 * triage never leaves a half-applied external call.
 *
 * Per-item flow (see cli/README.md for the full contract):
 *   Enter = accept the item's proposal (only offered when one exists)
 *   a/r/e/d/m = authored/runtime/env/derived/managed (explicit override)
 *   s = skip this item, q = quit — apply whatever was already decided
 * Choosing (or accepting a proposal of) "authored" on a secret-flagged item
 * never goes through silently: it prints a warning and requires the human
 * to type the literal word "allow" before it's added to the batch. A
 * ref-hint on an authored decision offers a y/N follow-up to attach
 * `ref=<kind>` to that --set clause.
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
     *   decisions: list<array{section:string, key:string, class:string, ref?:string}>,
     *   classified: int, skipped: int, quit: bool, needAllowSecret: bool
     * }
     */
    public static function run(array $items, $in, $out): array {
        $decisions = [];
        $classified = 0;
        $skipped = 0;
        $needAllowSecret = false;
        $quit = false;
        $total = count($items);

        foreach ($items as $idx => $item) {
            $section = self::str($item['section'] ?? null, '?');
            $key = self::str($item['key'] ?? null, '?');
            $proposal = self::strOrNull($item['proposal'] ?? null);
            $secret = self::strOrNull($item['secret'] ?? null);
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

            $decision = ['section' => $section, 'key' => $key, 'class' => $class];
            $eof = false;

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
            fwrite($out, "  -> $section:$key = {$decision['class']}" . (isset($decision['ref']) ? ",ref={$decision['ref']}" : '') . "\n");

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
        ];
    }

    /**
     * Pure filtering for `duo classify --accept-proposals` (non-interactive):
     * accept every item that has a proposal, EXCEPT a secret-flagged item
     * proposed "authored" — that always needs a human, so it comes back
     * separately as secretSkipped rather than silently folded into
     * decisions. No ref-hints are attached (see cli/README.md: attaching a
     * ref is the interactive y/N prompt's judgment call, not an automated
     * one). No I/O here — `duo classify` does the batching/streaming/exit
     * code around this.
     *
     * @param list<array<string, mixed>> $items
     * @return array{decisions: list<array{section:string,key:string,class:string}>, secretSkipped: list<string>}
     */
    public static function acceptProposals(array $items): array {
        $decisions = [];
        $secretSkipped = [];
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
            $decisions[] = ['section' => $section, 'key' => $key, 'class' => $proposal];
        }
        return ['decisions' => $decisions, 'secretSkipped' => $secretSkipped];
    }

    private static function str(mixed $v, string $default): string {
        return (is_string($v) || is_int($v)) && $v !== '' ? (string) $v : $default;
    }

    private static function strOrNull(mixed $v): ?string {
        return (is_string($v) && $v !== '') ? $v : null;
    }
}
