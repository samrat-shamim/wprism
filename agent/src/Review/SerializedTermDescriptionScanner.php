<?php
namespace Duo;

require_once __DIR__ . '/Pending.php';
require_once __DIR__ . '/LintFinding.php';

/**
 * Generic lint detector for serialized term descriptions with no declared
 * description_refs route. The facade decides that policy gate; this scanner
 * only preserves the historical safe PHP-serialization parse, scalar/array
 * candidate ordering, and resolved-identity finding shape. A resolver can be
 * supplied for offline characterization without database contact.
 */
final class SerializedTermDescriptionScanner {
    /**
     * @param null|callable(int):?array $resolveId
     * @return list<array{class:string,path:string,locator:string,value:mixed,matches?:array,note:string}>
     */
    public static function scan(
        string $description,
        string $taxonomy,
        string $rel,
        ?callable $resolveId = null
    ): array {
        $resolveId ??= static fn(int $id): ?array => Pending::resolve_id($id);
        $data = @unserialize($description, ['allowed_classes' => false]);
        if ($data === false && $description !== 'b:0;') {
            return [];
        }

        $findings = [];
        $items = is_array($data) ? $data : [$data];
        foreach ($items as $key => $value) {
            if (!is_numeric($value) || str_contains((string) $value, '.')) {
                continue;
            }
            $id = (int) $value;
            $hit = $resolveId($id);
            if ($hit === null) {
                continue;
            }
            $locator = is_array($data) ? ('description[' . $key . ']') : 'description';
            $findings[] = LintFinding::make('serialized_desc_ids', $rel, $locator, $id, $hit, sprintf(
                "this term's description unserializes to PHP data containing an integer that matches an "
                . "existing %s id (#%d \"%s\", %s); taxonomy '%s' has no 'description_refs' declaration, so "
                . "nothing rewrites this term's description (Capture tokenize_text()'s it as an opaque string) "
                . 'and this id is silently environment-bound — a serialized map of entity ids stored in a '
                . "term's description, before a description_refs declaration covers it.",
                $hit['kind'], $hit['id'], $hit['title'], $hit['post_type'], $taxonomy
            ));
        }
        return $findings;
    }
}
