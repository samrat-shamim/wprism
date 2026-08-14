<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/Transport.php';
require_once __DIR__ . '/LocalTransport.php';
require_once __DIR__ . '/DockerTransport.php';
require_once __DIR__ . '/SshTransport.php';
require_once __DIR__ . '/BootstrapEligibility.php';
require_once __DIR__ . '/CodeDeploy.php';
require_once __DIR__ . '/RollbackAuthority.php';
require_once __DIR__ . '/Adopt.php';
require_once __DIR__ . '/Doctor.php';
require_once __DIR__ . '/DoctorCommand.php';

/** Host command handler for the authorized adoption transaction. */
final class AdoptCommand {
    /**
     * Run the host-side adoption workflow.
     *
     * Eligibility is deliberately inspected only for an authorized local
     * transport. SSH and other adoption transports retain Adopt's existing
     * target preflight; the transaction itself remains owned by Adopt.
     */
    public static function run(EnvironmentDriver $transport, array $extra, string $sourceRoot): int {
        if ($extra !== []) {
            fwrite(STDERR, "duo: adopt accepts no extra arguments\n");
            return 1;
        }
        if (!$transport instanceof AdoptionTransport) {
            fwrite(STDERR, "duo: adopt requires a transport with explicit control-plane transfer authority\n");
            return 1;
        }

        $eligibility = null;
        if ($transport instanceof LocalTransport) {
            echo "adopt phase: read-only target eligibility\n";
            $eligibility = BootstrapEligibilityReport::inspect(
                $transport,
                $transport->name(),
                $transport->driverId(),
                $sourceRoot
            );
            foreach ($eligibility->checks() as $check) {
                $mark = $check['state'] === 'passed' ? 'PASS' : 'BLOCKED';
                echo "[$mark] bootstrap {$check['code']} — {$check['reason']}\n";
                if ($check['state'] !== 'passed') {
                    echo "  remediation: {$check['remediation']}\n";
                }
            }
            echo 'eligibility ' . $eligibility->toArray()['digest'] . "\n";
            if (!$eligibility->ready()) {
                fwrite(STDERR, "duo: adopt refused because the target is not safely eligible; no bootstrap write was attempted\n");
                return 1;
            }
        }

        echo "adopt phase: staged install + transactional doctor\n";
        $keyId = null;
        $publicKey = null;
        $recoveryConfig = null;
        if ($transport instanceof SshTransport && $transport->rollbackConfigured()) {
            try {
                $authority = new RollbackAuthority($transport);
                $keyId = $authority->keyId();
                $publicKey = $authority->publicKeyBase64();
                $recoveryConfig = $transport->recoveryConfig();
            } catch (\Throwable $error) {
                fwrite(STDERR, 'duo: adopt: ' . $error->getMessage() . "\n");
                return 1;
            }
        }

        $doctor = null;
        $isolatedDoctor = $eligibility !== null;
        $result = Adopt::install(
            $transport,
            $sourceRoot,
            $keyId,
            $publicKey,
            $recoveryConfig,
            $eligibility,
            static function () use ($transport, &$doctor, $isolatedDoctor): bool {
                $doctor = $isolatedDoctor
                    ? Doctor::runIsolated($transport)
                    : Doctor::run($transport);
                return $doctor['ok'] === true;
            }
        );
        if ($result['exit'] !== 0) {
            fwrite(STDERR, "duo: adopt failed during {$result['phase']}\n");
            CommandOutput::renderTransportDetail($result);
            if (is_array($doctor)) {
                DoctorCommand::render($doctor);
            }
            return $result['exit'];
        }

        $repoAction = $result['repo_created']
            ? 'created seed site.duo.json'
            : 'retained existing site.duo.json';
        echo "adopt: installed agent {$result['version']} + manifest library + rollback authority; $repoAction\n";
        echo "adopt phase: doctor (verified before commit)\n";
        if (!is_array($doctor)) {
            fwrite(STDERR, "duo: adopt: committed transaction has no doctor verification result\n");
            return 1;
        }
        DoctorCommand::render($doctor);
        return 0;
    }

}
