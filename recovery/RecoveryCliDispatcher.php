<?php
declare(strict_types=1);

namespace Duo\Recovery;

/** CLI-only dispatcher; all authority and resource transitions stay in ports. */
final class RecoveryCliDispatcher {
    /** @return array<string,string> */
    private static function args(array $argv): array {
        $out = [];
        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, '--') && str_contains($arg, '=')) {
                [$key, $value] = explode('=', substr($arg, 2), 2);
                $out[$key] = $value;
            }
        }
        return $out;
    }

    public static function run(array $argv): int {
        array_shift($argv);
        $action = (string) (array_shift($argv) ?? '');
        $args = self::args($argv);
        $root = (string) ($args['root'] ?? '');
        if ($root === '' || $root[0] !== '/') {
            fwrite(STDERR, "duo rollback: --root must be an absolute path\n");
            return 2;
        }
        try {
            $result = match ($action) {
                'init' => RecoveryAuthorityController::initialize(
                    $root,
                    isset($args['target-id']) && $args['target-id'] !== '' ? $args['target-id'] : null
                ),
                'install-key' => (function () use ($root, $args): array {
                    RecoveryAuthorityController::installPublicKey(
                        $root,
                        (string) ($args['key-id'] ?? ''),
                        (string) ($args['public-key'] ?? '')
                    );
                    return ['ok' => true];
                })(),
                'request' => RecoveryAuthorityController::handleRequest($root, (string) ($args['request'] ?? '')),
                'configure-recovery' => RecoveryExecutor::configureFromFile($root, (string) ($args['config'] ?? '')),
                'recovery-probe' => RecoveryExecutor::probe($root),
                'exclusion-request' => RecoveryExecutor::handleExclusionRequest($root, (string) ($args['request'] ?? '')),
                'checkpoint-request' => CheckpointBundle::handleRequest($root, (string) ($args['request'] ?? '')),
                'code-release-request' => CodeRelease::handleRequest($root, (string) ($args['request'] ?? '')),
                'upload-bundle-request' => UploadBundle::handleRequest($root, (string) ($args['request'] ?? '')),
                'effect-bundle-request' => EffectBundle::handleRequest($root, (string) ($args['request'] ?? '')),
                'execute' => RecoveryExecutor::execute(
                    $root,
                    (string) ($args['adapter'] ?? ''),
                    (string) ($args['operation-id'] ?? ''),
                    (int) ($args['attempt'] ?? 0),
                    (string) ($args['claimant'] ?? ''),
                    (int) ($args['claim-epoch'] ?? 0),
                    (string) ($args['input'] ?? '')
                ),
                'active-evidence' => RecoveryAuthorityController::activeEvidence($root),
                'authority-status' => RecoveryAuthorityController::status($root),
                'scoped-promotion-witness' => RecoveryExecutor::scopedPromotionWitness($root),
                'audit' => RecoveryAuthorityController::auditEvidence($root),
                'status' => RecoveryExecutor::decorateStatus($root, RecoveryAuthorityController::status($root)),
                default => throw new \RuntimeException("duo rollback: unknown action '$action'"),
            };
            echo RecoveryAuthorityController::canonical($result) . "\n";
            return 0;
        } catch (\Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            return 1;
        }
    }
}

// Keep the exported dispatcher directly loadable for packaged runtimes and
// focused tests; the normal rollback-control bootstrap has already loaded the
// legacy facade before it reaches this file, so this is a no-op there.
if (!class_exists(RollbackControl::class, false)) {
    require_once __DIR__ . '/rollback-control.php';
}
