<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

/**
 * Render the target-side writer half of the agent-generation fence.
 *
 * The stable lock object is the MU-plugin directory itself. Adoption may
 * replace both `wprism/` and `wprism-loader.php`, and unadoption deliberately
 * leaves no WPrism-owned lock file behind, so either of those paths would let
 * two generations lock different inodes. One shared pending hard link gives
 * adoption and unadoption a single atomic writer authority while its sibling
 * inode inside the operation's transaction gate proves which transaction owns
 * recovery. The loader checks that shared marker before and after its flock.
 */
final class AgentGenerationFence {
    public const PENDING_MARKER = '.wprism-generation-writer-pending';
    public const LEGACY_QUIESCENCE_FLAG = '--attest-legacy-loader-quiesced';
    private const OWNER_ANCHOR = 'generation-writer-owner';
    /**
     * Closed loader identities, not substring signatures. A new loader digest
     * enters the fenced list only when that release preserves this protocol;
     * otherwise a custom file could paste the marker and consume a quiescence
     * assertion as overwrite authority.
     *
     * @var list<string>
     */
    private const LEGACY_LOADER_SHA256 = [
        '2af8f428925ae2fddad264ddba2986dab93ab2b9b83e74397f55fd160142403a',
    ];
    /** @var list<string> */
    private const FENCED_LOADER_SHA256 = [
        '03c53428a5119b02e15127edbdedabc8013b88e297abb7349d8e6c664273d4fb',
    ];

    /**
     * Bind a read-only host decision to the exact installed loader bytes. The
     * same probe is repeated under LOCK_EX by migrationAssertionShell(), so an
     * attestation cannot authorize a loader that appeared or changed after the
     * operator reviewed the boundary.
     *
     * @return array{state:'absent'|'fenced-v1'|'legacy-unfenced'|'foreign',sha256:string}
     */
    public static function inspectInstalledLoader(AdoptionTransport $transport, string $loader): array {
        $result = $transport->captureRaw(
            'php -r ' . escapeshellarg(self::loaderProbePhp()) . ' ' . escapeshellarg($loader)
        );
        if ($result['exit'] !== 0) {
            $detail = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            throw new \RuntimeException(
                'wprism: installed loader generation state is unreadable'
                . ($detail !== '' ? ': ' . $detail : '')
            );
        }
        if (preg_match(
            '/\Aloader_generation_fence=(absent|fenced-v1|legacy-unfenced|foreign)\n'
                . 'loader_sha256=(absent|[a-f0-9]{64})\n?\z/D',
            $result['stdout'],
            $match
        ) !== 1) {
            throw new \RuntimeException('wprism: installed loader generation probe returned malformed evidence');
        }
        $state = $match[1];
        $sha256 = $match[2];
        if (($state === 'absent') !== ($sha256 === 'absent')) {
            throw new \RuntimeException('wprism: installed loader generation probe returned inconsistent evidence');
        }
        return ['state' => $state, 'sha256' => $sha256];
    }

    /** A fresh adoption must never install a loader the next operation would classify as foreign. */
    public static function assertCanonicalFencedLoader(string $loader): void {
        $stat = @lstat($loader);
        $sha256 = is_array($stat) && (((int) $stat['mode']) & 0170000) === 0100000 && !is_link($loader)
            ? @hash_file('sha256', $loader)
            : false;
        if (!is_string($sha256) || !in_array($sha256, self::FENCED_LOADER_SHA256, true)) {
            throw new \RuntimeException(
                'canonical loader bytes are not registered as a supported fenced-v1 generation'
            );
        }
    }

    /**
     * The first fenced release cannot retroactively lock processes that loaded
     * the five-line legacy loader. Only a narrowly named host assertion can
     * cross that one-time boundary; accepting it for any other state would turn
     * the assertion into a blanket bypass.
     *
     * @param array{state:string,sha256:string} $probe
     */
    public static function authorizeLoaderTransition(array $probe, bool $legacyLoaderQuiesced): bool {
        $state = $probe['state'] ?? '';
        if ($state === 'foreign') {
            throw new \RuntimeException(
                'wprism: the MU loader destination is occupied by a non-WPrism file; refusing generation migration'
            );
        }
        if ($state === 'legacy-unfenced' && !$legacyLoaderQuiesced) {
            throw new \RuntimeException(self::legacyLoaderRefusal());
        }
        if ($state !== 'legacy-unfenced' && $legacyLoaderQuiesced) {
            throw new \RuntimeException(
                'wprism: ' . self::LEGACY_QUIESCENCE_FLAG
                . ' is accepted only for a detected legacy unfenced WPrism loader; remove it and retry'
            );
        }
        if (!in_array($state, ['absent', 'fenced-v1', 'legacy-unfenced'], true)) {
            throw new \RuntimeException('wprism: installed loader generation state is unsupported');
        }
        return $state === 'legacy-unfenced';
    }

    /**
     * Recheck the host-bound loader state and bytes while this transaction owns
     * the stable MU-directory LOCK_EX. A retained transaction journal records
     * the exact legacy hash and assertion if cutover is interrupted.
     *
     * @param array{state:string,sha256:string} $probe
     */
    public static function migrationAssertionShell(array $probe, bool $legacyLoaderQuiesced): string {
        self::authorizeLoaderTransition($probe, $legacyLoaderQuiesced);
        $q = static fn(string $value): string => escapeshellarg($value);
        $expected = 'loader_generation_fence=' . $probe['state'] . "\nloader_sha256=" . $probe['sha256'];
        $record = <<<'RECORD'
format=wprism-legacy-loader-quiescence-attestation/v1
assertion=traffic-held-and-all-processes-that-could-have-loaded-the-unfenced-loader-exited-through-command-completion
RECORD;
        $shell = 'generation_loader_probe=$(php -r ' . $q(self::loaderProbePhp())
            . ' "$loader") || { echo \'wprism: installed loader generation state became unreadable before cutover\' >&2; exit 1; }' . "\n"
            . '[ "$generation_loader_probe" = ' . $q($expected)
            . ' ] || { echo \'wprism: installed loader generation changed after host review; no control-plane byte was moved\' >&2; exit 1; }' . "\n";
        if ($probe['state'] !== 'legacy-unfenced') {
            return $shell;
        }
        return $shell
            . 'legacy_attestation="$txn/legacy-loader-quiescence-attestation"' . "\n"
            . '[ ! -e "$legacy_attestation" ] && [ ! -L "$legacy_attestation" ] '
                . '|| { echo \'wprism: legacy loader quiescence evidence collided\' >&2; exit 1; }' . "\n"
            . '(umask 077; set -C; printf \'%s\\nloader_sha256=%s\\n\' ' . $q($record) . ' '
                . $q($probe['sha256']) . ' > "$legacy_attestation") '
                . '|| { echo \'wprism: could not record legacy loader quiescence evidence\' >&2; exit 1; }' . "\n"
            . '[ -f "$legacy_attestation" ] && [ ! -L "$legacy_attestation" ] '
                . '|| { echo \'wprism: recorded legacy loader quiescence evidence is unsafe\' >&2; exit 1; }' . "\n";
    }

    public static function legacyLoaderRefusal(): string {
        return 'wprism: installed legacy WPrism loader has no generation fence; hold traffic, stop or drain every PHP '
            . 'and WP-CLI process that could have loaded it, keep that quiescence through command completion, then rerun with '
            . self::LEGACY_QUIESCENCE_FLAG;
    }

    private static function loaderProbePhp(): string {
        $probe = <<<'PHP'
$path = $argv[1];
$before = @lstat($path);
if (!is_array($before)) {
    echo "loader_generation_fence=absent\nloader_sha256=absent\n";
    exit(0);
}
$type = ((int) $before['mode']) & 0170000;
if ($type !== 0100000 || is_link($path)) exit(10);
$identity = static fn(array $s): string => (string) $s['dev'] . ':' . (string) $s['ino'] . ':'
    . (string) (((int) $s['mode']) & 0170000) . ':' . (string) $s['size'] . ':'
    . (string) $s['mtime'] . ':' . (string) $s['ctime'];
$handle = @fopen($path, 'rb');
$opened = is_resource($handle) ? @fstat($handle) : false;
if (!is_array($opened) || $identity($before) !== $identity($opened)) {
    if (is_resource($handle)) fclose($handle);
    exit(11);
}
$bytes = stream_get_contents($handle);
$closed = @fstat($handle);
fclose($handle);
$after = @lstat($path);
if (!is_string($bytes) || !is_array($closed) || !is_array($after)
    || $identity($opened) !== $identity($closed) || $identity($opened) !== $identity($after)) exit(12);
$sha256 = hash('sha256', $bytes);
$legacy = __WPRISM_LEGACY_LOADER_SHA256__;
$fenced = __WPRISM_FENCED_LOADER_SHA256__;
$state = in_array($sha256, $legacy, true)
    ? 'legacy-unfenced'
    : (in_array($sha256, $fenced, true) ? 'fenced-v1' : 'foreign');
echo 'loader_generation_fence=', $state, "\nloader_sha256=", $sha256, "\n";
PHP;
        return str_replace(
            ['__WPRISM_LEGACY_LOADER_SHA256__', '__WPRISM_FENCED_LOADER_SHA256__'],
            [var_export(self::LEGACY_LOADER_SHA256, true), var_export(self::FENCED_LOADER_SHA256, true)],
            $probe
        );
    }

    /**
     * Shell helpers for scripts whose canonical variables are `$mu` (the
     * ordinary MU-plugin directory) and `$lock` (their transaction gate).
     * Callers initialize generation_locked/generation_pending to zero.
     */
    public static function shellHelpers(): string {
        $q = static fn(string $value): string => escapeshellarg($value);
        $identityPhp = <<<'PHP'
$s = @lstat($argv[1]);
if (!is_array($s)) exit(1);
echo (string) $s['dev'], ':', (string) $s['ino'], ':', (string) ($s['mode'] & 0170000);
PHP;
        $lockPhp = <<<'PHP'
$path = $argv[1];
$before = @lstat($path);
$handle = @fopen('php://fd/9', 'r');
$opened = is_resource($handle) ? @fstat($handle) : false;
$identity = static fn(array $s): string => (string) $s['dev'] . ':' . (string) $s['ino'] . ':' . (string) ($s['mode'] & 0170000);
if (!is_array($before) || !is_array($opened)
    || ($before['mode'] & 0170000) !== 0040000
    || $identity($before) !== $identity($opened)
    || !flock($handle, LOCK_EX)) exit(1);
$after = @lstat($path);
if (!is_array($after) || $identity($after) !== $identity($opened)) exit(1);
PHP;

        return 'generation_identity() { php -r ' . $q($identityPhp) . " \"\$1\"; }\n"
            . "generation_lock_acquire() {\n"
            . '  generation_reuse="${1:-0}"; generation_marker="$mu/' . self::PENDING_MARKER
                . '"; generation_anchor="$lock/' . self::OWNER_ANCHOR . "\"\n"
            . "  [ -d \"\$mu\" ] && [ ! -L \"\$mu\" ] && [ -d \"\$lock\" ] && [ ! -L \"\$lock\" ] || { echo 'wprism: agent generation fence roots are unsafe' >&2; return 1; }\n"
            . "  generation_mu_identity=\$(generation_identity \"\$mu\") && generation_gate_identity=\$(generation_identity \"\$lock\") || { echo 'wprism: agent generation fence roots are unreadable' >&2; return 1; }\n"
            . "  for generation_operation_gate in \"\$mu/.wprism-adopt-lock\" \"\$mu/.wprism-unadopt-lock\"; do\n"
            . "    if [ -e \"\$generation_operation_gate\" ] || [ -L \"\$generation_operation_gate\" ]; then [ -d \"\$generation_operation_gate\" ] && [ ! -L \"\$generation_operation_gate\" ] && [ \"\$(generation_identity \"\$generation_operation_gate\")\" = \"\$generation_gate_identity\" ] || { echo 'wprism: another control-plane transaction is active or requires recovery' >&2; return 1; }; fi\n"
            . "  done\n"
            . "  if [ -e \"\$generation_marker\" ] || [ -L \"\$generation_marker\" ]; then\n"
            . "    [ \"\$generation_reuse\" = 1 ] && [ -f \"\$generation_marker\" ] && [ ! -L \"\$generation_marker\" ] && [ -f \"\$generation_anchor\" ] && [ ! -L \"\$generation_anchor\" ] || { echo 'wprism: another agent generation writer is active or requires recovery' >&2; return 1; }\n"
            . "  else\n"
            . "    if [ -e \"\$generation_anchor\" ] || [ -L \"\$generation_anchor\" ]; then [ \"\$generation_reuse\" = 1 ] && [ -f \"\$generation_anchor\" ] && [ ! -L \"\$generation_anchor\" ] || { echo 'wprism: agent generation writer ownership evidence is unsafe' >&2; return 1; }; else (umask 077; set -C; : > \"\$generation_anchor\") || { echo 'wprism: could not stage the agent generation writer authority' >&2; return 1; }; fi\n"
            . "    generation_anchor_identity=\$(generation_identity \"\$generation_anchor\") || { echo 'wprism: staged agent generation writer authority is unsafe' >&2; return 1; }\n"
            . "    [ -f \"\$generation_anchor\" ] && [ ! -L \"\$generation_anchor\" ] && ln \"\$generation_anchor\" \"\$generation_marker\" || { [ \"\$(generation_identity \"\$generation_anchor\" 2>/dev/null || true)\" != \"\$generation_anchor_identity\" ] || rm -f \"\$generation_anchor\"; echo 'wprism: another agent generation writer is active or requires recovery' >&2; return 1; }\n"
            . "  fi\n"
            . "  generation_pending=1; generation_marker_identity=\$(generation_identity \"\$generation_marker\") && generation_anchor_identity=\$(generation_identity \"\$generation_anchor\") || { echo 'wprism: agent generation writer gate is unsafe' >&2; return 1; }\n"
            . "  [ \"\$generation_marker_identity\" = \"\$generation_anchor_identity\" ] && [ -f \"\$generation_marker\" ] && [ ! -L \"\$generation_marker\" ] && [ -f \"\$generation_anchor\" ] && [ ! -L \"\$generation_anchor\" ] || { echo 'wprism: agent generation writer gate is unsafe' >&2; return 1; }\n"
            . "  exec 9<\"\$mu\" || { echo 'wprism: could not open the MU-plugin generation fence' >&2; return 1; }\n"
            . '  php -r ' . $q($lockPhp) . " \"\$mu\" || { exec 9>&-; echo 'wprism: could not acquire the MU-plugin generation fence' >&2; return 1; }\n"
            . "  generation_locked=1\n"
            . "  [ \"\$(generation_identity \"\$mu\")\" = \"\$generation_mu_identity\" ] && [ \"\$(generation_identity \"\$lock\")\" = \"\$generation_gate_identity\" ] && [ \"\$(generation_identity \"\$generation_marker\")\" = \"\$generation_marker_identity\" ] && [ \"\$(generation_identity \"\$generation_anchor\")\" = \"\$generation_marker_identity\" ] && [ -f \"\$generation_marker\" ] && [ ! -L \"\$generation_marker\" ] && [ -f \"\$generation_anchor\" ] && [ ! -L \"\$generation_anchor\" ] || { echo 'wprism: agent generation writer gate changed while waiting for readers' >&2; return 1; }\n"
            . "}\n"
            . "generation_lock_release() {\n"
            . "  generation_clean=\"\${1:-0}\"\n"
            . "  if [ \"\$generation_clean\" = 1 ] && [ \"\$generation_pending\" -eq 1 ]; then\n"
            . "    [ \"\$(generation_identity \"\$mu\")\" = \"\$generation_mu_identity\" ] && [ \"\$(generation_identity \"\$lock\")\" = \"\$generation_gate_identity\" ] && [ \"\$(generation_identity \"\$generation_marker\")\" = \"\$generation_marker_identity\" ] && [ \"\$(generation_identity \"\$generation_anchor\")\" = \"\$generation_marker_identity\" ] && [ -f \"\$generation_marker\" ] && [ ! -L \"\$generation_marker\" ] && [ -f \"\$generation_anchor\" ] && [ ! -L \"\$generation_anchor\" ] || { echo 'wprism: agent generation writer gate changed before release' >&2; return 1; }\n"
            . "    rm -f \"\$generation_marker\" || { echo 'wprism: could not retire the agent generation writer gate' >&2; return 1; }; generation_pending=0\n"
            . "  fi\n"
            . "  if [ \"\$generation_locked\" -eq 1 ]; then exec 9>&- || return 1; generation_locked=0; fi\n"
            . "}\n";
    }
}
