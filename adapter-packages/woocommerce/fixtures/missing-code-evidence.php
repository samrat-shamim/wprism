<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';

use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\EvidenceSizeProfile;

final class WooCommerceMissingCodeEvidence {
    public static function profile(): array {
        // LifecyclePlanner:156,250: this fixture removes precisely the plugin
        // still declared active by the captured repository. Never infer intent
        // from the observed Throwable or accept another redacted safety gate.
        $plugin = 'woocommerce/woocommerce.php';
        $message = "active_plugins in state/options/core.json declares '$plugin' but "
            . "$plugin does not exist in this environment (checked against this environment's "
            . "wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' means "
            . "'installed on the env'). Install/vendor the plugin here, or this branch's code/ "
            . "changes haven't reached this environment yet.";
        return ['command' => 'lifecycle-status', 'reason_code' => 'lifecycle_status_failed', 'nodes' => [[
            'parent_index' => null, 'relation' => 'root', 'class' => RuntimeException::class,
            'message' => "wprism: deploy refused — code_mismatch:\n\n  - $message\n\n"
                . 'Install/vendor whatever is missing (or update code/) in this environment first, '
                . 'or pass --force-code-mismatch to proceed anyway.',
        ]]];
    }

    public static function verify(string $directory, string $pair): void {
        if (preg_match('/\A[a-z][a-z0-9]*\z/', $pair) !== 1) throw new RuntimeException('invalid lifecycle pair');
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        $read = static fn(string $stage): array => json_decode(PrivateCommandOutput::readObject(
            "$directory/$stage", $prelude, EvidenceSizeProfile::CONFORMANCE_TREE
        ), true, 32, JSON_THROW_ON_ERROR);
        $baseline = $read('baseline');
        if (array_keys($baseline) !== ['command', 'baseline'] || $baseline['command'] !== 'lifecycle-status'
            || !is_string($baseline['baseline'])) throw new RuntimeException('invalid lifecycle baseline envelope');
        PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'lifecycle-status');
        $diagnostic = $read('private');
        PrivateRefusalReceipt::verifyDiagnostic($diagnostic, self::profile());
        if (in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, 32, JSON_THROW_ON_ERROR), true)) {
            throw new RuntimeException('lifecycle refusal is not fresh');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
if ($argc !== 3) throw new RuntimeException('missing-code evidence requires private directory and pair');
WooCommerceMissingCodeEvidence::verify($argv[1], $argv[2]);
