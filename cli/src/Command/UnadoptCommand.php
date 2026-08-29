<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/../Onboarding/Unadopt.php';

/** Public confirmation boundary for evidence-preserving control-plane removal. */
final class UnadoptCommand {
    /** @param list<string> $extra */
    public static function run(EnvironmentDriver $transport, array $extra): int {
        if (!$transport instanceof AdoptionTransport) {
            fwrite(STDERR, "wprism: unadopt requires a transport with explicit control-plane transfer authority\n");
            return 1;
        }
        $archive = null;
        $yes = false;
        foreach ($extra as $arg) {
            if ($arg === '--yes' && !$yes) {
                $yes = true;
                continue;
            }
            if (str_starts_with($arg, '--archive-to=') && $archive === null) {
                $archive = substr($arg, strlen('--archive-to='));
                continue;
            }
            fwrite(STDERR, "wprism: unadopt accepts exactly --archive-to=<absolute-path> and optional --yes\n");
            return 1;
        }
        if (!is_string($archive) || $archive === '') {
            fwrite(STDERR, "wprism: unadopt requires --archive-to=<absolute-path>; removal never discards control or recovery evidence\n");
            return 1;
        }

        try {
            $plan = Unadopt::plan($transport, $archive);
        } catch (\Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            return 1;
        }
        self::renderPlan($plan);
        if (!$yes) {
            if (!function_exists('stream_isatty') || !stream_isatty(STDIN)) {
                fwrite(STDERR, "wprism: unadopt requires --yes when standard input is not interactive\n");
                return 1;
            }
            fwrite(STDOUT, 'Archive the reviewed WPrism control plane and remove it from the target? [y/N] ');
            $answer = fgets(STDIN);
            if (!is_string($answer) || !in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
                echo "Unadopt cancelled; no control-plane path was moved.\n";
                return 1;
            }
        }

        try {
            $result = Unadopt::execute($transport, $plan);
        } catch (\Throwable $error) {
            fwrite(STDERR, 'wprism: unadopt failed: ' . $error->getMessage() . "\n");
            return 1;
        }
        if ($result['exit'] !== 0) {
            fwrite(STDERR, "wprism: unadopt failed during {$result['phase']}\n");
            CommandOutput::renderTransportDetail($result);
            return $result['exit'];
        }
        $receipt = $result['receipt'] ?? [];
        echo 'unadopt: WPrism control plane removed; complete evidence archive: '
            . ($receipt['archive'] ?? $archive) . "\n";
        echo 'unadopt: repository code, media, state, Git history, site.wprism.json, and durable revocations were preserved in place' . "\n";
        echo 'unadopt receipt: ' . hash('sha256', json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . "\n";
        return 0;
    }

    /** @param array<string,mixed> $plan */
    private static function renderPlan(array $plan): void {
        echo "WPrism unadopt plan {$plan['digest']}\n";
        echo "  agent: {$plan['agent_version']}\n";
        echo "  archive: {$plan['archive']}\n";
        echo "  remove from live target after archive verification:\n";
        foreach ($plan['surfaces'] as $surface) {
            echo "    {$surface['path']} ({$surface['sha256']})\n";
        }
        echo "  preserve in place:\n";
        foreach ($plan['preserved_in_place'] as $path) {
            echo "    $path\n";
        }
    }
}
