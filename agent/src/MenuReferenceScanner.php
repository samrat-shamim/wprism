<?php
namespace Duo;

require_once __DIR__ . '/Pending.php';
require_once __DIR__ . '/StructuredReferenceScanner.php';
require_once __DIR__ . '/LintFinding.php';
require_once __DIR__ . '/StateTreeWalker.php';

/**
 * Manifest-aware scanning for canonical menu item references behind Lint's
 * filesystem facade.
 *
 * Menu files have one schema-owned ref field and ordinary post-meta values.
 * The policy lookup and environment resolver are injected so this scanner
 * owns only item traversal and finding semantics, without loading WordPress
 * or opening a database.
 */
final class MenuReferenceScanner {
    private const MAX_VALUE_LEN = 200;

    /**
     * @param callable(string,array):?array $metaRuleForPost
     * @param null|callable(int):?array $resolveId
     * @return list<array{class:string,path:string,locator:string,value:mixed,matches?:array,note:string}>
     */
    public static function scan(
        array $items,
        string $rel,
        callable $metaRuleForPost,
        string $home,
        string $homeEscaped,
        ?callable $resolveId = null
    ): array {
        $resolveId ??= static fn(int $id): ?array => Pending::resolve_id($id);
        $findings = [];

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue; // RepositoryCompiler owns malformed item shapes.
            }

            $type = (string) ($item['type'] ?? '');
            $ref = $item['ref'] ?? '';
            $prefix = "items[$index]";
            if ($type === 'post_type' || $type === 'taxonomy') {
                foreach (Pending::numeric_candidates($ref) as [$id, $locSuffix]) {
                    $hit = $resolveId($id);
                    $kind = $type === 'post_type' ? 'post' : 'term';
                    $findings[] = LintFinding::make(
                        'unrewritten_registered_ref',
                        $rel,
                        $prefix . '.ref' . $locSuffix,
                        $id,
                        $hit,
                        "menu item type '$type' declares its ref as a canonical $kind token, but this value is "
                            . 'still numeric in captured state — the schema-owned rewrite never ran and the id '
                            . 'is silently environment-bound.'
                    );
                }
            } elseif ($type === 'custom' && is_string($ref)) {
                // A numeric-looking custom URL is not an entity reference.
                self::flagEscapedHome($findings, $rel, $prefix . '.ref', $ref, $home, $homeEscaped);
            }

            $meta = (array) ($item['meta'] ?? []);
            foreach ($meta as $key => $value) {
                $rule = $metaRuleForPost((string) $key, $meta) ?? [];
                if (isset($rule['ref']) || !empty($rule['lint_ok'])) {
                    continue;
                }
                $locator = $prefix . '.meta.' . $key;
                if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                    foreach (StructuredReferenceScanner::scan(
                        $value,
                        $rel,
                        $locator,
                        (array) ($rule['json_refs'] ?? []),
                        isset($rule['key_refs']) ? (array) $rule['key_refs'] : null,
                        $resolveId
                    ) as $finding) {
                        $findings[] = $finding;
                    }
                    continue;
                }
                foreach (Pending::numeric_candidates($value) as [$id, $locSuffix]) {
                    $hit = $resolveId($id);
                    if ($hit !== null) {
                        $findings[] = LintFinding::make(
                            'bare_id',
                            $rel,
                            $locator . $locSuffix,
                            $id,
                            $hit,
                            self::bareIdNote($hit)
                        );
                    }
                }
            }

            StateTreeWalker::strings($meta, $prefix . '.meta', function (
                string $path,
                string $value
            ) use (&$findings, $rel, $home, $homeEscaped): void {
                self::flagEscapedHome($findings, $rel, $path, $value, $home, $homeEscaped);
            });
        }

        return $findings;
    }

    private static function bareIdNote(array $hit): string {
        return "no ref is declared for this key; the number coincides with an existing {$hit['kind']} id "
            . "(#{$hit['id']} \"{$hit['title']}\", {$hit['post_type']}) on this environment — could be a genuine "
            . 'unrewritten reference, or an unrelated small number (a count, a version, an ordering index...). '
            . 'Small ids coincide; this is a signal to investigate, not proof.';
    }

    private static function flagEscapedHome(
        array &$findings,
        string $rel,
        string $locator,
        string $value,
        string $home,
        string $homeEscaped
    ): void {
        if ($value === '' || !str_contains($value, $homeEscaped)) {
            return;
        }
        $findings[] = LintFinding::make('escaped_home', $rel, $locator, self::truncate($value), null, sprintf(
            "this environment's home URL (%s) appears in JSON-escaped form (\\/ instead of /); "
            . "Tokens::tokenize_text() only matches the plain, unescaped form (a literal str_replace()), so this "
            . "will NOT be rewritten on apply and will leak this environment's host into the target — "
            . "the escaped-slash URL leak shape of an opaque embedded JSON blob.",
            $home
        ));
    }

    private static function truncate(string $value): string {
        return strlen($value) > self::MAX_VALUE_LEN
            ? substr($value, 0, self::MAX_VALUE_LEN) . '…(truncated)'
            : $value;
    }
}
