<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/Doctor.php';

/** Host command handler for the environment doctor workflow. */
final class DoctorCommand {
    /** @param array{ok:bool,checks:list<array{label:string,ok:bool,detail:string,advisory?:bool}>} $result */
    public static function render(array $result): void {
        foreach ($result['checks'] as $check) {
            $mark = $check['ok'] ? 'PASS' : (!empty($check['advisory']) ? 'WARN' : 'FAIL');
            $line = "[$mark] {$check['label']}";
            if (!$check['ok'] && $check['detail'] !== '') {
                $line .= " — {$check['detail']}";
            }
            echo $line . "\n";
        }
    }

    public static function run(EnvironmentDriver $driver): int {
        $result = Doctor::run($driver);
        self::render($result);
        return $result['ok'] ? 0 : 1;
    }
}
