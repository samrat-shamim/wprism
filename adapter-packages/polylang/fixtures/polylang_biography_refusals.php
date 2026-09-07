<?php
declare(strict_types=1);

require_once __DIR__ . '/polylang_biography_evidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';

use WPrism\Canon;
use WPrism\UserMetaState;
use WPrismTest\EvidenceSizeProfile;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;

/** Fixture-owned intent; generic traversal, compilation, transport and cause verification stay shared. */
final class PolylangBiographyRefusals {
    public static function profile(string $boundary, string $command): array {
        if (!in_array($boundary, ['existing', 'desired'], true) || !in_array($command, ['capture', 'plan', 'apply'], true)
            || ($boundary === 'desired' && $command === 'capture')) {
            throw new RuntimeException('Polylang biography refusal boundary is unsupported');
        }
        $where = $boundary === 'existing' ? "user 'admin' meta description_fr" : 'metadata ' . UserMetaState::path('admin') . ':description_fr';
        return ['command' => $command, 'reason_code' => $command . '_failed', 'nodes' => [[
            'parent_index' => null, 'relation' => 'root', 'class' => RuntimeException::class,
            'message' => 'wprism: ' . $where . ' is not already canonical under the native pre_user_description KSES boundary',
        ]]];
    }

    public static function coordinates(string $boundary, string $control): array {
        if ($boundary === 'safe' && $control === 'safe') return ['safe', 'safe'];
        if (!array_key_exists($control, PolylangBiographyValues::hostile())) throw new RuntimeException('Polylang biography hostile control is unsupported');
        return match ($boundary) {
            'existing' => [$control, 'safe'], 'desired' => ['safe', $control],
            default => throw new RuntimeException('Polylang biography snapshot boundary is unsupported'),
        };
    }

    public static function snapshot(string $repo, string $nativeStem, string $pair, string $service, string $boundary, string $control): array {
        [$native, $canonical] = self::coordinates($boundary, $control);
        $record = [
            'format' => 'polylang-biography-preservation/v1',
            'observation' => PolylangBiographyEvidence::capture($repo, $nativeStem, $pair, $service, $native, $canonical),
            'policy' => FilesystemTreeEvidence::capture($repo, 'site.wprism.json'),
        ];
        self::assertSnapshot($record, $boundary, $control);
        return $record;
    }

    public static function assertSnapshot(array $record, string $boundary, string $control): void {
        [$native, $canonical] = self::coordinates($boundary, $control);
        if (self::keys($record) !== ['format', 'observation', 'policy'] || $record['format'] !== 'polylang-biography-preservation/v1') {
            throw new RuntimeException('Polylang biography preservation envelope is malformed');
        }
        PolylangBiographyEvidence::assertRecord($record['observation'], $native, $canonical);
        FilesystemTreeEvidence::assertRecord($record['policy'], 'site.wprism.json');
    }

    public static function readSnapshot(string $stem, string $boundary, string $control): array {
        $record = json_decode(PrivateCommandOutput::readObject($stem, profile: EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
        self::assertSnapshot($record, $boundary, $control);
        return $record;
    }

    public static function assertPublic(array $record, string $command): void {
        $remediation = match ($command) {
            'capture' => 'inspect private operator evidence and capture recovery state; classify, correct, or recover the blocker before another attempt',
            'plan' => 'inspect private operator evidence and target state, then correct the repository, policy, capability, or target-state blocker',
            'apply' => 'inspect apply_in_progress and recovery evidence, then resume or recover according to the recorded phase',
            default => throw new RuntimeException('Polylang biography command is unsupported'),
        };
        $message = $command . ' refused at an unclassified safety gate';
        $expected = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => $command,
            'error' => $command . '_failed', 'reason_code' => $command . '_failed', 'message' => $message,
            'remediation' => $remediation, 'details_redacted' => true,
            'diagnostics' => [['code' => $command . '_failed', 'message' => $message, 'remediation' => $remediation]],
        ];
        if (Canon::encode($record) !== Canon::encode($expected)) throw new RuntimeException('Polylang biography refusal is not the exact redacted public envelope');
    }

    public static function assertPrivate(array $record, string $boundary, string $command): void {
        PrivateRefusalReceipt::assertCollection($record, self::profile($boundary, $command));
    }

    public static function baseline(string $stem, string $pair, string $service, string $boundary, string $command): void {
        $record = json_decode(PolylangBiographyEvidence::nativeOutput($stem, $pair, $service), true, 32, JSON_THROW_ON_ERROR);
        $profile = self::profile($boundary, $command);
        if (self::keys($record) !== ['baseline'] || !is_string($record['baseline'])) throw new RuntimeException('Polylang biography refusal baseline is malformed');
        PrivateRefusalReceipt::validateDiagnosticBaseline($record['baseline'], $profile['command']);
    }

    public static function nativeControl(string $stem, string $pair, string $service, string $mode, string $control): void {
        self::coordinates('existing', $control);
        $record = json_decode(PolylangBiographyEvidence::nativeOutput($stem, $pair, $service), true, 32, JSON_THROW_ON_ERROR);
        if (!in_array($mode, ['corrupt', 'restore'], true)
            || Canon::encode($record) !== Canon::encode(['format' => 'polylang-biography-control/v1', 'mode' => $mode, 'control' => $control, 'rows_changed' => 1])) {
            throw new RuntimeException('Polylang biography native control did not prove its exact row mutation');
        }
    }

    public static function verify(string $sink, string $pair, string $service, string $boundary, string $command, string $control): void {
        $before = self::readSnapshot($sink . '/before', $boundary, $control);
        $after = self::readSnapshot($sink . '/after', $boundary, $control);
        if ($before !== $after) throw new RuntimeException('Polylang biography refusal changed complete canonical or native user state');
        self::assertPublic(json_decode(PolylangBiographyEvidence::nativeOutput($sink . '/command', $pair, $service, 1), true, 32, JSON_THROW_ON_ERROR), $command);
        self::assertPrivate(json_decode(PolylangBiographyEvidence::nativeOutput($sink . '/private', $pair, $service), true, 32, JSON_THROW_ON_ERROR), $boundary, $command);
    }

    public static function corruptCanonical(string $repo, string $control): array {
        self::coordinates('desired', $control);
        $relative = 'state/' . UserMetaState::path('admin');
        $before = FilesystemTreeEvidence::capture($repo, $relative);
        self::assertFile($before, 'safe');
        Canon::write_file($repo . '/' . $relative, Canon::encode(UserMetaState::document('admin', PolylangBiographyValues::authored('{{home}}', $control))));
        $after = FilesystemTreeEvidence::capture($repo, $relative);
        self::assertFile($after, $control);
        return ['format' => 'polylang-biography-canonical-control/v1', 'control' => $control, 'before' => $before, 'after' => $after];
    }

    public static function restoreCanonical(string $repo, string $control, string $stem): void {
        $record = json_decode(PrivateCommandOutput::readObject($stem), true, 32, JSON_THROW_ON_ERROR);
        if (self::keys($record) !== ['after', 'before', 'control', 'format'] || $record['format'] !== 'polylang-biography-canonical-control/v1' || $record['control'] !== $control) {
            throw new RuntimeException('Polylang biography canonical recovery receipt is malformed');
        }
        self::assertFile($record['before'], 'safe'); self::assertFile($record['after'], $control);
        $relative = 'state/' . UserMetaState::path('admin');
        if (FilesystemTreeEvidence::capture($repo, $relative) !== $record['after']) throw new RuntimeException('Polylang biography canonical control no longer owns its preimage');
        Canon::write_file($repo . '/' . $relative, base64_decode($record['before']['files'][0]['contents_base64'], true));
        // Only the fixture's own edit is undone, after command preservation was
        // already checked. No protected-command timestamp is normalized away.
        if (!touch($repo . '/' . $relative, $record['before']['files'][0]['mtime'])
            || FilesystemTreeEvidence::capture($repo, $relative) !== $record['before']) throw new RuntimeException('Polylang biography canonical control restoration was not exact');
    }

    private static function assertFile(array $record, string $control): void {
        FilesystemTreeEvidence::assertRecord($record, 'state/' . UserMetaState::path('admin'));
        $expected = Canon::encode(UserMetaState::document('admin', PolylangBiographyValues::authored('{{home}}', $control)));
        if (count($record['files']) !== 1 || base64_decode($record['files'][0]['contents_base64'], true) !== $expected) {
            throw new RuntimeException('Polylang biography canonical control requires its exact fixture document');
        }
    }

    private static function keys(array $record): array { $keys = array_keys($record); sort($keys, SORT_STRING); return $keys; }
}

if (isset($argv) && realpath($argv[0]) === __FILE__) {
    try {
        $mode = $argv[1] ?? '';
        if ($mode === 'profile' && count($argv) === 4) echo json_encode(PolylangBiographyRefusals::profile($argv[2], $argv[3]), JSON_THROW_ON_ERROR) . "\n";
        elseif ($mode === 'baseline' && count($argv) === 7) PolylangBiographyRefusals::baseline(...array_slice($argv, 2));
        elseif ($mode === 'native-control' && count($argv) === 7) PolylangBiographyRefusals::nativeControl(...array_slice($argv, 2));
        elseif ($mode === 'snapshot' && count($argv) === 8) echo json_encode(PolylangBiographyRefusals::snapshot(...array_slice($argv, 2)), JSON_THROW_ON_ERROR) . "\n";
        elseif ($mode === 'admit-snapshot' && count($argv) === 5) PolylangBiographyRefusals::readSnapshot(...array_slice($argv, 2));
        elseif ($mode === 'verify' && count($argv) === 8) PolylangBiographyRefusals::verify(...array_slice($argv, 2));
        elseif ($mode === 'corrupt' && count($argv) === 4) echo json_encode(PolylangBiographyRefusals::corruptCanonical($argv[2], $argv[3]), JSON_THROW_ON_ERROR) . "\n";
        elseif ($mode === 'restore' && count($argv) === 5) { PolylangBiographyRefusals::restoreCanonical($argv[2], $argv[3], $argv[4]); echo "{\"restored\":true}\n"; }
        elseif ($mode === 'same' && count($argv) === 6) {
            if (PolylangBiographyRefusals::readSnapshot($argv[2], $argv[4], $argv[5]) !== PolylangBiographyRefusals::readSnapshot($argv[3], $argv[4], $argv[5])) {
                throw new RuntimeException('Polylang biography fixture restoration changed complete canonical or native user state');
            }
        } else throw new RuntimeException('Polylang biography refusal evidence arguments are invalid');
    } catch (Throwable $failure) {
        fwrite(STDERR, json_encode(['error' => get_class($failure), 'message_sha256' => hash('sha256', $failure->getMessage())], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
}
