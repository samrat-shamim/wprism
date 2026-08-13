<?php
namespace Duo;

// Direct production loads need the real collaborators, while CLI refusal
// fixtures intentionally preload Canon/Policy stubs. Preserve both closed
// load boundaries without redeclaring those test doubles.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/Canon.php';
}
if (!class_exists(Deletion::class, false)) {
    require_once __DIR__ . '/Deletion.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/Snapshot.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/Policy.php';
}

/**
 * Decodes and validates one canonical deletion intent before
 * RepositoryCompiler aggregates duplicate/live conflicts and sorts all
 * diagnostics. The injected sink preserves compiler-wide error aggregation.
 */
final class RepositoryDeletionParser {
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private Policy $policy;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(Policy $policy, \Closure $add) {
        $this->policy = $policy;
        $this->add = $add;
    }

    /** @return ?array typed deletion IR entry */
    public function parse(string $path, string $content): ?array {
        try {
            $data = Canon::decode($content);
        } catch (\Throwable $t) {
            $this->add('malformed_deletion', $path, '', $t->getMessage());
            return null;
        }
        if (!is_array($data) || array_is_list($data)) {
            $this->add('malformed_deletion', $path, '', 'deletion intent must decode to an object');
            return null;
        }
        $required = ['format', 'uuid', 'kind', 'type', 'expected_hash', 'expected_revision', 'source_path'];
        $unknown = array_values(array_diff(array_keys($data), $required));
        if ($unknown) {
            sort($unknown, SORT_STRING);
            $this->add('malformed_deletion', $path, '', 'unknown deletion field(s): ' . implode(', ', $unknown));
        }
        foreach ($required as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                $this->add('malformed_deletion', $path, $field, 'required deletion field is missing or not a non-empty string');
            }
        }
        $uuid = (string) ($data['uuid'] ?? '');
        if (($data['format'] ?? '') !== Deletion::FORMAT) {
            $this->add('malformed_deletion', $path, 'format', 'unsupported deletion intent format');
        }
        if (!preg_match(self::UUID_RE, $uuid)) {
            $this->add('invalid_uuid', $path, 'uuid', "'$uuid' is not a lowercase RFC UUID");
        }
        if (basename($path) !== $uuid . '.json') {
            $this->add('malformed_deletion', $path, 'uuid', 'deletion filename does not match its uuid');
        }
        $kind = (string) ($data['kind'] ?? '');
        $type = (string) ($data['type'] ?? '');
        if (!in_array($kind, ['post', 'term', 'menu', 'table'], true)) {
            $this->add('malformed_deletion', $path, 'kind', "unsupported deletion kind '$kind'");
        }
        foreach (['expected_hash', 'expected_revision'] as $field) {
            if (!preg_match('/^[0-9a-f]{64}$/', (string) ($data[$field] ?? ''))) {
                $this->add('malformed_deletion', $path, $field, 'field must be a lowercase SHA-256 hash');
            }
        }
        $source = (string) ($data['source_path'] ?? '');
        $sourceOk = match ($kind) {
            'post' => preg_match('#^posts/' . preg_quote($type, '#') . '/' . preg_quote($uuid, '#') . '--[^/]+\.md$#', $source),
            'term' => preg_match('#^terms/' . preg_quote($type, '#') . '/' . preg_quote($uuid, '#') . '--[^/]+\.json$#', $source),
            'menu' => $type === 'nav_menu' && preg_match('#^menus/[^/]+\.json$#', $source),
            'table' => preg_match('#^tables/' . preg_quote($type, '#') . '/' . preg_quote($uuid, '#') . '--[^/]+\.json$#', $source),
            default => false,
        };
        if (!$sourceOk) {
            $this->add('malformed_deletion', $path, 'source_path', 'source_path does not match the declared kind, type, and uuid');
        }
        if (in_array($kind, ['post', 'term', 'menu', 'table'], true) && $type !== '') {
            try {
                Deletion::capability($this->policy, $kind, $type);
            } catch (\Throwable $t) {
                $this->add('unsupported_deletion', $path, 'type', $t->getMessage());
            }
        }
        return [
            'type' => 'deletion',
            'path' => $path,
            'hash' => hash('sha256', $content),
            'source_hash' => hash('sha256', $content),
            'content' => $content,
            'data' => $data,
        ];
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
