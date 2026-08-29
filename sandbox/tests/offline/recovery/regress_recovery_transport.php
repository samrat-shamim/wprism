<?php
/**
 * Offline characterization for the `RecoveryTransport` capability boundary.
 *
 * Verified rollback used to be SSH-only by PHP type and by config-parsing
 * location, not by any capability SSH uniquely has. This suite pins what the
 * interface now guarantees, and — just as importantly — what the refactor was
 * NOT allowed to move:
 *
 *   (a) SSH's handoff is byte-for-byte the wire it always was: the allocated
 *       path is still `/tmp/wprism-rollback-<label>-<32hex>.json`, placement is
 *       still one `scp <local> <host>:<path>`, and removal is still one
 *       `ssh -T <host> 'rm -f <path>'`. A fixture already greps the `request`
 *       label by name (sandbox/tests/fixtures/scoped-promote-unit.php),
 *       so a single unified prefix would have been a silent wire change.
 *   (b) LocalTransport's handoff is an exclusive 0600 create that refuses a
 *       path it did not reserve, refuses a second write to the same token,
 *       and refuses to unlink a path whose dev:ino no longer matches what the
 *       exclusive create observed.
 *   (d) An environment that never opted in answers every recovery predicate
 *       false and carries no rollback authority, which is what keeps `wprism
 *       envs`, `wprism status` and `wprism promote` byte-identical for it.
 *
 * DockerTransport is deliberately NOT a RecoveryTransport in this phase and
 * the suite asserts that: `docker compose run --rm` gives every call a fresh
 * container (cli/src/Transport/DockerTransport.php), so a /tmp handoff would
 * be destroyed between the placement and the `php` call — docker needs its own
 * bind-mounted handoff and its own adoption surface first.
 */
declare(strict_types=1);

// From offline/recovery/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

require_once dirname(__DIR__, 4) . '/cli/src/Transport/Transport.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/RecoveryTransport.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/RecoveryConfig.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/LocalTransport.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/DockerTransport.php';
require_once dirname(__DIR__, 4) . '/cli/src/Transport/SshTransport.php';

use WPrism\Orchestrator\DockerTransport;
use WPrism\Orchestrator\LocalTransport;
use WPrism\Orchestrator\RecoveryTransport;
use WPrism\Orchestrator\SshTransport;

$tmp = sys_get_temp_dir() . '/wprism-recovery-transport-' . bin2hex(random_bytes(8));

function rt_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            rt_remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/** @return array<string,mixed> */
function rt_recovery_keys(string $signingKey): array {
    $argv = ['/bin/true'];
    return [
        'rollback_key_id' => 'recovery-transport-key',
        'rollback_recovery' => [
            'adapters' => [
                'code_restore' => $argv,
                'database_restore' => $argv,
                'prior_verify' => $argv,
                'storage_restore' => $argv,
            ],
            'checkpoint_provider' => $argv,
            'code_release_provider' => $argv,
            'effect_provider' => $argv,
            'exclusion_provider' => $argv,
            'timeout_seconds' => 5,
            'upload_provider' => $argv,
        ],
        'rollback_signing_key' => $signingKey,
        'verified_rollback' => [
            'claim_ttl_seconds' => 90,
            'encryption_key_id' => 'kms-recovery-transport',
            'retention_seconds' => 3600,
        ],
    ];
}

$priorPath = (string) getenv('PATH');
try {
    mkdir($tmp, 0700, true);
    $signingKey = $tmp . '/signing.key';
    file_put_contents($signingKey, "unused-by-this-suite\n");

    // ---------------------------------------------------------------- (a)
    // The SSH wire, observed rather than asserted from the source: a fake
    // `scp`/`ssh` on PATH records exactly what the transport executed.
    $fakeBin = $tmp . '/fake-bin';
    mkdir($fakeBin, 0700, true);
    $scpLog = $tmp . '/scp.log';
    $sshLog = $tmp . '/ssh.log';
    file_put_contents(
        $fakeBin . '/scp',
        "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> " . escapeshellarg($scpLog) . "\nexit 0\n"
    );
    chmod($fakeBin . '/scp', 0700);
    file_put_contents(
        $fakeBin . '/ssh',
        "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> " . escapeshellarg($sshLog) . "\nexit 0\n"
    );
    chmod($fakeBin . '/ssh', 0700);
    putenv('PATH=' . $fakeBin . ':' . $priorPath);

    $ssh = new SshTransport('recovery-transport-ssh', [
        'host' => 'fixture-host',
        'repo_path' => '/srv/site',
        'transport' => 'ssh',
        'wp_path' => '/srv/wordpress',
    ] + rt_recovery_keys($signingKey));

    wprism_check(
        $ssh instanceof RecoveryTransport,
        'SshTransport implements the recovery capability interface'
    );
    $sshInput = $ssh->allocateControlInput('input');
    $sshRequest = $ssh->allocateControlInput('request');
    wprism_check(
        preg_match('#^/tmp/wprism-rollback-input-[a-f0-9]{32}\.json$#D', $sshInput) === 1,
        'the SSH operation handoff keeps its exact /tmp/wprism-rollback-input-<32hex>.json name'
    );
    wprism_check(
        preg_match('#^/tmp/wprism-rollback-request-[a-f0-9]{32}\.json$#D', $sshRequest) === 1,
        'the SSH signed-request handoff keeps its distinct wprism-rollback-request- prefix, which a fixture greps by name'
    );
    wprism_check(
        $ssh->allocateControlInput('input') !== $sshInput,
        'each allocation is an unpredictable fresh token'
    );

    $source = $tmp . '/handoff-source.json';
    file_put_contents($source, "{\"fixture\":true}\n");
    $placed = $ssh->putControlInput($source, $sshInput);
    wprism_check_same(0, $placed['exit'], 'the SSH handoff placement succeeds through the transport scp');
    wprism_check_same(
        [$source, 'fixture-host:' . $sshInput],
        explode("\n", rtrim((string) file_get_contents($scpLog), "\n")),
        'placement is one scp with the exact two arguments RollbackAuthority used to build inline'
    );
    $ssh->removeControlInput($sshInput);
    wprism_check_same(
        ['-T', 'fixture-host', 'rm -f ' . escapeshellarg($sshInput)],
        explode("\n", rtrim((string) file_get_contents($sshLog), "\n")),
        'removal is the same single ssh -T "rm -f <escaped path>" RollbackAuthority used to build inline'
    );
    putenv('PATH=' . $priorPath);

    // ---------------------------------------------------------------- (b)
    $local = new LocalTransport('recovery-transport-local', [
        '_machine_local' => true,
        'repo_path' => $tmp . '/site',
        'transport' => 'local',
        'wp_path' => $tmp . '/wordpress',
    ] + rt_recovery_keys($signingKey));

    wprism_check(
        $local instanceof RecoveryTransport,
        'LocalTransport implements the recovery capability interface'
    );
    $localPath = $local->allocateControlInput('input');
    wprism_check(
        preg_match('#^/tmp/wprism-rollback-input-[a-f0-9]{32}\.json$#D', $localPath) === 1,
        'the local handoff uses the same closed name shape as SSH'
    );
    wprism_check(!file_exists($localPath), 'allocation reserves a name and creates nothing');

    $result = $local->putControlInput($source, $localPath);
    wprism_check_same(0, $result['exit'], 'the local handoff places the controller bytes');
    wprism_check_same(
        (string) file_get_contents($source),
        (string) file_get_contents($localPath),
        'the placed handoff is byte-identical to the canonical JSON the controller wrote'
    );
    wprism_check_same(0600, fileperms($localPath) & 0777, 'the placed handoff is mode 0600');

    $again = $local->putControlInput($source, $localPath);
    wprism_check_same(64, $again['exit'], 'a second placement onto a spent token refuses');

    $unreserved = '/tmp/wprism-rollback-input-' . str_repeat('a', 32) . '.json';
    wprism_check_same(
        64,
        $local->putControlInput($source, $unreserved)['exit'],
        'a path this transport never reserved is refused rather than created'
    );
    wprism_check(
        !file_exists($unreserved),
        'the refused unreserved path is not created as a side effect'
    );
    wprism_check_same(
        64,
        $local->removeControlInput($unreserved)['exit'],
        'removal refuses a path this transport never reserved'
    );

    // The dev:ino guard: replace the file with a different inode carrying the
    // same bytes. Unlinking it would delete something this process never
    // created, which is exactly what cleanupUploadedFile() refuses for the
    // adoption archive (cli/src/Transport/LocalTransport.php).
    $substitute = $tmp . '/substitute.json';
    file_put_contents($substitute, (string) file_get_contents($source));
    unlink($localPath);
    rename($substitute, $localPath);
    $refused = $local->removeControlInput($localPath);
    wprism_check_same(73, $refused['exit'], 'removal refuses a handoff whose dev:ino changed under it');
    wprism_check(file_exists($localPath), 'the foreign replacement is retained for operator review, not deleted');
    @unlink($localPath);

    $collision = $local->allocateControlInput('input');
    file_put_contents($collision, "pre-existing\n");
    wprism_check_same(
        67,
        $local->putControlInput($source, $collision)['exit'],
        'exclusive creation refuses a reserved path that already exists'
    );
    wprism_check_same(
        "pre-existing\n",
        (string) file_get_contents($collision),
        'the colliding path keeps its own bytes'
    );
    @unlink($collision);

    $missing = $local->allocateControlInput('request');
    wprism_check_same(
        0,
        $local->removeControlInput($missing)['exit'],
        'removing a reserved handoff that was never placed is success, so the crash-edge finally is unconditional'
    );

    // ---------------------------------------------------------------- (d)
    $bare = new LocalTransport('recovery-transport-bare', [
        'repo_path' => $tmp . '/site',
        'transport' => 'local',
        'wp_path' => $tmp . '/wordpress',
    ]);
    foreach ([
        'carriesRollbackAuthority',
        'checkpointConfigured',
        'codeReleaseConfigured',
        'effectProviderConfigured',
        'recoveryConfigured',
        'rollbackConfigured',
        'uploadProviderConfigured',
        'verifiedRollbackConfigured',
    ] as $predicate) {
        wprism_check_same(
            false,
            $bare->$predicate(),
            "an environment that never opted in answers $predicate() false"
        );
    }
    wprism_check_same(null, $bare->recoveryConfig(), 'an un-opted-in local environment exposes no recovery config');
    wprism_check_same(null, $bare->verifiedRollbackConfig(), 'an un-opted-in local environment exposes no verified policy');
    wprism_check_same(
        'local  wp_path=' . $tmp . '/wordpress repo_path=' . $tmp . '/site',
        $bare->describe(),
        'wprism envs prints exactly what it printed before for an environment that never opted in'
    );
    wprism_check_same(
        'local  wp_path=' . $tmp . '/wordpress repo_path=' . $tmp . '/site'
            . ' rollback_key_id=recovery-transport-key rollback_recovery=configured verified_rollback=configured',
        $local->describe(),
        'an opted-in local environment names its rollback authority in wprism envs'
    );

    // The privilege gate: the same configuration without machine-local
    // provenance is a hard refusal, not a quietly disarmed environment.
    wprism_check_throws(
        static fn() => new LocalTransport('recovery-transport-unauthorized', [
            'repo_path' => $tmp . '/site',
            'transport' => 'local',
            'wp_path' => $tmp . '/wordpress',
        ] + rt_recovery_keys($signingKey)),
        \RuntimeException::class,
        'a local rollback authority without machine-local provenance refuses at construction',
        'local rollback authority is privileged and has no machine-local authorization'
    );

    // One parser, one set of refusals: the message an operator reads for a
    // malformed rollback_recovery is the SSH message, on every transport.
    $malformed = rt_recovery_keys($signingKey);
    $malformed['rollback_recovery']['timeout_seconds'] = 900;
    $sshRefusal = null;
    $localRefusal = null;
    try {
        new SshTransport('parity', [
            'host' => 'fixture-host',
            'repo_path' => '/srv/site',
            'transport' => 'ssh',
            'wp_path' => '/srv/wordpress',
        ] + $malformed);
    } catch (\Throwable $failure) {
        $sshRefusal = $failure->getMessage();
    }
    try {
        new LocalTransport('parity', [
            '_machine_local' => true,
            'repo_path' => $tmp . '/site',
            'transport' => 'local',
            'wp_path' => $tmp . '/wordpress',
        ] + $malformed);
    } catch (\Throwable $failure) {
        $localRefusal = $failure->getMessage();
    }
    wprism_check_same(
        "env 'parity': rollback_recovery.timeout_seconds must be 1..60",
        $sshRefusal,
        'SSH keeps its exact rollback_recovery refusal string'
    );
    wprism_check_same(
        $sshRefusal,
        $localRefusal,
        'local refuses the same malformed config with the byte-identical message, because one parser owns both'
    );

    // The phase boundary, asserted rather than assumed.
    $docker = new DockerTransport('recovery-transport-docker', [
        'compose_file' => $tmp . '/docker-compose.yml',
        'repo_path' => '/srv/site',
        'service' => 'wordpress',
        'transport' => 'docker',
    ]);
    wprism_check(
        !$docker instanceof RecoveryTransport,
        'DockerTransport is deliberately not a RecoveryTransport yet: run --rm gives every call a fresh container, so a /tmp handoff would vanish before the runtime could read it'
    );
} finally {
    putenv('PATH=' . $priorPath);
    rt_remove_tree($tmp);
}

wprism_check_summary('recovery transport capability boundary');
