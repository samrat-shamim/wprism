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
require_once $root . '/agent/src/Kernel/PlainData.php';
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
        if (self::removed($record) !== self::removedFromTree($compiled->tree())) {
            throw new RuntimeException('Polylang uninstall retained identities disagree with genuine compiler order');
        }
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

    /** Re-derive from complete retained bytes, never from the observed failure. */
    public static function removed(array $snapshot): array {
        self::assertSnapshot($snapshot);
        $tree = [];
        foreach ($snapshot['trees']['state']['files'] as $file) {
            $path = $file['path'];
            if (!str_ends_with($path, '.json')) continue;
            $type = str_starts_with($path, 'terms/') ? 'term'
                : (str_starts_with($path, 'menus/') ? 'menu' : (str_starts_with($path, 'sidebars/') ? 'sidebar' : null));
            if ($type === null) continue;
            $data = json_decode(base64_decode($file['contents_base64'], true), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) throw new RuntimeException('Polylang uninstall retained entity is not a canonical object');
            $identity = $type === 'sidebar' ? 'sidebar/' . substr($path, strlen('sidebars/'), -5) : ($data['uuid'] ?? null);
            if (!is_string($identity) || isset($tree[$identity])
                || ($type !== 'sidebar' && !\WPrism\RepositoryIdentityRegistry::is_uuid($identity))) {
                throw new RuntimeException('Polylang uninstall retained canonical identity is malformed or duplicate');
            }
            $tree[$identity] = ['type' => $type, 'data' => $data];
        }
        // RepositoryCompiler sorts top-level identities; the guard visits a
        // menu's items inline, not in a global sort of all removed UUIDs.
        ksort($tree, SORT_STRING);
        return self::removedFromTree($tree);
    }

    /** Polylang 3.8.6 uninstall.php owns exactly these captured backing families. */
    private static function removedFromTree(array $tree): array {
        $removed = [];
        foreach ($tree as $identity => $entity) {
            $data = $entity['data'];
            if ($entity['type'] === 'term' && in_array($data['taxonomy'] ?? null,
                ['language', 'term_language', 'post_translations', 'term_translations'], true)) {
                $removed[] = ['uuid' => $identity, 'kind' => 'term', 'family' => $data['taxonomy']];
            } elseif ($entity['type'] === 'menu') {
                foreach ($data['items'] ?? [] as $item) {
                    if (array_key_exists('_pll_menu_item', $item['meta'] ?? [])) {
                        $removed[] = ['uuid' => $item['uuid'] ?? null, 'kind' => 'post', 'family' => 'menu_item', 'owner' => $identity];
                    }
                }
            } elseif ($entity['type'] === 'sidebar') {
                foreach ($data['widgets'] ?? [] as $widget) {
                    if (($widget['type'] ?? null) === 'polylang') {
                        $removed[] = ['uuid' => $widget['uuid'] ?? null, 'kind' => 'widget_polylang', 'family' => 'widget'];
                    }
                }
            }
        }
        $seen = [];
        foreach ($removed as $identity) {
            if (!is_string($identity['uuid']) || !\WPrism\RepositoryIdentityRegistry::is_uuid($identity['uuid']) || isset($seen[$identity['uuid']])) {
                throw new RuntimeException('Polylang uninstall removed identity is malformed or duplicate');
            }
            $seen[$identity['uuid']] = true;
        }
        if ($removed === []) throw new RuntimeException('Polylang uninstall has no captured owned identity');
        return $removed;
    }

    public static function database(string $sink, string $stage, string $pair, array $snapshot): array {
        self::assertSnapshot($snapshot);
        $roster = self::native("$sink/$stage-tables", $pair, opaque: true);
        $dump = self::native("$sink/$stage-database", $pair, opaque: true);
        SqlDumpEvidence::assertComplete($dump, SqlDumpEvidence::tables($roster),
            ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta', 'wp_wprism_map', 'wp_wprism_state']);
        $removed = self::removed($snapshot);
        $wanted = array_fill_keys(array_column($removed, 'uuid'), true);
        $maps = [];
        foreach (SqlDumpEvidence::projectColumns($dump, 'wp_wprism_map', ['uuid', 'entity_type', 'id_kind', 'local_id']) as $row) {
            if (!is_string($row['uuid']) || !isset($wanted[$row['uuid']])) continue;
            if (!is_string($row['id_kind']) || !is_int($row['local_id']) || $row['local_id'] <= 0 || isset($maps[$row['uuid']][$row['id_kind']])) {
                throw new RuntimeException('Polylang uninstall canonical map is invalid or duplicated');
            }
            $maps[$row['uuid']][$row['id_kind']] = $row;
        }
        $backing = [];
        foreach (['post' => ['wp_posts', 'ID'], 'term' => ['wp_terms', 'term_id'], 'term_taxonomy' => ['wp_term_taxonomy', 'term_taxonomy_id']] as $kind => [$table, $column]) {
            $backing[$kind] = [];
            foreach (SqlDumpEvidence::projectColumns($dump, $table, [$column]) as $row) {
                $id = $row[$column];
                if (!is_int($id) || $id <= 0 || isset($backing[$kind][$id])) throw new RuntimeException('Polylang uninstall native backing identity is invalid or duplicate');
                $backing[$kind][$id] = true;
            }
        }
        $backing['widget_polylang'] = [];
        $widgetOption = false;
        foreach (SqlDumpEvidence::projectColumns($dump, 'wp_options', ['option_name', 'option_value']) as $row) {
            if ($row['option_name'] !== 'widget_polylang') continue;
            if ($widgetOption || !is_string($row['option_value'])) throw new RuntimeException('Polylang uninstall widget option is duplicate or not observable');
            $widgetOption = true;
            // Reinstall may recreate the marker-only option. Its existence
            // is not evidence that any mapped multiwidget instance survived.
            $instances = \WPrism\PlainData::decode_serialized($row['option_value'], 'Polylang uninstall widget option');
            if (!is_array($instances)) throw new RuntimeException('Polylang uninstall widget storage is not a native instance array');
            foreach ($instances as $id => $settings) {
                if ($id === '_multiwidget' && in_array($settings, [1, '1'], true)) continue;
                if (!is_int($id) || $id <= 0 || !is_array($settings)) throw new RuntimeException('Polylang uninstall widget instance identity is malformed');
                $backing['widget_polylang'][$id] = true;
            }
        }
        foreach ($removed as $identity) {
            $kinds = $identity['kind'] === 'term' ? ['term', 'term_taxonomy'] : [$identity['kind']];
            $rows = $maps[$identity['uuid']] ?? [];
            $type = $identity['family'] === 'widget' ? 'widget' : ($identity['family'] === 'menu_item' ? 'menu_item' : 'term');
            if (count($rows) !== count($kinds)) throw new RuntimeException('Polylang uninstall lacks a complete exact canonical map tuple');
            foreach ($kinds as $kind) {
                $row = $rows[$kind] ?? null;
                if ($row === null || $row['entity_type'] !== $type || isset($backing[$kind][$row['local_id']])) {
                    throw new RuntimeException('Polylang uninstall identity lacks its exact retained map and removed backing premise');
                }
            }
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

    public static function profile(array $snapshot): array {
        $first = self::removed($snapshot)[0]['kind'];
        return ['command' => 'plan', 'reason_code' => 'canonical_identity_recovery_required', 'nodes' => [[
            'parent_index' => null, 'relation' => 'root', 'class' => \WPrism\CommandRefusalException::class,
            'message' => 'wprism: canonical mapped identity has no matching live backing row; refusing to create or rebind it. Restore the database-matched backup or capture the intended deletion before plan/apply.',
        ], [
            'parent_index' => 0, 'relation' => 'previous',
            'class' => $first === 'widget_polylang' ? \WPrism\CommandRefusalException::class : RuntimeException::class,
            'message' => match ($first) {
                'post' => 'wprism: canonical post map witness does not match its live backing row',
                'term' => 'wprism: canonical term map witness does not match its live backing row',
                'widget_polylang' => 'wprism: scoped target selected ledger identities are not backed by the exact strict target observation',
            },
        ]]];
    }

    public static function verify(string $sink, string $pair): void {
        $before = self::readSnapshot("$sink/before");
        if (self::readSnapshot("$sink/after") !== $before) throw new RuntimeException('Polylang uninstall refusal changed complete state, media or policy bytes');
        if (self::database($sink, 'before', $pair, $before) !== self::database($sink, 'after', $pair, $before)) {
            throw new RuntimeException('Polylang uninstall refusal changed complete database bytes');
        }
        self::assertPublic(json_decode(self::native("$sink/command", $pair, 1), true, 32, JSON_THROW_ON_ERROR));
        PrivateRefusalReceipt::verifyDiagnostic(json_decode(self::native("$sink/private", $pair), true, 32, JSON_THROW_ON_ERROR), self::profile($before));
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
