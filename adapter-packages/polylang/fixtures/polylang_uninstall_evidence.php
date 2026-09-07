<?php
declare(strict_types=1);

$root = dirname(__DIR__, 3);
require_once $root . '/sandbox/tests/lib/FilesystemTreeEvidence.php';
require_once $root . '/sandbox/tests/lib/PrivateCommandOutput.php';
require_once $root . '/sandbox/tests/lib/SqlDumpEvidence.php';
require_once $root . '/sandbox/tests/lib/PrivateRefusalReceipt.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Code/Code.php';
require_once $root . '/agent/src/Code/CodeStateContract.php';
require_once $root . '/agent/src/Repository/RepositoryAuthorization.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrismTest\EvidenceSizeProfile;
use WPrismTest\FilesystemTreeEvidence;
use WPrismTest\PrivateCommandOutput;
use WPrismTest\PrivateRefusalReceipt;
use WPrismTest\SqlDumpEvidence;

/** Native uninstall removes backing data, not the retained canonical identity. */
final class PolylangUninstallEvidence {
    public static function native(string $stem, string $pair, int $exit = 0, bool $opaque = false): string {
        if (preg_match('/\A[a-z][a-z0-9]{2,23}\z/', $pair) !== 1) throw new RuntimeException('Polylang uninstall pair identity is invalid');
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli2-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        return $opaque
            ? PrivateCommandOutput::readBytes($stem, $prelude, EvidenceSizeProfile::CONFORMANCE_TREE, $exit)
            : PrivateCommandOutput::readObject($stem, $prelude, EvidenceSizeProfile::CONFORMANCE_TREE, $exit);
    }

    public static function snapshot(string $repo): array {
        $trees = [];
        foreach (['state', 'media', 'site.wprism.json'] as $boundary) {
            $trees[$boundary] = FilesystemTreeEvidence::capture($repo, $boundary, EvidenceSizeProfile::CONFORMANCE_TREE);
        }
        $policy = \WPrism\Policy::load($repo, adapterLibrary: \WPrism\AdapterLibrary::fromSourceTree(dirname(__DIR__, 3)));
        $compiled = \WPrism\RepositoryCompiler::compile($repo, $policy);
        $widgets = [];
        foreach ($compiled->tree() as $entity) {
            if ($entity['type'] !== 'sidebar') continue;
            foreach ($entity['data']['widgets'] as $widget) {
                if ($widget['type'] === 'polylang') $widgets[] = $widget['uuid'];
            }
        }
        sort($widgets, SORT_STRING);
        foreach ($trees as $boundary => $tree) {
            if (FilesystemTreeEvidence::capture($repo, $boundary, EvidenceSizeProfile::CONFORMANCE_TREE) !== $tree) {
                throw new RuntimeException('Polylang uninstall premise compilation changed its repository');
            }
        }
        $record = ['format' => 'polylang-uninstall-preservation/v1', 'widgets' => $widgets, 'trees' => $trees];
        self::assertSnapshot($record);
        return $record;
    }

    public static function assertSnapshot(array $record): void {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        if ($keys !== ['format', 'trees', 'widgets'] || $record['format'] !== 'polylang-uninstall-preservation/v1'
            || !is_array($record['trees']) || array_keys($record['trees']) !== ['state', 'media', 'site.wprism.json']
            || !is_array($record['widgets']) || !array_is_list($record['widgets']) || $record['widgets'] === []) {
            throw new RuntimeException('Polylang uninstall lacks its complete repository and canonical widget premises');
        }
        foreach ($record['trees'] as $boundary => $tree) FilesystemTreeEvidence::assertRecord($tree, $boundary, EvidenceSizeProfile::CONFORMANCE_TREE);
        $widgets = [];
        foreach ($record['trees']['state']['files'] as $file) {
            if (!str_starts_with($file['path'], 'sidebars/') || !str_ends_with($file['path'], '.json')) continue;
            $sidebar = json_decode(base64_decode($file['contents_base64'], true), true, 32, JSON_THROW_ON_ERROR);
            foreach (($sidebar['widgets'] ?? []) as $widget) {
                if (($widget['type'] ?? null) === 'polylang') {
                    if (!is_string($widget['uuid'] ?? null) || !\WPrism\RepositoryIdentityRegistry::is_uuid($widget['uuid'])) {
                        throw new RuntimeException('Polylang uninstall canonical widget identity is malformed');
                    }
                    $widgets[] = $widget['uuid'];
                }
            }
        }
        sort($widgets, SORT_STRING);
        if ($widgets !== $record['widgets'] || count(array_unique($widgets)) !== count($widgets)) {
            throw new RuntimeException('Polylang uninstall retained widget identities do not match complete canonical bytes');
        }
    }

    public static function readSnapshot(string $stem): array {
        $record = json_decode(PrivateCommandOutput::readObject($stem, profile: EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
        self::assertSnapshot($record);
        return $record;
    }

    public static function database(string $sink, string $stage, string $pair, array $snapshot): array {
        self::assertSnapshot($snapshot);
        $roster = self::native("$sink/$stage-tables", $pair, opaque: true);
        $dump = self::native("$sink/$stage-database", $pair, opaque: true);
        SqlDumpEvidence::assertComplete($dump, SqlDumpEvidence::tables($roster),
            ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta', 'wp_wprism_map', 'wp_wprism_state']);
        // The engine's four-column ledger schema and complete-insert producer
        // bind each captured widget, not any row that happens to contain its UUID.
        foreach ($snapshot['widgets'] as $uuid) {
            $pattern = '/^INSERT INTO `wp_wprism_map` \(`uuid`, `entity_type`, `id_kind`, `local_id`\) VALUES '
                . '\(\x27' . preg_quote($uuid, '/') . '\x27,\x27widget\x27,\x27widget_polylang\x27,[1-9][0-9]*\);$/m';
            if (preg_match_all($pattern, $dump) !== 1) throw new RuntimeException('Polylang uninstall lacks its exact retained canonical widget map');
        }
        return [$roster, $dump];
    }

    public static function assertPublic(array $record): void {
        $expected = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'plan',
            'error' => 'canonical_identity_recovery_required', 'reason_code' => 'canonical_identity_recovery_required',
            'message' => 'canonical mapped identity has no matching live backing row; plan/apply was refused before creating or rebinding it',
            'remediation' => 'restore the database-matched backup or capture the intended deletion before retrying plan/apply'];
        if (Canon::encode($record) !== Canon::encode($expected)) throw new RuntimeException('Polylang uninstall lacks its exact public identity recovery refusal');
    }

    public static function profile(): array {
        return ['command' => 'plan', 'reason_code' => 'canonical_identity_recovery_required', 'nodes' => [[
            'parent_index' => null, 'relation' => 'root', 'class' => \WPrism\CommandRefusalException::class,
            'message' => 'wprism: canonical mapped identity has no matching live backing row; refusing to create or rebind it. Restore the database-matched backup or capture the intended deletion before plan/apply.',
        ], [
            'parent_index' => 0, 'relation' => 'previous', 'class' => \WPrism\CommandRefusalException::class,
            'message' => 'wprism: scoped target selected ledger identities are not backed by the exact strict target observation',
        ]]];
    }

    public static function verify(string $sink, string $pair): void {
        $before = self::readSnapshot("$sink/before");
        if (self::readSnapshot("$sink/after") !== $before) throw new RuntimeException('Polylang uninstall refusal changed complete state, media or policy bytes');
        if (self::database($sink, 'before', $pair, $before) !== self::database($sink, 'after', $pair, $before)) {
            throw new RuntimeException('Polylang uninstall refusal changed complete database bytes');
        }
        self::assertPublic(json_decode(self::native("$sink/command", $pair, 1), true, 32, JSON_THROW_ON_ERROR));
        PrivateRefusalReceipt::verifyDiagnostic(json_decode(self::native("$sink/private", $pair), true, 32, JSON_THROW_ON_ERROR), self::profile());
    }
}

if (isset($argv) && realpath($argv[0]) === __FILE__) {
    try {
        if (count($argv) === 3 && $argv[1] === 'snapshot') {
            echo json_encode(PolylangUninstallEvidence::snapshot($argv[2]), JSON_THROW_ON_ERROR), "\n";
        } elseif (count($argv) === 4 && $argv[1] === 'admit') {
            PolylangUninstallEvidence::database($argv[2], 'before', $argv[3], PolylangUninstallEvidence::readSnapshot($argv[2] . '/before'));
        } elseif (count($argv) === 4 && $argv[1] === 'verify') {
            PolylangUninstallEvidence::verify($argv[2], $argv[3]);
        } else throw new RuntimeException('Polylang uninstall evidence arguments are invalid');
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode(['error' => get_class($error), 'message_sha256' => hash('sha256', $error->getMessage())], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
}
