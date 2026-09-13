<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ScalarValueConstraint.php';

require_once __DIR__ . '/../Kernel/TableRowScope.php';

require_once __DIR__ . '/../Kernel/EncodedText.php';
require_once __DIR__ . '/../Kernel/BlockAttributeReader.php';
require_once __DIR__ . '/../Kernel/BlockContentGrammar.php';

require_once __DIR__ . '/../Kernel/PhpContainerValue.php';

require_once __DIR__ . '/../Grammar/BodyRefGrammar.php';
require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
require_once __DIR__ . '/../Grammar/AuthoredValueCodec.php';
require_once __DIR__ . '/../Policy/ScopeAdoption.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/PostPasswordBinding.php';
require_once __DIR__ . '/../Kernel/PersonalData.php';
require_once __DIR__ . '/../Kernel/Secrets.php';

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
            $line = sprintf(
                '[%s] %s uuid=%s surface=%s field=%s classification=%s declared_by=%s',
                $d['code'],
                $d['path'],
                $d['uuid'],
                $d['surface'],
                $d['field'],
                $d['classification'],
                $d['declared_by'] ?? 'none'
            );
            // The key=value head stays byte-identical for every finding that
            // ever had one: it is what live suites and operator greps key on
            // (sandbox/tests/live/regress_option_subkeys.sh:592 quotes a whole
            // line). A remedy is an ADDITIONAL indented line under its own
            // finding rather than another key=value pair, because the sentence
            // holds spaces and would end the scan mid-remedy (issue #3495).
            return isset($d['remediation']) && is_string($d['remediation']) && $d['remediation'] !== ''
                ? $line . "\n      remedy: " . $d['remediation']
                : $line;
        }, $diagnostics);
        parent::__construct(
            'wprism: repository authorization failed (' . count($diagnostics)
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
        'ping_status', 'excerpt', 'meta', 'terms', 'term_orders', 'password_binding',
    ];
    private const ATTACHMENT_FIELDS = ['file', 'media', 'mime', 'alt'];
    private const TERM_FIELDS = [
        'uuid', 'taxonomy', 'name', 'slug', 'description', 'parent', 'meta', 'relationships',
    ];
    private const MENU_FIELDS = ['uuid', 'name', 'slug', 'locations', 'items'];
    private const MENU_ITEM_FIELDS = [
        'uuid', 'type', 'object', 'ref', 'parent', 'position', 'title',
        'description', 'attr_title', 'target', 'classes', 'xfn', 'meta',
    ];
    private const TABLE_FIELDS = ['columns', 'meta', 'table', 'uuid'];
    private const USER_META_FIELDS = ['login', 'meta'];
    private const MANAGED_OPTIONS = ['active_plugins', 'template', 'stylesheet'];
    private const MAX_MENU_URI_TOTAL_BYTES = 131072;
    private const MAX_MENU_URI_PATH_BYTES = 65536;
    private const MAX_MENU_URI_FRAGMENT_BYTES = 65536;
    private const MAX_MENU_QUERY_BYTES = 65536;
    private const MAX_MENU_QUERY_COMPONENT_BYTES = 8192;
    private const MAX_MENU_QUERY_NAME_SEGMENT_BYTES = 1024;
    private const MAX_MENU_QUERY_NAME_SEGMENTS = 16;
    private const MAX_MENU_QUERY_PAIRS = 512;
    private const MENU_URI_DECODE_WORK_FACTOR = 32;
    private const MAX_MENU_URI_DECODE_WORK_BYTES = 4194304;

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
            throw new \RuntimeException('wprism: no state/ directory in ' . rtrim($repo, '/'));
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
        foreach (glob($stateDir . '/sidebars/*.json') ?: [] as $f) {
            $content = Canon::read_file($f);
            $front = Canon::decode($content);
            $rel = substr($f, strlen($stateDir) + 1);
            $sidebar = SidebarState::sidebar_from_path($rel);
            $out[SidebarState::key((string) $sidebar)] = [
                'type' => SidebarState::ENTITY_TYPE,
                'path' => $rel,
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
                case 'sidebar':
                    self::authorize_sidebar($policy, (string) $uuid, $entity, $diagnostics);
                    break;
                case 'options':
                    self::authorize_options($policy, (string) $uuid, $entity, $diagnostics);
                    break;
                case 'user-meta':
                    self::authorize_user_meta($policy, (string) $uuid, $entity, $diagnostics);
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
            self::finding($out, 'repository_entity_out_of_scope', $path, $uuid, 'post_type', 'type', 'unscoped', 'site.wprism.json');
        }
        $typeDetails = $policy->post_type_rule_details($postType);
        $typeClass = $typeDetails['rule']['class'] ?? 'authored';
        if ($typeClass !== 'authored') {
            // The whole-type class is the one finding whose coordinates named
            // no repair: `classification=runtime declared_by=site.wprism.json`
            // says a rule exists somewhere without saying WHICH entry or what
            // to write instead, and issue #3495's walkthrough spent two more
            // hand-edits of site.wprism.json discovering both.
            self::finding(
                $out, 'repository_field_not_authored', $path, $uuid, 'post_type', 'type',
                $typeClass, $typeDetails['source'],
                ScopeAdoption::scope_class_remedy('post_type', $postType, $typeClass, $typeDetails['source'])
            );
        }

        foreach (['title', 'slug', 'status', 'date', 'date_gmt', 'modified', 'modified_gmt', 'author', 'parent',
            'menu_order', 'comment_status', 'ping_status', 'excerpt'] as $field) {
            if (!array_key_exists($field, $front)) {
                continue;
            }
            $details = $policy->field_rule_details($postType, $field);
            // Post front-matter is the one derived-field surface Policy
            // explicitly supports.  Policy::validate_field_classes() owns
            // the closed field-name/class allowlist, so accepting only the
            // manifest-resolved 'derived' class here permits Woo's captured
            // title/timestamps without opening derived/runtime post_meta or
            // options to the repository.  Any other class remains refused.
            $manifestDerivedPostField = $details['class'] === 'derived';
            if ($details['class'] !== 'authored' && !$manifestDerivedPostField) {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'post_field', $field, $details['class'], $details['source']);
            }
        }
        foreach (['title', 'excerpt', 'author', 'alt'] as $field) {
            if (array_key_exists($field, $front)) {
                self::authorize_sensitivity(
                    $out, $path, $uuid, 'post_field', $field, $front[$field], [], 'platform'
                );
            }
        }
        self::authorize_sensitivity(
            $out,
            $path,
            $uuid,
            'post_field',
            'body',
            self::post_body_for_clearance($policy, $postType, (string) ($entity['body'] ?? ''), $path),
            [],
            'platform',
            $policy->body_mode($postType) === BodyRefGrammar::BODY_MODE
                ? ($policy->body_ref_rule($postType)['pii_paths'] ?? []) : []
        );
        if (array_key_exists('password_binding', $front)) {
            $expected = null;
            try {
                $expected = PostPasswordBinding::name($uuid);
            } catch (\Throwable) {
                // The repository UUID validator owns the identity finding.
            }
            if (!is_string($front['password_binding']) || $expected === null
                || !hash_equals($expected, $front['password_binding'])) {
                self::finding(
                    $out,
                    'repository_field_not_authored',
                    $path,
                    $uuid,
                    'post_field',
                    'password_binding',
                    'invalid',
                    'platform',
                    'use the exact post_password:<uuid> binding emitted by capture; never put a password in state/'
                );
            }
        }

        $meta = (array) ($front['meta'] ?? []);
        foreach ($meta as $key => $value) {
            $details = $policy->meta_rule_details_for_post((string) $key, $meta);
            $rule = $details['rule'] ?? [];
            $class = $rule['class'] ?? 'unclassified';
            if ($class !== 'authored') {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'post_meta', (string) $key, $class, $details['source']);
            } else {
                self::authorize_sensitivity(
                    $out, $path, $uuid, 'post_meta', (string) $key, $value, $rule, $details['source']
                );
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
        $allowedFields = self::TERM_FIELDS;
        if ($policy->taxonomy_term_group_is_authored((string) ($front['taxonomy'] ?? ''))) {
            $allowedFields[] = 'term_group';
        }
        self::unexpected_fields($front, $allowedFields, $path, $uuid, 'term_field', $out);
        self::authorize_taxonomy($policy, (string) ($front['taxonomy'] ?? ''), $path, $uuid, 'taxonomy', $out);
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $front)) {
                self::authorize_sensitivity(
                    $out, $path, $uuid, 'term_field', $field, $front[$field], [], 'platform'
                );
            }
        }
        $meta = (array) ($front['meta'] ?? []);
        foreach ($meta as $key => $value) {
            $details = $policy->meta_rule_details_for_term((string) $key, $meta);
            $rule = $details['rule'] ?? [];
            $class = $rule['class'] ?? 'unclassified';
            if ($class !== 'authored') {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'term_meta', (string) $key, $class, $details['source']);
            } else {
                self::authorize_sensitivity(
                    $out, $path, $uuid, 'term_meta', (string) $key, $value, $rule, $details['source']
                );
            }
        }
        foreach ((array) ($front['relationships'] ?? []) as $taxonomy => $_) {
            self::authorize_taxonomy($policy, (string) $taxonomy, $path, $uuid, 'term_relationships', $out);
        }
    }

    private static function authorize_menu(Policy $policy, string $uuid, array $entity, array &$out): void {
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $path = $entity['path'];
        self::unexpected_fields($front, self::MENU_FIELDS, $path, $uuid, 'menu_field', $out);
        self::authorize_sensitivity(
            $out, $path, $uuid, 'menu_field', 'name', $front['name'] ?? '', [], 'platform'
        );
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
            foreach (['title', 'description', 'attr_title', 'target', 'classes', 'xfn'] as $field) {
                if (array_key_exists($field, $item)) {
                    self::authorize_sensitivity(
                        $out, $path, $uuid, "menu_item[$index]", $field, $item[$field], [], 'platform'
                    );
                }
            }
            foreach ($managed as $field => $metaKey) {
                self::require_managed_meta($policy, $metaKey, "items[$index].$field", $path, $uuid, $out);
            }
            $refMeta = ($item['type'] ?? '') === 'custom' ? '_menu_item_url' : '_menu_item_object_id';
            self::require_managed_meta($policy, $refMeta, "items[$index].ref", $path, $uuid, $out);
            if (array_key_exists('ref', $item)) {
                // `ref` is materialized through managed post meta, but its
                // repository bytes are still Git-editable. A custom URL can
                // carry credentials/PII and a non-custom token must receive
                // the same clearance before its separate reference grammar.
                $clearanceValue = $item['ref'];
                if (($item['type'] ?? '') === 'custom' && is_string($clearanceValue)) {
                    $semantic = self::menu_uri_clearance_parts($clearanceValue);
                    if ($semantic === null) {
                        // The diagnostic carries coordinates only: malformed
                        // URI bytes may themselves be credentials and must
                        // never be reflected into a refusal message.
                        self::finding(
                            $out,
                            'repository_menu_url_query_invalid',
                            $path,
                            $uuid,
                            "menu_item[$index]",
                            'ref',
                            'invalid',
                            'platform',
                            'replace the custom menu URL with a bounded query whose percent escapes are well formed'
                        );
                        $clearanceValue = [$clearanceValue];
                    } else {
                        // Ordered scalar-name + one-entry-map pairs retain
                        // every duplicate while path/fragment views expose
                        // percent-encoded semantics. The raw URL remains first;
                        // no decoded form is ever written back.
                        $clearanceValue = self::menu_uri_clearance_value($clearanceValue, $semantic);
                    }
                }
                self::authorize_sensitivity(
                    $out, $path, $uuid, "menu_item[$index]", 'ref', $clearanceValue, [], 'platform'
                );
            }

            // issue #3266: re-derive classification from the COMPILED
            // repository's own policy, independent of what captured it —
            // same defense-in-depth authorize_post() already applies to
            // its own 'meta' field (a merge/rebase can land a captured
            // 'authored' key next to a policy that no longer agrees).
            $itemMeta = (array) ($item['meta'] ?? []);
            foreach ($itemMeta as $key => $value) {
                $details = $policy->meta_rule_details_for_post((string) $key, $itemMeta);
                $rule = $details['rule'] ?? [];
                $class = $rule['class'] ?? 'unclassified';
                if ($class !== 'authored') {
                    self::finding(
                        $out, 'repository_field_not_authored', $path, $uuid,
                        "menu_item[$index]", (string) $key, $class, $details['source']
                    );
                } else {
                    self::authorize_sensitivity(
                        $out, $path, $uuid, "menu_item[$index]", (string) $key,
                        $value, $rule, $details['source']
                    );
                }
            }
        }
    }

    /**
     * @return ?array{
     *   path:string,
     *   fragment:string,
     *   pairs:list<array{name:string,value:string,path:list<string>}>
     * } null means malformed or over budget
     */
    private static function menu_uri_clearance_parts(string $url): ?array {
        $length = strlen($url);
        // The total bound precedes even delimiter discovery: otherwise a
        // fragment-only overlimit input is duplicated by substr() before its
        // inevitable refusal, defeating the parser's memory ceiling.
        if ($length > self::MAX_MENU_URI_TOTAL_BYTES) {
            return null;
        }
        $fragmentAt = strpos($url, '#');
        $queryAt = strpos($url, '?');
        $hasQuery = $queryAt !== false && ($fragmentAt === false || $queryAt < $fragmentAt);
        $pathEnd = $hasQuery ? $queryAt : ($fragmentAt === false ? $length : $fragmentAt);
        $queryEnd = $fragmentAt === false ? $length : $fragmentAt;
        $rawPath = substr($url, 0, $pathEnd);
        $rawQuery = $hasQuery ? substr($url, $queryAt + 1, $queryEnd - $queryAt - 1) : '';
        $rawFragment = $fragmentAt === false ? '' : substr($url, $fragmentAt + 1);
        $semanticBytes = strlen($rawPath) + strlen($rawQuery) + strlen($rawFragment);
        if (strlen($rawPath) > self::MAX_MENU_URI_PATH_BYTES
            || strlen($rawQuery) > self::MAX_MENU_QUERY_BYTES
            || strlen($rawFragment) > self::MAX_MENU_URI_FRAGMENT_BYTES) {
            return null;
        }
        $workRemaining = min(
            self::MAX_MENU_URI_DECODE_WORK_BYTES,
            max(1, $semanticBytes) * self::MENU_URI_DECODE_WORK_FACTOR
        );
        $path = self::decode_menu_uri_component(
            $rawPath,
            self::MAX_MENU_URI_PATH_BYTES,
            $workRemaining,
            false
        );
        $pairs = self::menu_query_clearance_pairs($rawQuery, $workRemaining);
        $fragment = self::decode_menu_uri_component(
            $rawFragment,
            self::MAX_MENU_URI_FRAGMENT_BYTES,
            $workRemaining,
            false
        );
        if ($path === null || $pairs === null || $fragment === null) {
            return null;
        }
        return ['path' => $path, 'fragment' => $fragment, 'pairs' => $pairs];
    }

    /**
     * @return ?list<array{name:string,value:string,path:list<string>}>
     * null means malformed or over budget
     */
    private static function menu_query_clearance_pairs(string $query, int &$workRemaining): ?array {
        if ($query === '') {
            return [];
        }
        // A custom menu ref is stored and emitted as an opaque URI here. `&`
        // is the URL query pair delimiter; `;` remains component data rather
        // than inheriting a process-local PHP arg_separator.input setting.
        $parts = explode('&', $query, self::MAX_MENU_QUERY_PAIRS + 1);
        if (count($parts) > self::MAX_MENU_QUERY_PAIRS) {
            return null;
        }
        $pairs = [];
        foreach ($parts as $part) {
            $separator = strpos($part, '=');
            $rawName = $separator === false ? $part : substr($part, 0, $separator);
            $rawValue = $separator === false ? '' : substr($part, $separator + 1);
            $name = self::decode_menu_uri_component(
                $rawName,
                self::MAX_MENU_QUERY_COMPONENT_BYTES,
                $workRemaining,
                true
            );
            $value = self::decode_menu_uri_component(
                $rawValue,
                self::MAX_MENU_QUERY_COMPONENT_BYTES,
                $workRemaining,
                true
            );
            if ($name === null || $value === null) {
                return null;
            }
            $path = self::menu_query_name_path($name);
            if ($path === null) {
                return null;
            }
            $pairs[] = ['name' => $name, 'value' => $value, 'path' => $path];
        }
        return $pairs;
    }

    /** @return ?list<string> null means malformed or over budget */
    private static function menu_query_name_path(string $name): ?array {
        $open = strpos($name, '[');
        if ($open === false) {
            if (str_contains($name, ']') || strlen($name) > self::MAX_MENU_QUERY_NAME_SEGMENT_BYTES) {
                return null;
            }
            return [$name];
        }
        if ($open === 0 || str_contains(substr($name, 0, $open), ']')) {
            return null;
        }
        $base = substr($name, 0, $open);
        if (strlen($base) > self::MAX_MENU_QUERY_NAME_SEGMENT_BYTES) {
            return null;
        }
        $path = [$base];
        $offset = $open;
        $length = strlen($name);
        while ($offset < $length) {
            if ($name[$offset] !== '[') {
                return null;
            }
            $close = strpos($name, ']', $offset + 1);
            if ($close === false) {
                return null;
            }
            $segment = substr($name, $offset + 1, $close - $offset - 1);
            if (str_contains($segment, '[')
                || strlen($segment) > self::MAX_MENU_QUERY_NAME_SEGMENT_BYTES) {
                return null;
            }
            $path[] = $segment;
            if (count($path) > self::MAX_MENU_QUERY_NAME_SEGMENTS) {
                return null;
            }
            $offset = $close + 1;
        }
        return $path;
    }

    private static function decode_menu_uri_component(
        string $raw,
        int $maxBytes,
        int &$workRemaining,
        bool $queryForm
    ): ?string {
        if (strlen($raw) > $maxBytes) {
            return null;
        }
        $decoded = $queryForm ? str_replace('+', ' ', $raw) : $raw;
        while (true) {
            $bytes = strlen($decoded);
            if ($bytes > $maxBytes
                || $bytes > $workRemaining
                || preg_match('/%(?![0-9A-Fa-f]{2})/', $decoded)
                || preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
                return null;
            }
            $workRemaining -= $bytes;
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                break;
            }
            $decoded = $next;
        }
        return $decoded;
    }

    /**
     * @param array{
     *   path:string,
     *   fragment:string,
     *   pairs:list<array{name:string,value:string,path:list<string>}>
     * } $semantic
     * @return list<mixed>
     */
    private static function menu_uri_clearance_value(string $url, array $semantic): array {
        $clearance = [$url, $semantic['path']];
        if ($semantic['fragment'] !== '') {
            $clearance[] = $semantic['fragment'];
        }
        foreach ($semantic['pairs'] as $pair) {
            $name = $pair['name'];
            $value = $pair['value'];
            // PHP coerces canonical decimal string keys to ints. Retain the
            // decoded name separately as a string value so that coercion can
            // never remove it from scalar PII/hard-secret scanning, then keep
            // the one-entry map to apply its semantic role to its own value.
            $clearance[] = $name;
            $clearance[] = [$name => $value];
            if (count($pair['path']) === 1) {
                continue;
            }
            // Form-style bracket names are semantic paths. Preserve their
            // nesting for address context, and apply every named segment to
            // the value separately so a credential container such as
            // smtp_pass[primary] cannot shed the smtp_pass role at its leaf.
            $nested = $value;
            foreach (array_reverse($pair['path']) as $segment) {
                $nested = $segment === '' ? [$nested] : [$segment => $nested];
            }
            $clearance[] = $nested;
            foreach ($pair['path'] as $segment) {
                if ($segment === '') {
                    continue;
                }
                $clearance[] = $segment;
                $clearance[] = [$segment => $value];
            }
        }
        return $clearance;
    }

    private static function authorize_sidebar(Policy $policy, string $stateKey, array $entity, array &$out): void {
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $path = (string) $entity['path'];
        self::unexpected_fields($front, ['widgets'], $path, $stateKey, 'sidebar_field', $out);
        $declared = $policy->widget_types();
        foreach ((array) ($front['widgets'] ?? []) as $i => $widget) {
            $widget = (array) $widget;
            self::unexpected_fields($widget, ['uuid', 'type', 'settings'], $path, $stateKey, "widget[$i]", $out);
            $type = (string) ($widget['type'] ?? '');
            if (!isset($declared[$type])) {
                self::finding($out, 'repository_entity_out_of_scope', $path, $stateKey, "widget[$i]", 'type', 'unclassified', null);
                continue;
            }
            foreach ((array) ($widget['settings'] ?? []) as $setting => $value) {
                $rule = $declared[$type]['settings'][$setting] ?? null;
                $class = is_array($rule) ? ($rule['class'] ?? 'unclassified') : 'unclassified';
                if ($class !== 'authored') {
                    self::finding(
                        $out, 'repository_field_not_authored', $path, $stateKey,
                        "widget[$i].settings", (string) $setting, $class, 'manifest widgets.' . $type
                    );
                } else {
                    self::authorize_sensitivity(
                        $out, $path, $stateKey, "widget[$i].settings", (string) $setting,
                        ($rule['codec'] ?? '') === 'blocks' && is_string($value)
                            ? BlockAttributeReader::clearance_value($value, BlockContentGrammar::project($policy->manifests, $policy->site['policy'] ?? [])) : $value,
                        $rule, 'manifest widgets.' . $type
                    );
                }
            }
        }
    }

    private static function authorize_options(Policy $policy, string $uuid, array $entity, array &$out): void {
        $document = $entity['data'] ?? Canon::decode($entity['content']);
        // issue #3263: re-derivation is about this one immutable revision (same
        // "authorization is about one immutable revision" principle
        // manifests/interpreters/acf.php's own prime_repository() docblock
        // documents) — the sibling-lookup context (ACF's shadow pointer) an
        // interpreter's option_rule() needs comes from this SAME document's
        // own present values plus hash-bound v2 deletion witnesses, never a
        // live target or capture-process history.
        $allOptions = OptionState::classification_values($document);
        // A competitor need not have an adapter pin or a code payload. This
        // common capture/compiler boundary rejects its desired activation
        // before publication, lifecycle reconciliation, or materialization.
        $activePlugins = OptionState::values($document)['active_plugins'] ?? [];
        foreach ($policy->active_plugin_conflicts(is_array($activePlugins) ? $activePlugins : []) as $conflict) {
            self::finding(
                $out, 'repository_active_plugin_incompatible', $entity['path'], $uuid,
                'managed_option', $conflict['incompatible_plugin'], 'incompatible', $conflict['manifest'],
                "Remove '{$conflict['incompatible_plugin']}' from canonical active_plugins or unpin "
                    . "'{$conflict['manifest']}', whose '{$conflict['plugin']}' contract forbids it."
            );
        }
        foreach (OptionState::records($document) as $name => $record) {
            $details = str_contains((string) $name, '{{')
                ? $policy->canonical_option_name_ref_details((string) $name)
                : $policy->option_rule_details_for_option((string) $name, $allOptions);
            $rule = $details['rule'] ?? [];
            // issue #3264 (fork A): theme_mods_<stylesheet>'s own sub_keys
            // rule is never findable via the ordinary single-name lookup
            // above (its physical NAME is computed, not declared).
            // Deliberately the PREFIX-only match (Policy::
            // dynamic_option_rule_for_prefix(), not the exact-match
            // dynamic_option_rule_for_name() Apply::option_apply_target()
            // uses): authorization runs as part of repository compilation,
            // which `wp wprism deploy` also goes through — including on a
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
            if (!empty($rule[PhpContainerValue::FIELD])) {
                try {
                    PhpContainerValue::assert_canonical($value, "repository option '$name'");
                } catch (\RuntimeException $failure) {
                    self::finding($out, 'repository_option_container_invalid', $entity['path'], $uuid,
                        'option', (string) $name, 'malformed', $details['source']);
                }
            }
            try {
                OptionState::assert_rule_autoload($rule, (string) $record['autoload'], "repository option '$name'");
            } catch (\Throwable $t) {
                self::finding(
                    $out, 'repository_option_autoload_not_authorized', $entity['path'], $uuid,
                    'option_autoload', (string) $name, (string) $record['autoload'], $details['source']
                );
            }
            if (!empty($rule['sub_keys'])) {
                // issue #3233: this option's OWN top-level class is legitimately
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
            } elseif ($class === 'authored') {
                self::authorize_sensitivity(
                    $out, $entity['path'], $uuid, 'option', (string) $name,
                    $value, $rule, $details['source']
                );
            }
        }
    }

    private static function authorize_user_meta(
        Policy $policy,
        string $stateKey,
        array $entity,
        array &$out
    ): void {
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $path = (string) $entity['path'];
        self::unexpected_fields($front, self::USER_META_FIELDS, $path, $stateKey, 'user_meta_field', $out);
        self::authorize_sensitivity(
            $out, $path, $stateKey, 'user_meta_field', 'login', $front['login'] ?? '', [], 'platform'
        );
        $meta = (array) ($front['meta'] ?? []);
        foreach ($meta as $key => $value) {
            $details = $policy->meta_rule_details_for_user((string) $key, $meta);
            $rule = $details['rule'] ?? [];
            $class = $rule['class'] ?? 'unclassified';
            if ($class !== 'authored') {
                self::finding(
                    $out, 'repository_field_not_authored', $path, $stateKey,
                    'user_meta', (string) $key, $class, $details['source']
                );
                continue;
            }
            if (empty($rule['allow_secret']) && Secrets::clearance_match_deep((string) $key, $value) !== null) {
                self::finding(
                    $out, 'repository_user_meta_secret_not_allowed', $path, $stateKey,
                    'user_meta', (string) $key, 'secret', $details['source']
                );
            }
            self::authorize_encoding($out, $path, $stateKey, 'user_meta', (string) $key, $value, $rule, $details['source']);
            if (empty($rule['allow_pii']) && PersonalData::match_deep((string) $key, $value) !== null) {
                self::finding(
                    $out, 'repository_user_meta_pii_not_allowed', $path, $stateKey,
                    'user_meta', (string) $key, 'pii', $details['source']
                );
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
        foreach ($value as $subKey => $subValue) {
            $subRule = (array) ($subKeys[$subKey] ?? []);
            $subClass = $subRule['class'] ?? 'unclassified';
            if ($subClass !== 'authored') {
                self::finding(
                    $out, 'repository_field_not_authored', $path, $uuid, 'option_sub_key',
                    "$name.$subKey", $subClass, $source
                );
            } else {
                self::authorize_sensitivity(
                    $out, $path, $uuid, 'option_sub_key', "$name.$subKey",
                    $subValue, $subRule, $source
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
        $columnCodecs = $policy->column_codec_rules($table);
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        self::unexpected_fields($front, self::TABLE_FIELDS, $path, $uuid, 'table_field', $out);
        if (!TableRowScope::matches($decl, (array) ($front['columns'] ?? []))) {
            self::finding($out, 'repository_entity_out_of_scope', $path, $uuid, 'table', 'row_scope', 'unowned', $tableDetails['source']);
        }
        if (($front['table'] ?? null) !== $table) {
            self::finding($out, 'repository_field_not_authored', $path, $uuid, 'table_field', 'table', 'mismatched', $tableDetails['source']);
        }

        $refs = [];
        foreach ($decl['refs'] ?? [] as $ref) {
            $refs[(string) $ref['column']] = true;
        }
        foreach ((array) ($front['columns'] ?? []) as $column => $value) {
            if (isset($refs[$column])) {
                continue;
            }
            $rule = (array) ($decl['columns'][$column] ?? []);
            $class = $rule['class'] ?? 'unclassified';
            if ($class !== 'authored') {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'table_column', (string) $column, $class, $tableDetails['source']);
            } else {
                $clearanceValue = $value;
                $piiSubject = null;
                if (isset($columnCodecs[$column])) {
                    try {
                        $clearanceValue = ColumnCodecGrammar::decode_for_clearance(
                            $value,
                            $columnCodecs[$column],
                            "repository table '$table' column '$column'",
                            'authored'
                        );
                        if (isset($columnCodecs[$column]['value'])) {
                            $piiSubject = AuthoredValueCodec::pii_subject($clearanceValue, $columnCodecs[$column]['value'],
                                true, "repository table '$table' column '$column'");
                        }
                    } catch (\Throwable) {
                        // RepositoryPortableShapeValidator owns malformed
                        // codec framing. Raw scanning here retains defense in
                        // depth without replacing its stable diagnostic.
                    }
                }
                self::authorize_sensitivity(
                    $out, $path, $uuid, 'table_column', (string) $column,
                    $clearanceValue, $rule, $tableDetails['source'], [], $piiSubject
                );
            }
        }

        $metaTables = [];
        foreach (Snapshot::meta_tables($policy) as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) === $table) {
                $metaTables[$metaName] = $metaDecl;
            }
        }
        foreach ((array) ($front['meta'] ?? []) as $key => $value) {
            if (!$metaTables) {
                self::finding($out, 'repository_field_not_authored', $path, $uuid, 'attached_meta', (string) $key, 'unclassified', null);
                continue;
            }
            foreach ($metaTables as $metaName => $metaDecl) {
                $rule = Snapshot::meta_key_in_keyspace($metaDecl, (string) $key)
                    ? ReferenceRules::attached_meta_key($metaDecl, (string) $key)
                    : [];
                $class = $rule['class'] ?? 'unclassified';
                if ($class !== 'authored') {
                    $source = $policy->declared_table_details($metaName)['source'];
                    self::finding($out, 'repository_field_not_authored', $path, $uuid, "attached_meta:$metaName", (string) $key, $class, $source);
                } else {
                    $source = $policy->declared_table_details($metaName)['source'];
                    self::authorize_sensitivity(
                        $out, $path, $uuid, "attached_meta:$metaName", (string) $key,
                        $value, $rule, $source
                    );
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

    /** Open structured body framing before the recursive secret/PII gate. */
    private static function post_body_for_clearance(
        Policy $policy,
        string $postType,
        string $body,
        string $path
    ): mixed {
        if ($policy->body_mode($postType) === 'blocks') return BlockAttributeReader::clearance_value($body,
            BlockContentGrammar::project($policy->manifests, $policy->site['policy'] ?? []));
        try {
            return match ($policy->body_mode($postType)) {
                'serialized' => PlainData::decode_serialized($body, "$path body"),
                BodyRefGrammar::BODY_MODE => BodyRefGrammar::decode($body, "$path body"),
                default => $body,
            };
        } catch (\Throwable) {
            // Structural validators own malformed framing. The raw fallback
            // still catches literal signatures while preserving their stable
            // schema/body diagnostic as the primary refusal.
            return $body;
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

    /** Re-run capture's clearance on the immutable bytes plan/apply consume.
     *  @param list<string> $reviewedScalarPaths */
    private static function authorize_sensitivity(
        array &$out,
        string $path,
        string $uuid,
        string $surface,
        string $field,
        mixed $value,
        array $rule,
        ?string $source,
        array $reviewedScalarPaths = [],
        ?array $piiSubject = null
    ): void {
        self::authorize_encoding($out, $path, $uuid, $surface, $field, $value, $rule, $source);
        try {
            ScalarValueConstraint::assert_value($value, $rule, "$surface.$field");
        } catch (\RuntimeException) {
            self::finding($out, 'repository_scalar_value_invalid', $path, $uuid, $surface, $field, 'malformed', $source);
        }
        if (empty($rule['allow_secret']) && Secrets::clearance_match_deep($field, $value) !== null) {
            self::finding(
                $out, 'repository_secret_not_allowed', $path, $uuid,
                $surface, $field, 'secret', $source
            );
        }
        if (empty($rule['allow_pii']) && ($piiSubject['present'] ?? true)
            && PersonalData::match_deep($field, $piiSubject === null ? $value : $piiSubject['value'], $reviewedScalarPaths) !== null) {
            self::finding(
                $out, 'repository_pii_not_allowed', $path, $uuid,
                $surface, $field, 'pii', $source
            );
        }
    }

    private static function authorize_encoding(array &$out, string $path, string $uuid, string $surface, string $field, mixed $value, array $rule, ?string $source): void {
        try {
            EncodedText::assert_canonical($value, $rule, "$surface.$field");
        } catch (\RuntimeException) {
            self::finding($out, 'repository_text_encoding_invalid', $path, $uuid, $surface, $field, 'malformed', $source);
        }
    }

    /**
     * `$remediation` is an OPTIONAL in-place field, not a new envelope
     * version. `wprism-apply-in-progress` needed v2 (issue #3489) because absence
     * of its new field could be read as a claim; absence here claims nothing
     * beyond "this finding carries no reviewed one-line remedy", which is
     * exactly what every finding said before. Existing readers key on `code`
     * (regress_repository_compiler.sh:179 columns it) and are unaffected.
     */
    private static function finding(array &$out, string $code, string $path, string $uuid, string $surface, string $field, string $classification, ?string $source, ?string $remediation = null): void {
        $finding = [
            'code' => $code,
            'path' => $path,
            'uuid' => $uuid,
            'surface' => $surface,
            'field' => $field,
            'classification' => $classification,
            'declared_by' => $source,
        ];
        if ($remediation !== null) {
            $finding['remediation'] = $remediation;
        }
        $out[] = $finding;
    }
}
