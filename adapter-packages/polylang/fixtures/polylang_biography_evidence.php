<?php
declare(strict_types=1);

require_once __DIR__ . '/polylang_biography_values.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/agent_version.php';
require_once dirname(__DIR__, 3) . '/agent/src/Policy/Policy.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/MetaRows.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/Code.php';
require_once dirname(__DIR__, 3) . '/agent/src/Code/CodeStateContract.php';
require_once dirname(__DIR__, 3) . '/agent/src/Repository/RepositoryAuthorization.php';
require_once dirname(__DIR__, 3) . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\UserMetaState;
use WPrismTest\EvidenceSizeProfile;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;

final class PolylangBiographyEvidence {
    public static function seedReceipt(string $stem, string $pair, string $service, string $mode): string {
        $bytes = self::nativeOutput($stem, $pair, $service);
        $record = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        if (!in_array($mode, ['seed-source', 'seed-target'], true)
            || self::keys($record) !== ['format', 'keys', 'mode'] || $record['format'] !== 'polylang-biography-seed/v1'
            || $record['mode'] !== $mode || $record['keys'] !== PolylangBiographyValues::KEYS) {
            throw new RuntimeException('Polylang biography seed receipt has an invalid closed roster');
        }
        return $bytes;
    }

    private static function nativeOutput(string $stem, string $pair, string $service): string {
        if (preg_match('/^[a-z][a-z0-9]{2,23}$/D', $pair) !== 1 || !in_array($service, ['cli1', 'cli2'], true)) {
            throw new RuntimeException('Polylang biography native transport identity is invalid');
        }
        $prelude = '/^ ?Container wprism-' . preg_quote($pair, '/') . '-' . $service . '-run-[a-f0-9]+ (Creating|Created) *$/D';
        return PrivateCommandOutput::readObject($stem, $prelude);
    }

    public static function assertNative(array $native): void {
        if (self::keys($native) !== ['admin_id', 'biographies', 'default_shadow', 'format', 'home', 'hostile_oracle', 'metadata', 'orphan_rows', 'users']
            || $native['format'] !== 'polylang-biography-native/v1'
            || !is_string($native['home']) || preg_match('~^http://localhost:[0-9]+$~D', $native['home']) !== 1
            || \WPrism\MetaRows::positive_id($native['admin_id']) === null || $native['orphan_rows'] !== '0'
            || !is_array($native['users']) || !array_is_list($native['users']) || $native['users'] === [] || count($native['users']) > 64
            || !is_array($native['metadata']) || !array_is_list($native['metadata']) || count($native['metadata']) !== count($native['users'])
            || $native['default_shadow'] !== []) {
            throw new RuntimeException('Polylang biography native evidence has an invalid closed roster');
        }
        $lastUser = 0;
        $admin = null;
        $metaIds = [];
        foreach ($native['users'] as $position => $user) {
            if (!is_array($user) || self::keys($user) !== ['ID', 'display_name', 'user_activation_key', 'user_email', 'user_login', 'user_nicename', 'user_pass', 'user_registered', 'user_status', 'user_url']
                || count(array_filter($user, 'is_string')) !== count($user)
                || \WPrism\MetaRows::positive_id($user['ID']) === null || (int) $user['ID'] <= $lastUser) {
                throw new RuntimeException('Polylang biography native user roster is malformed');
            }
            $lastUser = (int) $user['ID'];
            $owner = $native['metadata'][$position];
            if (!is_array($owner) || self::keys($owner) !== ['rows', 'user_id'] || $owner['user_id'] !== $user['ID']
                || !is_array($owner['rows']) || !array_is_list($owner['rows']) || count($owner['rows']) > 512) {
                throw new RuntimeException('Polylang biography metadata owner roster is malformed');
            }
            $lastMeta = 0;
            foreach ($owner['rows'] as $row) {
                if (!is_array($row) || self::keys($row) !== ['meta_id', 'meta_key', 'meta_value']
                    || \WPrism\MetaRows::positive_id($row['meta_id']) === null
                    || (int) $row['meta_id'] <= $lastMeta || isset($metaIds[$row['meta_id']])
                    || !is_string($row['meta_key']) || $row['meta_key'] === ''
                    || (!is_string($row['meta_value']) && $row['meta_value'] !== null)) {
                    throw new RuntimeException('Polylang biography native metadata row is malformed');
                }
                $lastMeta = (int) $row['meta_id'];
                $metaIds[$row['meta_id']] = true;
            }
            if ($user['user_login'] === 'admin') {
                if ($admin !== null || $user['ID'] !== $native['admin_id']) throw new RuntimeException('Polylang biography exact login is ambiguous');
                $admin = $owner['rows'];
            }
        }
        if ($admin === null) throw new RuntimeException('Polylang biography exact login is absent');
        if (array_filter($admin, static fn(array $row): bool => $row['meta_key'] === 'description_en') !== []) {
            throw new RuntimeException('Polylang biography default shadow key must be absent in native storage');
        }
        $expected = PolylangBiographyValues::authored($native['home']);
        $api = [];
        foreach ($expected as $key => $value) {
            $raw = array_values(array_filter($admin, static fn(array $row): bool => $row['meta_key'] === $key));
            if (count($raw) !== 1 || $raw[0]['meta_value'] !== $value) throw new RuntimeException('Polylang biography native raw value differs from declared intent');
            $api[$key] = [$value];
        }
        if ($native['biographies'] !== $api) throw new RuntimeException('Polylang biography native API differs from raw intent');
        if (array_keys($native['hostile_oracle']) !== array_keys(PolylangBiographyValues::hostile())) throw new RuntimeException('Polylang biography hostile oracle roster is incomplete');
        foreach (PolylangBiographyValues::hostile() as $name => $value) {
            $oracle = $native['hostile_oracle'][$name];
            if (!is_array($oracle) || self::keys($oracle) !== ['input', 'sanitized'] || $oracle['input'] !== $value
                || !is_string($oracle['sanitized']) || $oracle['sanitized'] === $value) {
                throw new RuntimeException('Polylang biography hostile native oracle premise failed');
            }
        }
    }

    public static function capture(string $repo, string $nativeStem, string $pair, string $service): array {
        if (function_exists('wp_kses')) {
            throw new RuntimeException('Polylang biography requires its standalone host compiler and exact native transport');
        }
        $native = json_decode(self::nativeOutput($nativeStem, $pair, $service), true, 32, JSON_THROW_ON_ERROR);
        self::assertNative($native);
        $tree = FilesystemTreeEvidence::capture($repo, 'state', EvidenceSizeProfile::CONFORMANCE_TREE);
        $policy = \WPrism\Policy::load($repo, adapterLibrary: \WPrism\AdapterLibrary::fromSourceTree(dirname(__DIR__, 3)));
        $artifact = \WPrism\RepositoryCompiler::compile($repo, $policy);
        $compiled = $artifact->tree()[UserMetaState::key('admin')]['data'] ?? null;
        if (FilesystemTreeEvidence::capture($repo, 'state', EvidenceSizeProfile::CONFORMANCE_TREE) !== $tree || function_exists('wp_kses')) {
            throw new RuntimeException('Polylang biography compilation changed its tree or loaded a native sanitizer');
        }
        $record = ['format' => 'polylang-biography-evidence/v1', 'native' => $native, 'tree' => $tree, 'compiled' => $compiled];
        self::assertRecord($record);
        return $record;
    }

    public static function assertRecord(array $record): void {
        if (self::keys($record) !== ['compiled', 'format', 'native', 'tree'] || $record['format'] !== 'polylang-biography-evidence/v1') {
            throw new RuntimeException('Polylang biography evidence envelope is malformed');
        }
        self::assertNative($record['native']);
        FilesystemTreeEvidence::assertRecord($record['tree'], 'state', EvidenceSizeProfile::CONFORMANCE_TREE);
        $rows = array_values(array_filter($record['tree']['files'], static fn(array $file): bool => $file['path'] === UserMetaState::path('admin')));
        $expected = UserMetaState::document('admin', PolylangBiographyValues::authored('{{home}}'));
        if (count($rows) !== 1 || base64_decode($rows[0]['contents_base64'], true) !== Canon::encode($expected)
            || Canon::encode($record['compiled']) !== Canon::encode($expected)) {
            throw new RuntimeException('Polylang biography canonical and compiled intent must retain exact home tokens');
        }
    }

    private static function keys(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
    }
}

if (isset($argv) && realpath($argv[0]) === __FILE__) {
    try {
        if (count($argv) === 6 && $argv[1] === 'seed') {
            echo PolylangBiographyEvidence::seedReceipt($argv[2], $argv[3], $argv[4], $argv[5]);
        } elseif (count($argv) === 6 && $argv[1] === 'capture') {
            echo json_encode(PolylangBiographyEvidence::capture($argv[2], $argv[3], $argv[4], $argv[5]), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        } elseif (count($argv) === 4 && $argv[1] === 'compare') {
            $source = json_decode(PrivateCommandOutput::readObject($argv[2], null, EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
            $target = json_decode(PrivateCommandOutput::readObject($argv[3], null, EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
            PolylangBiographyEvidence::assertRecord($source);
            PolylangBiographyEvidence::assertRecord($target);
            if ($source['native']['home'] === $target['native']['home'] || $source['compiled'] !== $target['compiled']) {
                throw new RuntimeException('Polylang biography must rebind across distinct homes without changing compiled intent');
            }
        } else {
            throw new RuntimeException('Polylang biography evidence arguments are invalid');
        }
    } catch (Throwable $error) {
        // Keep user/password/metadata bytes private even when admission fails.
        fwrite(STDERR, json_encode(['error' => get_class($error), 'message_sha256' => hash('sha256', $error->getMessage())], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
}
