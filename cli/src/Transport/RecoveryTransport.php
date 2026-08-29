<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';

/**
 * Exact command surface required by the signed rollback authority protocol.
 *
 * This is the recovery sibling of `AdoptionTransport` (Transport.php:16-34)
 * and exists for the same stated reason: "Keeping this boundary narrower than
 * SshTransport makes the double-failure contract executable offline without
 * coupling the transaction to one transport" (Transport.php:11-14).
 *
 * The protocol needs exactly two things from a transport, and neither of them
 * is SSH-shaped:
 *
 *  1. Run `php <root>/recovery-runtime/rollback-control.php <action>
 *     --root=<root>` on the target. That is `captureRaw()`, which every
 *     transport already has on the base class (Transport.php:167) and which
 *     `RollbackAuthority::readStatus()` is the whole of
 *     (RollbackAuthority.php:123-131).
 *  2. Place one mode-0600 canonical-JSON handoff where that runtime can read
 *     it, then remove it. That is the genuine capability gap: SSH scps it into
 *     the target's `/tmp` (SshTransport::uploadFile), a local target writes it
 *     on the one filesystem both sides share, and a docker `run --rm` service
 *     cannot use `/tmp` at all because every call gets a fresh container
 *     (DockerTransport.php:156).
 *
 * Which is why the handoff is three members rather than a reuse of
 * `AdoptionTransport::uploadFile()`. `uploadFile()` carries adoption's closed
 * path contract — `LocalTransport::uploadFile()` refuses anything that is not
 * the adopt tar (LocalTransport.php:255-258) — and it forces the caller to
 * invent a target path it cannot know is reachable. Splitting allocation from
 * placement is what lets `RollbackAuthority` keep its
 * `$remote = allocate(); try { … } finally { remove($remote); }` shape
 * verbatim: the unconditional `finally` removal on every observed exit is
 * exactly what the 198-case SSH crash matrix certifies
 * (docs/ssh-rollback-certification.md), and a two-method seam that only
 * yields a path on success would silently drop the removal on the upload
 * failure edge.
 *
 * Everything else about the protocol is transport-free by construction: the
 * runtime is WordPress-free PHP, providers are absolute-path argv invoked
 * target-side through `proc_open(..., ['bypass_shell' => true])`
 * (recovery/ProviderClient.php:34-41), and the writer exclusion is reserved
 * and released target-side by the authority itself
 * (`RecoveryClaim::WRITER_EXCLUSION`, cli/src/Recovery/RecoveryClaim.php).
 */
interface RecoveryTransport extends EnvironmentDriver {
    /**
     * Whether this environment is expected to carry an adopted rollback
     * authority control plane at all.
     *
     * This is NOT "is the authority healthy" — that is
     * `RollbackAuthority::status()`, which costs a target round trip. It is
     * the cheap, local predicate that decides whether the round trip happens,
     * and it exists so that widening the protocol beyond SSH cannot change
     * one byte of `wprism status`, `wprism doctor` or `wprism promote` output for an
     * environment that never opted in (AGENTS.md rule 8).
     *
     * SSH answers true unconditionally: adoption provisions the runtime on
     * every SSH target (cli/src/Onboarding/Adopt.php), and `wprism status`
     * reports its authority line whether or not the controller holds a signing
     * key. A transport admitted later answers on
     * its own opt-in configuration.
     */
    public function carriesRollbackAuthority(): bool;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function captureRaw(string $script): array;

    public function name(): string;

    public function repoPath(): string;

    public function rollbackConfigured(): bool;

    public function rollbackKeyId(): ?string;

    public function rollbackSigningKeyPath(): ?string;

    public function recoveryConfigured(): bool;

    public function checkpointConfigured(): bool;

    public function codeReleaseConfigured(): bool;

    public function uploadProviderConfigured(): bool;

    public function effectProviderConfigured(): bool;

    public function verifiedRollbackConfigured(): bool;

    /** @return ?array{claim_ttl_seconds:int,encryption_key_id:string,retention_seconds:int} */
    public function verifiedRollbackConfig(): ?array;

    /** @return ?array<string,mixed> */
    public function recoveryConfig(): ?array;

    /**
     * Reserve one TARGET-side absolute path for a control handoff of this
     * label, without touching any filesystem.
     *
     * Pure naming, deliberately: the caller records the path BEFORE the write
     * is attempted so its `finally` can remove a partially placed blob. The
     * label distinguishes the two handoff shapes the authority sends —
     * `input` for `execute --input=` and `request` for the signed
     * receipt/event requests — and it is part of the wire: an offline fixture
     * pins `/tmp/wprism-rollback-request-*.json` by name
     * (sandbox/tests/fixtures/scoped-promote-unit.php:364).
     */
    public function allocateControlInput(string $label): string;

    /**
     * Place the controller's mode-0600 canonical-JSON bytes at a path this
     * transport allocated.
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function putControlInput(string $localPath, string $targetPath): array;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function removeControlInput(string $targetPath): array;
}
