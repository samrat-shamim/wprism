<?php
namespace Duo;

/**
 * A fail-closed repository preflight with stable, machine-readable findings.
 * It deliberately runs before Ledger::ensure(), Capture::snapshot(), deploy
 * lifecycle calls, or any filesystem materialization.
 */
final class RepositoryAuthorizationException extends \RuntimeException {
    /** @var array<int,array<string,mixed>> */
    public array $diagnostics;

    public function __construct(array $diagnostics) {
        $this->diagnostics = $diagnostics;
        $lines = array_map(static function (array $d): string {
            return sprintf(
                '[%s] %s uuid=%s surface=%s field=%s classification=%s declared_by=%s',
                $d['code'],
                $d['path'],
                $d['uuid'],
                $d['surface'],
                $d['field'],
                $d['classification'],
                $d['declared_by'] ?? 'none'
            );
        }, $diagnostics);
        parent::__construct(
            'duo: repository authorization failed (' . count($diagnostics)
            . " finding(s)); no target mutation attempted:\n  - " . implode("\n  - ", $lines)
        );
    }

    public function payload(): array {
        return [
            'ok' => false,
            'error' => 'repository_authorization_failed',
            'diagnostics' => $this->diagnostics,
        ];
    }
}

final class RepositoryAuthorization {
    private const POST_FIELDS = [
        'uuid', 'type', 'slug', 'title', 'status', 'date', 'date_gmt',
        'modified', 'modified_gmt', 'author', 'parent', 'menu_order', 'comment_status',
        'ping_status', 'excerpt', 'meta', 'terms', 'term_orders',
    ];
    private const ATTACHMENT_FIELDS = ['file', 'media', 'mime', 'alt'];
    private const TERM_FIELDS = [
        'uuid', 'taxonomy', 'name', 'slug', 'description', 'parent', 'relationships',
    ];
    private const MENU_FIELDS = ['uuid', 'name', 'slug', 'locations', 'items'];
    private const MENU_ITEM_FIELDS = [
        'uuid', 'type', 'object', 'ref', 'parent', 'position', 'title',
        'description', 'attr_title', 'target', 'classes', 'xfn', 'meta',
    ];
    private const TABLE_FIELDS = ['columns', 'meta', 'table', 'uuid'];
    private const MANAGED_OPTIONS = ['active_plugins', 'template', 'stylesheet'];

    /**
     * One filesystem read produces the exact tree both authorization and the
     * subsequent plan/apply consume. Deploy uses it too, preventing lifecycle
     * reconciliation from getting ahead of a later apply refusal.
     *
     * @return array<string,array{type:string,path:string,hash:string,content:string,post_type?:string}>
     */
    public static function load_tree(string $repo, Policy $policy): array {
        $stateDir = rtrim($repo, '/') . '/state';
        if (!is_dir($stateDir)) {
            throw new \RuntimeException("duo: no state/ directory in " . rtrim($repo, '/'));
        }
        $out = [];
        foreach (glob($stateDir . '/posts/*/*.md') ?: [] as $f) {
            $content = Canon::read_file($f);
            [$front, $body] = Canon::parse_post_file($content);
            $out[$front['uuid']] = [
                'type' => 'post',
                'post_type' => $front['type'],
                'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', Canon::post_hash_basis($front, $body, $policy)),
                'content' => $content,
                'data' => $front,
                'body' => $body,
            ];
        }
        foreach (glob($stateDir . '/terms/*/*.json') ?: [] as $f) {
            $content = Canon::read_file($f);
            $front = Canon::decode($content);
            $out[$front['uuid']] = [
                'type' => 'term',
                'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', $content),
                'content' => $content,
                'data' => $front,
            ];
        }
        foreach (glob($stateDir . '/menus/*.json') ?: [] as $f) {
            $content = Canon::read_file($f);
            $front = Canon::decode($content);
            $out[$front['uuid']] = [
                'type' => 'menu',
                'path' => substr($f, strlen($stateDir) + 1),
                'hash' => hash('sha256', $content),
                'content' => $content,
                'data' => $front,
            ];
        }
        $optFile = $stateDir . '/options/core.json';
        if (is_file($optFile)) {
            $content = Canon::read_file($optFile);
            $out['options/core'] = [
                'type' => 'options',
                'path' => 'options/core.json',
                'hash' => hash('sha256', $content),
                'content' => $content,
                'data' => Canon::decode($content),
            ];
        }
        return array_merge($out, Snapshot::load_tree_entries($stateDir));
    }

    /** @return array<string,array> authorized tree */
    public static function load_authorized_tree(string $repo, Policy $policy): array {
        // Compatibility facade for older callers: there is no longer an
        // authorization-only route around semantic compilation.
        return RepositoryCompiler::compile($repo, $policy)->tree();
    }

    public static function assert_tree(Policy $policy, array $tree): void {
        $policy->prime_interpreters_from_repository($tree);
        $diagnostics = [];
        foreach ($tree as $uuid => $entity) {
            switch ($entity['type']) {
                case 'post':
                    self::authorize_post($policy, (string) $uuid, $entity, $diagnostics);
                    break;
                case 'term':
                    self::authorize_term($policy, (string) $uuid, $entity, $diagnostics);
                    break;
                case 'menu':
                    self::authorize_menu($policy, (string) $uuid, $entity, $diagnostics);
                    break;
                case 'options':
                    self::authorize_options($policy, (string) $uuid, $entity, $diagnostics);
                    break;
                default:
                    self::authorize_table($policy, (string) $uuid, $entity, $diagnostics);
                    break;
            }
        }
        if (!$diagnostics) {
            return;
        }
        usort($diagnostics, static fn(array $a, array $b): int =>
            [$a['path'], $a['surface'], $a['field'], $a['classification']]
            <=> [$b['path'], $b['surface'], $b['field'], $b['classification']]
        );
        throw new RepositoryAuthorizationException($diagnostics);
    }

    private static function authorize_post(Policy $policy, string $uuid, array $entity, array &$out): void {
        $front = $entity['data'] ?? Canon::parse_post_file($entity['content'])[0];
        $path = $entity['path'];
        $postType = (string) ($front['type'] ?? '');
        self::unexpected_fields($front, array_merge(
            self::POST_FIELDS,
            $postType === 'attachment' ? self::ATTACHMENT_FIELDS : []
        ), $path, $uuid, 'post_field', $out);

        if (!in_array($postType, $policy->post_types(), true)) {
            self::finding($out, 'repository_entity_out_of_scope', $path, $uuid, 'post_type', 'type', 'unscoped', 'site.duo.json');
        }
        $typeDetails = $policy->post_type_rule_details($postType);
        $typeClass = $typeDetails['rule']['class'] ?? 'authored';
        if ($typeClass !== 'authored') {
            self::finding($out, 'repository_field_not_authored', $path, $uuid, 'post_type', 'type', $typeClass, $typeDetails['source']);
        }

        foreach (['title', 'slug', 'status', 'date', 'date_gmt', 'modified', 'modified_gmt', 'author', 'parent',
            'menu_order', 'comment_status', 'ping_status', 'excerpt'] as $field) {
            if (!array_key_exists($field, $front)) {
                continue;
            }
            $details = $policy->field_rule_details($postType, $field);
            $dedicatedDerivedTitle = $field === 'title' && $details['class'] === 'derived';
            if ($details['class'] !== 'authored' && !$dedicatedDerivedTitle) {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'post_field', $field, $details['class'], $details['source']);
            }
        }

        $meta = (array) ($front['meta'] ?? []);
        foreach ($meta as $key => $_) {
            $details = $policy->meta_rule_details_for_post((string) $key, $meta);
            $class = $details['rule']['class'] ?? 'unclassified';
            if ($class !== 'authored') {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'post_meta', (string) $key, $class, $details['source']);
            }
        }
        foreach ((array) ($front['terms'] ?? []) as $taxonomy => $_) {
            self::authorize_taxonomy($policy, (string) $taxonomy, $path, $uuid, 'post_terms', $out);
        }
        foreach ((array) ($front['term_orders'] ?? []) as $taxonomy => $_) {
            self::authorize_taxonomy($policy, (string) $taxonomy, $path, $uuid, 'post_term_orders', $out);
        }

        if ($postType === 'attachment') {
            self::require_managed_meta($policy, '_wp_attached_file', 'file', $path, $uuid, $out);
            self::require_managed_meta($policy, '_wp_attachment_image_alt', 'alt', $path, $uuid, $out);
        }
    }

    private static function authorize_term(Policy $policy, string $uuid, array $entity, array &$out): void {
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $path = $entity['path'];
        self::unexpected_fields($front, self::TERM_FIELDS, $path, $uuid, 'term_field', $out);
        self::authorize_taxonomy($policy, (string) ($front['taxonomy'] ?? ''), $path, $uuid, 'taxonomy', $out);
        foreach ((array) ($front['relationships'] ?? []) as $taxonomy => $_) {
            self::authorize_taxonomy($policy, (string) $taxonomy, $path, $uuid, 'term_relationships', $out);
        }
    }

    private static function authorize_menu(Policy $policy, string $uuid, array $entity, array &$out): void {
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $path = $entity['path'];
        self::unexpected_fields($front, self::MENU_FIELDS, $path, $uuid, 'menu_field', $out);
        $managed = [
            'type' => '_menu_item_type',
            'object' => '_menu_item_object',
            'parent' => '_menu_item_menu_item_parent',
            'target' => '_menu_item_target',
            'classes' => '_menu_item_classes',
            'xfn' => '_menu_item_xfn',
        ];
        foreach ((array) ($front['items'] ?? []) as $index => $item) {
            $item = (array) $item;
            self::unexpected_fields($item, self::MENU_ITEM_FIELDS, $path, $uuid, "menu_item[$index]", $out);
            foreach ($managed as $field => $metaKey) {
                self::require_managed_meta($policy, $metaKey, "items[$index].$field", $path, $uuid, $out);
            }
            $refMeta = ($item['type'] ?? '') === 'custom' ? '_menu_item_url' : '_menu_item_object_id';
            self::require_managed_meta($policy, $refMeta, "items[$index].ref", $path, $uuid, $out);

            // DUO-3266: re-derive classification from the COMPILED
            // repository's own policy, independent of what captured it —
            // same defense-in-depth authorize_post() already applies to
            // its own 'meta' field (a merge/rebase can land a captured
            // 'authored' key next to a policy that no longer agrees).
            $itemMeta = (array) ($item['meta'] ?? []);
            foreach ($itemMeta as $key => $_) {
                $details = $policy->meta_rule_details_for_post((string) $key, $itemMeta);
                $class = $details['rule']['class'] ?? 'unclassified';
                if ($class !== 'authored') {
                    self::finding(
                        $out, 'repository_field_not_authored', $path, $uuid,
                        "menu_item[$index]", (string) $key, $class, $details['source']
                    );
                }
            }
        }
    }

    private static function authorize_options(Policy $policy, string $uuid, array $entity, array &$out): void {
        $document = $entity['data'] ?? Canon::decode($entity['content']);
        foreach (OptionState::records($document) as $name => $record) {
            $details = str_contains((string) $name, '{{')
                ? $policy->canonical_option_name_ref_details((string) $name)
                : $policy->option_rule_details((string) $name);
            $rule = $details['rule'] ?? [];
            // DUO-3264 (fork A): theme_mods_<stylesheet>'s own sub_keys
            // rule is never findable via the ordinary single-name lookup
            // above (its physical NAME is computed, not declared).
            // Deliberately the PREFIX-only match (Policy::
            // dynamic_option_rule_for_prefix(), not the exact-match
            // dynamic_option_rule_for_name() Apply::option_apply_target()
            // uses): authorization runs as part of repository compilation,
            // which `wp duo deploy` also goes through — including on a
            // target whose active theme does not match yet, since deploy
            // is what reconciles that mismatch. Requiring an exact match
            // here would make deploy unable to compile the very repository
            // it needs to read to know what to reconcile — see
            // dynamic_option_rule_for_prefix()'s own docblock for the full
            // reasoning (caught live, not by inspection).
            if (($rule['class'] ?? null) === null && empty($rule['sub_keys']) && !str_contains((string) $name, '{{')) {
                $dynamicRule = $policy->dynamic_option_rule_for_prefix((string) $name);
                if ($dynamicRule !== null) {
                    $rule = $dynamicRule;
                    $details = ['rule' => $rule, 'source' => 'dynamic_options'];
                }
            }
            $class = $rule['class'] ?? 'unclassified';
            if ($record['state'] === 'deleted') {
                if ($class !== 'authored' || !empty($rule['sub_keys'])) {
                    self::finding(
                        $out, 'repository_option_delete_not_authored', $entity['path'], $uuid,
                        'option_tombstone', (string) $name, $class, $details['source']
                    );
                }
                continue;
            }
            if ($record['state'] === 'absent') {
                $managed = $class === 'managed' && in_array($name, self::MANAGED_OPTIONS, true);
                if ($class !== 'authored' && !$managed && empty($rule['sub_keys'])) {
                    self::finding(
                        $out, 'repository_option_absence_not_authored', $entity['path'], $uuid,
                        'option_absence', (string) $name, $class, $details['source']
                    );
                }
                continue;
            }
            $value = $record['value'];
            try {
                OptionState::assert_rule_autoload($rule, (string) $record['autoload'], "repository option '$name'");
            } catch (\Throwable $t) {
                self::finding(
                    $out, 'repository_option_autoload_not_authorized', $entity['path'], $uuid,
                    'option_autoload', (string) $name, (string) $record['autoload'], $details['source']
                );
            }
            if (!empty($rule['sub_keys'])) {
                // DUO-3233: this option's OWN top-level class is legitimately
                // something other than 'authored' (Polylang's `polylang`/
                // Yoast's `wpseo` are both 'env' — excluded whole, except
                // named sub-keys carved out below them) — so the ordinary
                // whole-value check below does not apply. Instead, every KEY
                // actually present in the repository's captured value must
                // be individually declared authored in sub_keys; anything
                // else is exactly the "unknown field" case
                // unexpected_fields() already guards for post/term/menu/
                // table entities, applied here to an option's own sub-keys.
                self::authorize_option_sub_keys(
                    (string) $name, $value, $rule['sub_keys'], $details['source'], $entity['path'], $uuid, $out
                );
                continue;
            }
            $managed = $class === 'managed' && in_array($name, self::MANAGED_OPTIONS, true);
            if ($class !== 'authored' && !$managed) {
                self::finding($out, 'repository_field_not_authored', $entity['path'], $uuid, 'option', (string) $name, $class, $details['source']);
            }
        }
    }

    private static function authorize_option_sub_keys(
        string $name, $value, array $subKeys, ?string $source, string $path, string $uuid, array &$out
    ): void {
        if (!is_array($value)) {
            self::finding($out, 'repository_field_not_authored', $path, $uuid, 'option', $name, 'malformed', $source);
            return;
        }
        foreach ($value as $subKey => $_) {
            $subClass = $subKeys[$subKey]['class'] ?? 'unclassified';
            if ($subClass !== 'authored') {
                self::finding(
                    $out, 'repository_field_not_authored', $path, $uuid, 'option_sub_key',
                    "$name.$subKey", $subClass, $source
                );
            }
        }
    }

    private static function authorize_table(Policy $policy, string $uuid, array $entity, array &$out): void {
        $path = $entity['path'];
        $table = (string) $entity['type'];
        $rows = Snapshot::row_tables($policy);
        $tableDetails = $policy->declared_table_details($table);
        if (!isset($rows[$table])) {
            $class = $tableDetails['rule']['class'] ?? 'unclassified';
            self::finding($out, 'repository_entity_out_of_scope', $path, $uuid, 'table', 'table', $class, $tableDetails['source']);
            return;
        }
        $decl = $rows[$table];
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        self::unexpected_fields($front, self::TABLE_FIELDS, $path, $uuid, 'table_field', $out);
        if (($front['table'] ?? null) !== $table) {
            self::finding($out, 'repository_field_not_authored', $path, $uuid, 'table_field', 'table', 'mismatched', $tableDetails['source']);
        }

        $refs = [];
        foreach ($decl['refs'] ?? [] as $ref) {
            $refs[(string) $ref['column']] = true;
        }
        foreach ((array) ($front['columns'] ?? []) as $column => $_) {
            if (isset($refs[$column])) {
                continue;
            }
            $class = $decl['columns'][$column]['class'] ?? 'unclassified';
            if ($class !== 'authored') {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'table_column', (string) $column, $class, $tableDetails['source']);
            }
        }

        $metaTables = [];
        foreach (Snapshot::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) === $table) {
                $metaTables[$metaName] = $metaDecl;
            }
        }
        foreach ((array) ($front['meta'] ?? []) as $key => $_) {
            if (!$metaTables) {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'attached_meta', (string) $key, 'unclassified', null);
                continue;
            }
            foreach ($metaTables as $metaName => $metaDecl) {
                $class = Snapshot::meta_key_in_keyspace($metaDecl, (string) $key)
                    ? ($metaDecl['keys'][$key]['class'] ?? ($metaDecl['default_class'] ?? 'authored'))
                    : 'unclassified';
                if ($class !== 'authored') {
                    $source = $policy->declared_table_details($metaName)['source'];
                    self::finding($out, 'repository_field_not_authored', $path, $uuid, "attached_meta:$metaName", (string) $key, $class, $source);
                }
            }
        }
    }

    private static function authorize_taxonomy(Policy $policy, string $taxonomy, string $path, string $uuid, string $surface, array &$out): void {
        $details = $policy->taxonomy_scope_details($taxonomy);
        if (!$details['authorized']) {
            self::finding($out, 'repository_entity_out_of_scope', $path, $uuid, $surface, $taxonomy, 'unscoped', $details['source']);
        }
    }

    private static function require_managed_meta(Policy $policy, string $metaKey, string $field, string $path, string $uuid, array &$out): void {
        $details = $policy->post_meta_rule_details($metaKey);
        $class = $details['rule']['class'] ?? 'unclassified';
        if ($class !== 'managed') {
            self::finding($out, 'repository_managed_route_not_authorized', $path, $uuid, 'managed_post_meta', $field, $class, $details['source']);
        }
    }

    private static function unexpected_fields(array $actual, array $allowed, string $path, string $uuid, string $surface, array &$out): void {
        foreach (array_diff(array_keys($actual), $allowed) as $field) {
            self::finding($out, 'repository_field_not_authored', $path, $uuid, $surface, (string) $field, 'unclassified', null);
        }
    }

    private static function finding(array &$out, string $code, string $path, string $uuid, string $surface, string $field, string $classification, ?string $source): void {
        $out[] = [
            'code' => $code,
            'path' => $path,
            'uuid' => $uuid,
            'surface' => $surface,
            'field' => $field,
            'classification' => $classification,
            'declared_by' => $source,
        ];
    }
}
