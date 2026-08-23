<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';

/**
 * Placing one host-built archive at one target path — the whole capability
 * gap between "the host resolved the locked components" and "the target holds
 * them" (DUO-3514).
 *
 * This is the code-half sibling of `RecoveryTransport` (RecoveryTransport.php)
 * and it exists for the same stated reason, applied to a different protocol.
 * Host→target code push needs exactly two things from a transport:
 *
 *  1. Run a script on the target — untar into a staging directory, `mv` a
 *     verified tree into place, remove the staging directory. That is
 *     `captureRaw()`, which every transport already has on the base class
 *     (Transport.php:167).
 *  2. Get ONE host-side file to a target path the host chose. That is the
 *     genuine gap: SSH scps it into the target's `/tmp`
 *     (`SshTransport::uploadFile()`), a local target shares a filesystem with
 *     the host and never reaches this path at all (its `repo_path` IS a host
 *     path, so `CodeResolveCommand` materializes directly), and a docker
 *     `run --rm` service gets a fresh container per call
 *     (DockerTransport.php:156) so a `/tmp` blob would be destroyed between
 *     the placement and the extract.
 *
 * ## Why three members rather than a reuse of `AdoptionTransport::uploadFile()`
 *
 * Verbatim the argument `RecoveryTransport.php:31-42` makes for the control
 * handoff, and it holds here for the same two reasons. `uploadFile()` carries
 * ADOPTION's closed path contract — `LocalTransport::uploadFile()` refuses
 * anything that is not the adopt tar (LocalTransport.php:255-258) — so a
 * second protocol reusing it would have to widen a deliberately narrow
 * refusal. And it forces the caller to invent a target path it cannot know is
 * reachable. Splitting allocation from placement is what lets the caller keep
 * the `$remote = allocate(); try { … } finally { remove($remote); }` shape:
 * the removal happens on every observed exit, including the one where the
 * upload itself failed after writing a partial blob, which a two-method seam
 * that only yields a path on success would silently skip.
 *
 * ## Why the gate is `instanceof`, not a driver-id match
 *
 * `CodeResolveCommand` reaches the push arm only for a transport that IS one
 * of these. Every other driver — docker with no writable bind at its
 * `repo_path`, and every hand-rolled fixture driver — keeps the
 * `code_resolve_transport_unsupported` refusal it had before this interface
 * existed, byte for byte (AGENTS.md rule 8). Widening the push to a second
 * transport is therefore an `implements` on that transport and nothing else.
 */
interface CodePushTransport extends EnvironmentDriver {
    /**
     * Reserve one TARGET-side absolute path for a code-push archive of this
     * label, without touching any filesystem.
     *
     * Pure naming, deliberately: the caller records the path BEFORE the write
     * is attempted so its `finally` can remove a partially placed archive.
     * The label is part of the wire — a live fixture asserts that a re-run
     * transferring nothing leaves no `/tmp/duo-code-push-*` behind — so it is
     * validated rather than interpolated.
     */
    public function allocateCodePushInput(string $label): string;

    /**
     * Place the host's archive bytes at a path this transport allocated.
     *
     * @return array{exit:int, stdout:string, stderr:string}
     */
    public function putCodePushInput(string $localPath, string $targetPath): array;

    /** @return array{exit:int, stdout:string, stderr:string} */
    public function removeCodePushInput(string $targetPath): array;
}
