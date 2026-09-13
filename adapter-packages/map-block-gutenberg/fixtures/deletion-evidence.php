<?php
declare(strict_types=1);

final class MapDeletionEvidence {
    public static function inject(array $document, array $previous): array {
        $records = \WPrism\OptionState::records($document);
        if (array_key_exists('gmw-map-block-key', $records)) throw new RuntimeException('credential already appears in canonical options');
        $records['gmw-map-block-key'] = \WPrism\OptionState::deleted($previous);
        return \WPrism\OptionState::document($records);
    }

    public static function diagnostics(): array {
        return [['code' => 'repository_option_delete_not_authored', 'path' => 'options/core.json', 'uuid' => 'options/core',
            'surface' => 'option_tombstone', 'field' => 'gmw-map-block-key', 'classification' => 'env', 'declared_by' => 'map-block-gutenberg']];
    }

    public static function assertRefusal(array $report, string $command): void {
        $remediation = match ($command) {
            'compile' => 'fix every repository, policy, or code diagnostic before compiling again',
            'apply' => 'inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase',
            default => throw new RuntimeException('unknown deletion refusal command'),
        };
        $expected = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => $command,
            'error' => 'repository_authorization_failed', 'reason_code' => 'repository_authorization_failed',
            'message' => 'repository authorization refused this command', 'remediation' => $remediation,
            'diagnostics' => self::diagnostics()];
        if ($report !== $expected) throw new RuntimeException('credential deletion did not reach its exact public refusal');
    }

    public static function assertObservation(array $report): void {
        if (array_keys($report) !== ['format', 'key_preserved', 'intent_preserved', 'deleted', 'artifact_sha256', 'intent_sha256', 'options_sha256']
            || $report['format'] !== 'wprism-map-deletion-observation/v1' || $report['key_preserved'] !== true
            || $report['intent_preserved'] !== true || !is_bool($report['deleted'])) throw new RuntimeException('deletion observation premise differs');
        foreach (['artifact_sha256', 'intent_sha256', 'options_sha256'] as $field) {
            if (!is_string($report[$field]) || preg_match('/^[a-f0-9]{64}$/D', $report[$field]) !== 1) throw new RuntimeException('deletion preservation witness missing');
        }
    }

    public static function assertTransport(string $command, string $pair, string $stem): void {
        require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
        if (preg_match('/^[a-z][a-z0-9]*$/D', $pair) !== 1) throw new RuntimeException('deletion transport pair is malformed');
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        $bytes = \WPrismTest\PrivateCommandOutput::readObject($stem, $prelude, expectedExit: 1);
        self::assertRefusal(json_decode($bytes, true, 32, JSON_THROW_ON_ERROR), $command);
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'transport' && count($argv) === 5) {
            MapDeletionEvidence::assertTransport($argv[2], $argv[3], $argv[4]);
            exit(0);
        }
        if (count($argv) !== 2) throw new RuntimeException('deletion evidence command missing');
        $input = json_decode((string) stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($input)) throw new RuntimeException('deletion refusal is not an object');
        if ($argv[1] === 'observation') MapDeletionEvidence::assertObservation($input);
        else MapDeletionEvidence::assertRefusal($input, $argv[1]);
    } catch (Throwable $error) {
        fwrite(STDERR, "Map Block deletion evidence refused\n");
        exit(1);
    }
}
