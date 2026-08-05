<?php
namespace Duo;

/**
 * Layered classification policy: site policy overrides > pinned manifests
 * (in pin order) > option name-patterns. Anything unmatched is unclassified,
 * and unclassified is a loud abort at the call sites (never a silent guess).
 */
final class Policy {
    public array $site = [];
    /** @var array<int, array> */
    public array $manifests = [];
    /** @var array<string, object>|null lazily-built interpreter instances */
    private ?array $interpreterInstances = null;

    /**
     * Interpreter registry. An interpreter is a class with
     *   post_meta_rule(string $key, array $allMeta): ?array
     * returning a classification rule (same shape as manifest post_meta rules,
     * optionally with 'cast') or null to defer. Manifests opt in via
     * {"interpreter": "<name>"} — for schema-driven plugins (ACF) whose meta
     * semantics live in data, not in a static key list.
     */
    private const INTERPRETERS = [
        'acf' => ['\\Duo\\Interpreters\\Acf', '/Interpreters/Acf.php'],
    ];

    public static function manifests_dir(): string {
        $env = getenv('DUO_MANIFESTS_DIR');
        if ($env && is_dir($env)) {
            return $env;
        }
        $local = dirname(__DIR__, 2) . '/manifests';
        if (is_dir($local)) {
            return $local;
        }
        return '/duo-manifests';
    }

    public static function load(?string $repo, ?array $manifestNames = null): self {
        $p = new self();
        if ($repo !== null) {
            $siteFile = rtrim($repo, '/') . '/site.duo.json';
            if (!is_file($siteFile)) {
                throw new \RuntimeException("duo: $siteFile not found (not a duo site repo?)");
            }
            $p->site = Canon::decode(Canon::read_file($siteFile));
        }
        $names = $manifestNames ?? ($p->site['manifests'] ?? ['core']);
        $dir = self::manifests_dir();
        foreach ($names as $name) {
            $file = $dir . '/' . basename($name) . '.json';
            if (!is_file($file)) {
                throw new \RuntimeException("duo: manifest '$name' not found in $dir");
            }
            $p->manifests[] = Canon::decode(Canon::read_file($file));
        }
        return $p;
    }

    private function rule(string $section, string $name): ?array {
        $sitePolicy = $this->site['policy'][$section][$name] ?? null;
        if ($sitePolicy !== null) {
            return $sitePolicy;
        }
        foreach ($this->manifests as $m) {
            if (isset($m[$section][$name])) {
                return $m[$section][$name];
            }
        }
        if ($section === 'options') {
            foreach ($this->manifests as $m) {
                foreach ($m['option_patterns'] ?? [] as $pat) {
                    if (preg_match('/' . $pat['match'] . '/', $name)) {
                        return ['class' => $pat['class']];
                    }
                }
            }
        }
        return null;
    }

    public function option_rule(string $name): ?array {
        return $this->rule('options', $name);
    }

    public function post_meta_rule(string $key): ?array {
        return $this->rule('post_meta', $key);
    }

    public function term_meta_rule(string $key): ?array {
        return $this->rule('term_meta', $key);
    }

    public function table_rule(string $unprefixedTable): ?array {
        return $this->rule('tables', $unprefixedTable);
    }

    /** Option names classified authored (the capture whitelist). */
    public function authored_options(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['options'] ?? [] as $name => $r) {
                if (($r['class'] ?? '') === 'authored') {
                    $out[$name] = $r;
                }
            }
        }
        foreach ($this->site['policy']['options'] ?? [] as $name => $r) {
            if (($r['class'] ?? '') === 'authored') {
                $out[$name] = $r;
            } else {
                unset($out[$name]);
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** blockName => list of {path, kind, type} rules, merged across manifests. */
    public function block_attr_rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['block_attrs'] ?? [] as $block => $rules) {
                $out[$block] = $rules;
            }
        }
        return $out;
    }

    public function post_types(): array {
        return $this->site['policy']['post_types'] ?? ['post', 'page', 'attachment'];
    }

    public function taxonomies(): array {
        return $this->site['policy']['taxonomies'] ?? ['category', 'post_tag'];
    }

    /** @return array<string, object> */
    private function interpreters(): array {
        if ($this->interpreterInstances !== null) {
            return $this->interpreterInstances;
        }
        $this->interpreterInstances = [];
        foreach ($this->manifests as $m) {
            $name = $m['interpreter'] ?? null;
            if ($name === null || isset($this->interpreterInstances[$name])) {
                continue;
            }
            $spec = self::INTERPRETERS[$name]
                ?? throw new \RuntimeException("duo: manifest '{$m['name']}' wants unknown interpreter '$name'");
            $file = __DIR__ . $spec[1];
            if (!is_file($file)) {
                throw new \RuntimeException("duo: interpreter '$name' not installed ($file missing)");
            }
            require_once $file;
            $this->interpreterInstances[$name] = new $spec[0]($this);
        }
        return $this->interpreterInstances;
    }

    /**
     * Post-context-aware meta classification: interpreters see the entity's
     * full meta map (shadow keys and all) and win over static rules.
     */
    public function meta_rule_for_post(string $key, array $allMeta): ?array {
        foreach ($this->interpreters() as $i) {
            $rule = $i->post_meta_rule($key, $allMeta);
            if ($rule !== null) {
                return $rule;
            }
        }
        return $this->post_meta_rule($key);
    }

    /**
     * 'blocks' (default: block-parser rewriting + URL tokenization) or
     * 'verbatim' (byte-preserved — for post types whose content is serialized
     * data, e.g. acf-field, where URL substitution would corrupt lengths).
     */
    public function body_mode(string $postType): string {
        foreach ($this->manifests as $m) {
            $mode = $m['post_types'][$postType]['body'] ?? null;
            if ($mode !== null) {
                return $mode;
            }
        }
        return 'blocks';
    }

    /** Manifest-declared rebuilders (wp-cli commands run in the rebuild pass). */
    public function rebuilders(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['rebuilders'] ?? [] as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * Referential delete guards, keyed "post:<post_type>" — each guard names a
     * table/column holding local ids that reference the entity; matching rows
     * block deletion at plan time.
     */
    public function delete_guards(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['delete_guards'] ?? [] as $key => $guards) {
                $out[$key] = array_merge($out[$key] ?? [], $guards);
            }
        }
        return $out;
    }
}
