<?php
namespace WPrism;

/**
 * Every declared outbound reference edge in one compiled canonical tree,
 * enumerated exactly once.
 *
 * The compiler already had to walk this graph to prove that no reference
 * dangles; scope resolution has to walk the same graph to prove that no
 * dependency gets left behind. Two independent walkers would be a
 * correctness trap rather than a duplication nuisance: on the day an
 * adapter declares a reference shape the second walker has never heard of,
 * neither walker fails loudly — the validator quietly stops guarding an
 * edge, or a resolved scope quietly ships without one of its dependencies.
 * That is precisely the failure issue #3344 exists to make impossible, so the
 * enumeration lives here, once, and both callers consume it.
 *
 * Nothing here reads WordPress or the ledger. Canonical state is uuid-keyed
 * end to end, so the whole graph is a pure function of the tree plus the
 * pinned policy — see RepositoryCompiler's own docblock: the IR is complete
 * before Tokens, Ledger, or Capture can be constructed.
 *
 * An edge is a claim, not a validated fact. `check` records which proof the
 * edge still owes, because the compiler's diagnostics remain the single
 * place a malformed or dangling reference is reported; this class never
 * decides that a repository is invalid.
 */
final class ReferenceGraph {
    /** Reference found as a canonical {{kind:uuid}} token; compiler owes it validate_token(). */
    public const CHECK_TOKEN = 'token';
    /** Bare uuid in a declared structural field; compiler owes it validate_raw_ref() against `expects`. */
    public const CHECK_RAW = 'raw';
    /** Structural restatement of an edge some other check already proved; validating twice would double-report. */
    public const CHECK_NONE = 'none';

    /** A reference from one entity's content or declared rule. */
    public const REL_REFERENCE = 'reference';
    /** A hierarchical parent: post_parent, term parent, or menu-item parent. */
    public const REL_PARENT = 'parent';
    /** A post's assignment to a term. */
    public const REL_TERM = 'term';
    /** A term's declared relationship to another term (issue #3316 object keyspaces). */
    public const REL_RELATIONSHIP = 'relationship';

    /**
     * Reference kind => the canonical entity types a target of that kind may
     * legally be. Core owns post/term/tt; every other kind arrives from a
     * pinned declaration (a table's id_kind, a widget type), never from an
     * engine branch.
     *
     * @return array<string,list<string>>
     */
    public static function kind_types(Policy $policy): array {
        $kindTypes = [
            'post' => ['post', 'menu_item'],
            'term' => ['term', 'menu'],
            'tt' => ['term', 'menu'],
        ];
        foreach (Snapshot::row_tables($policy) as $table => $decl) {
            $kindTypes[(string) $decl['id_kind']] = [$table];
        }
        foreach ($policy->widget_types() as $type => $_decl) {
            $kindTypes[SidebarState::kind((string) $type)] = ['widget'];
        }
        return $kindTypes;
    }

    /**
     * Which tree entry owns each addressable uuid.
     *
     * Menu items and widgets are real reference targets with real uuids, but
     * they are not files: a menu item lives inside its menu's entry and a
     * widget inside its sidebar's. A reference to one is therefore a
     * dependency on the whole owning entry, which is the unit anything
     * downstream can actually move.
     *
     * @param array<string,array<string,mixed>> $tree
     * @return array<string,array{entity:string,kind:string}> uuid => owner
     */
    public static function owners(array $tree): array {
        $owners = [];
        foreach ($tree as $key => $entity) {
            $key = (string) $key;
            $type = (string) ($entity['type'] ?? '');
            $uuid = (string) ($entity['data']['uuid'] ?? '');
            if ($uuid !== '') {
                $owners[$uuid] = ['entity' => $key, 'kind' => $type];
            }
            if ($type === 'menu') {
                foreach ((array) ($entity['data']['items'] ?? []) as $item) {
                    $itemUuid = (string) ($item['uuid'] ?? '');
                    if ($itemUuid !== '') {
                        $owners[$itemUuid] = ['entity' => $key, 'kind' => 'menu_item'];
                    }
                }
            } elseif ($type === SidebarState::ENTITY_TYPE) {
                foreach ((array) ($entity['data']['widgets'] ?? []) as $widget) {
                    $widgetUuid = (string) ($widget['uuid'] ?? '');
                    if ($widgetUuid !== '') {
                        $owners[$widgetUuid] = ['entity' => $key, 'kind' => 'widget'];
                    }
                }
            }
        }
        return $owners;
    }

    /**
     * Enumerate every outbound reference edge in the tree.
     *
     * `from` is the tree key of the referring entry — a uuid for
     * post/term/menu/table rows, or the synthetic key options/user-meta/
     * sidebar entries are filed under. `target` is the referenced uuid as
     * written; proving it resolves is the compiler's job, not this walker's.
     *
     * Order is deterministic: tree order (the compiler ksorts it), then
     * document order within each entry.
     *
     * @param array<string,array<string,mixed>> $tree
     * @return list<array{from:string,path:string,locator:string,relation:string,check:string,target:string,expects:list<string>,token:?string}>
     */
    public static function edges(array $tree, Policy $policy): array {
        $edges = [];
        foreach ($tree as $key => $entity) {
            $from = (string) $key;
            $path = (string) $entity['path'];
            $emit = static function (
                string $locator,
                string $relation,
                string $check,
                string $target,
                array $expects = [],
                ?string $token = null
            ) use (&$edges, $from, $path): void {
                $edges[] = [
                    'from' => $from, 'path' => $path, 'locator' => $locator,
                    'relation' => $relation, 'check' => $check,
                    'target' => $target, 'expects' => $expects, 'token' => $token,
                ];
            };

            // Untyped token pass. Every typed declaration — scalar refs,
            // json_refs/key_refs, block and shortcode attributes,
            // option_name_refs, table foreign keys, attached meta, widget
            // settings — lands in canonical state as this one wire form, so
            // one kind-agnostic scan covers all of them without the engine
            // knowing which adapter declared what. Keys are scanned too:
            // option_name_refs put the token in the option NAME.
            self::walk_tokens($entity['data'], '$', static function (string $token, string $locator) use ($emit): void {
                $uuid = preg_match('/^\{\{[a-z][a-z0-9_]*:(.+)\}\}$/', $token, $m) === 1 ? $m[1] : '';
                $emit($locator, self::REL_REFERENCE, self::CHECK_TOKEN, $uuid, [], $token);
            });

            $type = (string) $entity['type'];
            if ($type === 'post') {
                // A whole-block codec may need one structural reference kind
                // which cannot be registered globally (TEC's stored legacy
                // widget is the concrete case). Count only tokens parsed from
                // attributes the manifest assigns to that codec. The same
                // spelling in freeform/body text or an undeclared attribute
                // stays on the ordinary token path and therefore refuses as
                // an unregistered kind; a codec declaration cannot widen the
                // repository token vocabulary outside its owned structure.
                $codecWidgetTokens = self::codec_widget_token_counts((string) $entity['body'], $policy);
                self::walk_tokens($entity['body'], 'body', static function (string $token, string $locator) use ($emit, &$codecWidgetTokens): void {
                    $uuid = preg_match('/^\{\{[a-z][a-z0-9_]*:(.+)\}\}$/', $token, $m) === 1 ? $m[1] : '';
                    if (preg_match('/^\{\{widget:([^{}]+)\}\}$/D', $token, $widget) === 1
                        && ($codecWidgetTokens[$token] ?? 0) > 0) {
                        --$codecWidgetTokens[$token];
                        $emit($locator, self::REL_REFERENCE, self::CHECK_RAW, $widget[1], ['widget'], $token);
                        return;
                    }
                    $emit($locator, self::REL_REFERENCE, self::CHECK_TOKEN, $uuid, [], $token);
                });
                foreach ((array) ($entity['data']['terms'] ?? []) as $tax => $termUuids) {
                    foreach ((array) $termUuids as $i => $termUuid) {
                        // Concatenated deliberately: "terms.$tax[$i]" reads
                        // as a string offset into $tax, which is how the
                        // previous inline walk reported every one of these
                        // as "terms.c".
                        $emit('terms.' . $tax . '[' . $i . ']', self::REL_TERM, self::CHECK_RAW, (string) $termUuid, ['term']);
                    }
                }
                // post_parent is already a token, so the pass above proved
                // its shape and target; this restates it as a hierarchy edge
                // for cycle detection and child descent.
                if (is_string($entity['data']['parent'] ?? null)
                    && preg_match('/^\{\{post:([0-9a-f-]{36})\}\}$/', $entity['data']['parent'], $m)) {
                    $emit('parent', self::REL_PARENT, self::CHECK_NONE, $m[1]);
                }
            } elseif ($type === 'term') {
                if (!empty($entity['data']['parent'])) {
                    $emit('parent', self::REL_PARENT, self::CHECK_RAW, (string) $entity['data']['parent'], ['term']);
                }
                foreach ((array) ($entity['data']['relationships'] ?? []) as $tax => $relUuids) {
                    foreach ((array) $relUuids as $i => $relUuid) {
                        $emit('relationships.' . $tax . '[' . $i . ']', self::REL_RELATIONSHIP, self::CHECK_RAW, (string) $relUuid, ['term']);
                    }
                }
            } elseif ($type === 'menu') {
                // A menu owns its items, so an item's parent is an
                // intra-entry edge: it moves scope not at all, but it must
                // still reach cycle detection. Membership in this same menu
                // is the compiler's diagnostic to raise, so only edges that
                // actually resolve inside the menu are emitted here.
                $items = [];
                foreach ((array) ($entity['data']['items'] ?? []) as $item) {
                    $items[(string) ($item['uuid'] ?? '')] = true;
                }
                foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                    if (!empty($item['parent']) && isset($items[(string) $item['parent']])) {
                        $edges[] = [
                            'from' => (string) ($item['uuid'] ?? ''), 'path' => $path,
                            'locator' => "items[$i].parent", 'relation' => self::REL_PARENT,
                            'check' => self::CHECK_NONE, 'target' => (string) $item['parent'],
                            'expects' => [], 'token' => null,
                        ];
                    }
                }
            }
        }
        return $edges;
    }

    /**
     * @return array<string,int> exact {{widget:uuid}} token => occurrence count
     */
    private static function codec_widget_token_counts(string $body, Policy $policy): array {
        if ($body === '' || !function_exists('parse_blocks')) {
            return [];
        }
        $counts = [];
        $rules = $policy->block_attr_rules();
        foreach (parse_blocks($body) as $block) {
            if (is_array($block)) {
                self::collect_codec_widget_tokens($block, $rules, $counts);
            }
        }
        return $counts;
    }

    /**
     * @param array<string,mixed> $block
     * @param array<string,array> $rules
     * @param array<string,int> $counts
     */
    private static function collect_codec_widget_tokens(array $block, array $rules, array &$counts): void {
        $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : '';
        $declared = $rules[$name] ?? [];
        $codecPaths = [];
        foreach ($declared as $rule) {
            if (!is_array($rule) || !array_key_exists('codec', $rule) || !is_string($rule['path'] ?? null)) {
                continue;
            }
            $codecPaths[(string) $rule['path']] = true;
        }
        // AttributeGrammar makes codec ownership exclusive. Re-prove the
        // shape here because frozen/custom policies and narrow test doubles
        // can reach the graph without having run the live manifest loader.
        if ($codecPaths !== [] && count($codecPaths) === count($declared)) {
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            foreach (array_keys($codecPaths) as $path) {
                if (!array_key_exists($path, $attrs)) {
                    continue;
                }
                self::walk_tokens($attrs[$path], 'codec', static function (string $token) use (&$counts): void {
                    if (preg_match('/^\{\{widget:[^{}]+\}\}$/D', $token) === 1) {
                        $counts[$token] = ($counts[$token] ?? 0) + 1;
                    }
                });
            }
        }
        foreach ((array) ($block['innerBlocks'] ?? []) as $inner) {
            if (is_array($inner)) {
                self::collect_codec_widget_tokens($inner, $rules, $counts);
            }
        }
    }

    private static function walk_tokens($value, string $locator, callable $visit): void {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $next = $locator . (is_int($key) ? "[$key]" : '.' . $key);
                if (is_string($key)) {
                    self::tokens_in_string($key, $next . ' (key)', $visit);
                }
                self::walk_tokens($child, $next, $visit);
            }
            return;
        }
        if (is_string($value)) {
            self::tokens_in_string($value, $locator, $visit);
        }
    }

    private static function tokens_in_string(string $value, string $locator, callable $visit): void {
        if (!preg_match_all('/\{\{([a-z][a-z0-9_]*):([^{}]+)\}\}/', $value, $matches, PREG_SET_ORDER)) {
            return;
        }
        foreach ($matches as $m) {
            $visit($m[0], $locator);
        }
    }
}
