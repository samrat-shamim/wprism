<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/LocalTransport.php';
require_once __DIR__ . '/../Transport/DockerTransport.php';
require_once __DIR__ . '/../Transport/SshTransport.php';
require_once __DIR__ . '/../Transport/RecoveryTransport.php';
require_once __DIR__ . '/../Onboarding/BootstrapEligibility.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';
require_once dirname(__DIR__, 3) . '/recovery/rollback-control.php';
require_once __DIR__ . '/../Recovery/RollbackAuthority.php';
require_once __DIR__ . '/../Onboarding/Adopt.php';
require_once __DIR__ . '/../Onboarding/Doctor.php';
require_once __DIR__ . '/DoctorCommand.php';

/** Host command handler for the authorized adoption transaction. */
final class AdoptCommand {
    /**
     * Run the host-side adoption workflow.
     *
     * The dispatcher supplies explicit initial authority when the recovery
     * root does not exist. Local bootstrap always requires isolated target
     * eligibility; existing SSH targets retain Adopt's update preflight.
     */
    public static function run(
        EnvironmentDriver $transport,
        array $extra,
        string $sourceRoot,
        ?BootstrapEligibilityReport $bootstrapAuthority = null
    ): int {
        $legacyLoaderQuiesced = false;
        foreach ($extra as $arg) {
            if ($arg === AgentGenerationFence::LEGACY_QUIESCENCE_FLAG && !$legacyLoaderQuiesced) {
                $legacyLoaderQuiesced = true;
                continue;
            }
            fwrite(
                STDERR,
                'wprism: adopt accepts only optional ' . AgentGenerationFence::LEGACY_QUIESCENCE_FLAG . "\n"
            );
            return 1;
        }
        if (!$transport instanceof AdoptionTransport) {
            fwrite(STDERR, "wprism: adopt requires a transport with explicit control-plane transfer authority\n");
            return 1;
        }

        $eligibility = $bootstrapAuthority;
        if (($transport instanceof LocalTransport || $transport instanceof DockerTransport) && $eligibility === null) {
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
                fwrite(STDERR, "wprism: adopt refused because the target is not safely eligible; no bootstrap write was attempted\n");
                return 1;
            }
        }

        echo "adopt phase: staged install + transactional doctor\n";
        $keyId = null;
        $publicKey = null;
        $recoveryConfig = null;
        // Adopt::install() already installs the runtime files for every
        // AdoptionTransport (Onboarding/Adopt.php) — what this block adds is
        // the public key and recovery-config the runtime needs to verify
        // anything. Gating it on the capability interface rather than on SSH
        // is what stops a configured local adopt from installing a runtime it
        // could never authorize against.
        if ($transport instanceof RecoveryTransport && $transport->rollbackConfigured()) {
            try {
                $authority = new RollbackAuthority($transport);
                $keyId = $authority->keyId();
                $publicKey = $authority->publicKeyBase64();
                $recoveryConfig = $transport->recoveryConfig();
            } catch (\Throwable $error) {
                fwrite(STDERR, 'wprism: adopt: ' . $error->getMessage() . "\n");
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
            },
            null,
            $legacyLoaderQuiesced
        );
        if ($result['exit'] !== 0) {
            fwrite(STDERR, "wprism: adopt failed during {$result['phase']}\n");
            CommandOutput::renderTransportDetail($result);
            if (is_array($doctor)) {
                DoctorCommand::render($doctor);
            }
            return $result['exit'];
        }

        $repoAction = $result['repo_created']
            ? 'created seed site.wprism.json'
            : 'retained existing site.wprism.json';
        echo "adopt: installed agent {$result['version']} + embedded adapter library + rollback authority; $repoAction\n";
        if (($result['legacy_loader_transition'] ?? false) === true) {
            echo "adopt: legacy unfenced loader transition used the explicit quiescence attestation\n";
        }
        echo "adopt phase: doctor (verified before commit)\n";
        if (!is_array($doctor)) {
            fwrite(STDERR, "wprism: adopt: committed transaction has no doctor verification result\n");
            return 1;
        }
        DoctorCommand::render($doctor);
        return 0;
    }

}
