<?php
declare(strict_types=1);

namespace Duo {
    final class Canon {
        /** @return array<string,mixed> */
        public static function decode(string $content): array {
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('fixture canonical decode failed');
            }
            return $decoded;
        }
    }

    final class Policy {
        /** @var array<string,array<string,mixed>> */
        public array $capabilities = [];
        /** @var array<string,array<string,mixed>> */
        public array $tables = [];

        /** @return ?array<string,mixed> */
        public function deletion_capability(string $selector): ?array {
            return $this->capabilities[$selector] ?? null;
        }

        /** @return array<string,array<string,mixed>> */
        public function declared_tables(): array {
            return $this->tables;
        }
    }
}

namespace {
    require_once __DIR__ . '/../../agent/src/RepositoryDeletionParser.php';

    use Duo\Policy;
    use Duo\RepositoryDeletionParser;

    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$ok) {
            $failures[] = $message;
        }
    };
    $uuid = '11111111-1111-4111-8111-111111111111';
    $hash = str_repeat('a', 64);
    $validatorPath = realpath(__DIR__ . '/../../agent/src/RepositoryDeletionParser.php');
    $probe = proc_open(
        [PHP_BINARY, '-r', 'require $argv[1]; echo class_exists(\\Duo\\Policy::class, false) && class_exists(\\Duo\\Deletion::class, false) && !class_exists(\\Duo\\RepositoryCompiler::class, false) ? "loaded\\n" : "broken\\n";', (string) $validatorPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $probeOut = is_resource($probe) ? stream_get_contents($pipes[1]) : '';
    $probeErr = is_resource($probe) ? stream_get_contents($pipes[2]) : '';
    if (is_resource($probe)) {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $probeExit = proc_close($probe);
    } else {
        $probeExit = 1;
    }
    $check($probeExit === 0 && $probeOut === "loaded\n" && $probeErr === '', 'normal direct loading closes deletion dependencies without loading RepositoryCompiler');
    $check(!class_exists(\Duo\RepositoryCompiler::class, false), 'fake Canon/Policy direct loading does not pull RepositoryCompiler');

    $policy = new Policy();
    $policy->capabilities = [
        'post:page' => ['cascades' => ['postmeta', 'post_revisions', 'term_relationships']],
        'term:category' => ['cascades' => ['termmeta', 'term_taxonomy', 'term_relationships']],
        'menu:nav_menu' => ['cascades' => ['termmeta', 'term_taxonomy', 'term_relationships', 'menu_items']],
        'table:duo_rows' => ['cascades' => []],
    ];
    $diagnostics = [];
    $parser = new RepositoryDeletionParser(
        $policy,
        static function (string $code, string $path, string $locator, string $message, ?string $relatedPath = null) use (&$diagnostics): void {
            $diagnostics[] = compact('code', 'path', 'locator', 'message', 'relatedPath');
        }
    );
    $reset = static function () use (&$diagnostics): void { $diagnostics = []; };
    $record = static function (string $kind, string $type, string $source) use ($uuid, $hash): array {
        return [
            'format' => 'duo-deletion/v1',
            'uuid' => $uuid,
            'kind' => $kind,
            'type' => $type,
            'expected_hash' => $hash,
            'expected_revision' => $hash,
            'source_path' => $source,
        ];
    };
    foreach ([
        ['post', 'page', "posts/page/$uuid--hello.md"],
        ['term', 'category', "terms/category/$uuid--news.json"],
        ['menu', 'nav_menu', 'menus/primary.json'],
        ['table', 'duo_rows', "tables/duo_rows/$uuid--one.json"],
    ] as [$kind, $type, $source]) {
        $reset();
        $content = json_encode($record($kind, $type, $source), JSON_THROW_ON_ERROR);
        $entry = $parser->parse("deletions/$uuid.json", $content);
        $check($entry !== null && $entry['type'] === 'deletion' && $entry['data']['source_path'] === $source && $diagnostics === [], "$kind tombstone parses into canonical deletion IR without compiler state");
    }

    $reset();
    $bad = $record('not-a-kind', 'page', 'wrong');
    $bad['format'] = 'duo-deletion/v0';
    $bad['uuid'] = 'not-a-uuid';
    $bad['expected_hash'] = 'wrong';
    unset($bad['expected_revision']);
    $bad['unexpected'] = true;
    $entry = $parser->parse('deletions/not-a-uuid.json', json_encode($bad, JSON_THROW_ON_ERROR));
    $check($entry !== null && array_map(static fn(array $d): array => [$d['code'], $d['locator']], $diagnostics) === [
        ['malformed_deletion', ''],
        ['malformed_deletion', 'expected_revision'],
        ['malformed_deletion', 'format'],
        ['invalid_uuid', 'uuid'],
        ['malformed_deletion', 'kind'],
        ['malformed_deletion', 'expected_hash'],
        ['malformed_deletion', 'expected_revision'],
        ['malformed_deletion', 'source_path'],
    ], 'malformed deletion findings retain the historical sink order and locators');

    $reset();
    $parser->parse("deletions/$uuid.json", '{');
    $check(count($diagnostics) === 1 && $diagnostics[0]['code'] === 'malformed_deletion' && $diagnostics[0]['locator'] === '', 'canonical decode failures remain one structured malformed-deletion diagnostic');

    $reset();
    $unsupported = $record('post', 'unsupported_type', "posts/unsupported_type/$uuid--one.md");
    $parser->parse("deletions/$uuid.json", json_encode($unsupported, JSON_THROW_ON_ERROR));
    $check(count($diagnostics) === 1 && $diagnostics[0]['code'] === 'unsupported_deletion' && $diagnostics[0]['locator'] === 'type', 'unsupported destructive capability remains a parser diagnostic rather than a compiler exception');

    $compiler = (string) file_get_contents(__DIR__ . '/../../agent/src/RepositoryCompiler.php');
    $check(
        substr_count($compiler, 'new RepositoryDeletionParser(') === 1
        && substr_count($compiler, '->deletionParser->parse(') === 1
        && !str_contains($compiler, 'private function parse_deletion('),
        'RepositoryCompiler delegates the moved tombstone grammar without retaining a private duplicate'
    );
    if ($failures !== []) {
        fwrite(STDERR, "FAILED\n");
        exit(1);
    }
    echo "ALL PASSED\n";
}
