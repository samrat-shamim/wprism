<?php
namespace Duo;

/**
 * What an adapter's declarations mean for a site's authored scope, and what
 * the site has already decided about them.
 *
 * ## Why this is one class and not three rules
 *
 * Three call sites answer the same question and used to answer it apart:
 * `InitPlanner::adapter_scope()` (which types does a selected adapter put in
 * the proposed scope), `AdapterCertify`'s pin (which of them has the site not
 * decided yet), and `RepositoryAuthorization`'s refusal (which recorded entry
 * is blocking this file, and what changes it). A second reading of "declared
 * authored" would be a second product, so the rule lives here once.
 *
 * ## The rule
 *
 * A manifest declaration is authored when its class is `authored` OR when it
 * is STRUCTURAL — declared with no class at all, which the policy reads as
 * authored (`Policy::post_type_rule_details()`, and
 * `ScopeGrammar::validate_scope_classes()`:37, where a manifest rule's
 * missing class defaults to `authored` while a SITE rule's must be written
 * out). Contact Form 7 declares `wpcf7_contact_form: {}` that way and
 * Polylang its four taxonomies; T7 grind A4 is the record of what leaving
 * them out costs.
 *
 * ## What the site has decided, and why a pin may not overrule it
 *
 * `policy.scope.<kind>.<name>` is the site's own whole-type decision and it
 * outranks every manifest ("Site policy always wins", docs/guides/
 * adapter-authoring.md §Precedence; `Policy::post_type_rule_details()`:1841
 * returns the site rule before it ever looks at a manifest). Two different
 * acts write byte-identical `{"class":"runtime"}` there — `duo classify
 * --set=scope:<kind>:<name>=runtime` through `Policy::set_rule()`
 * (Policy.php:2801) and `duo init --allow-unmanaged-plugins` recording an
 * unmanaged plugin's rowful type (`InitPlanner::unmanaged_scope()`:640) — and
 * the grammar admits no third key that could tell them apart
 * (Policy.php:2794: "scope rules accept class only"). So provenance is NOT
 * recoverable from the file, and nothing here guesses it: a recorded entry is
 * reported as `shadowed` and left exactly as the site wrote it. Only an
 * operator saying so in a second, explicit act may change it.
 *
 * Pure: no filesystem, no WordPress, no Policy instance. Both writers hand it
 * decoded arrays.
 */
final class ScopeAdoption {
    /** A declared surface the site has not decided; a pin may write it. */
    public const EXTEND = 'extend';
    /** A declared surface a site scope rule records as non-authored. */
    public const SHADOWED = 'shadowed';
    /** Already authored, by a site rule or by the site's flat opt-in list. */
    public const SETTLED = 'settled';

    /** site.duo.json's flat per-kind opt-in list. */
    private const FLAT = ['post_type' => 'post_types', 'taxonomy' => 'taxonomies'];

    /**
     * The list `Policy::post_types()`:1118 / `taxonomies()`:1309 fall back to
     * when the site names none. Restated here so a plan never proposes a
     * scope rule for a type the engine already treats as in scope.
     */
    private const FLAT_DEFAULT = [
        'post_type' => ['post', 'page', 'attachment'],
        'taxonomy' => ['category', 'post_tag'],
    ];

    /**
     * Every post type and taxonomy one manifest declares as authored.
     *
     * @param array<string,mixed> $manifest one decoded adapter manifest
     * @return array{post_types:list<string>,taxonomies:list<string>}
     */
    public static function declared_authored(array $manifest): array {
        $out = ['post_types' => [], 'taxonomies' => []];
        foreach (['post_types', 'taxonomies'] as $section) {
            foreach ((array) ($manifest[$section] ?? []) as $name => $rule) {
                if (is_array($rule) && ($rule['class'] ?? 'authored') === 'authored') {
                    $out[$section][] = (string) $name;
                }
            }
        }
        return $out;
    }

    /**
     * One row per authored surface the manifest declares, saying what the
     * site has already decided about it.
     *
     * Rows are ordered kind-then-name so two runs over the same repository
     * print the same bytes.
     *
     * @param array<string,mixed> $manifest the adapter being adopted
     * @param array<string,mixed> $site     decoded site.duo.json
     * @return list<array{kind:string,name:string,state:string,class:?string,pointer:string,spec:string}>
     */
    public static function plan(array $manifest, array $site): array {
        $declared = self::declared_authored($manifest);
        $rows = [];
        foreach (self::FLAT as $kind => $section) {
            $names = $declared[$section];
            sort($names, SORT_STRING);
            $recorded = (array) ($site['policy']['scope'][$kind] ?? []);
            $flat = $site['policy'][$section] ?? self::FLAT_DEFAULT[$kind];
            foreach (array_values(array_unique($names)) as $name) {
                $rule = $recorded[$name] ?? null;
                if (is_array($rule)) {
                    // A recorded site rule answers first — exactly as
                    // Policy::post_type_rule_details():1841 resolves it — even
                    // when the flat list also names the type: that combination
                    // is capture's own dead end (in scope, not authored).
                    $class = is_string($rule['class'] ?? null) ? $rule['class'] : null;
                    $state = $class === 'authored' ? self::SETTLED : self::SHADOWED;
                } else {
                    $class = null;
                    $state = in_array($name, (array) $flat, true) ? self::SETTLED : self::EXTEND;
                }
                $rows[] = [
                    'kind' => $kind,
                    'name' => $name,
                    'state' => $state,
                    'class' => $class,
                    'pointer' => self::pointer($kind, $name),
                    'spec' => self::classify_spec($kind, $name),
                ];
            }
        }
        return $rows;
    }

    /** The JSON path of the site's own decision, as an operator would grep for it. */
    public static function pointer(string $kind, string $name): string {
        return "policy.scope.$kind.$name";
    }

    /** The `wp duo classify --set` spec that makes this surface authored. */
    public static function classify_spec(string $kind, string $name): string {
        return "scope:$kind:$name=authored";
    }

    /**
     * The remedy clause a whole-type authorization refusal carries.
     *
     * It names the entry that is blocking and the one command that changes
     * it, because the coordinates alone (`classification=runtime
     * declared_by=site.duo.json`) named neither — DUO-3495's walkthrough
     * needed two more hand-edits to find out which.
     *
     * Value-free by construction: a repository path never enters it, so the
     * whole diagnostic batch survives `CommandRefusalException::
     * containsSensitivePublicDetail()` (Cli.php:43-49 omits the batch entirely
     * when one field is sensitive) and reaches a `--format=json` caller.
     */
    public static function scope_class_remedy(string $kind, string $name, string $class, ?string $source): string {
        $set = "wp duo classify --repo=<repo> --set='" . self::classify_spec($kind, $name) . "'";
        if ($source === 'site.duo.json') {
            return 'site.duo.json ' . self::pointer($kind, $name) . " records class $class, which outranks every "
                . "manifest; run $set to manage this $kind here, or delete that entry to fall back to the "
                . 'adapter declaration';
        }
        return ($source === null ? 'no pinned adapter' : "adapter '$source'")
            . " classifies $kind $name as $class; site policy outranks a manifest, so run $set to manage it here";
    }
}
