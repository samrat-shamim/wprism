<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/SqlDumpEvidence.php';
require_once dirname(__DIR__) . '/native-apply/evidence.php';

final class QiNativeDependencyEvidence {
    public const PLUGIN = 'qi-blocks/class-qi-blocks.php';
    public const WRONG_PLUGIN = 'qi-blocks/qi-fixture-wrong.php';
    public const EXACT_SHA256 = '1e7c3ec1b77eb8d1663aaf177cfd8533a3794d96015d08236796872f25b77e5e';
    public const PRIOR_SHA256 = '05f51dda085ed0365922cf771ff80d0c5dfa4f6b2220dfc2b986ee87dd7b0f96';
    public const MAXIMUM_SHA256 = '1a8fbd2d49992ee21a9ed03f0bb20b9a5d8e5f052f7d69c0677ed82711b9c682';
    public const UNREADABLE_SHA256 = 'cc75e6c76917a276d48c28980fb0d43246c6df14f93b01cf46521c17b736360e';
    public const CASES = ['inactive', 'missing', 'prior', 'maximum', 'unreadable', 'wrong-basename'];

    public static function profile(string $case): array {
        $plugin = self::PLUGIN;
        $findings = [match ($case) {
            'inactive' => "active_plugins in state/options/core.json declares '$plugin', and its code is installed, but it is not active in this environment. Run 'wprism deploy <env>' before apply so activation hooks and schema migrations complete first.",
            'missing', 'wrong-basename' => "active_plugins in state/options/core.json declares '$plugin' but $plugin does not exist in this environment (checked against this environment's wp-content/plugins/ — phase 1 has no code/ deploy transport, so 'in code' means 'installed on the env'). Install/vendor the plugin here, or this branch's code/ changes haven't reached this environment yet.",
            'prior', 'maximum', 'unreadable' => "$plugin " . match ($case) {
                'prior' => '1.5.1', 'maximum' => '1.5.3', 'unreadable' => '(unknown version)',
            } . " is active in this environment, outside the 'qi-blocks' manifest's declared version_range (>=1.5.2 <1.5.3, pinned by site.wprism.json). Classification guarantees for this plugin are NOT validated against this version — apply may silently misclassify fields. Update the plugin, pin an older manifest, or pass --force-code-mismatch to proceed at your own risk.",
            default => throw new RuntimeException('unknown Qi dependency refusal'),
        }];
        if ($case === 'wrong-basename') $findings[] = "plugin '" . self::WRONG_PLUGIN . "' is active in this environment but absent from canonical active_plugins. Run 'wprism deploy <env>' before apply so its deactivation hooks complete first.";
        return ['command' => 'apply', 'reason_code' => 'apply_failed', 'nodes' => [[
            'class' => RuntimeException::class, 'parent_index' => null, 'relation' => 'root',
            'message' => "wprism: apply refused — code_mismatch:\n\n  - " . implode("\n\n  - ", $findings)
                . "\n\nRun 'wprism deploy <env>' first for lifecycle reconciliation, or pass --force-code-mismatch to proceed despite those lifecycle mismatches.",
        ]]];
    }

    public static function expected(string $case): array {
        $actual = ['version' => '1.5.2', 'active' => true, 'loaded' => false, 'wrong_version' => null,
            'wrong_active' => false, 'entry_sha256' => self::EXACT_SHA256, 'wrong_sha256' => null, 'backup_sha256' => null];
        return array_replace($actual, match ($case) {
            'restored' => [],
            'inactive' => ['active' => false],
            'missing' => ['version' => null, 'active' => false, 'entry_sha256' => null],
            'prior' => ['version' => '1.5.1', 'entry_sha256' => self::PRIOR_SHA256],
            'maximum' => ['version' => '1.5.3', 'entry_sha256' => self::MAXIMUM_SHA256, 'backup_sha256' => self::EXACT_SHA256],
            'unreadable' => ['version' => null, 'entry_sha256' => self::UNREADABLE_SHA256, 'backup_sha256' => self::EXACT_SHA256],
            'wrong-basename' => ['version' => null, 'active' => false, 'wrong_version' => '1.5.2', 'wrong_active' => true,
                'entry_sha256' => null, 'wrong_sha256' => self::EXACT_SHA256, 'backup_sha256' => self::EXACT_SHA256],
            default => throw new RuntimeException('unknown Qi dependency premise'),
        });
    }

    public static function transport(string $pair): string {
        QiNativeApplyEvidence::check(preg_match('/^[a-z][a-z0-9]*$/D', $pair) === 1, 'exact owned dependency pair');
        return ' ?Container wprism-' . preg_quote($pair, '/') . '-cli[12]-run-[a-f0-9]{12} (?:Creating|Created) *';
    }

    public static function command(string $stem, string $pair, string $root, string $verb = '', int $exit = 0): string {
        $transport = self::transport($pair);
        $pattern = $transport;
        if ($verb !== '') {
            QiNativeApplyEvidence::check(in_array($verb, ['apply', 'capture'], true), 'known dependency command');
            $prefix = 'private command diagnostics (unverified): ' . $root . '/sandbox/tmp/wprism-conformance-' . $verb . '.' . $pair . '.';
            $pattern .= '|' . preg_quote($prefix, '/') . '[A-Za-z0-9]{6}';
        }
        $bytes = WPrismTest\PrivateCommandOutput::readBytes($stem, '/\A(?:' . $pattern . ')\z/',
            WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE, expectedExit: $exit);
        if ($verb !== '') {
            $stderr = (string) file_get_contents($stem . '.stderr');
            $notice = explode("\n", $stderr, 2)[0];
            QiNativeApplyEvidence::check(str_starts_with($notice, $prefix), 'one leading private diagnostic pointer');
            $directory = substr($notice, strlen('private command diagnostics (unverified): '));
            $original = WPrismTest\PrivateCommandOutput::readBytes($directory . '/command', '/\A(?:' . $transport . ')\z/',
                WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE, expectedExit: $exit);
            QiNativeApplyEvidence::check($bytes === $original && $stderr === $notice . "\n" . file_get_contents($directory . '/command.stderr'),
                'wrapped command preserves both complete original streams');
        }
        QiNativeApplyEvidence::check(preg_match('/(?:PHP (?:Warning|Notice|Deprecated|Fatal error|Parse error)|(?:Fatal error|Warning|Notice|Deprecated|Parse error):)/', $bytes) !== 1,
            'dependency command emitted a diagnostic');
        return $bytes;
    }

    public static function images(array $before, array $after): void {
        foreach ([$before, $after] as $image) {
            QiNativeApplyEvidence::check(array_keys($image) === ['tables', 'database', 'repository', 'files', 'code'], 'complete refusal image roster');
            WPrismTest\SqlDumpEvidence::assertComplete($image['database'], WPrismTest\SqlDumpEvidence::tables($image['tables']),
                ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_wprism_map', 'wp_wprism_state'], WPrismTest\EvidenceSizeProfile::NATIVE_DATABASE);
            QiNativeApplyEvidence::check(array_keys($image['repository']) === ['state', 'policy', 'media'], 'complete repository image roster');
            foreach (['state' => 'state', 'policy' => 'site.wprism.json', 'media' => 'media'] as $name => $path) {
                WPrismTest\FilesystemTreeEvidence::assertRecord($image['repository'][$name], $path, WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
                QiNativeApplyEvidence::check(count($image['repository'][$name]['files']) > 0, 'nonempty dependency repository ' . $name);
            }
            QiNativeApplyEvidence::check(array_keys($image['files']) === ['plugins', 'uploads'], 'both native filesystem roots observed');
            foreach ($image['files'] as $name => $tree) QiNativeApplyEvidence::check(array_keys($tree) === ['root', 'directories', 'files']
                && $tree['root'] === $name && $tree['directories'] !== [] && $tree['files'] !== [], 'nonempty complete native ' . $name . ' inventory');
        }
        QiNativeApplyEvidence::check($before === $after, 'refusal changed the complete native database, canonical intent, policy, media or plugin/upload inventories');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) return;
$root = dirname(__DIR__, 4);
if (($argv[1] ?? '') === '--command') {
    QiNativeDependencyEvidence::command($argv[2], $argv[3], $root, $argv[4] ?? '', (int) ($argv[5] ?? 0));
    return;
}
if (($argv[1] ?? '') === '--snapshot') {
    $trees = [];
    foreach (['state' => 'state', 'policy' => 'site.wprism.json', 'media' => 'media'] as $name => $path) {
        $trees[$name] = WPrismTest\FilesystemTreeEvidence::capture($argv[2], $path, WPrismTest\EvidenceSizeProfile::CONFORMANCE_TREE);
    }
    echo json_encode($trees, JSON_THROW_ON_ERROR), "\n";
    return;
}
[$sink, $pair] = array_slice($argv, 1);
$read = static fn(string $name, string $verb = '', int $exit = 0): string => QiNativeDependencyEvidence::command("$sink/$name", $pair, $root, $verb, $exit);
$object = static fn(string $name, string $verb = '', int $exit = 0): array => json_decode($read($name, $verb, $exit), true, 64, JSON_THROW_ON_ERROR);
foreach (QiNativeDependencyEvidence::CASES as $case) {
    $expected = QiNativeDependencyEvidence::expected($case);
    QiNativeApplyEvidence::check($object("$case-status") === $expected, 'exact independent dependency premise: ' . $case);
    $public = $object("$case-refusal", 'apply', 1);
    QiNativeApplyEvidence::check(($public['format'] ?? null) === 'wprism-command-refusal/v1' && ($public['ok'] ?? null) === false
        && ($public['command'] ?? null) === 'apply' && ($public['reason_code'] ?? null) === 'apply_failed'
        && ($public['details_redacted'] ?? null) === true, 'public dependency refusal keeps its private cause boundary: ' . $case);
    $notice = explode("\n", (string) file_get_contents("$sink/$case-refusal.stderr"), 2)[0];
    $directory = substr($notice, strlen('private command diagnostics (unverified): '));
    $transport = '/\A(?:' . QiNativeDependencyEvidence::transport($pair) . ')\z/';
    $baseline = json_decode(WPrismTest\PrivateCommandOutput::readObject($directory . '/baseline', $transport), true, 32, JSON_THROW_ON_ERROR);
    $diagnostic = json_decode(WPrismTest\PrivateCommandOutput::readObject($directory . '/private', $transport), true, 32, JSON_THROW_ON_ERROR);
    QiNativeApplyEvidence::check(array_keys($baseline) === ['command', 'baseline'] && $baseline['command'] === 'apply', 'exact invocation freshness baseline');
    WPrismTest\PrivateRefusalReceipt::validateDiagnosticBaseline($baseline['baseline'], 'apply');
    WPrismTest\PrivateRefusalReceipt::verifyDiagnostic($diagnostic, QiNativeDependencyEvidence::profile($case));
    QiNativeApplyEvidence::check(!in_array($diagnostic['records'][0]['name'], json_decode($baseline['baseline'], true, 32, JSON_THROW_ON_ERROR), true), 'refusal graph is new to this invocation');
    $images = [];
    foreach (['before', 'after'] as $when) {
        $image = ['tables' => $read("$case-$when-tables"), 'database' => $read("$case-$when-database"),
            'repository' => $object("$case-$when-repository"), 'files' => $object("$case-$when-files"), 'code' => $object("$case-$when-code")];
        QiNativeApplyEvidence::check($image['code'] === $expected, 'refusal retains its exact dependency premise');
        $images[$when] = $image;
    }
    QiNativeDependencyEvidence::images($images['before'], $images['after']);
}
QiNativeApplyEvidence::check($object('restored-status') === QiNativeDependencyEvidence::expected('restored'), 'exact basename, bytes, version and activation restored');
QiNativeApplyEvidence::check($object('target2') === $object('restored-native') && $object('restored-native') === $object('restored-stable'),
    'all dependency faults, native reactivation, successful retry and HTTP consumers preserve the complete target native corpus');
foreach (['reactivated-apply', 'reinstalled-apply', 'restored-apply', 'restored-repeat'] as $name) {
    $apply = $object($name, 'apply');
    QiNativeApplyEvidence::check($apply['applied'] === 0 && $apply['warnings'] === [] && $apply['canary'] === 'clean'
        && $apply['drift'] === [] && $apply['actions'] === [] && $apply['verification'] === [
            'verifier' => 'canonical-recapture/v1', 'result' => 'pass', 'live_entities' => 7, 'deletions' => 0, 'skipped_user_meta' => 0,
        ], 'restored exact dependency resumes a verified zero-write Apply: ' . $name);
}
QiNativeApplyEvidence::css($read('source-http'), $read('restored-http'), $object('source1'), $object('restored-native'));
echo json_encode(['format' => 'wprism-qi-native-dependency-admission/v1', 'result' => 'pass', 'refusals' => QiNativeDependencyEvidence::CASES,
    'scope' => 'Public content-only agent Apply preflight and native code restoration. Full database and repository bytes, plugin/upload hash inventories; no host certification or source-payload transport claim.'], JSON_THROW_ON_ERROR), "\n";
