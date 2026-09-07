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

final class PolylangLanguageDeletionEvidence {
    public static function native(string $stem, string $pair, int $exit = 0, bool $opaque = false): string {
        if (preg_match('/\A[a-z][a-z0-9]{2,23}\z/', $pair) !== 1) throw new RuntimeException('Polylang deletion pair identity is invalid');
        $prelude = '/\A Container wprism-' . preg_quote($pair, '/') . '-cli1-run-[a-f0-9]{12} (?:Creating|Created) \z/';
        return $opaque
            ? PrivateCommandOutput::readBytes($stem, $prelude, EvidenceSizeProfile::CONFORMANCE_TREE, $exit)
            : PrivateCommandOutput::readObject($stem, $prelude, EvidenceSizeProfile::CONFORMANCE_TREE, $exit);
    }

    public static function assertControl(array $record, string $mode): void {
        if (!in_array($mode, ['add', 'delete'], true) || self::keys($record) !== ['default_lang', 'format', 'identities', 'languages', 'mode']
            || $record['format'] !== 'polylang-language-deletion-control/v1' || $record['mode'] !== $mode
            || $record['languages'] !== ($mode === 'add' ? ['ar', 'en', 'fr', 'nl'] : ['ar', 'en', 'fr'])
            || !in_array($record['default_lang'], ['ar', 'en', 'fr'], true) || !is_array($record['identities'])
            || array_keys($record['identities']) !== ['language', 'term_language']) {
            throw new RuntimeException('Polylang native language control lacks its complete declared premises');
        }
        $ids = $uuids = [];
        foreach ($record['identities'] as $identity) {
            if (!is_array($identity) || self::keys($identity) !== ['term_id', 'uuid'] || !is_int($identity['term_id']) || $identity['term_id'] < 1
                || ($mode === 'add' ? $identity['uuid'] !== null : (!is_string($identity['uuid']) || !\WPrism\RepositoryIdentityRegistry::is_uuid($identity['uuid'])))) {
                throw new RuntimeException('Polylang native language control has an incomplete identity');
            }
            $ids[] = $identity['term_id'];
            $uuids[] = $identity['uuid'];
        }
        if (count(array_unique($ids)) !== 2 || ($mode === 'delete' && count(array_unique($uuids)) !== 2)) {
            throw new RuntimeException('Polylang native language identities are not distinct');
        }
    }

    public static function selector(array $control): string {
        self::assertControl($control, 'delete');
        $missing = [];
        foreach ($control['identities'] as $taxonomy => $identity) $missing[$identity['uuid']] = 'term:' . $taxonomy;
        // RepositoryCompiler.php:433 orders its complete tree by UUID before
        // Deletion::capture_tombstones traverses it. Bind before the command,
        // never choose an expected cause from the command's observed failure.
        ksort($missing, SORT_STRING);
        return reset($missing);
    }

    public static function snapshot(string $repo, array $control): array {
        self::assertControl($control, 'delete');
        $trees = [];
        foreach (['state', 'media', 'site.wprism.json'] as $boundary) {
            $trees[$boundary] = FilesystemTreeEvidence::capture($repo, $boundary, EvidenceSizeProfile::CONFORMANCE_TREE);
        }
        $policy = \WPrism\Policy::load($repo, adapterLibrary: \WPrism\AdapterLibrary::fromSourceTree(dirname(__DIR__, 3)));
        $compiled = \WPrism\RepositoryCompiler::compile($repo, $policy);
        foreach ($control['identities'] as $taxonomy => $identity) {
            $entity = $compiled->tree()[$identity['uuid']] ?? null;
            if (!is_array($entity) || $entity['type'] !== 'term' || $entity['data']['taxonomy'] !== $taxonomy
                || $entity['data']['slug'] !== ($taxonomy === 'language' ? 'nl' : 'pll_nl')) {
                throw new RuntimeException('Polylang deletion lacks its previously captured compiled native identity');
            }
        }
        foreach ($trees as $boundary => $tree) {
            if (FilesystemTreeEvidence::capture($repo, $boundary, EvidenceSizeProfile::CONFORMANCE_TREE) !== $tree) {
                throw new RuntimeException('Polylang deletion premise compilation changed its repository');
            }
        }
        return ['format' => 'polylang-language-deletion-preservation/v1', 'control' => $control, 'trees' => $trees];
    }

    public static function readSnapshot(string $stem): array {
        $record = json_decode(PrivateCommandOutput::readObject($stem, profile: EvidenceSizeProfile::CONFORMANCE_TREE), true, 32, JSON_THROW_ON_ERROR);
        if (self::keys($record) !== ['control', 'format', 'trees'] || $record['format'] !== 'polylang-language-deletion-preservation/v1'
            || !is_array($record['trees']) || array_keys($record['trees']) !== ['state', 'media', 'site.wprism.json']) {
            throw new RuntimeException('Polylang deletion retained repository envelope is malformed');
        }
        self::assertControl($record['control'], 'delete');
        foreach ($record['trees'] as $boundary => $tree) FilesystemTreeEvidence::assertRecord($tree, $boundary, EvidenceSizeProfile::CONFORMANCE_TREE);
        foreach ($record['control']['identities'] as $taxonomy => $identity) {
            $matches = [];
            foreach ($record['trees']['state']['files'] as $file) {
                if (!str_starts_with($file['path'], 'terms/' . $taxonomy . '/') || !str_ends_with($file['path'], '.json')) continue;
                $term = json_decode(base64_decode($file['contents_base64'], true), true, 32, JSON_THROW_ON_ERROR);
                if (($term['uuid'] ?? null) === $identity['uuid']) $matches[] = $term;
            }
            if (count($matches) !== 1 || ($matches[0]['taxonomy'] ?? null) !== $taxonomy
                || ($matches[0]['slug'] ?? null) !== ($taxonomy === 'language' ? 'nl' : 'pll_nl')) {
                throw new RuntimeException('Polylang deletion retained state lacks its exact captured identity');
            }
        }
        return $record;
    }

    public static function assertPublic(array $record, string $selector): void {
        if (!in_array($selector, ['term:language', 'term:term_language'], true)) throw new RuntimeException('Polylang deletion selector is unsupported');
        $expected = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'capture',
            'error' => 'unsupported_deletion', 'reason_code' => 'unsupported_deletion',
            'message' => "deletion intent for $selector is unsupported because no pinned adapter owns its destructive semantics",
            'remediation' => 'restore the missing source entity, or pin a compatible adapter that declares the required reverse-reference guards and cascade effects before trying again',
            'diagnostics' => [['code' => 'unsupported_deletion', 'surface' => $selector,
                'message' => 'no pinned adapter declares this deletion selector',
                'remediation' => 'restore the missing source entity or pin a compatible adapter with complete deletion guards and cascade effects']]];
        if (Canon::encode($record) !== Canon::encode($expected)) throw new RuntimeException('Polylang deletion lacks its exact public capability refusal');
    }

    public static function database(string $sink, string $stage, string $pair, array $control): array {
        self::assertControl($control, 'delete');
        $roster = self::native("$sink/$stage-tables", $pair, opaque: true);
        $tables = SqlDumpEvidence::tables($roster);
        $dump = self::native("$sink/$stage-database", $pair, opaque: true);
        SqlDumpEvidence::assertComplete($dump, $tables, ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_terms',
            'wp_term_taxonomy', 'wp_term_relationships', 'wp_termmeta', 'wp_users', 'wp_usermeta', 'wp_wprism_map', 'wp_wprism_state']);
        foreach ($control['identities'] as $identity) {
            if (preg_match('/^INSERT INTO `wp_wprism_map` \([^\n]+\) VALUES \([^\n]*\x27' . preg_quote($identity['uuid'], '/') . '\x27[^\n]*\);$/m', $dump) !== 1) {
                throw new RuntimeException('Polylang deletion lacks its retained stale ledger identity premise');
            }
        }
        return [$roster, $dump];
    }

    public static function verify(string $sink, string $pair): void {
        $before = self::readSnapshot($sink . '/before');
        if (self::readSnapshot($sink . '/after') !== $before) throw new RuntimeException('Polylang deletion refusal changed complete state, media or policy bytes');
        if (self::database($sink, 'before', $pair, $before['control']) !== self::database($sink, 'after', $pair, $before['control'])) {
            throw new RuntimeException('Polylang deletion refusal changed the complete native database dump');
        }
        $selector = self::selector($before['control']);
        self::assertPublic(json_decode(self::native($sink . '/command', $pair, 1), true, 32, JSON_THROW_ON_ERROR), $selector);
        PrivateRefusalReceipt::verifyDiagnostic(json_decode(self::native($sink . '/private', $pair), true, 32, JSON_THROW_ON_ERROR),
            ['command' => 'capture', 'reason_code' => 'unsupported_deletion', 'nodes' => [[
                'parent_index' => null, 'relation' => 'root', 'class' => \WPrism\CommandRefusalException::class,
                'message' => "wprism: deletion intent for $selector is unsupported — no pinned adapter declares its reverse-reference checks and cascade effects",
            ]]]);
    }

    private static function keys(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
    }
}

if (isset($argv) && realpath($argv[0]) === __FILE__) {
    try {
        if (count($argv) === 5 && $argv[1] === 'control') {
            PolylangLanguageDeletionEvidence::assertControl(json_decode(PolylangLanguageDeletionEvidence::native($argv[2], $argv[3]), true, 32, JSON_THROW_ON_ERROR), $argv[4]);
        } elseif (count($argv) === 4 && $argv[1] === 'capture') {
            $capture = json_decode(PolylangLanguageDeletionEvidence::native($argv[2], $argv[3]), true, 32, JSON_THROW_ON_ERROR);
            if (($capture['warnings'] ?? null) !== [] || ($capture['counts']['deletion'] ?? null) !== 0
                || ($capture['counts']['term'] ?? 0) < 8 || ($capture['counts']['post'] ?? 0) < 1
                || ($capture['state_dir'] ?? null) !== '/siterepo/state') throw new RuntimeException('Polylang unused-language baseline Capture did not succeed cleanly');
        } elseif (count($argv) === 5 && $argv[1] === 'snapshot') {
            $control = json_decode(PolylangLanguageDeletionEvidence::native($argv[3], $argv[4]), true, 32, JSON_THROW_ON_ERROR);
            echo json_encode(PolylangLanguageDeletionEvidence::snapshot($argv[2], $control), JSON_THROW_ON_ERROR), "\n";
        } elseif (count($argv) === 4 && $argv[1] === 'admit') {
            $before = PolylangLanguageDeletionEvidence::readSnapshot($argv[2] . '/before');
            PolylangLanguageDeletionEvidence::database($argv[2], 'before', $argv[3], $before['control']);
        } elseif (count($argv) === 4 && $argv[1] === 'verify') {
            PolylangLanguageDeletionEvidence::verify($argv[2], $argv[3]);
        } else throw new RuntimeException('Polylang language deletion evidence arguments are invalid');
    } catch (Throwable $error) {
        fwrite(STDERR, json_encode(['error' => get_class($error), 'message_sha256' => hash('sha256', $error->getMessage())], JSON_THROW_ON_ERROR) . "\n");
        exit(1);
    }
}
